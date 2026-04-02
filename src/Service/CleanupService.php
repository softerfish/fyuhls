<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Core\StorageManager;
use App\Model\File;
use App\Model\StoredFile;
use Exception;

class CleanupService
{
    /**
     * Prevent overlapping cron runs using a filesystem lock
     */
    public function lock(): bool {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $lockFile = $root . '/storage/cache/cron.lock';
        
        $fp = fopen($lockFile, 'c');
        if (!$fp) return false;
        
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        
        // Keep the handle open so the lock persists
        $this->lockHandle = $fp;
        return true;
    }

    public function unlock(): void {
        if (isset($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
    }

    private $lockHandle;

    /**
     * Delete files that have passed their delete_at date
     */
    public function runExpiredCleanup(): array
    {
        $db = Database::getInstance()->getConnection();
        
        // 1. Find expired files that are still 'active'
        $stmt = $db->prepare("SELECT id, filename, stored_file_id FROM files WHERE delete_at < NOW() AND status = 'active' LIMIT 100");
        $stmt->execute();
        $expiredFiles = $stmt->fetchAll();

        $results = [
            'deleted' => 0,
            'errors' => 0,
            'freed_bytes' => 0,
            'cache_files_cleaned' => 0,
            'cache_bytes_freed' => 0
        ];

        foreach ($expiredFiles as $file) {
            try {
                // Centralized "Atomic Release" - uses specific server provider internally
                \App\Model\File::hardDelete($file['id']);
                
                $results['deleted']++;
                Logger::info("Auto-cleanup: Expired file purged", ['file_id' => $file['id'], 'filename' => $file['filename']]);
            } catch (Exception $e) {
                $results['errors']++;
                Logger::error("Auto-cleanup failed for file ID: " . $file['id'], ['error' => $e->getMessage()]);
            }
        }

        // 2. Cleanup orphaned cache/temp files
        $cacheResults = $this->runCacheCleanup();
        $results['cache_files_cleaned'] = $cacheResults['files_deleted'];
        $results['cache_bytes_freed'] = $cacheResults['bytes_freed'];

        // Update last run time in settings
        $db->prepare("REPLACE INTO settings (setting_key, setting_value, setting_group) VALUES ('last_cron_run', NOW(), 'system')")->execute();

        return $results;
    }

    /**
     * Delete files in the cache directories that are older than 24 hours
     */
    public function runCacheCleanup(): array
    {
        $cacheDirs = [
            dirname(__DIR__, 2) . '/storage/cache/uploads',
            dirname(__DIR__, 2) . '/storage/cache/chunks'
        ];

        $results = [
            'files_deleted' => 0,
            'dirs_deleted' => 0,
            'bytes_freed' => 0
        ];

        foreach ($cacheDirs as $dir) {
            if (!is_dir($dir)) continue;

            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            $now = time();
            $maxAge = 86400; // 24 hours

            foreach ($items as $item) {
                if ($item->getMTime() < ($now - $maxAge)) {
                    $path = $item->getRealPath();
                    if ($item->isDir()) {
                        // only delete empty dirs
                        $files = scandir($path);
                        if (count($files) <= 2) { // . and ..
                            @rmdir($path);
                            $results['dirs_deleted']++;
                        }
                    } else {
                        $size = $item->getSize();
                        if (@unlink($path)) {
                            $results['bytes_freed'] += $size;
                            $results['files_deleted']++;
                        }
                    }
                }
            }
        }

        return $results;
    }
}
