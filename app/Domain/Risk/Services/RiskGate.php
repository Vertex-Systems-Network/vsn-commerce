<?php
namespace App\Domain\Risk\Services;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Enums\WalletEntryDirection;
use App\Enums\WalletTransactionType;
use App\Models\{GameEntry,PaymentIntent,ReturnRequest,RiskHold,RiskProfile,User,Vendor,WalletEntry};
/** Defines the RiskGate class and its project responsibilities. */
class RiskGate {
    /** Initializes the RiskGate instance and its dependencies. */
    public function __construct(private readonly RiskRecorder $events){}
    /** Handles held for the risk gate workflow. */
    public function held(User $user,string $scope,?Vendor $vendor=null):?RiskHold {
        RiskHold::query()->where('status','active')->whereNotNull('expires_at')->where('expires_at','<=',now())->update(['status'=>'expired']);
        return RiskHold::query()->where('status','active')->where('starts_at','<=',now())->where(/** Inline callback for this operation. */ fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))
            ->whereIn('scope',[$scope,'all'])->where(/** Inline callback for this operation. */ function($q)use($user,$vendor){$q->where('user_id',$user->id);if($vendor)$q->orWhere('vendor_id',$vendor->id);})->latest('id')->first();
    }
    /** Handles assert allowed for the risk gate workflow. */
    public function assertAllowed(User $user,string $scope,?Vendor $vendor=null):void {
        $hold=$this->held($user,$scope,$vendor);
        if($hold){$this->events->record($user,$vendor,'risk_hold_blocked_action','high',0,$scope,'risk_hold',$hold->public_id,null,['scope'=>$scope]);throw new RiskBlockedException('This action is temporarily restricted while an account review is in progress.',$scope);}
        if(config('vsn.risk.mode','observe')==='enforce'){
            $threshold=(int)config('vsn.risk.critical_score',75);
            $userScore=(int)(RiskProfile::query()->where('user_id',$user->id)->value('score')??0);
            $vendorScore=$vendor?(int)(RiskProfile::query()->where('vendor_id',$vendor->id)->value('score')??0):0;
            if(max($userScore,$vendorScore)>=$threshold){$this->events->record($user,$vendor,'critical_risk_score_blocked_action','critical',0,$scope,null,null,null,['scope'=>$scope,'userScore'=>$userScore,'vendorScore'=>$vendorScore]);throw new RiskBlockedException('This action requires manual review before it can continue.',$scope);}
        }
    }
    /** Handles wallet transfer for the risk gate workflow. */
    public function walletTransfer(User $user,int $coins):void {
        $this->assertAllowed($user,'wallet');
        $hourCount=WalletEntry::query()->where('user_id',$user->id)->where('direction',WalletEntryDirection::Debit->value)->whereHas('transaction',/** Inline callback for this operation. */ fn($q)=>$q->whereIn('type',[WalletTransactionType::Transfer->value,WalletTransactionType::Gift->value])->where('occurred_at','>=',now()->subHour()))->count();
        $dayCoins=(int)WalletEntry::query()->where('user_id',$user->id)->where('direction',WalletEntryDirection::Debit->value)->whereHas('transaction',/** Inline callback for this operation. */ fn($q)=>$q->whereIn('type',[WalletTransactionType::Transfer->value,WalletTransactionType::Gift->value])->where('occurred_at','>=',now()->subDay()))->sum('coins');
        $maxHour=(int)config('vsn.risk.velocity.wallet_transfers_per_hour',10);$maxDay=(int)config('vsn.risk.velocity.wallet_transfer_coins_per_day',350000);
        if($hourCount>=$maxHour||$dayCoins+$coins>$maxDay){$this->events->record($user,null,'wallet_transfer_velocity_blocked','high',20,'wallet',null,null,null,['hourCount'=>$hourCount,'dayCoins'=>$dayCoins,'attemptCoins'=>$coins]);throw new RiskBlockedException('Wallet transfer velocity limit reached. Try again later.','wallet');}
    }
    /** Handles payment for the risk gate workflow. */
    public function payment(User $user):void {
        $this->assertAllowed($user,'payments');$count=PaymentIntent::query()->where('user_id',$user->id)->where('created_at','>=',now()->subMinutes(15))->count();$max=(int)config('vsn.risk.velocity.payment_intents_per_15m',8);
        if($count>=$max){$this->events->record($user,null,'payment_velocity_blocked','high',20,'payments',null,null,null,['count15m'=>$count]);throw new RiskBlockedException('Too many payment attempts. Try again later.','payments');}
    }
    /** Handles game for the risk gate workflow. */
    public function game(User $user,int $quantity):void {
        $this->assertAllowed($user,'games');$entries=(int)GameEntry::query()->where('user_id',$user->id)->where('created_at','>=',now()->subHour())->sum('quantity');$max=(int)config('vsn.risk.velocity.game_entries_per_hour',100);
        if($entries+$quantity>$max){$this->events->record($user,null,'game_entry_velocity_blocked','high',20,'games',null,null,null,['entries1h'=>$entries,'attempt'=>$quantity]);throw new RiskBlockedException('Game entry velocity limit reached. Try again later.','games');}
    }
    /** Handles returns for the risk gate workflow. */
    public function returns(User $user):void {
        $this->assertAllowed($user,'returns');$count=ReturnRequest::query()->where('user_id',$user->id)->where('submitted_at','>=',now()->subDay())->count();$max=(int)config('vsn.risk.velocity.return_requests_per_day',5);
        if($count>=$max){$this->events->record($user,null,'return_velocity_blocked','high',15,'returns',null,null,null,['count24h'=>$count]);throw new RiskBlockedException('Return request velocity limit reached. Contact support if you need help.','returns');}
    }
    /** Handles payout for the risk gate workflow. */
    public function payout(User $user,Vendor $vendor):void{$this->assertAllowed($user,'payouts',$vendor);}
    /** Handles affiliate for the risk gate workflow. */
    public function affiliate(User $user):void{$this->assertAllowed($user,'affiliate');}
}
