<?php

namespace App\Service;

use App\Core\Database;
use App\Core\StorageManager;
use App\Model\File;
use App\Model\Folder;
use App\Model\Package;
use App\Model\QuotaReservation;
use App\Model\Setting;
use App\Model\StoredFile;
use App\Model\UploadSession;
use App\Model\User;
use Exception;

class MultipartUploadService
{
    private static bool $schemaReady = false;
    private const SESSION_TTL_SECONDS = 7200;

    public function __construct()
    {
        $this->ensureSchema();
    }

    public function createSession(?int $userId, string $filename, int $expectedSize, ?int $folderId = null, ?string $mimeHint = null, ?string $guestSessionId = null, ?string $checksumSha256 = null): array
    {
        $filename = trim($filename);
        if ($filename === '') {
            throw new Exception('A filename is required.');
        }

        if ($expectedSize <= 0) {
            throw new Exception('Upload size must be greater than zero.');
        }

        $this->assertAllowedExtension($filename);

        if ($userId !== null) {
            $user = User::find($userId);
            if (!$user) {
                throw new Exception('User not found.');
            }
            $package = Package::getUserPackage($userId);
        } else {
            $package = Package::getGuestPackage();
        }
        if (!$package) {
            throw new Exception('Upload package not found.');
        }

        $maxConcurrent = max(1, (int)($package['concurrent_uploads'] ?? 1));
        $activeCount = $userId !== null
            ? UploadSession::countActiveForUser($userId)
            : ($guestSessionId ? UploadSession::countActiveForGuestSession($guestSessionId) : 0);
        if ($activeCount >= $maxConcurrent) {
            throw new Exception('You already have the maximum number of active uploads for your package.');
        }

        if ((int)$package['max_upload_size'] > 0 && $expectedSize > (int)$package['max_upload_size']) {
            throw new Exception('File exceeds your package upload limit.');
        }

        if ($folderId !== null) {
            if ($userId === null) {
                throw new Exception('Guests cannot upload into private folders.');
            }
            $folder = Folder::find($folderId);
            if (!$folder || (int)$folder['user_id'] !== $userId) {
                throw new Exception('Folder not found.');
            }
        }

        $checksumSha256 = $this->normalizeChecksum($checksumSha256);
        if ($checksumSha256 !== null) {
            $existing = $this->findDeduplicationCandidate($checksumSha256, $expectedSize);
            if ($existing) {
                return $this->createImmediateDuplicateSession(
                    $existing,
                    $userId,
                    $filename,
                    $expectedSize,
                    $folderId,
                    $guestSessionId,
                    $checksumSha256
                );
            }
        }

        $db = Database::getInstance()->getConnection();
        [$providerKey, $provider, $fileServerId] = StorageManager::resolveFromDb($db);
        $storageProvider = explode('_', $providerKey, 2)[0];
        $capabilities = $provider->getCapabilities();

        if (empty($capabilities['multipart']) || (empty($capabilities['presigned_part_upload']) && empty($capabilities['app_part_upload']))) {
            throw new Exception('The selected storage backend does not support direct multipart uploads yet.');
        }

        $this->assertQuotaAvailable($userId, $expectedSize, $package, $fileServerId);

        $partSizeBytes = $this->resolvePartSize($expectedSize);
        if (!empty($capabilities['app_part_upload'])) {
            $partSizeBytes = min($partSizeBytes, $this->resolveAppUploadPartSizeLimit());
        }
        $objectKey = $this->buildObjectKey($userId, $filename);
        $uploadInit = $provider->createMultipartUpload($objectKey, [
            'ContentType' => $mimeHint ?: 'application/octet-stream',
        ]);

        if (!$uploadInit || empty($uploadInit['upload_id'])) {
            throw new Exception('Could not open multipart upload.');
        }

        $publicId = $this->newPublicId('us_');
        $expiresAt = $this->nextExpiry();
        $metadata = json_encode([
            'provider_key' => $providerKey,
            'original_extension' => strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)),
            'api_version' => 'v1',
            'guest_session_id' => $guestSessionId,
        ], JSON_UNESCAPED_SLASHES);

        $db->beginTransaction();
        try {
            $sessionId = UploadSession::create([
                'public_id' => $publicId,
                'user_id' => $userId,
                'guest_session_id' => $guestSessionId,
                'folder_id' => $folderId,
                'storage_server_id' => $fileServerId,
                'storage_provider' => $storageProvider,
                'original_filename' => $filename,
                'object_key' => $objectKey,
                'expected_size' => $expectedSize,
                'mime_hint' => $mimeHint,
                'multipart_upload_id' => $uploadInit['upload_id'],
                'status' => 'uploading',
                'reserved_bytes' => $expectedSize,
                'part_size_bytes' => $partSizeBytes,
                'metadata_json' => $metadata,
                'expires_at' => $expiresAt,
            ]);

            QuotaReservation::create([
                'public_id' => $this->newPublicId('qr_'),
                'user_id' => $userId,
                'upload_session_id' => $sessionId,
                'storage_server_id' => $fileServerId,
                'reserved_bytes' => $expectedSize,
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $provider->abortMultipartUpload($objectKey, $uploadInit['upload_id']);
            throw $e;
        }

        $session = UploadSession::findByPublicId($publicId);

        return [
            'session' => $session,
            'part_size_bytes' => $partSizeBytes,
            'expires_at' => $expiresAt,
            'capabilities' => $capabilities,
            'upload_skipped' => false,
        ];
    }

    public function getSessionForActor(string $publicId, ?int $userId, ?string $guestSessionId = null): ?array
    {
        $session = UploadSession::findByPublicId($publicId);
        if (!$session) {
            return null;
        }

        if ($userId !== null) {
            if ((int)$session['user_id'] !== $userId) {
                return null;
            }
        } elseif (($session['guest_session_id'] ?? null) !== $guestSessionId) {
            return null;
        }

        $this->refreshSessionLease((int)$session['id']);
        $session['parts'] = UploadSession::getParts((int)$session['id']);
        return $session;
    }

    public function signParts(array $session, array $partNumbers, int $expiry = 3600): array
    {
        $db = Database::getInstance()->getConnection();
        $provider = StorageManager::getProviderById($session['storage_server_id'] ? (int)$session['storage_server_id'] : null, $db);
        $this->refreshSessionLease((int)$session['id']);
        $urls = [];

        foreach ($partNumbers as $partNumber) {
            $partNumber = (int)$partNumber;
            if ($partNumber <= 0 || $partNumber > 10000) {
                continue;
            }

            if (!empty($provider->getCapabilities()['app_part_upload'])) {
                $url = '/api/v1/uploads/sessions/' . rawurlencode((string)$session['public_id']) . '/parts/upload/' . $partNumber;
            } else {
                $url = $provider->createMultipartPartUrl($session['object_key'], (string)$session['multipart_upload_id'], $partNumber, $expiry);
            }
            if (!$url) {
                continue;
            }

            UploadSession::upsertPart((int)$session['id'], $partNumber, null, 0, 'signed');
            $urls[] = [
                'part_number' => $partNumber,
                'url' => $url,
                'expires_in' => $expiry,
            ];
        }

        return $urls;
    }

    public function reportPart(array $session, int $partNumber, string $etag, int $partSize, ?string $checksum = null): array
    {
        if ($partNumber <= 0) {
            throw new Exception('Invalid part number.');
        }

        $this->refreshSessionLease((int)$session['id']);

        UploadSession::upsertPart((int)$session['id'], $partNumber, trim($etag, '"'), max(0, $partSize), 'uploaded', $checksum);

        $parts = UploadSession::getParts((int)$session['id']);
        $uploadedBytes = 0;
        $uploadedCount = 0;
        foreach ($parts as $part) {
            if ($part['status'] === 'uploaded' || $part['status'] === 'verified') {
                $uploadedBytes += (int)$part['part_size'];
                $uploadedCount++;
            }
        }

        UploadSession::update((int)$session['id'], [
            'uploaded_bytes' => $uploadedBytes,
            'completed_parts' => $uploadedCount,
            'status' => 'uploading',
        ]);

        $fresh = UploadSession::findByPublicId($session['public_id']);
        $fresh['parts'] = $parts;
        return $fresh;
    }

    public function complete(array $session, ?string $checksumSha256 = null): array
    {
        $db = Database::getInstance()->getConnection();
        $provider = StorageManager::getProviderById($session['storage_server_id'] ? (int)$session['storage_server_id'] : null, $db);
        $this->refreshSessionLease((int)$session['id']);
        $parts = array_values(array_filter(
            UploadSession::getParts((int)$session['id']),
            static fn(array $part): bool => in_array($part['status'], ['uploaded', 'verified'], true) && !empty($part['etag'])
        ));

        if (empty($parts)) {
            throw new Exception('No uploaded parts were reported for this session.');
        }

        usort($parts, static fn(array $a, array $b): int => (int)$a['part_number'] <=> (int)$b['part_number']);

        UploadSession::update((int)$session['id'], [
            'status' => 'completing',
            'checksum_sha256' => $checksumSha256,
        ]);

        if (!$provider->completeMultipartUpload($session['object_key'], (string)$session['multipart_upload_id'], $parts)) {
            UploadSession::update((int)$session['id'], [
                'status' => 'failed',
                'error_message' => 'Multipart completion failed at the storage provider.',
            ]);
            throw new Exception('Multipart completion failed.');
        }

        $head = $provider->head($session['object_key']);
        $finalSize = (int)($head['content_length'] ?? $session['expected_size']);
        $mimeType = (string)($head['content_type'] ?? ($session['mime_hint'] ?: 'application/octet-stream'));
        $providerEtag = (string)($head['etag'] ?? '');

        $db->beginTransaction();
        try {
            $storedFileId = null;
            $createdNewStoredObject = false;
            $dedupeEnabled = Setting::get('upload_detect_duplicates', '1') === '1';

            $dedupeCandidate = null;
            if ($dedupeEnabled) {
                if ($checksumSha256) {
                    $dedupeCandidate = StoredFile::findByHashAndSize($checksumSha256, $finalSize);
                    if (!$dedupeCandidate) {
                        $dedupeCandidate = StoredFile::findByCompletedUploadChecksumAndSize($checksumSha256, $finalSize);
                    }
                }

                if (!$dedupeCandidate && $providerEtag !== '') {
                    $dedupeCandidate = StoredFile::findByProviderEtagAndSize($providerEtag, $finalSize);
                }
            }

            if ($dedupeCandidate) {
                $existing = $dedupeCandidate;
                if ($existing && !empty($existing['storage_path'])) {
                    $existingProvider = StorageManager::getProviderById($existing['file_server_id'] ? (int)$existing['file_server_id'] : null, $db);
                    if ($existingProvider->exists($existing['storage_path'])) {
                        $storedFileId = (int)$existing['id'];
                        StoredFile::incrementRefCount($storedFileId);
                        StoredFile::update($storedFileId, [
                            'file_hash' => $checksumSha256 ?: $existing['file_hash'],
                            'provider_etag' => $existing['provider_etag'] ?: $providerEtag,
                            'checksum_verified_at' => date('Y-m-d H:i:s'),
                        ]);

                        $deletedDuplicateObject = $provider->delete($session['object_key']);
                        if ($deletedDuplicateObject) {
                            \App\Core\Logger::info('Multipart duplicate object removed after dedupe hit', [
                                'session_id' => $session['public_id'] ?? null,
                                'object_key' => $session['object_key'] ?? null,
                                'stored_file_id' => $storedFileId,
                            ]);
                        } else {
                            \App\Core\Logger::warning('Multipart duplicate object could not be removed after dedupe hit', [
                                'session_id' => $session['public_id'] ?? null,
                                'object_key' => $session['object_key'] ?? null,
                                'stored_file_id' => $storedFileId,
                            ]);
                        }
                    }
                }
            }

            if (!$storedFileId) {
                $storedFileId = StoredFile::create(
                    $checksumSha256 ?: hash('sha256', $session['public_id'] . '|' . $session['object_key']),
                    $session['storage_provider'],
                    $session['object_key'],
                    $finalSize,
                    $mimeType,
                    $session['storage_server_id'] ? (int)$session['storage_server_id'] : null,
                    $providerEtag ?: null
                );
                $createdNewStoredObject = true;
            }

            $fileId = File::create(
                !empty($session['user_id']) ? (int)$session['user_id'] : null,
                $storedFileId,
                $session['original_filename'],
                $session['folder_id'] ? (int)$session['folder_id'] : null,
                null,
                1,
                'active'
            );

            if (!empty($session['user_id'])) {
                $db->prepare("UPDATE users SET storage_used = storage_used + ?, storage_warning_sent = 0 WHERE id = ?")
                    ->execute([$finalSize, $session['user_id']]);
            }

            $reservation = QuotaReservation::findActiveBySession((int)$session['id']);
            if ($reservation) {
                QuotaReservation::updateStatus((int)$reservation['id'], 'committed');
            }

            if ($createdNewStoredObject && !empty($session['storage_server_id'])) {
                StorageManager::recordUsage($db, (int)$session['storage_server_id'], $finalSize);
                \App\Service\SystemStatsService::increment('total_storage_bytes', $finalSize);
            }

            UploadSession::update((int)$session['id'], [
                'status' => 'completed',
                'checksum_sha256' => $checksumSha256,
                'uploaded_bytes' => $finalSize,
                'error_message' => null,
                'completed_at' => date('Y-m-d H:i:s'),
                'metadata_json' => json_encode([
                    'provider_key' => $session['metadata']['provider_key'] ?? null,
                    'file_id' => $fileId,
                    'provider_etag' => $providerEtag,
                ], JSON_UNESCAPED_SLASHES),
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            \App\Core\Logger::error('Multipart upload finalization failed', [
                'session_id' => $session['public_id'] ?? null,
                'storage_server_id' => $session['storage_server_id'] ?? null,
                'object_key' => $session['object_key'] ?? null,
                'error' => $e->getMessage(),
            ]);
            UploadSession::update((int)$session['id'], [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }

        return [
            'file_id' => $fileId,
            'session_id' => $session['public_id'],
            'stored_file_id' => $storedFileId,
            'deduplicated' => !$createdNewStoredObject,
            'checksum_sha256' => $checksumSha256,
            'upload_skipped' => false,
        ];
    }

    public function abort(array $session): void
    {
        $db = Database::getInstance()->getConnection();
        $provider = StorageManager::getProviderById($session['storage_server_id'] ? (int)$session['storage_server_id'] : null, $db);
        if (!empty($session['multipart_upload_id'])) {
            $provider->abortMultipartUpload($session['object_key'], (string)$session['multipart_upload_id']);
        }

        UploadSession::update((int)$session['id'], [
            'status' => 'aborted',
            'error_message' => null,
        ]);

        $reservation = QuotaReservation::findActiveBySession((int)$session['id']);
        if ($reservation) {
            QuotaReservation::updateStatus((int)$reservation['id'], 'released');
        }
    }

    public function expireStaleSessions(int $limit = 100): array
    {
        $expired = 0;
        $released = 0;

        foreach (UploadSession::findExpiring($limit) as $session) {
            try {
                $this->abort($session);
                UploadSession::update((int)$session['id'], ['status' => 'expired']);
                $expired++;
                $released++;
            } catch (\Throwable $e) {
                UploadSession::update((int)$session['id'], ['error_message' => $e->getMessage()]);
            }
        }

        return ['expired_sessions' => $expired, 'released_reservations' => $released];
    }

    public function releaseExpiredReservations(int $limit = 100): array
    {
        $released = 0;
        foreach (QuotaReservation::findExpired($limit) as $reservation) {
            if (!empty($reservation['upload_session_id'])) {
                $session = Database::getInstance()->getConnection()
                    ->prepare("SELECT status FROM upload_sessions WHERE id = ? LIMIT 1");
                $session->execute([(int)$reservation['upload_session_id']]);
                $sessionStatus = $session->fetchColumn();
                if (in_array($sessionStatus, ['pending', 'uploading', 'completing', 'processing'], true)) {
                    $this->refreshSessionLease((int)$reservation['upload_session_id']);
                    continue;
                }
            }
            QuotaReservation::updateStatus((int)$reservation['id'], 'expired');
            $released++;
        }

        return ['expired_reservations' => $released];
    }

    public function reconcileCompletedChecksums(int $limit = 100): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT us.public_id, us.checksum_sha256, sf.id AS stored_file_id
            FROM upload_sessions us
            JOIN files f ON JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.file_id')) = f.id
            JOIN stored_files sf ON f.stored_file_id = sf.id
            WHERE us.status = 'completed'
              AND us.checksum_sha256 IS NOT NULL
              AND (sf.checksum_verified_at IS NULL OR sf.provider_etag IS NULL)
            ORDER BY us.id ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $updated = 0;
        foreach ($rows as $row) {
            StoredFile::update((int)$row['stored_file_id'], [
                'checksum_verified_at' => date('Y-m-d H:i:s'),
            ]);
            $updated++;
        }

        return ['checksums_marked_verified' => $updated];
    }

    public function reconcileActiveSessions(int $limit = 100): array
    {
        $reconciled = 0;
        foreach (UploadSession::findExpiring($limit) as $session) {
            if ($session['status'] === 'completing' && !empty($session['uploaded_bytes'])) {
                UploadSession::update((int)$session['id'], [
                    'status' => 'failed',
                    'error_message' => 'Completion timed out before metadata could be finalized.',
                ]);
                $reconciled++;
            }
        }

        return ['reconciled_sessions' => $reconciled];
    }

    private function assertQuotaAvailable(?int $userId, int $expectedSize, array $package, ?int $fileServerId): void
    {
        if ($userId !== null) {
            $user = User::find($userId);
            $activeReserved = QuotaReservation::activeReservedBytesForUser($userId);
            $maxStorage = (int)($package['max_storage_bytes'] ?? 0);

            if ($maxStorage > 0) {
                $used = (int)($user['storage_used'] ?? 0);
                if (($used + $activeReserved + $expectedSize) > $maxStorage) {
                    throw new Exception('This upload would exceed your storage quota.');
                }
            }
        }

        if ($fileServerId) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT current_usage_bytes, max_capacity_bytes FROM file_servers WHERE id = ? LIMIT 1");
            $stmt->execute([$fileServerId]);
            $server = $stmt->fetch();
            if ($server && (int)$server['max_capacity_bytes'] > 0) {
                $reserved = QuotaReservation::activeReservedBytesForServer($fileServerId);
                $current = (int)$server['current_usage_bytes'];
                if (($current + $reserved + $expectedSize) > (int)$server['max_capacity_bytes']) {
                    throw new Exception('The selected storage node does not have enough free capacity.');
                }
            }
        }
    }

    private function resolvePartSize(int $expectedSize): int
    {
        $configuredMb = max(8, (int)Setting::get('upload_chunk_size_mb', '64'));
        $partSize = $configuredMb * 1024 * 1024;
        $minForPartLimit = (int)ceil($expectedSize / 10000);

        if ($expectedSize > 20 * 1024 * 1024 * 1024) {
            $partSize = max($partSize, 128 * 1024 * 1024);
        } elseif ($expectedSize > 2 * 1024 * 1024 * 1024) {
            $partSize = max($partSize, 64 * 1024 * 1024);
        } else {
            $partSize = max($partSize, 16 * 1024 * 1024);
        }

        $partSize = max($partSize, $minForPartLimit);
        return min($partSize, 5 * 1024 * 1024 * 1024);
    }

    private function resolveAppUploadPartSizeLimit(): int
    {
        $configuredMb = max(8, (int)Setting::get('upload_chunk_size_mb', '64'));
        return min($configuredMb, 16) * 1024 * 1024;
    }

    private function assertAllowedExtension(string $filename): void
    {
        $allowedSetting = Setting::get('upload_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4,mp3,ipa,apk');
        $allowedExtensions = array_values(array_filter(array_map('trim', explode(',', strtolower($allowedSetting)))));
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExtensions, true)) {
            $allowedStr = implode(', ', $allowedExtensions);
            throw new Exception("Security Error: file type (.$ext) is not allowed. Allowed extensions are: [$allowedStr]. Check your Settings.");
        }
    }

    private function buildObjectKey(?int $userId, string $filename): string
    {
        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension);
        $suffix = $extension ? '.' . $extension : '';
        $ownerSegment = $userId !== null ? 'u' . $userId : 'guest';
        return sprintf(
            'uploads/%s/%s/%s%s',
            date('Y/m'),
            $ownerSegment,
            bin2hex(random_bytes(16)),
            $suffix
        );
    }

    private function newPublicId(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(10));
    }

    private function nextExpiry(): string
    {
        return date('Y-m-d H:i:s', time() + self::SESSION_TTL_SECONDS);
    }

    private function refreshSessionLease(int $sessionId): void
    {
        $expiresAt = $this->nextExpiry();
        UploadSession::refreshExpiry($sessionId, $expiresAt);
        QuotaReservation::refreshExpiryBySession($sessionId, $expiresAt);
    }

    private function normalizeChecksum(?string $checksum): ?string
    {
        $checksum = strtolower(trim((string)$checksum));
        if ($checksum === '' || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            return null;
        }

        return $checksum;
    }

    private function findDeduplicationCandidate(string $checksumSha256, int $expectedSize): ?array
    {
        $candidate = StoredFile::findByHashAndSize($checksumSha256, $expectedSize);
        if (!$candidate) {
            $candidate = StoredFile::findByCompletedUploadChecksumAndSize($checksumSha256, $expectedSize);
        }
        if (!$candidate) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $provider = StorageManager::getProviderById($candidate['file_server_id'] ? (int)$candidate['file_server_id'] : null, $db);
        if (!$provider->exists($candidate['storage_path'])) {
            return null;
        }

        $head = $provider->head($candidate['storage_path']);
        $contentLength = (int)($head['content_length'] ?? 0);
        if ($head === null || ($expectedSize > 0 && $contentLength > 0 && $contentLength !== $expectedSize)) {
            return null;
        }

        return $candidate;
    }

    private function createImmediateDuplicateSession(
        array $storedFile,
        ?int $userId,
        string $filename,
        int $expectedSize,
        ?int $folderId,
        ?string $guestSessionId,
        string $checksumSha256
    ): array {
        $db = Database::getInstance()->getConnection();
        $publicId = $this->newPublicId('us_');
        $fileId = null;

        $db->beginTransaction();
        try {
            StoredFile::incrementRefCount((int)$storedFile['id']);

            $fileId = File::create(
                $userId,
                (int)$storedFile['id'],
                $filename,
                $folderId,
                null,
                $this->resolveInitialVisibility($userId),
                'active'
            );

            if ($userId !== null) {
                $db->prepare("UPDATE users SET storage_used = storage_used + ?, storage_warning_sent = 0 WHERE id = ?")
                    ->execute([$expectedSize, $userId]);
            }

            UploadSession::create([
                'public_id' => $publicId,
                'user_id' => $userId,
                'guest_session_id' => $guestSessionId,
                'folder_id' => $folderId,
                'storage_server_id' => $storedFile['file_server_id'] ? (int)$storedFile['file_server_id'] : null,
                'storage_provider' => (string)($storedFile['storage_provider'] ?? 'local'),
                'original_filename' => $filename,
                'object_key' => (string)($storedFile['storage_path'] ?? ''),
                'expected_size' => $expectedSize,
                'mime_hint' => (string)($storedFile['mime_type'] ?? ''),
                'checksum_sha256' => $checksumSha256,
                'status' => 'completed',
                'reserved_bytes' => 0,
                'uploaded_bytes' => $expectedSize,
                'completed_parts' => 0,
                'part_size_bytes' => 0,
                'metadata_json' => json_encode([
                    'provider_key' => null,
                    'file_id' => $fileId,
                    'stored_file_id' => (int)$storedFile['id'],
                    'deduplicated' => true,
                    'upload_skipped' => true,
                ], JSON_UNESCAPED_SLASHES),
                'completed_at' => date('Y-m-d H:i:s'),
                'expires_at' => $this->nextExpiry(),
            ]);

            StoredFile::update((int)$storedFile['id'], [
                'file_hash' => $checksumSha256,
                'checksum_verified_at' => date('Y-m-d H:i:s'),
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        \App\Core\Logger::info('Multipart upload skipped because duplicate content already exists', [
            'stored_file_id' => (int)$storedFile['id'],
            'file_id' => $fileId,
            'checksum_sha256' => $checksumSha256,
        ]);

        $session = UploadSession::findByPublicId($publicId);
        $session['parts'] = [];

        return [
            'session' => $session,
            'part_size_bytes' => 0,
            'expires_at' => $session['expires_at'] ?? null,
            'capabilities' => [
                'multipart' => false,
                'presigned_part_upload' => false,
                'presigned_download' => false,
                'head' => false,
            ],
            'upload_skipped' => true,
            'file_id' => $fileId,
            'stored_file_id' => (int)$storedFile['id'],
            'deduplicated' => true,
        ];
    }

    private function resolveInitialVisibility(?int $userId): int
    {
        if ($userId === null) {
            return 1;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT default_privacy FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $pref = $stmt->fetchColumn();

        return $pref === 'private' ? 0 : 1;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS `upload_sessions` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `public_id` VARCHAR(32) NOT NULL,
                `user_id` BIGINT UNSIGNED NULL,
                `guest_session_id` VARCHAR(128) NULL,
                `folder_id` BIGINT UNSIGNED NULL,
                `storage_server_id` INT UNSIGNED NULL,
                `storage_provider` VARCHAR(50) NOT NULL DEFAULT 'local',
                `original_filename` VARCHAR(255) NOT NULL,
                `object_key` VARCHAR(255) NOT NULL,
                `expected_size` BIGINT UNSIGNED NOT NULL,
                `mime_hint` VARCHAR(255) NULL,
                `checksum_sha256` CHAR(64) NULL,
                `multipart_upload_id` VARCHAR(255) NULL,
                `status` ENUM('pending', 'uploading', 'completing', 'processing', 'completed', 'failed', 'aborted', 'expired') NOT NULL DEFAULT 'pending',
                `reserved_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `uploaded_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `completed_parts` INT UNSIGNED NOT NULL DEFAULT 0,
                `part_size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
                `metadata_json` LONGTEXT NULL,
                `error_message` TEXT NULL,
                `expires_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `upload_sessions_public_id` (`public_id`),
                KEY `upload_sessions_user_status` (`user_id`, `status`),
                KEY `upload_sessions_guest_status` (`guest_session_id`, `status`),
                KEY `upload_sessions_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS `upload_session_parts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `upload_session_id` BIGINT UNSIGNED NOT NULL,
                `part_number` INT UNSIGNED NOT NULL,
                `etag` VARCHAR(255) NULL,
                `part_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `checksum_sha256` CHAR(64) NULL,
                `status` ENUM('signed', 'uploaded', 'verified', 'failed') NOT NULL DEFAULT 'signed',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `upload_session_part_unique` (`upload_session_id`, `part_number`),
                KEY `upload_session_part_status` (`upload_session_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS `quota_reservations` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `public_id` VARCHAR(32) NOT NULL,
                `user_id` BIGINT UNSIGNED NULL,
                `upload_session_id` BIGINT UNSIGNED NULL,
                `storage_server_id` INT UNSIGNED NULL,
                `reserved_bytes` BIGINT UNSIGNED NOT NULL,
                `status` ENUM('active', 'committed', 'released', 'expired') NOT NULL DEFAULT 'active',
                `expires_at` DATETIME NULL,
                `released_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `quota_reservations_public_id` (`public_id`),
                KEY `quota_reservations_user_status` (`user_id`, `status`),
                KEY `quota_reservations_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        try { $db->exec("ALTER TABLE `stored_files` ADD COLUMN `provider_etag` VARCHAR(255) NULL AFTER `mime_type`"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `stored_files` ADD COLUMN `checksum_verified_at` DATETIME NULL AFTER `provider_etag`"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `stored_files` ADD INDEX `file_hash_size_idx` (`file_hash`, `file_size`)"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `upload_sessions` MODIFY COLUMN `user_id` BIGINT UNSIGNED NULL"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `quota_reservations` MODIFY COLUMN `user_id` BIGINT UNSIGNED NULL"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `upload_sessions` ADD COLUMN `guest_session_id` VARCHAR(128) NULL AFTER `user_id`"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `upload_sessions` ADD INDEX `upload_sessions_guest_status` (`guest_session_id`, `status`)"); } catch (\Throwable $e) {}
        self::$schemaReady = true;
    }
}
