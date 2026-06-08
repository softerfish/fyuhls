<?php

namespace App\Controller\Api;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Model\File;
use App\Model\Folder;
use App\Service\ApiAuthService;
use App\Service\FeatureService;
use App\Service\SeoService;

class UploaderApiController
{
    private ApiAuthService $apiAuth;

    public function __construct()
    {
        $this->apiAuth = new ApiAuthService();
    }

    public function listFiles(): void
    {
        $context = $this->context('files.read');
        $folderId = isset($_GET['folder_id']) && $_GET['folder_id'] !== 'root' ? (int)$_GET['folder_id'] : null;
        if ($folderId !== null) {
            $this->ownedActiveFolder((string)$folderId, (int)$context['user_id']);
        }
        $files = File::getByUser((int)$context['user_id'], $folderId);
        $this->json(['status' => 'ok', 'files' => array_map([$this, 'filePayload'], $files)]);
    }

    public function listFolders(): void
    {
        $context = $this->context('files.read');
        $parentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== 'root' ? (int)$_GET['parent_id'] : null;
        if ($parentId !== null) {
            $this->ownedActiveFolder((string)$parentId, (int)$context['user_id']);
        }
        $folders = Folder::getByUser((int)$context['user_id'], $parentId);
        $this->json(['status' => 'ok', 'folders' => array_map([$this, 'folderPayload'], $folders)]);
    }

    public function createFolder(): void
    {
        $context = $this->context('files.write');
        $payload = $this->body();
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            $this->json(['error' => 'Folder name is required.'], 422);
        }
        $parentId = isset($payload['parent_id']) && $payload['parent_id'] !== 'root' ? (int)$payload['parent_id'] : null;
        if ($parentId !== null) {
            $this->ownedActiveFolder((string)$parentId, (int)$context['user_id']);
        }
        try {
            $id = Folder::create((int)$context['user_id'], substr($name, 0, 191), $parentId);
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 409);
        }
        $folder = Folder::find($id);
        $this->json(['status' => 'ok', 'folder' => $this->folderPayload($folder ?: ['id' => $id, 'name' => $name])], 201);
    }

    public function renameFile(string $id): void
    {
        $context = $this->context('files.write');
        $file = $this->ownedActiveFile($id, (int)$context['user_id']);
        $name = trim((string)($this->body()['name'] ?? ''));
        if ($name === '') {
            $this->json(['error' => 'Filename is required.'], 422);
        }
        File::update((int)$file['id'], ['filename' => substr($name, 0, 255)]);
        $this->json(['status' => 'ok']);
    }

    public function renameFolder(string $id): void
    {
        $context = $this->context('files.write');
        $folder = $this->ownedActiveFolder($id, (int)$context['user_id']);
        $name = trim((string)($this->body()['name'] ?? ''));
        if ($name === '') {
            $this->json(['error' => 'Folder name is required.'], 422);
        }
        Folder::update((int)$folder['id'], ['name' => substr($name, 0, 191)]);
        $this->json(['status' => 'ok']);
    }

    public function moveItems(): void
    {
        $context = $this->context('files.write');
        $payload = $this->body();
        $targetFolderId = isset($payload['target_folder_id']) && $payload['target_folder_id'] !== 'root' ? (int)$payload['target_folder_id'] : null;
        if ($targetFolderId !== null) {
            $this->ownedActiveFolder((string)$targetFolderId, (int)$context['user_id']);
        }

        $items = $this->resolveOwnedActiveBatchItems((array)($payload['items'] ?? []), (int)$context['user_id'], true);
        foreach ($items as $item) {
            if ($item['type'] === 'folder' && $targetFolderId !== null && Folder::isSubfolderOf($targetFolderId, (int)$item['row']['id'])) {
                $this->json(['error' => 'A folder cannot be moved into itself or one of its descendants.'], 409);
            }
        }

        $updated = 0;
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    File::update((int)$item['row']['id'], ['folder_id' => $targetFolderId]);
                } else {
                    Folder::update((int)$item['row']['id'], ['parent_id' => $targetFolderId]);
                }
                $updated++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->json(['error' => 'Could not move every selected item.'], 500);
        }

        $this->json(['status' => 'ok', 'updated' => $updated]);
    }

    public function copyItems(): void
    {
        $context = $this->context('files.write');
        $payload = $this->body();
        $targetFolderId = isset($payload['target_folder_id']) && $payload['target_folder_id'] !== 'root' ? (int)$payload['target_folder_id'] : null;
        if ($targetFolderId !== null) {
            $this->ownedActiveFolder((string)$targetFolderId, (int)$context['user_id']);
        }

        $items = $this->resolveOwnedActiveBatchItems((array)($payload['items'] ?? []), (int)$context['user_id'], true);

        $created = [];
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                $name = isset($item['request']['name']) ? trim((string)$item['request']['name']) : null;
                if ($item['type'] === 'file') {
                    $newId = File::copy((int)$item['row']['id'], $targetFolderId, $name ?: null);
                    if (!$newId) {
                        throw new \RuntimeException('Could not copy every selected item.');
                    }
                    $created[] = ['type' => 'file', 'id' => $newId];
                } else {
                    $newId = Folder::copyTree((int)$item['row']['id'], (int)$context['user_id'], $targetFolderId, $name ?: null);
                    if (!$newId) {
                        throw new \RuntimeException('Could not copy every selected item.');
                    }
                    $created[] = ['type' => 'folder', 'id' => $newId];
                }
            }
            $db->commit();
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->json(['error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->json(['error' => 'Could not copy every selected item.'], 500);
        }

        $this->json(['status' => 'ok', 'created' => $created], 201);
    }

    public function deleteItems(): void
    {
        $context = $this->context('files.write');
        $items = $this->resolveOwnedActiveDeleteItems((array)($this->body()['items'] ?? []), (int)$context['user_id']);

        $deleted = 0;
        $db = Database::getInstance()->getConnection();
        $bonusTouchUserIds = [];
        $db->beginTransaction();
        try {
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $bonusTouchUserIds = array_merge($bonusTouchUserIds, File::trash((int)$item['row']['id']));
                } else {
                    $bonusTouchUserIds = array_merge($bonusTouchUserIds, Folder::trashTree((int)$item['row']['id']));
                }
                $deleted++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->json(['error' => 'Could not delete every selected item.'], 500);
        }
        $bonusTouchUserIds = array_values(array_unique(array_filter(array_map('intval', $bonusTouchUserIds), static fn (int $userId): bool => $userId > 0)));
        if ($bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'api_delete_items',
                'item_count' => $deleted,
            ]);
        }
        $this->json(['status' => 'ok', 'deleted' => $deleted]);
    }

    public function bulkLinks(): void
    {
        $context = $this->context('files.read');
        $payload = $this->body();
        $format = (string)($payload['format'] ?? 'plain');
        $baseUrl = rtrim(SeoService::trustedBaseUrl(), '/');
        $links = [];
        foreach (($payload['items'] ?? []) as $item) {
            $type = $item['type'] ?? '';
            $id = (string)($item['id'] ?? '');
            if ($type === 'file') {
                $file = $this->ownedActiveFile($id, (int)$context['user_id'], false);
                if (!$file) {
                    continue;
                }
                $url = $baseUrl . '/file/' . rawurlencode((string)$file['short_id']);
                $links[] = $this->formatLink($format, $url, (string)$file['filename']);
            } elseif ($type === 'folder') {
                $folder = $this->ownedActiveFolder($id, (int)$context['user_id'], false);
                if (!$folder) {
                    continue;
                }
                $folderPublicId = trim((string)($folder['short_id'] ?? ''));
                if ($folderPublicId === '') {
                    continue;
                }
                $url = $baseUrl . '/folder/' . rawurlencode($folderPublicId);
                $links[] = $this->formatLink($format, $url, (string)$folder['name']);
            }
        }
        $this->json(['status' => 'ok', 'format' => $format, 'links' => $links, 'text' => implode("\n", $links)]);
    }

    public function earningsStats(): void
    {
        $this->ensureRewardsApiEnabled();
        $context = $this->context('stats.read');
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT status, COUNT(*) as rows_count, SUM(amount) as amount
            FROM earnings
            WHERE user_id = ?
            GROUP BY status
        ");
        $stmt->execute([(int)$context['user_id']]);
        $byStatus = $stmt->fetchAll();

        $stmt = $db->prepare("
            SELECT DATE(created_at) as day, SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as downloads, SUM(amount) as earnings
            FROM earnings
            WHERE user_id = ? AND type = 'download_reward'
            GROUP BY DATE(created_at)
            ORDER BY day DESC
            LIMIT 60
        ");
        $stmt->execute([(int)$context['user_id']]);

        $this->json(['status' => 'ok', 'by_status' => $byStatus, 'by_day' => $stmt->fetchAll()]);
    }

    public function payoutInfo(): void
    {
        $this->ensureRewardsApiEnabled();
        $context = $this->context('stats.read');
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'cleared'");
        $stmt->execute([(int)$context['user_id']]);
        $cleared = (float)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved', 'paid')");
        $stmt->execute([(int)$context['user_id']]);
        $reserved = (float)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT id, amount, method, status, created_at, processed_at FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([(int)$context['user_id']]);
        $this->json([
            'status' => 'ok',
            'available_balance' => max(0, $cleared - $reserved),
            'cleared' => $cleared,
            'reserved_or_paid' => $reserved,
            'withdrawals' => $stmt->fetchAll(),
        ]);
    }

    public function createRemoteUpload(): void
    {
        $context = $this->context('remote.upload');
        $payload = $this->body();
        $url = trim((string)($payload['url'] ?? ''));
        if (!preg_match('/^https?:\/\//i', $url)) {
            $this->json(['error' => 'A valid HTTP or HTTPS URL is required.'], 422);
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $this->json(['error' => 'Invalid protocol. Only HTTP and HTTPS allowed.'], 422);
        }
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            $this->json(['error' => 'Embedded credentials are not allowed in remote URLs.'], 422);
        }

        $package = \App\Model\Package::getUserPackage((int)$context['user_id']);
        if (!$package || empty($package['allow_remote_upload'])) {
            $this->json(['error' => 'Remote upload not allowed for your package.'], 403);
        }
        if (!$this->allowRemoteUploadQueueRequest((int)$context['user_id'], \App\Service\SecurityService::getClientIp())) {
            $this->json(['error' => 'Too many remote upload requests are already pending for this account. Please wait for existing jobs to finish.'], 429);
        }

        $host = parse_url($url, PHP_URL_HOST);
        $approvedIps = $this->resolveApprovedRemoteIps($host);
        if (empty($approvedIps)) {
            $this->json(['error' => 'Could not resolve host.'], 422);
        }

        $maxRemoteBytes = $this->resolveRemoteUploadByteLimit((int)$context['user_id'], $package);
        if ($maxRemoteBytes <= 0) {
            $this->json(['error' => 'Remote upload is not available because your remaining limits are exhausted.'], 422);
        }

        $folderId = isset($payload['folder_id']) && $payload['folder_id'] !== 'root' ? (int)$payload['folder_id'] : null;
        if ($folderId !== null) {
            $this->ownedActiveFolder((string)$folderId, (int)$context['user_id']);
        }
        $db = Database::getInstance()->getConnection();
        try {
            $jobId = $this->queueRemoteUploadJob((int)$context['user_id'], $folderId, $url);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'remote_upload_pending_limit_reached') {
                $this->json(['error' => 'Too many remote upload requests are already pending for this account. Please wait for existing jobs to finish.'], 429);
            }
            $this->json(['error' => 'Could not queue remote upload right now.'], 503);
        }
        \App\Service\NotificationService::sendEvent(
            (int)$context['user_id'],
            'remote_uploads',
            'remote_upload:' . $jobId,
            'Remote upload queued',
            'Your remote upload was added to the queue and will be processed in the background.',
            'info',
            '/notifications'
        );
        $this->json(['status' => 'ok', 'job_id' => $jobId], 201);
    }

    public function remoteStatus(string $id): void
    {
        $context = $this->context('remote.upload');
        $db = Database::getInstance()->getConnection();
        $this->ensureRemoteUploadQueueSchema($db);
        $stmt = $db->prepare("SELECT id, folder_id, status, error_message, created_at, processed_at FROM remote_upload_queue WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([(int)$id, (int)$context['user_id']]);
        $job = $stmt->fetch();
        if (!$job) {
            $this->json(['error' => 'Remote upload not found.'], 404);
        }
        $this->json(['status' => 'ok', 'job' => $job]);
    }

    public function cancelRemoteUpload(string $id): void
    {
        $context = $this->context('remote.upload');
        $db = Database::getInstance()->getConnection();
        $this->ensureRemoteUploadQueueSchema($db);
        $stmt = $db->prepare("UPDATE remote_upload_queue SET status = 'canceled', error_message = 'Canceled by API user.', processed_at = NOW() WHERE id = ? AND user_id = ? AND status IN ('pending', 'processing')");
        $stmt->execute([(int)$id, (int)$context['user_id']]);
        $this->json(['status' => 'ok', 'cancelled' => $stmt->rowCount()]);
    }

    public function openApi(): void
    {
        $rewardsEnabled = FeatureService::rewardsEnabled();
        $this->json([
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Uploader API',
                'version' => '1.0.0',
            ],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
            'paths' => $this->buildOpenApiPaths($rewardsEnabled),
        ]);
    }

    private function ensureRewardsApiEnabled(): void
    {
        if (!FeatureService::rewardsEnabled()) {
            $this->json(['error' => 'Not found.'], 404);
        }
    }

    private function buildOpenApiPaths(bool $rewardsEnabled): array
    {
        $paths = [
            '/api/v1/files' => ['get' => ['summary' => 'List account files', 'tags' => ['files']]],
            '/api/v1/folders' => ['get' => ['summary' => 'List account folders', 'tags' => ['folders']], 'post' => ['summary' => 'Create folder', 'tags' => ['folders']]],
            '/api/v1/files/{id}/rename' => ['post' => ['summary' => 'Rename a file', 'tags' => ['files']]],
            '/api/v1/folders/{id}/rename' => ['post' => ['summary' => 'Rename a folder', 'tags' => ['folders']]],
            '/api/v1/items/move' => ['post' => ['summary' => 'Move selected files or folders', 'tags' => ['files']]],
            '/api/v1/items/copy' => ['post' => ['summary' => 'Copy files or clone folders', 'tags' => ['files']]],
            '/api/v1/items/delete' => ['post' => ['summary' => 'Move files or folders to trash', 'tags' => ['files']]],
            '/api/v1/bulk-links' => ['post' => ['summary' => 'Generate bulk links', 'tags' => ['links']]],
            '/api/v1/uploads/managed' => ['post' => ['summary' => 'Create a managed multipart upload', 'tags' => ['uploads']]],
            '/api/v1/uploads/sessions' => ['post' => ['summary' => 'Create multipart upload session', 'tags' => ['uploads']]],
            '/api/v1/remote-uploads' => ['post' => ['summary' => 'Create remote upload job', 'tags' => ['remote']]],
            '/api/v1/remote-uploads/{id}' => ['get' => ['summary' => 'Inspect a remote upload job', 'tags' => ['remote']]],
            '/api/v1/remote-uploads/{id}/cancel' => ['post' => ['summary' => 'Cancel a remote upload job', 'tags' => ['remote']]],
        ];

        if ($rewardsEnabled) {
            $paths['/api/v1/earnings/stats'] = ['get' => ['summary' => 'Read earnings statistics', 'tags' => ['earnings']]];
            $paths['/api/v1/payouts'] = ['get' => ['summary' => 'Read payout balance and requests', 'tags' => ['earnings']]];
        }

        return $paths;
    }

    private function context(string $scope): array
    {
        try {
            $context = $this->apiAuth->resolveRequestContext();
            $this->apiAuth->requireScope($context, $scope);
            $this->apiAuth->enforceRateLimit($context, 'api_' . str_replace('.', '_', $scope), 120, 60);
        } catch (\RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 403);
        }

        if (empty($context['user_id'])) {
            $this->json(['error' => 'Authentication required.'], 401);
        }

        if (($context['mode'] ?? 'session') === 'session' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
            if (!Csrf::verify($token)) {
                $this->json(['error' => 'CSRF token invalid.'], 403);
            }
        }

        return $context;
    }

    private function ownedActiveFile(string $id, int $userId, bool $halt = true): ?array
    {
        $file = File::find($id);
        if ($file && (int)$file['user_id'] === $userId) {
            return $file;
        }
        if ($halt) {
            $this->json(['error' => 'File not found.'], 404);
        }
        return null;
    }

    private function ownedActiveFolder(string $id, int $userId, bool $halt = true): ?array
    {
        $folder = Folder::find($id);
        if ($folder && ($folder['status'] ?? 'active') === 'active' && (int)$folder['user_id'] === $userId) {
            return $folder;
        }
        if ($halt) {
            $this->json(['error' => 'Folder not found.'], 404);
        }
        return null;
    }

    private function resolveOwnedActiveBatchItems(array $items, int $userId, bool $allowFolders): array
    {
        $resolved = [];
        $seen = [];

        foreach ($items as $item) {
            $type = strtolower(trim((string)($item['type'] ?? '')));
            $id = (string)($item['id'] ?? '');
            if ($id === '' || !in_array($type, $allowFolders ? ['file', 'folder'] : ['file'], true)) {
                $this->json(['error' => 'Every batch item must include a valid type and id.'], 422);
            }

            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $row = $type === 'file'
                ? $this->ownedActiveFile($id, $userId, false)
                : $this->ownedActiveFolder($id, $userId, false);
            if (!$row) {
                $this->json(['error' => 'One or more selected items no longer exist or are no longer available to this account.'], 409);
            }

            $resolved[] = [
                'type' => $type,
                'row' => $row,
                'request' => is_array($item) ? $item : [],
            ];
        }

        if ($resolved === []) {
            $this->json(['error' => 'No items selected.'], 422);
        }

        return $resolved;
    }

    private function resolveOwnedActiveDeleteItems(array $items, int $userId): array
    {
        $resolved = [];
        $seen = [];
        $skipped = 0;

        foreach ($items as $item) {
            $type = strtolower(trim((string)($item['type'] ?? '')));
            $id = (string)($item['id'] ?? '');
            if ($id === '' || !in_array($type, ['file', 'folder'], true)) {
                $this->json(['error' => 'Every batch item must include a valid type and id.'], 422);
            }

            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $row = $type === 'file'
                ? $this->ownedActiveFile($id, $userId, false)
                : $this->ownedActiveFolder($id, $userId, false);
            if (!$row) {
                $skipped++;
                continue;
            }

            $resolved[] = [
                'type' => $type,
                'row' => $row,
                'request' => is_array($item) ? $item : [],
            ];
        }

        if ($resolved !== [] && $skipped > 0) {
            $this->json(['error' => 'One or more selected items no longer exist or are no longer available to this account.'], 409);
        }

        if ($resolved === [] && $skipped === 0) {
            $this->json(['error' => 'No items selected.'], 422);
        }

        return $resolved;
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : $_POST;
    }

    private function resolveApprovedRemoteIps(?string $host): array
    {
        return \App\Service\SecurityService::resolveApprovedRemoteDestinationIps($host);
    }

    private function isAllowedRemoteIp(string $ip): bool
    {
        return \App\Service\SecurityService::isAllowedRemoteDestinationIp($ip);
    }

    private function resolveRemoteUploadByteLimit(int $userId, array $package): int
    {
        $limit = (int)($package['max_upload_size'] ?? 0);
        if ($limit <= 0) {
            $limit = PHP_INT_MAX;
        }

        $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
        if ($maxStorage > 0 && $userId > 0) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $storageUsed = (int)$stmt->fetchColumn();
            $activeReserved = \App\Model\QuotaReservation::activeReservedBytesForUser($userId);
            $remaining = max(0, $maxStorage - $storageUsed - $activeReserved);
            $limit = min($limit, $remaining);
        }

        return max(0, $limit);
    }

    private function allowRemoteUploadQueueRequest(int $userId, string $clientIp): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $perMinuteLimit = max(1, (int)\App\Model\Setting::get('remote_upload_rate_limit', '12'));
        if (!\App\Service\RateLimiterService::check('remote_upload_user', (string)$userId, $perMinuteLimit, 60)) {
            return false;
        }

        if (!\App\Service\RateLimiterService::check('remote_upload_ip', $clientIp, max($perMinuteLimit, 20), 60)) {
            return false;
        }

        return true;
    }

    private function queueRemoteUploadJob(int $userId, ?int $folderId, string $url): int
    {
        $pendingLimit = max(1, (int)\App\Model\Setting::get('remote_upload_pending_limit', '10'));
        $db = Database::getInstance()->getConnection();
        $this->ensureRemoteUploadQueueSchema($db);
        $lockKey = 'fyuhls_remote_upload_queue_' . $userId;
        $lockStmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $lockStmt->execute([$lockKey]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new \RuntimeException('remote_upload_queue_lock_failed');
        }

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM remote_upload_queue WHERE user_id = ? AND status IN ('pending', 'processing')");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() >= $pendingLimit) {
                throw new \RuntimeException('remote_upload_pending_limit_reached');
            }

            $stmt = $db->prepare("INSERT INTO remote_upload_queue (user_id, folder_id, url) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $folderId, $url]);
            return (int)$db->lastInsertId();
        } finally {
            try {
                $releaseStmt = $db->prepare("SELECT RELEASE_LOCK(?)");
                $releaseStmt->execute([$lockKey]);
            } catch (\Throwable $e) {
            }
        }
    }

    private function ensureRemoteUploadQueueSchema(\PDO $db): void
    {
        \App\Service\Database\SchemaService::ensureTables(['remote_upload_queue'], false);
    }

    private function filePayload(array $file): array
    {
        return [
            'id' => (int)$file['id'],
            'short_id' => $file['short_id'] ?? null,
            'filename' => $file['filename'] ?? '',
            'folder_id' => $file['folder_id'] ?? null,
            'size' => (int)($file['file_size'] ?? 0),
            'mime_type' => $file['mime_type'] ?? null,
            'downloads' => (int)($file['downloads'] ?? 0),
            'is_public' => (int)($file['is_public'] ?? 0),
            'status' => $file['status'] ?? 'active',
            'created_at' => $file['created_at'] ?? null,
        ];
    }

    private function folderPayload(array $folder): array
    {
        return [
            'id' => (int)$folder['id'],
            'short_id' => $folder['short_id'] ?? null,
            'name' => $folder['name'] ?? '',
            'parent_id' => $folder['parent_id'] ?? null,
            'file_count' => (int)($folder['file_count'] ?? 0),
            'folder_count' => (int)($folder['folder_count'] ?? 0),
            'total_size' => (int)($folder['total_size'] ?? 0),
            'created_at' => $folder['created_at'] ?? null,
        ];
    }

    private function formatLink(string $format, string $url, string $name): string
    {
        return match ($format) {
            'html' => '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>',
            'bbcode' => '[url=' . $url . ']' . $name . '[/url]',
            default => $url,
        };
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
