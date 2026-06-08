<?php

namespace App\Model;

use App\Core\Database;
use App\Core\Logger;
use App\Service\Database\SchemaService;
use App\Service\EncryptionService;
use PDO;

class ApiToken
{
    public static function create(array $data): array
    {
        if (!self::schemaAvailable()) {
            throw new \RuntimeException('API token management is temporarily unavailable until an administrator repairs the database schema.');
        }
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
        if (!self::schemaAvailable()) {
            return null;
        }
        $db = Database::getInstance()->getConnection();
        $tokenHash = hash('sha256', trim($rawToken));
        $stmt = $db->prepare("
            SELECT t.*
            FROM api_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = ?
              AND t.status = 'active'
              AND u.status = 'active'
              AND (t.expires_at IS NULL OR t.expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch() ?: null;
        return $row ? self::decodeRow($row) : null;
    }

    public static function getByUser(int $userId): array
    {
        if (!self::schemaAvailable()) {
            return [];
        }
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
        if (!self::schemaAvailable()) {
            throw new \RuntimeException('API token management is temporarily unavailable until an administrator repairs the database schema.');
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE api_tokens
            SET status = 'revoked', revoked_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$id, $userId]);
    }

    public static function revokeAllForUser(int $userId): void
    {
        self::requireSecurityStorage();
        self::revokeAllForUserWithConnection(Database::getInstance()->getConnection(), $userId);
    }

    public static function revokeAllForUserWithConnection(PDO $db, int $userId): void
    {
        self::requireSecurityStorage();
        if ($userId <= 0) {
            return;
        }

        $stmt = $db->prepare("
            UPDATE api_tokens
            SET status = 'revoked',
                revoked_at = COALESCE(revoked_at, NOW())
            WHERE user_id = ?
              AND status = 'active'
        ");
        $stmt->execute([$userId]);
    }

    public static function touchUsage(int $id, ?string $ip = null): void
    {
        if (!self::schemaAvailable()) {
            return;
        }
        $db = Database::getInstance()->getConnection();
        $encryptedIp = $ip !== null && $ip !== '' ? EncryptionService::encrypt($ip) : null;
        $stmt = $db->prepare("
            UPDATE api_tokens
            SET last_used_at = NOW(), last_used_ip = ?
            WHERE id = ?
              AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        ");
        $stmt->execute([$encryptedIp, $id]);
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
        if (isset($row['last_used_ip']) && is_string($row['last_used_ip']) && str_starts_with($row['last_used_ip'], 'ENC:')) {
            $row['last_used_ip'] = EncryptionService::decrypt($row['last_used_ip']);
        }
        return $row;
    }

    private static function ensureSchema(): void
    {
        SchemaService::ensureTables(['api_tokens'], false);
    }

    private static function schemaAvailable(): bool
    {
        try {
            self::ensureSchema();
            return true;
        } catch (\Throwable $e) {
            Logger::warning('api token runtime schema unavailable', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private static function requireSecurityStorage(): void
    {
        if (!self::schemaAvailable()) {
            throw new \RuntimeException('API token revocation is temporarily unavailable until an administrator repairs the database schema.');
        }
    }
}
