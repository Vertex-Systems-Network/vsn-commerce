<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Catalog\Services\ProductMediaService;
use App\Domain\Finance\Services\VendorResolver;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
/** Defines the ProductMediaController class and its project responsibilities. */
class ProductMediaController extends Controller
{
    /** Initializes the ProductMediaController instance and its dependencies. */
    public function __construct(private readonly VendorResolver $vendors){}
    /** Handles seller upload for the product media controller workflow. */
    public function sellerUpload(Request $request,Product $product,ProductMediaService $service):JsonResponse{$vendor=$this->vendors->forUser($request->user());abort_unless($product->vendor_id===$vendor->id,404);abort_if(in_array($product->status->value,['suspended','archived'],true),422,'This product is locked by marketplace review.');return $this->upload($request,$product,$service);}
    /** Handles seller update for the product media controller workflow. */
    public function sellerUpdate(Request $request,Product $product,ProductMediaAsset $media,ProductMediaService $service):JsonResponse{$vendor=$this->vendors->forUser($request->user());abort_unless($product->vendor_id===$vendor->id,404);abort_if(in_array($product->status->value,['suspended','archived'],true),422,'This product is locked by marketplace review.');$d=$request->validate(['alt'=>'nullable|string|max:190','sortOrder'=>'required|integer|min:0|max:999']);$asset=$service->update($product,$media,$request->user(),$d['alt']??null,(int)$d['sortOrder']);return response()->json(['data'=>['id'=>$asset->public_id,'alt'=>$asset->alt_text,'sortOrder'=>$asset->sort_order]]);}
    /** Handles seller delete for the product media controller workflow. */
    public function sellerDelete(Request $request,Product $product,ProductMediaAsset $media,ProductMediaService $service):JsonResponse{$vendor=$this->vendors->forUser($request->user());abort_unless($product->vendor_id===$vendor->id,404);abort_if(in_array($product->status->value,['suspended','archived'],true),422,'This product is locked by marketplace review.');$service->delete($product,$media);return response()->json(['data'=>['deleted'=>true]]);}
    /** Handles admin upload for the product media controller workflow. */
    public function adminUpload(Request $request,Product $product,ProductMediaService $service):JsonResponse{$this->admin($request);return $this->upload($request,$product,$service);}
    /** Handles admin update for the product media controller workflow. */
    public function adminUpdate(Request $request,Product $product,ProductMediaAsset $media,ProductMediaService $service):JsonResponse{$this->admin($request);$d=$request->validate(['alt'=>'nullable|string|max:190','sortOrder'=>'required|integer|min:0|max:999']);$asset=$service->update($product,$media,$request->user(),$d['alt']??null,(int)$d['sortOrder']);return response()->json(['data'=>['id'=>$asset->public_id,'alt'=>$asset->alt_text,'sortOrder'=>$asset->sort_order]]);}
    /** Handles admin delete for the product media controller workflow. */
    public function adminDelete(Request $request,Product $product,ProductMediaAsset $media,ProductMediaService $service):JsonResponse{$this->admin($request);$service->delete($product,$media);return response()->json(['data'=>['deleted'=>true]]);}
    /** Handles upload for the product media controller workflow. */
    private function upload(Request $request,Product $product,ProductMediaService $service):JsonResponse{$d=$request->validate(['file'=>'required|file|mimes:jpg,jpeg,png,webp|max:10240','alt'=>'nullable|string|max:190']);$asset=$service->upload($product,$request->user(),$d['file'],$d['alt']??null);return response()->json(['data'=>['id'=>$asset->public_id,'url'=>Storage::disk($asset->disk)->url($asset->path),'mimeType'=>$asset->mime_type,'byteSize'=>$asset->byte_size,'width'=>$asset->width,'height'=>$asset->height,'sha256'=>$asset->sha256]],201);}
    /** Handles admin for the product media controller workflow. */
    private function admin(Request $request):void{abort_unless(in_array($request->user()->role,[UserRole::Admin,UserRole::SuperAdmin],true),403);}
}
