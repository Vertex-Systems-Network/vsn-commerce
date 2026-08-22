<?php
namespace App\Domain\Affiliate\Actions;

use App\Enums\AffiliateCommissionStatus;
use App\Models\AffiliateCommission;
use Illuminate\Support\Facades\DB;

/** Defines the MatureAffiliateCommissions class and its project responsibilities. */
class MatureAffiliateCommissions
{
    /** Executes the mature affiliate commissions operation. */
    public function execute(int $limit = 500): int
    {
        $ids = AffiliateCommission::query()
            ->where('status', AffiliateCommissionStatus::Pending->value)
            ->where('available_at', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $changed = DB::transaction(/** Inline callback for this operation. */ function () use ($id): bool {
                $row = AffiliateCommission::query()->whereKey($id)->lockForUpdate()->first();
                if (! $row || $row->status !== AffiliateCommissionStatus::Pending || $row->available_at->isFuture()) return false;
                $row->update(['status' => AffiliateCommissionStatus::Available]);
                return true;
            }, 3);
            if ($changed) $count++;
        }
        return $count;
    }
}
