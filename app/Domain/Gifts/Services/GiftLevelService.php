<?php

namespace App\Domain\Gifts\Services;

/** Defines the GiftLevelService class and its project responsibilities. */
class GiftLevelService
{
    /** Handles level for for the gift level service workflow. */
    public function levelFor(int $coins): array
    {
        $levels = collect(config('vsn.gifts.levels', []))->sortBy('min_coins')->values();
        $current = $levels->first() ?? ['code'=>'starter','name'=>'Starter Gifter','min_coins'=>0,'next_coins'=>null,'next_reward'=>null];
        foreach ($levels as $level) {
            if ($coins >= (int) ($level['min_coins'] ?? 0)) $current = $level;
        }
        $next = $levels->first(/** Inline callback for this operation. */ fn (array $level) => (int) ($level['min_coins'] ?? 0) > $coins);
        $min = (int) ($current['min_coins'] ?? 0);
        $nextCoins = $next ? (int) $next['min_coins'] : null;
        $progress = $nextCoins === null ? 100 : min(100, max(0, (int) round((($coins - $min) / max(1, $nextCoins - $min)) * 100)));

        return [
            'code' => (string) ($current['code'] ?? 'starter'),
            'name' => (string) ($current['name'] ?? 'Starter Gifter'),
            'minCoins' => $min,
            'progress' => $progress,
            'nextCoins' => $nextCoins,
            'nextReward' => $next['unlock_reward_label'] ?? ($current['next_reward'] ?? null),
        ];
    }

    /** Handles thresholds crossed for the gift level service workflow. */
    public function thresholdsCrossed(int $before, int $after): array
    {
        return collect(config('vsn.gifts.levels', []))
            ->filter(/** Inline callback for this operation. */ fn (array $level) => (int) ($level['min_coins'] ?? 0) > $before && (int) ($level['min_coins'] ?? 0) <= $after)
            ->values()->all();
    }
}
