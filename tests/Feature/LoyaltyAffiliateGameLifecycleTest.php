<?php
namespace Tests\Feature;

use App\Domain\Affiliate\Actions\CreditAvailableAffiliateCommissions;
use App\Domain\Games\Actions\CloseGame;
use App\Domain\Games\Actions\CreateGame;
use App\Domain\Games\Actions\DrawGame;
use App\Domain\Games\Actions\FulfillGamePrize;
use App\Domain\Wallet\Services\CoinLotService;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\AffiliateAccountStatus;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Models\AffiliateAccount;
use App\Models\AffiliateCommission;
use App\Models\Game;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletCoinLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the LoyaltyAffiliateGameLifecycleTest class and its project responsibilities. */
class LoyaltyAffiliateGameLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies promotional credit creates expiring lot and expiry posts ledger debit. */
    public function test_promotional_credit_creates_expiring_lot_and_expiry_posts_ledger_debit(): void
    {
        config(['vsn.wallet.promotional_expiry_days'=>1]);
        $user=User::factory()->create();
        $wallets=app(WalletService::class);
        $wallets->credit($user,500,WalletTransactionType::AdminAdjustment,'am-credit',metadata:['reason'=>'promo']);
        $lot=WalletCoinLot::query()->where('user_id',$user->id)->firstOrFail();
        $this->assertNotNull($lot->expires_at);
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(1,app(CoinLotService::class)->expireDue());
        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>0]);
        $this->assertDatabaseHas('wallet_transactions',['type'=>'expiration','reference_type'=>'wallet_coin_lot']);
        Carbon::setTestNow();
    }

    /** Verifies game enforces per user campaign cap. */
    public function test_game_enforces_per_user_campaign_cap(): void
    {
        config(['vsn.games.max_entries_per_user'=>2]);
        $user=User::factory()->create(); Wallet::create(['user_id'=>$user->id,'balance_coins'=>1000,'reserved_coins'=>0]);
        $game=$this->game(maxPerUser:2);
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>2,'idempotencyKey'=>'am-cap-1','acceptRules'=>true])->assertCreated();
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>1,'idempotencyKey'=>'am-cap-2','acceptRules'=>true])->assertUnprocessable();
    }

    /** Verifies game fulfillment credits winner bonus once. */
    public function test_game_fulfillment_credits_winner_bonus_once(): void
    {
        $user=User::factory()->create(); $admin=User::factory()->create(['role'=>UserRole::Admin]); Wallet::create(['user_id'=>$user->id,'balance_coins'=>500,'reserved_coins'=>0]);
        $game=$this->game(maxPerUser:5,bonus:250);
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>1,'idempotencyKey'=>'am-win-001','acceptRules'=>true])->assertCreated();
        Carbon::setTestNow(now()->addHours(2)); app(CloseGame::class)->execute($game); app(DrawGame::class)->execute($game);
        $first=app(FulfillGamePrize::class)->execute($game->fresh(),$admin,'courier','AM-TRACK');
        $second=app(FulfillGamePrize::class)->execute($game->fresh(),$admin,'courier','AM-TRACK');
        $this->assertSame($first->id,$second->id);
        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>680]);
        $this->assertSame(1,\App\Models\WalletTransaction::query()->where('type','game_reward')->count());
        Carbon::setTestNow();
    }

    /** Verifies admin can suspend affiliate with append only event. */
    public function test_admin_can_suspend_affiliate_with_append_only_event(): void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]); $user=User::factory()->create();
        $account=AffiliateAccount::create(['user_id'=>$user->id,'referral_code'=>'VSNTEST123','status'=>AffiliateAccountStatus::Active,'terms_version'=>'test','terms_accepted_at'=>now()]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/engagement/affiliate/accounts/{$account->id}/status",['status'=>'suspended','reason'=>'Manual abuse review'])->assertOk()->assertJsonPath('data.status','suspended');
        $this->assertDatabaseHas('affiliate_account_events',['affiliate_account_id'=>$account->id,'event_type'=>'status_changed','to_status'=>'suspended']);
    }

    /** Verifies admin wallet adjustment is audited ledger transaction. */
    public function test_admin_wallet_adjustment_is_audited_ledger_transaction(): void
    {
        $admin=User::factory()->create(['role'=>UserRole::Admin]); $user=User::factory()->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/engagement/wallets/users/{$user->id}/adjust",['coins'=>700,'reason'=>'Customer service goodwill','expiresInDays'=>30])->assertOk()->assertJsonPath('data.balanceCoins',700);
        $this->assertDatabaseHas('wallet_transactions',['type'=>'admin_adjustment','reference_type'=>'admin_adjustment','reference_id'=>(string)$admin->id]);
    }

    /** Handles game for the milestone amengagement test workflow. */
    private function game(int $maxPerUser=2,int $bonus=0): Game
    {
        $product=Product::create(['public_id'=>(string)Str::ulid(),'sku'=>'AM-'.Str::upper(Str::random(8)),'slug'=>'am-'.Str::lower(Str::random(8)),'name'=>'AM Prize','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>100000,'installment_enabled'=>false,'game_enabled'=>true]);
        return app(CreateGame::class)->execute($product,70,now()->subMinute(),now()->addHour(),now()->addHours(2),100,'am-rules',[],$maxPerUser,$bonus);
    }
}
