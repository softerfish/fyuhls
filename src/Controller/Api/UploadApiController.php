<?php

namespace App\Controller\Api;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\StorageManager;
use App\Model\File;
use App\Model\Setting;
use App\Service\ApiAuthService;
use App\Service\ApiIdempotencyService;
use App\Service\DownloadManager;
use App\Service\MultipartUploadService;
use RuntimeException;

class UploadApiController
{
    private MultipartUploadService $service;
    private ApiAuthService $apiAuth;
    private ApiIdempotencyService $idempotency;

    public function __construct()
    {
        $this->service = new MultipartUploadService();
        $this->apiAuth = new ApiAuthService();
        $this->idempotency = new ApiIdempotencyService();
    }

    private function reportUploadFailure(string $action, \Throwable $e, int $status = 422): void
    {
        Logger::error('Upload API request failed', [
            'action' => $action,
            'error' => $e->getMessage(),
        ]);
        $this->jsonResponse([
            'error' => $this->userFacingUploadError($e),
        ], $this->uploadFailureStatus($e, $status));
    }

    private function userFacingUploadError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'The upload request could not be completed.';
        }

        if (str_starts_with($message, 'Security Error: file type (.') && str_contains($message, 'Allowed extensions are: [')) {
            return $message;
        }

        $safeMessages = [
            'A filename is required.',
            'Upload size must be greater than zero.',
            'User not found.',
            'Upload package not found.',
            'You already have the maximum number of active uploads for your package.',
            'File exceeds your package upload limit.',
            'Guests cannot upload into private folders.',
            'Folder not found.',
            'File replacement is currently disabled.',
            'File replacement requires a signed-in account.',
            'Replacement target not found.',
            'You can only replace your own active files.',
            'This file already has a replacement upload in progress.',
            'The selected storage backend does not support direct multipart uploads yet.',
            'This upload would exceed your storage quota.',
            'The selected storage node does not have enough free capacity.',
            'Could not open multipart upload.',
            'Invalid part number.',
            'This upload session can no longer accept part signing requests.',
            'This upload session can no longer accept uploaded parts.',
            'This upload session can no longer be completed.',
            'This upload session can no longer be aborted.',
            'No uploaded parts were reported for this session.',
            'Multipart completion failed.',
            'Uploaded part exceeds the allowed size for this upload session.',
            'Uploaded part metadata did not match storage provider state.',
            StorageManager::MISSING_FILE_SERVER_MESSAGE,
        ];

        if (in_array($message, $safeMessages, true)) {
            return $message;
        }

        return 'The upload request could not be completed.';
    }

    private function uploadFailureStatus(\Throwable $e, int $defaultStatus): int
    {
        if (trim($e->getMessage()) === StorageManager::MISSING_FILE_SERVER_MESSAGE) {
            return 503;
        }

        return $defaultStatus;
    }

    private function ensureChunkedUploadsEnabled(): void
    {
        if (Setting::get('upload_chunking_enabled', '1') !== '1') {
            $this->jsonResponse(['error' => 'Chunked browser uploads are currently disabled by the administrator.'], 503);
        }
    }

    public function createSession()
    {
        $this->ensureChunkedUploadsEnabled();
        $context = $this->resolveApiContext(true, 'files.upload', true);
        $userId = $context['user_id'];
        $guestSessionId = $context['guest_session_id'];
        $payload = $this->jsonBody();
        $this->apiAuth->enforceRateLimit($context, 'api_upload_create_session', 60, 60);
        try {
            $idempotency = $this->idempotency->begin(
                $this->idempotencyKey(),
                'upload.create_session',
                $this->actorKey($context),
                $userId,
                $context['api_token']['id'] ?? null,
                $payload
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === ApiIdempotencyService::STORAGE_UNAVAILABLE_MESSAGE) {
                $this->jsonResponse(['error' => $e->getMessage()], 503);
            }
            throw $e;
        }
        if (!empty($idempotency['replay'])) {
            $this->jsonResponse($idempotency['payload'], (int)$idempotency['status_code']);
        }
        if (!empty($idempotency['pending'])) {
            $this->jsonResponse(['error' => 'This idempotent request is still being processed. Retry shortly.'], 409);
        }

        try {
            $session = $this->service->createSession(
                $userId,
                (string)($payload['filename'] ?? ''),
                (int)($payload['size'] ?? 0),
                isset($payload['folder_id']) ? (int)$payload['folder_id'] : null,
                isset($payload['mime_type']) ? (string)$payload['mime_type'] : null,
                $guestSessionId,
                isset($payload['checksum_sha256']) ? (string)$payload['checksum_sha256'] : null,
                isset($payload['replace_file_id']) ? (int)$payload['replace_file_id'] : null
            );

            $response = [
                'status' => 'ok',
                'session' => $session['session'],
                'part_size_bytes' => $session['part_size_bytes'],
                'expires_at' => $session['expires_at'],
                'capabilities' => $session['capabilities'],
            ];
            $this->idempotency->complete($idempotency, 201, $response);
            $this->jsonResponse($response, 201);
        } catch (\Throwable $e) {
            $this->idempotency->release($idempotency);
            $this->reportUploadFailure('create_session', $e);
        }
    }

    public function createManagedUpload()
    {
        $this->ensureChunkedUploadsEnabled();
        $context = $this->resolveApiContext(true, 'files.upload', true);
        $payload = $this->jsonBody();
        $this->apiAuth->enforceRateLimit($context, 'api_upload_managed_create', 30, 60);
        try {
            $idempotency = $this->idempotency->begin(
                $this->idempotencyKey(),
                'upload.create_managed',
                $this->actorKey($context),
                $context['user_id'],
                $context['api_token']['id'] ?? null,
                $payload
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === ApiIdempotencyService::STORAGE_UNAVAILABLE_MESSAGE) {
                $this->jsonResponse(['error' => $e->getMessage()], 503);
            }
            throw $e;
        }
        if (!empty($idempotency['replay'])) {
            $replayed = $idempotency['payload'];
            if (!empty($replayed['session']['public_id'])) {
                $session = $this->service->getSessionForActor((string)$replayed['session']['public_id'], $context['user_id'], $context['guest_session_id']);
                if ($session && !in_array($session['status'], ['completed', 'failed', 'aborted', 'expired'], true)) {
                    $partNumbers = array_values(array_unique(array_map('intval', $payload['part_numbers'] ?? [1])));
                    $replayed['parts'] = $this->service->signParts($session, $partNumbers, (int)($payload['expires_in'] ?? 3600));
                }
            }
            $this->jsonResponse($replayed, (int)$idempotency['status_code']);
        }
        if (!empty($idempotency['pending'])) {
            $this->jsonResponse(['error' => 'This idempotent request is still being processed. Retry shortly.'], 409);
        }

        $partNumbers = array_values(array_unique(array_map('intval', $payload['part_numbers'] ?? [1])));
        if (empty($partNumbers)) {
            $partNumbers = [1];
        }

        try {
            $session = $this->service->createSession(
                $context['user_id'],
                (string)($payload['filename'] ?? ''),
                (int)($payload['size'] ?? 0),
                isset($payload['folder_id']) ? (int)$payload['folder_id'] : null,
                isset($payload['mime_type']) ? (string)$payload['mime_type'] : null,
                $context['guest_session_id'],
                isset($payload['checksum_sha256']) ? (string)$payload['checksum_sha256'] : null,
                isset($payload['replace_file_id']) ? (int)$payload['replace_file_id'] : null
            );
            $signed = $this->service->signParts($session['session'], $partNumbers, (int)($payload['expires_in'] ?? 3600));
            $response = [
                'status' => 'ok',
                'session' => $session['session'],
                'part_size_bytes' => $session['part_size_bytes'],
                'parts' => $signed,
                'complete_url' => '/api/v1/uploads/sessions/' . rawurlencode($session['session']['public_id']) . '/complete',
                'report_part_url' => '/api/v1/uploads/sessions/' . rawurlencode($session['session']['public_id']) . '/parts/report',
            ];
            $this->idempotency->complete($idempotency, 201, $response);
            $this->jsonResponse($response, 201);
        } catch (\Throwable $e) {
            $this->idempotency->release($idempotency);
            $this->reportUploadFailure('create_managed', $e);
        }
    }

    public function showSession(string $sessionId)
    {
        $context = $this->resolveApiContext(true, 'files.upload', false);
        [$userId, $guestSessionId] = [$context['user_id'], $context['guest_session_id']];
        $session = $this->service->getSessionForActor($sessionId, $userId, $guestSessionId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Upload session not found.'], 404);
        }

        $this->jsonResponse(['status' => 'ok', 'session' => $session]);
    }

    public function signParts(string $sessionId)
    {
        $context = $this->resolveApiContext(true, 'files.upload', true);
        [$userId, $guestSessionId] = [$context['user_id'], $context['guest_session_id']];
        $session = $this->service->getSessionForActor($sessionId, $userId, $guestSessionId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Upload session not found.'], 404);
        }

        $payload = $this->jsonBody();
        $partNumbers = array_values(array_unique(array_map('intval', $payload['part_numbers'] ?? [])));
        if (empty($partNumbers)) {
            $this->jsonResponse(['error' => 'At least one part number is required.'], 422);
        }

        try {
            $urls = $this->service->signParts($session, $partNumbers, (int)($payload['expires_in'] ?? 3600));
            $this->jsonResponse(['status' => 'ok', 'parts' => $urls]);
        } catch (\Throwable $e) {
            $this->reportUploadFailure('sign_parts', $e);
        }
    }

    public function reportPart(string $sessionId)
    {
        $context = $this->resolveApiContext(true, 'files.upload', true);
        [$userId, $guestSessionId] = [$context['user_id'], $context['guest_session_id']];
        $session = $this->service->getSessionForActor($sessionId, $userId, $guestSessionId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Upload session not found.'], 404);
        }

        $payload = $this->jsonBody();
        try {
            $fresh = $this->service->reportPart(
                $session,
                (int)($payload['part_number'] ?? 0),
                (string)($payload['etag'] ?? ''),
                (int)($payload['part_size'] ?? 0),
                isset($payload['checksum_sha256']) ? (string)$payload['checksum_sha256'] : null
            );
            $this->jsonResponse(['status' => 'ok', 'session' => $fresh]);
        } catch (\Throwable $e) {
            $this->reportUploadFailure('report_part', $e);
        }
    }

    public function uploadPartBinary(string $sessionId, string $partNumber)
    {
        $context = $this->resolveApiContext(true, 'files.upload', true);
        $this->apiAuth->enforceRateLimit($context, 'api_upload_part_binary', 240, 60);
        $session = $this->service->getSessionForActor($sessionId, $context['user_id'], $context['guest_session_id']);
        if (!$session) {
            $this->jsonResponse(['error' => 'Upload session not found.'], 404);
        }

        $partNumber = (int)$partNumber;
        if ($partNumber <= 0 || $partNumber > 10000) {
            $this->jsonResponse(['error' => 'Invalid part number.'], 422);
        }

        $input = fopen('php://input', 'rb');
        if (!$input) {
            $this->jsonResponse(['error' => 'Could not read upload body.'], 500);
        }

        try {
            $result = $this->service->writeUploadedPart($session, $partNumber, $input);
        } catch (\Throwable $e) {
            fclose($input);
            $this->reportUploadFailure('upload_part_binary', $e);
            return;
        }
        fclose($input);

        $etag = (string)($result['etag'] ?? '');
        $size = (int)($result['part_size'] ?? 0);
        if ($etag === '') {
            $this->jsonResponse(['error' => 'Local part upload did not return an ETag.'], 500);
        }

        header('Content-Type: application/json');
        header('ETag: "' . $etag . '"');
        echo json_encode([
            'status' => 'ok',
            'etag' => $etag,
            'part_size' => $size,
        ]);
        exit;
    }

    public function complete(string $sessionId)
    {
        $context = $this->resolveApiContext(true, 'files.upload', true);
        [$userId, $guestSessionId] = [$context['user_id'], $context['guest_session_id']];
        $payload = $this->jsonBody();
        $this->apiAuth->enforceRateLimit($context, 'api_upload_complete', 60, 60);
        try {
            $idempotency = $this->idempotency->begin(
                $this->idempotencyKey(),
                'upload.complete.' . $sessionId,
                $this->actorKey($context),
                $userId,
                $context['api_token']['id'] ?? null,
                $payload
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === ApiIdempotencyService::STORAGE_UNAVAILABLE_MESSAGE) {
                $this->jsonResponse(['error' => $e->getMessage()], 503);
            }
            throw $e;
        }
        if (!empty($idempotency['replay'])) {
            $this->jsonResponse($idempotency['payload'], (int)$idempotency['status_code']);
        }
        if (!empty($idempotency['pending'])) {
            $this->jsonResponse(['error' => 'This idempotent request is still being processed. Retry shortly.'], 409);
        }
        $session = $this->service->getSessionForActor($sessionId, $userId, $guestSessionId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Upload session not found.'], 404);
        }

        try {
            $result = $this->service->complete($session, isset($payload['checksum_sha256']) ? (string)$payload['checksum_sha256'] : null);
            $response = ['status' => 'ok'] + $result;
            $this->idempotency->complete($idempotency, 201, $response);
            $this->jsonResponse($response, 201);
        } catch (\Throwable $e) {
            $this->idempotency->release($idempotency);
            $this->reportUploadFailure('complete', $e);
        }
    }

    public function abort(string $sessionId)
    {
        $context = $this->resolveApiContext(true, 'files.upload', true);
        [$userId, $guestSessionId] = [$context['user_id'], $context['guest_session_id']];
        $this->apiAuth->enforceRateLimit($context, 'api_upload_abort', 60, 60);
        $session = $this->service->getSessionForActor($sessionId, $userId, $guestSessionId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Upload session not found.'], 404);
        }

        try {
            $this->service->abort($session);
            $this->jsonResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            $this->reportUploadFailure('abort', $e);
        }
    }

    public function downloadLink(string $fileId)
    {
        $context = $this->resolveApiContext(false, 'files.read', false);
        $this->apiAuth->enforceRateLimit($context, 'api_download_link', 60, 60);
        $userId = $context['user_id'] ?? $this->requireUser();

        $file = $this->ownedFileForActor($fileId, (int)$userId, false, false);
        if (!$file) {
            $this->jsonResponse(['error' => 'File not found.'], 404);
        }

        $downloadManager = new DownloadManager();
        try {
            $delivery = $downloadManager->previewDelivery($file);
            $downloadUrl = $downloadManager->issueNormalDownloadLink((string)($file['short_id'] ?? $file['id']), $file['filename']);
        } catch (\RuntimeException $e) {
            if (
                $e->getMessage() === DownloadManager::DOWNLOAD_LINK_TRACKING_UNAVAILABLE_MESSAGE
                || $e->getMessage() === StorageManager::MISSING_FILE_SERVER_MESSAGE
            ) {
                $this->jsonResponse(['error' => $e->getMessage()], 503);
            }
            throw $e;
        }

        $this->jsonResponse([
            'status' => 'ok',
            'url' => $downloadUrl,
            'expires_in' => 3600,
            'delivery' => $delivery['mode'],
            'delivery_reason' => $delivery['reason'],
        ]);
    }

    public function fileInfo(string $fileId)
    {
        $context = $this->resolveApiContext(false, 'files.read', false);
        $file = $this->ownedFileForActor($fileId, (int)($context['user_id'] ?? 0), true, false);
        if (!$file) {
            $this->jsonResponse(['error' => 'File not found.'], 404);
        }

        $this->jsonResponse([
            'status' => 'ok',
            'file' => [
                'id' => $file['id'],
                'short_id' => $file['short_id'],
                'filename' => $file['filename'],
                'status' => $file['status'],
                'file_size' => $file['file_size'],
                'mime_type' => $file['mime_type'],
                'is_public' => (int)$file['is_public'],
                'folder_id' => $file['folder_id'],
                'downloads' => $file['downloads'],
                'created_at' => $file['created_at'],
            ],
        ]);
    }

    private function requireUser(): int
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Authentication required.'], 401);
        }

        return (int)Auth::id();
    }

    private function ownedFileForActor(string $fileId, int $userId, bool $includeDeleted = false, bool $halt = true): ?array
    {
        $file = $includeDeleted ? File::findAnyStatus($fileId) : File::find($fileId);
        if ($file && (int)($file['user_id'] ?? 0) === $userId) {
            return $file;
        }

        if ($halt) {
            $this->jsonResponse(['error' => 'File not found.'], 404);
        }

        return null;
    }

    private function resolveUploadActor(): array
    {
        if (Auth::check()) {
            return [(int)Auth::id(), null];
        }

        if (Setting::get('upload_login_required', '0') === '1') {
            $this->jsonResponse(['error' => 'Authentication required.'], 401);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $guestSessionId = session_id();
        if ($guestSessionId === '') {
            $this->jsonResponse(['error' => 'Guest upload session could not be established.'], 500);
        }

        return [null, $guestSessionId];
    }

    private function resolveApiContext(bool $allowGuestUploadFallback = false, ?string $requiredScope = null, bool $requireSessionCsrf = true): array
    {
        try {
            $context = $this->apiAuth->resolveRequestContext();
        } catch (\RuntimeException $e) {
            Logger::warning('Upload API authentication failed', ['error' => $e->getMessage()]);
            $this->jsonResponse(['error' => 'Authentication failed.'], 401);
        }

        if (($context['mode'] ?? 'session') === 'token') {
            if ($requiredScope !== null) {
                try {
                    $this->apiAuth->requireScope($context, $requiredScope);
                } catch (\RuntimeException $e) {
                    Logger::warning('Upload API scope check failed', [
                        'scope' => $requiredScope,
                        'error' => $e->getMessage(),
                    ]);
                    $this->jsonResponse(['error' => 'This token does not have permission for that action.'], 403);
                }
            }
            return $context;
        }

        if ($allowGuestUploadFallback) {
            [$userId, $guestSessionId] = $this->resolveUploadActor();
            $context['user_id'] = $userId;
            $context['guest_session_id'] = $guestSessionId;
        }

        if ($requireSessionCsrf && !empty($context['csrf_required'])) {
            $this->requireCsrf();
        }

        return $context;
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return $_POST ?: [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function requireCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
        if (!Csrf::verify($token)) {
            $this->jsonResponse(['error' => 'CSRF token invalid.'], 403);
        }
    }

    private function idempotencyKey(): ?string
    {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
        if ($key === null) {
            return null;
        }

        $key = trim($key);
        return $key !== '' ? substr($key, 0, 128) : null;
    }

    private function actorKey(array $context): string
    {
        if (!empty($context['api_token']['id'])) {
            return 'token:' . $context['api_token']['id'];
        }
        if (!empty($context['user_id'])) {
            return 'user:' . $context['user_id'];
        }
        if (!empty($context['guest_session_id'])) {
            return 'guest:' . $context['guest_session_id'];
        }
        return 'anonymous';
    }

    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
