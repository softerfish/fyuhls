<?php

namespace App\Service;

use App\Core\StorageManager;
use App\Model\StoredFile;
use App\Model\File;
use App\Model\Setting;

class FileProcessor {
    private const DANGEROUS_UPLOAD_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jspx', 'shtml',
        'sh', 'bash', 'cmd', 'bat', 'ps1',
    ];
    private static $afterLocalArtifactsPreparedHandler = null;
    private static $mimeTypeDetectorForTests = null;

    // No longer injecting StorageProvider in constructor, as we select it dynamically

    public static function setAfterLocalArtifactsPreparedHandlerForTests(?callable $handler): void
    {
        self::$afterLocalArtifactsPreparedHandler = $handler;
    }

    public static function setMimeTypeDetectorForTests(?callable $handler): void
    {
        self::$mimeTypeDetectorForTests = $handler;
    }

    private static function fireAfterLocalArtifactsPreparedForTests(array $context = []): void
    {
        if (!is_callable(self::$afterLocalArtifactsPreparedHandler)) {
            return;
        }

        (self::$afterLocalArtifactsPreparedHandler)($context);
    }

    private static function detectMimeTypeForUpload(string $tempFilePath): string
    {
        if (is_callable(self::$mimeTypeDetectorForTests)) {
            return trim((string)(self::$mimeTypeDetectorForTests)($tempFilePath));
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = $finfo ? (string)@finfo_file($finfo, $tempFilePath) : '';
            if ($finfo) {
                @finfo_close($finfo);
            }
            return $mimeType;
        }

        if (function_exists('mime_content_type')) {
            return (string)\mime_content_type($tempFilePath);
        }

        return '';
    }

    private function cleanupPhysicalUploadArtifacts(object $storage, ?string $relativePath, ?string $thumbRelativePath = null): void
    {
        if (is_string($thumbRelativePath) && $thumbRelativePath !== '') {
            try {
                $storage->delete($thumbRelativePath);
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('Failed to remove thumbnail artifact during upload rollback', [
                    'path' => $thumbRelativePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (is_string($relativePath) && $relativePath !== '') {
            try {
                $storage->delete($relativePath);
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('Failed to remove uploaded object during upload rollback', [
                    'path' => $relativePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function cleanupLocalTemporaryArtifact(?string $path, string $label): void
    {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return;
        }

        if (@unlink($path)) {
            return;
        }

        \App\Core\Logger::warning('Temporary upload artifact could not be removed during cleanup', [
            'path' => $path,
            'label' => $label,
        ]);
    }

    private function deleteDeduplicatedUploadArtifactsOrFail(object $storage, string $providerKey, string $hash, string $relativePath, ?string $thumbRelativePath, int $storedFileId): void
    {
        $deletedDuplicateThumb = true;
        if (is_string($thumbRelativePath) && $thumbRelativePath !== '') {
            $deletedDuplicateThumb = false;
            try {
                if (!$storage->delete($thumbRelativePath)) {
                    throw new \RuntimeException('Classic upload duplicate thumbnail cleanup did not complete.');
                }
                $deletedDuplicateThumb = true;
            } catch (\Throwable $e) {
                throw new \RuntimeException('Classic upload duplicate thumbnail cleanup did not complete.', 0, $e);
            }
        }

        try {
            if (!$deletedDuplicateThumb || !$storage->delete($relativePath)) {
                throw new \RuntimeException('Classic upload duplicate object cleanup did not complete.');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Classic upload duplicate object cleanup did not complete.', 0, $e);
        }

        \App\Core\Logger::info('Classic upload duplicate object removed after canonical dedupe match', [
            'hash' => $hash,
            'provider' => $providerKey,
            'path' => $relativePath,
            'stored_file_id' => $storedFileId,
        ]);
    }

    private function rollbackCommittedDeduplicatedUploadOrFail(
        \PDO $db,
        int $fileId,
        int $storedFileId,
        int $fileSize,
        ?int $userId,
        object $storage,
        ?string $relativePath,
        ?string $thumbRelativePath,
        \Throwable $cleanupError
    ): void {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Classic upload rollback could not delete the committed logical file row.');
            }

            StoredFile::decrementRefCount($storedFileId);

            if ($userId !== null && $userId > 0) {
                $db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) - ?), storage_warning_sent = 0 WHERE id = ?")
                    ->execute([$fileSize, $userId]);
            }

            $db->commit();
        } catch (\Throwable $rollbackError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            \App\Core\Logger::error('Classic upload dedupe rollback failed after duplicate cleanup could not be proven', [
                'file_id' => $fileId,
                'stored_file_id' => $storedFileId,
                'path' => $relativePath,
                'error' => $rollbackError->getMessage(),
                'cleanup_error' => $cleanupError->getMessage(),
            ]);

            throw new \RuntimeException('Classic upload duplicate cleanup failed after commit and the logical upload could not be rolled back safely.', 0, $cleanupError);
        }

        $this->cleanupPhysicalUploadArtifacts($storage, $relativePath, $thumbRelativePath);

        throw $cleanupError;
    }

    private function parseAllowedExtensions(string $allowedSetting): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($allowedSetting)))));
    }

    private function isDangerousUploadExtension(string $extension): bool
    {
        $extension = strtolower(trim($extension));
        return $extension !== '' && in_array($extension, self::DANGEROUS_UPLOAD_EXTENSIONS, true);
    }

    private function assertAllowedExtensionForPackage(string $filename, array $package): void
    {
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $globalAllowed = $this->parseAllowedExtensions(\App\Model\Setting::get('upload_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4,mp3,ipa,apk'));

        if ($ext === '' || $this->isDangerousUploadExtension($ext) || !in_array($ext, $globalAllowed, true)) {
            $allowedStr = implode(', ', $globalAllowed);
            throw new \Exception("Security Error: file type (.$ext) is not allowed. Allowed extensions are: [$allowedStr]. Check your Settings.");
        }

        $packageSetting = trim((string)($package['accepted_file_types'] ?? ''));
        if ($packageSetting === '') {
            return;
        }

        $packageAllowed = $this->parseAllowedExtensions($packageSetting);
        if ($packageAllowed === []) {
            throw new \Exception('This package does not currently allow any upload file types.');
        }

        if (!in_array($ext, $packageAllowed, true)) {
            $allowedStr = implode(', ', $packageAllowed);
            throw new \Exception("Security Error: file type (.$ext) is not allowed for this package. Allowed package extensions are: [$allowedStr].");
        }
    }

    private function acquireUserStorageQuotaLock(\PDO $db, int $userId): bool
    {
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseUserStorageQuotaLock(\PDO $db, int $userId): void
    {
        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * process a complete upload (after chunk assembly)
     *
     * @throws \Exception
     */
    public function processUpload(string $tempFilePath, string $originalFilename, int $userId, int|string|null $folderId = null, ?int $quotaReservationId = null): array {
        StoredFile::ensureSchemaCompatibility();
        $fileSize = filesize($tempFilePath);
        \App\Core\Logger::info("Processing complete upload", ['file' => $originalFilename, 'size' => $fileSize, 'user' => $userId]);

        $db = \App\Core\Database::getInstance()->getConnection();
        $storageQuotaLockHeld = false;
        $fileServerCapacityLockHeld = false;
        $fileServerId = null;
        $thumbnailTempPath = null;
        try {

            // Resolve folderId if it's a slug
            if ($folderId && !is_numeric($folderId)) {
                $folder = \App\Model\Folder::find($folderId);
                $folderId = $folder ? (int)$folder['id'] : null;
            } else {
                $folderId = $folderId ? (int)$folderId : null;
            }

            if ($folderId !== null) {
                $folder = \App\Model\Folder::find($folderId);
                if (
                    !$folder
                    || (int)($folder['user_id'] ?? 0) !== $userId
                    || ($folder['status'] ?? 'active') !== 'active'
                ) {
                    $folderId = null;
                } else {
                    $folderId = (int)$folder['id'];
                }
            }

        // enforce max upload size based on user package
        $package = $userId ? \App\Model\Package::getUserPackage($userId) : \App\Model\Package::getGuestPackage();
        $this->assertAllowedExtensionForPackage($originalFilename, $package ?: []);
        $maxSize = $package['max_upload_size'] > 0 ? (int)$package['max_upload_size'] : PHP_INT_MAX;

        if (filesize($tempFilePath) > $maxSize) {
            unlink($tempFilePath);
            throw new \Exception("file is too big for your account's package limit.");
        }

        if ($userId && !empty($package['max_storage_bytes']) && (float)$package['max_storage_bytes'] > 0) {
            if (!$this->acquireUserStorageQuotaLock($db, $userId)) {
                unlink($tempFilePath);
                throw new \Exception("Upload could not be started safely right now. Please try again.");
            }
            $storageQuotaLockHeld = true;
            $stmt = $db->prepare("SELECT storage_used FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $storageUsed = (float)$stmt->fetchColumn();
            $activeReserved = (float)\App\Model\QuotaReservation::activeReservedBytesForUser($userId);
            if ($quotaReservationId !== null && $quotaReservationId > 0) {
                $reservation = \App\Model\QuotaReservation::findActiveById($quotaReservationId);
                if ($reservation && (int)($reservation['user_id'] ?? 0) === $userId) {
                    $activeReserved = max(0.0, $activeReserved - (float)($reservation['reserved_bytes'] ?? 0));
                }
            }
            $maxStorage = (float)$package['max_storage_bytes'];

            if (($storageUsed + $activeReserved + $fileSize) > $maxStorage) {
                unlink($tempFilePath);
                throw new \Exception("Storage quota exceeded for your account package.");
            }
        }

        // calculate hash (deduplication check)
        $hash = hash_file('sha256', $tempFilePath);
        $fileSize = filesize($tempFilePath);
        \App\Core\Logger::info("Processing upload", ['filename' => $originalFilename, 'tmp' => $tempFilePath, 'size' => $fileSize]);

        $mimeType = self::detectMimeTypeForUpload($tempFilePath);

        if ($mimeType === '' || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType)) {
            \App\Core\Logger::error('Upload rejected because MIME detection is unavailable or invalid.', [
                'filename' => $originalFilename,
                'path' => $tempFilePath,
            ]);
            unlink($tempFilePath);
            throw new \Exception('Upload validation is unavailable on this server because MIME detection is not configured correctly.');
        }

        $deduplicationService = new DeduplicationService();

        // check if file already exists globally (this keeps things clean in the db)
        $existingStoredFile = null;
        $dedupeEnabled = $deduplicationService->enabled();
        $dedupeHashLockHeld = false;
        $storage = null;
            $storedFileId = 0;
            $thumbCreated = false;
            $thumbRelative = null;
            $relativePath = null;
        $needsStoredFileRefIncrement = false;
        $needsStoredFileCreate = false;
        $needsUsageRecord = false;
        $deduplicated = false;
        $ownsUploadedObjectUntilCommit = false;
        $postCommitCleanupRelativePath = null;
        $postCommitCleanupThumbRelativePath = null;
        $postCommitCleanupStoredFileId = 0;

        if ($dedupeEnabled) {
            if (!$deduplicationService->acquireHashLock($db, $hash, 10)) {
                if (file_exists($tempFilePath)) {
                    unlink($tempFilePath);
                }
                throw new \Exception("Upload could not be started safely right now. Please try again.");
            }
            $dedupeHashLockHeld = true;
        }

        if ($dedupeEnabled) {
            $existingStoredFile = $deduplicationService->findReusableStoredFile($hash, $fileSize, null, true);
            if ($existingStoredFile) {
                \App\Core\Logger::info("Deduplication hit (Hash: $hash, Size: $fileSize)");
            }
        }

        // pick the right storage server from the file_servers table
        [$providerKey, $storage, $fileServerId] = StorageManager::resolveFromDb($db, $fileSize, true);
        $providerName = $storage->getName();
        \App\Core\Logger::info("Resolved storage provider", ['provider' => $providerKey, 'server_id' => $fileServerId]);

        // generate a secure path: yyyy/mm/hash.ext
        // security: always lowercase the stored extension so 'evil.PHP' can't sneak past
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $relativePath = $this->buildStoredObjectRelativePath($hash, $ext, $dedupeEnabled);

        // thumbnails (images/videos)
        $thumbRelative = StoredFile::buildThumbnailVariantPathFromStoragePath($relativePath);
        \App\Core\Logger::info("Deduplication check", ['hash' => $hash, 'size' => $fileSize]);
        if ($existingStoredFile) {
            // Fix: we must check existence on the original provider, not the current default!
            $originalProvider = StorageManager::getProviderById($existingStoredFile['file_server_id'], $db);

            // verify the file actually exists on the storage provider
            // if it doesn't, we treat it as a collision and force a re-upload to repair the record.
            if ($originalProvider->exists($existingStoredFile['storage_path'])) {
                \App\Core\Logger::info("Deduplication hit (Storage Verified)", ['hash' => $hash, 'existing_id' => $existingStoredFile['id'], 'provider' => $existingStoredFile['storage_provider']]);
                // just link to existing physical file
                $storedFileId = $existingStoredFile['id'];
                $needsStoredFileRefIncrement = true;
                $deduplicated = true;

                // delete temp file as we don't need it
                if (file_exists($tempFilePath)) {
                    unlink($tempFilePath);
                }
            } else {
                \App\Core\Logger::warning("Deduplication hit in DB, but file MISSING from storage. Creating a fresh stored file row instead of rewriting the old shared record.", ['hash' => $hash, 'path' => $existingStoredFile['storage_path']]);
                $existingStoredFile = null;
            }
        }

            if (!$existingStoredFile) {
                \App\Core\Logger::info("Deduplication miss or repair needed, saving to storage", ['hash' => $hash, 'size' => $fileSize, 'provider' => $providerKey]);

            if (!empty($fileServerId)) {
                if (!StorageManager::acquireServerCapacityLock($db, (int)$fileServerId, 10)) {
                    if (file_exists($tempFilePath)) {
                        unlink($tempFilePath);
                    }
                    throw new \Exception("Upload could not be started safely right now. Please try again.");
                }
                $fileServerCapacityLockHeld = true;
                StorageManager::assertServerHasCapacity($db, (int)$fileServerId, $fileSize, true);
            }

            if (str_starts_with($mimeType, 'image/')) {
                $thumbnailTempPath = TemporaryArtifactService::createTempPath('fy_thumb_', '.jpg');
                $thumbCreated = $this->createImageThumbnail($tempFilePath, $thumbnailTempPath);
            } elseif (str_starts_with($mimeType, 'video/')) {
                // check DB setting first, then config file fallback
                $ffmpegEnabled = Setting::getOrConfig('video.ffmpeg_enabled', '1');
                $ffmpeg = Setting::getOrConfig('video.ffmpeg_path', '');
                if ($ffmpegEnabled === '1' && !empty($ffmpeg)) {
                    $thumbnailTempPath = TemporaryArtifactService::createTempPath('fy_thumb_', '.jpg');
                    $thumbCreated = $this->createVideoThumbnail($tempFilePath, $thumbnailTempPath, $ffmpeg);
                }
            }

            self::fireAfterLocalArtifactsPreparedForTests([
                'temp_file_path' => $tempFilePath,
                'thumbnail_temp_path' => $thumbnailTempPath,
                'thumbnail_created' => $thumbCreated,
                'mime_type' => $mimeType,
                'original_filename' => $originalFilename,
                'user_id' => $userId,
            ]);

            if (!$storage->save($tempFilePath, $relativePath)) {
                throw new \Exception("failed to save file to storage ($providerName).");
            }
            \App\Core\Logger::info("File saved to storage provider", ['provider' => $providerKey, 'path' => $relativePath]);
            $ownsUploadedObjectUntilCommit = true;

            // save thumbnail if we got one
            if ($thumbCreated && $thumbRelative !== null) {
                $tmpThumb = $thumbnailTempPath;
                $storage->save((string)$tmpThumb, $thumbRelative);
                \App\Core\Logger::info("Thumbnail saved to storage provider", ['provider' => $providerKey, 'path' => $thumbRelative]);
                $this->cleanupLocalTemporaryArtifact($tmpThumb, 'thumbnail');
                $thumbnailTempPath = null;
            }

            $canonicalStoredFile = $dedupeEnabled
                ? $deduplicationService->findReusableStoredFile($hash, $fileSize, null, false)
                : null;
            if ($canonicalStoredFile) {
                $samePhysicalObject =
                    (int)($canonicalStoredFile['file_server_id'] ?? 0) === (int)($fileServerId ?? 0)
                    && (string)($canonicalStoredFile['storage_path'] ?? '') === $relativePath;

                if ($samePhysicalObject) {
                    \App\Core\Logger::info("Classic upload dedupe matched the existing canonical object on the same storage path", [
                        'hash' => $hash,
                        'provider' => $providerKey,
                        'path' => $relativePath,
                        'stored_file_id' => (int)$canonicalStoredFile['id'],
                    ]);
                    $storedFileId = (int)$canonicalStoredFile['id'];
                    $needsStoredFileRefIncrement = true;
                    $deduplicated = true;
                    $ownsUploadedObjectUntilCommit = false;
                } else {
                    $storedFileId = (int)$canonicalStoredFile['id'];
                    $needsStoredFileRefIncrement = true;
                    $deduplicated = true;
                    $postCommitCleanupRelativePath = $relativePath;
                    $postCommitCleanupThumbRelativePath = $thumbCreated ? $thumbRelative : null;
                    $postCommitCleanupStoredFileId = $storedFileId;
                }
            } elseif ($dedupeEnabled) {
                \App\Core\Logger::warning("Deduplication fallback refused to rewrite an existing stored file row in place after canonical lookup miss.", [
                    'hash' => $hash,
                    'size' => $fileSize,
                    'provider' => $providerKey,
                    'path' => $relativePath,
                ]);
            }

            // bump per-server usage stats
            if (!$storedFileId) {
                $needsStoredFileCreate = true;
            }
            $needsUsageRecord = !empty($fileServerId) && !$deduplicated;

            // cleanup temp file
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }

        // delete_at is not set on upload - expiry is based on last download, not upload time
        $deleteAt = null;

        // Get User Privacy Preference
        $isPublic = 1;
        if ($userId) {
            $db = \App\Core\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT default_privacy FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $pref = $stmt->fetchColumn();
            $isPublic = ($pref === 'private') ? 0 : 1;
        }

        $fileId = 0;
        $db->beginTransaction();
        try {
            if ($needsStoredFileRefIncrement && $storedFileId > 0) {
                StoredFile::incrementRefCount($storedFileId);
                StoredFile::update($storedFileId, [
                    'file_hash' => $hash,
                    'checksum_verified_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if ($needsStoredFileCreate) {
                $storedFileId = StoredFile::create($hash, $providerKey, $relativePath, $fileSize, $mimeType, $fileServerId ?? null);
            }

            if ($needsUsageRecord && $fileServerId) {
                StorageManager::recordUsageOrFail($db, (int)$fileServerId, $fileSize);
            }

            $fileId = File::create($userId, $storedFileId, $originalFilename, $folderId, $deleteAt, $isPublic);

            if ($userId) {
                $db->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?")->execute([$fileSize, $userId]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            if ($ownsUploadedObjectUntilCommit && $storage !== null) {
                $this->cleanupPhysicalUploadArtifacts($storage, $relativePath, $thumbCreated ? $thumbRelative : null);
            }

            $this->cleanupLocalTemporaryArtifact($thumbnailTempPath, 'thumbnail');
            $thumbnailTempPath = null;

            throw $e;
        }

        if ($postCommitCleanupRelativePath !== null) {
            try {
                $this->deleteDeduplicatedUploadArtifactsOrFail(
                    $storage,
                    $providerKey,
                    $hash,
                    $postCommitCleanupRelativePath,
                    $postCommitCleanupThumbRelativePath,
                    $postCommitCleanupStoredFileId
                );
            } catch (\Throwable $cleanupError) {
                $this->rollbackCommittedDeduplicatedUploadOrFail(
                    $db,
                    $fileId,
                    $postCommitCleanupStoredFileId,
                    $fileSize,
                    $userId,
                    $storage,
                    $postCommitCleanupRelativePath,
                    $postCommitCleanupThumbRelativePath,
                    $cleanupError
                );
            }
        }

        \App\Core\Auth::logActivity('upload', "Uploaded file: " . $originalFilename . " (ID: " . $fileId . ")");

        if ($userId) {
            $this->checkStorageQuotaWarning($userId);
            \App\Service\BonusOfferService::touchUserFailSoft($userId, true, [
                'workflow' => 'classic_upload',
                'file_id' => $fileId,
                'filename' => $originalFilename,
            ]);
        }

            return [
                'file_id' => $fileId,
                'status' => 'success',
                'deduplicated' => $deduplicated
            ];
        } finally {
            $this->cleanupLocalTemporaryArtifact($thumbnailTempPath, 'thumbnail');
            $this->cleanupLocalTemporaryArtifact($tempFilePath, 'upload');
            if (!empty($dedupeHashLockHeld)) {
                $deduplicationService->releaseHashLock($db, $hash);
            }
            if ($storageQuotaLockHeld) {
                $this->releaseUserStorageQuotaLock($db, $userId);
            }
            if ($fileServerCapacityLockHeld && !empty($fileServerId)) {
                StorageManager::releaseServerCapacityLock($db, (int)$fileServerId);
            }
        }
    }

    private function checkStorageQuotaWarning(int $userId): void {
        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT username, email, storage_used, storage_warning_threshold, storage_warning_sent, package_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || $user['storage_warning_sent']) return;

        $package = \App\Model\Package::find($user['package_id']);
        $maxStorage = $package ? (float)$package['max_storage_bytes'] : 0;

        if ($maxStorage <= 0) return;

        $threshold = (int)$user['storage_warning_threshold'];
        $usagePercent = ($user['storage_used'] / $maxStorage) * 100;

        if ($usagePercent >= $threshold) {
            $email = \App\Service\EncryptionService::decrypt($user['email']);
            $username = \App\Service\EncryptionService::decrypt($user['username']);

            $sent = \App\Service\MailService::sendTemplate($email, 'storage_limit_warning', [
                '{username}' => $username,
                '{usage_percent}' => round($usagePercent, 1),
                '{threshold}' => $threshold,
                '{max_storage}' => self::formatSize($maxStorage)
            ]);

            if ($sent) {
                $db->prepare("UPDATE users SET storage_warning_sent = 1 WHERE id = ?")->execute([$userId]);
            }
        }
    }

    private function createImageThumbnail(string $source, string $dest): bool {
        $dims = \App\Core\Config::get('thumbnail', ['max_width' => 320, 'max_height' => 240, 'quality' => 80]);
        $info = @getimagesize($source);
        if (!$info) return false;
        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';
        $ratio = min($dims['max_width'] / $width, $dims['max_height'] / $height, 1);
        $newW = (int)floor($width * $ratio);
        $newH = (int)floor($height * $ratio);
        if (!function_exists('imagecreatetruecolor')) return false;
        $dst = imagecreatetruecolor($newW, $newH);
        switch ($mime) {
            case 'image/jpeg':
                if (!function_exists('imagecreatefromjpeg')) return false;
                $src = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                if (!function_exists('imagecreatefrompng')) return false;
                $src = @imagecreatefrompng($source);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                break;
            case 'image/gif':
                if (!function_exists('imagecreatefromgif')) return false;
                $src = @imagecreatefromgif($source);
                break;
            default:
                return false;
        }
        if (!$src) {
            imagedestroy($dst);
            return false;
        }
        if (!function_exists('imagecopyresampled') || !function_exists('imagejpeg')) return false;
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        $ok = imagejpeg($dst, $dest, $dims['quality']);
        imagedestroy($dst);
        imagedestroy($src);
        return $ok;
    }

    private function createVideoThumbnail(string $source, string $dest, string $ffmpegPath): bool {
        $dims = \App\Core\Config::get('thumbnail', ['max_width' => 320, 'max_height' => 240]);
        $ffmpegPath = trim($ffmpegPath);
        $resolvedFfmpegPath = $ffmpegPath !== '' ? realpath($ffmpegPath) : false;
        if ($resolvedFfmpegPath === false || !is_file($resolvedFfmpegPath) || !preg_match('/^ffmpeg(?:\.exe)?$/i', basename($resolvedFfmpegPath))) {
            return false;
        }
        $scale = $dims['max_width'] . ':-1';
        $cmd = escapeshellarg($resolvedFfmpegPath) . " -y -ss 00:00:01 -i " . escapeshellarg($source) . " -frames:v 1 -vf scale=" . $scale . " " . escapeshellarg($dest);
        $result = @shell_exec($cmd);
        return file_exists($dest);
    }

    private function buildStoredObjectRelativePath(string $hash, string $extension, bool $dedupeEnabled): string
    {
        $prefix = date('Y/m') . '/';
        $suffix = $extension !== '' ? '.' . $extension : '';

        if ($dedupeEnabled) {
            return $prefix . $hash . $suffix;
        }

        return $prefix . $hash . '-' . bin2hex(random_bytes(8)) . $suffix;
    }

    public static function formatSize($bytes, $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
