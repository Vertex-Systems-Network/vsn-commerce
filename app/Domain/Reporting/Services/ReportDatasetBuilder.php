<?php
namespace App\Domain\Reporting\Services;

use App\Domain\Finance\FinanceAccounts;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\AffiliateCommission;
use App\Models\FinanceEntry;
use App\Models\Game;
use App\Models\GameEntry;
use App\Models\Order;
use App\Models\PromotionUsage;
use App\Models\Refund;
use App\Models\VendorOrder;
use App\Models\VendorPayout;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Defines the ReportDatasetBuilder class and its project responsibilities. */
class ReportDatasetBuilder
{
    public const TYPES=['executive_summary','orders','sellers','finance_ledger','promotions','affiliate','wallet','games','refunds','tax','customer_cohorts'];

    /** Initializes the ReportDatasetBuilder instance and its dependencies. */
    public function __construct(private readonly MarketplaceAnalyticsService $analytics){}

    /** Handles types for the report dataset builder workflow. */
    public function types():array{return self::TYPES;}

    /** Handles build for the report dataset builder workflow. */
    public function build(string $type,array $filters):array
    {
        abort_unless(in_array($type,self::TYPES,true),422,'Unsupported report type.');
        [$from,$to]=$this->analytics->range($filters);$currency=(string)($filters['currency']??config('vsn.currency','PKR'));
        return match($type){
            'executive_summary'=>$this->executive($filters),
            'orders'=>$this->orders($from,$to,$currency),
            'sellers'=>$this->sellers($from,$to,$currency),
            'finance_ledger'=>$this->finance($from,$to,$currency),
            'promotions'=>$this->promotions($from,$to),
            'affiliate'=>$this->affiliate($from,$to,$currency),
            'wallet'=>$this->wallet($from,$to),
            'games'=>$this->games($from,$to),
            'refunds'=>$this->refunds($from,$to,$currency),
            'tax'=>$this->tax($from,$to,$currency),
            'customer_cohorts'=>$this->cohorts($filters),
        };
    }

    /** Handles executive for the report dataset builder workflow. */
    private function executive(array $filters):array
    {
        $d=$this->analytics->dashboard($filters);$rows=[];
        foreach(['commerce','finance','liabilities','affiliate','games','tax'] as $section)foreach(($d[$section]??[]) as $key=>$value)if(is_scalar($value)||$value===null)$rows[]=['section'=>$section,'metric'=>$key,'value'=>$value];
        return ['headers'=>['section','metric','value'],'rows'=>$rows];
    }

    /** Handles orders for the report dataset builder workflow. */
    private function orders(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $rows=Order::query()->where('currency',$currency)->whereBetween('placed_at',[$from,$to])->withCount('items')->orderBy('placed_at')->get()->map(/** Inline callback for this operation. */ fn($o)=>[
            'order_id'=>$o->public_id,'customer_ref'=>$this->customerRef((int)$o->user_id),'status'=>$o->status->value,'payment_status'=>$o->payment_status->value,'payment_method'=>$o->payment_method,'items'=>$o->items_count,'subtotal_minor'=>(int)$o->subtotal_minor,'shipping_minor'=>(int)$o->shipping_minor,'discount_minor'=>(int)$o->discount_minor,'platform_discount_minor'=>(int)$o->platform_discount_minor,'seller_discount_minor'=>(int)$o->seller_discount_minor,'tax_minor'=>(int)$o->tax_minor,'coin_redemption_minor'=>(int)$o->coin_redemption_minor,'order_value_minor'=>(int)$o->subtotal_minor+(int)$o->shipping_minor-(int)$o->discount_minor+(int)$o->tax_added_minor,'refunded_minor'=>(int)$o->refunded_minor,'currency'=>$o->currency,'placed_at'=>$o->placed_at?->toIso8601String(),'delivered_at'=>$o->delivered_at?->toIso8601String()
        ])->all();
        return ['headers'=>array_keys($rows[0]??['order_id'=>null,'customer_ref'=>null,'status'=>null,'payment_status'=>null,'payment_method'=>null,'items'=>null,'subtotal_minor'=>null,'shipping_minor'=>null,'discount_minor'=>null,'platform_discount_minor'=>null,'seller_discount_minor'=>null,'tax_minor'=>null,'coin_redemption_minor'=>null,'order_value_minor'=>null,'refunded_minor'=>null,'currency'=>null,'placed_at'=>null,'delivered_at'=>null]),'rows'=>$rows];
    }

    /** Handles sellers for the report dataset builder workflow. */
    private function sellers(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $rows=VendorOrder::query()->join('orders','orders.id','=','vendor_orders.order_id')->leftJoin('vendors','vendors.id','=','vendor_orders.vendor_id')->whereIn('orders.payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value,PaymentStatus::Refunded->value])->where('vendor_orders.currency',$currency)->whereBetween('orders.placed_at',[$from,$to])
            ->groupBy('vendor_orders.vendor_id','vendors.name','vendors.slug')->selectRaw('vendor_orders.vendor_id, vendors.name, vendors.slug, COUNT(*) orders_count, SUM(vendor_orders.subtotal_minor) merchandise_minor, SUM(vendor_orders.shipping_minor) shipping_minor, SUM(vendor_orders.discount_minor) discount_minor, SUM(vendor_orders.platform_commission_minor) commission_minor, SUM(vendor_orders.seller_payable_minor) seller_payable_minor, SUM(vendor_orders.seller_payable_reversed_minor) seller_reversed_minor, SUM(vendor_orders.tax_minor) tax_minor')->orderByDesc('merchandise_minor')->get()->map(/** Inline callback for this operation. */ fn($r)=>['vendor_id'=>$r->vendor_id,'vendor'=>$r->name??'Platform','slug'=>$r->slug,'orders'=>(int)$r->orders_count,'merchandise_minor'=>(int)$r->merchandise_minor,'shipping_minor'=>(int)$r->shipping_minor,'discount_minor'=>(int)$r->discount_minor,'commission_minor'=>(int)$r->commission_minor,'seller_payable_minor'=>(int)$r->seller_payable_minor,'seller_reversed_minor'=>(int)$r->seller_reversed_minor,'tax_minor'=>(int)$r->tax_minor,'currency'=>$currency])->all();
        return ['headers'=>array_keys($rows[0]??['vendor_id'=>null,'vendor'=>null,'slug'=>null,'orders'=>null,'merchandise_minor'=>null,'shipping_minor'=>null,'discount_minor'=>null,'commission_minor'=>null,'seller_payable_minor'=>null,'seller_reversed_minor'=>null,'tax_minor'=>null,'currency'=>null]),'rows'=>$rows];
    }

    /** Handles finance for the report dataset builder workflow. */
    private function finance(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $rows=FinanceEntry::query()->join('finance_journals','finance_journals.id','=','finance_entries.finance_journal_id')->leftJoin('vendors','vendors.id','=','finance_entries.vendor_id')->where('finance_journals.currency',$currency)->whereBetween('finance_journals.posted_at',[$from,$to])->orderBy('finance_journals.posted_at')->orderBy('finance_entries.id')->get(['finance_journals.public_id as journal_id','finance_journals.type','finance_journals.reference_type','finance_journals.reference_id','finance_journals.posted_at','finance_entries.account_code','finance_entries.direction','finance_entries.amount_minor','vendors.name as vendor'])->map(/** Inline callback for this operation. */ fn($r)=>['journal_id'=>$r->journal_id,'type'=>$r->type,'reference_type'=>$r->reference_type,'reference_id'=>$r->reference_id,'posted_at'=>(string)$r->posted_at,'account_code'=>$r->account_code,'direction'=>$r->direction,'amount_minor'=>(int)$r->amount_minor,'currency'=>$currency,'vendor'=>$r->vendor])->all();
        return ['headers'=>['journal_id','type','reference_type','reference_id','posted_at','account_code','direction','amount_minor','currency','vendor'],'rows'=>$rows];
    }

    /** Handles promotions for the report dataset builder workflow. */
    private function promotions(CarbonImmutable $from,CarbonImmutable $to):array
    {
        $rows=PromotionUsage::query()->with(['promotion:id,public_id,name,vendor_id','promotion.vendor:id,name','order:id,public_id,payment_status'])->where('status','redeemed')->whereBetween('redeemed_at',[$from,$to])->whereHas('order',/** Inline callback for this operation. */ fn($q)=>$q->whereIn('payment_status',[PaymentStatus::Paid->value,PaymentStatus::PartiallyRefunded->value,PaymentStatus::Refunded->value]))->orderBy('redeemed_at')->get()->map(/** Inline callback for this operation. */ fn($u)=>['promotion_id'=>$u->promotion?->public_id,'promotion'=>$u->promotion?->name,'vendor'=>$u->promotion?->vendor?->name,'order_id'=>$u->order?->public_id,'discount_minor'=>(int)$u->discount_minor,'platform_funded_minor'=>(int)$u->platform_funded_minor,'seller_funded_minor'=>(int)$u->seller_funded_minor,'redeemed_at'=>$u->redeemed_at?->toIso8601String()])->all();
        return ['headers'=>['promotion_id','promotion','vendor','order_id','discount_minor','platform_funded_minor','seller_funded_minor','redeemed_at'],'rows'=>$rows];
    }

    /** Handles affiliate for the report dataset builder workflow. */
    private function affiliate(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $rows=AffiliateCommission::query()->where('currency',$currency)->whereBetween('created_at',[$from,$to])->orderBy('created_at')->get()->map(/** Inline callback for this operation. */ fn($c)=>['commission_id'=>$c->public_id,'order_id'=>$c->order?->public_id,'buyer_ref'=>$this->customerRef((int)$c->buyer_id),'beneficiary_ref'=>$this->customerRef((int)$c->beneficiary_id),'level'=>(int)$c->level_no,'rate_bps'=>(int)$c->rate_bps,'eligible_spend_minor'=>(int)$c->eligible_spend_minor,'reward_coins'=>(int)$c->reward_coins,'status'=>$c->status->value,'available_at'=>$c->available_at?->toIso8601String(),'credited_at'=>$c->credited_at?->toIso8601String()])->all();
        return ['headers'=>['commission_id','order_id','buyer_ref','beneficiary_ref','level','rate_bps','eligible_spend_minor','reward_coins','status','available_at','credited_at'],'rows'=>$rows];
    }

    /** Handles wallet for the report dataset builder workflow. */
    private function wallet(CarbonImmutable $from,CarbonImmutable $to):array
    {
        $rows=DB::table('wallet_entries')->join('wallet_transactions','wallet_transactions.id','=','wallet_entries.wallet_transaction_id')->whereBetween('wallet_transactions.occurred_at',[$from,$to])->orderBy('wallet_transactions.occurred_at')->orderBy('wallet_entries.id')->get(['wallet_transactions.public_id as transaction_id','wallet_transactions.type','wallet_transactions.reference_type','wallet_transactions.reference_id','wallet_transactions.occurred_at','wallet_entries.user_id','wallet_entries.direction','wallet_entries.coins','wallet_entries.balance_after_coins'])->map(/** Inline callback for this operation. */ fn($r)=>['transaction_id'=>$r->transaction_id,'type'=>$r->type,'reference_type'=>$r->reference_type,'reference_id'=>$r->reference_id,'occurred_at'=>(string)$r->occurred_at,'customer_ref'=>$this->customerRef((int)$r->user_id),'direction'=>$r->direction,'coins'=>(int)$r->coins,'balance_after_coins'=>(int)$r->balance_after_coins])->all();
        return ['headers'=>['transaction_id','type','reference_type','reference_id','occurred_at','customer_ref','direction','coins','balance_after_coins'],'rows'=>$rows];
    }

    /** Handles games for the report dataset builder workflow. */
    private function games(CarbonImmutable $from,CarbonImmutable $to):array
    {
        $rows=Game::query()->with('product:id,name,base_price_minor,currency')->where(/** Inline callback for this operation. */ function($q)use($from,$to){$q->whereBetween('opens_at',[$from,$to])->orWhereBetween('drawn_at',[$from,$to])->orWhereBetween('cancelled_at',[$from,$to]);})->orderBy('opens_at')->get()->map(/** Inline callback for this operation. */ function($g){$entries=GameEntry::query()->where('game_id',$g->id);return ['game_id'=>$g->public_id,'product'=>$g->product?->name,'status'=>$g->status->value,'entry_coins'=>(int)$g->entry_coins,'entry_requests'=>(clone $entries)->count(),'tickets'=>(int)(clone $entries)->sum('quantity'),'coins_spent'=>(int)(clone $entries)->sum('coins_spent'),'prize_value_minor'=>(int)($g->product?->base_price_minor??0),'currency'=>$g->product?->currency,'opens_at'=>$g->opens_at?->toIso8601String(),'drawn_at'=>$g->drawn_at?->toIso8601String(),'fulfilled_at'=>$g->fulfilled_at?->toIso8601String()];})->all();
        return ['headers'=>['game_id','product','status','entry_coins','entry_requests','tickets','coins_spent','prize_value_minor','currency','opens_at','drawn_at','fulfilled_at'],'rows'=>$rows];
    }

    /** Handles refunds for the report dataset builder workflow. */
    private function refunds(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $rows=Refund::query()->where('currency',$currency)->whereBetween('created_at',[$from,$to])->with('order:id,public_id')->orderBy('created_at')->get()->map(/** Inline callback for this operation. */ fn($r)=>['refund_id'=>$r->public_id,'order_id'=>$r->order?->public_id,'status'=>$r->status->value,'resolution'=>$r->resolution->value,'amount_minor'=>(int)$r->amount_minor,'tax_refund_minor'=>(int)$r->tax_refund_minor,'cash_refund_minor'=>(int)$r->cash_refund_minor,'coin_refund_minor'=>(int)$r->coin_refund_minor,'coin_refund_coins'=>(int)$r->coin_refund_coins,'currency'=>$r->currency,'processed_at'=>$r->processed_at?->toIso8601String(),'created_at'=>$r->created_at?->toIso8601String()])->all();
        return ['headers'=>['refund_id','order_id','status','resolution','amount_minor','tax_refund_minor','cash_refund_minor','coin_refund_minor','coin_refund_coins','currency','processed_at','created_at'],'rows'=>$rows];
    }

    /** Handles tax for the report dataset builder workflow. */
    private function tax(CarbonImmutable $from,CarbonImmutable $to,string $currency):array
    {
        $rows=DB::table('order_tax_lines')->join('orders','orders.id','=','order_tax_lines.order_id')->leftJoin('vendors','vendors.id','=','order_tax_lines.vendor_id')->where('orders.currency',$currency)->whereBetween('orders.placed_at',[$from,$to])->orderBy('orders.placed_at')->get(['orders.public_id as order_id','order_tax_lines.source','order_tax_lines.source_reference','order_tax_lines.jurisdiction_name','order_tax_lines.tax_class_code','order_tax_lines.label','order_tax_lines.rate_bps','order_tax_lines.taxable_minor','order_tax_lines.tax_minor','order_tax_lines.price_includes_tax','order_tax_lines.liability_bearer','vendors.name as vendor'])->map(/** Inline callback for this operation. */ fn($r)=>['order_id'=>$r->order_id,'vendor'=>$r->vendor,'source'=>$r->source,'source_reference'=>$r->source_reference,'jurisdiction'=>$r->jurisdiction_name,'tax_class'=>$r->tax_class_code,'label'=>$r->label,'rate_bps'=>(int)$r->rate_bps,'taxable_minor'=>(int)$r->taxable_minor,'tax_minor'=>(int)$r->tax_minor,'price_includes_tax'=>(bool)$r->price_includes_tax?'yes':'no','liability_bearer'=>$r->liability_bearer,'currency'=>$currency])->all();
        return ['headers'=>['order_id','vendor','source','source_reference','jurisdiction','tax_class','label','rate_bps','taxable_minor','tax_minor','price_includes_tax','liability_bearer','currency'],'rows'=>$rows];
    }

    /** Handles cohorts for the report dataset builder workflow. */
    private function cohorts(array $filters):array
    {
        $rows=$this->analytics->dashboard($filters)['customers']??[];
        return ['headers'=>['cohort','newCustomers','repeatWithin30Days','repeatWithin30DaysBps','orderValueMinor'],'rows'=>$rows];
    }

    /** Handles customer ref for the report dataset builder workflow. */
    private function customerRef(int $id):string
    {
        $key=(string)config('app.key','vsn-reporting');return strtoupper(substr(hash_hmac('sha256',(string)$id,$key),0,16));
    }
}
