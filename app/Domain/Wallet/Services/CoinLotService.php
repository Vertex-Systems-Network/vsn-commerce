<?php
namespace App\Domain\Wallet\Services;

use App\Enums\WalletEntryDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletCoinConsumption;
use App\Models\WalletCoinLot;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the CoinLotService class and its project responsibilities. */
class CoinLotService
{
    /** Handles ensure opening coverage for the coin lot service workflow. */
    public function ensureOpeningCoverage(Wallet $wallet): void
    {
        if ($wallet->balance_coins <= 0) return;
        $tracked = (int) WalletCoinLot::query()->where('wallet_id',$wallet->id)->sum('remaining_coins');
        $missing = max(0, (int)$wallet->balance_coins - $tracked);
        if ($missing <= 0) return;
        WalletCoinLot::create([
            'wallet_id'=>$wallet->id,'user_id'=>$wallet->user_id,'source_type'=>'opening_balance',
            'original_coins'=>$missing,'remaining_coins'=>$missing,'metadata'=>['backfill'=>true],
        ]);
    }

    /** Handles record credit for the coin lot service workflow. */
    public function recordCredit(Wallet $wallet, WalletTransaction $tx, int $coins, WalletTransactionType|string $type, array $metadata = [], ?WalletCoinLot $originLot = null): WalletCoinLot
    {
        $typeValue = $type instanceof WalletTransactionType ? $type->value : (string)$type;
        $expiresAt = $this->resolveExpiry($typeValue, $metadata, $originLot?->expires_at);
        return WalletCoinLot::create([
            'wallet_id'=>$wallet->id,'user_id'=>$wallet->user_id,'source_transaction_id'=>$tx->id,'origin_lot_id'=>$originLot?->id,
            'source_type'=>$typeValue,'original_coins'=>$coins,'remaining_coins'=>$coins,'expires_at'=>$expiresAt,
            'metadata'=>$metadata ?: null,
        ]);
    }

    /** @return array<int,array{lot:WalletCoinLot,coins:int}> */
    public function consume(Wallet $wallet, WalletTransaction $tx, int $coins, bool $includeExpired = false): array
    {
        if ($coins <= 0) return [];
        $this->ensureOpeningCoverage($wallet);
        $remaining = $coins;
        $allocations = [];
        $lots = WalletCoinLot::query()->where('wallet_id',$wallet->id)->where('remaining_coins','>',0)
            ->when(! $includeExpired, /** Inline callback for this operation. */ fn($q)=>$q->where(/** Inline callback for this operation. */ fn($x)=>$x->whereNull('expires_at')->orWhere('expires_at','>',now())))
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($lots as $lot) {
            if ($remaining <= 0) break;
            $take = min($remaining, (int)$lot->remaining_coins);
            if ($take <= 0) continue;
            $lot->remaining_coins -= $take;
            $lot->save();
            WalletCoinConsumption::create(['debit_transaction_id'=>$tx->id,'wallet_coin_lot_id'=>$lot->id,'coins'=>$take]);
            $allocations[] = ['lot'=>$lot->fresh(),'coins'=>$take];
            $remaining -= $take;
        }
        return $allocations;
    }

    /** Restore provenance for a reversal of a debit transaction. */
    public function restoreDebit(WalletTransaction $original, Wallet $wallet, WalletTransaction $reversal): int
    {
        $restored = 0;
        $consumptions = WalletCoinConsumption::query()->where('debit_transaction_id',$original->id)
            ->whereHas('lot',/** Inline callback for this operation. */ fn($q)=>$q->where('wallet_id',$wallet->id))->with('lot')->lockForUpdate()->get();
        foreach ($consumptions as $row) {
            $coins = max(0, (int)$row->coins - (int)$row->restored_coins);
            if ($coins <= 0) continue;
            $row->lot->remaining_coins += $coins;
            $row->lot->save();
            $row->restored_coins += $coins;
            $row->save();
            $restored += $coins;
        }
        if ($restored === 0) {
            $entry = $original->entries->first(/** Inline callback for this operation. */ fn($e)=>$e->wallet_id===$wallet->id && $e->direction===WalletEntryDirection::Debit);
            if ($entry) {
                $this->recordCredit($wallet,$reversal,(int)$entry->coins,WalletTransactionType::Reversal,['legacy_restore'=>true]);
                $restored = (int)$entry->coins;
            }
        }
        return $restored;
    }


    /** Handles spendable coins for the coin lot service workflow. */
    public function spendableCoins(Wallet $wallet): int
    {
        $this->ensureOpeningCoverage($wallet);
        $expired = (int) WalletCoinLot::query()->where('wallet_id',$wallet->id)->where('remaining_coins','>',0)->whereNotNull('expires_at')->where('expires_at','<=',now())->sum('remaining_coins');
        return max(0, $wallet->availableCoins() - $expired);
    }

    /** Handles summary for the coin lot service workflow. */
    public function summary(User $user): array
    {
        $wallet = Wallet::query()->where('user_id',$user->id)->first();
        if (! $wallet) return ['expiring30Days'=>0,'nextExpiryAt'=>null,'nonExpiringCoins'=>0];
        $this->ensureOpeningCoverage($wallet);
        $base = WalletCoinLot::query()->where('wallet_id',$wallet->id)->where('remaining_coins','>',0);
        $expiring = (clone $base)->whereNotNull('expires_at')->whereBetween('expires_at',[now(),now()->addDays(30)])->sum('remaining_coins');
        $next = (clone $base)->whereNotNull('expires_at')->where('expires_at','>',now())->min('expires_at');
        $nonExpiring = (clone $base)->whereNull('expires_at')->sum('remaining_coins');
        return ['expiring30Days'=>(int)$expiring,'nextExpiryAt'=>$next ? Carbon::parse($next)->toISOString() : null,'nonExpiringCoins'=>(int)$nonExpiring];
    }

    /** Handles expire due for the coin lot service workflow. */
    public function expireDue(int $limit = 500): int
    {
        $ids = WalletCoinLot::query()->whereNull('expired_at')->whereNotNull('expires_at')->where('expires_at','<=',now())->where('remaining_coins','>',0)->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $done = DB::transaction(/** Inline callback for this operation. */ function () use ($id): bool {
                $lot = WalletCoinLot::query()->whereKey($id)->lockForUpdate()->first();
                if (! $lot || $lot->remaining_coins <= 0 || ! $lot->expires_at || $lot->expires_at->isFuture()) return false;
                $wallet = Wallet::query()->whereKey($lot->wallet_id)->lockForUpdate()->firstOrFail();
                $available = max(0, (int)$wallet->balance_coins - (int)$wallet->reserved_coins);
                $coins = min((int)$lot->remaining_coins, $available);
                if ($coins <= 0) return false; // Active checkout holds postpone expiry safely.
                $wallet->balance_coins -= $coins;
                $wallet->save();
                $tx = WalletTransaction::create([
                    'public_id'=>(string)Str::ulid(),'initiated_by_user_id'=>null,'type'=>WalletTransactionType::Expiration,'status'=>'posted',
                    'idempotency_key'=>"wallet-expire:{$lot->id}:{$lot->expires_at->timestamp}",'reference_type'=>'wallet_coin_lot','reference_id'=>(string)$lot->id,
                    'metadata'=>['source_type'=>$lot->source_type],'occurred_at'=>now(),
                ]);
                $tx->entries()->create(['wallet_id'=>$wallet->id,'user_id'=>$wallet->user_id,'direction'=>WalletEntryDirection::Debit,'coins'=>$coins,'balance_after_coins'=>$wallet->balance_coins]);
                WalletCoinConsumption::create(['debit_transaction_id'=>$tx->id,'wallet_coin_lot_id'=>$lot->id,'coins'=>$coins]);
                $lot->remaining_coins -= $coins;
                if ($lot->remaining_coins === 0) { $lot->expired_at = now(); $lot->expiration_transaction_id = $tx->id; }
                $lot->save();
                return true;
            },3);
            if ($done) $count++;
        }
        return $count;
    }

    /** Handles resolve expiry for the coin lot service workflow. */
    private function resolveExpiry(string $type, array $metadata, mixed $originExpiry = null): ?Carbon
    {
        if (!empty($metadata['expires_at'])) return Carbon::parse($metadata['expires_at']);
        if ($originExpiry) return Carbon::parse($originExpiry);
        $days = match ($type) {
            WalletTransactionType::DailyCheckin->value,
            WalletTransactionType::StreakBonus->value,
            WalletTransactionType::AffiliateCommission->value,
            WalletTransactionType::GameReward->value,
            WalletTransactionType::GiftLevelReward->value,
            WalletTransactionType::AdminAdjustment->value => (int) config('vsn.wallet.promotional_expiry_days', 365),
            default => 0,
        };
        return $days > 0 ? now()->addDays($days) : null;
    }
}
