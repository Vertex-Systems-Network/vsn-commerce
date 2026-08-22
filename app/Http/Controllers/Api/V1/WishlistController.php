<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the WishlistController class and its project responsibilities. */
class WishlistController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request):JsonResponse{$rows=WishlistItem::query()->where('user_id',$request->user()->id)->with(['product.vendor','product.category','product.images.mediaAsset','product.variants.inventories','variant.inventories'])->latest()->get()->filter(/** Inline callback for this operation. */ fn($x)=>$x->product?->status?->value==='published');return response()->json(['data'=>['items'=>$rows->map(/** Inline callback for this operation. */ fn($x)=>$this->row($x,$request))->values()->all(),'total'=>$rows->count()]]);}
    /** Handles status for the wishlist controller workflow. */
    public function status(Request $request,Product $product):JsonResponse{$row=WishlistItem::query()->where('user_id',$request->user()->id)->where('product_id',$product->id)->latest()->first();return response()->json(['data'=>['saved'=>(bool)$row,'itemId'=>$row?->public_id]]);}
    /** Handles the store request for this resource. */
    public function store(Request $request,Product $product):JsonResponse{abort_unless($product->status->value==='published',404);$d=$request->validate(['variantId'=>'nullable|integer']);$variant=null;if(!empty($d['variantId'])){$variant=ProductVariant::query()->where('product_id',$product->id)->whereKey((int)$d['variantId'])->where('is_active',true)->firstOrFail();}$scope='product';$row=DB::transaction(/** Inline callback for this operation. */ function()use($request,$product,$variant,$scope){$row=WishlistItem::query()->firstOrCreate(['user_id'=>$request->user()->id,'product_id'=>$product->id,'scope_key'=>$scope],['public_id'=>(string)Str::ulid(),'product_variant_id'=>$variant?->id]);if($variant&&$row->product_variant_id!==$variant->id)$row->update(['product_variant_id'=>$variant->id]);return $row;},3);$row->load(['product.vendor','product.category','product.images.mediaAsset','product.variants.inventories','variant.inventories']);return response()->json(['data'=>$this->row($row,$request)],$row->wasRecentlyCreated?201:200);}
    /** Handles the destroy request for this resource. */
    public function destroy(Request $request,WishlistItem $wishlistItem):JsonResponse{abort_unless($wishlistItem->user_id===$request->user()->id,404);$wishlistItem->delete();return response()->json(['data'=>['deleted'=>true]]);}
    /** Handles row for the wishlist controller workflow. */
    private function row(WishlistItem $row,Request $request):array{$stock=$row->variant?->inventories?->sum(/** Inline callback for this operation. */ fn($i)=>$i->available());return ['id'=>$row->public_id,'savedAt'=>$row->created_at?->toIso8601String(),'variantId'=>$row->variant?->id,'variantName'=>$row->variant?->name,'available'=>$row->variant?$stock>0:$row->product->variants->contains(/** Inline callback for this operation. */ fn($v)=>$v->inventories->sum(/** Inline callback for this operation. */ fn($i)=>$i->available())>0),'product'=>(new ProductResource($row->product))->resolve($request)];}
}
