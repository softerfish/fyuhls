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
        $stmt->bindValue(1, $fromServerId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();
        $files = $stmt->fetchAll();

        $results = [
            'success' => 0,
            'failed' => 0,
            'remaining' => 0
        ];

        foreach ($files as $file) {
            $destCapacityLockHeld = false;
            try {
                $storedFile = StoredFile::find((int)$file['id']);
                if (!$storedFile) {
                    throw new Exception("Stored file record not found.");
                }

                $destCapacityLockHeld = StorageManager::acquireServerCapacityLock($db, $toServerId, 10);
                if (!$destCapacityLockHeld) {
                    throw new Exception("Destination storage capacity could not be reserved safely right now.");
                }
                StorageManager::assertServerHasCapacity($db, $toServerId, (int)($file['file_size'] ?? 0), true);

                $primaryPath = $storedFile['storage_path'];
                $variantPaths = $this->buildVariantPaths($storedFile);
                $sourceExists = $sourceProvider->exists($primaryPath);
                $copiedDestinationPayload = false;
                $copiedDestinationVariants = [];

                if (!$sourceExists) {
                    if (!$destProvider->exists($primaryPath)) {
                        throw new Exception("Source file is missing from storage.");
                    }
                    Logger::warning('Recovered partial migration state by using existing destination payload', [
                        'file_id' => (int)$file['id'],
                        'path' => $primaryPath,
                        'from_server_id' => $fromServerId,
                        'to_server_id' => $toServerId,
                    ]);
                } else {
                    $sourceHead = $sourceProvider->head($primaryPath);
                    if (!$this->copyBetweenProviders($sourceProvider, $destProvider, $primaryPath, $sourceHead)) {
                        throw new Exception("Failed to copy file payload.");
                    }
                    $copiedDestinationPayload = true;

                    foreach ($variantPaths as $variantPath) {
                        if ($sourceProvider->exists($variantPath)) {
                            if (!$this->copyBetweenProviders($sourceProvider, $destProvider, $variantPath, $sourceProvider->head($variantPath))) {
                                throw new Exception("Failed to copy variant payload.");
                            }
                            $copiedDestinationVariants[] = $variantPath;
                        }
                    }
                }

                $db->beginTransaction();
                try {
                    if (!$this->claimStoredFileSwitchover($db, (int)$file['id'], $fromServerId, $toServerId, (string)$dest['server_type'])) {
                        $db->rollBack();
                        if ($copiedDestinationPayload) {
                            $this->cleanupDestinationArtifacts($destProvider, $primaryPath, $copiedDestinationVariants);
                        }
                        Logger::warning('Skipped stale migration candidate after source pointer changed', [
                            'file_id' => (int)$file['id'],
                            'expected_from_server_id' => $fromServerId,
                            'to_server_id' => $toServerId,
                        ]);
                        continue;
                    }

                    \App\Core\StorageManager::recordUsageOrFail($db, $toServerId, (int)$file['file_size']);
                    if (!$sourceExists) {
                        \App\Core\StorageManager::releaseUsageOrFail($db, $fromServerId, (int)$file['file_size']);
                    }

                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    if ($copiedDestinationPayload) {
                        $this->cleanupDestinationArtifacts($destProvider, $primaryPath, $copiedDestinationVariants);
                    }
                    throw $e;
                }

                if ($sourceExists) {
                    try {
                        $this->cleanupSourceArtifactsAfterCommittedSwitchover($sourceProvider, $primaryPath, $variantPaths);
                        \App\Core\StorageManager::releaseUsageOrFail($db, $fromServerId, (int)$file['file_size']);
                    } catch (\Throwable $e) {
                        if ($sourceProvider->exists($primaryPath)) {
                            $rolledBack = $this->rollbackCommittedSwitchoverToSource(
                                $db,
                                (int)$file['id'],
                                $fromServerId,
                                $toServerId,
                                (string)$source['server_type'],
                                (int)$file['file_size'],
                                $sourceProvider,
                                $destProvider,
                                $primaryPath,
                                $copiedDestinationVariants
                            );
                            if ($rolledBack) {
                                throw new Exception('Source cleanup did not complete after migration commit; migration was rolled back to the source server.', 0, $e);
                            }
                        }

                        Logger::warning('Source cleanup did not complete after migration commit; destination pointer remains durable', [
                            'file_id' => (int)$file['id'],
                            'path' => $primaryPath,
                            'from_server_id' => $fromServerId,
                            'to_server_id' => $toServerId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $results['success']++;
            } catch (Exception $e) {
                Logger::error('File migration failed', ['file_id' => $file['id'], 'error' => $e->getMessage()]);
                $results['failed']++;
            } finally {
                if ($destCapacityLockHeld) {
                    StorageManager::releaseServerCapacityLock($db, $toServerId);
                }
            }
        }

        // Count Remaining
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM stored_files WHERE file_server_id = ?");
        $stmtCount->execute([$fromServerId]);
        $results['remaining'] = (int)$stmtCount->fetchColumn();

        return $results;
    }

    private function claimStoredFileSwitchover(\PDO $db, int $storedFileId, int $fromServerId, int $toServerId, string $destServerType): bool
    {
        $lockStmt = $db->prepare("SELECT file_server_id FROM stored_files WHERE id = ? LIMIT 1 FOR UPDATE");
        $lockStmt->execute([$storedFileId]);
        $currentServerId = $lockStmt->fetchColumn();
        if ($currentServerId === false || (int)$currentServerId !== $fromServerId) {
            return false;
        }

        $stmtUpdate = $db->prepare("
            UPDATE stored_files
            SET file_server_id = ?, storage_provider = ?
            WHERE id = ? AND file_server_id = ?
        ");
        $stmtUpdate->execute([$toServerId, $destServerType, $storedFileId, $fromServerId]);
        return $stmtUpdate->rowCount() === 1;
    }

    private function copyBetweenProviders(StorageProvider $sourceProvider, StorageProvider $destProvider, string $path, ?array $expectedHead = null): bool
    {
        $tmpPath = TemporaryArtifactService::createTempFile('fy_mig_');

        $handle = fopen($tmpPath, 'wb');
        if ($handle === false) {
            TemporaryArtifactService::cleanup($tmpPath);
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

        $saved = $destProvider->save($tmpPath, $path);
        TemporaryArtifactService::cleanup($tmpPath);

        if (!$saved) {
            return false;
        }

        $destHead = $destProvider->head($path);
        if ($destHead === null) {
            return false;
        }

        $expectedSize = (int)($expectedHead['content_length'] ?? 0);
        $actualSize = (int)($destHead['content_length'] ?? 0);

        if ($expectedSize > 0 && $actualSize > 0 && $expectedSize !== $actualSize) {
            Logger::error('Migrated payload size mismatch', [
                'path' => $path,
                'expected_size' => $expectedSize,
                'actual_size' => $actualSize,
            ]);
            return false;
        }

        return true;
    }

    private function buildVariantPaths(array $storedFile): array
    {
        $thumbnailPath = \App\Model\StoredFile::buildThumbnailVariantPathFromStoragePath((string)($storedFile['storage_path'] ?? ''));
        return $thumbnailPath !== null ? [$thumbnailPath] : [];
    }

    private function cleanupDestinationArtifacts(StorageProvider $destProvider, string $primaryPath, array $variantPaths): void
    {
        foreach ($variantPaths as $variantPath) {
            try {
                $destProvider->delete($variantPath);
            } catch (\Throwable $e) {
                Logger::warning('Destination migration variant cleanup failed after DB rollback', [
                    'path' => $variantPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $destProvider->delete($primaryPath);
        } catch (\Throwable $e) {
            Logger::warning('Destination migration payload cleanup failed after DB rollback', [
                'path' => $primaryPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cleanupSourceArtifactsAfterCommittedSwitchover(StorageProvider $sourceProvider, string $primaryPath, array $variantPaths): void
    {
        if ($variantPaths !== [] && !$sourceProvider->deleteVariants($primaryPath, $variantPaths)) {
            throw new Exception("Source variant cleanup did not complete after migration commit.");
        }

        if (!$sourceProvider->delete($primaryPath)) {
            throw new Exception("Source payload cleanup did not complete after migration commit.");
        }
    }

    private function rollbackCommittedSwitchoverToSource(
        \PDO $db,
        int $storedFileId,
        int $fromServerId,
        int $toServerId,
        string $sourceServerType,
        int $fileSize,
        StorageProvider $sourceProvider,
        StorageProvider $destProvider,
        string $primaryPath,
        array $variantPaths
    ): bool {
        try {
            $this->restoreSourceArtifactsAfterRollback($sourceProvider, $destProvider, $primaryPath, $variantPaths);

            $db->beginTransaction();
            if (!$this->claimStoredFileSwitchover($db, $storedFileId, $toServerId, $fromServerId, $sourceServerType)) {
                $db->rollBack();
                return false;
            }

            $db->commit();
            $destinationCleanupProven = false;
            try {
                $this->cleanupDestinationArtifactsOrFail($destProvider, $primaryPath, $variantPaths);
                $destinationCleanupProven = true;
            } catch (\Throwable $cleanupError) {
                Logger::warning('Destination cleanup after migration rollback could not be proven; usage remains reserved until a follow-up purge succeeds', [
                    'file_id' => $storedFileId,
                    'to_server_id' => $toServerId,
                    'path' => $primaryPath,
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            if ($destinationCleanupProven) {
                \App\Core\StorageManager::releaseUsageOrFail($db, $toServerId, $fileSize);
            }

            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::warning('Committed migration switchover rollback failed', [
                'file_id' => $storedFileId,
                'from_server_id' => $fromServerId,
                'to_server_id' => $toServerId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function restoreSourceArtifactsAfterRollback(StorageProvider $sourceProvider, StorageProvider $destProvider, string $primaryPath, array $variantPaths): void
    {
        try {
            $destHead = $destProvider->head($primaryPath);
            if (!$sourceProvider->exists($primaryPath) && $destHead !== null && !$this->copyBetweenProviders($destProvider, $sourceProvider, $primaryPath, $destHead)) {
                throw new Exception('Source payload restore did not complete after rollback.');
            }

            foreach ($variantPaths as $variantPath) {
                $variantHead = $destProvider->head($variantPath);
                if (!$sourceProvider->exists($variantPath) && $variantHead !== null && !$this->copyBetweenProviders($destProvider, $sourceProvider, $variantPath, $variantHead)) {
                    throw new Exception('Source variant restore did not complete after rollback.');
                }
            }

            if (!$sourceProvider->exists($primaryPath)) {
                throw new Exception('Source payload is missing after rollback restore.');
            }

            foreach ($variantPaths as $variantPath) {
                if (!$sourceProvider->exists($variantPath)) {
                    throw new Exception('Source variant is missing after rollback restore.');
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('Source migration rollback restore failed after cleanup had already started', [
                'path' => $primaryPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function cleanupDestinationArtifactsOrFail(StorageProvider $destProvider, string $primaryPath, array $variantPaths): void
    {
        foreach ($variantPaths as $variantPath) {
            if (!$destProvider->delete($variantPath)) {
                throw new Exception('Destination migration variant cleanup did not complete after rollback.');
            }
        }

        if (!$destProvider->delete($primaryPath)) {
            throw new Exception('Destination migration payload cleanup did not complete after rollback.');
        }
    }
}
