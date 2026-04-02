<?php

namespace App\Controller\Admin;

use App\Core\Database;
use App\Core\Auth;
use App\Service\EncryptionService;
use App\Service\EncryptedSearchService;
use App\Core\View;

class SearchController
{
    private function checkAuth()
    {
        Auth::requireAdmin();
    }

    public function search()
    {
        $this->checkAuth();
        $query = trim($_GET['q'] ?? '');

        if (empty($query)) {
            header("Location: /admin");
            exit;
        }

        $db = Database::getInstance()->getConnection();

        // 1. Direct ID / Short ID Check (Numerical or fixed-length exact identifiers)
        if (is_numeric($query)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$query]);
            if ($stmt->fetch()) {
                header("Location: /admin/users/edit/" . $query);
                exit;
            }

            $stmt = $db->prepare("SELECT id, filename FROM files WHERE id = ?");
            $stmt->execute([$query]);
            $file = $stmt->fetch();
            if ($file) {
                $filename = $query;
                try {
                    $filename = EncryptionService::decrypt($file['filename']);
                } catch (\Throwable $e) {
                }
                header("Location: /admin/files?q=" . urlencode($filename));
                exit;
            }
        }

        if (strlen($query) >= 8 && strlen($query) <= 16) {
            $stmt = $db->prepare("SELECT id, filename, short_id FROM files WHERE short_id = ?");
            $stmt->execute([$query]);
            $file = $stmt->fetch();
            if ($file) {
                try {
                    $file['filename'] = EncryptionService::decrypt($file['filename']);
                } catch (\Throwable $e) {
                    $file['filename'] = '(encrypted)';
                }

                View::render('admin/search_results.php', [
                    'query' => $query,
                    'users' => [],
                    'files' => [$file],
                ]);
                return;
            }
        }

        // 2. Deterministic Search (encrypted data requires exact matches)
        $encryptedQuery = EncryptionService::encrypt($query);

        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$encryptedQuery, $encryptedQuery]);
        $users = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT id, filename, short_id FROM files WHERE filename = ?");
        $stmt->execute([$encryptedQuery]);
        $files = $stmt->fetchAll();

        // 3. Logic: If exactly one result, redirect. Otherwise, show results.
        if (count($users) === 1 && count($files) === 0) {
            header("Location: /admin/users/edit/" . $users[0]['id']);
            exit;
        }

        foreach ($users as &$u) {
            try { $u['username'] = EncryptionService::decrypt($u['username']); } catch (\Exception $e) { $u['username'] = '(encrypted)'; }
            try { $u['email'] = EncryptionService::decrypt($u['email']); } catch (\Exception $e) { $u['email'] = '(encrypted)'; }
        }
        foreach ($files as &$f) {
            try { $f['filename'] = EncryptionService::decrypt($f['filename']); } catch (\Exception $e) { $f['filename'] = '(encrypted)'; }
        }

        if (empty($users) && empty($files)) {
            $users = EncryptedSearchService::searchUsers($query);
            $files = EncryptedSearchService::searchFiles($query);
        }

        if (count($users) === 1 && count($files) === 0) {
            header("Location: /admin/users/edit/" . $users[0]['id']);
            exit;
        }

        // 4. Diagnostic Logging (If 0 results, log it so admin can see what they are struggling to find)
        if (empty($users) && empty($files)) {
            $logFile = defined('BASE_PATH') ? BASE_PATH . '/storage/logs/admin_search.log' : dirname(__DIR__, 3) . '/storage/logs/admin_search.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            // Strip complex characters but allow emails/usernames
            $sanitized = substr(preg_replace('/[^a-zA-Z0-9_@.-]/', '', $query), 0, 50);
            $logEntry = "[" . date('Y-m-d H:i:s') . "] Type: Miss | Query: {$sanitized}\n";
            @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }

        View::render('admin/search_results.php', [
            'query' => $query,
            'users' => $users,
            'files' => $files
        ]);
    }
}
