<?php

namespace App\Service;

use App\Model\Setting;

class FeatureService
{
    public static function rewardsEnabled(): bool
    {
        return Setting::get('rewards_enabled', '0', 'rewards') === '1';
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
