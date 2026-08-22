<?php
namespace App\Domain\Games\Actions;

use App\Domain\Games\Exceptions\GameException;
use App\Enums\GameStatus;
use App\Models\Game;

/** Defines the AdvanceGameLifecycle class and its project responsibilities. */
class AdvanceGameLifecycle
{
    /** Initializes the AdvanceGameLifecycle instance and its dependencies. */
    public function __construct(private readonly DrawGame $draw, private readonly CancelGame $cancel) {}

    /** Executes the advance game lifecycle operation. */
    public function execute(): array
    {
        $opened = Game::query()->where('status', GameStatus::Scheduled->value)->where('opens_at','<=',now())->where('closes_at','>',now())->update(['status'=>GameStatus::Open->value,'updated_at'=>now()]);
        $closed = Game::query()->whereIn('status',[GameStatus::Scheduled->value,GameStatus::Open->value])->where('closes_at','<=',now())->update(['status'=>GameStatus::Closed->value,'closed_at'=>now(),'updated_at'=>now()]);
        $drawn = 0; $cancelled = 0;
        Game::query()->where('status',GameStatus::Closed->value)->where('announcement_at','<=',now())->whereDoesntHave('draw')->orderBy('id')->chunkById(50,/** Inline callback for this operation. */ function($games) use (&$drawn,&$cancelled): void {
            foreach ($games as $game) {
                try { $this->draw->execute($game); $drawn++; }
                catch (GameException $e) {
                    if ($e->getMessage() === 'Game has no eligible entries to draw.') {
                        $this->cancel->execute($game, 'No eligible entries at draw time.');
                        $cancelled++;
                    }
                }
            }
        });
        return compact('opened','closed','drawn','cancelled');
    }
}
