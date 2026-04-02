<?php

namespace App\Controller;

use App\Model\Folder;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;

class FolderController {
    private const MAX_FOLDER_NAME_LENGTH = 191;

    private function normalizeFolderName(?string $name, string $fallback = 'New Folder'): string
    {
        $name = trim((string)$name);
        if ($name === '') {
            $name = $fallback;
        }

        return mb_substr($name, 0, self::MAX_FOLDER_NAME_LENGTH);
    }

    public function create() {
        if (!Auth::check()) {
            http_response_code(401);
            die(json_encode(['error' => 'Login required']));
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Method Not Allowed");
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF Mismatch");

        $name = $this->normalizeFolderName($_POST['name'] ?? 'New Folder');
        $parentId = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;

        $userId = Auth::id();
        if (!$userId && !Auth::isAdmin()) {
            http_response_code(403);
            die(json_encode(['error' => 'Unauthorized']));
        }

        $folderId = Folder::create($userId, $name, $parentId);
        Auth::logActivity('folder_create', "Created folder: $name (ID: $folderId)");

        echo json_encode(['status' => 'success', 'folder_id' => $folderId]);
    }

    public function rename() {
        if (!Auth::check()) {
            http_response_code(401);
            die(json_encode(['error' => 'Login required']));
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Method Not Allowed");
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF Mismatch");

        $id = $_POST['folder_id'];
        $newName = $this->normalizeFolderName($_POST['name'] ?? '', '');
        $folder = Folder::find($id);

        if (!$folder || ($folder['status'] ?? 'active') !== 'active' || ($folder['user_id'] != Auth::id() && !Auth::isAdmin())) {
            http_response_code(403);
            die(json_encode(['error' => 'Unauthorized']));
        }

        if ($newName === '') die(json_encode(['error' => 'Name cannot be empty']));

        Folder::update($folder['id'], ['name' => $newName]);
        Auth::logActivity('folder_rename', "Renamed folder ID " . $folder['id'] . " to $newName");

        echo json_encode(['status' => 'success']);
    }

    public function delete() {
        if (!Auth::check()) {
            http_response_code(401);
            die(json_encode(['error' => 'Login required']));
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Method Not Allowed");
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF Mismatch");

        $id = $_POST['folder_id'];
        $folder = Folder::find($id);

        if (!$folder || ($folder['status'] ?? 'active') !== 'active' || ($folder['user_id'] != Auth::id() && !Auth::isAdmin())) {
            http_response_code(403);
            die(json_encode(['error' => 'Unauthorized']));
        }

        Folder::hardDeleteTree((int)$folder['id']);
        Auth::logActivity('folder_delete', "Deleted folder: " . $folder['name'] . " (ID: " . $folder['id'] . ")");

        echo json_encode(['status' => 'success']);
    }

    public function listJson() {
        if (!Auth::check()) {
            http_response_code(401);
            die(json_encode([]));
        }
        
        $folders = Folder::getAllByUser(Auth::id() ?? 0);
        echo json_encode($folders);
    }
}
