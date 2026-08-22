<?php
namespace App\Domain\Affiliate\Actions;

use App\Enums\AffiliateAccountStatus;
use App\Models\AffiliateAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the EnrollAffiliate class and its project responsibilities. */
class EnrollAffiliate
{
    /** Executes the enroll affiliate operation. */
    public function execute(User $user, string $termsVersion, array $metadata = []): AffiliateAccount
    {
        $existing = AffiliateAccount::query()->where('user_id', $user->id)->first();
        if ($existing) return $existing;

        return DB::transaction(/** Inline callback for this operation. */ function () use ($user, $termsVersion, $metadata): AffiliateAccount {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = AffiliateAccount::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing) return $existing;

            do {
                $code = 'VSN'.Str::upper(Str::random(9));
            } while (AffiliateAccount::query()->where('referral_code', $code)->exists());

            return AffiliateAccount::create([
                'user_id' => $user->id,
                'referral_code' => $code,
                'status' => AffiliateAccountStatus::Active,
                'terms_version' => $termsVersion,
                'terms_accepted_at' => now(),
                'metadata' => $metadata,
            ]);
        }, 3);
    }
}
