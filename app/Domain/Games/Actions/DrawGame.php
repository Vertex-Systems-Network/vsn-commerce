<?php
namespace App\Domain\Games\Actions;

use App\Domain\Games\Exceptions\GameException;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameDraw;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the DrawGame class and its project responsibilities. */
class DrawGame
{
    /** Executes the draw game operation. */
    public function execute(Game $game): GameDraw
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($game): GameDraw {
            $game = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            if ($game->draw) return $game->draw()->with(['winner','winningEntry'])->firstOrFail();
            if ($game->status === GameStatus::Open && $game->closes_at->lte(now())) {
                $game->status = GameStatus::Closed; $game->closed_at = now(); $game->save();
            }
            if ($game->status !== GameStatus::Closed) throw new GameException('Only closed games can be drawn.');
            if (now()->lt($game->announcement_at)) throw new GameException('Winner draw cannot run before the announcement time.');

            $entries = $game->entries()
                ->whereDoesntHave('refund')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id','public_id','user_id','quantity','coins_spent','created_at']);

            $totalTickets = (int) $entries->sum('quantity');
            if ($totalTickets < 1) throw new GameException('Game has no eligible entries to draw.');

            $snapshot = $entries->map(/** Inline callback for this operation. */ fn ($entry) => [
                'entry'=>$entry->public_id,
                'userHash'=>hash('sha256', "game-user:{$game->public_id}:{$entry->user_id}"),
                'quantity'=>$entry->quantity,
                'coins'=>$entry->coins_spent,
                'createdAt'=>$entry->created_at->toISOString(),
            ])->values()->all();
            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $snapshotHash = hash('sha256', $snapshotJson);

            $secret = Crypt::decryptString($game->draw_secret_ciphertext);
            if (! hash_equals($game->commitment_hash, hash('sha256', $secret))) {
                throw new GameException('Game draw commitment verification failed.');
            }
            $selectionHash = hash('sha256', "{$secret}|{$snapshotHash}|{$game->public_id}");
            $winningTicket = (int) (hexdec(substr($selectionHash, 0, 12)) % $totalTickets) + 1;

            $cursor = 0; $winnerEntry = null;
            foreach ($entries as $entry) {
                $cursor += $entry->quantity;
                if ($winningTicket <= $cursor) { $winnerEntry = $entry; break; }
            }
            if (! $winnerEntry) throw new GameException('Could not resolve the winning ticket.');

            $draw = GameDraw::create([
                'public_id'=>(string) Str::ulid(),
                'game_id'=>$game->id,
                'commitment_hash'=>$game->commitment_hash,
                'snapshot_hash'=>$snapshotHash,
                'snapshot'=>$snapshot,
                'snapshot_canonical'=>$snapshotJson,
                'revealed_secret'=>$secret,
                'selection_hash'=>$selectionHash,
                'total_tickets'=>$totalTickets,
                'winning_ticket_number'=>$winningTicket,
                'winner_user_id'=>$winnerEntry->user_id,
                'winner_entry_id'=>$winnerEntry->id,
                'drawn_at'=>now(),
            ]);
            $game->status = GameStatus::WinnerSelected;
            $game->drawn_at = now();
            $game->save();

            return $draw->load(['winner','winningEntry']);
        }, 3);
    }
}
