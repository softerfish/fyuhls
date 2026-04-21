<?php

namespace App\Model;

use App\Core\Database;
use PDO;

class File {
    private static $mockFind = null;
    private static bool $schemaReady = false;
    public static function setMockFind(?callable $fn): void {
        self::$mockFind = $fn;
    }

    public static function create(?int $userId, int $storedFileId, string $filename, ?int $folderId = null, ?string $deleteAt = null, int $isPublic = 1, string $status = 'active'): int {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        
        $encFilename = \App\Service\EncryptionService::encrypt($filename);
        $shortId = bin2hex(random_bytes(4)); // 8 chars
        
        $stmt = $db->prepare("INSERT INTO files (user_id, stored_file_id, folder_id, filename, delete_at, is_public, short_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $storedFileId, $folderId, $encFilename, $deleteAt, $isPublic, $shortId, $status]);
        $newId = (int)$db->lastInsertId();

        if ($newId) {
            \App\Service\SystemStatsService::increment('total_files');
        }

        return $newId;
    }

    public static function find(int|string $id): ?array {
        self::ensureSchema();
        if (is_callable(self::$mockFind)) {
            return call_user_func(self::$mockFind, $id);
        }
        $db = Database::getInstance()->getConnection();

        try {
            if (is_numeric($id)) {
                $stmt = $db->prepare("
                    SELECT f.*, sf.storage_path, sf.storage_provider, sf.mime_type, sf.file_size, sf.file_server_id, sf.provider_etag, sf.file_hash
                    FROM files f
                    JOIN stored_files sf ON f.stored_file_id = sf.id
                    WHERE (f.short_id = ? OR f.id = ?)
                      AND f.status NOT IN ('deleted', 'pending_purge', 'failed', 'abandoned', 'quarantined')
                    ORDER BY CASE WHEN f.short_id = ? THEN 0 ELSE 1 END
                    LIMIT 1
                ");
                $stmt->execute([(string)$id, (int)$id, (string)$id]);
            } else {
                $stmt = $db->prepare("
                    SELECT f.*, sf.storage_path, sf.storage_provider, sf.mime_type, sf.file_size, sf.file_server_id, sf.provider_etag, sf.file_hash
                    FROM files f
                    JOIN stored_files sf ON f.stored_file_id = sf.id
                    WHERE f.short_id = ?
                      AND f.status NOT IN ('deleted', 'pending_purge', 'failed', 'abandoned', 'quarantined')
                    LIMIT 1
                ");
                $stmt->execute([$id]);
            }
            $file = $stmt->fetch() ?: null;
        } catch (\PDOException $e) {
            return null;
        }
        
        if ($file) {
            $file = self::decryptRow($file);
        }
        
        return $file;
    }

    public static function findAnyStatus(int|string $id): ?array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        try {
            if (is_numeric($id)) {
                $stmt = $db->prepare("
                    SELECT f.*, sf.storage_path, sf.storage_provider, sf.mime_type, sf.file_size, sf.file_server_id, sf.provider_etag, sf.file_hash
                    FROM files f
                    JOIN stored_files sf ON f.stored_file_id = sf.id
                    WHERE (f.short_id = ? OR f.id = ?)
                    ORDER BY CASE WHEN f.short_id = ? THEN 0 ELSE 1 END
                    LIMIT 1
                ");
                $stmt->execute([(string)$id, (int)$id, (string)$id]);
            } else {
                $stmt = $db->prepare("
                    SELECT f.*, sf.storage_path, sf.storage_provider, sf.mime_type, sf.file_size, sf.file_server_id, sf.provider_etag, sf.file_hash
                    FROM files f
                    JOIN stored_files sf ON f.stored_file_id = sf.id
                    WHERE f.short_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$id]);
            }
            $file = $stmt->fetch() ?: null;
            if ($file) {
                $file = self::decryptRow($file);
            }
            return $file;
        } catch (\PDOException $e) {
            return null;
        }
    }

    public static function incrementDownloads(int $id): void {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE files SET downloads = downloads + 1, last_download_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function delete(int $id): void {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE files SET status = 'deleted' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function trash(int $id): void {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE files
            SET deleted_restore_status = CASE
                    WHEN status <> 'deleted' THEN status
                    ELSE deleted_restore_status
                END,
                status = 'deleted'
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    public static function restore(int $id): void {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE files
            SET status = COALESCE(NULLIF(deleted_restore_status, ''), 'active'),
                deleted_restore_status = NULL
            WHERE id = ? AND status = 'deleted'
        ");
        $stmt->execute([$id]);
    }

    public static function hardDelete(int $id, ?array $audit = null): void {
        self::ensureSchema();
        FileDeletionLog::boot();
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT user_id, status, stored_file_id, filename,
                       (SELECT file_size FROM stored_files WHERE id = files.stored_file_id) as size
                FROM files
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $file = $stmt->fetch();

            if (!$file) {
                $db->rollBack();
                return;
            }

            $decodedFilename = isset($file['filename']) ? (string)\App\Service\EncryptionService::decrypt($file['filename']) : '';
            $auditRole = strtolower(trim((string)($audit['deleted_by_role'] ?? 'system')));
            if ($auditRole === '') {
                $auditRole = 'system';
            }
            $auditLabel = isset($audit['deleted_by_label']) ? trim((string)$audit['deleted_by_label']) : '';
            if ($auditLabel === '') {
                $auditLabel = $auditRole === 'admin' ? 'Administrator' : ($auditRole === 'user' ? 'You' : 'System');
            }
            $auditReason = isset($audit['delete_reason']) ? trim((string)$audit['delete_reason']) : '';
            $auditUserId = isset($audit['deleted_by_user_id']) ? (int)$audit['deleted_by_user_id'] : null;

            if (!empty($file['user_id'])) {
                FileDeletionLog::record(
                    (int)$file['user_id'],
                    $id,
                    $decodedFilename,
                    $auditReason !== '' ? $auditReason : null,
                    $auditUserId,
                    $auditRole,
                    $auditLabel
                );
            }

            $storedFileId = (int)$file['stored_file_id'];

            // Delete the logical file row first so the final stored file release will not violate files_stored_fk.
            $stmtDel = $db->prepare("DELETE FROM files WHERE id = ?");
            $stmtDel->execute([$id]);

            StoredFile::decrementRefCount($storedFileId);

            if ($file['user_id']) {
                $db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) - ?), storage_warning_sent = 0 WHERE id = ?")
                   ->execute([$file['size'] ?? 0, $file['user_id']]);
            }

            if ($file['status'] === 'active') {
                \App\Service\SystemStatsService::decrement('total_files');
            }
            \App\Service\SystemStatsService::decrement('total_storage_bytes', (int)($file['size'] ?? 0));

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        StoredFile::hardDelete($storedFileId);
    }


    public static function getByUser(int $userId, ?int $folderId = null): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT f.*, sf.file_size, sf.mime_type, sf.storage_path, sf.storage_provider, sf.file_hash
            , sf.file_server_id, sf.provider_etag
            FROM files f 
            JOIN stored_files sf ON f.stored_file_id = sf.id 
            WHERE f.user_id = ? AND (f.status = 'active' OR f.status = 'hidden' OR f.status = 'ready' OR f.status = 'processing')
            AND f.folder_id " . ($folderId === null ? "IS NULL" : "= ?") . "
            AND f.status NOT IN ('pending_purge')
            ORDER BY f.created_at DESC
        ";
        $stmt = $db->prepare($sql);
        if ($folderId === null) {
            $stmt->execute([$userId]);
        } else {
            $stmt->execute([$userId, $folderId]);
        }
        
        $files = $stmt->fetchAll();
        foreach ($files as &$file) {
            $file = self::decryptRow($file);
        }
        
        return $files;
    }

    public static function getDeletedByUser(int $userId): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT f.*, sf.file_size, sf.mime_type, sf.storage_path, sf.storage_provider, sf.file_hash
            , sf.file_server_id, sf.provider_etag
            FROM files f 
            JOIN stored_files sf ON f.stored_file_id = sf.id 
            WHERE f.user_id = ? AND f.status = 'deleted'
            ORDER BY f.created_at DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        $files = $stmt->fetchAll();
        
        foreach ($files as &$file) {
            $file = self::decryptRow($file);
        }
        
        return $files;
    }

    public static function update(int $id, array $data): bool {
        self::ensureSchema();

        // only allow known columns to prevent SQL column-name injection
        static $allowed = [
            'folder_id', 'status', 'deleted_restore_status', 'filename',
            'is_public', 'password', 'allow_ppd', 'delete_at',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        if (empty($data)) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            if ($key === 'filename' || $key === 'password') {
                $values[] = \App\Service\EncryptionService::encrypt($value);
            } else {
                $values[] = $value;
            }
        }
        $values[] = $id;
        $sql = "UPDATE files SET " . implode(', ', $fields) . " WHERE id = ?";
        return $db->prepare($sql)->execute($values);
    }

    public static function copy(int $id, ?int $targetFolderId = null): int|bool {
        self::ensureSchema();
        $file = self::find($id);
        if (!$file) return false;

        $db = Database::getInstance()->getConnection();
        $newFilename = "Copy of " . $file['filename'];
        $encFilename = \App\Service\EncryptionService::encrypt($newFilename);
        $shortId = bin2hex(random_bytes(4));
        
        $stmt = $db->prepare("INSERT INTO files (user_id, stored_file_id, folder_id, filename, status, short_id) VALUES (?, ?, ?, ?, 'active', ?)");
        $stmt->execute([$file['user_id'], $file['stored_file_id'], $targetFolderId, $encFilename, $shortId]);
        $newId = (int)$db->lastInsertId();

        if ($newId) {
            // Increment ref_count on stored_files
            $stmtInc = $db->prepare("UPDATE stored_files SET ref_count = ref_count + 1 WHERE id = ?");
            $stmtInc->execute([$file['stored_file_id']]);

            \App\Service\SystemStatsService::increment('total_files');
        }

        return $newId;
    }

    public static function userHasStoredFile(int $userId, int $storedFileId): bool
    {
        self::ensureSchema();
        if ($userId <= 0 || $storedFileId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT 1
            FROM files
            WHERE user_id = ?
              AND stored_file_id = ?
              AND status IN ('active', 'hidden', 'ready', 'processing')
            LIMIT 1
        ");
        $stmt->execute([$userId, $storedFileId]);

        return (bool)$stmt->fetchColumn();
    }

    public static function createSavedCopyForUser(int $sourceFileId, int $targetUserId, ?int $targetFolderId = null, ?int $maxStorageBytes = null): int|false
    {
        self::ensureSchema();
        if ($targetUserId <= 0) {
            return false;
        }

        $sourceFile = self::find($sourceFileId);
        if (!$sourceFile) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $storedFileId = (int)$sourceFile['stored_file_id'];
            // Lock the saver's user row so concurrent save-to-account requests serialize cleanly.
            $stmtUser = $db->prepare("SELECT default_privacy FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmtUser->execute([$targetUserId]);
            $defaultPrivacy = (string)$stmtUser->fetchColumn();
            if ($defaultPrivacy === '') {
                $db->rollBack();
                return false;
            }

            $stmtExisting = $db->prepare("
                SELECT id
                FROM files
                WHERE user_id = ?
                  AND stored_file_id = ?
                  AND status IN ('active', 'hidden', 'ready', 'processing')
                LIMIT 1
                FOR UPDATE
            ");
            $stmtExisting->execute([$targetUserId, $storedFileId]);
            if ($stmtExisting->fetchColumn()) {
                $db->rollBack();
                return false;
            }

            $fileSize = (int)($sourceFile['file_size'] ?? 0);
            if (($maxStorageBytes ?? 0) > 0) {
                $stmtUsage = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmtUsage->execute([$targetUserId]);
                $storageUsed = (int)$stmtUsage->fetchColumn();
                if (($storageUsed + $fileSize) > (int)$maxStorageBytes) {
                    $db->rollBack();
                    return false;
                }
            }

            $isPublic = $defaultPrivacy === 'private' ? 0 : 1;

            $newFileId = self::create(
                $targetUserId,
                $storedFileId,
                (string)$sourceFile['filename'],
                $targetFolderId,
                null,
                $isPublic,
                'active'
            );

            StoredFile::incrementRefCount($storedFileId);

            $stmtStorage = $db->prepare("UPDATE users SET storage_used = storage_used + ?, storage_warning_sent = 0 WHERE id = ?");
            $stmtStorage->execute([$fileSize, $targetUserId]);

            $db->commit();
            return $newFileId;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function decryptRow(array $row): array {
        if (!\App\Service\EncryptionService::isReady()) return $row;

        // Optimized Scan: Only check columns marked as Encrypted in files or stored_files
        static $fileEncCols = null;
        if ($fileEncCols === null) {
            $fileEncCols = array_merge(
                \App\Service\Database\SchemaService::getEncryptedColumns('files'),
                \App\Service\Database\SchemaService::getEncryptedColumns('stored_files')
            );
        }

        foreach ($fileEncCols as $col) {
            if (isset($row[$col]) && is_string($row[$col]) && str_starts_with($row[$col], 'ENC:')) {
                $row[$col] = \App\Service\EncryptionService::decrypt($row[$col]);
            }
        }
        return $row;
    }

    private static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        if ($db->inTransaction()) {
            return;
        }
        try {
            $db->exec("ALTER TABLE `files` ADD COLUMN `deleted_restore_status` VARCHAR(32) NULL AFTER `status`");
        } catch (\Throwable $e) {
        }

        self::$schemaReady = true;
    }
}
