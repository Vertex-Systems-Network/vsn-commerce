<?php
namespace App\Domain\Games\Actions;

use App\Domain\Games\Exceptions\GameException;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\GameStatus;
use App\Enums\WalletTransactionType;
use App\Models\Game;
use App\Models\GamePrizeFulfillment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Defines the FulfillGamePrize class and its project responsibilities. */
class FulfillGamePrize
{
    /** Initializes the FulfillGamePrize instance and its dependencies. */
    public function __construct(private readonly WalletService $wallets) {}

    /** Executes the fulfill game prize operation. */
    public function execute(Game $game, User $actor, string $method = 'manual_handoff', ?string $reference = null, ?string $note = null): GamePrizeFulfillment
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($game,$actor,$method,$reference,$note): GamePrizeFulfillment {
            $game = Game::query()->whereKey($game->id)->with(['draw.winner'])->lockForUpdate()->firstOrFail();
            $existing = GamePrizeFulfillment::query()->where('game_id',$game->id)->first();
            if ($existing) return $existing;
            if ($game->status !== GameStatus::WinnerSelected || ! $game->draw || ! $game->draw->winner) {
                throw new GameException('Prize can only be fulfilled after a winner has been selected.');
            }
            $walletTx = null;
            if ((int)$game->winner_bonus_coins > 0) {
                $walletTx = $this->wallets->credit(
                    $game->draw->winner,
                    (int)$game->winner_bonus_coins,
                    WalletTransactionType::GameReward,
                    "game-winner-reward:{$game->public_id}",
                    'game',
                    $game->public_id,
                    ['game_id'=>$game->public_id,'winner_entry_id'=>$game->draw->winningEntry?->public_id]
                );
            }
            $row = GamePrizeFulfillment::create([
                'game_id'=>$game->id,
                'winner_user_id'=>$game->draw->winner_user_id,
                'fulfilled_by_user_id'=>$actor->id,
                'wallet_transaction_id'=>$walletTx?->id,
                'method'=>trim($method) ?: 'manual_handoff',
                'reference'=>$reference ? trim($reference) : null,
                'note'=>$note ? trim($note) : null,
                'fulfilled_at'=>now(),
            ]);
            $game->status = GameStatus::Fulfilled;
            $game->fulfilled_at = now();
            $game->save();
            return $row;
        }, 3);
    }
}
