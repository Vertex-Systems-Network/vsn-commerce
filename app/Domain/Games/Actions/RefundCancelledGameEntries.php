<?php
namespace App\Domain\Games\Actions;

use App\Domain\Wallet\Actions\ReverseWalletTransaction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameEntry;
use App\Models\GameEntryRefund;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;

/** Defines the RefundCancelledGameEntries class and its project responsibilities. */
class RefundCancelledGameEntries
{
    /** Initializes the RefundCancelledGameEntries instance and its dependencies. */
    public function __construct(private readonly ReverseWalletTransaction $reverse) {}

    /** Executes the refund cancelled game entries operation. */
    public function execute(?Game $game = null, int $limit = 200): int
    {
        $query = GameEntry::query()
            ->whereDoesntHave('refund')
            ->whereHas('game', /** Inline callback for this operation. */ fn ($q) => $q->where('status', GameStatus::Cancelled->value))
            ->with(['game','walletTransaction'])
            ->orderBy('id');
        if ($game) $query->where('game_id', $game->id);

        $count = 0;
        foreach ($query->limit($limit)->get() as $entry) {
            try {
                $tx = WalletTransaction::query()->where('reversal_of_transaction_id', $entry->wallet_transaction_id)->first();
                if (! $tx) {
                    $tx = $this->reverse->execute(
                        null,
                        $entry->walletTransaction,
                        "game-entry-refund:{$entry->public_id}",
                        'game_entry',
                        $entry->public_id
                    );
                }
                $refund = GameEntryRefund::firstOrCreate(
                    ['game_entry_id'=>$entry->id],
                    ['wallet_transaction_id'=>$tx->id,'reason'=>$entry->game->cancellation_reason ?: 'game_cancelled','refunded_at'=>now()]
                );
                if ($refund->wasRecentlyCreated) $count++;
            } catch (QueryException) {
                // Unique constraints make retries safe; another worker may have completed this refund.
            }
        }
        return $count;
    }
}
