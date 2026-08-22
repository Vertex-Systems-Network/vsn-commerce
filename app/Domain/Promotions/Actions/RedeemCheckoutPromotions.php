<?php
namespace App\Domain\Promotions\Actions;
use App\Models\CheckoutSession;
use App\Models\Order;
/** Defines the RedeemCheckoutPromotions class and its project responsibilities. */
class RedeemCheckoutPromotions
{
    /** Executes the redeem checkout promotions operation. */
    public function execute(CheckoutSession $session,Order $order):void
    {
        $session->promotionUsages()->where('status','reserved')->update(['status'=>'redeemed','order_id'=>$order->id,'redeemed_at'=>now()]);
    }
}
