<?php
namespace App\Domain\Returns\Actions;
use App\Domain\Returns\Exceptions\ReturnException;
use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use Illuminate\Support\Facades\DB;
/** Defines the RestockReturnedItems class and its project responsibilities. */
class RestockReturnedItems
{
    /** Executes the restock returned items operation. */
    public function execute(ReturnRequest $request): void
    {
        $ids=$request->items()->whereNull('restocked_at')->orderBy('id')->pluck('id');
        foreach($ids as $id){
            DB::transaction(/** Inline callback for this operation. */ function() use($id): void {
                $row=ReturnRequestItem::query()->whereKey($id)->with('orderItem')->lockForUpdate()->firstOrFail();
                if($row->restocked_at)return;
                $qty=max(0,(int)$row->accepted_quantity);
                if($qty>0 && $row->restock){
                    $movement=InventoryMovement::query()->where('type',InventoryMovementType::Sale->value)->where('reference_type','order_item')->where('reference_id',(string)$row->order_item_id)->first();
                    if(!$movement) throw new ReturnException('Original inventory movement could not be located for this return item.');
                    $inventory=Inventory::query()->whereKey($movement->inventory_id)->lockForUpdate()->firstOrFail();
                    $inventory->on_hand += $qty; $inventory->save();
                    InventoryMovement::create(['inventory_id'=>$inventory->id,'type'=>InventoryMovementType::Return,'on_hand_delta'=>$qty,'reserved_delta'=>0,'reference_type'=>'return_request_item','reference_id'=>(string)$row->id,'metadata'=>['order_item_id'=>$row->order_item_id,'condition'=>$row->condition]]);
                }
                $row->update(['restocked_at'=>now()]);
                if($qty>0)$row->orderItem()->increment('returned_quantity',$qty);
            },3);
        }
    }
}
