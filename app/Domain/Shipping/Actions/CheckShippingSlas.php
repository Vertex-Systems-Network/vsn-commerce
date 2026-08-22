<?php
namespace App\Domain\Shipping\Actions;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
/** Defines the CheckShippingSlas class and its project responsibilities. */
class CheckShippingSlas
{
    /** Executes the check shipping slas operation. */
    public function execute():array
    {
        $dispatch=0;$delivery=0;
        Shipment::query()->whereNull('dispatch_breached_at')->whereNull('picked_up_at')->whereNotNull('dispatch_due_at')->where('dispatch_due_at','<',now())->whereNotIn('status',[ShipmentStatus::Cancelled->value,ShipmentStatus::ReturnedToSender->value])->select('id')->chunkById(100,/** Inline callback for this operation. */ function($rows)use(&$dispatch){foreach($rows as $row){DB::transaction(/** Inline callback for this operation. */ function()use($row,&$dispatch){$s=Shipment::query()->whereKey($row->id)->lockForUpdate()->first();if(!$s||$s->dispatch_breached_at||$s->picked_up_at)return;$s->update(['dispatch_breached_at'=>now()]);$s->events()->firstOrCreate(['provider_event_id'=>'internal:sla-dispatch:'.$s->public_id],['public_id'=>(string)Str::ulid(),'status'=>$s->status,'code'=>'sla.dispatch_breached','message'=>'Seller dispatch SLA breached.','occurred_at'=>now(),'payload'=>['source'=>'scheduler']]);$dispatch++;});}});
        Shipment::query()->whereNull('delivery_breached_at')->whereNull('delivered_at')->whereNotNull('delivery_due_at')->where('delivery_due_at','<',now())->whereNotIn('status',[ShipmentStatus::Cancelled->value,ShipmentStatus::ReturnedToSender->value])->select('id')->chunkById(100,/** Inline callback for this operation. */ function($rows)use(&$delivery){foreach($rows as $row){DB::transaction(/** Inline callback for this operation. */ function()use($row,&$delivery){$s=Shipment::query()->whereKey($row->id)->lockForUpdate()->first();if(!$s||$s->delivery_breached_at||$s->delivered_at)return;$s->update(['delivery_breached_at'=>now()]);$s->events()->firstOrCreate(['provider_event_id'=>'internal:sla-delivery:'.$s->public_id],['public_id'=>(string)Str::ulid(),'status'=>$s->status,'code'=>'sla.delivery_breached','message'=>'Customer delivery SLA breached.','occurred_at'=>now(),'payload'=>['source'=>'scheduler']]);$delivery++;});}});
        return ['dispatchBreaches'=>$dispatch,'deliveryBreaches'=>$delivery];
    }
}
