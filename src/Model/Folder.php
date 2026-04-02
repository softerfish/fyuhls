<?php

namespace App\Model;

use App\Core\Database;
use PDO;

class Folder {
    private static bool $schemaReady = false;

    public static function create(int $userId, string $name, int|string|null $parentId = null): int {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        
        // Resolve parentId if it's a slug
        if ($parentId && !is_numeric($parentId)) {
            $parent = self::find($parentId);
            $parentId = ($parent && ($parent['status'] ?? 'active') === 'active' && (int)($parent['user_id'] ?? 0) === $userId) ? (int)$parent['id'] : null;
        } else {
            $parentId = $parentId ? (int)$parentId : null;
        }

        if ($parentId !== null) {
            $parent = self::find($parentId);
            if (!$parent || ($parent['status'] ?? 'active') !== 'active' || (int)($parent['user_id'] ?? 0) !== $userId) {
                $parentId = null;
            }
        }

        $encName = \App\Service\EncryptionService::encrypt($name);
        $shortId = bin2hex(random_bytes(4));
        
        $stmt = $db->prepare("INSERT INTO folders (user_id, parent_id, name, short_id, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute([$userId, $parentId, $encName, $shortId]);
        return (int)$db->lastInsertId();
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

    public static function getByUser(int $userId, ?int $parentId = null): array {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $sql = "
            SELECT f.*, 
                   (SELECT COUNT(*) FROM folders f2 WHERE f2.parent_id = f.id) as folder_count,
                   (SELECT COUNT(*) FROM files fi WHERE fi.folder_id = f.id AND fi.status = 'active') as file_count,
                   (SELECT SUM(sf.file_size) FROM files fi 
                    JOIN stored_files sf ON fi.stored_file_id = sf.id 
                    WHERE fi.folder_id = f.id AND fi.status = 'active') as total_size
            FROM folders f 
            WHERE f.user_id = ? AND f.status = 'active' AND f.parent_id " . ($parentId === null ? "IS NULL" : "= ?");
        
        $stmt = $db->prepare($sql);
        if ($parentId === null) {
            $stmt->execute([$userId]);
        } else {
            $stmt->execute([$userId, $parentId]);
        }
        
        $folders = $stmt->fetchAll();
        foreach ($folders as &$folder) {
            $allChildIds = self::getRecursiveSubfolderIds((int)$folder['id']);
            $idList = array_merge([(int)$folder['id']], $allChildIds);
            $inClause = implode(',', $idList);

            // Recursive folder count (excluding self)
            $folder['folder_count'] = count($allChildIds);

            // Recursive file count
            $stmtFiles = $db->query("SELECT COUNT(*) FROM files WHERE folder_id IN ($inClause) AND status = 'active'");
            $folder['file_count'] = (int)$stmtFiles->fetchColumn();

            // Recursive total size
            $stmtSize = $db->query("
                SELECT SUM(sf.file_size) 
                FROM files fi 
                JOIN stored_files sf ON fi.stored_file_id = sf.id 
                WHERE fi.folder_id IN ($inClause) AND fi.status = 'active'
            ");
            $folder['total_size'] = (int)$stmtSize->fetchColumn();

            $folder['name'] = \App\Service\EncryptionService::decrypt($folder['name']);
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

    public static function update(int $id, array $data): bool {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
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
        return $db->prepare($sql)->execute($values);
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

    public static function softDeleteTree(int $folderId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $allFolderIds = array_merge([$folderId], self::getAllRecursiveFolderIds($folderId));
        $inClause = implode(',', array_map('intval', $allFolderIds));
        $db->exec("UPDATE folders SET status = 'deleted' WHERE id IN ($inClause)");
    }

    public static function restoreTree(int $folderId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $allFolderIds = self::getTreeIds($folderId);
        $inClause = implode(',', array_map('intval', $allFolderIds));
        $db->exec("UPDATE folders SET status = 'active' WHERE id IN ($inClause)");
    }

    public static function getTreeIds(int $folderId): array
    {
        self::ensureSchema();
        return array_merge([$folderId], self::getAllRecursiveFolderIds($folderId));
    }

    public static function hardDeleteTree(int $folderId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $allFolderIds = array_merge([$folderId], self::getAllRecursiveFolderIds($folderId));
        $inClause = implode(',', array_map('intval', $allFolderIds));

        $stmt = $db->query("SELECT id FROM files WHERE folder_id IN ($inClause)");
        $fileIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fileIds as $fileId) {
            \App\Model\File::hardDelete((int)$fileId);
        }

        $db->prepare("DELETE FROM folders WHERE id = ?")->execute([$folderId]);
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

    private static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $db->exec("ALTER TABLE `folders` ADD COLUMN `status` ENUM('active', 'deleted') NOT NULL DEFAULT 'active' AFTER `name`");
        } catch (\Throwable $e) {
        }

        self::$schemaReady = true;
    }
}
