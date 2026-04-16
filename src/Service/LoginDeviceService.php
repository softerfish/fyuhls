<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Model\User;

class LoginDeviceService
{
    private const COOKIE_NAME = 'fyuhls_device';
    private const COOKIE_TTL = 31536000; // 1 year

    public static function handleSuccessfulLogin(array $user, string $ip): void
    {
        if (empty($user['id']) || empty($user['email'])) {
            return;
        }

        self::ensureTableExists();

        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        $isNewCookie = false;

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $isNewCookie = true;
        }

        self::persistCookie($token);

        $tokenHash = hash('sha256', $token);
        $uaHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM user_login_devices WHERE user_id = ? AND device_token_hash = ? LIMIT 1");
        $stmt->execute([(int)$user['id'], $tokenHash]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $update = $db->prepare("
                UPDATE user_login_devices
                SET user_agent_hash = ?, last_seen_ip = ?, last_seen_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$uaHash, $ip, (int)$existingId]);
            return;
        }

        $insert = $db->prepare("
            INSERT INTO user_login_devices (user_id, device_token_hash, user_agent_hash, first_seen_ip, last_seen_ip, created_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $insert->execute([(int)$user['id'], $tokenHash, $uaHash, $ip, $ip]);

        try {
            MailService::sendTemplate((string)$user['email'], 'new_device_login', [
                '{username}' => (string)($user['username'] ?? 'User'),
                '{login_ip}' => $ip,
                '{login_time}' => date('Y-m-d H:i:s'),
            ], 'high');
        } catch (\Throwable $e) {
            Logger::warning('new device login email failed', [
                'user_id' => (int)$user['id'],
                'error' => $e->getMessage(),
                'new_cookie' => $isNewCookie ? 1 : 0,
            ]);
        }
    }

    public static function ensureTableExists(): void
    {
        $db = Database::getInstance()->getConnection();
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_login_devices (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                device_token_hash CHAR(64) NOT NULL,
                user_agent_hash CHAR(64) NOT NULL,
                first_seen_ip VARCHAR(45) NOT NULL,
                last_seen_ip VARCHAR(45) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY user_device_unique (user_id, device_token_hash),
                KEY user_last_seen_idx (user_id, last_seen_at),
                CONSTRAINT user_login_devices_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ensured = true;
    }

    private static function persistCookie(string $token): void
    {
        $secure = \App\Service\SecurityService::isHttpsRequest();

        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[self::COOKIE_NAME] = $token;
    }
}
