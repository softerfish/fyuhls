<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Core\StorageManager;
use App\Model\File;
use App\Model\Setting;
use App\Model\StoredFile;

class DeduplicationService
{
    public function acquireHashLock(\PDO $db, ?string $checksumSha256, int $timeoutSeconds = 10): bool
    {
        $checksumSha256 = $this->normalizeChecksum($checksumSha256);
        if ($checksumSha256 === null) {
            return false;
        }

        $stmt = $db->prepare("SELECT GET_LOCK(?, ?)");
        $stmt->execute([$this->buildHashLockKey($checksumSha256), max(1, $timeoutSeconds)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public function releaseHashLock(\PDO $db, ?string $checksumSha256): void
    {
        $checksumSha256 = $this->normalizeChecksum($checksumSha256);
        if ($checksumSha256 === null) {
            return;
        }

        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$this->buildHashLockKey($checksumSha256)]);
        } catch (\Throwable $e) {
        }
    }

    public function enabled(): bool
    {
        return Setting::get('upload_detect_duplicates', '1') === '1';
    }

    public function findReusableStoredFile(
        ?string $checksumSha256,
        int $expectedSize,
        ?int $excludeStoredFileId = null,
        bool $requireEnabled = false
    ): ?array {
        if ($expectedSize <= 0) {
            return null;
        }

        if ($requireEnabled && !$this->enabled()) {
            return null;
        }

        $checksumSha256 = $this->normalizeChecksum($checksumSha256);

        $candidates = [];

        if ($checksumSha256 !== null) {
            foreach (StoredFile::findAlternativesByHashAndSize($checksumSha256, $expectedSize, $excludeStoredFileId, 10) as $candidate) {
                $candidates[(int)$candidate['id']] = $candidate;
            }

            if ($candidates === []) {
                $completedUploadCandidate = StoredFile::findByCompletedUploadChecksumAndSize($checksumSha256, $expectedSize);
                if ($completedUploadCandidate) {
                    $candidates[(int)$completedUploadCandidate['id']] = $completedUploadCandidate;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($excludeStoredFileId !== null && (int)$candidate['id'] === $excludeStoredFileId) {
                continue;
            }

            if ($this->isReusableCandidate($candidate, $expectedSize)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isReusableCandidate(array $candidate, int $expectedSize): bool
    {
        $storedFileId = (int)($candidate['id'] ?? 0);
        $storagePath = trim((string)($candidate['storage_path'] ?? ''));
        $fileSize = (int)($candidate['file_size'] ?? 0);

        if ($storedFileId <= 0 || $storagePath === '' || $fileSize <= 0 || $fileSize !== $expectedSize) {
            return false;
        }

        $db = Database::getInstance()->getConnection();

        if (!$this->hasReusableLogicalReference($db, $storedFileId)) {
            Logger::warning('Dedup candidate rejected because no eligible live file references remain', [
                'stored_file_id' => $storedFileId,
            ]);
            return false;
        }

        try {
            $provider = StorageManager::getProviderById(
                !empty($candidate['file_server_id']) ? (int)$candidate['file_server_id'] : null,
                $db
            );
        } catch (\Throwable $e) {
            Logger::warning('Dedup candidate rejected because its storage provider could not be resolved', [
                'stored_file_id' => $storedFileId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        try {
            $head = $provider->head($storagePath);
        } catch (\Throwable $e) {
            Logger::warning('Dedup candidate rejected because provider metadata lookup failed', [
                'stored_file_id' => $storedFileId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($head === null) {
            Logger::warning('Dedup candidate rejected because provider metadata was missing', [
                'stored_file_id' => $storedFileId,
                'storage_path' => $storagePath,
            ]);
            return false;
        }

        $hasUsableContentLength = array_key_exists('content_length', $head) && (int)$head['content_length'] > 0;
        if ($hasUsableContentLength) {
            $contentLength = (int)$head['content_length'];
            if ($contentLength !== $expectedSize) {
                Logger::warning('Dedup candidate rejected because provider metadata size mismatched', [
                    'stored_file_id' => $storedFileId,
                    'expected_size' => $expectedSize,
                    'provider_size' => $contentLength,
                ]);
                return false;
            }
            return true;
        }

        try {
            if (!$provider->exists($storagePath)) {
                Logger::warning('Dedup candidate rejected because the stored object is missing after metadata fallback', [
                    'stored_file_id' => $storedFileId,
                    'storage_path' => $storagePath,
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            Logger::warning('Dedup candidate rejected because existence fallback failed after incomplete metadata', [
                'stored_file_id' => $storedFileId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    private function hasReusableLogicalReference(\PDO $db, int $storedFileId): bool
    {
        if ($storedFileId <= 0) {
            return false;
        }

        $eligibleStatuses = File::storageConsumingStatuses();
        if ($eligibleStatuses === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($eligibleStatuses), '?'));
        $stmt = $db->prepare("
            SELECT 1
            FROM files
            WHERE stored_file_id = ?
              AND status IN ($placeholders)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$storedFileId], $eligibleStatuses));

        return (bool)$stmt->fetchColumn();
    }

    private function normalizeChecksum(?string $checksum): ?string
    {
        $checksum = strtolower(trim((string)$checksum));
        if ($checksum === '' || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            return null;
        }

        return $checksum;
    }

    private function buildHashLockKey(string $checksumSha256): string
    {
        return 'fyuhls_dedupe_hash_' . hash('sha256', $checksumSha256);
    }
}
