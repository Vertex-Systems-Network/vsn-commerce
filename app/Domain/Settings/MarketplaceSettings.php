<?php

namespace App\Domain\Settings;

use App\Models\MarketplaceSetting;

/** Defines the MarketplaceSettings class and its project responsibilities. */
class MarketplaceSettings
{
    /** Handles get for the marketplace settings workflow. */
    public function get(string $group, string $key, mixed $default=null): mixed
    {
        $row=MarketplaceSetting::query()->where('group',$group)->where('key',$key)->first();
        return $row ? $row->value : $default;
    }

    /** Handles ordering enabled for the marketplace settings workflow. */
    public function orderingEnabled(): bool { return (bool)$this->get('orders','orderingEnabled',true); }
    /** Handles returns window days for the marketplace settings workflow. */
    public function returnsWindowDays(): int { return max(0,(int)$this->get('orders','returnsWindowDays',(int)config('vsn.returns.window_days',30))); }
}
