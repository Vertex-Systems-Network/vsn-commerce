<?php
namespace App\Domain\Finance\Actions;
use App\Domain\Finance\FinanceAccounts;
use App\Enums\FinanceDirection;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
/** Defines the ConfirmCodCollection class and its project responsibilities. */
class ConfirmCodCollection
{
    /** Initializes the ConfirmCodCollection instance and its dependencies. */
    public function __construct(private readonly PostFinanceJournal $journal,private readonly ReconcileVendorSettlements $reconcile){}
    /** Executes the confirm cod collection operation. */
    public function execute(Order $order):Order
    {return DB::transaction(/** Inline callback for this operation. */ function()use($order):Order{$order=Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();if($order->payment_method!=='cod')abort(422,'Only COD orders use collection confirmation.');if($order->payment_status===PaymentStatus::Paid)return $order;
        $amount=(int)$order->total_minor;if($amount>0)$this->journal->execute('cod_collection',$order->currency,"finance-cod-collection:{$order->public_id}",[
            ['account'=>FinanceAccounts::PAYMENT_CLEARING,'direction'=>FinanceDirection::Debit->value,'amount'=>$amount],['account'=>FinanceAccounts::COD_RECEIVABLE,'direction'=>FinanceDirection::Credit->value,'amount'=>$amount],
        ],'order',$order->public_id);
        $order->update(['payment_status'=>PaymentStatus::Paid]);foreach($order->vendorOrders()->pluck('vendor_id')->filter()->unique() as $vendorId)$this->reconcile->execute((int)$vendorId);return $order->fresh();},3);}
}
