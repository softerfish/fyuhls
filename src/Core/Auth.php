<?php

namespace App\Core;

class Auth {
    public static function logActivity(string $type, ?string $description = null): void {
        $userId = self::id();
        $ip = \App\Service\SecurityService::getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $db = Database::getInstance()->getConnection();
        
        $encIp = \App\Service\EncryptionService::encrypt($ip);
        $encUa = \App\Service\EncryptionService::encrypt($ua);
        
        try {
            $stmt = $db->prepare("INSERT INTO user_activity_log (user_id, activity_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $type, $description, $encIp, $encUa]);
        } catch (\PDOException $e) {
            // If table doesn't exist, create it and retry
            if ($e->getCode() === '42S02') {
                self::createActivityLogTable($db);
                $stmt = $db->prepare("INSERT INTO user_activity_log (user_id, activity_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $type, $description, $encIp, $encUa]);
            } else {
                throw $e;
            }
        }
    }

    private static function createActivityLogTable($db): void {
        $sql = "CREATE TABLE IF NOT EXISTS `user_activity_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NULL,
            `activity_type` VARCHAR(50) NOT NULL,
            `description` TEXT NULL,
            `ip_address` TEXT NULL,
            `user_agent` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `user_activity_user` (`user_id`),
            INDEX `user_activity_type` (`activity_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
    }

    public static function login(int $userId, string $role): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        session_regenerate_id(true); // Prevent Session Fixation
        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = $role;
        $_SESSION['last_activity'] = time();
    }

    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_unset();
        session_destroy();
    }

    public static function check(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    public static function isAdmin(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            http_response_code(403);
            die("Access Denied: Admin privileges required.");
        }
    }

    /**
     * Get the currently logged in user's full database record, automatically decrypted.
     */
    public static function user(): ?array {
        $id = self::id();
        if (!$id) return null;

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if ($user) {
            $user['username'] = \App\Service\EncryptionService::decrypt($user['username']);
            $user['email'] = \App\Service\EncryptionService::decrypt($user['email']);
            if (isset($user['payment_details'])) {
                $user['payment_details'] = \App\Service\EncryptionService::decrypt($user['payment_details']);
            }
            if (isset($user['api_key'])) {
                $user['api_key'] = \App\Service\EncryptionService::decrypt($user['api_key']);
            }
        }

        return $user ?: null;
    }
}
