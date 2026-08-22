<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Catalog\Services\CatalogMutationService;
use App\Domain\Finance\Services\VendorResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogManagementProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
/** Defines the SellerCatalogController class and its project responsibilities. */
class SellerCatalogController extends Controller
{
    /** Initializes the SellerCatalogController instance and its dependencies. */
    public function __construct(private readonly VendorResolver $vendors){}
    /** Handles the index request for this resource. */
    public function index(Request $request):JsonResponse{$vendor=$this->vendors->forUser($request->user());$q=Product::query()->where('vendor_id',$vendor->id)->with(['vendor','category','images.mediaAsset','variants.inventories'])->when($request->string('status')->toString(),/** Inline callback for this operation. */ fn($x,$s)=>$x->where('status',$s))->latest();$rows=$q->paginate(50);return response()->json(['data'=>['items'=>CatalogManagementProductResource::collection($rows->getCollection())->resolve($request),'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()],'categories'=>$this->categories()]]);}
    /** Handles the show request for this resource. */
    public function show(Request $request,Product $product):CatalogManagementProductResource{$vendor=$this->vendors->forUser($request->user());abort_unless($product->vendor_id===$vendor->id,404);return new CatalogManagementProductResource($product->load(['vendor','category','images.mediaAsset','variants.inventories']));}
        /** Handles the store request for this resource. */
        public function store(Request $request,CatalogMutationService $service):CatalogManagementProductResource{$vendor=$this->vendors->forUser($request->user());$product=$service->create($vendor,$request->user(),$this->validated($request),false);return new CatalogManagementProductResource($product);}
    /** Handles the update request for this resource. */
    public function update(Request $request,Product $product,CatalogMutationService $service):CatalogManagementProductResource{$vendor=$this->vendors->forUser($request->user());abort_unless($product->vendor_id===$vendor->id,404);abort_if(in_array($product->status->value,['suspended','archived'],true),422,'This product is locked by marketplace review.');return new CatalogManagementProductResource($service->update($product,$request->user(),$this->validated($request,true),false));}
    /** Handles submit for the seller catalog controller workflow. */
    public function submit(Request $request,Product $product,CatalogMutationService $service):CatalogManagementProductResource{$vendor=$this->vendors->forUser($request->user());abort_unless($product->vendor_id===$vendor->id,404);return new CatalogManagementProductResource($service->submit($product));}
    /** Handles stock for the seller catalog controller workflow. */
    public function stock(Request $request,ProductVariant $variant,CatalogMutationService $service):JsonResponse{$vendor=$this->vendors->forUser($request->user());$variant->load('product');abort_unless($variant->product?->vendor_id===$vendor->id,404);$d=$request->validate(['onHand'=>'required|integer|min:0','safetyStock'=>'nullable|integer|min:0','reason'=>'nullable|string|max:190']);$row=$service->setStock($variant,$request->user(),(int)$d['onHand'],isset($d['safetyStock'])?(int)$d['safetyStock']:null,$d['reason']??'seller_adjustment');return response()->json(['data'=>['variantId'=>$variant->id,'onHand'=>$row->on_hand,'reserved'=>$row->reserved,'safetyStock'=>$row->safety_stock,'available'=>$row->available()]]);}
    /** Handles categories for the seller catalog controller workflow. */
    private function categories():array{return Category::query()->where('is_active',true)->orderBy('sort_order')->orderBy('name')->get(['id','name','slug'])->toArray();}
    /** Handles validated for the seller catalog controller workflow. */
    private function validated(Request $request,bool $partial=false):array{$req=$partial?'sometimes':'required';return $request->validate(['name'=>[$req,'string','max:190'],'slug'=>['sometimes','nullable','string','max:190'],'sku'=>['sometimes','nullable','string','max:120',Rule::unique('products','sku')->ignore($request->route('product')?->id)],'categoryId'=>[$req,'integer','exists:categories,id'],'shortDescription'=>['sometimes','nullable','string','max:1000'],'description'=>['sometimes','nullable','string','max:20000'],'currency'=>['sometimes','string','size:3'],'basePriceMinor'=>[$req,'integer','min:1'],'compareAtPriceMinor'=>['sometimes','nullable','integer','min:1'],'installmentEnabled'=>['sometimes','boolean'],'gameEnabled'=>['sometimes','boolean'],'taxClassId'=>['sometimes','nullable','string','exists:tax_classes,public_id'],'priceIncludesTax'=>['sometimes','nullable','boolean'],'images'=>['sometimes','array','max:10'],'images.*'=>['url','max:2048'],'variants'=>[$req,'array','min:1','max:100'],'variants.*.id'=>['sometimes','integer'],'variants.*.sku'=>['sometimes','nullable','string','max:120'],'variants.*.name'=>['required_with:variants','string','max:160'],'variants.*.options'=>['sometimes','array'],'variants.*.priceMinor'=>['sometimes','nullable','integer','min:1'],'variants.*.compareAtPriceMinor'=>['sometimes','nullable','integer','min:1'],'variants.*.isDefault'=>['sometimes','boolean'],'variants.*.isActive'=>['sometimes','boolean'],'variants.*.stock'=>['sometimes','integer','min:0'],'variants.*.safetyStock'=>['sometimes','integer','min:0']]);}
}
