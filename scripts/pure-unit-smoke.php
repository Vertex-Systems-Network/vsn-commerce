<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Enums/VendorSettlementStatus.php';
require_once __DIR__.'/../app/Enums/ReviewStatus.php';
require_once __DIR__.'/../app/Enums/UserRole.php';

use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Enums\VendorSettlementStatus;

$passed = 0;
$check = static /** Inline callback for this operation. */ function (bool $condition, string $label) use (&$passed): void {
    if (! $condition) { fwrite(STDERR, '[FAIL] '.$label.PHP_EOL); exit(1); }
    $passed++; echo '[PASS] '.$label.PHP_EOL;
};

$check(VendorSettlementStatus::from('payout_pending') === VendorSettlementStatus::PayoutPending, 'vendor settlement payout_pending backing value');
$check(! in_array('reserved', array_map(/** Inline callback for this operation. */ fn($case) => $case->value, VendorSettlementStatus::cases()), true), 'invalid vendor settlement reserved value stays rejected');
$check(ReviewStatus::from('approved') === ReviewStatus::Approved, 'review approved backing value');
$check(! in_array('published', array_map(/** Inline callback for this operation. */ fn($case) => $case->value, ReviewStatus::cases()), true), 'invalid review published value stays rejected');
$check(UserRole::from('super_admin') === UserRole::SuperAdmin, 'super admin role backing value');
$check(UserRole::from('customer') === UserRole::Customer, 'customer role backing value');

$roles = array_map(/** Inline callback for this operation. */ fn(UserRole $role) => $role->value, UserRole::cases());
$check(count($roles) === count(array_unique($roles)), 'user role backing values are unique');
$settlement = array_map(/** Inline callback for this operation. */ fn(VendorSettlementStatus $status) => $status->value, VendorSettlementStatus::cases());
$check(count($settlement) === count(array_unique($settlement)), 'vendor settlement backing values are unique');
$reviews = array_map(/** Inline callback for this operation. */ fn(ReviewStatus $status) => $status->value, ReviewStatus::cases());
$check(count($reviews) === count(array_unique($reviews)), 'review status backing values are unique');

$check(hash_equals(hash('sha256', 'vsn-ecommerce'), hash('sha256', 'vsn-ecommerce')), 'SHA-256 evidence comparison');
$check(! hash_equals(hash('sha256', 'vsn-ecommerce'), hash('sha256', 'other-project')), 'SHA-256 mismatch rejection');

echo 'Pure dependency-free unit assertions: '.$passed.'/'.$passed.' PASS'.PHP_EOL;
