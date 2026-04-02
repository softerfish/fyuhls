<?php

namespace App\Core;

class Csrf {
    public static function generate(): string {
        // use double-submit cookie token for better multi-tab experience
        return self::getCookieToken();
    }

    public static function verify(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        $cookieToken = $_COOKIE['csrf_token'] ?? 'missing';
        $sessionToken = $_SESSION['csrf_token'] ?? 'missing';
        $incomingToken = $token ?? 'missing';

        if (empty($token)) {
            $logPath = dirname(__DIR__, 2) . '/storage/logs/csrf_debug.log';
            @file_put_contents($logPath, date('Y-m-d H:i:s') . " - CSRF Missing token\n", FILE_APPEND);
            return false;
        }
        
        // prefer cookie match
        if (isset($_COOKIE['csrf_token']) && hash_equals($_COOKIE['csrf_token'], $token)) {
            return true;
        }
        // fallback to session token if cookie missing
        if (!empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
            return true;
        }

        $logPath = dirname(__DIR__, 2) . '/storage/logs/csrf_debug.log';
        @file_put_contents($logPath, date('Y-m-d H:i:s') . " - CSRF Mismatch\n", FILE_APPEND);
        return false;
    }

    public static function field(): string {
        $token = self::getCookieToken();
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    private static function getCookieToken(): string {
        if (isset($_COOKIE['csrf_token']) && !empty($_COOKIE['csrf_token'])) {
            return $_COOKIE['csrf_token'];
        }
        $token = bin2hex(random_bytes(32));
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        // we need js access for double submit; so httponly false
        setcookie('csrf_token', $token, [
            'expires' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Strict'
        ]);
        // ensure availability in cli/tests
        $_COOKIE['csrf_token'] = $token;
        // keep session token in sync as fallback
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['csrf_token'] = $token;
        return $token;
    }
}
