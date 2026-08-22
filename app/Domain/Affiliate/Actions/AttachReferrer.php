<?php
namespace App\Domain\Affiliate\Actions;

use App\Domain\Affiliate\Exceptions\AffiliateException;
use App\Enums\AffiliateAccountStatus;
use App\Models\AffiliateAccount;
use App\Models\AffiliateRelationship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the AttachReferrer class and its project responsibilities. */
class AttachReferrer
{
    /** Executes the attach referrer operation. */
    public function execute(User $user, string $referralCode): AffiliateRelationship
    {
        $code = Str::upper(trim($referralCode));
        if ($code === '') throw new AffiliateException('Referral code is required.', 'referralCode');

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $code): AffiliateRelationship {
            $account = AffiliateAccount::query()->where('referral_code', $code)->first();
            if (! $account || $account->status !== AffiliateAccountStatus::Active) {
                throw new AffiliateException('Referral code is invalid or inactive.', 'referralCode');
            }
            if ($account->user_id === $user->id) {
                throw new AffiliateException('You cannot refer yourself.', 'referralCode');
            }

            User::query()->whereIn('id', [$user->id, $account->user_id])->orderBy('id')->lockForUpdate()->get();
            $account = AffiliateAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($account->status !== AffiliateAccountStatus::Active) {
                throw new AffiliateException('Referral code is invalid or inactive.', 'referralCode');
            }

            $existing = AffiliateRelationship::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->parent_user_id === $account->user_id) return $existing;
                throw new AffiliateException('A referrer is already attached to this account.', 'referralCode');
            }

            $cursor = $account->user_id;
            $seen = [];
            for ($hop = 0; $hop < 100 && $cursor; $hop++) {
                if ($cursor === $user->id) {
                    throw new AffiliateException('This referral would create a circular network.', 'referralCode');
                }
                if (isset($seen[$cursor])) {
                    throw new AffiliateException('The referral network contains a cycle and cannot be extended.', 'referralCode');
                }
                $seen[$cursor] = true;
                $cursor = (int) (AffiliateRelationship::query()->where('user_id', $cursor)->value('parent_user_id') ?? 0);
            }
            if ($cursor) throw new AffiliateException('Referral ancestry is deeper than the safety limit.', 'referralCode');

            return AffiliateRelationship::create([
                'user_id' => $user->id,
                'parent_user_id' => $account->user_id,
                'referral_account_id' => $account->id,
                'joined_at' => now(),
            ]);
        }, 3);
    }
}
