<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Catalog\Services\ProductSearchService;
use App\Domain\Catalog\Services\CatalogCache;
use App\Http\Controllers\Controller;
use App\Models\CatalogSearchEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
/** Defines the SearchController class and its project responsibilities. */
class SearchController extends Controller
{
    /** Handles suggestions for the search controller workflow. */
    public function suggestions(Request $request,ProductSearchService $search):JsonResponse
    {
        $d=$request->validate(['q'=>'required|string|max:120','limit'=>'nullable|integer|min:1|max:12']);
        return response()->json(['data'=>$search->suggestions($d['q'],(int)($d['limit']??8))]);
    }
    /** Handles trending for the search controller workflow. */
    public function trending(Request $request,CatalogCache $cache):JsonResponse
    {
        $days=max(1,min(30,(int)$request->integer('days',7)));$limit=max(1,min(20,(int)$request->integer('limit',8)));$minimum=max(2,(int)config('vsn.catalog.trending_search_min_count',3));
        $rows=$cache->remember('search:trending:'.$days.':'.$limit.':'.$minimum,(int)config('vsn.performance.trending_cache_seconds',60),/** Inline callback for this operation. */ function()use($days,$limit,$minimum):array{
            return CatalogSearchEvent::query()->where('searched_at','>=',now()->subDays($days))
                ->select('normalized_query',DB::raw('MAX(query) as query'),DB::raw('COUNT(*) as searches'),DB::raw('MAX(result_count) as result_count'))
                ->groupBy('normalized_query')->havingRaw('COUNT(*) >= ?',[$minimum])->orderByDesc('searches')->orderByDesc('result_count')->limit($limit)->get()
                ->map(/** Inline callback for this operation. */ fn($r)=>['query'=>$r->query,'searches'=>(int)$r->searches,'resultCount'=>(int)$r->result_count])->values()->all();
        });
        return response()->json(['data'=>$rows]);
    }
    /** Handles recent for the search controller workflow. */
    public function recent(Request $request):JsonResponse
    {
        $rows=CatalogSearchEvent::query()->where('user_id',$request->user()->id)->latest('searched_at')->limit(50)->get()
            ->unique('normalized_query')->take(12)->values()->map(/** Inline callback for this operation. */ fn($r)=>['id'=>$r->public_id,'query'=>$r->query,'resultCount'=>$r->result_count,'searchedAt'=>$r->searched_at?->toISOString()]);
        return response()->json(['data'=>$rows]);
    }
    /** Handles clear recent for the search controller workflow. */
    public function clearRecent(Request $request):JsonResponse
    {
        $count=CatalogSearchEvent::query()->where('user_id',$request->user()->id)->delete();
        return response()->json(['data'=>['deleted'=>$count]]);
    }
}
