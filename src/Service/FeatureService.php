<?php

namespace App\Service;

use App\Model\Setting;

class FeatureService
{
    public static function rewardsEnabled(): bool
    {
        return Setting::get('rewards_enabled', '0', 'rewards') === '1';
    }

    public static function isRewardsCronTask(string $taskKey): bool
    {
        $taskKey = trim($taskKey);
        return str_starts_with($taskKey, 'reward_') || str_starts_with($taskKey, 'fraud_');
    }

    public static function cronTaskEnabled(string $taskKey): bool
    {
        return !self::isRewardsCronTask($taskKey) || self::rewardsEnabled();
    }

    public static function affiliateEnabled(): bool
    {
        return self::rewardsEnabled() && Setting::get('affiliate_enabled', '0', 'rewards') === '1';
    }

    public static function twoFactorEnabled(): bool
    {
        return Setting::get('two_factor_enabled', '0', 'security') === '1';
    }
}
