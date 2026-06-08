<?php

namespace App\Controller\Admin;

use App\Core\Database;
use App\Core\Auth;
use App\Core\Logger;
use App\Model\User;
use App\Service\AdminUserNavigationService;
use App\Service\EncryptionService;
use App\Service\EncryptedSearchService;
use App\Core\View;

class SearchController
{
    private function canSearchUsers(): bool
    {
        return Auth::hasCapability('users.manage');
    }

    private function canSearchFiles(): bool
    {
        return Auth::hasCapability('files.moderate');
    }

    private function checkAuth()
    {
        Auth::requireAnyCapability(['users.manage', 'files.moderate']);
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
        $canSearchUsers = $this->canSearchUsers();
        $canSearchFiles = $this->canSearchFiles();

        // 1. Direct ID / Short ID Check (Numerical or fixed-length exact identifiers)
        if (is_numeric($query)) {
            if ($canSearchUsers) {
                $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
                $stmt->execute([$query]);
                if ($stmt->fetch()) {
                    header("Location: " . AdminUserNavigationService::destinationForUserEdit((int)$query));
                    exit;
                }
            }

            if ($canSearchFiles) {
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
        }

        if ($canSearchFiles && strlen($query) >= 8 && strlen($query) <= 16) {
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

        // 2. Exact hash-backed search for user credentials.
        $users = [];
        if ($canSearchUsers) {
            $lookupHash = User::credentialLookupHash($query);
            if ($lookupHash !== '') {
                $stmt = $db->prepare("SELECT id, username, email FROM users WHERE email_lookup = ? OR username_lookup = ?");
                $stmt->execute([$lookupHash, $lookupHash]);
                $users = $stmt->fetchAll();
            }
        }

        // Files still fall back to the bounded decrypt search because filenames do
        // not yet have a dedicated blind-index column.
        $files = [];

        // 3. Logic: If exactly one result, redirect. Otherwise, show results.
        if (count($users) === 1 && count($files) === 0) {
            header("Location: " . AdminUserNavigationService::destinationForUserEdit((int)$users[0]['id']));
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
            if ($canSearchUsers) {
                $users = EncryptedSearchService::searchUsers($query);
            }
            if ($canSearchFiles) {
                $files = EncryptedSearchService::searchFiles($query);
            }
        }

        if (count($users) === 1 && count($files) === 0) {
            header("Location: " . AdminUserNavigationService::destinationForUserEdit((int)$users[0]['id']));
            exit;
        }

        // 4. Diagnostic Logging (If 0 results, log it so admin can see what they are struggling to find)
        if (empty($users) && empty($files)) {
            Logger::info('admin search miss', [
                'query_sha256' => hash('sha256', $query),
                'query_length' => strlen($query),
                'query_is_numeric' => is_numeric($query),
                'query_has_at_sign' => str_contains($query, '@'),
                'can_search_users' => $canSearchUsers,
                'can_search_files' => $canSearchFiles,
            ]);
        }

        View::render('admin/search_results.php', [
            'query' => $query,
            'users' => $users,
            'files' => $files
        ]);
    }
}
