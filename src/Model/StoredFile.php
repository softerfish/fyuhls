<?php

namespace App\Model;

use App\Core\Database;
use PDO;

class StoredFile {
    public static function findByHash(string $hash): ?array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE file_hash = ? LIMIT 1");
        $stmt->execute([$hash]);
        $storedFile = $stmt->fetch() ?: null;
        
        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function findByHashAndSize(string $hash, int $size): ?array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE file_hash = ? AND file_size = ? LIMIT 1");
        $stmt->execute([$hash, $size]);
        $storedFile = $stmt->fetch() ?: null;

        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function findAlternativesByHashAndSize(string $hash, int $size, ?int $excludeId = null, int $limit = 10): array {
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

    public static function findByProviderEtagAndSize(string $providerEtag, int $size): ?array {
        $providerEtag = trim($providerEtag);
        if ($providerEtag === '') {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE provider_etag = ? AND file_size = ? LIMIT 1");
        $stmt->execute([$providerEtag, $size]);
        $storedFile = $stmt->fetch() ?: null;

        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function findByCompletedUploadChecksumAndSize(string $checksum, int $size): ?array {
        $checksum = strtolower(trim($checksum));
        if ($checksum === '' || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT sf.*
            FROM upload_sessions us
            JOIN files f
              ON CAST(JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.file_id')) AS UNSIGNED) = f.id
            JOIN stored_files sf ON f.stored_file_id = sf.id
            WHERE us.status = 'completed'
              AND us.checksum_sha256 = ?
              AND sf.file_size = ?
            ORDER BY us.completed_at DESC, us.id DESC
            LIMIT 1
        ");
        $stmt->execute([$checksum, $size]);
        $storedFile = $stmt->fetch() ?: null;

        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function find(int $id): ?array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $storedFile = $stmt->fetch() ?: null;
        
        return $storedFile ? self::decryptRow($storedFile) : null;
    }

    public static function create(string $hash, string $provider, string $path, int $size, string $mimeType, ?int $fileServerId = null, ?string $providerEtag = null): int {
        $db = Database::getInstance()->getConnection();
        
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
     * Centralized "Atomic Release" - Deletes physical file and DB record if ref_count is 0.
     */
    public static function hardDelete(int $id): bool {
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
            return false;
        }

        // 1. Decrypt path and mime
        $sf = self::decryptRow($sf);

        try {
            // 2. Resolve SPECIFIC server provider (Fix for multiple R2/S3 servers)
            $storage = \App\Core\StorageManager::getProviderById($sf['file_server_id'], $db);

            // 3. Collect variants (thumbnails) - centralized variant logic
            $variants = [];
            $pathParts = explode('/', $sf['storage_path']);
            if (count($pathParts) >= 3) {
                // Simplified but safe thumbnail prediction
                $variants[] = "thumbnails/{$pathParts[0]}/{$pathParts[1]}/{$sf['file_hash']}.jpg";
            }

            // 4. Physical Delete
            if ($storage->delete($sf['storage_path'])) {
                $storage->deleteVariants($sf['storage_path'], $variants);
                
                // 5. DB Cleanup
                $db->prepare("DELETE FROM stored_files WHERE id = ?")->execute([$id]);

                // 6. Release usage from server stats
                if ($sf['file_server_id']) {
                    \App\Core\StorageManager::releaseUsage($db, $sf['file_server_id'], (int)$sf['file_size']);
                }

                \App\Core\Logger::info("StoredFile: Physical file and record purged", ['id' => $id, 'path' => $sf['storage_path'], 'server' => $sf['file_server_id']]);
                return true;
            }
        } catch (\Exception $e) {
            \App\Core\Logger::error("StoredFile: hardDelete failed", ['id' => $id, 'error' => $e->getMessage()]);
        }

        return false;
    }
}
