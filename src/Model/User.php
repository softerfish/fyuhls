<?php

namespace App\Model;

use App\Core\Database;
use App\Service\EncryptionService;
use PDO;

class User {
    
    /**
     * Find a user by their internal ID
     */
    public static function find(int $id): ?array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ? self::decryptRow($user) : null;
    }

    /**
     * Find a user by their non-guessable Public ID (e.g. u_8j2kL9m1)
     */
    public static function findByPublicId(string $publicId): ?array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE public_id = ?");
        $stmt->execute([$publicId]);
        $user = $stmt->fetch();
        return $user ? self::decryptRow($user) : null;
    }

    /**
     * Find a user by encrypted username or email (for login)
     */
    public static function findByCredentials(string $usernameOrEmail): ?array {
        $db = Database::getInstance()->getConnection();
        $enc = EncryptionService::encrypt($usernameOrEmail);
        
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$enc, $enc]);
        $user = $stmt->fetch();
        return $user ? self::decryptRow($user) : null;
    }

    /**
     * Create a new user with a secure Public ID
     */
    public static function create(array $data): int {
        $db = Database::getInstance()->getConnection();
        self::ensurePublicIdColumnExists($db);

        $publicId = 'u_' . bin2hex(random_bytes(6)); // e.g. u_a1b2c3d4e5f6
        
        $sql = "INSERT INTO users (public_id, username, email, password, role, package_id, referrer_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $publicId,
            EncryptionService::encrypt($data['username']),
            EncryptionService::encrypt($data['email']),
            $data['password'],
            $data['role'] ?? 'user',
            $data['package_id'] ?? 2,
            $data['referrer_id'] ?? null
        ]);

        $userId = (int)$db->lastInsertId();
        if ($userId) {
            \App\Service\SystemStatsService::increment('total_users');
        }

        return $userId;
    }

    public static function decryptRow(array $user): array {
        if (!EncryptionService::isReady()) return $user;

        $encCols = \App\Service\Database\SchemaService::getEncryptedColumns('users');
        foreach ($encCols as $col) {
            if (isset($user[$col]) && is_string($user[$col]) && str_starts_with($user[$col], 'ENC:')) {
                $user[$col] = EncryptionService::decrypt($user[$col]);
            }
        }
        return $user;
    }

    /**
     * Self-healing: Ensure public_id column exists
     */
    public static function ensurePublicIdColumnExists($db): void {
        try {
            $db->query("SELECT public_id FROM users LIMIT 1");
        } catch (\PDOException $e) {
            // Column missing, add it
            $db->exec("ALTER TABLE users ADD COLUMN public_id VARCHAR(16) AFTER id");
            $db->exec("CREATE UNIQUE INDEX public_id_idx ON users(public_id)");
            
            // Seed existing users with public IDs
            $stmt = $db->query("SELECT id FROM users WHERE public_id IS NULL OR public_id = ''");
            $users = $stmt->fetchAll();
            $update = $db->prepare("UPDATE users SET public_id = ? WHERE id = ?");
            foreach ($users as $u) {
                $pid = 'u_' . bin2hex(random_bytes(6));
                $update->execute([$pid, $u['id']]);
            }
        }
    }
}
