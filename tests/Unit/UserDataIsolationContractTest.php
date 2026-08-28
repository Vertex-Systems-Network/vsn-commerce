<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Guards authenticated customer surfaces against accidental shared/demo data regressions. */
class UserDataIsolationContractTest extends TestCase
{
    /** Confirms customer controllers scope personal records to the authenticated user's identifier. */
    public function test_customer_controllers_keep_personal_records_user_scoped(): void
    {
        $root = dirname(__DIR__, 2);
        $contracts = [
            'app/Http/Controllers/Api/V1/OrderController.php' => ["where('user_id', \$request->user()->id)"],
            'app/Http/Controllers/Api/V1/WalletController.php' => ["where('user_id', \$user->id)", "where('user_id', \$request->user()->id)"],
            'app/Http/Controllers/Api/V1/WishlistController.php' => ["where('user_id', \$request->user()->id)"],
            'app/Http/Controllers/Api/V1/NotificationController.php' => ["where('user_id', \$request->user()->id)"],
            'app/Http/Controllers/Api/V1/ReturnController.php' => ["where('user_id', \$request->user()->id)"],
            'app/Http/Controllers/Api/V1/AddressController.php' => ['$request->user()->addresses()', '$address->user_id === $request->user()->id'],
        ];

        foreach ($contracts as $file => $needles) {
            $source = preg_replace('/\s+/', '', (string) file_get_contents($root.'/'.$file));
            foreach ($needles as $needle) {
                $this->assertStringContainsString(
                    preg_replace('/\s+/', '', $needle),
                    $source,
                    "Missing authenticated-user scope in {$file}",
                );
            }
        }
    }

    /** Confirms the customer dashboard obtains personal data from authenticated APIs rather than bundled demo state. */
    public function test_account_overview_uses_authenticated_server_endpoints(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/js/pages/Account.jsx');
        foreach (['/orders', '/wallet', '/wishlist', '/activity', '/returns'] as $endpoint) {
            $this->assertStringContainsString($endpoint, $source, "Account UI is missing {$endpoint} server data.");
        }

        $this->assertFileDoesNotExist($root.'/resources/js/platform/store.jsx', 'Legacy client business store must remain retired.');
        $this->assertFileDoesNotExist($root.'/resources/js/data/catalog.js', 'Bundled demo catalog authority must remain retired.');
        $this->assertStringNotContainsString('useStore', $source, 'Account UI must not regain legacy client business authority.');
        $this->assertStringNotContainsString('data/catalog', $source, 'Account UI must not regain bundled catalog authority.');
    }
}
