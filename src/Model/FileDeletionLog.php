<?php

namespace App\Model;

use App\Core\Database;

class FileDeletionLog
{
    private static bool $tableChecked = false;

    public static function boot(): void
    {
        self::ensureTable();
    }

    public static function record(
        int $uploaderUserId,
        ?int $originalFileId,
        string $originalFilename,
        ?string $deleteReason,
        ?int $deletedByUserId,
        string $deletedByRole,
        ?string $deletedByLabel = null
    ): void {
        if ($uploaderUserId <= 0) {
            return;
        }

        self::ensureTable();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO file_deletion_log (
                uploader_user_id,
                original_file_id,
                original_filename,
                delete_reason,
                deleted_by_user_id,
                deleted_by_role,
                deleted_by_label
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uploaderUserId,
            $originalFileId,
            \App\Service\EncryptionService::encrypt($originalFilename),
            $deleteReason !== null && $deleteReason !== '' ? \App\Service\EncryptionService::encrypt($deleteReason) : null,
            $deletedByUserId,
            $deletedByRole,
            $deletedByLabel !== null && $deletedByLabel !== '' ? \App\Service\EncryptionService::encrypt($deletedByLabel) : null,
        ]);
    }

    public static function getByUploader(int $userId, int $limit = 25): array
    {
        if ($userId <= 0) {
            return [];
        }

        self::ensureTable();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT *
            FROM file_deletion_log
            WHERE uploader_user_id = ?
            ORDER BY deleted_at DESC, id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return self::decryptRows($stmt->fetchAll() ?: []);
    }

    public static function getByUploaderPage(int $userId, int $page = 1, int $perPage = 20, string $scope = 'all'): array
    {
        if ($userId <= 0) {
            return [];
        }

        self::ensureTable();
        $db = Database::getInstance()->getConnection();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        [$whereSql, $params] = self::scopeClause($scope);

        $stmt = $db->prepare("
            SELECT *
            FROM file_deletion_log
            WHERE uploader_user_id = ?
              {$whereSql}
            ORDER BY deleted_at DESC, id DESC
            LIMIT ? OFFSET ?
        ");
        $index = 1;
        $stmt->bindValue($index++, $userId, \PDO::PARAM_INT);
        foreach ($params as $param) {
            $stmt->bindValue($index++, $param, \PDO::PARAM_STR);
        }
        $stmt->bindValue($index++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return self::decryptRows($stmt->fetchAll() ?: []);
    }

    public static function countByUploader(int $userId, string $scope = 'all'): int
    {
        if ($userId <= 0) {
            return 0;
        }

        self::ensureTable();
        $db = Database::getInstance()->getConnection();
        [$whereSql, $params] = self::scopeClause($scope);
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM file_deletion_log
            WHERE uploader_user_id = ?
              {$whereSql}
        ");
        $index = 1;
        $stmt->bindValue($index++, $userId, \PDO::PARAM_INT);
        foreach ($params as $param) {
            $stmt->bindValue($index++, $param, \PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public static function hasOriginalFileId(int $originalFileId): bool
    {
        if ($originalFileId <= 0) {
            return false;
        }

        self::ensureTable();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT 1 FROM file_deletion_log WHERE original_file_id = ? LIMIT 1");
        $stmt->execute([$originalFileId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function decryptValue($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (str_starts_with($value, 'ENC:')) {
            return (string)\App\Service\EncryptionService::decrypt($value);
        }

        return $value;
    }

    private static function decryptRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['original_filename'] = self::decryptValue($row['original_filename'] ?? null);
            $row['delete_reason'] = self::decryptValue($row['delete_reason'] ?? null);
            $row['deleted_by_label'] = self::decryptValue($row['deleted_by_label'] ?? null);
        }

        return $rows;
    }

    private static function scopeClause(string $scope): array
    {
        $scope = strtolower(trim($scope));
        return match ($scope) {
            'user' => ["AND deleted_by_role = ?", ['user']],
            'admin' => ["AND deleted_by_role <> ?", ['user']],
            default => ['', []],
        };
    }

    private static function ensureTable(): void
    {
        if (self::$tableChecked) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS `file_deletion_log` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uploader_user_id` BIGINT UNSIGNED NOT NULL,
                `original_file_id` BIGINT UNSIGNED NULL,
                `original_filename` TEXT NOT NULL,
                `delete_reason` TEXT NULL,
                `deleted_by_user_id` BIGINT UNSIGNED NULL,
                `deleted_by_role` VARCHAR(32) NOT NULL DEFAULT 'user',
                `deleted_by_label` TEXT NULL,
                `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `file_deletion_uploader_idx` (`uploader_user_id`, `deleted_at`),
                INDEX `file_deletion_actor_idx` (`deleted_by_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::$tableChecked = true;
    }
}
