<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Catalog\Services\CatalogCache;
use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
/** Defines the CategoryController class and its project responsibilities. */
class CategoryController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(CatalogCache $cache):JsonResponse
    {
        $rows=$cache->remember('categories:public',(int)config('vsn.performance.catalog_cache_seconds',120),/** Inline callback for this operation. */ fn()=>Category::query()->where('is_active',true)->withCount(['products'=>/** Inline callback for this operation. */ fn($q)=>$q->published()])->orderBy('sort_order')->orderBy('name')->get()->map(/** Inline callback for this operation. */ fn($c)=>['id'=>$c->id,'name'=>$c->name,'slug'=>$c->slug,'parentId'=>$c->parent_id,'productsCount'=>$c->products_count])->values()->all());
        return response()->json(['data'=>$rows]);
    }
}
