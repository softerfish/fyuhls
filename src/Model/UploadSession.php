<?php

namespace App\Model;

use App\Core\Database;
use App\Service\Storage\StoragePathGuard;

class UploadSession
{
    public static function create(array $data): int
    {
        $db = Database::getInstance()->getConnection();
        $data['object_key'] = StoragePathGuard::normalizeObjectPath((string)($data['object_key'] ?? ''), 'Upload session object key');

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

    public static function findById(int $id): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM upload_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: null;
        return $row ? self::decryptRow($row) : null;
    }

    public static function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        if (array_key_exists('object_key', $data) && $data['object_key'] !== null) {
            $data['object_key'] = StoragePathGuard::normalizeObjectPath((string)$data['object_key'], 'Upload session object key');
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

    public static function transitionStatus(int $id, array $allowedCurrentStatuses, string $newStatus, array $extraData = []): bool
    {
        $allowedCurrentStatuses = array_values(array_filter(array_map('strval', $allowedCurrentStatuses), static fn(string $status): bool => $status !== ''));
        if ($id <= 0 || $allowedCurrentStatuses === [] || trim($newStatus) === '') {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $encCols = \App\Service\Database\SchemaService::getEncryptedColumns('upload_sessions');
        $fields = ['`status` = ?'];
        $values = [$newStatus];

        if (array_key_exists('object_key', $extraData) && $extraData['object_key'] !== null) {
            $extraData['object_key'] = StoragePathGuard::normalizeObjectPath((string)$extraData['object_key'], 'Upload session object key');
        }

        foreach ($extraData as $key => $value) {
            $fields[] = "`{$key}` = ?";
            if (in_array($key, $encCols, true) && $value !== null) {
                $values[] = \App\Service\EncryptionService::encrypt((string)$value);
            } else {
                $values[] = $value;
            }
        }

        $placeholders = implode(', ', array_fill(0, count($allowedCurrentStatuses), '?'));
        $values[] = $id;
        array_push($values, ...$allowedCurrentStatuses);

        $sql = "UPDATE upload_sessions SET " . implode(', ', $fields) . " WHERE id = ? AND status IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        if ($stmt->rowCount() === 1) {
            return true;
        }

        if (!in_array($newStatus, $allowedCurrentStatuses, true)) {
            return false;
        }

        return self::rowAlreadyMatches($id, [$newStatus], array_merge(['status' => $newStatus], $extraData));
    }

    public static function updateIfStatus(int $id, array $allowedCurrentStatuses, array $data): bool
    {
        $allowedCurrentStatuses = array_values(array_filter(array_map('strval', $allowedCurrentStatuses), static fn(string $status): bool => $status !== ''));
        if ($id <= 0 || $allowedCurrentStatuses === [] || empty($data)) {
            return false;
        }

        if (array_key_exists('object_key', $data) && $data['object_key'] !== null) {
            $data['object_key'] = StoragePathGuard::normalizeObjectPath((string)$data['object_key'], 'Upload session object key');
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

        $placeholders = implode(', ', array_fill(0, count($allowedCurrentStatuses), '?'));
        $values[] = $id;
        array_push($values, ...$allowedCurrentStatuses);

        $sql = "UPDATE upload_sessions SET " . implode(', ', $fields) . " WHERE id = ? AND status IN ($placeholders)";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        if ($stmt->rowCount() === 1) {
            return true;
        }

        return self::rowAlreadyMatches($id, $allowedCurrentStatuses, $data);
    }

    private static function rowAlreadyMatches(int $id, array $allowedStatuses, array $expectedData): bool
    {
        if ($id <= 0 || $allowedStatuses === [] || $expectedData === []) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT * FROM upload_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: null;
        if (!is_array($row)) {
            return false;
        }

        if (!in_array((string)($row['status'] ?? ''), $allowedStatuses, true)) {
            return false;
        }

        $encCols = \App\Service\Database\SchemaService::getEncryptedColumns('upload_sessions');
        foreach ($expectedData as $key => $expectedValue) {
            if (in_array($key, $encCols, true)) {
                return false;
            }

            if (!array_key_exists($key, $row)) {
                return false;
            }

            if ($expectedValue === null) {
                if ($row[$key] !== null) {
                    return false;
                }
                continue;
            }

            if ((string)$row[$key] !== (string)$expectedValue) {
                return false;
            }
        }

        return true;
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

    public static function getUploadedPartsForCompletion(int $sessionId): array
    {
        if ($sessionId <= 0) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT part_number, etag, part_size, checksum_sha256, status
            FROM upload_session_parts
            WHERE upload_session_id = ?
              AND status = 'uploaded'
              AND etag IS NOT NULL
              AND etag <> ''
            ORDER BY part_number ASC
        ");
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
              AND status IN ('uploading', 'completing', 'processing')
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
              AND status IN ('uploading', 'completing', 'processing')
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
