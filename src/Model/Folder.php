<?php

namespace App\Model;

use App\Core\Database;
use App\Service\Database\SchemaService;
use PDO;

class Folder {
    private static bool $schemaReady = false;

    public static function create(int $userId, string $name, int|string|null $parentId = null): int {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $parentId = self::normalizeParentIdForWrite($db, $parentId, $userId);
            $encName = \App\Service\EncryptionService::encrypt($name);
            $shortId = bin2hex(random_bytes(4));

            $stmt = $db->prepare("INSERT INTO folders (user_id, parent_id, name, short_id, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$userId, $parentId, $encName, $shortId]);
            $folderId = (int)$db->lastInsertId();

            if ($ownTransaction) {
                $db->commit();
            }

            return $folderId;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function find(int|string $id): ?array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        try {
            // Support both numeric ID and hash-based short_id
            $whereClause = is_numeric($id) ? "id = ?" : "short_id = ?";

            $stmt = $db->prepare("SELECT * FROM folders WHERE $whereClause");
            $stmt->execute([$id]);
            $folder = $stmt->fetch() ?: null;
        } catch (\PDOException $e) {
            return null;
        }

        if ($folder) {
            $folder['name'] = \App\Service\EncryptionService::decrypt($folder['name']);
        }

        return $folder;
    }

    public static function findByShortId(string $shortId): ?array {
        self::ensureSchema();
        $shortId = trim($shortId);
        if ($shortId === '') {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $stmt = $db->prepare("SELECT * FROM folders WHERE short_id = ? LIMIT 1");
            $stmt->execute([$shortId]);
            $folder = $stmt->fetch() ?: null;
        } catch (\PDOException $e) {
            return null;
        }

        if ($folder) {
            $folder['name'] = \App\Service\EncryptionService::decrypt($folder['name']);
        }

        return $folder;
    }

    public static function getByUser(int $userId, ?int $parentId = null): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM folders WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $allFolders = $stmt->fetchAll();
        if ($allFolders === []) {
            return [];
        }

        $foldersById = [];
        $childrenByParent = [];
        foreach ($allFolders as $row) {
            $folderId = (int)($row['id'] ?? 0);
            if ($folderId <= 0) {
                continue;
            }

            $normalizedParentId = array_key_exists('parent_id', $row) && $row['parent_id'] !== null
                ? (int)$row['parent_id']
                : null;
            $row['parent_id'] = $normalizedParentId;

            $foldersById[$folderId] = $row;
            $childrenByParent[self::folderParentMapKey($normalizedParentId)][] = $folderId;
        }

        if ($foldersById === []) {
            return [];
        }

        $fileMetricsByFolderId = self::loadActiveFileMetricsByFolderId($db, array_keys($foldersById));
        $statsMemo = [];
        $folders = [];

        foreach ($childrenByParent[self::folderParentMapKey($parentId)] ?? [] as $folderId) {
            if (!isset($foldersById[$folderId])) {
                continue;
            }

            $folder = $foldersById[$folderId];
            $stats = self::recursiveStatsForFolder($folderId, $childrenByParent, $fileMetricsByFolderId, $statsMemo);
            $folder['folder_count'] = $stats['folder_count'];
            $folder['file_count'] = $stats['file_count'];
            $folder['total_size'] = $stats['total_size'];
            $folder['name'] = \App\Service\EncryptionService::decrypt($folder['name']);
            $folders[] = $folder;
        }

        return $folders;
    }

    public static function getRecursiveSubfolderIds(int $folderId): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ids = [];

        $stmt = $db->prepare("SELECT id FROM folders WHERE parent_id = ? AND status = 'active'");
        $stmt->execute([$folderId]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            $ids[] = (int)$childId;
            $ids = array_merge($ids, self::getRecursiveSubfolderIds((int)$childId));
        }

        return $ids;
    }

    public static function getAllByUser(int $userId): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM folders WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $folders = $stmt->fetchAll();
        foreach ($folders as &$folder) {
            $folder['name'] = \App\Service\EncryptionService::decrypt($folder['name']);
        }
        return $folders;
    }

    public static function getDeletedByUser(int $userId): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM folders WHERE user_id = ? AND status = 'deleted' ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $folders = $stmt->fetchAll();
        foreach ($folders as &$folder) {
            $folder['name'] = \App\Service\EncryptionService::decrypt($folder['name']);
            $folder['folder_count'] = 0;
            $folder['file_count'] = 0;
            $folder['total_size'] = 0;
        }
        return $folders;
    }

    public static function update(int $id, array $data): bool {
        self::ensureSchema();
        static $allowed = ['name', 'parent_id', 'status'];
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
            $folderUserId = null;
            if (array_key_exists('parent_id', $data)) {
                $stmtFolder = $db->prepare("SELECT user_id FROM folders WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmtFolder->execute([$id]);
                $folderUserId = $stmtFolder->fetchColumn();
                if ($folderUserId === false) {
                    if ($ownTransaction && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    return false;
                }

                $data['parent_id'] = self::normalizeParentIdForWrite($db, $data['parent_id'], (int)$folderUserId, $id);
            }

            $fields = [];
            $values = [];
            foreach ($data as $key => $value) {
                $fields[] = "$key = ?";
                if ($key === 'name') {
                    $values[] = \App\Service\EncryptionService::encrypt($value);
                } else {
                    $values[] = $value;
                }
            }
            $values[] = $id;
            $sql = "UPDATE folders SET " . implode(', ', $fields) . " WHERE id = ?";
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

    public static function delete(int $id): bool {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM folders WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function isSubfolderOf(int|string $targetId, int|string $parentId): bool {
        self::ensureSchema();
        $target = self::find($targetId);
        $parent = self::find($parentId);
        if (!$target || !$parent) return false;

        $targetId = (int)$target['id'];
        $parentId = (int)$parent['id'];

        if ($targetId === $parentId) return true;

        $db = Database::getInstance()->getConnection();
        $currId = (int)$target['parent_id'];

        while ($currId !== null && $currId > 0) {
            if ($currId === $parentId) return true;
            $stmt = $db->prepare("SELECT parent_id FROM folders WHERE id = ?");
            $stmt->execute([$currId]);
            $row = $stmt->fetch();
            $currId = $row ? (int)$row['parent_id'] : null;
        }

        return false;
    }

    public static function trashTree(int $folderId): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $bonusTouchUserIds = [];
        try {
            $allFolderIds = array_merge([$folderId], self::getAllRecursiveFolderIds($folderId));
            $inClause = implode(',', array_map('intval', $allFolderIds));
            if ($inClause === '') {
                if ($ownTransaction) {
                    $db->commit();
                }
                return [];
            }

            $fileRows = $db->query("
                SELECT f.user_id, f.status, COALESCE(sf.file_size, 0) AS file_size
                FROM files f
                LEFT JOIN stored_files sf ON sf.id = f.stored_file_id
                WHERE f.folder_id IN ($inClause) AND f.status <> 'deleted'
                FOR UPDATE
            ")->fetchAll(PDO::FETCH_ASSOC);
            $bonusTouchUserIds = array_values(array_unique(array_map(
                'intval',
                array_filter(array_column($fileRows, 'user_id'))
            )));
            $storageDeltas = self::aggregateUserStorageDeltas(
                $fileRows,
                static fn(array $row): string => 'deleted'
            );

            $db->exec("
                UPDATE files
                SET deleted_restore_status = CASE
                        WHEN status <> 'deleted' THEN status
                        ELSE deleted_restore_status
                    END,
                    status = 'deleted'
                WHERE folder_id IN ($inClause) AND status <> 'deleted'
            ");
            self::applyUserStorageDeltas($db, $storageDeltas);
            $db->exec("UPDATE folders SET status = 'deleted' WHERE id IN ($inClause)");

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
                'workflow' => 'trash_folder_tree',
                'folder_id' => $folderId,
            ]);
        }

        return $bonusTouchUserIds;
    }

    public static function softDeleteTree(int $folderId): array
    {
        return self::trashTree($folderId);
    }

    public static function restoreTree(int $folderId): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $bonusTouchUserIds = [];
        try {
            $allFolderIds = self::lockTreeIds($db, $folderId);
            if ($allFolderIds === []) {
                if ($ownTransaction) {
                    $db->commit();
                }
                return [];
            }

            $rootStmt = $db->prepare("SELECT user_id, parent_id FROM folders WHERE id = ? LIMIT 1 FOR UPDATE");
            $rootStmt->execute([$folderId]);
            $rootFolder = $rootStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($rootFolder)) {
                if ($ownTransaction) {
                    $db->commit();
                }
                return [];
            }

            $restoredParentId = self::resolveRestorableParentId(
                $db,
                (int)($rootFolder['user_id'] ?? 0),
                isset($rootFolder['parent_id']) ? (int)$rootFolder['parent_id'] : null
            );

            $inClause = implode(',', array_map('intval', $allFolderIds));
            $fileRows = $db->query("
                SELECT f.user_id, f.status, f.deleted_restore_status, COALESCE(sf.file_size, 0) AS file_size
                FROM files f
                LEFT JOIN stored_files sf ON sf.id = f.stored_file_id
                WHERE f.folder_id IN ($inClause) AND f.status = 'deleted'
                FOR UPDATE
            ")->fetchAll(PDO::FETCH_ASSOC);
            $bonusTouchUserIds = array_values(array_unique(array_map(
                'intval',
                array_filter(array_column($fileRows, 'user_id'))
            )));
            $storageDeltas = self::aggregateUserStorageDeltas(
                $fileRows,
                static function (array $row): string {
                    $restoredStatus = trim((string)($row['deleted_restore_status'] ?? ''));
                    return $restoredStatus !== '' ? $restoredStatus : 'active';
                }
            );

            $db->exec("
                UPDATE files
                SET status = COALESCE(NULLIF(deleted_restore_status, ''), 'active'),
                    deleted_restore_status = NULL
                WHERE folder_id IN ($inClause) AND status = 'deleted'
            ");
            self::applyUserStorageDeltas($db, $storageDeltas);
            $db->exec("UPDATE folders SET status = 'active' WHERE id IN ($inClause)");
            $db->prepare("UPDATE folders SET parent_id = ? WHERE id = ?")->execute([$restoredParentId, $folderId]);

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
                'workflow' => 'restore_folder_tree',
                'folder_id' => $folderId,
            ]);
        }

        return $bonusTouchUserIds;
    }

    private static function aggregateUserStorageDeltas(array $fileRows, callable $nextStatusResolver): array
    {
        $deltas = [];
        foreach ($fileRows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $delta = File::storageUsageDeltaForTransition(
                (string)($row['status'] ?? ''),
                (string)$nextStatusResolver($row),
                (int)($row['file_size'] ?? 0)
            );
            if ($delta === 0) {
                continue;
            }

            $deltas[$userId] = ($deltas[$userId] ?? 0) + $delta;
        }

        return $deltas;
    }

    private static function applyUserStorageDeltas(\PDO $db, array $deltas): void
    {
        foreach ($deltas as $userId => $delta) {
            File::applyStorageUsageDelta($db, (int)$userId, (int)$delta);
        }
    }

    public static function getTreeIds(int $folderId): array
    {
        self::ensureSchema();
        return array_merge([$folderId], self::getAllRecursiveFolderIds($folderId));
    }

    public static function hardDeleteTree(int $folderId, ?array $audit = null): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        $storedFileIds = [];
        $bonusTouchUserIds = [];
        try {
            $allFolderIds = self::lockTreeIds($db, $folderId);
            $inClause = implode(',', array_map('intval', $allFolderIds));
            if ($inClause === '') {
                if ($ownTransaction) {
                    $db->commit();
                }
                return;
            }

            $stmt = $db->query("SELECT id, stored_file_id FROM files WHERE folder_id IN ($inClause) FOR UPDATE");
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $fileIds = array_map(static fn (array $file): int => (int)($file['id'] ?? 0), $files);
            $storedFileIds = array_values(array_unique(array_filter(array_map(static fn (array $file): int => (int)($file['stored_file_id'] ?? 0), $files))));
            \App\Model\File::validateHardDeleteBatch($fileIds, $audit);
            foreach ($fileIds as $fileId) {
                $reversalResult = \App\Model\File::hardDelete($fileId, $audit);
                foreach (($reversalResult['user_ids'] ?? []) as $userId) {
                    $normalizedUserId = (int)$userId;
                    if ($normalizedUserId > 0) {
                        $bonusTouchUserIds[$normalizedUserId] = $normalizedUserId;
                    }
                }
            }

            $orderedFolderIds = array_reverse($allFolderIds);
            foreach ($orderedFolderIds as $deleteFolderId) {
                $db->prepare("DELETE FROM folders WHERE id = ?")->execute([(int)$deleteFolderId]);
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
            foreach ($storedFileIds as $storedFileId) {
                $storedFileCleanup = \App\Model\StoredFile::purgeIfUnreferenced((int)$storedFileId);
                if (($storedFileCleanup['status'] ?? '') === 'failed') {
                    \App\Core\Logger::warning('Stored-file cleanup deferred after folder tree hard delete', [
                        'folder_id' => $folderId,
                        'stored_file_id' => (int)$storedFileId,
                        'reason' => $storedFileCleanup['reason'] ?? 'unknown',
                    ]);
                }
            }

            if ($bonusTouchUserIds !== []) {
                \App\Service\BonusOfferService::touchUsersFailSoft(array_values($bonusTouchUserIds), true, [
                    'workflow' => 'hard_delete_folder_tree',
                    'folder_id' => $folderId,
                ]);
            }
        }
    }

    public static function copyTree(int $folderId, int $userId, ?int $targetParentId = null, ?string $newName = null): ?int
    {
        self::ensureSchema();
        $folder = self::find($folderId);
        if (!$folder || (int)($folder['user_id'] ?? 0) !== $userId || ($folder['status'] ?? 'active') !== 'active') {
            return null;
        }

        $copyName = trim((string)($newName ?? ''));
        if ($copyName === '') {
            $copyName = (string)$folder['name'] . ' (Copy)';
        }

        $db = Database::getInstance()->getConnection();
        $newFolderId = self::create($userId, $copyName, $targetParentId);
        $copySucceeded = false;

        try {
            $stmtFiles = $db->prepare("SELECT id FROM files WHERE user_id = ? AND folder_id = ? AND status IN ('active', 'hidden', 'ready', 'processing')");
            $stmtFiles->execute([$userId, $folderId]);
            foreach ($stmtFiles->fetchAll(PDO::FETCH_COLUMN) as $fileId) {
                if (!\App\Model\File::copy((int)$fileId, $newFolderId)) {
                    return null;
                }
            }

            $stmtFolders = $db->prepare("SELECT id FROM folders WHERE user_id = ? AND parent_id = ? AND status = 'active'");
            $stmtFolders->execute([$userId, $folderId]);
            foreach ($stmtFolders->fetchAll(PDO::FETCH_COLUMN) as $childFolderId) {
                if (self::copyTree((int)$childFolderId, $userId, $newFolderId, null) === null) {
                    return null;
                }
            }

            $copySucceeded = true;
            return $newFolderId;
        } finally {
            if (!$copySucceeded && $newFolderId > 0) {
                try {
                    self::cleanupPartialCopyTree($newFolderId, $userId);
                } catch (\Throwable $e) {
                    \App\Core\Logger::error('Folder copy cleanup failed after partial copy error', [
                        'source_folder_id' => $folderId,
                        'new_folder_id' => $newFolderId,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private static function cleanupPartialCopyTree(int $folderId, int $userId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $allFolderIds = array_merge([$folderId], self::getAllRecursiveFolderIds($folderId));
        if ($allFolderIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($allFolderIds), '?'));
        $stmt = $db->prepare("
            SELECT id, user_id, stored_file_id, status, creation_origin,
                   (SELECT file_size FROM stored_files WHERE id = files.stored_file_id) AS size
            FROM files
            WHERE folder_id IN ($placeholders)
            FOR UPDATE
        ");
        $stmt->execute($allFolderIds);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $file) {
            if ((int)($file['user_id'] ?? 0) !== $userId || (string)($file['creation_origin'] ?? '') !== 'copy') {
                throw new \RuntimeException('Partial folder copy cleanup encountered an unexpected file outside the copied branch.');
            }
        }

        foreach ($files as $file) {
            $fileId = (int)($file['id'] ?? 0);
            $storedFileId = (int)($file['stored_file_id'] ?? 0);
            $fileSize = (int)($file['size'] ?? 0);
            if ($fileId <= 0) {
                continue;
            }

            $db->prepare("DELETE FROM files WHERE id = ?")->execute([$fileId]);
            if ($storedFileId > 0) {
                \App\Model\StoredFile::decrementRefCount($storedFileId);
            }
            if ($fileSize > 0) {
                $db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) - ?), storage_warning_sent = 0 WHERE id = ?")
                    ->execute([$fileSize, $userId]);
            }
            if (($file['status'] ?? 'active') === 'active') {
                \App\Service\SystemStatsService::decrement('total_files');
            }
        }

        foreach (array_reverse($allFolderIds) as $deleteFolderId) {
            $db->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?")->execute([(int)$deleteFolderId, $userId]);
        }
    }

    public static function purgeDeletedByUser(int $userId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT id
            FROM folders
            WHERE user_id = ? AND status = 'deleted'
            ORDER BY parent_id IS NULL DESC, id ASC
        ");
        $stmt->execute([$userId]);
        $folderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($folderIds as $folderId) {
            $check = $db->prepare("SELECT status FROM folders WHERE id = ? LIMIT 1");
            $check->execute([(int)$folderId]);
            if ($check->fetchColumn() === 'deleted') {
                $db->prepare("DELETE FROM folders WHERE id = ?")->execute([(int)$folderId]);
            }
        }
    }

    private static function getAllRecursiveFolderIds(int $folderId): array
    {
        $db = Database::getInstance()->getConnection();
        $ids = [];

        $stmt = $db->prepare("SELECT id FROM folders WHERE parent_id = ?");
        $stmt->execute([$folderId]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            $ids[] = (int)$childId;
            $ids = array_merge($ids, self::getAllRecursiveFolderIds((int)$childId));
        }

        return $ids;
    }

    private static function normalizeParentIdForWrite(\PDO $db, int|string|null $parentId, int $userId, ?int $currentFolderId = null): ?int
    {
        if ($parentId === null || $parentId === '' || $parentId === 'root') {
            return null;
        }

        $row = null;
        if (is_numeric($parentId)) {
            $stmt = $db->prepare("
                SELECT id, user_id, parent_id, status
                FROM folders
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([(int)$parentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $stmt = $db->prepare("
                SELECT id, user_id, parent_id, status
                FROM folders
                WHERE short_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([(string)$parentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (
            !is_array($row)
            || (int)($row['user_id'] ?? 0) !== $userId
            || (string)($row['status'] ?? 'active') !== 'active'
        ) {
            throw new \RuntimeException('The selected parent folder is no longer available.');
        }

        $resolvedParentId = (int)($row['id'] ?? 0);
        if ($currentFolderId !== null) {
            self::assertParentIsNotDescendant($db, $currentFolderId, $resolvedParentId);
        }

        return $resolvedParentId > 0 ? $resolvedParentId : null;
    }

    private static function assertParentIsNotDescendant(\PDO $db, int $currentFolderId, int $resolvedParentId): void
    {
        if ($currentFolderId <= 0 || $resolvedParentId <= 0) {
            return;
        }

        if ($currentFolderId === $resolvedParentId) {
            throw new \RuntimeException('A folder cannot be moved into itself or one of its descendants.');
        }

        $ancestorId = $resolvedParentId;
        while ($ancestorId > 0) {
            if ($ancestorId === $currentFolderId) {
                throw new \RuntimeException('A folder cannot be moved into itself or one of its descendants.');
            }

            $stmt = $db->prepare("
                SELECT parent_id
                FROM folders
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$ancestorId]);
            $parentValue = $stmt->fetchColumn();
            if ($parentValue === false || $parentValue === null) {
                break;
            }

            $ancestorId = (int)$parentValue;
        }
    }

    private static function resolveRestorableParentId(\PDO $db, int $userId, ?int $parentId): ?int
    {
        $ancestorId = $parentId !== null ? (int)$parentId : null;
        while ($ancestorId !== null && $ancestorId > 0) {
            $stmt = $db->prepare("
                SELECT id, user_id, parent_id, status
                FROM folders
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$ancestorId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($row)
                || (int)($row['user_id'] ?? 0) !== $userId
                || (string)($row['status'] ?? 'active') !== 'active'
            ) {
                return null;
            }

            $ancestorId = isset($row['parent_id']) ? (int)$row['parent_id'] : null;
        }

        return $parentId !== null && $parentId > 0 ? (int)$parentId : null;
    }

    private static function lockTreeIds(\PDO $db, int $folderId): array
    {
        $stmt = $db->prepare("SELECT id FROM folders WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$folderId]);
        if (!$stmt->fetchColumn()) {
            return [];
        }

        return array_merge([$folderId], self::lockRecursiveChildFolderIds($db, $folderId));
    }

    private static function loadActiveFileMetricsByFolderId(\PDO $db, array $folderIds): array
    {
        if ($folderIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
        $stmt = $db->prepare("
            SELECT fi.folder_id, COUNT(*) AS file_count, COALESCE(SUM(sf.file_size), 0) AS total_size
            FROM files fi
            JOIN stored_files sf ON fi.stored_file_id = sf.id
            WHERE fi.status = 'active' AND fi.folder_id IN ($placeholders)
            GROUP BY fi.folder_id
        ");
        $stmt->execute(array_values($folderIds));

        $metrics = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $folderId = (int)($row['folder_id'] ?? 0);
            if ($folderId <= 0) {
                continue;
            }

            $metrics[$folderId] = [
                'file_count' => (int)($row['file_count'] ?? 0),
                'total_size' => (int)($row['total_size'] ?? 0),
            ];
        }

        return $metrics;
    }

    private static function recursiveStatsForFolder(int $folderId, array $childrenByParent, array $fileMetricsByFolderId, array &$memo): array
    {
        if (isset($memo[$folderId])) {
            return $memo[$folderId];
        }

        $folderCount = 0;
        $fileCount = (int)($fileMetricsByFolderId[$folderId]['file_count'] ?? 0);
        $totalSize = (int)($fileMetricsByFolderId[$folderId]['total_size'] ?? 0);

        foreach ($childrenByParent[self::folderParentMapKey($folderId)] ?? [] as $childFolderId) {
            $childStats = self::recursiveStatsForFolder((int)$childFolderId, $childrenByParent, $fileMetricsByFolderId, $memo);
            $folderCount += 1 + (int)$childStats['folder_count'];
            $fileCount += (int)$childStats['file_count'];
            $totalSize += (int)$childStats['total_size'];
        }

        $memo[$folderId] = [
            'folder_count' => $folderCount,
            'file_count' => $fileCount,
            'total_size' => $totalSize,
        ];

        return $memo[$folderId];
    }

    private static function folderParentMapKey(?int $parentId): string
    {
        return $parentId === null ? 'root' : (string)$parentId;
    }

    private static function lockRecursiveChildFolderIds(\PDO $db, int $folderId): array
    {
        $ids = [];
        $stmt = $db->prepare("SELECT id FROM folders WHERE parent_id = ? FOR UPDATE");
        $stmt->execute([$folderId]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            $childId = (int)$childId;
            if ($childId <= 0) {
                continue;
            }
            $ids[] = $childId;
            $ids = array_merge($ids, self::lockRecursiveChildFolderIds($db, $childId));
        }

        return $ids;
    }

    private static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        SchemaService::ensureTables(['folders'], false);
        self::$schemaReady = true;
    }
}
