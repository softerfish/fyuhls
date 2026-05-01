<?php

namespace App\Controller\Api;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Model\File;
use App\Model\Folder;
use App\Service\ApiAuthService;
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
        $id = Folder::create((int)$context['user_id'], substr($name, 0, 191), $parentId);
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

        $updated = 0;
        foreach (($payload['items'] ?? []) as $item) {
            $type = $item['type'] ?? '';
            $id = (string)($item['id'] ?? '');
            if ($type === 'file') {
                $file = $this->ownedActiveFile($id, (int)$context['user_id'], false);
                if ($file) {
                    File::update((int)$file['id'], ['folder_id' => $targetFolderId]);
                    $updated++;
                }
            } elseif ($type === 'folder') {
                $folder = $this->ownedActiveFolder($id, (int)$context['user_id'], false);
                if ($folder && ($targetFolderId === null || !Folder::isSubfolderOf($targetFolderId, (int)$folder['id']))) {
                    Folder::update((int)$folder['id'], ['parent_id' => $targetFolderId]);
                    $updated++;
                }
            }
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

        $created = [];
        foreach (($payload['items'] ?? []) as $item) {
            $type = $item['type'] ?? '';
            $id = (string)($item['id'] ?? '');
            $name = isset($item['name']) ? trim((string)$item['name']) : null;
            if ($type === 'file') {
                $file = $this->ownedActiveFile($id, (int)$context['user_id'], false);
                if ($file) {
                    $newId = File::copy((int)$file['id'], $targetFolderId, $name ?: null);
                    if ($newId) {
                        $created[] = ['type' => 'file', 'id' => $newId];
                    }
                }
            } elseif ($type === 'folder') {
                $folder = $this->ownedActiveFolder($id, (int)$context['user_id'], false);
                if ($folder) {
                    $newId = Folder::copyTree((int)$folder['id'], (int)$context['user_id'], $targetFolderId, $name ?: null);
                    if ($newId) {
                        $created[] = ['type' => 'folder', 'id' => $newId];
                    }
                }
            }
        }

        $this->json(['status' => 'ok', 'created' => $created], 201);
    }

    public function deleteItems(): void
    {
        $context = $this->context('files.write');
        $deleted = 0;
        foreach (($this->body()['items'] ?? []) as $item) {
            $type = $item['type'] ?? '';
            $id = (string)($item['id'] ?? '');
            if ($type === 'file') {
                $file = $this->ownedActiveFile($id, (int)$context['user_id'], false);
                if ($file) {
                    File::trash((int)$file['id']);
                    $deleted++;
                }
            } elseif ($type === 'folder') {
                $folder = $this->ownedActiveFolder($id, (int)$context['user_id'], false);
                if ($folder) {
                    Folder::softDeleteTree((int)$folder['id']);
                    $deleted++;
                }
            }
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
            SELECT DATE(created_at) as day, COUNT(*) as downloads, SUM(amount) as earnings
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
        $folderId = isset($payload['folder_id']) && $payload['folder_id'] !== 'root' ? (int)$payload['folder_id'] : null;
        if ($folderId !== null) {
            $this->ownedActiveFolder((string)$folderId, (int)$context['user_id']);
        }
        $db = Database::getInstance()->getConnection();
        $this->ensureRemoteUploadQueueSchema($db);
        $stmt = $db->prepare("INSERT INTO remote_upload_queue (user_id, folder_id, url) VALUES (?, ?, ?)");
        $stmt->execute([(int)$context['user_id'], $folderId, $url]);
        $this->json(['status' => 'ok', 'job_id' => (int)$db->lastInsertId()], 201);
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
            'paths' => [
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
                '/api/v1/earnings/stats' => ['get' => ['summary' => 'Read earnings statistics', 'tags' => ['earnings']]],
                '/api/v1/payouts' => ['get' => ['summary' => 'Read payout balance and requests', 'tags' => ['earnings']]],
                '/api/v1/remote-uploads' => ['post' => ['summary' => 'Create remote upload job', 'tags' => ['remote']]],
                '/api/v1/remote-uploads/{id}' => ['get' => ['summary' => 'Inspect a remote upload job', 'tags' => ['remote']]],
                '/api/v1/remote-uploads/{id}/cancel' => ['post' => ['summary' => 'Cancel a remote upload job', 'tags' => ['remote']]],
            ],
        ]);
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
        if ($file && ((int)$file['user_id'] === $userId || Auth::isAdmin())) {
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
        if ($folder && ($folder['status'] ?? 'active') === 'active' && ((int)$folder['user_id'] === $userId || Auth::isAdmin())) {
            return $folder;
        }
        if ($halt) {
            $this->json(['error' => 'Folder not found.'], 404);
        }
        return null;
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : $_POST;
    }

    private function ensureRemoteUploadQueueSchema(\PDO $db): void
    {
        try {
            $db->exec("ALTER TABLE remote_upload_queue MODIFY status ENUM('pending', 'processing', 'completed', 'failed', 'canceled') NOT NULL DEFAULT 'pending'");
        } catch (\Throwable $e) {
        }
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
