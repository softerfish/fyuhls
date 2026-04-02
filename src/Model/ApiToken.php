<?php

namespace App\Model;

use App\Core\Database;

class ApiToken
{
    public static function create(array $data): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        $publicId = 'atk_' . bin2hex(random_bytes(8));
        $secret = 'fyu_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $secret);
        $prefix = substr($secret, 0, 12);
        $lastFour = substr($secret, -4);

        $stmt = $db->prepare("
            INSERT INTO api_tokens (
                public_id, user_id, name, token_prefix, token_last_four, token_hash,
                scopes_json, status, expires_at, last_used_ip
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NULL)
        ");
        $stmt->execute([
            $publicId,
            $data['user_id'],
            $data['name'],
            $prefix,
            $lastFour,
            $tokenHash,
            json_encode(array_values($data['scopes'] ?? []), JSON_UNESCAPED_SLASHES),
            $data['expires_at'] ?? null,
        ]);

        return [
            'id' => (int)$db->lastInsertId(),
            'public_id' => $publicId,
            'token' => $secret,
            'name' => $data['name'],
            'scopes' => array_values($data['scopes'] ?? []),
            'expires_at' => $data['expires_at'] ?? null,
            'last_four' => $lastFour,
        ];
    }

    public static function findActiveByRawToken(string $rawToken): ?array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $tokenHash = hash('sha256', trim($rawToken));
        $stmt = $db->prepare("
            SELECT *
            FROM api_tokens
            WHERE token_hash = ?
              AND status = 'active'
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch() ?: null;
        return $row ? self::decodeRow($row) : null;
    }

    public static function getByUser(int $userId): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT *
            FROM api_tokens
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        return array_map([self::class, 'decodeRow'], $stmt->fetchAll());
    }

    public static function revoke(int $id, int $userId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE api_tokens
            SET status = 'revoked', revoked_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$id, $userId]);
    }

    public static function touchUsage(int $id, ?string $ip = null): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE api_tokens
            SET last_used_at = NOW(), last_used_ip = ?
            WHERE id = ?
              AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        ");
        $stmt->execute([$ip, $id]);
    }

    public static function hasScope(array $token, string $scope): bool
    {
        $scopes = $token['scopes'] ?? [];
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    private static function decodeRow(array $row): array
    {
        $row['scopes'] = [];
        if (!empty($row['scopes_json'])) {
            $row['scopes'] = json_decode((string)$row['scopes_json'], true) ?: [];
        }
        return $row;
    }

    private static function ensureSchema(): void
    {
        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS `api_tokens` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `public_id` VARCHAR(24) NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `token_prefix` VARCHAR(16) NOT NULL,
                `token_last_four` VARCHAR(4) NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `scopes_json` LONGTEXT NOT NULL,
                `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
                `expires_at` DATETIME NULL,
                `last_used_at` DATETIME NULL,
                `last_used_ip` VARCHAR(64) NULL,
                `revoked_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `api_tokens_public_id` (`public_id`),
                UNIQUE KEY `api_tokens_hash` (`token_hash`),
                KEY `api_tokens_user_status` (`user_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
