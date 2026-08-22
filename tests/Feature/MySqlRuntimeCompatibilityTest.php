<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Defines the MySqlRuntimeCompatibilityTest class and its project responsibilities. */
class MySqlRuntimeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies mysql and mariadb connections are first class and strict. */
    public function test_mysql_and_mariadb_connections_are_first_class_and_strict(): void
    {
        foreach (['mysql', 'mariadb'] as $name) {
            $connection = config("database.connections.{$name}");
            $this->assertIsArray($connection);
            $this->assertSame($name, $connection['driver']);
            $this->assertSame('utf8mb4', $connection['charset']);
            $this->assertSame('utf8mb4_unicode_ci', $connection['collation']);
            $this->assertTrue($connection['strict']);
        }
    }

    /** Verifies mysql runtime guard indexes exist. */
    public function test_mysql_runtime_guard_indexes_exist(): void
    {
        $this->requireMysql();
        $expected = [
            'carts_one_active_user_mysql_uq',
            'checkout_one_reserved_cart_mysql_uq',
            'saved_payment_default_user_mysql_uq',
            'tax_jurisdiction_region_mysql_uq',
            'tax_class_one_default_mysql_uq',
        ];
        $rows = DB::select('select distinct index_name from information_schema.statistics where table_schema = database()');
        $names = collect($rows)->pluck('index_name')->all();
        foreach ($expected as $name) $this->assertContains($name, $names);
    }

    /** Verifies mysql partial unique guards are virtual and foreign keys survive. */
    public function test_mysql_partial_unique_guards_are_virtual_and_foreign_keys_survive(): void
    {
        $this->requireMysql();
        $expectedColumns = [
            'carts' => 'mysql_active_user_guard',
            'checkout_sessions' => 'mysql_reserved_cart_guard',
            'saved_payment_methods' => 'mysql_default_user_guard',
            'tax_jurisdictions' => 'mysql_region_guard',
            'tax_classes' => 'mysql_default_guard',
        ];

        foreach ($expectedColumns as $table => $column) {
            $row = DB::selectOne(
                'select extra from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                [$table, $column]
            );
            $this->assertNotNull($row, "Missing generated guard {$table}.{$column}");
            $this->assertStringContainsString('VIRTUAL GENERATED', strtoupper((string) $row->extra));
        }

        $foreignKeys = collect(DB::select(
            "select table_name, column_name, referenced_table_name from information_schema.key_column_usage where table_schema = database() and referenced_table_name is not null"
        ))->map(/** Inline callback for this operation. */ fn ($row) => "{$row->table_name}.{$row->column_name}->{$row->referenced_table_name}")->all();

        $this->assertContains('carts.user_id->users', $foreignKeys);
        $this->assertContains('checkout_sessions.cart_id->carts', $foreignKeys);
        $this->assertContains('saved_payment_methods.user_id->users', $foreignKeys);
    }

    /** Verifies database backup command is driver aware and keeps password out of arguments. */
    public function test_database_backup_command_is_driver_aware_and_keeps_password_out_of_arguments(): void
    {
        config([
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'vsn_ecommerce',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => 'secret-for-test',
            'database.connections.mysql.charset' => 'utf8mb4',
            'database.connections.mariadb' => array_merge((array) config('database.connections.mysql'), ['driver' => 'mariadb']),
            'vsn.operations.backups.mysql_dump_binary' => 'mysqldump',
            'vsn.operations.backups.mysql_no_tablespaces' => true,
        ]);

        $service = app(\App\Domain\Operations\Services\DatabaseBackupService::class);
        $method = new \ReflectionMethod($service, 'dumpCommand');
        $method->setAccessible(true);

        [$mysqlCommand, $mysqlEnv] = $method->invoke($service, 'mysql', '/tmp/vsn-test.sql');
        $this->assertSame('mysqldump', $mysqlCommand[0]);
        $this->assertContains('--no-tablespaces', $mysqlCommand);
        $this->assertSame('secret-for-test', $mysqlEnv['MYSQL_PWD']);
        $this->assertStringNotContainsString('secret-for-test', implode(' ', $mysqlCommand));

        [$mariaCommand] = $method->invoke($service, 'mariadb', '/tmp/vsn-test-maria.sql');
        $this->assertNotContains('--no-tablespaces', $mariaCommand);
    }

    /** Verifies mysql active cart guard rejects duplicate active cart for user. */
    public function test_mysql_active_cart_guard_rejects_duplicate_active_cart_for_user(): void
    {
        $this->requireMysql();
        $user = User::factory()->create();
        $now = now();
        DB::table('carts')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'user_id' => $user->id,
            'status' => 'active',
            'currency' => 'PKR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(QueryException::class);
        DB::table('carts')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'user_id' => $user->id,
            'status' => 'active',
            'currency' => 'PKR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Verifies mysql tax guards normalize null region and single default class. */
    public function test_mysql_tax_guards_normalize_null_region_and_single_default_class(): void
    {
        $this->requireMysql();
        $now = now();
        DB::table('tax_jurisdictions')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'country_code' => 'PK', 'region_code' => null, 'name' => 'Pakistan',
            'status' => 'active', 'priority' => 100, 'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            DB::table('tax_jurisdictions')->insert([
                'public_id' => (string) \Illuminate\Support\Str::ulid(),
                'country_code' => 'PK', 'region_code' => null, 'name' => 'Pakistan duplicate',
                'status' => 'active', 'priority' => 90, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->fail('Expected normalized country/NULL-region uniqueness to reject the duplicate jurisdiction.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        DB::table('tax_classes')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'STD', 'name' => 'Standard',
            'is_default' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->expectException(QueryException::class);
        DB::table('tax_classes')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(), 'code' => 'ALT', 'name' => 'Alternative',
            'is_default' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Handles require mysql for the my sql runtime compatibility test workflow. */
    private function requireMysql(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB runtime contract is exercised by phpunit.mysql.xml.');
        }
    }
}
