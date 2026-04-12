<?php

namespace App\Controller\Api;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Model\File;
use App\Model\Setting;
use App\Service\ApiAuthService;
use App\Service\ApiIdempotencyService;
use App\Service\DownloadManager;
use App\Service\MultipartUploadService;

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
        ], $status);
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
            'The selected storage backend does not support direct multipart uploads yet.',
            'This upload would exceed your storage quota.',
            'The selected storage node does not have enough free capacity.',
            'Could not open multipart upload.',
            'Invalid part number.',
            'No uploaded parts were reported for this session.',
            'Multipart completion failed.',
        ];

        if (in_array($message, $safeMessages, true)) {
            return $message;
        }

        return 'The upload request could not be completed.';
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
        $idempotency = $this->idempotency->begin(
            $this->idempotencyKey(),
            'upload.create_session',
            $this->actorKey($context),
            $userId,
            $context['api_token']['id'] ?? null,
            $payload
        );
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
                isset($payload['checksum_sha256']) ? (string)$payload['checksum_sha256'] : null
            );

            $response = [
                'status' => 'ok',
                'session' => $session['session'],
                'part_size_bytes' => $session['part_size_bytes'],
                'expires_at' => $session['expires_at'],
                'capabilities' => $session['capabilities'],
                'upload_skipped' => !empty($session['upload_skipped']),
                'file_id' => $session['file_id'] ?? null,
                'deduplicated' => !empty($session['deduplicated']),
            ];
            $this->idempotency->complete($idempotency, 201, $response);
            $this->jsonResponse($response, 201);
        } catch (\Throwable $e) {
            $this->reportUploadFailure('create_session', $e);
        }
    }

    public function createManagedUpload()
    {
        $this->ensureChunkedUploadsEnabled();
        $context = $this->resolveApiContext(true, 'files.upload', true);
        $payload = $this->jsonBody();
        $this->apiAuth->enforceRateLimit($context, 'api_upload_managed_create', 30, 60);
        $idempotency = $this->idempotency->begin(
            $this->idempotencyKey(),
            'upload.create_managed',
            $this->actorKey($context),
            $context['user_id'],
            $context['api_token']['id'] ?? null,
            $payload
        );
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
                isset($payload['checksum_sha256']) ? (string)$payload['checksum_sha256'] : null
            );
            if (!empty($session['upload_skipped'])) {
                $response = [
                    'status' => 'ok',
                    'session' => $session['session'],
                    'part_size_bytes' => 0,
                    'parts' => [],
                    'complete_url' => null,
                    'report_part_url' => null,
                    'upload_skipped' => true,
                    'file_id' => $session['file_id'] ?? null,
                    'deduplicated' => !empty($session['deduplicated']),
                ];
                $this->idempotency->complete($idempotency, 201, $response);
                $this->jsonResponse($response, 201);
            }
            $signed = $this->service->signParts($session['session'], $partNumbers, (int)($payload['expires_in'] ?? 3600));
            $response = [
                'status' => 'ok',
                'session' => $session['session'],
                'part_size_bytes' => $session['part_size_bytes'],
                'parts' => $signed,
                'complete_url' => '/api/v1/uploads/sessions/' . rawurlencode($session['session']['public_id']) . '/complete',
                'report_part_url' => '/api/v1/uploads/sessions/' . rawurlencode($session['session']['public_id']) . '/parts/report',
                'upload_skipped' => false,
                'file_id' => $session['file_id'] ?? null,
                'deduplicated' => !empty($session['deduplicated']),
            ];
            $this->idempotency->complete($idempotency, 201, $response);
            $this->jsonResponse($response, 201);
        } catch (\Throwable $e) {
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

        $urls = $this->service->signParts($session, $partNumbers, (int)($payload['expires_in'] ?? 3600));
        $this->jsonResponse(['status' => 'ok', 'parts' => $urls]);
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

    public function complete(string $sessionId)
    {
        $context = $this->resolveApiContext(true, 'files.upload', true);
        [$userId, $guestSessionId] = [$context['user_id'], $context['guest_session_id']];
        $payload = $this->jsonBody();
        $this->apiAuth->enforceRateLimit($context, 'api_upload_complete', 60, 60);
        $idempotency = $this->idempotency->begin(
            $this->idempotencyKey(),
            'upload.complete.' . $sessionId,
            $this->actorKey($context),
            $userId,
            $context['api_token']['id'] ?? null,
            $payload
        );
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

        $this->service->abort($session);
        $this->jsonResponse(['status' => 'ok']);
    }

    public function downloadLink(string $fileId)
    {
        $context = $this->resolveApiContext(false, 'files.read', false);
        $this->apiAuth->enforceRateLimit($context, 'api_download_link', 60, 60);
        $userId = $context['user_id'] ?? $this->requireUser();

        $file = File::find($fileId);
        if (!$file) {
            $this->jsonResponse(['error' => 'File not found.'], 404);
        }

        if ((int)$file['user_id'] !== (int)$userId && !Auth::isAdmin()) {
            $this->jsonResponse(['error' => 'File not found.'], 404);
        }

        $downloadManager = new DownloadManager();
        $delivery = $downloadManager->previewDelivery($file);
        $downloadUrl = $downloadManager->generateSignedUrl($file['id'], $file['filename']);

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
        $file = File::findAnyStatus($fileId);
        if (!$file) {
            $this->jsonResponse(['error' => 'File not found.'], 404);
        }

        if ((int)$file['user_id'] !== (int)$context['user_id'] && !Auth::isAdmin()) {
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
