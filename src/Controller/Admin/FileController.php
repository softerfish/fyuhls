<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Core\Csrf;
use App\Service\EncryptedSearchService;

class FileController
{

    private function checkAuth()
    {
        Auth::requireAdmin();
    }

    public function index()
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();

        // Pagination
        $page = (int)($_GET['page'] ?? 1);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Search
        $search = $_GET['q'] ?? '';
        if (!empty($search)) {
            $matchedFiles = EncryptedSearchService::searchFiles($search);
            $total = count($matchedFiles);
            $files = array_slice($matchedFiles, $offset, $perPage);
        } else {
            $total = (int)$db->query("SELECT COUNT(*) FROM files")->fetchColumn();

            $sql = "SELECT f.*, u.username, fs.name as server_name, sf.storage_provider FROM files f LEFT JOIN users u ON f.user_id = u.id LEFT JOIN stored_files sf ON f.stored_file_id = sf.id LEFT JOIN file_servers fs ON sf.file_server_id = fs.id ORDER BY f.created_at DESC LIMIT $perPage OFFSET $offset";

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $files = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($files as &$file) {
                $file['filename'] = \App\Service\EncryptionService::decrypt($file['filename']);
                $file['username'] = \App\Service\EncryptionService::decrypt($file['username']);
                
                if (!$file['server_name']) {
                    $file['server_name'] = !empty($file['storage_provider']) ? ucfirst($file['storage_provider']) : 'Local';
                }
            }
        }

        $totalPages = ceil($total / $perPage);

        View::render('admin/files/index.php', [
            'files' => $files,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total
        ]);
    }

    public function delete()
    {
        $this->checkAuth();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }

            $fileId = (int)$_POST['file_id'];
            $db = Database::getInstance()->getConnection();
            
            // Mark for background purge instead of instant hard delete
            $stmt = $db->prepare("UPDATE files SET status = 'pending_purge' WHERE id = ?");
            $stmt->execute([$fileId]);

            $_SESSION['success'] = "File has been marked for background deletion.";
            header("Location: /admin/files");
            exit;
        }
    }
}
