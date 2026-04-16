<?php

namespace App\Core;

use App\Core\Config;

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
        $previousToken = $_SESSION['csrf_token_prev'] ?? '';

        if ($sessionToken !== '' && hash_equals($sessionToken, $token)) {
            $_SESSION['csrf_token_prev'] = $sessionToken;
            $newToken = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $newToken;
            if (!headers_sent()) {
                header('X-CSRF-Token: ' . $newToken);
            }
            return true;
        }

        if ($previousToken !== '' && hash_equals($previousToken, $token)) {
            // A concurrent request might have hit the rotated token just before this one processed.
            // Let it pass with the previous token, and reply with the new current token to get the client re-synced.
            if (!headers_sent()) {
                header('X-CSRF-Token: ' . $sessionToken);
            }
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
        if (!Config::get('debug', false)) {
            return;
        }

        $logPath = dirname(__DIR__, 2) . '/storage/logs/csrf_debug.log';
        @file_put_contents($logPath, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
    }
}
