<?php

namespace App\Model;

use App\Core\Database;
use App\Core\Config;
use App\Service\EncryptionService;
use PDO;

class User {
    private static bool $runtimeColumnsReady = false;
    private const FALLBACK_SCAN_BATCH_SIZE = 250;
    private const FALLBACK_SCAN_MAX_ROWS = 5000;
    
    /**
     * Find a user by their internal ID
     */
    public static function find(int $id): ?array {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
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
        self::ensureRuntimeColumns($db);
        $stmt = $db->prepare("SELECT * FROM users WHERE public_id = ?");
        $stmt->execute([$publicId]);
        $user = $stmt->fetch();
        return $user ? self::decryptRow($user) : null;
    }

    /**
     * Find a user by username or email for login and recovery flows.
     *
     * New installs use blind-index lookup columns so login no longer depends on
     * deterministic ciphertext. Older installs fall back to legacy deterministic
     * lookup and then to a decrypt-and-compare recovery scan, backfilling the
     * lookup columns on success.
     */
    public static function findByCredentials(string $usernameOrEmail): ?array {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);

        $normalized = self::normalizeCredentialValue($usernameOrEmail);
        if ($normalized === '') {
            return null;
        }

        $lookupHash = self::buildCredentialLookupHash($normalized);
        if ($lookupHash !== '') {
            $stmt = $db->prepare("SELECT * FROM users WHERE username_lookup = ? OR email_lookup = ? LIMIT 1");
            $stmt->execute([$lookupHash, $lookupHash]);
            $user = $stmt->fetch();
            if ($user) {
                self::backfillLookupColumnsForUser($db, $user);
                return self::decryptRow($user);
            }
        }

        $legacyEnc = self::encryptLegacyLookupValue($normalized);
        if ($legacyEnc !== null) {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$legacyEnc, $legacyEnc]);
            $user = $stmt->fetch();
            if ($user) {
                self::backfillLookupColumnsForUser($db, $user);
                return self::decryptRow($user);
            }
        }

        $matchedUser = self::findByFallbackDecryptScan($db, $normalized, $usernameOrEmail);
        if ($matchedUser !== null) {
            return self::decryptRow($matchedUser);
        }

        return null;
    }

    /**
     * Create a new user with a secure Public ID
     */
    public static function create(array $data): int {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);

        $publicId = 'u_' . bin2hex(random_bytes(6)); // e.g. u_a1b2c3d4e5f6
        $username = (string)($data['username'] ?? '');
        $email = (string)($data['email'] ?? '');
        $usernameLookup = self::buildCredentialLookupHash($username);
        $emailLookup = self::buildCredentialLookupHash($email);
        
        $sql = "INSERT INTO users (public_id, username, email, username_lookup, email_lookup, password, role, package_id, referrer_id, referrer_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $publicId,
            EncryptionService::encrypt($username),
            EncryptionService::encrypt($email),
            $usernameLookup,
            $emailLookup,
            $data['password'],
            $data['role'] ?? 'user',
            $data['package_id'] ?? 2,
            $data['referrer_id'] ?? null,
            $data['referrer_source'] ?? null,
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
     * Self-healing: Ensure runtime compatibility columns exist.
     */
    public static function ensureRuntimeColumns($db): void {
        if (self::$runtimeColumnsReady) {
            return;
        }

        self::ensurePublicIdColumnExists($db);
        self::ensureCredentialLookupColumnsExist($db);
        self::ensureReferrerSourceColumnExists($db);
        self::backfillMissingCredentialLookups($db);
        self::$runtimeColumnsReady = true;
    }

    private static function ensurePublicIdColumnExists($db): void {
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

    private static function ensureCredentialLookupColumnsExist($db): void {
        try {
            $db->query("SELECT username_lookup FROM users LIMIT 1");
        } catch (\PDOException $e) {
            $db->exec("ALTER TABLE users ADD COLUMN username_lookup CHAR(64) NULL AFTER username");
        }

        try {
            $db->query("SELECT email_lookup FROM users LIMIT 1");
        } catch (\PDOException $e) {
            $db->exec("ALTER TABLE users ADD COLUMN email_lookup CHAR(64) NULL AFTER email");
        }

        try {
            $db->exec("CREATE INDEX users_username_lookup_idx ON users(username_lookup)");
        } catch (\PDOException $e) {
        }

        try {
            $db->exec("CREATE INDEX users_email_lookup_idx ON users(email_lookup)");
        } catch (\PDOException $e) {
        }
    }

    private static function ensureReferrerSourceColumnExists($db): void {
        try {
            $db->query("SELECT referrer_source FROM users LIMIT 1");
        } catch (\PDOException $e) {
            $db->exec("ALTER TABLE users ADD COLUMN referrer_source VARCHAR(20) NULL AFTER referrer_id");
        }
    }

    private static function normalizeCredentialValue(?string $value): string {
        return mb_strtolower(trim((string)$value));
    }

    private static function getCredentialLookupSecret(): string {
        $secret = (string)Config::get('security.encryption_key', '');
        if ($secret !== '') {
            return $secret;
        }
        return (string)Config::get('app_key', '');
    }

    private static function buildCredentialLookupHash(?string $value): string {
        $normalized = self::normalizeCredentialValue($value);
        $secret = self::getCredentialLookupSecret();
        if ($normalized === '' || $secret === '') {
            return '';
        }

        return hash_hmac('sha256', $normalized, $secret);
    }

    private static function encryptLegacyLookupValue(string $value): ?string {
        if ($value === '' || !EncryptionService::isReady()) {
            return null;
        }

        $configuredKey = (string)Config::get('security.encryption_key', '');
        $decoded = base64_decode($configuredKey, true);
        $key = ($decoded !== false && strlen($decoded) === 32) ? $decoded : $configuredKey;
        if ($key === '' || strlen($key) !== 32) {
            return null;
        }

        $iv = substr(hash_hmac('sha256', $value, $key, true), 0, 16);
        $ciphertext = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return null;
        }

        return 'ENC:' . base64_encode($iv . $ciphertext);
    }

    private static function backfillLookupColumnsForUser($db, array $user): void {
        $usernameLookup = self::buildCredentialLookupHash(EncryptionService::decrypt((string)($user['username'] ?? '')));
        $emailLookup = self::buildCredentialLookupHash(EncryptionService::decrypt((string)($user['email'] ?? '')));
        if ($usernameLookup === '' && $emailLookup === '') {
            return;
        }

        if (($user['username_lookup'] ?? null) === $usernameLookup && ($user['email_lookup'] ?? null) === $emailLookup) {
            return;
        }

        $stmt = $db->prepare("UPDATE users SET username_lookup = ?, email_lookup = ? WHERE id = ?");
        $stmt->execute([$usernameLookup !== '' ? $usernameLookup : null, $emailLookup !== '' ? $emailLookup : null, (int)$user['id']]);
    }

    private static function backfillMissingCredentialLookups($db, int $limit = 250): void {
        $limit = max(1, min(1000, $limit));
        $stmt = $db->query("SELECT * FROM users WHERE username_lookup IS NULL OR email_lookup IS NULL LIMIT {$limit}");
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        foreach ($rows as $row) {
            self::backfillLookupColumnsForUser($db, $row);
        }
    }

    private static function findByFallbackDecryptScan($db, string $normalized, string $rawInput): ?array
    {
        $rowsScanned = 0;
        $lastId = 0;

        while ($rowsScanned < self::FALLBACK_SCAN_MAX_ROWS) {
            $remaining = self::FALLBACK_SCAN_MAX_ROWS - $rowsScanned;
            $batchSize = min(self::FALLBACK_SCAN_BATCH_SIZE, $remaining);

            $stmt = $db->prepare("
                SELECT * FROM users
                WHERE id > ?
                ORDER BY id ASC
                LIMIT {$batchSize}
            ");
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll() ?: [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $rowsScanned++;
                $lastId = max($lastId, (int)($row['id'] ?? 0));

                $username = self::normalizeCredentialValue(EncryptionService::decrypt((string)($row['username'] ?? '')));
                $email = self::normalizeCredentialValue(EncryptionService::decrypt((string)($row['email'] ?? '')));
                if ($username === $normalized || $email === $normalized) {
                    self::backfillLookupColumnsForUser($db, $row);
                    if ($rowsScanned > self::FALLBACK_SCAN_BATCH_SIZE) {
                        \App\Core\Logger::warning('Credential lookup required bounded decrypt scan match', [
                            'input_length' => strlen($rawInput),
                            'rows_scanned' => $rowsScanned,
                        ]);
                    }
                    return $row;
                }
            }
        }

        if ($rowsScanned > 0) {
            \App\Core\Logger::warning('Credential lookup exhausted bounded decrypt scan without a match', [
                'input_length' => strlen($rawInput),
                'rows_scanned' => $rowsScanned,
                'scan_truncated' => $rowsScanned >= self::FALLBACK_SCAN_MAX_ROWS,
            ]);
        }

        return null;
    }
}
