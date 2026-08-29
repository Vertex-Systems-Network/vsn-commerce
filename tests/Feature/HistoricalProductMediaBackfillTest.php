<?php

namespace Tests\Feature;

use App\Domain\Catalog\Actions\ReconcileHistoricalProductMedia;
use App\Models\MediaLibraryAsset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductMediaAsset;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Proves P2-D historical media backfill is additive, tenant-safe, deterministic and retry-safe. */
class HistoricalProductMediaBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_namespace_legacy_image_is_reconciled_without_changing_read_semantics(): void
    {
        Storage::fake('public');
        config(['vsn.catalog.media_disk' => 'public']);
        [$vendor, $product] = $this->product('namespace');
        $path = 'products/'.$product->public_id.'/legacy.jpg';
        $contents = $this->imageContents('legacy.jpg');
        Storage::disk('public')->put($path, $contents);
        $url = Storage::disk('public')->url($path);

        $image = ProductImage::create([
            'product_id' => $product->id,
            'url' => $url,
            'source' => 'legacy_url',
            'alt_text' => 'Historical alt text',
            'sort_order' => 7,
        ]);

        $result = app(ReconcileHistoricalProductMedia::class)->execute(true);

        $this->assertSame(1, $result['before']['total']);
        $this->assertSame(1, $result['before']['resolvable']);
        $this->assertSame(0, $result['before']['unresolved']);
        $this->assertSame(1, $result['applied']);
        $this->assertSame(0, $result['after']['total']);

        $image->refresh()->load(['mediaAsset', 'product']);
        $this->assertSame('managed', $image->source);
        $this->assertNotNull($image->media_asset_id);
        $this->assertSame($url, $image->url);
        $this->assertSame($url, $image->publicUrl());
        $this->assertSame('Historical alt text', $image->alt_text);
        $this->assertSame(7, $image->sort_order);
        $this->assertSame($product->id, $image->mediaAsset->product_id);
        $this->assertSame($vendor->id, $image->product->vendor_id);
        $this->assertSame(hash('sha256', $contents), $image->mediaAsset->sha256);
        $this->assertSame('historical_backfill', $image->mediaAsset->metadata['source']);
        $this->assertSame('product_namespace', $image->mediaAsset->metadata['provenance']);

        $again = app(ReconcileHistoricalProductMedia::class)->execute(true);
        $this->assertSame(0, $again['applied']);
        $this->assertDatabaseCount('product_media_assets', 1);
    }

    public function test_existing_product_asset_is_relinked_without_duplicate_identity(): void
    {
        Storage::fake('public');
        [, $product] = $this->product('existing');
        $path = 'products/'.$product->public_id.'/existing.jpg';
        $contents = $this->imageContents('existing.jpg');
        Storage::disk('public')->put($path, $contents);
        $meta = $this->imageMetadata($contents);
        $asset = ProductMediaAsset::create([
            'public_id' => (string) Str::ulid(),
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'existing.jpg',
            'alt_text' => 'Existing',
            'mime_type' => $meta['mime'],
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'width' => $meta['width'],
            'height' => $meta['height'],
            'status' => 'active',
            'visibility' => 'public',
            'metadata' => ['source' => 'upload'],
            'sort_order' => 0,
        ]);
        $image = ProductImage::create([
            'product_id' => $product->id,
            'url' => Storage::disk('public')->url($path),
            'source' => 'legacy_url',
            'alt_text' => 'Existing',
            'sort_order' => 0,
        ]);

        $result = app(ReconcileHistoricalProductMedia::class)->execute(true);

        $this->assertSame(1, $result['applied']);
        $this->assertSame($asset->id, $image->fresh()->media_asset_id);
        $this->assertDatabaseCount('product_media_assets', 1);
    }

    public function test_library_scope_is_respected_and_unproven_urls_fail_zero_gate(): void
    {
        Storage::fake('public');
        [$vendorA, $product] = $this->product('library-a');
        [$vendorB] = $this->product('library-b');
        $ownerA = User::findOrFail($vendorA->owner_user_id);
        $ownerB = User::findOrFail($vendorB->owner_user_id);

        $own = $this->libraryAsset($vendorA, $ownerA, 'media-library/vendors/'.$vendorA->id.'/own.jpg', 'own.jpg');
        $global = $this->libraryAsset(null, $ownerA, 'media-library/global/global.jpg', 'global.jpg');
        $foreign = $this->libraryAsset($vendorB, $ownerB, 'media-library/vendors/'.$vendorB->id.'/foreign.jpg', 'foreign.jpg');

        ProductImage::create(['product_id' => $product->id, 'url' => Storage::disk('public')->url($own->path), 'source' => 'legacy_url', 'alt_text' => 'Own', 'sort_order' => 0]);
        ProductImage::create(['product_id' => $product->id, 'url' => Storage::disk('public')->url($global->path), 'source' => 'legacy_url', 'alt_text' => 'Global', 'sort_order' => 1]);
        ProductImage::create(['product_id' => $product->id, 'url' => Storage::disk('public')->url($foreign->path), 'source' => 'legacy_url', 'alt_text' => 'Foreign', 'sort_order' => 2]);
        ProductImage::create(['product_id' => $product->id, 'url' => 'https://example.test/unproven.jpg', 'source' => 'legacy_url', 'alt_text' => 'External', 'sort_order' => 3]);

        $result = app(ReconcileHistoricalProductMedia::class)->execute(true);

        $this->assertSame(4, $result['before']['total']);
        $this->assertSame(2, $result['before']['resolvable']);
        $this->assertSame(2, $result['before']['unresolved']);
        $this->assertSame(2, $result['applied']);
        $this->assertSame(2, $result['after']['unresolved']);
        $this->assertSame(
            ['cross_vendor_media', 'unproven_url_or_binary'],
            array_values(array_map(static fn (array $item): string => $item['reason'], $result['after']['items']))
        );
        $this->assertDatabaseCount('product_media_assets', 2);
        $this->artisan('vsn:product-media-backfill --require-zero-unresolved')->assertExitCode(1);
    }

    /** @return array{0: Vendor, 1: Product} */
    private function product(string $suffix): array
    {
        $owner = User::factory()->create(['role' => 'seller']);
        $vendor = Vendor::create([
            'owner_user_id' => $owner->id,
            'name' => 'Vendor '.$suffix,
            'slug' => 'vendor-'.$suffix,
            'status' => 'active',
            'commission_bps' => 1000,
        ]);
        $product = Product::create([
            'public_id' => (string) Str::ulid(),
            'vendor_id' => $vendor->id,
            'slug' => 'product-'.$suffix,
            'name' => 'Product '.$suffix,
            'status' => 'draft',
            'currency' => 'PKR',
            'base_price_minor' => 10000,
        ]);

        return [$vendor, $product];
    }

    private function libraryAsset(?Vendor $vendor, User $uploader, string $path, string $name): MediaLibraryAsset
    {
        $contents = $this->imageContents($name);
        Storage::disk('public')->put($path, $contents);
        $meta = $this->imageMetadata($contents);

        return MediaLibraryAsset::create([
            'public_id' => (string) Str::ulid(),
            'vendor_id' => $vendor?->id,
            'uploaded_by_user_id' => $uploader->id,
            'scope_key' => $vendor ? 'vendor:'.$vendor->id : 'global',
            'disk' => 'public',
            'path' => $path,
            'original_name' => $name,
            'alt_text' => $name,
            'mime_type' => $meta['mime'],
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'width' => $meta['width'],
            'height' => $meta['height'],
            'visibility' => 'public',
            'status' => 'active',
            'metadata' => ['source' => 'test'],
        ]);
    }

    private function imageContents(string $name): string
    {
        $width = 20 + (strlen($name) % 7);
        $file = UploadedFile::fake()->image($name, $width, 20);
        $contents = file_get_contents($file->getRealPath());
        $this->assertIsString($contents);

        return $contents;
    }

    /** @return array{mime: string, width: int, height: int} */
    private function imageMetadata(string $contents): array
    {
        $info = getimagesizefromstring($contents);
        $this->assertIsArray($info);

        return ['mime' => $info['mime'], 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }
}
