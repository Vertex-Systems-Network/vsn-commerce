<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Actions\ReconcileHistoricalProductMedia;
use Illuminate\Console\Command;

/** Inventories and optionally reconciles historical URL-backed product media. */
final class ReconcileHistoricalProductMediaCommand extends Command
{
    protected $signature = 'vsn:product-media-backfill
        {--apply : Apply only rows whose managed-media provenance is proven}
        {--require-zero-unresolved : Exit non-zero while any historical product-media row remains unresolved}
        {--json : Emit the deterministic reconciliation result as JSON}';

    protected $description = 'Inventory and safely reconcile historical product image URLs into managed media identities.';

    public function handle(ReconcileHistoricalProductMedia $reconciler): int
    {
        $result = $reconciler->execute((bool) $this->option('apply'));
        $after = $result['after'];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Historical product media: before=%d resolvable=%d unresolved=%d applied=%d after=%d unresolved=%d',
                $result['before']['total'],
                $result['before']['resolvable'],
                $result['before']['unresolved'],
                $result['applied'],
                $after['total'],
                $after['unresolved'],
            ));

            foreach ($after['items'] as $item) {
                if ($item['status'] !== 'unresolved') {
                    continue;
                }

                $this->warn(sprintf(
                    'UNRESOLVED image=%d product=%s vendor=%s reason=%s url_sha256=%s',
                    $item['imageId'],
                    (string) ($item['productPublicId'] ?? $item['productId']),
                    (string) ($item['vendorId'] ?? 'none'),
                    $item['reason'],
                    $item['urlHash'],
                ));
            }
        }

        if ($this->option('require-zero-unresolved') && $after['unresolved'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
