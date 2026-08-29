<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Services\CatalogCache;
use App\Models\MediaLibraryAsset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductMediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Reconciles historical URL-backed product images only when managed-media provenance is provable. */
final class ReconcileHistoricalProductMedia
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly CatalogCache $cache) {}

    /** Returns the complete deterministic unresolved/resolvable set before any mutation. */
    public function inventory(): array
    {
        $items = [];

        $images = ProductImage::query()
            ->with('product')
            ->where(/** Includes every row still depending on legacy URL authority or missing managed identity. */ function ($query): void {
                $query->where('source', 'legacy_url')->orWhereNull('media_asset_id');
            })
            ->lazyById(100);

        foreach ($images as $image) {
            $items[] = $this->plan($image);
        }

        $resolvable = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'resolvable'));
        $unresolved = count($items) - $resolvable;

        return [
            'total' => count($items),
            'resolvable' => $resolvable,
            'unresolved' => $unresolved,
            'items' => $items,
        ];
    }

    /** Inventories first, then optionally applies only still-provable rows and re-inventories afterwards. */
    public function execute(bool $apply = false): array
    {
        $before = $this->inventory();
        $applied = 0;

        if ($apply) {
            foreach ($before['items'] as $item) {
                if ($item['status'] !== 'resolvable') {
                    continue;
                }

                $changed = DB::transaction(function () use ($item): bool {
                    $image = ProductImage::query()->with('product')->lockForUpdate()->find($item['imageId']);
                    if (! $image) {
                        return false;
                    }

                    $freshPlan = $this->plan($image);
                    if ($freshPlan['status'] !== 'resolvable') {
                        return false;
                    }

                    return $this->applyPlan($image, $freshPlan);
                }, 3);

                if ($changed) {
                    $applied++;
                }
            }
        }

        if ($applied > 0) {
            $this->cache->bump();
        }

        return [
            'before' => $before,
            'applied' => $applied,
            'after' => $this->inventory(),
        ];
    }

    /** Produces one tenant-aware, non-mutating reconciliation decision. */
    private function plan(ProductImage $image): array
    {
        $product = $image->product;
        $base = [
            'imageId' => $image->id,
            'productId' => $image->product_id,
            'productPublicId' => $product?->public_id,
            'vendorId' => $product?->vendor_id,
            'urlHash' => hash('sha256', (string) $image->url),
        ];

        if (! $product) {
            return [...$base, 'status' => 'unresolved', 'reason' => 'missing_product'];
        }

        $legacyUrl = (string) $image->url;
        if ($legacyUrl === '') {
            return [...$base, 'status' => 'unresolved', 'reason' => 'missing_legacy_url'];
        }

        foreach (ProductMediaAsset::query()->where('product_id', $product->id)->where('status', 'active')->orderBy('id')->cursor() as $asset) {
            if (! $this->urlMatches($asset->disk, $asset->path, $legacyUrl)) {
                continue;
            }

            $binary = $this->inspectStoredImage($asset->disk, $asset->path);
            if (! $binary || ! hash_equals((string) $asset->sha256, $binary['sha256'])) {
                return [...$base, 'status' => 'unresolved', 'reason' => 'product_asset_integrity_mismatch'];
            }

            return [
                ...$base,
                'status' => 'resolvable',
                'provenance' => 'existing_product_asset',
                'assetId' => $asset->id,
            ];
        }

        $libraryMatch = $this->matchingLibraryAsset($product, $legacyUrl);
        if ($libraryMatch instanceof MediaLibraryAsset) {
            $binary = $this->inspectStoredImage($libraryMatch->disk, $libraryMatch->path);
            if (! $binary || ! hash_equals((string) $libraryMatch->sha256, $binary['sha256'])) {
                return [...$base, 'status' => 'unresolved', 'reason' => 'media_library_integrity_mismatch'];
            }

            if ($this->hasConflictingProductHashIdentity($product, $binary['sha256'], $legacyUrl)) {
                return [...$base, 'status' => 'unresolved', 'reason' => 'product_sha_identity_conflict'];
            }

            return [
                ...$base,
                'status' => 'resolvable',
                'provenance' => 'media_library',
                'libraryAssetId' => $libraryMatch->id,
                'disk' => $libraryMatch->disk,
                'path' => $libraryMatch->path,
                'uploadedByUserId' => $libraryMatch->uploaded_by_user_id,
                'originalName' => $libraryMatch->original_name,
                'mime' => $binary['mime'],
                'bytes' => $binary['bytes'],
                'sha256' => $binary['sha256'],
                'width' => $binary['width'],
                'height' => $binary['height'],
                'libraryPublicId' => $libraryMatch->public_id,
            ];
        }

        if ($this->matchesForeignLibraryAsset($product, $legacyUrl)) {
            return [...$base, 'status' => 'unresolved', 'reason' => 'cross_vendor_media'];
        }

        $namespaceMatch = $this->matchingProductNamespaceObject($product, $legacyUrl);
        if ($namespaceMatch) {
            if ($this->hasConflictingProductHashIdentity($product, $namespaceMatch['sha256'], $legacyUrl)) {
                return [...$base, 'status' => 'unresolved', 'reason' => 'product_sha_identity_conflict'];
            }

            return [
                ...$base,
                'status' => 'resolvable',
                'provenance' => 'product_namespace',
                ...$namespaceMatch,
            ];
        }

        return [...$base, 'status' => 'unresolved', 'reason' => 'unproven_url_or_binary'];
    }

    /** Applies one fresh plan without replacing the historical ProductImage row or legacy URL. */
    private function applyPlan(ProductImage $image, array $plan): bool
    {
        if ($plan['provenance'] === 'existing_product_asset') {
            $asset = ProductMediaAsset::query()->whereKey($plan['assetId'])->where('product_id', $image->product_id)->where('status', 'active')->first();
            if (! $asset || ! $this->urlMatches($asset->disk, $asset->path, (string) $image->url)) {
                return false;
            }

            $image->forceFill(['media_asset_id' => $asset->id, 'source' => 'managed'])->save();

            return true;
        }

        $asset = ProductMediaAsset::query()
            ->where('product_id', $image->product_id)
            ->where('sha256', $plan['sha256'])
            ->lockForUpdate()
            ->first();

        if ($asset) {
            if ($asset->status !== 'active' || ! $this->urlMatches($asset->disk, $asset->path, (string) $image->url)) {
                return false;
            }
        } else {
            $metadata = $plan['provenance'] === 'media_library'
                ? ['source' => 'media_library', 'media_library_asset_id' => $plan['libraryPublicId'], 'historical_backfill' => true]
                : ['source' => 'historical_backfill', 'provenance' => 'product_namespace'];

            $asset = ProductMediaAsset::create([
                'public_id' => (string) Str::ulid(),
                'product_id' => $image->product_id,
                'product_variant_id' => $image->product_variant_id,
                'uploaded_by_user_id' => $plan['uploadedByUserId'] ?? null,
                'disk' => $plan['disk'],
                'path' => $plan['path'],
                'original_name' => $plan['originalName'] ?? basename((string) $plan['path']),
                'alt_text' => trim((string) $image->alt_text) ?: $image->product?->name,
                'mime_type' => $plan['mime'],
                'byte_size' => $plan['bytes'],
                'sha256' => $plan['sha256'],
                'width' => $plan['width'],
                'height' => $plan['height'],
                'status' => 'active',
                'visibility' => 'public',
                'metadata' => $metadata,
                'sort_order' => $image->sort_order,
            ]);
        }

        $image->forceFill(['media_asset_id' => $asset->id, 'source' => 'managed'])->save();

        return true;
    }

    /** Finds a same-vendor or global reusable library object whose generated delivery URL exactly matches. */
    private function matchingLibraryAsset(Product $product, string $legacyUrl): ?MediaLibraryAsset
    {
        $assets = MediaLibraryAsset::query()
            ->where('status', 'active')
            ->where(/** Global assets or assets owned by the product vendor are eligible. */ function ($query) use ($product): void {
                $query->whereNull('vendor_id')->orWhere('vendor_id', $product->vendor_id);
            })
            ->orderBy('id')
            ->cursor();

        foreach ($assets as $asset) {
            $scopeValid = $asset->vendor_id === null
                ? $asset->scope_key === 'global'
                : $asset->scope_key === 'vendor:'.$product->vendor_id;
            if ($scopeValid && $this->urlMatches($asset->disk, $asset->path, $legacyUrl)) {
                return $asset;
            }
        }

        return null;
    }

    /** Detects exact URL matches that belong to another vendor and therefore must fail closed. */
    private function matchesForeignLibraryAsset(Product $product, string $legacyUrl): bool
    {
        foreach (MediaLibraryAsset::query()->where('status', 'active')->whereNotNull('vendor_id')->where('vendor_id', '!=', $product->vendor_id)->orderBy('id')->cursor() as $asset) {
            if ($this->urlMatches($asset->disk, $asset->path, $legacyUrl)) {
                return true;
            }
        }

        return false;
    }

    /** Resolves only an existing object inside the configured product-owned namespace. */
    private function matchingProductNamespaceObject(Product $product, string $legacyUrl): ?array
    {
        $disk = (string) config('vsn.catalog.media_disk', 'public');
        $directory = 'products/'.$product->public_id;

        try {
            $paths = Storage::disk($disk)->files($directory);
            sort($paths, SORT_STRING);
        } catch (Throwable) {
            return null;
        }

        foreach ($paths as $path) {
            if (! $this->urlMatches($disk, $path, $legacyUrl)) {
                continue;
            }

            $binary = $this->inspectStoredImage($disk, $path);
            if (! $binary) {
                return null;
            }

            return [
                'disk' => $disk,
                'path' => $path,
                'originalName' => basename($path),
                ...$binary,
            ];
        }

        return null;
    }

    /** Rejects a same-product SHA identity that cannot preserve the historical delivery URL. */
    private function hasConflictingProductHashIdentity(Product $product, string $sha256, string $legacyUrl): bool
    {
        $existing = ProductMediaAsset::query()->where('product_id', $product->id)->where('sha256', $sha256)->first();
        if (! $existing) {
            return false;
        }

        return $existing->status !== 'active' || ! $this->urlMatches($existing->disk, $existing->path, $legacyUrl);
    }

    /** Compares only framework-generated storage URLs; no remote network request is performed. */
    private function urlMatches(string $disk, string $path, string $legacyUrl): bool
    {
        try {
            return Storage::disk($disk)->exists($path)
                && hash_equals(Storage::disk($disk)->url($path), $legacyUrl);
        } catch (Throwable) {
            return false;
        }
    }

    /** Reads a bounded existing storage object and derives trusted image integrity metadata. */
    private function inspectStoredImage(string $disk, string $path): ?array
    {
        try {
            $storage = Storage::disk($disk);
            $maxBytes = (int) config('vsn.security.uploads.max_file_bytes', 10_485_760);
            $size = $storage->size($path);
            if ($size < 1 || $size > $maxBytes) {
                return null;
            }

            $stream = $storage->readStream($path);
            if (! is_resource($stream)) {
                return null;
            }

            try {
                $contents = stream_get_contents($stream, $maxBytes + 1);
            } finally {
                fclose($stream);
            }

            if (! is_string($contents) || strlen($contents) !== $size || strlen($contents) > $maxBytes) {
                return null;
            }

            $info = @getimagesizefromstring($contents);
            $mime = is_array($info) ? ($info['mime'] ?? null) : null;
            if (! is_string($mime) || ! in_array($mime, self::ALLOWED_MIMES, true)) {
                return null;
            }

            return [
                'mime' => $mime,
                'bytes' => $size,
                'sha256' => hash('sha256', $contents),
                'width' => isset($info[0]) ? (int) $info[0] : null,
                'height' => isset($info[1]) ? (int) $info[1] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
