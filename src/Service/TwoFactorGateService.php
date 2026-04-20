<?php

namespace App\Service;

use App\Core\Auth;
use App\Core\Database;
use App\Model\Setting;

class TwoFactorGateService
{
    public static function interceptRequest(): void
    {
        if (!FeatureService::twoFactorEnabled() || !Auth::check()) {
            return;
        }

        $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        if (str_starts_with($uri, '/assets') || str_starts_with($uri, '/2fa') || $uri === '/logout') {
            return;
        }

        $userId = Auth::id();
        $db = Database::getInstance()->getConnection();
        if (!$db) {
            return;
        }

        $stmt = $db->prepare("SELECT * FROM user_two_factor WHERE user_id = ?");
        $stmt->execute([$userId]);
        $fa = $stmt->fetch();

        if ($fa && (int) $fa['is_enabled'] === 1) {
            if ((!isset($_SESSION['2fa_verified']) || $_SESSION['2fa_verified'] !== true) && !self::isDeviceTrusted($userId)) {
                header('Location: /2fa/verify');
                exit;
            }
        }

        $enforceDate = Setting::get('2fa_enforce_date', '', 'security');
        if (!empty($enforceDate) && strtotime($enforceDate) <= time()) {
            if (!$fa || (int) $fa['is_enabled'] === 0) {
                header('Location: /2fa/setup');
                exit;
            }
        }
    }

    private static function isDeviceTrusted(int $userId): bool
    {
        $cookieName = '2fa_trust_' . $userId;
        if (!isset($_COOKIE[$cookieName])) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("SELECT 1 FROM user_two_factor_devices WHERE user_id = ? AND trust_token = ? AND expires_at > NOW()");
        $stmt->execute([$userId, $_COOKIE[$cookieName]]);
        return (bool) $stmt->fetchColumn();
    }
}
