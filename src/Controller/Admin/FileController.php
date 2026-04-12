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
            $files = $this->hydrateFileListingRows($files, $db);
        } else {
            $total = (int)$db->query("SELECT COUNT(*) FROM files")->fetchColumn();

            $sql = "SELECT f.*, u.username, fs.name as server_name, sf.storage_provider, sf.ref_count
                    FROM files f
                    LEFT JOIN users u ON f.user_id = u.id
                    LEFT JOIN stored_files sf ON f.stored_file_id = sf.id
                    LEFT JOIN file_servers fs ON sf.file_server_id = fs.id
                    ORDER BY f.created_at DESC LIMIT $perPage OFFSET $offset";

            $stmt = $db->prepare($sql);
            $stmt->execute();
            $files = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $files = $this->hydrateFileListingRows($files, $db);
        }

        $totalPages = ceil($total / $perPage);

        $dedupeSummary = [
            'logical_files' => $total,
            'unique_stored_files' => 0,
            'duplicate_file_entries' => 0,
        ];

        try {
            $summaryStmt = $db->query("
                SELECT
                    COUNT(*) AS unique_stored_files,
                    COALESCE(SUM(GREATEST(ref_count - 1, 0)), 0) AS duplicate_file_entries
                FROM stored_files
            ");
            $summary = $summaryStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $dedupeSummary['unique_stored_files'] = (int)($summary['unique_stored_files'] ?? 0);
            $dedupeSummary['duplicate_file_entries'] = (int)($summary['duplicate_file_entries'] ?? 0);
        } catch (\Throwable $e) {
            // Leave defaults when the schema is unavailable.
        }

        View::render('admin/files/index.php', [
            'files' => $files,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'dedupeSummary' => $dedupeSummary,
        ]);
    }

    private function hydrateFileListingRows(array $files, \PDO $db): array
    {
        if (empty($files)) {
            return [];
        }

        $storedFileIds = array_values(array_unique(array_filter(array_map(
            static fn(array $file): int => (int)($file['stored_file_id'] ?? 0),
            $files
        ))));

        $refCounts = [];
        if (!empty($storedFileIds)) {
            $placeholders = implode(',', array_fill(0, count($storedFileIds), '?'));
            $stmt = $db->prepare("SELECT id, ref_count FROM stored_files WHERE id IN ($placeholders)");
            $stmt->execute($storedFileIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $refCounts[(int)$row['id']] = (int)($row['ref_count'] ?? 1);
            }
        }

        foreach ($files as &$file) {
            if (isset($file['filename']) && is_string($file['filename'])) {
                $file['filename'] = \App\Service\EncryptionService::decrypt($file['filename']);
            }
            if (isset($file['username']) && is_string($file['username'])) {
                $file['username'] = \App\Service\EncryptionService::decrypt($file['username']);
            }

            if (empty($file['server_name'])) {
                $file['server_name'] = !empty($file['storage_provider']) ? ucfirst((string)$file['storage_provider']) : 'Local';
            }

            $storedFileId = (int)($file['stored_file_id'] ?? 0);
            $refCount = (int)($file['ref_count'] ?? ($refCounts[$storedFileId] ?? 1));
            $file['ref_count'] = max(1, $refCount);
            $file['is_duplicate_entry'] = $file['ref_count'] > 1;
            $file['duplicate_count'] = max(0, $file['ref_count'] - 1);
        }
        unset($file);

        return $files;
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
