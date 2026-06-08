<?php

namespace App\Model;

use App\Core\Database;
use App\Service\Database\SchemaService;
use App\Service\Storage\StoragePathGuard;
use PDO;

class StoredFile {
    private static bool $schemaReady = false;

    public static function ensureSchemaCompatibility(): void
    {
        if (self::$schemaReady) {
            return;
        }

        SchemaService::ensureTables(['stored_files'], false);
        self::$schemaReady = true;
    }

    public static function buildThumbnailVariantPathFromStoragePath(string $storagePath): ?string
    {
        $storagePath = trim(str_replace('\\', '/', $storagePath), '/');
        if ($storagePath === '') {
            return null;
        }

        $pathParts = explode('/', $storagePath);
        if (count($pathParts) < 3) {
            return null;
        }

        $basename = pathinfo((string)end($pathParts), PATHINFO_FILENAME);
        if ($basename === '') {
            return null;
        }

        return 'thumbnails/' . $pathParts[0] . '/' . $pathParts[1] . '/' . $basename . '.jpg';
    }

    public static function findByHash(string $hash): ?array {
        self::ensureSchemaCompatibility();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE file_hash = ? LIMIT 1");
        $stmt->execute([$hash]);
        $storedFile = $stmt->fetch() ?: null;

        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function findByHashAndSize(string $hash, int $size): ?array {
        self::ensureSchemaCompatibility();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE file_hash = ? AND file_size = ? LIMIT 1");
        $stmt->execute([$hash, $size]);
        $storedFile = $stmt->fetch() ?: null;

        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function findAlternativesByHashAndSize(string $hash, int $size, ?int $excludeId = null, int $limit = 10): array {
        self::ensureSchemaCompatibility();
        $hash = trim($hash);
        if ($hash === '') {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $sql = "SELECT * FROM stored_files WHERE file_hash = ? AND file_size = ?";
        $params = [$hash, $size];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $excludeId;
        }

        $sql .= " ORDER BY checksum_verified_at DESC, ref_count DESC, id DESC LIMIT " . max(1, (int)$limit);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'decryptRow'], $stmt->fetchAll() ?: []);
    }

    public static function findByCompletedUploadChecksumAndSize(string $checksum, int $size): ?array {
        self::ensureSchemaCompatibility();
        $checksum = strtolower(trim($checksum));
        if ($checksum === '' || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT candidate.*
            FROM (
                SELECT sf.*, us.completed_at, us.id AS upload_session_id
                FROM upload_sessions us
                JOIN stored_files sf
                  ON CAST(JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.stored_file_id')) AS UNSIGNED) = sf.id
                WHERE us.status = 'completed'
                  AND us.checksum_sha256 = ?
                  AND sf.file_size = ?

                UNION ALL

                SELECT sf.*, us.completed_at, us.id AS upload_session_id
                FROM upload_sessions us
                JOIN files f
                  ON CAST(JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.file_id')) AS UNSIGNED) = f.id
                JOIN stored_files sf ON f.stored_file_id = sf.id
                WHERE us.status = 'completed'
                  AND us.checksum_sha256 = ?
                  AND sf.file_size = ?
                  AND JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.stored_file_id')) IS NULL
            ) AS candidate
            ORDER BY candidate.completed_at DESC, candidate.upload_session_id DESC
            LIMIT 1
        ");
        $stmt->execute([$checksum, $size, $checksum, $size]);
        $storedFile = $stmt->fetch() ?: null;

        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function find(int $id): ?array {
        self::ensureSchemaCompatibility();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $storedFile = $stmt->fetch() ?: null;

        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function create(string $hash, string $provider, string $path, int $size, string $mimeType, ?int $fileServerId = null, ?string $providerEtag = null): int {
        self::ensureSchemaCompatibility();
        $db = Database::getInstance()->getConnection();
        $path = StoragePathGuard::normalizeObjectPath($path, 'Stored file path');

        $encPath = \App\Service\EncryptionService::encrypt($path);
        $encMime = \App\Service\EncryptionService::encrypt($mimeType);

        $stmt = $db->prepare("
            INSERT INTO stored_files (
                file_hash, storage_provider, storage_path, file_size, mime_type,
                provider_etag, checksum_verified_at, ref_count, file_server_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$hash, $provider, $encPath, $size, $encMime, $providerEtag, date('Y-m-d H:i:s'), $fileServerId]);
        return (int)$db->lastInsertId();
    }

    public static function update(int $id, array $data): void {
        $db = Database::getInstance()->getConnection();
        if (array_key_exists('storage_path', $data) && $data['storage_path'] !== null) {
            $data['storage_path'] = StoragePathGuard::normalizeObjectPath((string)$data['storage_path'], 'Stored file path');
        }
        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            // Check if column is encrypted in schema
            $encCols = \App\Service\Database\SchemaService::getEncryptedColumns('stored_files');
            if (in_array($key, $encCols)) {
                $values[] = \App\Service\EncryptionService::encrypt($value);
            } else {
                $values[] = $value;
            }
        }
        $values[] = $id;
        $sql = "UPDATE stored_files SET " . implode(', ', $fields) . " WHERE id = ?";
        $db->prepare($sql)->execute($values);
    }

    public static function incrementRefCount(int $id): void {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE stored_files SET ref_count = ref_count + 1 WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function decrementRefCount(int $id): void {
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE stored_files SET ref_count = GREATEST(0, ref_count - 1) WHERE id = ?")->execute([$id]);
    }

    public static function countFileReferences(int $id): int {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM files WHERE stored_file_id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    public static function reconcileRefCount(int $id): int {
        $db = Database::getInstance()->getConnection();
        $actualRefs = self::countFileReferences($id);
        $stmt = $db->prepare("UPDATE stored_files SET ref_count = ? WHERE id = ?");
        $stmt->execute([$actualRefs, $id]);
        return $actualRefs;
    }

    public static function delete(int $id): void {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM stored_files WHERE id = ?");
        $stmt->execute([$id]);
    }

    public static function cloneStoredFileObject(int $id, ?string $preferredFilename = null): ?array
    {
        $storedFile = self::find($id);
        if (!$storedFile) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $provider = \App\Core\StorageManager::getProviderById(
            !empty($storedFile['file_server_id']) ? (int)$storedFile['file_server_id'] : null,
            $db
        );

        $newStoragePath = self::buildUniqueStoragePathForDuplicate($storedFile, $preferredFilename);
        if (!self::copyObjectBetweenProviders($provider, $provider, (string)$storedFile['storage_path'], $newStoragePath)) {
            return null;
        }

        $newThumbnailPath = self::buildThumbnailVariantPathFromStoragePath($newStoragePath);
        $sourceThumbnailPath = self::buildThumbnailVariantPathFromStoragePath((string)$storedFile['storage_path']);
        if ($sourceThumbnailPath !== null && $newThumbnailPath !== null && $provider->exists($sourceThumbnailPath)) {
            self::copyObjectBetweenProviders($provider, $provider, $sourceThumbnailPath, $newThumbnailPath);
        }

        $head = $provider->head($newStoragePath);
        return [
            'file_hash' => (string)($storedFile['file_hash'] ?? ''),
            'storage_provider' => (string)($storedFile['storage_provider'] ?? ''),
            'storage_path' => $newStoragePath,
            'file_size' => (int)($storedFile['file_size'] ?? 0),
            'mime_type' => (string)($storedFile['mime_type'] ?? 'application/octet-stream'),
            'file_server_id' => !empty($storedFile['file_server_id']) ? (int)$storedFile['file_server_id'] : null,
            'provider_etag' => is_array($head) ? ($head['etag'] ?? null) : null,
            'thumbnail_path' => $newThumbnailPath,
        ];
    }

    private static function decryptRow(array $row): array {
        if (!\App\Service\EncryptionService::isReady()) return $row;

        $encCols = \App\Service\Database\SchemaService::getEncryptedColumns('stored_files');
        foreach ($encCols as $col) {
            if (isset($row[$col]) && is_string($row[$col]) && str_starts_with($row[$col], 'ENC:')) {
                $row[$col] = \App\Service\EncryptionService::decrypt($row[$col]);
            }
        }
        return $row;
    }

    /**
     * Centralized "Atomic Release" - deletes the physical object and DB record only
     * after the final unreferenced object cleanup has been fully proven.
     */
    public static function purgeIfUnreferenced(int $id): array {
        $db = \App\Core\Database::getInstance()->getConnection();
        $actualRefs = self::reconcileRefCount($id);
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE id = ?");
        $stmt->execute([$id]);
        $sf = $stmt->fetch();

        if (!$sf || $actualRefs > 0 || (int)$sf['ref_count'] > 0) {
            if ($sf && ($actualRefs > 0 || (int)$sf['ref_count'] > 0)) {
                \App\Core\Logger::info('StoredFile purge skipped because references still exist', [
                    'id' => $id,
                    'actual_refs' => $actualRefs,
                    'recorded_ref_count' => (int)$sf['ref_count'],
                ]);
            }
            return [
                'status' => 'retained',
                'id' => $id,
                'actual_refs' => $actualRefs,
                'recorded_ref_count' => $sf ? (int)$sf['ref_count'] : 0,
            ];
        }

        // 1. Decrypt path and mime
        $sf = self::decryptRow($sf);

        try {
            // 2. Resolve SPECIFIC server provider (Fix for multiple R2/S3 servers)
            $storage = \App\Core\StorageManager::getProviderById($sf['file_server_id'], $db);

            // 3. Collect variants (thumbnails) - centralized variant logic
            $variants = [];
            $thumbnailVariant = self::buildThumbnailVariantPathFromStoragePath((string)$sf['storage_path']);
            if ($thumbnailVariant !== null) {
                $variants[] = $thumbnailVariant;
            }

            if ($variants !== [] && !$storage->deleteVariants((string)$sf['storage_path'], $variants)) {
                \App\Core\Logger::warning('StoredFile purge deferred because variant cleanup could not be proven', [
                    'id' => $id,
                    'path' => $sf['storage_path'],
                    'variants' => $variants,
                    'server' => $sf['file_server_id'],
                ]);
                return [
                    'status' => 'failed',
                    'id' => $id,
                    'reason' => 'variant_cleanup_failed',
                ];
            }

            // Missing objects on disk should still allow the canonical stored-file
            // record and usage counters to be purged idempotently.
            $objectMissing = method_exists($storage, 'exists') && !$storage->exists((string)$sf['storage_path']);
            if (!($objectMissing || $storage->delete((string)$sf['storage_path']))) {
                \App\Core\Logger::warning('StoredFile purge deferred because payload cleanup could not be proven', [
                    'id' => $id,
                    'path' => $sf['storage_path'],
                    'server' => $sf['file_server_id'],
                ]);
                return [
                    'status' => 'failed',
                    'id' => $id,
                    'reason' => 'payload_cleanup_failed',
                ];
            }

            // 5. DB Cleanup
            $db->prepare("DELETE FROM stored_files WHERE id = ?")->execute([$id]);

            // 6. Release usage from server stats
            if ($sf['file_server_id']) {
                \App\Core\StorageManager::releaseUsage($db, $sf['file_server_id'], (int)$sf['file_size']);
            }
            \App\Service\SystemStatsService::decrement('total_storage_bytes', (int)$sf['file_size']);

            \App\Core\Logger::info("StoredFile: Physical file and record purged", [
                'id' => $id,
                'path' => $sf['storage_path'],
                'server' => $sf['file_server_id'],
                'object_missing_on_disk' => $objectMissing,
            ]);
            return [
                'status' => 'purged',
                'id' => $id,
                'object_missing_on_disk' => $objectMissing,
                'released_bytes' => (int)$sf['file_size'],
                'file_server_id' => $sf['file_server_id'] ? (int)$sf['file_server_id'] : null,
            ];
        } catch (\Exception $e) {
            \App\Core\Logger::error("StoredFile: hardDelete failed", ['id' => $id, 'error' => $e->getMessage()]);
        }

        return [
            'status' => 'failed',
            'id' => $id,
            'reason' => 'exception',
        ];
    }

    public static function hardDelete(int $id): bool {
        return (self::purgeIfUnreferenced($id)['status'] ?? 'failed') === 'purged';
    }

    private static function buildUniqueStoragePathForDuplicate(array $storedFile, ?string $preferredFilename = null): string
    {
        $extension = strtolower((string)pathinfo((string)($preferredFilename ?: ($storedFile['storage_path'] ?? '')), PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension);
        $suffix = $extension !== '' ? '.' . $extension : '';
        $hash = trim((string)($storedFile['file_hash'] ?? ''));
        $prefix = date('Y/m') . '/';
        $basename = ($hash !== '' ? $hash : 'copy') . '-' . bin2hex(random_bytes(8));

        return $prefix . $basename . $suffix;
    }

    private static function copyObjectBetweenProviders(\App\Interface\StorageProvider $sourceProvider, \App\Interface\StorageProvider $destProvider, string $sourcePath, string $destPath): bool
    {
        $tmpPath = \App\Service\TemporaryArtifactService::createTempFile('fy_dup_');

        $handle = fopen($tmpPath, 'wb');
        if ($handle === false) {
            \App\Service\TemporaryArtifactService::cleanup($tmpPath);
            throw new \RuntimeException('Failed to open temporary duplication file.');
        }

        ob_start(function (string $chunk) use ($handle): string {
            fwrite($handle, $chunk);
            return '';
        }, 65536);

        try {
            $sourceProvider->stream($sourcePath);
        } finally {
            ob_end_clean();
            fclose($handle);
        }

        $saved = $destProvider->save($tmpPath, $destPath);
        \App\Service\TemporaryArtifactService::cleanup($tmpPath);

        return $saved;
    }
}
