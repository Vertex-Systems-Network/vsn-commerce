<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Enums\VendorSettlementStatus;
use PHPUnit\Framework\TestCase;

/** Verifies pure domain enum contracts that seeders and workflow state machines depend on. */
final class DomainValueIntegrityTest extends TestCase
{
    /** Ensures payout reservation uses the valid settlement state and rejects the historical invalid literal. */
    public function test_vendor_settlement_payout_state_is_valid(): void
    {
        self::assertSame('payout_pending', VendorSettlementStatus::PayoutPending->value);
        self::assertNotContains('reserved', array_column(VendorSettlementStatus::cases(), 'value'));
    }

    /** Ensures seeded reviews use the approved state and reject the historical invalid published value. */
    public function test_review_status_contract_is_valid(): void
    {
        self::assertSame('approved', ReviewStatus::Approved->value);
        self::assertNotContains('published', array_column(ReviewStatus::cases(), 'value'));
    }

    /** Ensures privileged and customer roles keep the backing values used by authentication and RBAC. */
    public function test_primary_user_role_values_are_stable(): void
    {
        self::assertSame('super_admin', UserRole::SuperAdmin->value);
        self::assertSame('admin', UserRole::Admin->value);
        self::assertSame('seller', UserRole::Seller->value);
        self::assertSame('customer', UserRole::Customer->value);
    }

    /** Ensures each user role has a unique backing value. */
    public function test_user_role_backing_values_are_unique(): void
    {
        $values = array_column(UserRole::cases(), 'value');
        self::assertSame($values, array_values(array_unique($values)));
    }

    /** Ensures each order status has a unique backing value. */
    public function test_order_status_backing_values_are_unique(): void
    {
        $values = array_column(OrderStatus::cases(), 'value');
        self::assertSame($values, array_values(array_unique($values)));
    }

    /** Ensures each payment status has a unique backing value. */
    public function test_payment_status_backing_values_are_unique(): void
    {
        $values = array_column(PaymentStatus::cases(), 'value');
        self::assertSame($values, array_values(array_unique($values)));
    }

    /** Ensures each settlement status has a unique backing value. */
    public function test_settlement_status_backing_values_are_unique(): void
    {
        $values = array_column(VendorSettlementStatus::cases(), 'value');
        self::assertSame($values, array_values(array_unique($values)));
    }

    /** Ensures each review status has a unique backing value. */
    public function test_review_status_backing_values_are_unique(): void
    {
        $values = array_column(ReviewStatus::cases(), 'value');
        self::assertSame($values, array_values(array_unique($values)));
    }
}
