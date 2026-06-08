<?php

namespace App\Controller;

use App\Model\Folder;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;

class FolderController {
    private const MAX_FOLDER_NAME_LENGTH = 191;

    private function ownedActiveFolderForCurrentUser(string $id, bool $halt = true): ?array
    {
        $folder = Folder::find($id);
        if ($folder && ($folder['status'] ?? 'active') === 'active' && (int)($folder['user_id'] ?? 0) === (int)(Auth::id() ?? 0)) {
            return $folder;
        }

        if ($halt) {
            $this->jsonResponse(['error' => 'Unauthorized'], 403);
        }

        return null;
    }

    private function jsonResponse(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    private function normalizeFolderName(?string $name, string $fallback = 'New Folder'): string
    {
        $name = trim((string)$name);
        if ($name === '') {
            $name = $fallback;
        }

        return mb_substr($name, 0, self::MAX_FOLDER_NAME_LENGTH);
    }

    private function requiredFolderIdFromPost(): string
    {
        $id = trim((string)($_POST['folder_id'] ?? ''));
        if ($id === '') {
            $this->jsonResponse(['error' => 'Folder ID is required'], 400);
        }

        return $id;
    }

    public function create() {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Login required'], 401);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method Not Allowed'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['error' => 'CSRF mismatch'], 403);
        }

        $name = $this->normalizeFolderName($_POST['name'] ?? 'New Folder');
        $parentId = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;

        $userId = Auth::id();
        if (!$userId && !Auth::isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 403);
        }

        try {
            $folderId = Folder::create($userId, $name, $parentId);
        } catch (\RuntimeException $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 409);
        }
        Auth::logActivity('folder_create', "Created folder: $name (ID: $folderId)");

        $this->jsonResponse(['status' => 'success', 'folder_id' => $folderId]);
    }

    public function rename() {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Login required'], 401);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method Not Allowed'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['error' => 'CSRF mismatch'], 403);
        }

        $id = $this->requiredFolderIdFromPost();
        $newName = $this->normalizeFolderName($_POST['name'] ?? '', '');
        $folder = $this->ownedActiveFolderForCurrentUser((string)$id);

        if ($newName === '') {
            $this->jsonResponse(['error' => 'Name cannot be empty'], 422);
        }

        Folder::update($folder['id'], ['name' => $newName]);
        Auth::logActivity('folder_rename', "Renamed folder ID " . $folder['id'] . " to $newName");

        $this->jsonResponse(['status' => 'success']);
    }

    public function delete() {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Login required'], 401);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Method Not Allowed'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['error' => 'CSRF mismatch'], 403);
        }

        $id = $this->requiredFolderIdFromPost();
        $folder = $this->ownedActiveFolderForCurrentUser((string)$id);

        Folder::trashTree((int)$folder['id']);
        Auth::logActivity('folder_delete', "Moved folder to trash: " . $folder['name'] . " (ID: " . $folder['id'] . ")");

        $this->jsonResponse(['status' => 'success']);
    }

    public function listJson() {
        if (!Auth::check()) {
            $this->jsonResponse([], 401);
        }

        $folders = Folder::getAllByUser(Auth::id() ?? 0);
        $this->jsonResponse($folders);
    }
}
