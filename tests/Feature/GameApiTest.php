<?php

namespace Tests\Feature;

use App\Domain\Games\Actions\CancelGame;
use App\Domain\Games\Actions\CloseGame;
use App\Domain\Games\Actions\CreateGame;
use App\Domain\Games\Actions\DrawGame;
use App\Domain\Games\Actions\RefundCancelledGameEntries;
use App\Domain\Games\Exceptions\GameException;
use App\Enums\GameStatus;
use App\Enums\ProductStatus;
use App\Models\Game;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the GameApiTest class and its project responsibilities. */
class GameApiTest extends TestCase
{
    use RefreshDatabase;

    /** Updates up. */
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
            'vsn.games.rules_version' => 'test-rules-v1',
            'vsn.games.max_entries_per_request' => 20,
        ]);
    }


    /** Handles tear down for the game api test workflow. */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Verifies public game list exposes commitment but not unrevealed secret. */
    public function test_public_game_list_exposes_commitment_but_not_unrevealed_secret(): void
    {
        $game=$this->makeGame();
        $this->getJson('/api/v1/games')
            ->assertOk()
            ->assertJsonPath('data.0.id',$game->public_id)
            ->assertJsonPath('data.0.commitmentHash',$game->commitment_hash)
            ->assertJsonPath('data.0.draw',null)
            ->assertJsonMissingPath('data.0.drawSecret');
    }

    /** Verifies join debits wallet once and retry is idempotent. */
    public function test_join_debits_wallet_once_and_retry_is_idempotent(): void
    {
        $user=User::factory()->create(); Wallet::create(['user_id'=>$user->id,'balance_coins'=>1000,'reserved_coins'=>0]);
        $game=$this->makeGame(entryCoins:70);
        Sanctum::actingAs($user);
        $payload=['entries'=>2,'idempotencyKey'=>'game-entry-idem-001','acceptRules'=>true];

        $first=$this->postJson("/api/v1/games/{$game->public_id}/entries",$payload)
            ->assertCreated()->assertJsonPath('data.quantity',2)->assertJsonPath('data.coinsSpent',140)->json('data.id');
        $second=$this->postJson("/api/v1/games/{$game->public_id}/entries",$payload)
            ->assertCreated()->json('data.id');

        $this->assertSame($first,$second);
        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>860]);
        $this->assertDatabaseHas('games',['id'=>$game->id,'total_entries'=>2]);
        $this->assertDatabaseCount('game_entries',1);
        $this->assertDatabaseCount('wallet_transactions',1);
    }

    /** Verifies game entry idempotency key cannot cross users. */
    public function test_game_entry_idempotency_key_cannot_cross_users(): void
    {
        $a=User::factory()->create(); $b=User::factory()->create();
        Wallet::create(['user_id'=>$a->id,'balance_coins'=>1000,'reserved_coins'=>0]);
        Wallet::create(['user_id'=>$b->id,'balance_coins'=>1000,'reserved_coins'=>0]);
        $game=$this->makeGame();
        $payload=['entries'=>1,'idempotencyKey'=>'shared-game-idempotency','acceptRules'=>true];

        Sanctum::actingAs($a); $this->postJson("/api/v1/games/{$game->public_id}/entries",$payload)->assertCreated();
        Sanctum::actingAs($b); $this->postJson("/api/v1/games/{$game->public_id}/entries",$payload)
            ->assertUnprocessable()->assertJsonPath('errors.idempotencyKey.0','Idempotency key is already owned by another game entry.');
        $this->assertDatabaseHas('wallets',['user_id'=>$b->id,'balance_coins'=>1000]);
    }

    /** Verifies insufficient wallet balance and max entries fail without debit. */
    public function test_insufficient_wallet_balance_and_max_entries_fail_without_debit(): void
    {
        $user=User::factory()->create(); Wallet::create(['user_id'=>$user->id,'balance_coins'=>100,'reserved_coins'=>0]);
        $game=$this->makeGame(entryCoins:70,maxEntries:1);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>2,'idempotencyKey'=>'max-entry-test-001','acceptRules'=>true])
            ->assertUnprocessable();
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>1,'idempotencyKey'=>'balance-entry-001','acceptRules'=>true])
            ->assertCreated();
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>1,'idempotencyKey'=>'balance-entry-002','acceptRules'=>true])
            ->assertUnprocessable();

        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>30]);
        $this->assertDatabaseHas('games',['id'=>$game->id,'total_entries'=>1]);
    }

    /** Verifies closed game rejects new entries. */
    public function test_closed_game_rejects_new_entries(): void
    {
        $user=User::factory()->create(); Wallet::create(['user_id'=>$user->id,'balance_coins'=>500,'reserved_coins'=>0]);
        $game=$this->makeGame();
        Carbon::setTestNow(Carbon::parse('2026-08-08 11:30:00'));
        app(CloseGame::class)->execute($game);
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>1,'idempotencyKey'=>'closed-game-entry','acceptRules'=>true])
            ->assertUnprocessable();
        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>500]);
    }

    /** Verifies draw cannot run before announcement. */
    public function test_draw_cannot_run_before_announcement(): void
    {
        $game=$this->makeGame();
        Carbon::setTestNow(Carbon::parse('2026-08-08 11:30:00'));
        app(CloseGame::class)->execute($game);
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Winner draw cannot run before the announcement time.');
        app(DrawGame::class)->execute($game);
    }

    /** Verifies draw is reproducible from revealed secret snapshot and game id. */
    public function test_draw_is_reproducible_from_revealed_secret_snapshot_and_game_id(): void
    {
        $a=User::factory()->create(); $b=User::factory()->create();
        Wallet::create(['user_id'=>$a->id,'balance_coins'=>1000,'reserved_coins'=>0]);
        Wallet::create(['user_id'=>$b->id,'balance_coins'=>1000,'reserved_coins'=>0]);
        $game=$this->makeGame();

        Sanctum::actingAs($a);
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>2,'idempotencyKey'=>'draw-entry-a01','acceptRules'=>true])->assertCreated();
        Sanctum::actingAs($b);
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>3,'idempotencyKey'=>'draw-entry-b01','acceptRules'=>true])->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-08-08 12:30:00'));
        app(CloseGame::class)->execute($game);
        $draw=app(DrawGame::class)->execute($game);
        $snapshotJson=$draw->snapshot_canonical;
        $this->assertSame(hash('sha256',$snapshotJson),$draw->snapshot_hash);
        $this->assertSame(hash('sha256',$draw->revealed_secret),$draw->commitment_hash);
        $selection=hash('sha256',"{$draw->revealed_secret}|{$draw->snapshot_hash}|{$game->public_id}");
        $ticket=(int)(hexdec(substr($selection,0,12))%5)+1;
        $this->assertSame($selection,$draw->selection_hash);
        $this->assertSame($ticket,$draw->winning_ticket_number);
        $this->assertDatabaseHas('games',['id'=>$game->id,'status'=>'winner_selected']);
        $this->assertDatabaseCount('game_draws',1);

        $retry=app(DrawGame::class)->execute($game);
        $this->assertSame($draw->id,$retry->id);
        $this->assertDatabaseCount('game_draws',1);
    }

    /** Verifies cancelled game refunds each entry once with compensating ledger. */
    public function test_cancelled_game_refunds_each_entry_once_with_compensating_ledger(): void
    {
        $user=User::factory()->create(); Wallet::create(['user_id'=>$user->id,'balance_coins'=>500,'reserved_coins'=>0]);
        $game=$this->makeGame();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>2,'idempotencyKey'=>'refund-game-entry','acceptRules'=>true])->assertCreated();
        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>360]);

        app(CancelGame::class)->execute($game,'Prize unavailable');
        $this->assertSame(1,app(RefundCancelledGameEntries::class)->execute($game));
        $this->assertSame(0,app(RefundCancelledGameEntries::class)->execute($game));

        $this->assertDatabaseHas('wallets',['user_id'=>$user->id,'balance_coins'=>500]);
        $this->assertDatabaseCount('game_entry_refunds',1);
        $this->assertDatabaseHas('wallet_transactions',['type'=>'reversal','reference_type'=>'game_entry']);
    }

    /** Verifies non admin cannot use game control endpoints. */
    public function test_non_admin_cannot_use_game_control_endpoints(): void
    {
        $user=User::factory()->create(); $game=$this->makeGame();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/admin/games/{$game->public_id}/close",[])->assertForbidden();
    }

    /** Verifies admin can create close draw and fulfill game. */
    public function test_admin_can_create_close_draw_and_fulfill_game(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
        $admin=User::factory()->create(['role'=>'admin']);
        $winner=User::factory()->create(); Wallet::create(['user_id'=>$winner->id,'balance_coins'=>500,'reserved_coins'=>0]);
        $product=$this->makeProduct('admin-game-product');

        Sanctum::actingAs($admin);
        $gameData=$this->postJson('/api/v1/admin/games',[
            'productSlug'=>$product->slug,'entryCoins'=>70,'maxEntries'=>100,
            'opensAt'=>now()->subMinute()->toISOString(),'closesAt'=>now()->addHour()->toISOString(),
            'announcementAt'=>now()->addHours(2)->toISOString(),'rulesVersion'=>'admin-rules-v1',
        ])->assertCreated()->json('data');

        Sanctum::actingAs($winner);
        $this->postJson("/api/v1/games/{$gameData['id']}/entries",['entries'=>1,'idempotencyKey'=>'admin-flow-entry','acceptRules'=>true])->assertCreated();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$gameData['id']}/close",[])->assertUnprocessable();

        Carbon::setTestNow(Carbon::parse('2026-08-08 12:30:00'));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$gameData['id']}/close",[])->assertOk()->assertJsonPath('data.status','closed');
        $this->postJson("/api/v1/admin/games/{$gameData['id']}/draw",[])->assertOk()->assertJsonPath('data.status','winner_selected');
        $this->postJson("/api/v1/admin/games/{$gameData['id']}/fulfill",['method'=>'courier','reference'=>'TRACK-001','note'=>'Prize dispatched'])
            ->assertOk()->assertJsonPath('data.status','fulfilled')->assertJsonPath('data.fulfillment.reference','TRACK-001');
        $this->assertDatabaseHas('game_prize_fulfillments',['reference'=>'TRACK-001','fulfilled_by_user_id'=>$admin->id]);
    }

    /** Verifies my entries marks the winning entry without exposing other user identity. */
    public function test_my_entries_marks_the_winning_entry_without_exposing_other_user_identity(): void
    {
        $user=User::factory()->create(); Wallet::create(['user_id'=>$user->id,'balance_coins'=>500,'reserved_coins'=>0]);
        $game=$this->makeGame();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/games/{$game->public_id}/entries",['entries'=>1,'idempotencyKey'=>'single-winner-entry','acceptRules'=>true])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-08-08 12:30:00'));
        app(CloseGame::class)->execute($game);
        app(DrawGame::class)->execute($game);

        $this->getJson('/api/v1/games/me/entries')->assertOk()
            ->assertJsonPath('data.0.game.isWinner',true)
            ->assertJsonMissingPath('data.0.game.winnerUserId');
    }

    /** Handles make game for the game api test workflow. */
    private function makeGame(int $entryCoins=70, ?int $maxEntries=100): Game
    {
        $product=$this->makeProduct('game-product-'.Str::lower(Str::random(6)));
        return app(CreateGame::class)->execute(
            $product,$entryCoins,now()->subMinute(),now()->addHour(),now()->addHours(2),$maxEntries,'test-rules-v1'
        );
    }

    /** Handles make product for the game api test workflow. */
    private function makeProduct(string $slug): Product
    {
        return Product::create([
            'public_id'=>(string)Str::ulid(),'sku'=>'TEST-'.Str::upper(Str::random(10)),'slug'=>$slug,'name'=>Str::headline($slug),
            'status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>100000,'installment_enabled'=>false,'game_enabled'=>true,
        ]);
    }
}
