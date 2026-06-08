<?php

namespace App\Service;

use App\Core\Auth;

class AdminUserNavigationService
{
    public static function isCurrentUser(int $userId, ?int $currentUserId = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $resolvedCurrentUserId = $currentUserId ?? Auth::id();
        return $resolvedCurrentUserId !== null && $userId === (int)$resolvedCurrentUserId;
    }

    public static function destinationForUserEdit(int $userId, ?int $currentUserId = null): string
    {
        if ($userId <= 0) {
            return '/admin/users';
        }

        if (self::isCurrentUser($userId, $currentUserId)) {
            return '/settings';
        }

        return '/admin/users/edit/' . rawurlencode((string)$userId);
    }
}
