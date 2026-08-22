<?php
namespace App\Domain\Finance\Services;
use App\Domain\Finance\FinanceAccounts;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\GameStatus;
use App\Models\AffiliateCommission;
use App\Models\FinanceEntry;
use App\Models\Game;
use App\Models\VendorPayout;
use App\Models\Wallet;
/** Defines the FinanceDashboardService class and its project responsibilities. */
class FinanceDashboardService
{
    /** Handles balance for the finance dashboard service workflow. */
    private function balance(string $account,bool $creditNormal):int{$d=(int)FinanceEntry::query()->where('account_code',$account)->where('direction','debit')->sum('amount_minor');$c=(int)FinanceEntry::query()->where('account_code',$account)->where('direction','credit')->sum('amount_minor');return max(0,$creditNormal?$c-$d:$d-$c);}
    /** Handles summary for the finance dashboard service workflow. */
    public function summary():array
    {
        $coins=(int)Wallet::query()->where('balance_coins','>',0)->sum('balance_coins');$per=max(1,(int)config('vsn.coins_per_rupee',70));$coinMinor=intdiv($coins*100,$per);
        $affiliateCoins=(int)AffiliateCommission::query()->whereIn('status',[AffiliateCommissionStatus::Pending->value,AffiliateCommissionStatus::Available->value])->sum('reward_coins');$affiliateMinor=intdiv($affiliateCoins*100,$per);
        $gameMinor=(int)Game::query()->whereIn('status',[GameStatus::WinnerSelected->value])->join('products','products.id','=','games.product_id')->sum('products.base_price_minor');
        return ['currency'=>config('vsn.currency','PKR'),'ledger'=>['sellerPayableMinor'=>$this->balance(FinanceAccounts::SELLER_PAYABLE,true),'platformCommissionRevenueMinor'=>$this->balance(FinanceAccounts::PLATFORM_COMMISSION,true),'reviewCouponSubsidyExpenseMinor'=>$this->balance(FinanceAccounts::COUPON_SUBSIDY,false),'paymentClearingMinor'=>$this->balance(FinanceAccounts::PAYMENT_CLEARING,false),'codReceivableMinor'=>$this->balance(FinanceAccounts::COD_RECEIVABLE,false),'sellerRecoveryReceivableMinor'=>$this->balance(FinanceAccounts::SELLER_RECOVERY,false)],'operationalLiabilities'=>['vsnCoinLiabilityMinor'=>$coinMinor,'affiliatePendingLiabilityMinor'=>$affiliateMinor,'gamePrizeLiabilityMinor'=>$gameMinor],'payouts'=>['requestedMinor'=>(int)VendorPayout::query()->whereIn('status',['requested','approved','processing'])->sum('amount_minor'),'failedMinor'=>(int)VendorPayout::query()->where('status','failed')->sum('amount_minor'),'failedCount'=>(int)VendorPayout::query()->where('status','failed')->count(),'paidMinor'=>(int)VendorPayout::query()->where('status','paid')->sum('amount_minor')]];
    }
}
