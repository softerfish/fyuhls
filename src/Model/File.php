<?php

namespace App\Model;

use App\Core\Database;
use App\Service\Database\SchemaService;
use PDO;

class File {
    private static $mockFind = null;
    private static bool $schemaReady = false;
    private const STORAGE_CONSUMING_STATUSES = ['active', 'hidden', 'ready', 'processing'];
    public static function setMockFind(?callable $fn): void {
        self::$mockFind = $fn;
    }

    public static function create(?int $userId, int $storedFileId, string $filename, ?int $folderId = null, ?string $deleteAt = null, int $isPublic = 1, string $status = 'active', string $creationOrigin = 'upload'): int {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $folderId = self::normalizeFolderIdForWrite($db, $folderId, $userId);

            $encFilename = \App\Service\EncryptionService::encrypt($filename);
            $shortId = bin2hex(random_bytes(4)); // 8 chars
            $creationOrigin = in_array($creationOrigin, ['upload', 'copy', 'saved_copy'], true) ? $creationOrigin : 'upload';

            $stmt = $db->prepare("INSERT INTO files (user_id, stored_file_id, folder_id, filename, delete_at, is_public, short_id, status, creation_origin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $storedFileId, $folderId, $encFilename, $deleteAt, $isPublic, $shortId, $status, $creationOrigin]);
            $newId = (int)$db->lastInsertId();

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

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

    public static function findByShortId(string $shortId): ?array {
        self::ensureSchema();
        $shortId = trim($shortId);
        if ($shortId === '' || preg_match('/^[a-f0-9]{8}$/i', $shortId) !== 1) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $stmt = $db->prepare("
                SELECT f.*, sf.storage_path, sf.storage_provider, sf.mime_type, sf.file_size, sf.file_server_id, sf.provider_etag, sf.file_hash
                FROM files f
                JOIN stored_files sf ON f.stored_file_id = sf.id
                WHERE f.short_id = ?
                  AND f.status NOT IN ('deleted', 'pending_purge', 'failed', 'abandoned', 'quarantined')
                LIMIT 1
            ");
            $stmt->execute([$shortId]);
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

    public static function delete(int $id): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $bonusTouchUserIds = [];
        try {
            $stmt = $db->prepare("
                SELECT user_id, status,
                       (SELECT file_size FROM stored_files WHERE id = files.stored_file_id) AS size
                FROM files
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            if ($file) {
                $update = $db->prepare("UPDATE files SET status = 'deleted' WHERE id = ? AND status <> 'deleted'");
                $update->execute([$id]);
                if ($update->rowCount() === 1 && !empty($file['user_id'])) {
                    self::applyStorageUsageDelta(
                        $db,
                        (int)$file['user_id'],
                        self::storageUsageDeltaForTransition((string)($file['status'] ?? ''), 'deleted', (int)($file['size'] ?? 0))
                    );
                    $bonusTouchUserIds[] = (int)$file['user_id'];
                }
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($ownTransaction && $bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'delete_file',
                'file_id' => $id,
            ]);
        }

        return $bonusTouchUserIds;
    }

    public static function trash(int $id): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $bonusTouchUserIds = [];
        try {
            $stmt = $db->prepare("
                SELECT user_id, status,
                       (SELECT file_size FROM stored_files WHERE id = files.stored_file_id) AS size
                FROM files
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            if ($file) {
                $update = $db->prepare("
                    UPDATE files
                    SET deleted_restore_status = CASE
                            WHEN status <> 'deleted' THEN status
                            ELSE deleted_restore_status
                        END,
                        status = 'deleted'
                    WHERE id = ? AND status <> 'deleted'
                ");
                $update->execute([$id]);
                if ($update->rowCount() === 1 && !empty($file['user_id'])) {
                    self::applyStorageUsageDelta(
                        $db,
                        (int)$file['user_id'],
                        self::storageUsageDeltaForTransition((string)($file['status'] ?? ''), 'deleted', (int)($file['size'] ?? 0))
                    );
                    $bonusTouchUserIds[] = (int)$file['user_id'];
                }
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($ownTransaction && $bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'trash_file',
                'file_id' => $id,
            ]);
        }

        return $bonusTouchUserIds;
    }

    public static function restore(int $id): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $bonusTouchUserIds = [];
        try {
            $stmt = $db->prepare("
                SELECT user_id, status, deleted_restore_status,
                       (SELECT file_size FROM stored_files WHERE id = files.stored_file_id) AS size
                FROM files
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            if ($file) {
                $restoredStatus = (string)($file['deleted_restore_status'] ?? '');
                if ($restoredStatus === '') {
                    $restoredStatus = 'active';
                }
                $update = $db->prepare("
                    UPDATE files
                    SET status = COALESCE(NULLIF(deleted_restore_status, ''), 'active'),
                        deleted_restore_status = NULL
                    WHERE id = ? AND status = 'deleted'
                ");
                $update->execute([$id]);
                if ($update->rowCount() === 1 && !empty($file['user_id'])) {
                    self::applyStorageUsageDelta(
                        $db,
                        (int)$file['user_id'],
                        self::storageUsageDeltaForTransition((string)($file['status'] ?? ''), $restoredStatus, (int)($file['size'] ?? 0))
                    );
                    $bonusTouchUserIds[] = (int)$file['user_id'];
                }
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($ownTransaction && $bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'restore_file',
                'file_id' => $id,
            ]);
        }

        return $bonusTouchUserIds;
    }

    public static function markPendingPurge(int $id, ?array $audit = null): array {
        self::ensureSchema();
        FileDeletionLog::boot();
        if (!empty($audit['delete_file_earnings'])) {
            \App\Service\RewardService::prepareFileEarningsReversalRuntime();
        }
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare(self::appendForUpdateClause($db, "
                SELECT user_id, status, filename,
                       (SELECT file_size FROM stored_files WHERE id = files.stored_file_id) AS size
                FROM files
                WHERE id = ?
            "));
            $stmt->execute([$id]);
            $file = $stmt->fetch();

            if (!$file) {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
            }

            $audit = self::mergeStoredDeletionAudit($db, $id, $audit);
            [$auditRole, $auditLabel, $auditReason, $auditUserId] = self::normalizeDeletionAudit($audit);
            self::assertDeleteFileEarningsAuthorized($audit);
            self::assertRewardLinkedHardDeleteAuthorized($db, $id, $audit);

            $reversalResult = ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
            if (!empty($audit['delete_file_earnings']) && !empty($file['user_id'])) {
                $reversalResult = \App\Service\RewardService::reverseFileEarningsWithinTransaction(
                    $db,
                    $id,
                    (int)$file['user_id'],
                    isset($audit['rewards_reviewer_id']) ? (int)$audit['rewards_reviewer_id'] : $auditUserId,
                    $auditReason
                );
            }

            self::recordDeletionAuditIfNeeded($id, $file, $auditRole, $auditLabel, $auditReason, $auditUserId, $audit);
            $stmt = $db->prepare("UPDATE files SET status = 'pending_purge' WHERE id = ? AND status <> 'pending_purge'");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 1) {
                self::applyStorageUsageDelta(
                    $db,
                    !empty($file['user_id']) ? (int)$file['user_id'] : null,
                    self::storageUsageDeltaForTransition((string)($file['status'] ?? ''), 'pending_purge', (int)($file['size'] ?? 0))
                );
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $bonusTouchUserIds = self::mergeBonusTouchUserIds(
            $reversalResult['user_ids'] ?? [],
            !empty($file['user_id']) ? [(int)$file['user_id']] : []
        );
        $reversalResult['user_ids'] = $bonusTouchUserIds;

        if ($ownTransaction && $bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'soft_delete_file',
                'file_id' => $id,
            ]);
        }

        return $reversalResult;
    }

    public static function hardDelete(int $id, ?array $audit = null): array {
        self::ensureSchema();
        FileDeletionLog::boot();
        if (!empty($audit['delete_file_earnings'])) {
            \App\Service\RewardService::prepareFileEarningsReversalRuntime();
        }
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

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
                if ($ownTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
            }

            $audit = self::mergeStoredDeletionAudit($db, $id, $audit);
            [$auditRole, $auditLabel, $auditReason, $auditUserId] = self::normalizeDeletionAudit($audit);
            self::assertDeleteFileEarningsAuthorized($audit);
            self::assertRewardLinkedHardDeleteAuthorized($db, $id, $audit);

            $reversalResult = ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
            if (!empty($audit['delete_file_earnings']) && !empty($file['user_id'])) {
                $reversalResult = \App\Service\RewardService::reverseFileEarningsWithinTransaction(
                    $db,
                    $id,
                    (int)$file['user_id'],
                    isset($audit['rewards_reviewer_id']) ? (int)$audit['rewards_reviewer_id'] : $auditUserId,
                    $auditReason
                );
            }

            self::recordDeletionAuditIfNeeded($id, $file, $auditRole, $auditLabel, $auditReason, $auditUserId, $audit);
            $storedFileId = (int)$file['stored_file_id'];

            // Delete the logical file row first so the final stored file release will not violate files_stored_fk.
            $stmtDel = $db->prepare("DELETE FROM files WHERE id = ?");
            $stmtDel->execute([$id]);

            StoredFile::decrementRefCount($storedFileId);

            self::applyStorageUsageDelta(
                $db,
                !empty($file['user_id']) ? (int)$file['user_id'] : null,
                self::storageUsageDeltaForTransition((string)($file['status'] ?? ''), 'deleted', (int)($file['size'] ?? 0))
            );

            if ($file['status'] === 'active') {
                \App\Service\SystemStatsService::decrement('total_files');
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($ownTransaction) {
            $storedFileCleanup = StoredFile::purgeIfUnreferenced($storedFileId);
            if (($storedFileCleanup['status'] ?? '') === 'failed') {
                \App\Core\Logger::warning('Stored-file cleanup deferred after file hard delete', [
                    'file_id' => $id,
                    'stored_file_id' => $storedFileId,
                    'reason' => $storedFileCleanup['reason'] ?? 'unknown',
                ]);
            }
        }

        $bonusTouchUserIds = self::mergeBonusTouchUserIds(
            $reversalResult['user_ids'] ?? [],
            !empty($file['user_id']) ? [(int)$file['user_id']] : []
        );
        $reversalResult['user_ids'] = $bonusTouchUserIds;

        if ($ownTransaction && $bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'hard_delete_file',
                'file_id' => $id,
            ]);
        }

        return $reversalResult;
    }

    private static function normalizeDeletionAudit(?array $audit): array
    {
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

        return [$auditRole, $auditLabel, $auditReason, $auditUserId];
    }

    private static function mergeBonusTouchUserIds(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $userId) {
                $normalizedUserId = (int)$userId;
                if ($normalizedUserId > 0) {
                    $merged[$normalizedUserId] = $normalizedUserId;
                }
            }
        }

        return array_values($merged);
    }

    private static function appendForUpdateClause(PDO $db, string $sql): string
    {
        return Database::appendForUpdateClause($db, $sql);
    }

    private static function recordDeletionAuditIfNeeded(int $id, array $file, string $auditRole, string $auditLabel, string $auditReason, ?int $auditUserId, array $audit): void
    {
        $decodedFilename = isset($file['filename']) ? (string)\App\Service\EncryptionService::decrypt($file['filename']) : '';
        if (!empty($file['user_id']) && !FileDeletionLog::hasOriginalFileId($id)) {
            FileDeletionLog::record(
                (int)$file['user_id'],
                $id,
                $decodedFilename,
                $auditReason !== '' ? $auditReason : null,
                $auditUserId,
                $auditRole,
                $auditLabel,
                !empty($audit['delete_file_earnings']),
                !empty($audit['delete_file_earnings_authorized']),
                isset($audit['rewards_reviewer_id']) ? (int)$audit['rewards_reviewer_id'] : null
            );
        }
    }

    private static function assertDeleteFileEarningsAuthorized(?array $audit): void
    {
        if (empty($audit['delete_file_earnings'])) {
            return;
        }

        if (empty($audit['delete_file_earnings_authorized'])) {
            throw new \RuntimeException('Removing attached rewards during file deletion requires rewards-fraud review permission.');
        }
    }

    private static function assertRewardLinkedHardDeleteAuthorized(\PDO $db, int $fileId, ?array $audit): void
    {
        $fileId = (int)$fileId;
        if ($fileId <= 0 || !empty($audit['delete_file_earnings_authorized'])) {
            return;
        }

        $rewardReceiptStmt = $db->prepare("SELECT COUNT(*) FROM reward_receipts WHERE file_id = ? LIMIT 1");
        $rewardReceiptStmt->execute([$fileId]);
        $hasReceipts = (int)$rewardReceiptStmt->fetchColumn() > 0;

        $earningStmt = $db->prepare("
            SELECT COUNT(*)
            FROM earnings
            WHERE file_id = ?
              AND type IN ('download_reward', 'pps_reward', 'aggregate_summary')
            LIMIT 1
        ");
        $earningStmt->execute([$fileId]);
        $hasEarnings = (int)$earningStmt->fetchColumn() > 0;

        if ($hasReceipts || $hasEarnings) {
            throw new \RuntimeException('This file has reward-linked history and cannot be permanently deleted without an authorized reward review.');
        }
    }

    private static function mergeStoredDeletionAudit(\PDO $db, int $fileId, ?array $audit): array
    {
        $merged = is_array($audit) ? $audit : [];
        if ($fileId <= 0) {
            return $merged;
        }

        $storedAudit = FileDeletionLog::findLatestByOriginalFileId($fileId);
        if (!is_array($storedAudit)) {
            return $merged;
        }

        $fallbacks = [
            'deleted_by_user_id' => isset($storedAudit['deleted_by_user_id']) ? (int)$storedAudit['deleted_by_user_id'] : null,
            'deleted_by_role' => isset($storedAudit['deleted_by_role']) ? (string)$storedAudit['deleted_by_role'] : null,
            'deleted_by_label' => isset($storedAudit['deleted_by_label']) ? (string)$storedAudit['deleted_by_label'] : null,
            'delete_reason' => isset($storedAudit['delete_reason']) ? (string)$storedAudit['delete_reason'] : null,
            'delete_file_earnings' => !empty($storedAudit['delete_file_earnings']),
            'delete_file_earnings_authorized' => !empty($storedAudit['delete_file_earnings_authorized']),
            'rewards_reviewer_id' => isset($storedAudit['rewards_reviewer_id']) ? (int)$storedAudit['rewards_reviewer_id'] : null,
        ];

        foreach ($fallbacks as $key => $value) {
            if (!array_key_exists($key, $merged) || $merged[$key] === null || $merged[$key] === '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    public static function validateHardDeleteBatch(array $fileIds, ?array $audit = null): void
    {
        self::ensureSchema();
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0)));
        if ($fileIds === []) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            foreach ($fileIds as $fileId) {
                $stmt = $db->prepare(self::appendForUpdateClause($db, "SELECT id FROM files WHERE id = ? LIMIT 1"));
                $stmt->execute([$fileId]);
                if (!$stmt->fetchColumn()) {
                    continue;
                }

                $effectiveAudit = self::mergeStoredDeletionAudit($db, $fileId, $audit);
                self::assertDeleteFileEarningsAuthorized($effectiveAudit);
                self::assertRewardLinkedHardDeleteAuthorized($db, $fileId, $effectiveAudit);
            }

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
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
            'is_public', 'password', 'allow_ppd', 'delete_at', 'stored_file_id',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        if (empty($data)) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            if (array_key_exists('folder_id', $data)) {
                $stmtFile = $db->prepare("SELECT user_id FROM files WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmtFile->execute([$id]);
                $userId = $stmtFile->fetchColumn();
                if ($userId === false) {
                    if ($ownTransaction && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    return false;
                }

                $data['folder_id'] = self::normalizeFolderIdForWrite(
                    $db,
                    $data['folder_id'],
                    $userId !== null ? (int)$userId : null
                );
            }

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
            $result = $db->prepare($sql)->execute($values);

            if ($ownTransaction) {
                $db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function copy(int $id, ?int $targetFolderId = null, ?string $newFilename = null): int|bool {
        self::ensureSchema();
        $file = self::find($id);
        if (!$file) return false;

        $db = Database::getInstance()->getConnection();
        $copyName = trim((string)($newFilename ?? ''));
        if ($copyName === '') {
            $copyName = "Copy of " . $file['filename'];
        }
        $userId = (int)($file['user_id'] ?? 0);
        $storedFileId = (int)($file['stored_file_id'] ?? 0);
        $fileSize = (int)($file['file_size'] ?? 0);
        if ($userId <= 0 || $storedFileId <= 0 || $fileSize <= 0) {
            return false;
        }

        $package = \App\Model\Package::getUserPackage($userId);
        $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
        $encFilename = \App\Service\EncryptionService::encrypt($copyName);
        $shortId = bin2hex(random_bytes(4));

        $quotaLockHeld = false;
        $ownTransaction = !$db->inTransaction();
        if (!self::acquireUserStorageQuotaLock($db, $userId)) {
            return false;
        }
        $quotaLockHeld = true;

        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $stmtUser = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmtUser->execute([$userId]);
            $storageUsed = $stmtUser->fetchColumn();
            if ($storageUsed === false) {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return false;
            }

            $activeReserved = QuotaReservation::activeReservedBytesForUser($userId);
            if ($maxStorage > 0 && ((int)$storageUsed + $activeReserved + $fileSize) > $maxStorage) {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return false;
            }

            $targetFolderId = self::normalizeFolderIdForWrite($db, $targetFolderId, $userId);

            $stmt = $db->prepare("INSERT INTO files (user_id, stored_file_id, folder_id, filename, is_public, status, short_id, creation_origin) VALUES (?, ?, ?, ?, ?, 'active', ?, 'copy')");
            $stmt->execute([$userId, $storedFileId, $targetFolderId, $encFilename, (int)($file['is_public'] ?? 1), $shortId]);
            $newId = (int)$db->lastInsertId();
            if ($newId <= 0) {
                if ($ownTransaction && $db->inTransaction()) {
                    $db->rollBack();
                }
                return false;
            }

            $stmtInc = $db->prepare("UPDATE stored_files SET ref_count = ref_count + 1 WHERE id = ?");
            $stmtInc->execute([$storedFileId]);

            $stmtStorage = $db->prepare("UPDATE users SET storage_used = storage_used + ?, storage_warning_sent = 0 WHERE id = ?");
            $stmtStorage->execute([$fileSize, $userId]);

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        } finally {
            if ($quotaLockHeld) {
                self::releaseUserStorageQuotaLock($db, $userId);
            }
        }

        \App\Service\SystemStatsService::increment('total_files');
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

    public static function storageConsumingStatuses(): array
    {
        return self::STORAGE_CONSUMING_STATUSES;
    }

    public static function statusConsumesStorage(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::STORAGE_CONSUMING_STATUSES, true);
    }

    public static function storageUsageDeltaForTransition(string $previousStatus, string $nextStatus, int $bytes): int
    {
        $bytes = max(0, $bytes);
        if ($bytes === 0) {
            return 0;
        }

        $consumedBefore = self::statusConsumesStorage($previousStatus);
        $consumedAfter = self::statusConsumesStorage($nextStatus);
        if ($consumedBefore === $consumedAfter) {
            return 0;
        }

        return $consumedAfter ? $bytes : -$bytes;
    }

    public static function applyStorageUsageDelta(\PDO $db, ?int $userId, int $deltaBytes): void
    {
        $userId = (int)$userId;
        if ($userId <= 0 || $deltaBytes === 0) {
            return;
        }

        if ($deltaBytes > 0) {
            $db->prepare("UPDATE users SET storage_used = storage_used + ?, storage_warning_sent = 0 WHERE id = ?")
                ->execute([$deltaBytes, $userId]);
            return;
        }

        $db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) - ?), storage_warning_sent = 0 WHERE id = ?")
            ->execute([abs($deltaBytes), $userId]);
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

        $dedupeEnabled = \App\Model\Setting::get('upload_detect_duplicates', '1') === '1';
        StoredFile::ensureSchemaCompatibility();

        $db = Database::getInstance()->getConnection();
        $quotaLockHeld = false;
        if (!self::acquireUserStorageQuotaLock($db, $targetUserId)) {
            return false;
        }
        $quotaLockHeld = true;
        $db->beginTransaction();
        $clonedObject = null;
        $cloneServerId = 0;
        $serverCapacityLockHeld = false;

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

            if ($dedupeEnabled) {
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
            }

            $sourceFile = self::lockSourceFileForSavedCopy($db, $sourceFileId, $targetUserId);
            if (!$sourceFile) {
                $db->rollBack();
                return false;
            }

            $storedFileId = (int)$sourceFile['stored_file_id'];
            $fileSize = (int)($sourceFile['file_size'] ?? 0);
            if (($maxStorageBytes ?? 0) > 0) {
                $stmtUsage = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmtUsage->execute([$targetUserId]);
                $storageUsed = (int)$stmtUsage->fetchColumn();
                $activeReserved = QuotaReservation::activeReservedBytesForUser($targetUserId);
                if (($storageUsed + $activeReserved + $fileSize) > (int)$maxStorageBytes) {
                    $db->rollBack();
                    return false;
                }
            }

            $isPublic = $defaultPrivacy === 'private' ? 0 : 1;

            if (!$dedupeEnabled) {
                $cloneServerId = !empty($sourceFile['file_server_id']) ? (int)$sourceFile['file_server_id'] : 0;
                if ($cloneServerId > 0) {
                    if (!\App\Core\StorageManager::acquireServerCapacityLock($db, $cloneServerId, 10)) {
                        $db->rollBack();
                        return false;
                    }
                    $serverCapacityLockHeld = true;
                    \App\Core\StorageManager::assertServerHasCapacity($db, $cloneServerId, $fileSize, true);
                }

                $clonedObject = StoredFile::cloneStoredFileObject($storedFileId, (string)$sourceFile['filename']);
                if (!$clonedObject) {
                    $db->rollBack();
                    return false;
                }

                $storedFileId = StoredFile::create(
                    (string)($clonedObject['file_hash'] ?? ''),
                    (string)($clonedObject['storage_provider'] ?? ''),
                    (string)($clonedObject['storage_path'] ?? ''),
                    (int)($clonedObject['file_size'] ?? $fileSize),
                    (string)($clonedObject['mime_type'] ?? 'application/octet-stream'),
                    $clonedObject['file_server_id'] ?? null,
                    isset($clonedObject['provider_etag']) ? (string)$clonedObject['provider_etag'] : null
                );

                if (!empty($clonedObject['file_server_id'])) {
                    \App\Core\StorageManager::recordUsageOrFail($db, (int)$clonedObject['file_server_id'], $fileSize);
                }
                \App\Service\SystemStatsService::increment('total_storage_bytes', $fileSize);
            }

            $newFileId = self::create(
                $targetUserId,
                $storedFileId,
                (string)$sourceFile['filename'],
                $targetFolderId,
                null,
                $isPublic,
                'active',
                'saved_copy'
            );

            if ($dedupeEnabled) {
                StoredFile::incrementRefCount($storedFileId);
            }

            $stmtStorage = $db->prepare("UPDATE users SET storage_used = storage_used + ?, storage_warning_sent = 0 WHERE id = ?");
            $stmtStorage->execute([$fileSize, $targetUserId]);

            $db->commit();
            return $newFileId;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_array($clonedObject) && !empty($clonedObject['storage_path'])) {
                try {
                    $provider = \App\Core\StorageManager::getProviderById(
                        !empty($clonedObject['file_server_id']) ? (int)$clonedObject['file_server_id'] : null,
                        Database::getInstance()->getConnection()
                    );
                    $provider->delete((string)$clonedObject['storage_path']);
                    if (!empty($clonedObject['thumbnail_path'])) {
                        $provider->delete((string)$clonedObject['thumbnail_path']);
                    }
                } catch (\Throwable $cleanupError) {
                }
            }
            throw $e;
        } finally {
            if ($serverCapacityLockHeld && $cloneServerId > 0) {
                \App\Core\StorageManager::releaseServerCapacityLock($db, $cloneServerId);
            }
            if ($quotaLockHeld) {
                self::releaseUserStorageQuotaLock($db, $targetUserId);
            }
        }
    }

    private static function acquireUserStorageQuotaLock(\PDO $db, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private static function releaseUserStorageQuotaLock(\PDO $db, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        } catch (\Throwable $e) {
        }
    }

    public static function findPublicByShortId(string $shortId): ?array {
        self::ensureSchema();
        $shortId = trim($shortId);
        if ($shortId === '') {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $stmt = $db->prepare("
                SELECT f.*, sf.storage_path, sf.storage_provider, sf.mime_type, sf.file_size, sf.file_server_id, sf.provider_etag, sf.file_hash
                FROM files f
                JOIN stored_files sf ON f.stored_file_id = sf.id
                WHERE f.short_id = ?
                  AND f.is_public = 1
                  AND f.status IN ('active', 'ready', 'processing')
                LIMIT 1
            ");
            $stmt->execute([$shortId]);
            $file = $stmt->fetch() ?: null;
        } catch (\PDOException $e) {
            return null;
        }

        if ($file) {
            $file = self::decryptRow($file);
        }

        return $file;
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

        SchemaService::ensureTables(['files'], false);
        self::$schemaReady = true;
    }

    private static function normalizeFolderIdForWrite(\PDO $db, int|string|null $folderId, ?int $userId): ?int
    {
        if ($folderId === null || $folderId === '' || $folderId === 'root') {
            return null;
        }

        if ($userId === null || $userId <= 0) {
            throw new \RuntimeException('The selected folder is no longer available for this file.');
        }

        if (!is_numeric($folderId)) {
            $stmt = $db->prepare("
                SELECT id, user_id, status
                FROM folders
                WHERE short_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([(string)$folderId]);
        } else {
            $stmt = $db->prepare("
                SELECT id, user_id, status
                FROM folders
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([(int)$folderId]);
        }

        $folder = $stmt->fetch();
        if (
            !$folder
            || (string)($folder['status'] ?? 'active') !== 'active'
            || (int)($folder['user_id'] ?? 0) !== $userId
        ) {
            throw new \RuntimeException('The selected folder is no longer available for this file.');
        }

        return (int)$folder['id'];
    }

    private static function lockSourceFileForSavedCopy(\PDO $db, int $sourceFileId, int $targetUserId): ?array
    {
        if ($sourceFileId <= 0 || $targetUserId <= 0) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT f.*, sf.storage_path, sf.storage_provider, sf.mime_type, sf.file_size, sf.file_server_id, sf.provider_etag, sf.file_hash
            FROM files f
            INNER JOIN stored_files sf ON sf.id = f.stored_file_id
            WHERE f.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$sourceFileId]);
        $source = $stmt->fetch();
        if (!$source) {
            return null;
        }

        $status = (string)($source['status'] ?? '');
        $ownerId = (int)($source['user_id'] ?? 0);
        $isOwner = $ownerId > 0 && $ownerId === $targetUserId;
        $publiclySaveable = (int)($source['is_public'] ?? 0) === 1
            && in_array($status, ['active', 'ready', 'processing'], true);
        $ownerSaveable = $isOwner && in_array($status, self::STORAGE_CONSUMING_STATUSES, true);

        if (!$publiclySaveable && !$ownerSaveable) {
            return null;
        }

        return self::decryptRow($source);
    }
}
