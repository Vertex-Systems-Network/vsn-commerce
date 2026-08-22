<?php
namespace App\Domain\Notifications\Actions;
use App\Models\GameDraw;
use App\Models\GiftNotification;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\ReviewRewardCoupon;
use App\Models\ShipmentEvent;
/** Defines the ReconcileMarketplaceNotifications class and its project responsibilities. */
class ReconcileMarketplaceNotifications
{
    /** Initializes the ReconcileMarketplaceNotifications instance and its dependencies. */
    public function __construct(private readonly PublishMarketplaceNotification $publish){}
    /** Executes the reconcile marketplace notifications operation. */
    public function execute(int $limit=250):array
    {
        $counts=['orders'=>0,'shipping'=>0,'gifts'=>0,'games'=>0,'reviews'=>0,'returns'=>0];
        $since=now()->subDays((int)config('vsn.notifications.backfill_days',30));

        Order::query()->where('placed_at','>=',$since)->with('user')->latest('placed_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(Order $order)use(&$counts){
            if(!$order->user)return;
            $this->publish->execute($order->user,'orders','order.placed','Order received',"Your order {$order->public_id} has been placed.","order:placed:{$order->public_id}",'/orders','order',$order->public_id,['status'=>$order->status->value,'paymentStatus'=>$order->payment_status->value]);$counts['orders']++;
        });

        ShipmentEvent::query()->where('created_at','>=',$since)->with('shipment.order.user')->latest('created_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(ShipmentEvent $event)use(&$counts){
            $shipment=$event->shipment;$user=$shipment?->order?->user;if(!$shipment||!$user)return;
            $status=$event->status->value;$title=match($status){'delivered'=>'Delivered','out_for_delivery'=>'Out for delivery','delivery_failed'=>'Delivery attempt failed','return_to_origin'=>'Shipment returning to sender','returned_to_sender'=>'Returned to sender',default=>'Shipment update'};
            $body=$event->message ?: "Shipment {$shipment->tracking_number} is now ".str_replace('_',' ',$status).'.';
            $this->publish->execute($user,'shipping',"shipment.{$status}",$title,$body,"shipment-event:{$event->public_id}",'/tracking?order='.urlencode($shipment->order->public_id),'shipment',$shipment->public_id,['trackingNumber'=>$shipment->tracking_number,'status'=>$status,'location'=>$event->location]);$counts['shipping']++;
        });

        GiftNotification::query()->where('status','delivered')->where('delivered_at','>=',$since)->with(['gift.product','recipient'])->latest('delivered_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(GiftNotification $event)use(&$counts){
            if(!$event->recipient||!$event->gift)return;
            $gift=$event->gift;$title='You received a gift';$body=$gift->anonymous?'A VSN Ecommerce member sent you a gift.':(($gift->sender?->name ?? 'Someone').' sent you a gift.');
            $this->publish->execute($event->recipient,'gifts','gift.received',$title,$body,"gift-notification:{$event->public_id}",'/gifts','gift',$gift->public_id,['giftId'=>$gift->public_id,'anonymous'=>$gift->anonymous,'product'=>$gift->product?->name]);$counts['gifts']++;
        });

        GameDraw::query()->where('drawn_at','>=',$since)->with(['game.product','game.entries.user','winner'])->latest('drawn_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(GameDraw $draw)use(&$counts){
            $game=$draw->game;if(!$game)return;
            $seen=[];foreach($game->entries as $entry){$user=$entry->user;if(!$user||isset($seen[$user->id]))continue;$seen[$user->id]=true;$won=$draw->winner_user_id===$user->id;
                $this->publish->execute($user,'games',$won?'game.won':'game.result',$won?'You won the Game Win draw':'Game Win result available',$won?"You won {$game->product?->name}. Open the game for draw proof and fulfilment details.":"The winner for {$game->product?->name} has been selected. Open the game to verify the draw proof.","game-draw:{$draw->public_id}:{$user->id}",'/games','game',$game->public_id,['won'=>$won,'winningTicket'=>$draw->winning_ticket_number]);$counts['games']++;}
        });

        ReviewRewardCoupon::query()->where('issued_at','>=',$since)->with(['user','review.product'])->latest('issued_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(ReviewRewardCoupon $coupon)use(&$counts){if(!$coupon->user)return;$this->publish->execute($coupon->user,'rewards','review.coupon_issued','10% review coupon unlocked',"Your verified-review reward coupon {$coupon->code} is ready to use once.","review-coupon:issued:{$coupon->public_id}",'/reviews','review_coupon',$coupon->public_id,['code'=>$coupon->code,'expiresAt'=>$coupon->expires_at?->toISOString()]);$counts['reviews']++;});
        Review::query()->whereNotNull('moderated_at')->where('moderated_at','>=',$since)->with(['user','product'])->latest('moderated_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(Review $review)use(&$counts){if(!$review->user)return;$status=$review->status->value;$this->publish->execute($review->user,'reviews',"review.{$status}",$status==='approved'?'Review published':'Review moderation update',$status==='approved'?"Your review for {$review->product?->name} is now public.":"Your review for {$review->product?->name} was not published.","review:moderated:{$review->public_id}:{$status}",'/reviews','review',$review->public_id,['status'=>$status]);$counts['reviews']++;});

        ReturnRequest::query()->where('submitted_at','>=',$since)->with(['user','order'])->latest('submitted_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(ReturnRequest $request)use(&$counts){if(!$request->user)return;$this->publish->execute($request->user,'returns','return.created','Return request received',"Return {$request->public_id} for order {$request->order?->public_id} is {$request->status->value}.","return:state:{$request->public_id}:{$request->status->value}",'/returns','return',$request->public_id,['status'=>$request->status->value,'resolution'=>$request->resolution?->value]);$counts['returns']++;});
        Refund::query()->whereNotNull('processed_at')->where('processed_at','>=',$since)->with('order.user')->latest('processed_at')->limit($limit)->get()->each(/** Inline callback for this operation. */ function(Refund $refund)use(&$counts){$user=$refund->order?->user;if(!$user)return;$this->publish->execute($user,'returns','refund.completed','Refund completed',"Refund {$refund->public_id} has been completed.","refund:completed:{$refund->public_id}",'/returns','refund',$refund->public_id,['amountMinor'=>$refund->amount_minor,'coinRefundCoins'=>$refund->coin_refund_coins]);$counts['returns']++;});
        return $counts;
    }
}
