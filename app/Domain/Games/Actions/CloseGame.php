<?php
namespace App\Domain\Games\Actions;

use App\Domain\Games\Exceptions\GameException;
use App\Enums\GameStatus;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

/** Defines the CloseGame class and its project responsibilities. */
class CloseGame
{
    /** Executes the close game operation. */
    public function execute(Game $game): Game
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($game): Game {
            $game = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            if (in_array($game->status, [GameStatus::Closed, GameStatus::WinnerSelected, GameStatus::Fulfilled], true)) return $game;
            if ($game->status === GameStatus::Cancelled) throw new GameException('Cancelled games cannot be closed.');
            if (now()->lt($game->closes_at)) throw new GameException('Game cannot close before its configured close time.');
            $game->status = GameStatus::Closed;
            $game->closed_at = now();
            $game->save();
            return $game;
        }, 3);
    }
}
