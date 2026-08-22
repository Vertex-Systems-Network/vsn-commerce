<?php
namespace App\Domain\Reporting\Services;

use App\Domain\Finance\FinanceAccounts;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\GameStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AffiliateCommission;
use App\Models\FinanceEntry;
use App\Models\Game;
use App\Models\GameEntry;
use App\Models\GameEntryRefund;
use App\Models\KycVerification;
use App\Models\RiskCase;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\PromotionUsage;
use App\Models\ProductAlert;
use App\Models\ReturnRequest;
use App\Models\Refund;
use App\Models\User;
use App\Models\VendorOrder;
use App\Models\VendorPayout;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Defines the MarketplaceAnalyticsService class and its project responsibilities. */
class MarketplaceAnalyticsService
{
    /** Handles dashboard for the marketplace analytics service workflow. */
    public function dashboard(array $filters): array
    {
        [$from,$to,$timezone]=$this->range($filters);
        $currency=(string)($filters['currency']??config('vsn.currency','PKR'));
        $paid=$this->paidOrders($from,$to,$currency);
        $orders=(clone $paid)->get(['id','user_id','subtotal_minor','shipping_minor','discount_minor','platform_discount_minor','seller_discount_minor','tax_minor','tax_added_minor','total_minor','coin_redemption_minor','placed_at']);
        $orderIds=$orders->pluck('id');
        $completedRefunds=Refund::query()->whereIn('order_id',$orderIds)->where('status',RefundStatus::Completed->value)->whereBetween('processed_at',[$from,$to])->get(['id','order_id','amount_minor','tax_refund_minor','cash_refund_minor','coin_refund_minor','processed_at']);
        $manualRefunds=Refund::query()->whereIn('order_id',$orderIds)->where('status',RefundStatus::ManualPaymentRequired->value)->whereBetween('created_at',[$from,$to])->sum('amount_minor');
        $merchandise=(int)$orders->sum('subtotal_minor');
        $shipping=(int)$orders->sum('shipping_minor');
        $discounts=(int)$orders->sum('discount_minor');
        $platformDiscounts=(int)$orders->sum('platform_discount_minor');
        $sellerDiscounts=(int)$orders->sum('seller_discount_minor');
        $tax=(int)$orders->sum('tax_minor');
        $paidOrderValue=(int)$orders->sum(/** Inline callback for this operation. */ fn($o)=>(int)$o->subtotal_minor+(int)$o->shipping_minor-(int)$o->discount_minor+(int)$o->tax_added_minor);
        $refunds=(int)$completedRefunds->sum('amount_minor');
        $customerIds=$orders->pluck('user_id')->unique();
        $repeatCustomers=$this->repeatCustomerCount($customerIds->all(),$to);
        $finance=$this->financeRange($from,$to,$currency);
        $coinLiability=$this->coinLiability();
        $affiliate=$this->affiliateMetrics($from,$to,$currency);
        $games=$this->gameMetrics($from,$to,$currency);

        return [
            'range'=>['from'=>$from->toDateString(),'to'=>$to->toDateString(),'timezone'=>$timezone,'currency'=>$currency],
            'definitions'=>[
                'gmv'=>'Internal GMV is the paid order value: merchandise + shipping - discounts + tax added at checkout. VSN Coins are payment tender and do not reduce commerce value.',
                'paidOrderValue'=>'Merchandise + shipping - discounts + tax added at checkout. VSN Coins are payment tender and do not reduce commerce value.',
                'netOrderValue'=>'Paid order value less completed refunds in the selected period. Manual/COD refunds awaiting payment are shown separately.',
                'platformNetRevenue'=>'Posted platform commission revenue less posted platform-funded promotion/review-coupon subsidy expense. This is not company net profit.',
                'promotionRoi'=>'Promotion reporting shows attributed order value and return-on-discount multiple; it is attribution, not causal incrementality.',
            ],
            'marketplace'=>[
                'totalUsers'=>User::query()->count(),'newUsers'=>(int)User::query()->whereBetween('created_at',[$from,$to])->count(),
                'activeVendors'=>Vendor::query()->where('status','active')->count(),
            ],
            'operations'=>[
                'pendingKyc'=>KycVerification::query()->whereIn('status',['pending','under_review'])->count(),
                'openRiskCases'=>RiskCase::query()->whereIn('status',['open','reviewing'])->count(),
                'openReturns'=>ReturnRequest::query()->whereIn('status',['submitted','reviewing','approved','in_transit','received','disputed'])->count(),
                'activeProductAlerts'=>ProductAlert::query()->where('status','active')->count(),
                'gameDrawsDue24h'=>Game::query()->whereIn('status',[GameStatus::Open->value,GameStatus::Closed->value])->where('announcement_at','<=',now()->addDay())->count(),
                'manualRefundsPendingCount'=>Refund::query()->where('status',RefundStatus::ManualPaymentRequired->value)->count(),
            ],
            'commerce'=>[
                'orders'=>$orders->count(),'customers'=>$customerIds->count(),'repeatCustomers'=>$repeatCustomers,
                'repeatBuyerRateBps'=>$customerIds->count()?intdiv($repeatCustomers*10000,$customerIds->count()):0,
                'merchandiseGrossMinor'=>$merchandise,'shippingMinor'=>$shipping,'discountMinor'=>$discounts,
                'platformDiscountMinor'=>$platformDiscounts,'sellerDiscountMinor'=>$sellerDiscounts,'taxMinor'=>$tax,
                'gmvMinor'=>$paidOrderValue,'paidOrderValueMinor'=>$paidOrderValue,'completedRefundMinor'=>$refunds,'manualRefundPendingMinor'=>(int)$manualRefunds,
                'netOrderValueMinor'=>max(0,$paidOrderValue-$refunds),
                'averageOrderValueMinor'=>$orders->count()?intdiv($paidOrderValue,$orders->count()):0,
            ],
            'finance'=>array_merge($finance,['platformNetRevenueMinor'=>$finance['platformCommissionRevenueMinor']-$finance['platformSubsidyExpenseMinor']]),
            'liabilities'=>array_merge($coinLiability,$affiliate['liability'],$games['liability']),
            'affiliate'=>$affiliate['activity'],
            'games'=>$games['activity'],
            'trends'=>$this->dailyTrend($orders,$completedRefunds,$timezone),
            'sellers'=>$this->sellerBreakdown($from,$to,$currency),
            'promotions'=>$this->promotionBreakdown($from,$to,$currency),
            'tax'=>$this->taxBreakdown($orders,$completedRefunds),
            'customers'=>$this->customerCohorts($from,$to,$currency),
        ];
    }

    /** Handles range for the marketplace analytics service workflow. */
    public function range(array $filters): array
    {
        $timezone=(string)($filters['timezone']??config('vsn.reporting.timezone','Asia/Karachi'));
        try{$now=CarbonImmutable::now($timezone);}catch(\Throwable){$timezone='UTC';$now=CarbonImmutable::now('UTC');}
        $to=isset($filters['to'])?CarbonImmutable::parse((string)$filters['to'],$timezone)->endOfDay():$now->endOfDay();
        $from=isset($filters['from'])?CarbonImmutable::parse((string)$filters['from'],$timezone)->startOfDay():$to->subDays(29)->startOfDay();
        $maxDays=max(1,(int)config('vsn.reporting.max_dashboard_days',366));
        if($from->gt($to))[$from,$to]=[$to->startOfDay(),$from->endOfDay()];
        if($from->diffInDays($to)>$maxDays)$from=$to->subDays($maxDays-1)->startOfDay();
        return [$from->utc(),$to->utc(),$timezone];
    }

    /** Handles paid orders for the marketplace analytics service workflow. */
    private function paidOrders(CarbonImmutable $from,CarbonImmutable $to,string $currency):Builder
    {
        return Order::query()->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value,PaymentStatus::Refunded->value])
            ->where('currency',$currency)->whereBetween('placed_at',[$from,$to]);
    }

    /** Handles finance range for the marketplace analytics service workflow. */
    private function financeRange(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $balance=/** Inline callback for this operation. */ function(string $account,bool $creditNormal)use($from,$to,$currency):int{
            $q=FinanceEntry::query()->join('finance_journals','finance_journals.id','=','finance_entries.finance_journal_id')
                ->where('finance_entries.account_code',$account)->where('finance_journals.currency',$currency)->whereBetween('finance_journals.posted_at',[$from,$to]);
            $debits=(int)(clone $q)->where('finance_entries.direction','debit')->sum('finance_entries.amount_minor');
            $credits=(int)(clone $q)->where('finance_entries.direction','credit')->sum('finance_entries.amount_minor');
            return $creditNormal?$credits-$debits:$debits-$credits;
        };
        return [
            'platformCommissionRevenueMinor'=>$balance(FinanceAccounts::PLATFORM_COMMISSION,true),
            'platformSubsidyExpenseMinor'=>$balance(FinanceAccounts::COUPON_SUBSIDY,false),
            'sellerPayableMovementMinor'=>$balance(FinanceAccounts::SELLER_PAYABLE,true),
            'salesTaxPayableMovementMinor'=>$balance(FinanceAccounts::SALES_TAX_PAYABLE,true),
            'sellerRecoveryMovementMinor'=>$balance(FinanceAccounts::SELLER_RECOVERY,false),
            'sellerPayoutPaidMinor'=>(int)VendorPayout::query()->where('currency',$currency)->where('status','paid')->whereBetween('paid_at',[$from,$to])->sum('amount_minor'),
        ];
    }

    /** Handles coin liability for the marketplace analytics service workflow. */
    private function coinLiability():array
    {
        $coins=(int)Wallet::query()->where('balance_coins','>',0)->sum('balance_coins');
        $reserved=(int)Wallet::query()->where('reserved_coins','>',0)->sum('reserved_coins');
        $per=max(1,(int)config('vsn.coins_per_rupee',70));
        return ['vsnCoinsOutstanding'=>$coins,'vsnCoinsReserved'=>$reserved,'vsnCoinLiabilityMinor'=>intdiv($coins*100,$per)];
    }

    /** Handles affiliate metrics for the marketplace analytics service workflow. */
    private function affiliateMetrics(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $q=AffiliateCommission::query()->where('currency',$currency)->whereBetween('created_at',[$from,$to]);
        $reward=(int)(clone $q)->sum('reward_coins');
        $credited=(int)(clone $q)->where('status',AffiliateCommissionStatus::Credited->value)->sum('reward_coins');
        $pending=(int)AffiliateCommission::query()->where('currency',$currency)->whereIn('status',[AffiliateCommissionStatus::Pending->value,AffiliateCommissionStatus::Available->value])->sum('reward_coins');
        $per=max(1,(int)config('vsn.coins_per_rupee',70));
        return ['activity'=>['commissions'=>(clone $q)->count(),'rewardCoins'=>$reward,'creditedCoins'=>$credited,'rewardCostMinor'=>intdiv($reward*100,$per)],'liability'=>['affiliatePendingCoins'=>$pending,'affiliatePendingLiabilityMinor'=>intdiv($pending*100,$per)]];
    }

    /** Handles game metrics for the marketplace analytics service workflow. */
    private function gameMetrics(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $entries=GameEntry::query()->whereBetween('created_at',[$from,$to]);
        $coins=(int)(clone $entries)->sum('coins_spent');
        $refundCoins=(int)GameEntryRefund::query()->whereBetween('refunded_at',[$from,$to])->join('game_entries','game_entries.id','=','game_entry_refunds.game_entry_id')->sum('game_entries.coins_spent');
        $prizeLiability=(int)Game::query()->where('status',GameStatus::WinnerSelected->value)->whereNull('fulfilled_at')->join('products','products.id','=','games.product_id')->where('products.currency',$currency)->sum('products.base_price_minor');
        return ['activity'=>['entries'=>(clone $entries)->sum('quantity'),'entryCoins'=>$coins,'refundedEntryCoins'=>$refundCoins,'gamesDrawn'=>Game::query()->whereBetween('drawn_at',[$from,$to])->count()],'liability'=>['gamePrizeLiabilityMinor'=>$prizeLiability]];
    }

    /** Handles daily trend for the marketplace analytics service workflow. */
    private function dailyTrend($orders,$refunds,string $timezone):array
    {
        $rows=[];
        foreach($orders as $o){$d=$o->placed_at->timezone($timezone)->toDateString();$rows[$d]??=['date'=>$d,'orders'=>0,'paidOrderValueMinor'=>0,'refundMinor'=>0];$rows[$d]['orders']++;$rows[$d]['paidOrderValueMinor']+=(int)$o->subtotal_minor+(int)$o->shipping_minor-(int)$o->discount_minor+(int)$o->tax_added_minor;}
        $orderMap=$orders->keyBy('id');
        foreach($refunds as $r){$order=$orderMap->get($r->order_id);$date=($r->processed_at??$order?->placed_at)?->timezone($timezone)?->toDateString();if(!$date)continue;$rows[$date]??=['date'=>$date,'orders'=>0,'paidOrderValueMinor'=>0,'refundMinor'=>0];$rows[$date]['refundMinor']+=(int)$r->amount_minor;}
        ksort($rows);return array_values($rows);
    }

    /** Handles seller breakdown for the marketplace analytics service workflow. */
    private function sellerBreakdown(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        return VendorOrder::query()->join('orders','orders.id','=','vendor_orders.order_id')->leftJoin('vendors','vendors.id','=','vendor_orders.vendor_id')
            ->whereIn('orders.payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value,PaymentStatus::Refunded->value])->where('vendor_orders.currency',$currency)->whereBetween('orders.placed_at',[$from,$to])
            ->groupBy('vendor_orders.vendor_id','vendors.name')
            ->selectRaw('vendor_orders.vendor_id, vendors.name, COUNT(vendor_orders.id) as orders_count, SUM(vendor_orders.subtotal_minor + vendor_orders.shipping_minor - vendor_orders.discount_minor + vendor_orders.tax_added_minor) as order_value_minor, SUM(vendor_orders.platform_commission_minor) as commission_minor, SUM(vendor_orders.seller_payable_minor) as seller_payable_minor, SUM(vendor_orders.seller_payable_reversed_minor) as seller_reversed_minor')
            ->orderByDesc('order_value_minor')->limit(20)->get()->map(/** Inline callback for this operation. */ fn($r)=>['vendorId'=>$r->vendor_id,'vendor'=>$r->name??'Platform','orders'=>(int)$r->orders_count,'orderValueMinor'=>(int)$r->order_value_minor,'platformCommissionMinor'=>(int)$r->commission_minor,'sellerPayableMinor'=>(int)$r->seller_payable_minor,'sellerReversedMinor'=>(int)$r->seller_reversed_minor])->all();
    }

    /** Handles promotion breakdown for the marketplace analytics service workflow. */
    private function promotionBreakdown(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $rows=PromotionUsage::query()->with('promotion:id,public_id,name')->where('status','redeemed')->whereBetween('redeemed_at',[$from,$to])->whereNotNull('promotion_id')->get();
        return $rows->groupBy('promotion_id')->map(/** Inline callback for this operation. */ function($group){$discount=(int)$group->sum('discount_minor');$platform=(int)$group->sum('platform_funded_minor');$seller=(int)$group->sum('seller_funded_minor');$orderIds=$group->pluck('order_id')->filter()->unique();$value=(int)Order::query()->whereIn('id',$orderIds)->sum(DB::raw('subtotal_minor + shipping_minor - discount_minor + tax_added_minor'));return ['promotionId'=>$group->first()->promotion?->public_id,'name'=>$group->first()->promotion?->name??'Promotion','orders'=>$orderIds->count(),'discountMinor'=>$discount,'platformFundedMinor'=>$platform,'sellerFundedMinor'=>$seller,'attributedOrderValueMinor'=>$value,'returnOnDiscountBps'=>$discount?intdiv($value*10000,$discount):null];})->sortByDesc('attributedOrderValueMinor')->values()->take(20)->all();
    }

    /** Handles tax breakdown for the marketplace analytics service workflow. */
    private function taxBreakdown($orders,$refunds):array
    {
        return ['collectedMinor'=>(int)$orders->sum('tax_minor'),'addedAtCheckoutMinor'=>(int)$orders->sum('tax_added_minor'),'refundedMinor'=>(int)$refunds->sum('tax_refund_minor'),'netTaxMinor'=>max(0,(int)$orders->sum('tax_minor')-(int)$refunds->sum('tax_refund_minor'))];
    }

    /** Handles customer cohorts for the marketplace analytics service workflow. */
    private function customerCohorts(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $limit=max(1000,(int)config('vsn.reporting.max_cohort_customers',50000));
        $first=Order::query()->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value,PaymentStatus::Refunded->value])->where('currency',$currency)->selectRaw('user_id, MIN(placed_at) first_order_at')->groupBy('user_id')->havingRaw('MIN(placed_at) >= ? AND MIN(placed_at) <= ?',[$from,$to])->orderByRaw('MIN(placed_at) ASC')->limit($limit)->get();
        if($first->isEmpty())return [];
        $userIds=$first->pluck('user_id');$all=Order::query()->whereIn('user_id',$userIds)->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value,PaymentStatus::Refunded->value])->where('currency',$currency)->where('placed_at','<=',$to->addDays(30))->get(['user_id','subtotal_minor','shipping_minor','discount_minor','tax_added_minor','placed_at']);
        $byUser=$all->groupBy('user_id');$cohorts=[];
        foreach($first as $row){$firstAt=CarbonImmutable::parse($row->first_order_at);$key=$firstAt->format('Y-m');$cohorts[$key]??=['cohort'=>$key,'newCustomers'=>0,'repeatWithin30Days'=>0,'orderValueMinor'=>0];$cohorts[$key]['newCustomers']++;$orders=$byUser->get($row->user_id,collect());$repeat=$orders->filter(/** Inline callback for this operation. */ fn($o)=>CarbonImmutable::parse($o->placed_at)->gt($firstAt)&&CarbonImmutable::parse($o->placed_at)->lte($firstAt->addDays(30)))->isNotEmpty();if($repeat)$cohorts[$key]['repeatWithin30Days']++;$cohorts[$key]['orderValueMinor']+=(int)$orders->filter(/** Inline callback for this operation. */ fn($o)=>CarbonImmutable::parse($o->placed_at)->gte($firstAt)&&CarbonImmutable::parse($o->placed_at)->lte($firstAt->addDays(30)))->sum(/** Inline callback for this operation. */ fn($o)=>(int)$o->subtotal_minor+(int)$o->shipping_minor-(int)$o->discount_minor+(int)$o->tax_added_minor);}
        foreach($cohorts as &$c)$c['repeatWithin30DaysBps']=$c['newCustomers']?intdiv($c['repeatWithin30Days']*10000,$c['newCustomers']):0;unset($c);ksort($cohorts);return array_values($cohorts);
    }

    /** Handles repeat customer count for the marketplace analytics service workflow. */
    private function repeatCustomerCount(array $userIds,CarbonImmutable $to):int
    {
        if(!$userIds)return 0;
        return DB::query()->fromSub(Order::query()->selectRaw('user_id, COUNT(*) cnt')->whereIn('user_id',$userIds)->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value,PaymentStatus::Refunded->value])->where('placed_at','<=',$to)->groupBy('user_id'),'buyers')->where('cnt','>',1)->count();
    }
}
