<?php

namespace App\Core;

use App\Core\Config;

class Csrf {
    // Multipart batches can overlap many authenticated requests in the same session.
    // Keep a bounded window large enough for those in-flight requests to settle.
    private const RECENT_TOKEN_LIMIT = 64;

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
        $recentTokens = self::getRecentTokens();

        if ($sessionToken !== '' && hash_equals($sessionToken, $token)) {
            self::rememberRecentToken($sessionToken);
            $_SESSION['csrf_token_prev'] = $sessionToken;
            $newToken = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $newToken;
            if (!headers_sent()) {
                header('X-CSRF-Token: ' . $newToken);
            }
            return true;
        }

        if ($previousToken !== '' && hash_equals($previousToken, $token)) {
            if (!headers_sent()) {
                header('X-CSRF-Token: ' . $sessionToken);
            }
            return true;
        }

        foreach ($recentTokens as $recentToken) {
            if ($recentToken !== '' && hash_equals($recentToken, $token)) {
                if (!headers_sent()) {
                    header('X-CSRF-Token: ' . $sessionToken);
                }
                return true;
            }
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

    private static function getRecentTokens(): array {
        $recent = $_SESSION['csrf_token_recent'] ?? [];
        if (!is_array($recent)) {
            return [];
        }

        return array_values(array_filter($recent, static fn ($value) => is_string($value) && $value !== ''));
    }

    private static function rememberRecentToken(string $token): void {
        if ($token === '') {
            return;
        }

        $recent = self::getRecentTokens();
        array_unshift($recent, $token);
        $recent = array_values(array_unique($recent));
        if (count($recent) > self::RECENT_TOKEN_LIMIT) {
            $recent = array_slice($recent, 0, self::RECENT_TOKEN_LIMIT);
        }

        $_SESSION['csrf_token_recent'] = $recent;
    }

    private static function logDebug(string $message): void {
        if (!Config::get('debug', false)) {
            return;
        }

        $logPath = dirname(__DIR__, 2) . '/storage/logs/csrf_debug.log';
        @file_put_contents($logPath, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
    }
}
