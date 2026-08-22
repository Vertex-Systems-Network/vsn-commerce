<?php
namespace App\Domain\Catalog\Services;
use App\Domain\Security\Services\SecureUploadInspector;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductMediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
/** Defines the ProductMediaService class and its project responsibilities. */
class ProductMediaService
{
    /** Initializes the ProductMediaService instance and its dependencies. */
    public function __construct(private readonly SecureUploadInspector $uploads,private readonly CatalogCache $cache){}
    /** Handles upload for the product media service workflow. */
    public function upload(Product $product,User $actor,UploadedFile $file,?string $alt=null):ProductMediaAsset
    {
        $max=(int)config('vsn.catalog.max_product_images',10);abort_if($product->images()->count()>=$max,422,"A product can have at most {$max} images.");
        $inspected=$this->uploads->inspect($file,['image/jpeg','image/png','image/webp'],(int)config('vsn.security.uploads.max_file_bytes',10485760),true);
        $mime=$inspected['mime'];$bytes=$inspected['bytes'];$sha=$inspected['sha256'];$width=$inspected['width']??null;$height=$inspected['height']??null;
        if($existing=ProductMediaAsset::query()->where('product_id',$product->id)->where('sha256',$sha)->first()){if($existing->status==='active')return $existing;}
        $disk=(string)config('vsn.catalog.media_disk','public');$ext=match($mime){'image/png'=>'png','image/webp'=>'webp',default=>'jpg'};$path=$file->storeAs('products/'.$product->public_id,Str::ulid().'.'.$ext,$disk);if(!$path)throw ValidationException::withMessages(['file'=>['Product media could not be stored.']]);
        try{$asset=DB::transaction(/** Inline callback for this operation. */ function()use($product,$actor,$file,$mime,$bytes,$sha,$disk,$path,$width,$height,$alt,$existing){$sort=(int)($product->images()->max('sort_order')??-1)+1;$asset=$existing??null;if($asset){$asset->update(['uploaded_by_user_id'=>$actor->id,'disk'=>$disk,'path'=>$path,'original_name'=>$file->getClientOriginalName(),'alt_text'=>$alt?:$product->name,'mime_type'=>$mime,'byte_size'=>$bytes,'width'=>$width,'height'=>$height,'status'=>'active','visibility'=>'public','metadata'=>['source'=>'upload'],'sort_order'=>$sort]);}else{$asset=ProductMediaAsset::create(['public_id'=>(string)Str::ulid(),'product_id'=>$product->id,'uploaded_by_user_id'=>$actor->id,'disk'=>$disk,'path'=>$path,'original_name'=>$file->getClientOriginalName(),'alt_text'=>$alt?:$product->name,'mime_type'=>$mime,'byte_size'=>$bytes,'sha256'=>$sha,'width'=>$width,'height'=>$height,'status'=>'active','visibility'=>'public','metadata'=>['source'=>'upload'],'sort_order'=>$sort]);}ProductImage::create(['product_id'=>$product->id,'media_asset_id'=>$asset->id,'url'=>Storage::disk($disk)->url($path),'source'=>'managed','alt_text'=>$alt?:$product->name,'sort_order'=>$sort]);return $asset;},3);}catch(\Throwable $e){Storage::disk($disk)->delete($path);throw $e;}
        $this->cache->bump();return $asset;
    }
    /** Handles the update request for this resource. */
    public function update(Product $product,ProductMediaAsset $asset,User $actor,?string $alt,int $sortOrder):ProductMediaAsset
    {
        abort_unless($asset->product_id===$product->id&&$asset->status==='active',404);
        $result=DB::transaction(/** Inline callback for this operation. */ function()use($product,$asset,$actor,$alt,$sortOrder){$cleanAlt=trim((string)$alt)?:$product->name;$metadata=$asset->metadata??[];$metadata['last_edited_by']=$actor->id;$metadata['last_edited_at']=now()->toIso8601String();$asset->update(['alt_text'=>$cleanAlt,'sort_order'=>$sortOrder,'metadata'=>$metadata]);ProductImage::query()->where('product_id',$product->id)->where('media_asset_id',$asset->id)->update(['alt_text'=>$cleanAlt,'sort_order'=>$sortOrder]);return $asset->fresh();},3);
        $this->cache->bump();return $result;
    }
    /** Handles delete for the product media service workflow. */
    public function delete(Product $product,ProductMediaAsset $asset):void
    {
        abort_unless($asset->product_id===$product->id,404);DB::transaction(/** Removes the product reference without deleting reusable library binaries. */ function()use($asset){ProductImage::query()->where('media_asset_id',$asset->id)->delete();$asset->update(['status'=>'deleted']);},3);$source=(string)data_get($asset->metadata,'source','upload');if($source!=='media_library')Storage::disk($asset->disk)->delete($asset->path);$this->cache->bump();
    }
}
