<?php
namespace App\Domain\Games\Actions;

use App\Domain\Games\Exceptions\GameException;
use App\Enums\GameStatus;
use App\Enums\ProductStatus;
use App\Models\Game;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Defines the CreateGame class and its project responsibilities. */
class CreateGame
{
    /** Executes the create game operation. */
    public function execute(
        Product $product,
        int $entryCoins,
        Carbon $opensAt,
        Carbon $closesAt,
        Carbon $announcementAt,
        ?int $maxEntries,
        string $rulesVersion,
        array $metadata = [],
        ?int $maxEntriesPerUser = null,
        int $winnerBonusCoins = 0
    ): Game {
        if ($product->status !== ProductStatus::Published || ! $product->game_enabled) {
            throw new GameException('Product must be published and Game Win enabled.', 'productSlug');
        }
        if ($entryCoins <= 0) throw new GameException('Entry cost must be greater than zero.', 'entryCoins');
        if ($winnerBonusCoins < 0) throw new GameException('Winner bonus coins cannot be negative.', 'winnerBonusCoins');
        if ($maxEntriesPerUser !== null && $maxEntriesPerUser < 1) throw new GameException('Per-user entry cap must be at least one.', 'maxEntriesPerUser');
        if (! $opensAt->lt($closesAt)) throw new GameException('Close time must be after open time.', 'closesAt');
        if ($announcementAt->lt($closesAt)) throw new GameException('Announcement time cannot be before close time.', 'announcementAt');

        $overlap = Game::query()
            ->where('product_id', $product->id)
            ->whereNotIn('status', [GameStatus::Cancelled->value, GameStatus::Fulfilled->value])
            ->where(/** Inline callback for this operation. */ function ($query) use ($opensAt, $closesAt): void {
                $query->whereBetween('opens_at', [$opensAt, $closesAt])
                    ->orWhereBetween('closes_at', [$opensAt, $closesAt])
                    ->orWhere(/** Inline callback for this operation. */ fn ($q) => $q->where('opens_at', '<=', $opensAt)->where('closes_at', '>=', $closesAt));
            })->exists();
        if ($overlap) throw new GameException('This product already has an overlapping Game Win campaign.', 'productSlug');

        $secret = bin2hex(random_bytes(32));
        $status = $opensAt->lte(now()) && $closesAt->gt(now()) ? GameStatus::Open : GameStatus::Scheduled;

        return Game::create([
            'public_id'=>(string) Str::ulid(),
            'product_id'=>$product->id,
            'status'=>$status,
            'entry_coins'=>$entryCoins,
            'winner_bonus_coins'=>$winnerBonusCoins,
            'max_entries'=>$maxEntries,
            'max_entries_per_user'=>$maxEntriesPerUser,
            'total_entries'=>0,
            'opens_at'=>$opensAt,
            'closes_at'=>$closesAt,
            'announcement_at'=>$announcementAt,
            'rules_version'=>$rulesVersion,
            'commitment_hash'=>hash('sha256', $secret),
            'draw_secret_ciphertext'=>Crypt::encryptString($secret),
            'metadata'=>$metadata,
        ]);
    }
}
