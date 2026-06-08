<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Core\Csrf;
use App\Service\EncryptedSearchService;
use App\Service\RewardService;
use App\Service\StaffActivityService;

class FileController
{

    private function checkAuth()
    {
        Auth::requireCapability('files.moderate');
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
            $deleteReason = trim((string)($_POST['delete_reason'] ?? ''));
            $deleteEarnings = !empty($_POST['delete_file_earnings']);
            if ($deleteReason === '') {
                $_SESSION['error'] = "A deletion reason is required.";
                header("Location: /admin/files");
                exit;
            }

            if ($deleteEarnings && !Auth::hasCapability('rewards_fraud.manage')) {
                $_SESSION['error'] = "Removing attached rewards during file moderation requires rewards-fraud review permission.";
                header("Location: /admin/files");
                exit;
            }

            $file = \App\Model\File::findAnyStatus($fileId);
            if (!$file) {
                $_SESSION['error'] = "File not found.";
                header("Location: /admin/files");
                exit;
            }

            try {
                $earningsResult = \App\Model\File::markPendingPurge($fileId, [
                    'deleted_by_user_id' => Auth::id() ? (int)Auth::id() : null,
                    'deleted_by_role' => 'admin',
                    'deleted_by_label' => 'Administrator',
                    'delete_reason' => $deleteReason,
                    'delete_file_earnings' => $deleteEarnings,
                    'delete_file_earnings_authorized' => $deleteEarnings && Auth::hasCapability('rewards_fraud.manage'),
                    'rewards_reviewer_id' => Auth::id() ? (int)Auth::id() : null,
                ]);
            } catch (\Throwable $e) {
                $_SESSION['error'] = $e->getMessage();
                header("Location: /admin/files");
                exit;
            }

            $activityMessage = 'Marked file for background deletion. Reason: ' . $deleteReason;
            if ($deleteEarnings && (int)($earningsResult['count'] ?? 0) > 0) {
                $activityMessage .= sprintf(
                    ' Removed %d reward entr%s totaling $%0.4f.',
                    (int)$earningsResult['count'],
                    (int)$earningsResult['count'] === 1 ? 'y' : 'ies',
                    (float)($earningsResult['amount'] ?? 0)
                );
            } elseif ($deleteEarnings) {
                $activityMessage .= ' No qualifying rewards were attached to this file.';
            }

            StaffActivityService::log(
                'file_moderated_delete',
                'file',
                $fileId,
                $activityMessage,
                [
                    'reason' => $deleteReason,
                    'delete_file_earnings' => $deleteEarnings,
                    'reversed_earning_count' => (int)($earningsResult['count'] ?? 0),
                    'reversed_earning_amount' => (float)($earningsResult['amount'] ?? 0),
                ],
                (int)($file['user_id'] ?? 0)
            );

            $_SESSION['success'] = $deleteEarnings
                ? ('File has been marked for background deletion and attached rewards were removed (' . number_format((float)($earningsResult['amount'] ?? 0), 4) . ').')
                : "File has been marked for background deletion.";
            header("Location: /admin/files");
            exit;
        }
    }
}
