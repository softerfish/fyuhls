<?php

namespace App\Model;

use App\Core\Database;

class QuotaReservation
{
    public static function create(array $data): int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO quota_reservations (
                public_id, user_id, upload_session_id, storage_server_id,
                reserved_bytes, status, expires_at, released_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['public_id'],
            $data['user_id'],
            $data['upload_session_id'] ?? null,
            $data['storage_server_id'] ?? null,
            $data['reserved_bytes'],
            $data['status'] ?? 'active',
            $data['expires_at'] ?? null,
            $data['released_at'] ?? null,
        ]);

        return (int)$db->lastInsertId();
    }

    public static function findActiveById(int $id): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT * FROM quota_reservations
            WHERE id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findActiveBySession(int $sessionId): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT * FROM quota_reservations
            WHERE upload_session_id = ? AND status = 'active'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: null;
    }

    public static function updateStatus(int $id, string $status): void
    {
        $db = Database::getInstance()->getConnection();
        $releasedAt = in_array($status, ['released', 'expired', 'committed'], true) ? date('Y-m-d H:i:s') : null;
        $db->prepare("UPDATE quota_reservations SET status = ?, released_at = ? WHERE id = ?")->execute([$status, $releasedAt, $id]);
    }

    public static function activeReservedBytesForUser(int $userId): int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COALESCE(SUM(reserved_bytes), 0) FROM quota_reservations WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public static function activeReservedBytesForServer(int $serverId): int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COALESCE(SUM(reserved_bytes), 0) FROM quota_reservations WHERE storage_server_id = ? AND status = 'active'");
        $stmt->execute([$serverId]);
        return (int)$stmt->fetchColumn();
    }

    public static function findExpired(int $limit = 100): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT * FROM quota_reservations
            WHERE status = 'active'
              AND expires_at IS NOT NULL
              AND expires_at < NOW()
            ORDER BY expires_at ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function refreshExpiryBySession(int $sessionId, string $expiresAt): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("
            UPDATE quota_reservations
            SET expires_at = ?
            WHERE upload_session_id = ? AND status = 'active'
        ")->execute([$expiresAt, $sessionId]);
    }
}
