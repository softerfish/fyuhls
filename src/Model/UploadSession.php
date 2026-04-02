<?php

namespace App\Model;

use App\Core\Database;

class UploadSession
{
    public static function create(array $data): int
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            INSERT INTO upload_sessions (
                public_id, user_id, guest_session_id, folder_id, storage_server_id, storage_provider,
                original_filename, object_key, expected_size, mime_hint, checksum_sha256,
                multipart_upload_id, status, reserved_bytes, uploaded_bytes, completed_parts,
                part_size_bytes, metadata_json, error_message, expires_at, completed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['public_id'],
            $data['user_id'] ?? null,
            $data['guest_session_id'] ?? null,
            $data['folder_id'] ?? null,
            $data['storage_server_id'] ?? null,
            $data['storage_provider'] ?? 'local',
            \App\Service\EncryptionService::encrypt($data['original_filename']),
            \App\Service\EncryptionService::encrypt($data['object_key']),
            $data['expected_size'],
            isset($data['mime_hint']) ? \App\Service\EncryptionService::encrypt((string)$data['mime_hint']) : null,
            $data['checksum_sha256'] ?? null,
            $data['multipart_upload_id'] ?? null,
            $data['status'] ?? 'pending',
            $data['reserved_bytes'] ?? 0,
            $data['uploaded_bytes'] ?? 0,
            $data['completed_parts'] ?? 0,
            $data['part_size_bytes'] ?? 0,
            $data['metadata_json'] ?? null,
            $data['error_message'] ?? null,
            $data['expires_at'] ?? null,
            $data['completed_at'] ?? null,
        ]);

        return (int)$db->lastInsertId();
    }

    public static function findByPublicId(string $publicId): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM upload_sessions WHERE public_id = ? LIMIT 1");
        $stmt->execute([$publicId]);
        $row = $stmt->fetch() ?: null;
        return $row ? self::decryptRow($row) : null;
    }

    public static function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        $db = Database::getInstance()->getConnection();
        $fields = [];
        $values = [];
        $encCols = \App\Service\Database\SchemaService::getEncryptedColumns('upload_sessions');

        foreach ($data as $key => $value) {
            $fields[] = "`{$key}` = ?";
            if (in_array($key, $encCols, true) && $value !== null) {
                $values[] = \App\Service\EncryptionService::encrypt((string)$value);
            } else {
                $values[] = $value;
            }
        }

        $values[] = $id;
        $sql = "UPDATE upload_sessions SET " . implode(', ', $fields) . " WHERE id = ?";
        return $db->prepare($sql)->execute($values);
    }

    public static function upsertPart(int $sessionId, int $partNumber, ?string $etag, int $partSize, string $status = 'uploaded', ?string $checksum = null): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO upload_session_parts (upload_session_id, part_number, etag, part_size, checksum_sha256, status)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                etag = VALUES(etag),
                part_size = VALUES(part_size),
                checksum_sha256 = VALUES(checksum_sha256),
                status = VALUES(status)
        ");
        $stmt->execute([$sessionId, $partNumber, $etag, $partSize, $checksum, $status]);
    }

    public static function getParts(int $sessionId): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM upload_session_parts WHERE upload_session_id = ? ORDER BY part_number ASC");
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    public static function countActiveForGuestSession(string $guestSessionId): int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM upload_sessions
            WHERE guest_session_id = ?
              AND status IN ('pending', 'uploading', 'completing', 'processing')
        ");
        $stmt->execute([$guestSessionId]);
        return (int)$stmt->fetchColumn();
    }

    public static function refreshExpiry(int $id, string $expiresAt): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE upload_sessions SET expires_at = ? WHERE id = ?")->execute([$expiresAt, $id]);
    }

    public static function deleteParts(int $sessionId): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("DELETE FROM upload_session_parts WHERE upload_session_id = ?")->execute([$sessionId]);
    }

    public static function findExpiring(int $limit = 100): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT * FROM upload_sessions
            WHERE status IN ('pending', 'uploading', 'completing', 'processing')
              AND expires_at IS NOT NULL
              AND expires_at < NOW()
            ORDER BY expires_at ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        return array_map([self::class, 'decryptRow'], $rows);
    }

    public static function countActiveForUser(int $userId): int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM upload_sessions
            WHERE user_id = ?
              AND status IN ('pending', 'uploading', 'completing', 'processing')
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    private static function decryptRow(array $row): array
    {
        if (!\App\Service\EncryptionService::isReady()) {
            return $row;
        }

        $encCols = \App\Service\Database\SchemaService::getEncryptedColumns('upload_sessions');
        foreach ($encCols as $col) {
            if (isset($row[$col]) && is_string($row[$col]) && str_starts_with($row[$col], 'ENC:')) {
                $row[$col] = \App\Service\EncryptionService::decrypt($row[$col]);
            }
        }

        if (isset($row['metadata_json']) && is_string($row['metadata_json']) && $row['metadata_json'] !== '') {
            $row['metadata'] = json_decode($row['metadata_json'], true) ?: [];
        } else {
            $row['metadata'] = [];
        }

        return $row;
    }
}
