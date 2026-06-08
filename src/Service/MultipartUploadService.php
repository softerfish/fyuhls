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
use App\Service\Database\SchemaService;
use Exception;

class MultipartUploadService
{
    private static bool $schemaReady = false;
    private const SESSION_TTL_SECONDS = 7200;
    private const MULTIPART_BATCH_FAILURE_MESSAGE = 'Uploaded part metadata did not match storage provider state.';
    private const MULTIPART_EXTRA_PART_SAMPLE_LIMIT = 5;
    private const MAX_MULTIPART_PART_NUMBER = 10000;
    private const ACTIVE_SESSION_STATUSES = ['pending', 'uploading', 'processing'];
    private const ABORTABLE_SESSION_STATUSES = ['pending', 'uploading', 'processing'];
    private const COMPLETABLE_SESSION_STATUSES = ['uploading', 'processing'];
    private const FAILABLE_SESSION_STATUSES = ['pending', 'uploading', 'processing', 'completing'];
    private const DANGEROUS_UPLOAD_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jspx', 'shtml',
        'sh', 'bash', 'cmd', 'bat', 'ps1'
    ];
    private bool $userQuotaLockHeld = false;
    private ?int $lockedQuotaUserId = null;

    public function __construct()
    {
        $this->ensureSchema();
        StoredFile::ensureSchemaCompatibility();
    }

    public function createSession(?int $userId, string $filename, int $expectedSize, ?int $folderId = null, ?string $mimeHint = null, ?string $guestSessionId = null, ?string $checksumSha256 = null, ?int $replaceFileId = null): array
    {
        $filename = trim($filename);
        if ($filename === '') {
            throw new Exception('A filename is required.');
        }

        if ($expectedSize <= 0) {
            throw new Exception('Upload size must be greater than zero.');
        }

        $replaceTarget = null;
        $replaceBaselineSize = 0;

        if ($userId !== null) {
            $user = User::find($userId);
            if (!$user) {
                throw new Exception('User not found.');
            }
            $package = Package::getUserPackage($userId);
            if ($replaceFileId !== null && $replaceFileId > 0) {
                if (Setting::get('upload_replace_enabled', '0') !== '1') {
                    throw new Exception('File replacement is currently disabled.');
                }
                $replaceTarget = File::findAnyStatus($replaceFileId);
                if (!$replaceTarget) {
                    throw new Exception('Replacement target not found.');
                }
                if ((int)($replaceTarget['user_id'] ?? 0) !== $userId || (string)($replaceTarget['status'] ?? '') !== 'active') {
                    throw new Exception('You can only replace your own active files.');
                }
                $replaceBaselineSize = max(0, (int)($replaceTarget['file_size'] ?? 0));
                $folderId = (int)($replaceTarget['folder_id'] ?? 0) > 0 ? (int)$replaceTarget['folder_id'] : null;
            }
        } else {
            if ($replaceFileId !== null && $replaceFileId > 0) {
                throw new Exception('File replacement requires a signed-in account.');
            }
            $package = Package::getGuestPackage();
        }
        if (!$package) {
            throw new Exception('Upload package not found.');
        }

        $this->assertAllowedExtension($filename, $package);

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
        $db = Database::getInstance()->getConnection();
        $actorLockKey = $this->buildUploadAdmissionActorLockKey($userId, $guestSessionId);
        if (!$this->acquireUploadAdmissionLock($db, $actorLockKey)) {
            throw new Exception('Upload could not be started safely right now. Please try again.');
        }

        $replaceLockKey = null;
        $capacityLockServerId = null;
        try {
            if ($userId !== null && $userId > 0) {
                if (!$this->acquireUserStorageQuotaLock($db, $userId)) {
                    throw new Exception('Upload could not be started safely right now. Please try again.');
                }
                $this->userQuotaLockHeld = true;
                $this->lockedQuotaUserId = $userId;
            }

            if ($replaceTarget && $replaceFileId !== null && $replaceFileId > 0) {
                $replaceLockKey = $this->buildReplaceAdmissionLockKey((int)$replaceFileId);
                if (!$this->acquireReplaceAdmissionLock($db, $replaceLockKey)) {
                    throw new Exception('Upload could not be started safely right now. Please try again.');
                }

                if ($this->hasActiveReplacementSession((int)$replaceFileId, (int)$userId)) {
                    throw new Exception('This file already has a replacement upload in progress.');
                }
            }

            $maxConcurrent = max(1, (int)($package['concurrent_uploads'] ?? 1));
            $activeCount = $userId !== null
                ? UploadSession::countActiveForUser($userId)
                : UploadSession::countActiveForGuestSession((string)$guestSessionId);
            if ($activeCount >= $maxConcurrent) {
                throw new Exception('You already have the maximum number of active uploads for your package.');
            }

            // Do not short-circuit multipart uploads based only on a client-supplied checksum.
            // The completed object must be verified by the server before we reuse an existing
            // stored file, otherwise an attacker could claim a known checksum without actually
            // possessing the bytes.

            $quotaBytes = max(0, $expectedSize - $replaceBaselineSize);
            [$providerKey, $provider, $fileServerId] = StorageManager::resolveFromDb($db, $quotaBytes, true);
            $storageProvider = explode('_', $providerKey, 2)[0];
            $capabilities = $provider->getCapabilities();

            if (empty($capabilities['multipart']) || (empty($capabilities['presigned_part_upload']) && empty($capabilities['app_part_upload']))) {
                throw new Exception('The selected storage backend does not support direct multipart uploads yet.');
            }

            $capacityLockServerId = (int)$fileServerId > 0 ? (int)$fileServerId : null;
            if ($capacityLockServerId !== null && !StorageManager::acquireServerCapacityLock($db, $capacityLockServerId, 10)) {
                throw new Exception('Upload could not be started safely right now. Please try again.');
            }

            StorageManager::assertServerHasCapacity($db, $capacityLockServerId, $quotaBytes, true);
            $this->assertQuotaAvailable($userId, $quotaBytes, $package, $fileServerId);

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
                'replace_file_id' => $replaceTarget ? (int)$replaceTarget['id'] : null,
                'replace_stored_file_id' => $replaceTarget ? (int)($replaceTarget['stored_file_id'] ?? 0) : null,
                'replace_baseline_size' => $replaceBaselineSize,
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
                    'reserved_bytes' => $quotaBytes,
                    'part_size_bytes' => $partSizeBytes,
                    'metadata_json' => $metadata,
                    'expires_at' => $expiresAt,
                ]);

                QuotaReservation::create([
                    'public_id' => $this->newPublicId('qr_'),
                    'user_id' => $userId,
                    'upload_session_id' => $sessionId,
                    'storage_server_id' => $fileServerId,
                    'reserved_bytes' => $quotaBytes,
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
            ];
        } finally {
            if ($replaceLockKey !== null) {
                $this->releaseReplaceAdmissionLock($db, $replaceLockKey);
            }
            if ($this->userQuotaLockHeld && $this->lockedQuotaUserId !== null) {
                $this->releaseUserStorageQuotaLock($db, $this->lockedQuotaUserId);
                $this->userQuotaLockHeld = false;
                $this->lockedQuotaUserId = null;
            }
            if ($capacityLockServerId !== null) {
                StorageManager::releaseServerCapacityLock($db, $capacityLockServerId);
            }
            $this->releaseUploadAdmissionLock($db, $actorLockKey);
        }
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

        if ($this->sessionStatusAllowsLeaseRefresh((string)($session['status'] ?? ''))) {
            $this->refreshReadOnlySessionLeaseIfNeeded($session);
        }
        $session['parts'] = UploadSession::getParts((int)$session['id']);
        return $session;
    }

    public function signParts(array $session, array $partNumbers, int $expiry = 3600): array
    {
        $this->assertSessionAllowsMutation($session, self::ACTIVE_SESSION_STATUSES, 'This upload session can no longer accept part signing requests.');
        $session = $this->requireCurrentSessionStatus($session, self::ACTIVE_SESSION_STATUSES, 'This upload session can no longer accept part signing requests.');
        $db = Database::getInstance()->getConnection();
        $provider = $this->resolveSessionProviderOrFail($db, $session);
        if (!$this->refreshSessionLease((int)$session['id'], self::ACTIVE_SESSION_STATUSES)) {
            throw new Exception('This upload session can no longer accept part signing requests.');
        }
        $urls = [];

        foreach ($partNumbers as $partNumber) {
            $partNumber = (int)$partNumber;
            $expectedPartSize = $this->expectedPartSizeForSession($session, $partNumber);

            if (!empty($provider->getCapabilities()['app_part_upload'])) {
                $url = '/api/v1/uploads/sessions/' . rawurlencode((string)$session['public_id']) . '/parts/upload/' . $partNumber;
            } else {
                $url = $provider->createMultipartPartUrl(
                    $session['object_key'],
                    (string)$session['multipart_upload_id'],
                    $partNumber,
                    $expiry,
                    ['ContentLength' => $expectedPartSize]
                );
            }
            if (!$url) {
                continue;
            }

            if (!$this->refreshSessionLease((int)$session['id'], self::ACTIVE_SESSION_STATUSES)) {
                throw new Exception('This upload session can no longer accept part signing requests.');
            }
            UploadSession::upsertPart((int)$session['id'], $partNumber, null, 0, 'signed');
            $urls[] = [
                'part_number' => $partNumber,
                'url' => $url,
                'expires_in' => $expiry,
                'expected_size' => $expectedPartSize,
            ];
        }

        return $urls;
    }

    public function writeUploadedPart(array $session, int $partNumber, $stream): array
    {
        if (!is_resource($stream)) {
            throw new Exception('Could not read upload body.');
        }

        $session = $this->requireCurrentSessionStatus($session, ['uploading', 'processing'], 'This upload session can no longer accept uploaded parts.');
        $expectedPartSize = $this->expectedPartSizeForSession($session, $partNumber);
        $db = Database::getInstance()->getConnection();
        $provider = $this->resolveSessionProviderOrFail($db, $session);

        if (!method_exists($provider, 'writeMultipartPart')) {
            throw new Exception('The selected storage backend does not support app-routed multipart uploads.');
        }

        $buffer = fopen('php://temp', 'w+b');
        if (!is_resource($buffer)) {
            throw new Exception('Could not open multipart upload buffer.');
        }

        try {
            $writtenBytes = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new Exception('Could not read upload body.');
                }
                if ($chunk === '') {
                    continue;
                }

                $writtenBytes += strlen($chunk);
                if ($writtenBytes > $expectedPartSize) {
                    throw new Exception('Uploaded part exceeds the allowed size for this upload session.');
                }

                if (fwrite($buffer, $chunk) === false) {
                    throw new Exception('Could not buffer uploaded part data.');
                }
            }

            rewind($buffer);
            if (!$this->refreshSessionLease((int)$session['id'], ['uploading', 'processing'])) {
                throw new Exception('This upload session can no longer accept uploaded parts.');
            }
            $result = $provider->writeMultipartPart((string)$session['object_key'], (string)$session['multipart_upload_id'], $partNumber, $buffer);
        } finally {
            fclose($buffer);
        }

        if ((int)($result['part_size'] ?? 0) > $expectedPartSize) {
            throw new Exception('Uploaded part exceeds the allowed size for this upload session.');
        }

        return $result;
    }

    public function reportPart(array $session, int $partNumber, string $etag, int $partSize, ?string $checksum = null): array
    {
        $this->assertSessionAllowsMutation($session, ['uploading', 'processing'], 'This upload session can no longer accept uploaded parts.');
        $session = $this->claimSessionForPartMutation($session, 'This upload session can no longer accept uploaded parts.');
        $expectedPartSize = $this->expectedPartSizeForSession($session, $partNumber);
        if (!$this->refreshSessionLease((int)$session['id'], ['uploading', 'processing'])) {
            throw new Exception('This upload session can no longer accept uploaded parts.');
        }

        $normalizedEtag = trim($etag, '"');
        $partSize = max(0, $partSize);

        if ($partSize > $expectedPartSize) {
            $message = 'Uploaded part exceeds the allowed size for this upload session.';
            $this->failMultipartSession($session, $message);
            throw new Exception($message);
        }

        UploadSession::upsertPart((int)$session['id'], $partNumber, $normalizedEtag, $partSize, 'uploaded', $checksum);

        $parts = UploadSession::getParts((int)$session['id']);
        $uploadedBytes = 0;
        $uploadedCount = 0;
        foreach ($parts as $part) {
            if ($part['status'] === 'uploaded') {
                $uploadedBytes += (int)$part['part_size'];
                $uploadedCount++;
            }
        }

        if (!UploadSession::transitionStatus((int)$session['id'], ['uploading', 'processing'], 'uploading', [
            'uploaded_bytes' => $uploadedBytes,
            'completed_parts' => $uploadedCount,
        ])) {
            throw new Exception('This upload session can no longer accept uploaded parts.');
        }

        $fresh = UploadSession::findByPublicId($session['public_id']);
        $fresh['parts'] = $parts;
        return $fresh;
    }

    public function complete(array $session, ?string $checksumSha256 = null): array
    {
        $db = Database::getInstance()->getConnection();
        $sessionId = (int)($session['id'] ?? 0);
        $checksumSha256 = $this->normalizeChecksum($checksumSha256 ?: ($session['checksum_sha256'] ?? null));
        $deduplicationService = new DeduplicationService();
        $dedupeEnabled = $deduplicationService->enabled();
        $session = $this->claimSessionForCompletion($session, $checksumSha256);
        try {
            $provider = $this->resolveSessionProviderOrFail($db, $session);
        } catch (\Throwable $e) {
            $this->markSessionFailed($sessionId, $e->getMessage());
            throw $e;
        }
        $parts = $this->eligibleMultipartCompletionParts($sessionId);

        if (empty($parts)) {
            $this->markSessionFailed($sessionId, 'No uploaded parts were reported for this session.');
            throw new Exception('No uploaded parts were reported for this session.');
        }

        $assemblyParts = $parts;
        $enforceBatchVerification = $this->shouldEnforceMultipartBatchVerification($provider);
        $shadowBatchVerification = $this->shouldShadowMultipartBatchVerification($provider);
        if ($enforceBatchVerification || $shadowBatchVerification) {
            try {
                $batchVerification = $this->verifyMultipartPartsAgainstProvider($session, $provider, $parts);
                $this->logMultipartBatchVerificationResult($session, $provider, $batchVerification, $enforceBatchVerification);
                if ($enforceBatchVerification) {
                    $assemblyParts = $batchVerification['assembly_parts'];
                }
            } catch (\Throwable $e) {
                if ($enforceBatchVerification) {
                    \App\Core\Logger::warning('Multipart batch verification failed before provider completion', [
                        'session_id' => $session['public_id'] ?? null,
                        'storage_server_id' => $session['storage_server_id'] ?? null,
                        'object_key' => $session['object_key'] ?? null,
                        'reason' => $e->getMessage(),
                    ]);
                    $this->failMultipartSession($session, self::MULTIPART_BATCH_FAILURE_MESSAGE, $provider);
                    throw new Exception(self::MULTIPART_BATCH_FAILURE_MESSAGE, 0, $e);
                }

                \App\Core\Logger::warning('Multipart batch verification shadow mismatch', [
                    'session_id' => $session['public_id'] ?? null,
                    'storage_server_id' => $session['storage_server_id'] ?? null,
                    'object_key' => $session['object_key'] ?? null,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        usort($assemblyParts, static fn(array $a, array $b): int => (int)$a['part_number'] <=> (int)$b['part_number']);

        try {
            if (!$provider->completeMultipartUpload($session['object_key'], (string)$session['multipart_upload_id'], $assemblyParts)) {
                throw new Exception('Multipart completion failed at the storage provider.');
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Multipart provider completion failed', [
                'session_id' => $session['public_id'] ?? null,
                'storage_server_id' => $session['storage_server_id'] ?? null,
                'object_key' => $session['object_key'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->markSessionFailed($sessionId, 'Multipart completion failed at the storage provider.');
            throw new Exception('Multipart completion failed.', 0, $e);
        }

        try {
            $head = $provider->head($session['object_key']);
            if ($head === null) {
                throw new Exception('Completed object metadata could not be read.');
            }

            $finalSize = (int)($head['content_length'] ?? 0);
            $mimeType = (string)($head['content_type'] ?? ($session['mime_hint'] ?: 'application/octet-stream'));
            $providerEtag = (string)($head['etag'] ?? '');
            $mimeType = $this->validateCompletedObject($provider, $session, $finalSize, $mimeType, $checksumSha256);
            if ($checksumSha256 === null && $dedupeEnabled) {
                $absolutePath = method_exists($provider, 'getAbsolutePath')
                    ? (string)$provider->getAbsolutePath((string)$session['object_key'])
                    : '';
                $checksumSha256 = $this->hashCompletedObject($provider, (string)$session['object_key'], $absolutePath);
                if (!is_string($checksumSha256) || preg_match('/^[a-f0-9]{64}$/', $checksumSha256) !== 1) {
                    throw new Exception('Completed upload checksum could not be verified by the server.');
                }
            }
        } catch (\Throwable $e) {
            $this->markSessionFailed($sessionId, $e->getMessage());
            $provider->delete($session['object_key']);
            throw $e;
        }

        $dedupeHashLockHeld = false;
        if ($dedupeEnabled) {
            if (!$deduplicationService->acquireHashLock($db, $checksumSha256, 10)) {
                $this->markSessionFailed($sessionId, 'Upload could not be finalized safely right now. Please try again.');
                throw new Exception('Upload could not be finalized safely right now. Please try again.');
            }
            $dedupeHashLockHeld = true;
        }

        $db->beginTransaction();
        $shouldDeleteCompletedObjectOnFailure = false;
        $postCommitDuplicateObjectKey = null;
        $postCommitDuplicateStoredFileId = 0;
        $postCommitDuplicateWasSessionObject = false;
        $committedReservationId = 0;
        try {
            $storedFileId = null;
            $createdNewStoredObject = false;
            $replaceFileId = (int)($session['metadata']['replace_file_id'] ?? 0);
            $fileId = null;
            $lockedReplaceTarget = null;
            $currentReplaceSize = 0;
            $currentReplaceStoredFileId = 0;
            $currentReplaceFilename = null;

            if ($replaceFileId > 0) {
                $lockedReplaceTarget = $this->lockReplaceTarget($db, $replaceFileId, (int)($session['user_id'] ?? 0));
                $currentReplaceSize = max(0, (int)($lockedReplaceTarget['file_size'] ?? 0));
                $currentReplaceStoredFileId = (int)($lockedReplaceTarget['stored_file_id'] ?? 0);
                $currentReplaceFilename = (string)($lockedReplaceTarget['filename'] ?? $session['original_filename']);
            }

            $this->assertSessionStillFitsCurrentPackage($db, $session, $finalSize, $currentReplaceSize);

            $dedupeCandidate = $dedupeEnabled
                ? $this->findCanonicalDeduplicationCandidate(
                    $checksumSha256,
                    $finalSize
                )
                : null;

            if ($dedupeCandidate) {
                $existing = $dedupeCandidate;
                $dedupeCandidateIsSessionObject = $this->dedupeCandidateMatchesSessionObject($existing, $session);
                $storedFileId = (int)$existing['id'];
                if (!$dedupeCandidateIsSessionObject) {
                    $postCommitDuplicateObjectKey = (string)$session['object_key'];
                    $postCommitDuplicateStoredFileId = $storedFileId;
                }
                if ($replaceFileId <= 0 || $storedFileId !== $currentReplaceStoredFileId) {
                    StoredFile::incrementRefCount($storedFileId);
                }
                StoredFile::update($storedFileId, [
                    'file_hash' => $checksumSha256 ?: $existing['file_hash'],
                    'provider_etag' => $existing['provider_etag'] ?: $providerEtag,
                    'checksum_verified_at' => date('Y-m-d H:i:s'),
                ]);
                $postCommitDuplicateWasSessionObject = $dedupeCandidateIsSessionObject;
            }

            if (!$storedFileId) {
                $shouldDeleteCompletedObjectOnFailure = true;
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

            $releasedStoredFileId = 0;
            if ($replaceFileId > 0) {
                $releasedStoredFileId = $currentReplaceStoredFileId;
                File::update($replaceFileId, [
                    'stored_file_id' => $storedFileId,
                    'filename' => $session['original_filename'],
                ]);
                $fileId = $replaceFileId;
            } else {
                $isPublic = $this->resolveUploadVisibility($db, !empty($session['user_id']) ? (int)$session['user_id'] : null);
                $fileId = File::create(
                    !empty($session['user_id']) ? (int)$session['user_id'] : null,
                    $storedFileId,
                    $session['original_filename'],
                    $session['folder_id'] ? (int)$session['folder_id'] : null,
                    null,
                    $isPublic,
                    'active'
                );
            }

            if (!empty($session['user_id'])) {
                $storageDelta = $replaceFileId > 0 ? ($finalSize - $currentReplaceSize) : $finalSize;
                if ($storageDelta > 0) {
                    $db->prepare("UPDATE users SET storage_used = storage_used + ?, storage_warning_sent = 0 WHERE id = ?")
                        ->execute([$storageDelta, $session['user_id']]);
                } elseif ($storageDelta < 0) {
                    $db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) + ?), storage_warning_sent = 0 WHERE id = ?")
                        ->execute([$storageDelta, $session['user_id']]);
                } else {
                    $db->prepare("UPDATE users SET storage_warning_sent = 0 WHERE id = ?")
                        ->execute([$session['user_id']]);
                }
            }

            $reservation = QuotaReservation::findActiveBySession($sessionId);
            if ($reservation) {
                $committedReservationId = (int)($reservation['id'] ?? 0);
                QuotaReservation::updateStatus((int)$reservation['id'], 'committed');
            }

            if ($createdNewStoredObject && !empty($session['storage_server_id'])) {
                StorageManager::recordUsageOrFail($db, (int)$session['storage_server_id'], $finalSize);
                \App\Service\SystemStatsService::increment('total_storage_bytes', $finalSize);
            }

            if ($replaceFileId > 0 && $releasedStoredFileId > 0 && $releasedStoredFileId !== $storedFileId) {
                StoredFile::decrementRefCount($releasedStoredFileId);
            }

            if (!UploadSession::transitionStatus($sessionId, ['completing'], 'completed', [
                'checksum_sha256' => $checksumSha256,
                'uploaded_bytes' => $finalSize,
                'error_message' => null,
                'completed_at' => date('Y-m-d H:i:s'),
                'metadata_json' => json_encode([
                    'provider_key' => $session['metadata']['provider_key'] ?? null,
                    'file_id' => $fileId,
                    'stored_file_id' => $storedFileId,
                    'provider_etag' => $providerEtag,
                ], JSON_UNESCAPED_SLASHES),
            ])) {
                throw new Exception('This upload session can no longer be completed.');
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($shouldDeleteCompletedObjectOnFailure) {
                try {
                    $provider->delete($session['object_key']);
                } catch (\Throwable $cleanupError) {
                    \App\Core\Logger::warning('Completed multipart object could not be cleaned up after failed finalization', [
                        'session_id' => $session['public_id'] ?? null,
                        'object_key' => $session['object_key'] ?? null,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }
            \App\Core\Logger::error('Multipart upload finalization failed', [
                'session_id' => $session['public_id'] ?? null,
                'storage_server_id' => $session['storage_server_id'] ?? null,
                'object_key' => $session['object_key'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->markSessionFailed($sessionId, $e->getMessage());
            throw $e;
        } finally {
            if ($dedupeHashLockHeld) {
                $deduplicationService->releaseHashLock($db, $checksumSha256);
            }
        }

        if (is_string($postCommitDuplicateObjectKey) && $postCommitDuplicateObjectKey !== '') {
            try {
                $this->deleteDuplicateCompletedObjectOrFail(
                    $provider,
                    $session,
                    $postCommitDuplicateObjectKey,
                    $postCommitDuplicateStoredFileId,
                    $postCommitDuplicateWasSessionObject
                );
            } catch (\Throwable $cleanupError) {
                $this->rollbackCommittedDeduplicatedCompletionOrFail(
                    $db,
                    $session,
                    $sessionId,
                    (int)$fileId,
                    (int)$storedFileId,
                    $finalSize,
                    !empty($session['user_id']) ? (int)$session['user_id'] : null,
                    $committedReservationId,
                    $replaceFileId,
                    $currentReplaceStoredFileId,
                    $currentReplaceFilename,
                    $currentReplaceSize,
                    $cleanupError
                );
            }
        }

        if ($replaceFileId > 0 && $releasedStoredFileId > 0 && $releasedStoredFileId !== $storedFileId) {
            $storedFileCleanup = StoredFile::purgeIfUnreferenced($releasedStoredFileId);
            if (($storedFileCleanup['status'] ?? '') === 'failed') {
                \App\Core\Logger::warning('Stored-file cleanup deferred after multipart replacement', [
                    'session_id' => $session['public_id'] ?? null,
                    'file_id' => $fileId,
                    'stored_file_id' => $releasedStoredFileId,
                    'reason' => $storedFileCleanup['reason'] ?? 'unknown',
                ]);
            }
        }
        if (!empty($session['user_id'])) {
            \App\Service\BonusOfferService::touchUserFailSoft((int)$session['user_id'], true, [
                'workflow' => 'multipart_upload',
                'session_id' => $session['public_id'] ?? null,
                'file_id' => $fileId,
            ]);
        }

        $this->deleteSessionPartsFailSoft($sessionId, 'multipart completion success');

        return [
            'file_id' => $fileId,
            'session_id' => $session['public_id'],
            'stored_file_id' => $storedFileId,
            'deduplicated' => !$createdNewStoredObject,
            'checksum_sha256' => $checksumSha256,
        ];
    }

    private function claimSessionForCompletion(array $session, ?string $checksumSha256): array
    {
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new Exception('This upload session can no longer be completed.');
        }

        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_TTL_SECONDS);
        $claimed = UploadSession::transitionStatus($sessionId, self::COMPLETABLE_SESSION_STATUSES, 'completing', [
            'checksum_sha256' => $checksumSha256,
            'expires_at' => $expiresAt,
        ]);
        if (!$claimed) {
            $fresh = UploadSession::findById($sessionId);
            $status = (string)($fresh['status'] ?? $session['status'] ?? '');
            if ($status === 'completed') {
                throw new Exception('This upload session has already been completed.');
            }
            throw new Exception('This upload session can no longer be completed.');
        }

        $fresh = UploadSession::findById($sessionId);
        if (!is_array($fresh)) {
            throw new Exception('This upload session can no longer be completed.');
        }

        return $fresh;
    }

    private function claimSessionForPartMutation(array $session, string $message): array
    {
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new Exception($message);
        }

        $expiresAt = $this->nextExpiry();
        $claimed = UploadSession::transitionStatus($sessionId, ['uploading', 'processing'], 'uploading', [
            'expires_at' => $expiresAt,
        ]);
        if (!$claimed) {
            throw new Exception($message);
        }

        QuotaReservation::refreshExpiryBySession($sessionId, $expiresAt);
        $fresh = UploadSession::findById($sessionId);
        if (!is_array($fresh)) {
            throw new Exception($message);
        }

        return $fresh;
    }

    private function claimSessionForAbort(array $session): array
    {
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new Exception('This upload session can no longer be aborted.');
        }

        $claimed = UploadSession::transitionStatus($sessionId, self::ABORTABLE_SESSION_STATUSES, 'aborted', [
            'error_message' => null,
        ]);
        if (!$claimed) {
            throw new Exception('This upload session can no longer be aborted.');
        }

        $fresh = UploadSession::findById($sessionId);
        if (!is_array($fresh)) {
            throw new Exception('This upload session can no longer be aborted.');
        }

        return $fresh;
    }

    private function requireCurrentSessionStatus(array $session, array $allowedStatuses, string $message): array
    {
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new Exception($message);
        }

        $fresh = UploadSession::findById($sessionId);
        if (!is_array($fresh)) {
            throw new Exception($message);
        }

        $this->assertSessionAllowsMutation($fresh, $allowedStatuses, $message);
        return $fresh;
    }

    private function dedupeCandidateMatchesSessionObject(array $candidate, array $session): bool
    {
        $candidatePath = trim((string)($candidate['storage_path'] ?? ''));
        $sessionObjectKey = trim((string)($session['object_key'] ?? ''));
        if ($candidatePath === '' || $sessionObjectKey === '' || !hash_equals($candidatePath, $sessionObjectKey)) {
            return false;
        }

        $candidateServerId = (int)($candidate['file_server_id'] ?? 0);
        $sessionServerId = (int)($session['storage_server_id'] ?? 0);
        return $candidateServerId === $sessionServerId;
    }

    private function deleteDuplicateCompletedObjectOrFail(object $provider, array $session, string $objectKey, int $storedFileId, bool $sessionObjectReused): void
    {
        try {
            if (!$provider->delete($objectKey)) {
                throw new Exception('Multipart duplicate object cleanup did not complete.');
            }
        } catch (\Throwable $e) {
            throw new Exception('Multipart duplicate object cleanup did not complete.', 0, $e);
        }

        \App\Core\Logger::info('Multipart duplicate object removed after dedupe hit', [
            'session_id' => $session['public_id'] ?? null,
            'object_key' => $objectKey,
            'stored_file_id' => $storedFileId,
            'session_object_reused' => $sessionObjectReused,
        ]);
    }

    private function rollbackCommittedDeduplicatedCompletionOrFail(
        \PDO $db,
        array $session,
        int $sessionId,
        int $fileId,
        int $storedFileId,
        int $finalSize,
        ?int $userId,
        int $reservationId,
        int $replaceFileId,
        int $currentReplaceStoredFileId,
        ?string $currentReplaceFilename,
        int $currentReplaceSize,
        \Throwable $cleanupError
    ): void {
        $storageDelta = $replaceFileId > 0 ? ($finalSize - $currentReplaceSize) : $finalSize;
        $rolledBackMetadata = $session['metadata'] ?? [];
        $rolledBackMetadata['cleanup_failed_after_commit'] = true;

        try {
            $db->beginTransaction();

            if ($replaceFileId > 0) {
                File::update($replaceFileId, [
                    'stored_file_id' => $currentReplaceStoredFileId,
                    'filename' => $currentReplaceFilename ?? (string)($session['original_filename'] ?? ''),
                ]);

                if ($currentReplaceStoredFileId > 0 && $currentReplaceStoredFileId !== $storedFileId) {
                    StoredFile::incrementRefCount($currentReplaceStoredFileId);
                }
            } else {
                $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
                $stmt->execute([$fileId]);
                if ($stmt->rowCount() !== 1) {
                    throw new Exception('Multipart dedupe rollback could not delete the committed logical file row.');
                }
            }

            if ($storedFileId > 0 && ($replaceFileId <= 0 || $storedFileId !== $currentReplaceStoredFileId)) {
                StoredFile::decrementRefCount($storedFileId);
            }

            if ($userId !== null && $userId > 0 && $storageDelta !== 0) {
                $db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) - ?), storage_warning_sent = 0 WHERE id = ?")
                    ->execute([$storageDelta, $userId]);
            }

            if ($reservationId > 0) {
                QuotaReservation::updateStatus($reservationId, 'released');
            }

            if (!UploadSession::updateIfStatus($sessionId, ['completed'], [
                'status' => 'failed',
                'error_message' => $cleanupError->getMessage(),
                'completed_at' => null,
                'metadata_json' => json_encode($rolledBackMetadata, JSON_UNESCAPED_SLASHES),
            ])) {
                throw new Exception('Multipart dedupe rollback could not restore the upload session state.');
            }

            $db->commit();
        } catch (\Throwable $rollbackError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            \App\Core\Logger::error('Multipart dedupe rollback failed after duplicate cleanup could not be proven', [
                'session_id' => $session['public_id'] ?? null,
                'file_id' => $fileId,
                'stored_file_id' => $storedFileId,
                'replace_file_id' => $replaceFileId,
                'error' => $rollbackError->getMessage(),
                'cleanup_error' => $cleanupError->getMessage(),
            ]);

            throw new Exception('Multipart duplicate cleanup failed after commit and the completed upload could not be rolled back safely.', 0, $cleanupError);
        }

        throw $cleanupError;
    }

    public function abort(array $session): void
    {
        $this->assertSessionAllowsMutation($session, self::ABORTABLE_SESSION_STATUSES, 'This upload session can no longer be aborted.');
        $session = $this->claimSessionForAbort($session);
        $db = Database::getInstance()->getConnection();
        $provider = $this->resolveSessionProviderOrFail($db, $session);
        if (!empty($session['multipart_upload_id'])) {
            $provider->abortMultipartUpload($session['object_key'], (string)$session['multipart_upload_id']);
        }

        $reservation = QuotaReservation::findActiveBySession((int)$session['id']);
        if ($reservation) {
            QuotaReservation::updateStatus((int)$reservation['id'], 'released');
        }

        $this->deleteSessionPartsFailSoft((int)$session['id'], 'multipart abort');
    }

    public function expireStaleSessions(int $limit = 100): array
    {
        $expired = 0;
        $released = 0;

        foreach (UploadSession::findExpiring($limit) as $session) {
            if (!in_array((string)($session['status'] ?? ''), self::ABORTABLE_SESSION_STATUSES, true)) {
                continue;
            }
            try {
                $this->abort($session);
                UploadSession::transitionStatus((int)$session['id'], ['aborted'], 'expired');
                $expired++;
                $released++;
            } catch (\Throwable $e) {
                UploadSession::updateIfStatus((int)$session['id'], self::FAILABLE_SESSION_STATUSES, ['error_message' => $e->getMessage()]);
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

    private function markSessionFailed(int $sessionId, string $errorMessage): void
    {
        $failed = UploadSession::transitionStatus($sessionId, self::FAILABLE_SESSION_STATUSES, 'failed', [
            'error_message' => $errorMessage,
        ]);
        if (!$failed) {
            return;
        }

        $reservation = QuotaReservation::findActiveBySession($sessionId);
        if ($reservation) {
            QuotaReservation::updateStatus((int)$reservation['id'], 'released');
        }

        $this->deleteSessionPartsFailSoft($sessionId, 'multipart failure');
    }

    public function reconcileCompletedChecksums(int $limit = 100): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT candidate.public_id, candidate.checksum_sha256, candidate.stored_file_id
            FROM (
                SELECT us.public_id, us.checksum_sha256, sf.id AS stored_file_id, sf.checksum_verified_at, sf.provider_etag, us.id
                FROM upload_sessions us
                JOIN stored_files sf
                  ON CAST(JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.stored_file_id')) AS UNSIGNED) = sf.id
                WHERE us.status = 'completed'
                  AND us.checksum_sha256 IS NOT NULL

                UNION ALL

                SELECT us.public_id, us.checksum_sha256, sf.id AS stored_file_id, sf.checksum_verified_at, sf.provider_etag, us.id
                FROM upload_sessions us
                JOIN files f ON JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.file_id')) = f.id
                JOIN stored_files sf ON f.stored_file_id = sf.id
                WHERE us.status = 'completed'
                  AND us.checksum_sha256 IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(us.metadata_json, '$.stored_file_id')) IS NULL
            ) AS candidate
            WHERE candidate.checksum_verified_at IS NULL OR candidate.provider_etag IS NULL
            ORDER BY candidate.id ASC
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
        $abortAttempts = 0;
        $abortFailures = 0;
        foreach (UploadSession::findExpiring($limit) as $session) {
            if ((string)($session['status'] ?? '') !== 'completing') {
                continue;
            }

            $uploadId = trim((string)($session['multipart_upload_id'] ?? ''));
            if ($uploadId !== '') {
                $abortAttempts++;
                try {
                    $provider = $this->resolveSessionProviderOrFail(Database::getInstance()->getConnection(), $session);
                    if (!$provider->abortMultipartUpload((string)$session['object_key'], $uploadId)) {
                        $abortFailures++;
                        \App\Core\Logger::warning('Timed-out multipart completion abort returned false during reconciliation', [
                            'session_id' => $session['public_id'] ?? null,
                            'storage_server_id' => $session['storage_server_id'] ?? null,
                            'object_key' => $session['object_key'] ?? null,
                        ]);
                    }
                } catch (\Throwable $abortError) {
                    $abortFailures++;
                    \App\Core\Logger::warning('Timed-out multipart completion abort failed during reconciliation', [
                        'session_id' => $session['public_id'] ?? null,
                        'storage_server_id' => $session['storage_server_id'] ?? null,
                        'object_key' => $session['object_key'] ?? null,
                        'error' => $abortError->getMessage(),
                    ]);
                }
            }

            $this->markSessionFailed((int)$session['id'], 'Completion timed out before metadata could be finalized.');
            $fresh = UploadSession::findById((int)$session['id']);
            if (is_array($fresh) && (string)($fresh['status'] ?? '') === 'failed') {
                $reconciled++;
            }
        }

        return [
            'reconciled_sessions' => $reconciled,
            'abort_attempts' => $abortAttempts,
            'abort_failures' => $abortFailures,
        ];
    }

    private function resolveUploadVisibility(\PDO $db, ?int $userId): int
    {
        if ($userId === null || $userId <= 0) {
            return 1;
        }

        $stmt = $db->prepare("SELECT default_privacy FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $preference = $stmt->fetchColumn();

        return $preference === 'private' ? 0 : 1;
    }

    private function resolveSessionProviderOrFail(\PDO $db, array $session): \App\Interface\StorageProvider
    {
        $storageServerId = isset($session['storage_server_id']) ? (int)$session['storage_server_id'] : 0;
        if ($storageServerId <= 0) {
            throw new Exception('This upload session lost its storage node and can no longer continue. Please start a new upload.');
        }

        return StorageManager::getProviderById($storageServerId, $db);
    }

    private function assertSessionStillFitsCurrentPackage(\PDO $db, array $session, int $finalSize, int $currentReplaceSize = 0): void
    {
        $userId = (int)($session['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $package = Package::getUserPackage($userId);
        if (!$package) {
            throw new Exception('Upload package not found.');
        }

        $maxUploadSize = (int)($package['max_upload_size'] ?? 0);
        if ($maxUploadSize > 0 && $finalSize > $maxUploadSize) {
            throw new Exception('This upload no longer fits your current package limits.');
        }

        $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
        if ($maxStorage <= 0) {
            return;
        }

        $stmt = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);
        $storageUsed = (int)$stmt->fetchColumn();
        $sessionReservedBytes = 0;
        $reservation = QuotaReservation::findActiveBySession((int)($session['id'] ?? 0));
        if (is_array($reservation)) {
            $sessionReservedBytes = (int)($reservation['reserved_bytes'] ?? 0);
        }

        $otherReserved = max(0, QuotaReservation::activeReservedBytesForUser($userId) - $sessionReservedBytes);
        $storageDelta = $finalSize - max(0, $currentReplaceSize);
        $projectedUsage = max(0, $storageUsed + $storageDelta);
        if (($projectedUsage + $otherReserved) > $maxStorage) {
            throw new Exception('This upload no longer fits your current package limits.');
        }
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

    private function assertAllowedExtension(string $filename, array $package = []): void
    {
        $allowedSetting = Setting::get('upload_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4,mp3,ipa,apk');
        $allowedExtensions = array_values(array_filter(array_map('trim', explode(',', strtolower($allowedSetting)))));
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === '' || in_array($ext, self::DANGEROUS_UPLOAD_EXTENSIONS, true) || !in_array($ext, $allowedExtensions, true)) {
            $allowedStr = implode(', ', $allowedExtensions);
            throw new Exception("Security Error: file type (.$ext) is not allowed. Allowed extensions are: [$allowedStr]. Check your Settings.");
        }

        $packageSetting = trim((string)($package['accepted_file_types'] ?? ''));
        if ($packageSetting === '') {
            return;
        }

        $packageAllowed = array_values(array_filter(array_map('trim', explode(',', strtolower($packageSetting)))));
        if ($packageAllowed === []) {
            throw new Exception('This package does not currently allow any upload file types.');
        }

        if (!in_array($ext, $packageAllowed, true)) {
            $allowedStr = implode(', ', $packageAllowed);
            throw new Exception("Security Error: file type (.$ext) is not allowed for this package. Allowed package extensions are: [$allowedStr].");
        }
    }

    private function validateCompletedObject(object $provider, array $session, int $finalSize, string $mimeType, ?string $checksumSha256): string
    {
        $expectedSize = (int)($session['expected_size'] ?? 0);
        if ($expectedSize <= 0 || $finalSize <= 0 || $finalSize !== $expectedSize) {
            throw new Exception('Completed upload size did not match the requested upload size.');
        }

        $package = !empty($session['user_id'])
            ? Package::getUserPackage((int)$session['user_id'])
            : Package::getGuestPackage();
        $this->assertAllowedExtension((string)($session['original_filename'] ?? ''), $package ?: []);

        $objectExt = strtolower((string)pathinfo((string)($session['object_key'] ?? ''), PATHINFO_EXTENSION));
        if ($objectExt === '' || in_array($objectExt, self::DANGEROUS_UPLOAD_EXTENSIONS, true)) {
            throw new Exception('Completed upload object key has an unsafe extension.');
        }

        $mimeType = trim($mimeType);
        if ($mimeType === '' || preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*(?:\s*;\s*[^;\r\n]+)*$/i', $mimeType) !== 1) {
            $mimeType = 'application/octet-stream';
        }

        $absolutePath = method_exists($provider, 'getAbsolutePath')
            ? (string)$provider->getAbsolutePath((string)$session['object_key'])
            : '';

        if ($absolutePath !== '' && is_file($absolutePath)) {
            $actualSize = filesize($absolutePath);
            if ($actualSize === false || (int)$actualSize !== $expectedSize) {
                throw new Exception('Completed upload local file size did not match the expected size.');
            }

            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detected = finfo_file($finfo, $absolutePath);
                    finfo_close($finfo);
                    if (is_string($detected) && $detected !== '') {
                        $mimeType = $detected;
                    }
                }
            }
        }

        if ($checksumSha256 !== null) {
            $actualHash = $this->hashCompletedObject($provider, (string)$session['object_key'], $absolutePath);
            if (!is_string($actualHash) || !hash_equals($checksumSha256, $actualHash)) {
                throw new Exception('Completed upload checksum did not match the supplied checksum.');
            }
        }

        return $mimeType;
    }

    private function hashCompletedObject(object $provider, string $objectKey, string $absolutePath): ?string
    {
        if ($absolutePath !== '' && is_file($absolutePath)) {
            $hash = hash_file('sha256', $absolutePath);
            return is_string($hash) ? $hash : null;
        }

        if (!method_exists($provider, 'stream')) {
            return null;
        }

        $context = hash_init('sha256');
        $bufferLevel = ob_get_level();
        $started = ob_start(static function (string $chunk) use ($context): string {
            if ($chunk !== '') {
                hash_update($context, $chunk);
            }
            return '';
        }, 1024 * 1024);

        if (!$started) {
            return null;
        }

        try {
            $provider->stream($objectKey);
            while (ob_get_level() > $bufferLevel) {
                ob_end_flush();
            }
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        }

        return hash_final($context);
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

    private function refreshSessionLease(int $sessionId, ?array $allowedStatuses = null): bool
    {
        $expiresAt = $this->nextExpiry();
        $refreshed = UploadSession::updateIfStatus($sessionId, $allowedStatuses ?? array_merge(self::ACTIVE_SESSION_STATUSES, ['completing']), [
            'expires_at' => $expiresAt,
        ]);
        if (!$refreshed) {
            return false;
        }
        QuotaReservation::refreshExpiryBySession($sessionId, $expiresAt);
        return true;
    }

    private function refreshReadOnlySessionLeaseIfNeeded(array &$session): void
    {
        $expiresAt = trim((string)($session['expires_at'] ?? ''));
        $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
        $refreshThreshold = time() + intdiv(self::SESSION_TTL_SECONDS, 2);

        if ($expiresTimestamp !== false && $expiresTimestamp >= $refreshThreshold) {
            return;
        }

        if ($this->refreshSessionLease((int)($session['id'] ?? 0))) {
            $session['expires_at'] = $this->nextExpiry();
        }
    }

    private function sessionStatusAllowsLeaseRefresh(string $status): bool
    {
        return in_array($status, array_merge(self::ACTIVE_SESSION_STATUSES, ['completing']), true);
    }

    private function assertSessionAllowsMutation(array $session, array $allowedStatuses, string $message): void
    {
        $status = (string)($session['status'] ?? '');
        if (!in_array($status, $allowedStatuses, true)) {
            throw new Exception($message);
        }
    }

    private function normalizeChecksum(?string $checksum): ?string
    {
        $checksum = strtolower(trim((string)$checksum));
        if ($checksum === '' || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            return null;
        }

        return $checksum;
    }

    private function findCanonicalDeduplicationCandidate(?string $checksumSha256, int $expectedSize): ?array
    {
        return (new DeduplicationService())->findReusableStoredFile(
            $checksumSha256,
            $expectedSize,
            null,
            false
        );
    }

    private function maxPartCountForSession(array $session): int
    {
        $expectedSize = (int)($session['expected_size'] ?? 0);
        $partSizeBytes = (int)($session['part_size_bytes'] ?? 0);
        if ($expectedSize <= 0 || $partSizeBytes <= 0) {
            throw new Exception('Invalid part number.');
        }

        return max(1, (int)ceil($expectedSize / $partSizeBytes));
    }

    private function expectedPartSizeForSession(array $session, int $partNumber): int
    {
        if ($partNumber <= 0 || $partNumber > 10000) {
            throw new Exception('Invalid part number.');
        }

        $expectedSize = (int)($session['expected_size'] ?? 0);
        $partSizeBytes = (int)($session['part_size_bytes'] ?? 0);
        $maxPartCount = $this->maxPartCountForSession($session);
        if ($partNumber > $maxPartCount) {
            throw new Exception('Invalid part number.');
        }

        if ($partNumber < $maxPartCount) {
            return $partSizeBytes;
        }

        $consumedByPriorParts = ($maxPartCount - 1) * $partSizeBytes;
        $remaining = $expectedSize - $consumedByPriorParts;
        if ($remaining <= 0) {
            throw new Exception('Invalid part number.');
        }

        return $remaining;
    }

    private function eligibleMultipartCompletionParts(int $sessionId): array
    {
        $parts = UploadSession::getUploadedPartsForCompletion($sessionId);
        usort($parts, static fn(array $a, array $b): int => (int)$a['part_number'] <=> (int)$b['part_number']);
        return $parts;
    }

    private function shouldEnforceMultipartBatchVerification(object $provider): bool
    {
        if (Setting::get('upload_batch_verification_enabled', '1') !== '1') {
            return false;
        }

        $capabilities = method_exists($provider, 'getCapabilities') ? $provider->getCapabilities() : [];
        $isAppRoutedLocal = !empty($capabilities['app_part_upload']) && empty($capabilities['presigned_part_upload']);
        if ($isAppRoutedLocal) {
            return Setting::get('upload_batch_verification_local_enabled', '1') === '1';
        }

        if (!empty($capabilities['presigned_part_upload'])) {
            return Setting::get('upload_batch_verification_s3_enabled', '1') === '1';
        }

        return false;
    }

    private function shouldShadowMultipartBatchVerification(object $provider): bool
    {
        if (Setting::get('upload_batch_verification_shadow_mode', '0') !== '1') {
            return false;
        }

        $capabilities = method_exists($provider, 'getCapabilities') ? $provider->getCapabilities() : [];
        return !empty($capabilities['multipart']);
    }

    private function verifyMultipartPartsAgainstProvider(array $session, object $provider, array $eligibleParts): array
    {
        $uploadId = trim((string)($session['multipart_upload_id'] ?? ''));
        if ($uploadId === '') {
            throw new Exception('Multipart upload ID is missing for batch verification.');
        }

        if ($eligibleParts === []) {
            throw new Exception('No uploaded parts were reported for batch verification.');
        }

        $providerParts = $this->listMultipartPartsForVerification($provider, (string)$session['object_key'], $uploadId);
        if ($providerParts === []) {
            throw new Exception('Provider returned no multipart parts for batch verification.');
        }

        $providerByNumber = [];
        foreach ($providerParts as $index => $part) {
            $normalized = $this->normalizeProviderMultipartPartRecord($part, (int)$index);
            $partNumber = $normalized['part_number'];
            if (isset($providerByNumber[$partNumber])) {
                throw new Exception('Provider returned duplicate multipart metadata for part ' . $partNumber . '.');
            }
            $providerByNumber[$partNumber] = $normalized;
        }

        $assemblyParts = [];
        foreach ($eligibleParts as $dbPart) {
            $expected = $this->normalizeDbMultipartPartRecord($dbPart);
            $partNumber = $expected['part_number'];
            if (!isset($providerByNumber[$partNumber])) {
                throw new Exception('Provider multipart part ' . $partNumber . ' is missing.');
            }

            $providerPart = $providerByNumber[$partNumber];
            if ($providerPart['etag'] === '') {
                throw new Exception('Provider multipart part ' . $partNumber . ' has an empty ETag.');
            }

            $expectedEtag = $this->normalizeMultipartEtagForComparison($expected['etag'], $provider);
            $providerEtag = $this->normalizeMultipartEtagForComparison($providerPart['etag'], $provider);
            if ($expectedEtag === '' || $providerEtag === '' || !hash_equals($expectedEtag, $providerEtag)) {
                throw new Exception('Provider multipart part ' . $partNumber . ' ETag mismatch.');
            }

            if ($providerPart['size'] !== $expected['size']) {
                throw new Exception('Provider multipart part ' . $partNumber . ' size mismatch.');
            }

            $assemblyParts[] = [
                'part_number' => $partNumber,
                'etag' => $providerPart['etag'],
                'size' => $providerPart['size'],
            ];
            unset($providerByNumber[$partNumber]);
        }

        usort($assemblyParts, static fn(array $a, array $b): int => (int)$a['part_number'] <=> (int)$b['part_number']);
        ksort($providerByNumber);

        $extraPartSample = [];
        foreach ($providerByNumber as $extraPart) {
            if (count($extraPartSample) >= self::MULTIPART_EXTRA_PART_SAMPLE_LIMIT) {
                break;
            }
            $extraPartSample[] = [
                'part_number' => $extraPart['part_number'],
                'size' => $extraPart['size'],
                'etag' => $extraPart['etag'],
            ];
        }

        return [
            'assembly_parts' => $assemblyParts,
            'extra_part_count' => count($providerByNumber),
            'extra_part_sample' => $extraPartSample,
        ];
    }

    private function listMultipartPartsForVerification(object $provider, string $objectKey, string $uploadId): array
    {
        if (method_exists($provider, 'listMultipartPartsPage')) {
            $parts = [];
            $cursor = null;
            $seenCursors = [];
            for ($guard = 0; $guard < self::MAX_MULTIPART_PART_NUMBER; $guard++) {
                $page = $provider->listMultipartPartsPage($objectKey, $uploadId, $cursor);
                if (!is_array($page) || !isset($page['parts']) || !is_array($page['parts'])) {
                    throw new Exception('Provider returned malformed multipart listing page.');
                }
                array_push($parts, ...$page['parts']);

                $nextCursor = $page['next_cursor'] ?? null;
                if ($nextCursor === null || $nextCursor === '') {
                    return $parts;
                }

                $nextCursor = (string)$nextCursor;
                if ($nextCursor === (string)$cursor || isset($seenCursors[$nextCursor])) {
                    throw new Exception('Provider multipart listing cursor did not advance.');
                }

                $seenCursors[$nextCursor] = true;
                $cursor = $nextCursor;
            }

            throw new Exception('Provider multipart listing exceeded the pagination guard.');
        }

        if (!method_exists($provider, 'listMultipartParts')) {
            throw new Exception('Provider does not support multipart part listing.');
        }

        $parts = $provider->listMultipartParts($objectKey, $uploadId);
        if (!is_array($parts)) {
            throw new Exception('Provider returned malformed multipart listing data.');
        }

        if (isset($parts['parts']) && is_array($parts['parts'])) {
            return $parts['parts'];
        }

        return $parts;
    }

    private function normalizeProviderMultipartPartRecord(mixed $part, int $index): array
    {
        if (!is_array($part)) {
            throw new Exception('Provider returned malformed multipart part record at index ' . $index . '.');
        }

        if (!array_key_exists('part_number', $part) || !array_key_exists('etag', $part) || !array_key_exists('size', $part)) {
            throw new Exception('Provider multipart part record is missing required fields at index ' . $index . '.');
        }

        if (!is_numeric($part['part_number']) || !is_numeric($part['size'])) {
            throw new Exception('Provider multipart part record has invalid types at index ' . $index . '.');
        }

        $partNumber = (int)$part['part_number'];
        $size = (int)$part['size'];
        if ($partNumber < 1 || $partNumber > self::MAX_MULTIPART_PART_NUMBER || $size < 0) {
            throw new Exception('Provider multipart part record is out of range at index ' . $index . '.');
        }

        return [
            'part_number' => $partNumber,
            'etag' => $this->normalizeMultipartEtagForStorage((string)$part['etag']),
            'size' => $size,
        ];
    }

    private function normalizeDbMultipartPartRecord(array $part): array
    {
        if ((string)($part['status'] ?? '') !== 'uploaded') {
            throw new Exception('DB multipart part ' . (int)($part['part_number'] ?? 0) . ' is not eligible for completion.');
        }

        $partNumber = (int)($part['part_number'] ?? 0);
        $etag = $this->normalizeMultipartEtagForStorage((string)($part['etag'] ?? ''));
        $size = (int)($part['part_size'] ?? 0);
        if ($partNumber < 1 || $partNumber > self::MAX_MULTIPART_PART_NUMBER || $etag === '' || $size < 0) {
            throw new Exception('DB multipart part ' . $partNumber . ' is malformed for completion.');
        }

        return [
            'part_number' => $partNumber,
            'etag' => $etag,
            'size' => $size,
        ];
    }

    private function normalizeMultipartEtagForStorage(string $etag): string
    {
        return trim(trim($etag), '"');
    }

    private function normalizeMultipartEtagForComparison(string $etag, object $provider): string
    {
        $etag = $this->normalizeMultipartEtagForStorage($etag);
        $capabilities = method_exists($provider, 'getCapabilities') ? $provider->getCapabilities() : [];
        if (!empty($capabilities['multipart_etag_case_insensitive'])) {
            return strtolower($etag);
        }

        return $etag;
    }

    private function logMultipartBatchVerificationResult(array $session, object $provider, array $result, bool $enforced): void
    {
        $extraPartCount = (int)($result['extra_part_count'] ?? 0);
        if ($extraPartCount <= 0 && !$this->shouldShadowMultipartBatchVerification($provider)) {
            return;
        }

        \App\Core\Logger::info('Multipart batch verification completed', [
            'session_id' => $session['public_id'] ?? null,
            'storage_server_id' => $session['storage_server_id'] ?? null,
            'object_key' => $session['object_key'] ?? null,
            'enforced' => $enforced,
            'extra_part_count' => $extraPartCount,
            'extra_part_sample' => $result['extra_part_sample'] ?? [],
        ]);
    }

    private function failMultipartSession(array $session, string $message, ?object $provider = null): void
    {
        try {
            $provider = $provider ?? $this->resolveSessionProviderOrFail(Database::getInstance()->getConnection(), $session);
            if (!empty($session['multipart_upload_id'])) {
                if (!$provider->abortMultipartUpload((string)$session['object_key'], (string)$session['multipart_upload_id'])) {
                    \App\Core\Logger::warning('Multipart cleanup abort returned false while failing session', [
                        'session_id' => $session['public_id'] ?? null,
                        'storage_server_id' => $session['storage_server_id'] ?? null,
                        'object_key' => $session['object_key'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable $cleanupError) {
            \App\Core\Logger::warning('Multipart cleanup abort failed while failing session', [
                'session_id' => $session['public_id'] ?? null,
                'storage_server_id' => $session['storage_server_id'] ?? null,
                'object_key' => $session['object_key'] ?? null,
                'error' => $cleanupError->getMessage(),
            ]);
        }

        $this->markSessionFailed((int)($session['id'] ?? 0), $message);
    }

    private function deleteSessionPartsFailSoft(int $sessionId, string $reason): void
    {
        if ($sessionId <= 0) {
            return;
        }

        try {
            UploadSession::deleteParts($sessionId);
        } catch (\Throwable $cleanupError) {
            \App\Core\Logger::warning('Multipart session part rows could not be cleaned up', [
                'session_id' => $sessionId,
                'reason' => $reason,
                'error' => $cleanupError->getMessage(),
            ]);
        }
    }

    private function lockReplaceTarget(\PDO $db, int $replaceFileId, int $userId): array
    {
        if ($replaceFileId <= 0 || $userId <= 0) {
            throw new Exception('You can only replace your own active files.');
        }

        $stmt = $db->prepare("
            SELECT f.id, f.user_id, f.status, f.stored_file_id, f.filename, sf.file_size
            FROM files f
            JOIN stored_files sf ON f.stored_file_id = sf.id
            WHERE f.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$replaceFileId]);
        $replaceTarget = $stmt->fetch() ?: null;

        if (!$replaceTarget || (int)($replaceTarget['user_id'] ?? 0) !== $userId || (string)($replaceTarget['status'] ?? '') !== 'active') {
            throw new Exception('You can only replace your own active files.');
        }

        if (isset($replaceTarget['filename']) && is_string($replaceTarget['filename']) && str_starts_with($replaceTarget['filename'], 'ENC:')) {
            $replaceTarget['filename'] = \App\Service\EncryptionService::decrypt($replaceTarget['filename']);
        }

        return $replaceTarget;
    }

    private function buildUploadAdmissionActorLockKey(?int $userId, ?string $guestSessionId): string
    {
        if ($userId !== null && $userId > 0) {
            return 'user:' . $userId;
        }

        $guestSessionId = trim((string)$guestSessionId);
        if ($guestSessionId === '') {
            throw new Exception('Guest upload session could not be established.');
        }

        return 'guest:' . $guestSessionId;
    }

    private function acquireUploadAdmissionLock(\PDO $db, string $actorKey): bool
    {
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute(['fyuhls_upload_admission_' . hash('sha256', $actorKey)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseUploadAdmissionLock(\PDO $db, string $actorKey): void
    {
        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_upload_admission_' . hash('sha256', $actorKey)]);
        } catch (\Throwable $e) {
        }
    }

    private function buildReplaceAdmissionLockKey(int $replaceFileId): string
    {
        return 'replace:' . $replaceFileId;
    }

    private function acquireReplaceAdmissionLock(\PDO $db, string $replaceLockKey): bool
    {
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute(['fyuhls_upload_replace_admission_' . hash('sha256', $replaceLockKey)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseReplaceAdmissionLock(\PDO $db, string $replaceLockKey): void
    {
        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_upload_replace_admission_' . hash('sha256', $replaceLockKey)]);
        } catch (\Throwable $e) {
        }
    }

    private function hasActiveReplacementSession(int $replaceFileId, int $userId): bool
    {
        if ($replaceFileId <= 0 || $userId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT 1
            FROM upload_sessions
            WHERE user_id = ?
              AND status IN ('pending', 'uploading', 'completing', 'processing')
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.replace_file_id')) AS UNSIGNED) = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $replaceFileId]);
        return (bool)$stmt->fetchColumn();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        SchemaService::ensureTables(['stored_files', 'upload_sessions', 'upload_session_parts', 'quota_reservations'], false);
        self::$schemaReady = true;
    }

    private function acquireUserStorageQuotaLock(\PDO $db, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseUserStorageQuotaLock(\PDO $db, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        } catch (\Throwable $e) {
        }
    }
}
