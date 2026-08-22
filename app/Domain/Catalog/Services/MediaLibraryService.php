<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Security\Services\SecureUploadInspector;
use App\Models\MediaLibraryAsset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductMediaAsset;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stores reusable marketplace/seller media and safely attaches library images to products. */
class MediaLibraryService
{
    /** Creates the service with secure upload inspection and catalog cache invalidation. */
    public function __construct(private readonly SecureUploadInspector $uploads, private readonly CatalogCache $cache) {}

    /** Uploads an image into either the global admin library or one seller's isolated library. */
    public function upload(User $actor, UploadedFile $file, ?Vendor $vendor = null, ?string $alt = null): MediaLibraryAsset
    {
        $inspected = $this->uploads->inspect(
            $file,
            ['image/jpeg','image/png','image/webp'],
            (int) config('vsn.security.uploads.max_file_bytes', 10485760),
            true
        );
        $scopeKey = $vendor ? 'vendor:'.$vendor->id : 'global';
        $existing = MediaLibraryAsset::query()->where('scope_key', $scopeKey)->where('sha256', $inspected['sha256'])->first();
        if ($existing && $existing->status === 'active') return $existing;
        if ($existing && Storage::disk($existing->disk)->exists($existing->path)) {
            $existing->forceFill([
                'uploaded_by_user_id'=>$actor->id,
                'original_name'=>$file->getClientOriginalName(),
                'alt_text'=>trim((string)$alt) ?: $existing->alt_text,
                'mime_type'=>$inspected['mime'],'byte_size'=>$inspected['bytes'],'width'=>$inspected['width']??null,'height'=>$inspected['height']??null,
                'visibility'=>'public','status'=>'active',
            ])->save();
            return $existing->fresh();
        }

        $disk = (string) config('vsn.catalog.media_disk', 'public');
        $extension = match ($inspected['mime']) {'image/png'=>'png','image/webp'=>'webp',default=>'jpg'};
        $directory = $vendor ? 'media-library/vendors/'.$vendor->id : 'media-library/global';
        $path = $file->storeAs($directory, Str::ulid().'.'.$extension, $disk);
        if (! $path) throw ValidationException::withMessages(['file'=>['Media library file could not be stored.']]);

        try {
            return DB::transaction(/** Persists the reusable media row atomically. */ function () use ($actor,$file,$vendor,$alt,$scopeKey,$disk,$path,$inspected,$existing): MediaLibraryAsset {
                $payload = [
                    'vendor_id'=>$vendor?->id,'uploaded_by_user_id'=>$actor->id,'scope_key'=>$scopeKey,'disk'=>$disk,'path'=>$path,
                    'original_name'=>$file->getClientOriginalName(),'alt_text'=>trim((string)$alt) ?: null,'mime_type'=>$inspected['mime'],
                    'byte_size'=>$inspected['bytes'],'sha256'=>$inspected['sha256'],'width'=>$inspected['width']??null,'height'=>$inspected['height']??null,
                    'visibility'=>'public','status'=>'active','metadata'=>['source'=>'media_library_upload'],
                ];
                if ($existing) { $existing->fill($payload)->save(); return $existing->fresh(); }
                return MediaLibraryAsset::create(['public_id'=>(string)Str::ulid(), ...$payload]);
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    /** Attaches a reusable library asset to a product without duplicating the underlying binary file. */
    public function attach(Product $product, MediaLibraryAsset $library, User $actor, ?string $alt = null): ProductMediaAsset
    {
        abort_unless($library->status === 'active', 404);
        $max = (int) config('vsn.catalog.max_product_images', 10);
        abort_if($product->images()->count() >= $max, 422, "A product can have at most {$max} images.");
        $existing = ProductMediaAsset::query()->where('product_id',$product->id)->where('sha256',$library->sha256)->first();
        if ($existing && $existing->status === 'active') return $existing;

        $result = DB::transaction(/** Creates the product-specific media reference while reusing the library object. */ function () use ($product,$library,$actor,$alt,$existing): ProductMediaAsset {
            $sort = (int) ($product->images()->max('sort_order') ?? -1) + 1;
            $cleanAlt = trim((string)$alt) ?: ($library->alt_text ?: $product->name);
            $payload = [
                'product_id'=>$product->id,'uploaded_by_user_id'=>$actor->id,'disk'=>$library->disk,'path'=>$library->path,
                'original_name'=>$library->original_name,'alt_text'=>$cleanAlt,'mime_type'=>$library->mime_type,'byte_size'=>$library->byte_size,
                'sha256'=>$library->sha256,'width'=>$library->width,'height'=>$library->height,'status'=>'active','visibility'=>'public',
                'metadata'=>['source'=>'media_library','media_library_asset_id'=>$library->public_id],'sort_order'=>$sort,
            ];
            if ($existing) { $existing->fill($payload)->save(); $asset=$existing; }
            else { $asset=ProductMediaAsset::create(['public_id'=>(string)Str::ulid(), ...$payload]); }
            ProductImage::query()->where('product_id',$product->id)->where('media_asset_id',$asset->id)->delete();
            ProductImage::create(['product_id'=>$product->id,'media_asset_id'=>$asset->id,'url'=>Storage::disk($library->disk)->url($library->path),'source'=>'managed','alt_text'=>$cleanAlt,'sort_order'=>$sort]);
            return $asset;
        }, 3);
        $this->cache->bump();
        return $result;
    }

    /** Archives a library asset only when it is not currently referenced by active product media. */
    public function archive(MediaLibraryAsset $asset): void
    {
        $inUse = ProductMediaAsset::query()->where('status','active')->where('metadata->media_library_asset_id',$asset->public_id)->exists();
        abort_if($inUse, 422, 'This media item is currently used by a product. Remove it from products before archiving it.');
        $asset->update(['status'=>'archived']);
        Storage::disk($asset->disk)->delete($asset->path);
    }
}
