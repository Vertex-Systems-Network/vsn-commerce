<?php
namespace App\Domain\Finance\Actions;
use App\Enums\FinanceDirection;
use App\Models\FinanceJournal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the PostFinanceJournal class and its project responsibilities. */
class PostFinanceJournal
{
    /** @param array<int,array{account:string,direction:string,amount:int,vendor_id?:int|null,metadata?:array}> $entries */
    public function execute(string $type,string $currency,string $idempotencyKey,array $entries,?string $referenceType=null,?string $referenceId=null,array $metadata=[]):FinanceJournal
    {
        $existing=FinanceJournal::query()->where('idempotency_key',$idempotencyKey)->first(); if($existing)return $existing->load('entries');
        $debit=0;$credit=0;
        foreach($entries as $entry){$amount=(int)($entry['amount']??0);if($amount<0)throw new \InvalidArgumentException('Finance entry amount cannot be negative.');if($amount===0)continue;$dir=FinanceDirection::from($entry['direction']);$dir===FinanceDirection::Debit?$debit+=$amount:$credit+=$amount;}
        if($debit!==$credit)throw new \LogicException("Unbalanced finance journal: debit {$debit}, credit {$credit}.");
        if($debit===0)throw new \LogicException('Finance journal cannot be empty.');
        try {
            return DB::transaction(/** Inline callback for this operation. */ function()use($type,$currency,$idempotencyKey,$entries,$referenceType,$referenceId,$metadata):FinanceJournal{
                $existing=FinanceJournal::query()->where('idempotency_key',$idempotencyKey)->lockForUpdate()->first();if($existing)return $existing->load('entries');
                $journal=FinanceJournal::create(['public_id'=>(string)Str::ulid(),'type'=>$type,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'idempotency_key'=>$idempotencyKey,'currency'=>$currency,'status'=>'posted','posted_at'=>now(),'metadata'=>$metadata]);
                foreach($entries as $entry){$amount=(int)($entry['amount']??0);if($amount===0)continue;$journal->entries()->create(['vendor_id'=>$entry['vendor_id']??null,'account_code'=>$entry['account'],'direction'=>$entry['direction'],'amount_minor'=>$amount,'metadata'=>$entry['metadata']??null]);}
                return $journal->load('entries');
            },3);
        } catch (QueryException $e) {
            if(($e->errorInfo[0]??$e->getCode())==='23505' || str_contains(strtolower($e->getMessage()), 'unique')){
                $existing=FinanceJournal::query()->where('idempotency_key',$idempotencyKey)->first();
                if($existing)return $existing->load('entries');
            }
            throw $e;
        }
    }
}
