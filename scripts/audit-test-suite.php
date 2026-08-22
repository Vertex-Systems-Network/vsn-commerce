<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$testsRoot = $root.'/tests';
$errors = [];
$warnings = [];
$testMethods = 0;
$files = 0;

$critical = [
    'auth' => 'tests/Feature/AuthApiTest.php',
    'customer' => 'tests/Feature/CustomerAccountApiTest.php',
    'seller' => 'tests/Feature/SellerCenterApiTest.php',
    'admin' => 'tests/Feature/AdminOperationalPanelTest.php',
    'checkout' => 'tests/Feature/CheckoutApiTest.php',
    'payments' => 'tests/Feature/PaymentApiTest.php',
    'shipping' => 'tests/Feature/ShippingApiTest.php',
    'returns' => 'tests/Feature/ReturnsRefundsApiTest.php',
    'finance' => 'tests/Feature/FinancePayoutApiTest.php',
    'wallet' => 'tests/Feature/WalletApiTest.php',
    'kyc/security' => 'tests/Feature/KycSecurityTest.php',
    'notifications' => 'tests/Feature/NotificationMessagingApiTest.php',
    'rbac' => 'tests/Feature/RoleAccessAndAdminUiApiTest.php',
    'mysql' => 'tests/Feature/MySqlRuntimeCompatibilityTest.php',
];

foreach ($critical as $domain => $relative) {
    if (!is_file($root.'/'.$relative)) $errors[] = "Missing critical {$domain} test file: {$relative}";
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $files++;
    $path = $file->getPathname();
    $source = file_get_contents($path) ?: '';
    preg_match_all('/public\s+function\s+test_[A-Za-z0-9_]+\s*\(/', $source, $m);
    $testMethods += count($m[0]);
    if (preg_match('/\bsleep\s*\(/', $source)) $errors[] = 'Real sleep() found in test: '.str_replace($root.'/', '', $path);
    if (preg_match('/https?:\/\//', $source) && !str_contains($source, 'Http::fake') && !str_contains($source, 'unfaked-provider.invalid') && !str_contains($source, "withHeader('Origin'")) {
        $warnings[] = 'Review external URL in test (global stray-request block protects runtime): '.str_replace($root.'/', '', $path);
    }
}

if ($testMethods < 300) $errors[] = "Expected at least 300 test methods, found {$testMethods}.";

$testCase = file_get_contents($testsRoot.'/TestCase.php') ?: '';
if (!str_contains($testCase, 'Http::preventStrayRequests')) $errors[] = 'Global test harness does not block stray HTTP requests.';
if (!str_contains($testCase, 'Carbon::setTestNow')) $errors[] = 'Global test harness does not reset Carbon test time.';
if (!str_contains($testCase, 'non-test database')) $errors[] = 'Global test harness lacks non-test database safety guard.';
$guardPos = strpos($testCase, 'guardExternalTestDatabase();');
$parentPos = strpos($testCase, 'parent::setUp();');
if ($guardPos === false || $parentPos === false || $guardPos > $parentPos) $errors[] = 'Database safety guard must run before Laravel parent::setUp()/RefreshDatabase.';

foreach (['phpunit.xml','phpunit.mysql.xml','phpunit.postgres.xml'] as $xml) {
    if (!is_file($root.'/'.$xml)) $errors[] = "Missing {$xml}";
}

$workflow = file_get_contents($root.'/.github/workflows/ci.yml') ?: '';
foreach (['sqlite-tests:', 'mysql-tests:', 'postgres-tests:'] as $job) {
    if (!str_contains($workflow, $job)) $errors[] = "CI missing {$job}";
}

printf("Automated test audit\n  test files: %d\n  test methods: %d\n  critical domains: %d\n", $files, $testMethods, count($critical));
foreach ($warnings as $warning) echo "WARN: {$warning}\n";
if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, "ERROR: {$error}\n");
    exit(1);
}
echo "PASS: automated test suite contract is complete.\n";
