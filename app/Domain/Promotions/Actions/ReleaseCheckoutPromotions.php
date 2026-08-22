<?php
namespace App\Domain\Promotions\Actions;
use App\Models\CheckoutSession;
/** Defines the ReleaseCheckoutPromotions class and its project responsibilities. */
class ReleaseCheckoutPromotions
{
    /** Executes the release checkout promotions operation. */
    public function execute(CheckoutSession $session):void
    {
        $session->promotionUsages()->where('status','reserved')->update(['status'=>'released','released_at'=>now()]);
    }
}
