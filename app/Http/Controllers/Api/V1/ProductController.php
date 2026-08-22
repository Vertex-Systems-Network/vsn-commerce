<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
/** Defines the ProductController class and its project responsibilities. */
class ProductController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(ProductSearchService $search):JsonResponse{return response()->json(['data'=>$search->search(request())]);}
    /** Handles the show request for this resource. */
    public function show(Product $product):ProductResource{abort_unless($product->status->value==='published',404);return new ProductResource($product->load(['vendor','category','taxClass','images.mediaAsset','variants.inventories']));}
}
