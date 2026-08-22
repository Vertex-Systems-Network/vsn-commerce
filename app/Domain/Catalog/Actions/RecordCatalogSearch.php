<?php
namespace App\Domain\Catalog\Actions;
use App\Models\CatalogSearchEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
/** Defines the RecordCatalogSearch class and its project responsibilities. */
class RecordCatalogSearch
{
    /** Executes the record catalog search operation. */
    public function execute(Request $request,string $query,int $resultCount,array $filters=[]):void
    {
        $query=trim($query);if(mb_strlen($query)<2)return;
        $normalized=mb_strtolower(preg_replace('/\s+/u',' ',$query)?:$query);
        $user=$request->user();$device=trim((string)$request->header('X-Device-Id'));
        $visitorHash=$user?null:($device!==''?hash('sha256',$device):null);
        if(!$user&&!$visitorHash)return;
        $recent=CatalogSearchEvent::query()->where('normalized_query',$normalized)->where('searched_at','>=',now()->subMinutes(5));
        if($user)$recent->where('user_id',$user->id);else $recent->whereNull('user_id')->where('visitor_hash',$visitorHash);
        if($row=$recent->latest('searched_at')->first()){$row->update(['query'=>$query,'result_count'=>$resultCount,'filters'=>$filters,'searched_at'=>now()]);return;}
        CatalogSearchEvent::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user?->id,'visitor_hash'=>$visitorHash,'query'=>$query,'normalized_query'=>$normalized,'result_count'=>$resultCount,'filters'=>$filters,'searched_at'=>now()]);
    }
}
