<?php

namespace App\Core;

class Csrf {
    public static function generate(): string {
        return self::getSessionToken();
    }

    public static function verify(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token)) {
            self::logDebug('CSRF Missing token');
            return false;
        }

        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if ($sessionToken !== '' && hash_equals($sessionToken, $token)) {
            return true;
        }

        self::logDebug('CSRF Mismatch');
        return false;
    }

    public static function field(): string {
        $token = self::getSessionToken();
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    private static function getSessionToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!empty($_SESSION['csrf_token'])) {
            return (string)$_SESSION['csrf_token'];
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['csrf_token'];
    }

    private static function logDebug(string $message): void {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/csrf_debug.log';
        @file_put_contents($logPath, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
    }
}
