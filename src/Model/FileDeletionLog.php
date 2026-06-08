<?php

namespace App\Model;

use App\Core\Database;
use App\Core\Logger;
use App\Service\Database\SchemaService;

class FileDeletionLog
{
    private static bool $tableChecked = false;

    public static function boot(): void
    {
        self::assertTableAvailableForMutation();
    }

    public static function record(
        int $uploaderUserId,
        ?int $originalFileId,
        string $originalFilename,
        ?string $deleteReason,
        ?int $deletedByUserId,
        string $deletedByRole,
        ?string $deletedByLabel = null,
        bool $deleteFileEarnings = false,
        bool $deleteFileEarningsAuthorized = false,
        ?int $rewardsReviewerId = null
    ): void {
        if ($uploaderUserId <= 0) {
            return;
        }

        if (!self::tableAvailable()) {
            return;
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO file_deletion_log (
                uploader_user_id,
                original_file_id,
                original_filename,
                delete_reason,
                deleted_by_user_id,
                deleted_by_role,
                deleted_by_label,
                delete_file_earnings,
                delete_file_earnings_authorized,
                rewards_reviewer_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $uploaderUserId,
            $originalFileId,
            \App\Service\EncryptionService::encrypt($originalFilename),
            $deleteReason !== null && $deleteReason !== '' ? \App\Service\EncryptionService::encrypt($deleteReason) : null,
            $deletedByUserId,
            $deletedByRole,
            $deletedByLabel !== null && $deletedByLabel !== '' ? \App\Service\EncryptionService::encrypt($deletedByLabel) : null,
            $deleteFileEarnings ? 1 : 0,
            $deleteFileEarningsAuthorized ? 1 : 0,
            $rewardsReviewerId,
        ]);
    }

    public static function findLatestByOriginalFileId(int $originalFileId): ?array
    {
        if ($originalFileId <= 0) {
            return null;
        }

        if (!self::tableAvailable()) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT *
            FROM file_deletion_log
            WHERE original_file_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$originalFileId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $rows = self::decryptRows([$row]);
        return $rows[0] ?? null;
    }

    public static function getByUploader(int $userId, int $limit = 25): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (!self::tableAvailable()) {
            return [];
        }
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

        if (!self::tableAvailable()) {
            return [];
        }
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

        if (!self::tableAvailable()) {
            return 0;
        }
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

        if (!self::tableAvailable()) {
            return false;
        }
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

        SchemaService::ensureTables(['file_deletion_log'], false);
        self::$tableChecked = true;
    }

    private static function assertTableAvailableForMutation(): void
    {
        if (!self::tableAvailable()) {
            throw new \RuntimeException('File deletion history is temporarily unavailable until an administrator repairs the database schema.');
        }
    }

    private static function tableAvailable(): bool
    {
        try {
            self::ensureTable();
            return true;
        } catch (\Throwable $e) {
            Logger::warning('file deletion log schema unavailable', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
