<?php

namespace App\Service;

use App\Core\Database;
use PDO;

class EncryptedSearchService
{
    public static function searchUsers(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");

        $matches = [];
        while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $username = EncryptionService::decrypt($user['username']);
            $email = EncryptionService::decrypt($user['email']);

            if (
                (string) $user['id'] === $query
                || stripos((string) $username, $query) !== false
                || stripos((string) $email, $query) !== false
            ) {
                $user['username'] = $username;
                $user['email'] = $email;
                $matches[] = $user;
            }
        }

        return $matches;
    }

    public static function searchFiles(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT f.*, u.username, fs.name AS server_name, sf.storage_provider
            FROM files f
            LEFT JOIN users u ON f.user_id = u.id
            LEFT JOIN stored_files sf ON f.stored_file_id = sf.id
            LEFT JOIN file_servers fs ON sf.file_server_id = fs.id
            ORDER BY f.created_at DESC
        ");

        $matches = [];
        while ($file = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $filename = EncryptionService::decrypt($file['filename']);
            $username = EncryptionService::decrypt($file['username']);

            if (
                (string) $file['id'] === $query
                || stripos((string) $file['short_id'], $query) !== false
                || stripos((string) $filename, $query) !== false
                || stripos((string) $username, $query) !== false
            ) {
                $file['filename'] = $filename;
                $file['username'] = $username;

                if (!$file['server_name']) {
                    $file['server_name'] = !empty($file['storage_provider']) ? ucfirst($file['storage_provider']) : 'Local';
                }

                $matches[] = $file;
            }
        }

        return $matches;
    }
}
