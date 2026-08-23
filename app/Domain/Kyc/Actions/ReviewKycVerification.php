<?php

namespace App\Domain\Kyc\Actions;

use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Defines the ReviewKycVerification class and its project responsibilities. */
class ReviewKycVerification
{
    /** Executes the review kyc verification operation. */
    public function execute(KycVerification $verification, User $actor, bool $approve, ?string $reason = null): KycVerification
    {
        return DB::transaction(function () use ($verification, $actor, $approve, $reason): KycVerification {
            $verification = KycVerification::query()->lockForUpdate()->findOrFail($verification->id);

            if ($verification->status !== KycVerificationStatus::Pending) {
                abort(409, 'Only pending verifications can be reviewed.');
            }
            if (! $approve && ! trim((string) $reason)) {
                abort(422, 'Rejection reason is required.');
            }

            $days = $verification->type === KycVerificationType::GovernmentId
                ? (int) config('vsn.kyc.government_id_valid_days', 365)
                : (int) config('vsn.kyc.address_proof_valid_days', 180);

            $verification->update([
                'status' => $approve ? KycVerificationStatus::Approved : KycVerificationStatus::Rejected,
                'rejection_reason' => $approve ? null : $reason,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'expires_at' => $approve ? now()->addDays(max(1, $days)) : null,
            ]);

            return $verification->fresh(['user', 'reviewedBy']);
        }, 3);
    }
}
