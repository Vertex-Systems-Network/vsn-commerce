<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Actions\ReconcileVendorSettlements;
use App\Domain\Finance\FinanceAccounts;
use App\Enums\VendorSettlementStatus;
use App\Models\FinanceEntry;
use App\Models\Vendor;

/** Defines the VendorFinanceService class and its project responsibilities. */
class VendorFinanceService
{
    /** Initializes the VendorFinanceService instance and its dependencies. */
    public function __construct(private readonly ReconcileVendorSettlements $reconcile) {}

    /** Handles summary for the vendor finance service workflow. */
    public function summary(Vendor $vendor): array
    {
        $this->reconcile->execute($vendor->id);
        $settlements = $vendor->settlements()->with('vendorOrder')->get();

        $available = $settlements
            ->where('status', VendorSettlementStatus::Available)
            ->sum(/** Inline callback for this operation. */ fn ($settlement) => $settlement->availableMinor());
        $reserved = $settlements->sum('payout_reserved_minor');
        $paid = $settlements->sum('paid_out_minor');
        $pending = max(
            0,
            $settlements->sum(/** Inline callback for this operation. */ fn ($settlement) => $settlement->remainingPayableMinor())
                - $available
                - $reserved
        );

        $recoveryDebit = (int) FinanceEntry::query()
            ->where('vendor_id', $vendor->id)
            ->where('account_code', FinanceAccounts::SELLER_RECOVERY)
            ->where('direction', 'debit')
            ->sum('amount_minor');
        $recoveryCredit = (int) FinanceEntry::query()
            ->where('vendor_id', $vendor->id)
            ->where('account_code', FinanceAccounts::SELLER_RECOVERY)
            ->where('direction', 'credit')
            ->sum('amount_minor');
        $recovery = max(0, $recoveryDebit - $recoveryCredit);

        $defaultMethod = $vendor->payoutMethods()
            ->where('is_default', true)
            ->whereNull('revoked_at')
            ->first();

        return [
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'slug' => $vendor->slug,
            ],
            'currency' => config('vsn.currency', 'PKR'),
            'availableMinor' => (int) $available,
            'pendingMinor' => (int) $pending,
            'payoutReservedMinor' => (int) $reserved,
            'paidMinor' => (int) $paid,
            'sellerRecoveryOutstandingMinor' => $recovery,
            'minimumPayoutMinor' => (int) config('vsn.finance.minimum_payout_minor', 100000),
            'payoutHoldDays' => (int) config('vsn.finance.payout_hold_days', 30),
            'payoutReady' => (bool) (
                $defaultMethod
                && ! $defaultMethod->revoked_at
                && (
                    ! config('vsn.finance.require_verified_payout_method', true)
                    || $defaultMethod->verified_at
                )
            ),
            'defaultPayoutMethod' => $defaultMethod ? [
                'id' => $defaultMethod->public_id,
                'bankName' => $defaultMethod->bank_name,
                'accountLast4' => $defaultMethod->account_last4,
                'verified' => (bool) $defaultMethod->verified_at,
            ] : null,
            'holdBreakdown' => [
                'paymentMinor' => (int) $settlements
                    ->where('status', VendorSettlementStatus::HoldPayment)
                    ->sum(/** Inline callback for this operation. */ fn ($settlement) => $settlement->remainingPayableMinor()),
                'deliveryMinor' => (int) $settlements
                    ->where('status', VendorSettlementStatus::HoldDelivery)
                    ->sum(/** Inline callback for this operation. */ fn ($settlement) => $settlement->remainingPayableMinor()),
                'returnWindowMinor' => (int) $settlements
                    ->where('status', VendorSettlementStatus::HoldReturnWindow)
                    ->sum(/** Inline callback for this operation. */ fn ($settlement) => $settlement->remainingPayableMinor()),
            ],
            'lifetimeSellerPayableMinor' => (int) $settlements->sum('seller_payable_minor'),
            'lifetimeReversedMinor' => (int) $settlements->sum('seller_payable_reversed_minor'),
            'lifetimeRecoveryOffsetMinor' => (int) $settlements->sum('seller_recovery_offset_minor'),
            'settlements' => $settlements
                ->sortByDesc('id')
                ->take(25)
                ->values()
                ->map(/** Inline callback for this operation. */ fn ($settlement) => [
                    'id' => $settlement->public_id,
                    'vendorOrderId' => $settlement->vendorOrder?->public_id,
                    'status' => $settlement->status->value,
                    'sellerPayableMinor' => $settlement->seller_payable_minor,
                    'reversedMinor' => $settlement->seller_payable_reversed_minor,
                    'recoveryOffsetMinor' => $settlement->seller_recovery_offset_minor,
                    'reservedMinor' => $settlement->payout_reserved_minor,
                    'paidMinor' => $settlement->paid_out_minor,
                    'availableMinor' => $settlement->availableMinor(),
                    'eligibleAt' => $settlement->eligible_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
