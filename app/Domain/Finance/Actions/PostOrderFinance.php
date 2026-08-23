<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\FinanceAccounts;
use App\Enums\FinanceDirection;
use App\Enums\PaymentStatus;
use App\Enums\VendorSettlementStatus;
use App\Models\Order;
use App\Models\VendorSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the PostOrderFinance class and its project responsibilities. */
class PostOrderFinance
{
    /** Initializes the PostOrderFinance instance and its dependencies. */
    public function __construct(private readonly PostFinanceJournal $journal) {}

    /** Executes the post order finance operation. */
    public function execute(Order $order): void
    {
        DB::transaction(/** Inline callback for this operation. */ function () use ($order): void {
            $order = Order::query()->whereKey($order->id)->with('vendorOrders')->lockForUpdate()->firstOrFail();
            $entries = [];
            $cash = (int) $order->total_minor;
            if ($cash > 0) {
                $entries[] = ['account' => $order->payment_status === PaymentStatus::Paid ? FinanceAccounts::PAYMENT_CLEARING : FinanceAccounts::COD_RECEIVABLE, 'direction' => FinanceDirection::Debit->value, 'amount' => $cash];
            }
            if ((int) $order->coin_redemption_minor > 0) {
                $entries[] = ['account' => FinanceAccounts::COIN_LIABILITY, 'direction' => FinanceDirection::Debit->value, 'amount' => (int) $order->coin_redemption_minor];
            }
            // Only platform-funded discounts are an expense. Seller-funded promotions already reduce seller payable.
            // Legacy pre-Milestone-R orders had only discount_minor; treat those discounts as platform funded.
            $platformDiscount = (int) $order->platform_discount_minor;
            if ($platformDiscount === 0 && (int) $order->discount_minor > 0 && (int) $order->seller_discount_minor === 0) {
                $platformDiscount = (int) $order->discount_minor;
            }
            if ($platformDiscount > 0) {
                $entries[] = ['account' => FinanceAccounts::COUPON_SUBSIDY, 'direction' => FinanceDirection::Debit->value, 'amount' => $platformDiscount];
            }
            $commission = 0;
            $platformTax = 0;
            foreach ($order->vendorOrders as $vo) {
                if (! $vo->finance_posted_at) {
                    $sellerDiscount = (int) $vo->seller_discount_minor;
                    $vendorPlatformDiscount = max(0, (int) $vo->discount_minor - $sellerDiscount);
                    $existingSubsidy = (int) $vo->coupon_subsidy_minor;
                    $expectedSeller = max(0, (int) $vo->seller_payable_minor);
                    // Legacy rows reduced seller payable by the whole customer discount even
                    // when the platform funded it. Modern checkout rows already persist the
                    // subsidy and the correct payable, so restore only unmigrated legacy rows.
                    if ($vendorPlatformDiscount > 0 && $existingSubsidy === 0) {
                        $expectedSeller += $vendorPlatformDiscount;
                    }
                    $vo->update(['coupon_subsidy_minor' => $vendorPlatformDiscount, 'seller_payable_minor' => $expectedSeller]);
                    $vo->refresh();
                }
                if ((int) $vo->seller_payable_minor > 0) {
                    $entries[] = ['account' => FinanceAccounts::SELLER_PAYABLE, 'direction' => FinanceDirection::Credit->value, 'amount' => (int) $vo->seller_payable_minor, 'vendor_id' => $vo->vendor_id, 'metadata' => ['vendor_order_id' => $vo->public_id]];
                }
                $commission += (int) $vo->platform_commission_minor;
                $platformTax += (int) $vo->platform_tax_minor;
                VendorSettlement::query()->firstOrCreate(['vendor_order_id' => $vo->id], [
                    'public_id' => (string) Str::ulid(), 'vendor_id' => $vo->vendor_id, 'currency' => $vo->currency, 'gross_minor' => (int) $vo->subtotal_minor + (int) $vo->shipping_minor,
                    'customer_discount_minor' => (int) $vo->discount_minor, 'seller_discount_minor' => (int) $vo->seller_discount_minor, 'coupon_subsidy_minor' => (int) $vo->coupon_subsidy_minor,
                    'platform_commission_minor' => (int) $vo->platform_commission_minor, 'seller_payable_minor' => (int) $vo->seller_payable_minor, 'status' => VendorSettlementStatus::HoldPayment,
                ]);
                if (! $vo->finance_posted_at) {
                    $vo->update(['finance_posted_at' => now()]);
                }
            }
            if ($platformTax > 0) {
                $entries[] = ['account' => FinanceAccounts::SALES_TAX_PAYABLE, 'direction' => FinanceDirection::Credit->value, 'amount' => $platformTax];
            }
            if ($commission > 0) {
                $entries[] = ['account' => FinanceAccounts::PLATFORM_COMMISSION, 'direction' => FinanceDirection::Credit->value, 'amount' => $commission];
            }
            $this->journal->execute('order', ''.$order->currency, "finance-order:{$order->public_id}", $entries, 'order', $order->public_id, ['payment_method' => $order->payment_method, 'platform_discount_minor' => $platformDiscount, 'seller_discount_minor' => (int) $order->seller_discount_minor]);
        }, 3);
    }
}
