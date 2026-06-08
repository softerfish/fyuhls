<?php

namespace App\Service;

use App\Model\Package;
use App\Model\User;

class AdminActivityNavigationService
{
    public static function destinationForTarget(
        array $activity,
        ?callable $userExistsResolver = null,
        ?callable $packageExistsResolver = null,
        ?bool $rewardsEnabled = null,
        ?int $currentUserId = null
    ): ?string {
        $itemType = strtolower(trim((string)($activity['item_type'] ?? '')));
        $itemId = (int)($activity['item_id'] ?? 0);
        $targetUserId = (int)($activity['target_user_id'] ?? 0);

        return match ($itemType) {
            'user' => self::userDestination($itemId > 0 ? $itemId : $targetUserId, $userExistsResolver, $currentUserId),
            'package' => self::packageDestination($itemId, $packageExistsResolver),
            'subscription' => '/admin/subscriptions',
            'withdrawal' => self::withdrawalDestination($rewardsEnabled),
            'bonus_offer', 'bonus_award' => '/admin/configuration?tab=monetization',
            'setting', 'config' => '/admin/configuration',
            default => null,
        };
    }

    private static function userDestination(int $userId, ?callable $userExistsResolver, ?int $currentUserId): string
    {
        if ($userId <= 0 || !self::userExists($userId, $userExistsResolver)) {
            return '/admin/users';
        }

        return AdminUserNavigationService::destinationForUserEdit($userId, $currentUserId);
    }

    private static function packageDestination(int $packageId, ?callable $packageExistsResolver): string
    {
        if ($packageId <= 0 || !self::packageExists($packageId, $packageExistsResolver)) {
            return '/admin/packages';
        }

        return '/admin/package/edit/' . rawurlencode((string)$packageId);
    }

    private static function withdrawalDestination(?bool $rewardsEnabled): string
    {
        $enabled = $rewardsEnabled ?? FeatureService::rewardsEnabled();
        return $enabled ? '/admin/withdrawals' : '/admin/configuration?tab=monetization';
    }

    private static function userExists(int $userId, ?callable $userExistsResolver): bool
    {
        if ($userExistsResolver !== null) {
            return (bool)$userExistsResolver($userId);
        }

        return User::find($userId) !== null;
    }

    private static function packageExists(int $packageId, ?callable $packageExistsResolver): bool
    {
        if ($packageExistsResolver !== null) {
            return (bool)$packageExistsResolver($packageId);
        }

        return Package::find($packageId) !== null;
    }
}
