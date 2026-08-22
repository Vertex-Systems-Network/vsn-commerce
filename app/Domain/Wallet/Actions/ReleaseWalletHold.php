<?php
namespace App\Domain\Wallet\Actions;

use App\Enums\WalletHoldStatus;
use App\Models\Wallet;
use App\Models\WalletHold;
use Illuminate\Support\Facades\DB;

/** Defines the ReleaseWalletHold class and its project responsibilities. */
class ReleaseWalletHold
{
    /** Executes the release wallet hold operation. */
    public function execute(WalletHold $hold, WalletHoldStatus $status = WalletHoldStatus::Released): WalletHold
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($hold,$status): WalletHold {
            $hold = WalletHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($hold->status !== WalletHoldStatus::Active) return $hold;
            $wallet = Wallet::query()->whereKey($hold->wallet_id)->lockForUpdate()->firstOrFail();
            $wallet->reserved_coins = max(0, $wallet->reserved_coins - $hold->coins); $wallet->save();
            $hold->update(['status'=>$status,'released_at'=>now()]);
            return $hold->fresh();
        },3);
    }
}
