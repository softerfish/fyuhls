<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Core\StorageManager;
use App\Model\File;
use App\Model\FileDeletionLog;
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
            'review_staged' => 0,
            'errors' => 0,
            'freed_bytes' => 0,
            'cache_files_cleaned' => 0,
            'cache_bytes_freed' => 0
        ];

        foreach ($expiredFiles as $file) {
            try {
                // Centralized "Atomic Release" - uses specific server provider internally
                \App\Model\File::hardDelete((int)$file['id'], [
                    'deleted_by_role' => 'system',
                    'deleted_by_label' => 'System',
                    'delete_reason' => 'Expired file auto-cleanup.',
                ]);

                $results['deleted']++;
                Logger::info("Auto-cleanup: Expired file purged", ['file_id' => $file['id'], 'filename' => $file['filename']]);
            } catch (Exception $e) {
                if ($this->shouldStageExpiredFileForRewardReview($e) && $this->stageExpiredFileForRewardReview((int)$file['id'])) {
                    $results['review_staged']++;
                    Logger::warning('Auto-cleanup staged expired file for manual reward review before permanent purge.', [
                        'file_id' => (int)$file['id'],
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

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

    private function shouldStageExpiredFileForRewardReview(Exception $e): bool
    {
        return str_contains(
            $e->getMessage(),
            'This file has reward-linked history and cannot be permanently deleted without an authorized reward review.'
        );
    }

    private function stageExpiredFileForRewardReview(int $fileId): bool
    {
        if ($fileId <= 0) {
            return false;
        }

        FileDeletionLog::boot();

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        $touchUserId = 0;
        $staged = false;

        try {
            $stmt = $db->prepare("
                SELECT id, user_id, filename, status,
                       (SELECT file_size FROM stored_files WHERE id = files.stored_file_id) AS size
                FROM files
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();

            if (!$file || (string)($file['status'] ?? '') !== 'active') {
                $db->rollBack();
                return false;
            }

            if (!empty($file['user_id']) && !FileDeletionLog::hasOriginalFileId($fileId)) {
                $touchUserId = (int)$file['user_id'];
                FileDeletionLog::record(
                    (int)$file['user_id'],
                    $fileId,
                    (string)\App\Service\EncryptionService::decrypt((string)($file['filename'] ?? '')),
                    'Expired file auto-cleanup requires reward review before permanent purge.',
                    null,
                    'system',
                    'System'
                );
            }

            $update = $db->prepare("
                UPDATE files
                SET deleted_restore_status = CASE
                        WHEN status <> 'deleted' THEN status
                        ELSE deleted_restore_status
                    END,
                    status = 'deleted',
                    delete_at = NULL
                WHERE id = ?
            ");
            $update->execute([$fileId]);
            $staged = $update->rowCount() === 1;
            if ($staged) {
                File::applyStorageUsageDelta(
                    $db,
                    !empty($file['user_id']) ? (int)$file['user_id'] : null,
                    File::storageUsageDeltaForTransition((string)($file['status'] ?? ''), 'deleted', (int)($file['size'] ?? 0))
                );
            }

            $db->commit();
            if ($staged && $touchUserId > 0) {
                \App\Service\BonusOfferService::touchUserFailSoft($touchUserId, true, [
                    'workflow' => 'expired_file_cleanup_stage',
                    'file_id' => $fileId,
                ]);
            }

            return $staged;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::error('Auto-cleanup could not stage expired file for reward review.', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
