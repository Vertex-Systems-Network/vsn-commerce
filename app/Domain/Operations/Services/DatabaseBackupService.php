<?php

namespace App\Domain\Operations\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Defines the DatabaseBackupService class and its project responsibilities. */
class DatabaseBackupService
{
    /** Handles create for the database backup service workflow. */
    public function create(): BackupRun
    {
        abort_unless((bool) config('vsn.operations.backups.enabled', false), 503, 'Application backups are not enabled.');
        $disk = (string) config('vsn.operations.backups.disk', 'local');
        abort_if($disk === 'public' || config("filesystems.disks.{$disk}.visibility") === 'public', 500, 'Backup disk must be private.');

        $driver = DB::getDriverName();
        abort_unless(in_array($driver, ['mysql', 'mariadb', 'pgsql'], true), 500, "Database backups are not implemented for {$driver}.");

        $run = BackupRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'kind' => $driver,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['release' => config('vsn.operations.release'), 'driver' => $driver],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), $driver === 'pgsql' ? 'vsn-pg-' : 'vsn-my-');
        if ($tmp === false) {
            $run->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => 'Unable to create temporary backup file.']);
            throw new \RuntimeException('Unable to create temporary backup file.');
        }

        try {
            [$command, $environment, $extension, $folder] = $this->dumpCommand($driver, $tmp);
            $process = new Process($command, null, $environment, null, (int) config('vsn.operations.backups.timeout_seconds', 600));
            $process->mustRun();

            $sha = hash_file('sha256', $tmp);
            $size = filesize($tmp) ?: 0;
            if (! is_string($sha) || $size <= 0) {
                throw new \RuntimeException('Database dump completed without a valid backup artifact.');
            }

            $path = "backups/{$folder}/".now()->format('Y/m/d').'/vsn-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.'.$extension;
            $stream = fopen($tmp, 'rb');
            if (! is_resource($stream)) {
                throw new \RuntimeException('Temporary database backup cannot be read.');
            }
            Storage::disk($disk)->writeStream($path, $stream);
            fclose($stream);

            $run->update([
                'status' => 'completed',
                'storage_disk' => $disk,
                'storage_path' => $path,
                'sha256' => $sha,
                'size_bytes' => $size,
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('vsn.operations.backups.retention_days', 14)),
            ]);

            return $this->verify($run->fresh());
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => mb_substr($e->getMessage(), 0, 3000)]);
            throw $e;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Handles verify for the database backup service workflow. */
    public function verify(BackupRun $run): BackupRun
    {
        abort_unless($run->storage_disk && $run->storage_path && $run->sha256, 422, 'Backup has no completed artifact to verify.');
        $stream = Storage::disk($run->storage_disk)->readStream($run->storage_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Backup artifact cannot be read from private storage.');
        }
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);
        $actual = hash_final($hash);
        if (! hash_equals((string) $run->sha256, $actual)) {
            $run->update(['status' => 'verification_failed', 'error_message' => 'Stored backup SHA-256 verification failed.']);
            throw new \RuntimeException('Stored backup SHA-256 verification failed.');
        }
        $run->update(['verified_at' => now(), 'error_message' => null]);
        return $run->fresh();
    }

    /** Handles prune for the database backup service workflow. */
    public function prune(): int
    {
        $count = 0;
        BackupRun::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('status', ['completed', 'failed', 'verification_failed'])
            ->orderBy('id')
            ->chunkById(100, /** Inline callback for this operation. */ function ($runs) use (&$count): void {
                foreach ($runs as $run) {
                    if ($run->storage_disk && $run->storage_path) {
                        Storage::disk($run->storage_disk)->delete($run->storage_path);
                    }
                    $run->update(['status' => 'expired']);
                    $count++;
                }
            });
        return $count;
    }

    /** @return array{0: array<int,string>, 1: array<string,string>, 2: string, 3: string} */
    private function dumpCommand(string $driver, string $tmp): array
    {
        if ($driver === 'pgsql') {
            $db = config('database.connections.pgsql');
            return [[
                (string) config('vsn.operations.backups.pg_dump_binary', 'pg_dump'),
                '--format=custom', '--no-owner', '--no-privileges',
                '--host='.(string) ($db['host'] ?? '127.0.0.1'),
                '--port='.(string) ($db['port'] ?? '5432'),
                '--username='.(string) ($db['username'] ?? 'postgres'),
                '--dbname='.(string) ($db['database'] ?? 'vsn_ecommerce'),
                '--file='.$tmp,
            ], ['PGPASSWORD' => (string) ($db['password'] ?? '')], 'dump', 'postgres'];
        }

        $connection = $driver === 'mariadb' ? 'mariadb' : 'mysql';
        $db = config("database.connections.{$connection}");
        $command = [
            (string) config('vsn.operations.backups.mysql_dump_binary', 'mysqldump'),
            '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob',
            '--default-character-set='.(string) ($db['charset'] ?? 'utf8mb4'),
            '--host='.(string) ($db['host'] ?? '127.0.0.1'),
            '--port='.(string) ($db['port'] ?? '3306'),
            '--user='.(string) ($db['username'] ?? 'root'),
            '--result-file='.$tmp,
        ];
        // --no-tablespaces is a MySQL client option and is not portable to all MariaDB dump clients.
        if ($driver === 'mysql' && (bool) config('vsn.operations.backups.mysql_no_tablespaces', true)) {
            $command[] = '--no-tablespaces';
        }
        $command[] = (string) ($db['database'] ?? 'vsn_ecommerce');
        return [$command, ['MYSQL_PWD' => (string) ($db['password'] ?? '')], 'sql', $driver];
    }
}
