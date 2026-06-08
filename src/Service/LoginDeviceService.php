<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Model\User;
use App\Service\Database\SchemaService;

class LoginDeviceService
{
    private const COOKIE_NAME = 'fyuhls_device';
    private const COOKIE_TTL = 31536000; // 1 year

    public static function handleSuccessfulLogin(array $user, string $ip): void
    {
        if (empty($user['id']) || empty($user['email'])) {
            return;
        }

        try {
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
            $encryptedIp = EncryptionService::encrypt($ip);

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
                $update->execute([$uaHash, $encryptedIp, (int)$existingId]);
                return;
            }

            $insert = $db->prepare("
                INSERT INTO user_login_devices (user_id, device_token_hash, user_agent_hash, first_seen_ip, last_seen_ip, created_at, last_seen_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $insert->execute([(int)$user['id'], $tokenHash, $uaHash, $encryptedIp, $encryptedIp]);

            MailService::sendTemplate((string)$user['email'], 'new_device_login', [
                '{username}' => (string)($user['username'] ?? 'User'),
                '{login_ip}' => $ip,
                '{login_time}' => date('Y-m-d H:i:s'),
            ], 'high');
        } catch (\Throwable $e) {
            Logger::warning('login device tracking unavailable during successful login', [
                'user_id' => (int)$user['id'],
                'error' => $e->getMessage(),
            ]);
            return;
        }
    }

    public static function ensureTableExists(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        SchemaService::ensureTables(['user_login_devices'], false);
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
