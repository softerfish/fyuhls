<?php

namespace App\Service;

class AccountPlanStatusService
{
    public static function levelType(?array $user = null, ?array $package = null): string
    {
        $packageLevelType = strtolower(trim((string)($package['level_type'] ?? '')));
        if ($packageLevelType !== '') {
            return $packageLevelType;
        }

        $userLevelType = strtolower(trim((string)($user['package_level_type'] ?? '')));
        return $userLevelType !== '' ? $userLevelType : 'free';
    }

    public static function isPaidAccessLevel(?array $user = null, ?array $package = null): bool
    {
        return in_array(self::levelType($user, $package), ['paid', 'admin'], true);
    }

    public static function statusLabel(?array $user = null, ?array $package = null): string
    {
        $premiumExpiry = trim((string)($user['premium_expiry'] ?? $package['premium_expiry'] ?? ''));
        if ($premiumExpiry !== '' && strtotime($premiumExpiry) !== false) {
            return 'Premium until ' . date('M d, Y', strtotime($premiumExpiry));
        }

        return match (self::levelType($user, $package)) {
            'admin' => 'Administrator account',
            'paid' => 'Paid account active',
            default => 'Lifetime free account',
        };
    }

    public static function paymentsCopy(?array $user = null, ?array $package = null): string
    {
        $premiumExpiry = trim((string)($user['premium_expiry'] ?? $package['premium_expiry'] ?? ''));
        if ($premiumExpiry !== '' && strtotime($premiumExpiry) !== false) {
            return 'Your current premium access is active until ' . date('M d, Y', strtotime($premiumExpiry)) . '.';
        }

        return match (self::levelType($user, $package)) {
            'admin' => 'This administrator account does not use the paid checkout flow. Any billing history tied to this account will appear in the tables below.',
            'paid' => 'This paid account is active. Any successful renewal or later billing status change will appear in the tables below.',
            default => 'Your account is currently on the free plan. Any successful upgrade or renewal will appear in the tables below.',
        };
    }
}
