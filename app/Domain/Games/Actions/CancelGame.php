<?php
namespace App\Domain\Games\Actions;

use App\Domain\Games\Exceptions\GameException;
use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

/** Defines the CancelGame class and its project responsibilities. */
class CancelGame
{
    /** Executes the cancel game operation. */
    public function execute(Game $game, string $reason): Game
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($game,$reason): Game {
            $game = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            if ($game->status === GameStatus::Cancelled) return $game;
            if (in_array($game->status, [GameStatus::WinnerSelected, GameStatus::Fulfilled], true)) {
                throw new GameException('A game with a selected winner cannot be cancelled through the normal cancellation flow.');
            }
            $game->status = GameStatus::Cancelled;
            $game->cancelled_at = now();
            $game->cancellation_reason = trim($reason);
            $game->save();
            return $game;
        }, 3);
    }
}
