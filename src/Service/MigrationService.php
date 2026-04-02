<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Core\StorageManager;
use App\Interface\StorageProvider;
use App\Model\StoredFile;
use Exception;

class MigrationService
{
    /**
     * Move a batch of files from one server to another
     * 
     * @throws Exception
     */
    public function migrate(int $fromServerId, int $toServerId, int $limit = 10): array
    {
        $db = Database::getInstance()->getConnection();
        
        // 1. Get Servers
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$fromServerId]);
        $source = $stmt->fetch();
        
        $stmt->execute([$toServerId]);
        $dest = $stmt->fetch();
        
        if (!$source || !$dest) throw new Exception("Invalid servers selected.");
        if ((int)$fromServerId === (int)$toServerId) throw new Exception("Source and destination servers must be different.");

        $sourceProvider = StorageManager::getProviderById($fromServerId, $db);
        $destProvider = StorageManager::getProviderById($toServerId, $db);

        // 2. Find Files on Source Server
        $stmt = $db->prepare("SELECT * FROM stored_files WHERE file_server_id = ? LIMIT ?");
        $stmt->execute([$fromServerId, $limit]);
        $files = $stmt->fetchAll();

        $results = [
            'success' => 0,
            'failed' => 0,
            'remaining' => 0
        ];

        foreach ($files as $file) {
            try {
                $storedFile = StoredFile::find((int)$file['id']);
                if (!$storedFile) {
                    throw new Exception("Stored file record not found.");
                }

                $primaryPath = $storedFile['storage_path'];
                if (!$sourceProvider->exists($primaryPath)) {
                    throw new Exception("Source file is missing from storage.");
                }

                if (!$this->copyBetweenProviders($sourceProvider, $destProvider, $primaryPath)) {
                    throw new Exception("Failed to copy file payload.");
                }

                foreach ($this->buildVariantPaths($storedFile) as $variantPath) {
                    if ($sourceProvider->exists($variantPath) && !$this->copyBetweenProviders($sourceProvider, $destProvider, $variantPath)) {
                        throw new Exception("Failed to copy variant payload.");
                    }
                }

                if (!$sourceProvider->delete($primaryPath)) {
                    throw new Exception("Copied file, but failed to remove source payload.");
                }

                $sourceProvider->deleteVariants($primaryPath, $this->buildVariantPaths($storedFile));

                // 4. Update DB after the bytes are actually moved
                $stmtUpdate = $db->prepare("UPDATE stored_files SET file_server_id = ?, storage_provider = ? WHERE id = ?");
                $stmtUpdate->execute([$toServerId, $dest['server_type'], $file['id']]);
                
                // 5. Update Server Capacities
                $db->prepare("UPDATE file_servers SET current_usage_bytes = current_usage_bytes - ? WHERE id = ?")->execute([$file['file_size'], $fromServerId]);
                $db->prepare("UPDATE file_servers SET current_usage_bytes = current_usage_bytes + ? WHERE id = ?")->execute([$file['file_size'], $toServerId]);

                $results['success']++;
            } catch (Exception $e) {
                Logger::error('File migration failed', ['file_id' => $file['id'], 'error' => $e->getMessage()]);
                $results['failed']++;
            }
        }

        // Count Remaining
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM stored_files WHERE file_server_id = ?");
        $stmtCount->execute([$fromServerId]);
        $results['remaining'] = (int)$stmtCount->fetchColumn();

        return $results;
    }

    private function copyBetweenProviders(StorageProvider $sourceProvider, StorageProvider $destProvider, string $path): bool
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'fy_mig_');
        if ($tmpPath === false) {
            throw new Exception("Failed to allocate temporary file for migration.");
        }

        $handle = fopen($tmpPath, 'wb');
        if ($handle === false) {
            @unlink($tmpPath);
            throw new Exception("Failed to open temporary migration file.");
        }

        ob_start(function (string $chunk) use ($handle): string {
            fwrite($handle, $chunk);
            return '';
        }, 65536);

        try {
            $sourceProvider->stream($path);
        } finally {
            ob_end_clean();
            fclose($handle);
        }

        $destProvider->delete($path);
        $saved = $destProvider->save($tmpPath, $path);
        if (file_exists($tmpPath)) {
            @unlink($tmpPath);
        }

        return $saved;
    }

    private function buildVariantPaths(array $storedFile): array
    {
        $pathParts = explode('/', $storedFile['storage_path'] ?? '');
        if (count($pathParts) < 3 || empty($storedFile['file_hash'])) {
            return [];
        }

        return [
            "thumbnails/{$pathParts[0]}/{$pathParts[1]}/{$storedFile['file_hash']}.jpg",
        ];
    }
}
