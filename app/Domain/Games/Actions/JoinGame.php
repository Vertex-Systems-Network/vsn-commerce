<?php
namespace App\Domain\Games\Actions;

use App\Domain\Games\Exceptions\GameException;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\Services\WalletService;
use App\Domain\Risk\Services\RiskGate;
use App\Domain\Risk\Exceptions\RiskBlockedException;
use App\Enums\GameStatus;
use App\Enums\ProductStatus;
use App\Enums\WalletTransactionType;
use App\Models\Game;
use App\Models\GameEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the JoinGame class and its project responsibilities. */
class JoinGame
{
    /** Initializes the JoinGame instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets, private readonly RiskGate $risk) {}

    /** Executes the join game operation. */
    public function execute(User $user, Game $game, int $quantity, string $idempotencyKey, array $consent = []): GameEntry
    {
        $maxPerRequest = (int) config('vsn.games.max_entries_per_request', 20);
        if ($quantity < 1 || $quantity > $maxPerRequest) {
            throw new GameException("Entries must be between 1 and {$maxPerRequest}.", 'entries');
        }

        $existing = GameEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->user_id !== $user->id || $existing->game_id !== $game->id) {
                throw new GameException('Idempotency key is already owned by another game entry.', 'idempotencyKey');
            }
            return $existing->load(['game.product.images','refund']);
        }
        try { $this->risk->game($user, $quantity); }
        catch (RiskBlockedException $e) { throw new GameException($e->getMessage(), 'risk'); }

        try {
            return DB::transaction(/** Inline callback for this operation. */ function () use ($user,$game,$quantity,$idempotencyKey,$consent): GameEntry {
                $existing = GameEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->user_id !== $user->id || $existing->game_id !== $game->id) {
                        throw new GameException('Idempotency key is already owned by another game entry.', 'idempotencyKey');
                    }
                    return $existing->load(['game.product.images','refund']);
                }

                $game = Game::query()->whereKey($game->id)->with('product')->lockForUpdate()->firstOrFail();
                if ($game->status === GameStatus::Scheduled && $game->opens_at->lte(now()) && $game->closes_at->gt(now())) {
                    $game->status = GameStatus::Open;
                    $game->save();
                }
                if ($game->status !== GameStatus::Open || now()->lt($game->opens_at) || now()->gte($game->closes_at)) {
                    throw new GameException('This Game Win campaign is not accepting entries.', 'game');
                }
                if ($game->product->status !== ProductStatus::Published || ! $game->product->game_enabled) {
                    throw new GameException('The prize product is not currently eligible for Game Win.', 'game');
                }
                if ($game->max_entries !== null && $game->total_entries + $quantity > $game->max_entries) {
                    throw new GameException('Not enough Game Win entries remain.', 'entries');
                }
                $perUserCap = (int) ($game->max_entries_per_user ?: config('vsn.games.max_entries_per_user', 100));
                if ($perUserCap > 0) {
                    $already = (int) GameEntry::query()->where('game_id',$game->id)->where('user_id',$user->id)->sum('quantity');
                    if ($already + $quantity > $perUserCap) throw new GameException("You may enter this campaign at most {$perUserCap} times.", 'entries');
                }

                $coins = $game->entry_coins * $quantity;
                try {
                    $walletTx = $this->wallets->debit(
                        $user,
                        $coins,
                        WalletTransactionType::GameEntry,
                        "game-entry:{$idempotencyKey}",
                        'game',
                        $game->public_id,
                        ['game_id'=>$game->public_id,'quantity'=>$quantity,'entry_coins'=>$game->entry_coins]
                    );
                } catch (WalletException $e) {
                    throw new GameException($e->getMessage(), 'entries');
                }

                $entry = GameEntry::create([
                    'public_id'=>(string) Str::ulid(),
                    'game_id'=>$game->id,
                    'user_id'=>$user->id,
                    'quantity'=>$quantity,
                    'coins_spent'=>$coins,
                    'idempotency_key'=>$idempotencyKey,
                    'wallet_transaction_id'=>$walletTx->id,
                    'rules_version'=>$game->rules_version,
                    'consented_at'=>now(),
                    'ip_hash'=>$consent['ip_hash'] ?? null,
                    'user_agent_hash'=>$consent['user_agent_hash'] ?? null,
                ]);
                $game->total_entries += $quantity;
                $game->save();

                return $entry->load(['game.product.images','refund']);
            }, 3);
        } catch (QueryException $e) {
            $existing = GameEntry::query()->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) throw $e;
            if ($existing->user_id !== $user->id || $existing->game_id !== $game->id) {
                throw new GameException('Idempotency key is already owned by another game entry.', 'idempotencyKey');
            }
            return $existing->load(['game.product.images','refund']);
        }
    }
}
