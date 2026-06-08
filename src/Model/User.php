<?php

namespace App\Model;

use App\Core\Database;
use App\Core\Config;
use App\Service\EncryptionService;
use App\Service\Database\SchemaService;
use PDO;

class User {
    private static bool $runtimeColumnsReady = false;
    private const FALLBACK_SCAN_BATCH_SIZE = 250;
    private const FALLBACK_SCAN_LOG_THRESHOLD = 5000;
    private const TOKEN_HASH_PREFIX = 'sha256:';
    private const CREDENTIAL_LOCK_TIMEOUT_SECONDS = 5;

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
     * lookup and then to a decrypt-and-compare recovery scan without mutating
     * account rows during ordinary runtime requests.
     */
    public static function findByCredentials(string $usernameOrEmail, int $excludeUserId = 0): ?array {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);

        $normalized = self::normalizeCredentialValue($usernameOrEmail);
        if ($normalized === '') {
            return null;
        }

        $lookupHash = self::buildCredentialLookupHash($normalized);
        if ($lookupHash !== '') {
            $sql = "SELECT * FROM users WHERE (username_lookup = ? OR email_lookup = ?)";
            $params = [$lookupHash, $lookupHash];
            if ($excludeUserId > 0) {
                $sql .= " AND id <> ?";
                $params[] = $excludeUserId;
            }
            $sql .= " LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch();
            if ($user) {
                return self::decryptRow($user);
            }
        }

        $legacyEnc = self::encryptLegacyLookupValue($normalized);
        if ($legacyEnc !== null) {
            $sql = "SELECT * FROM users WHERE (username = ? OR email = ?)";
            $params = [$legacyEnc, $legacyEnc];
            if ($excludeUserId > 0) {
                $sql .= " AND id <> ?";
                $params[] = $excludeUserId;
            }
            $sql .= " LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch();
            if ($user) {
                return self::decryptRow($user);
            }
        }

        $matchedUser = self::findByFallbackDecryptScan($db, $normalized, $usernameOrEmail, $excludeUserId);
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

    /**
     * @return array<int, string>
     */
    public static function lockCredentialValues(PDO $db, array $values, int $timeoutSeconds = self::CREDENTIAL_LOCK_TIMEOUT_SECONDS): array
    {
        $lockKeys = [];
        $stmt = $db->prepare("SELECT GET_LOCK(?, ?)");

        try {
            foreach (self::credentialLockKeys($values) as $lockKey) {
                $stmt->execute([$lockKey, $timeoutSeconds]);
                if ((int)$stmt->fetchColumn() !== 1) {
                    throw new \RuntimeException('Could not acquire the account credential integrity lock.');
                }
                $lockKeys[] = $lockKey;
            }
        } catch (\Throwable $e) {
            self::releaseCredentialLocks($db, $lockKeys);
            throw $e;
        }

        return $lockKeys;
    }

    /**
     * @param array<int, string> $lockKeys
     */
    public static function releaseCredentialLocks(PDO $db, array $lockKeys): void
    {
        if ($lockKeys === []) {
            return;
        }

        $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
        foreach (array_reverse($lockKeys) as $lockKey) {
            $stmt->execute([$lockKey]);
        }
    }

    public static function assertCredentialsAvailable(PDO $db, ?string $username, ?string $email, int $excludeUserId = 0): void
    {
        self::ensureRuntimeColumns($db);

        $normalizedUsername = self::normalizeCredentialValue($username);
        if ($normalizedUsername !== '') {
            $existingUser = self::findByCredentials($normalizedUsername, $excludeUserId);
            if ($existingUser) {
                throw new \RuntimeException('Username or email already taken.');
            }
        }

        $normalizedEmail = self::normalizeCredentialValue($email);
        if ($normalizedEmail !== '') {
            $existingEmailOwner = self::findByEmailOrPendingEmail($normalizedEmail, $excludeUserId);
            if ($existingEmailOwner) {
                throw new \RuntimeException('That email address is already in use or waiting to be confirmed.');
            }
        }
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

        SchemaService::ensureTables(['users'], false);
        self::$runtimeColumnsReady = true;
    }

    private static function hashStoredOneTimeToken(string $token): string
    {
        return self::TOKEN_HASH_PREFIX . hash('sha256', $token);
    }

    private static function migrateLegacyOneTimeTokens($db): void
    {
        foreach (['verification_token', 'email_change_token', 'reset_token'] as $column) {
            try {
                $stmt = $db->prepare("SELECT id, {$column} FROM users WHERE {$column} IS NOT NULL AND {$column} <> '' AND {$column} NOT LIKE ?");
                $stmt->execute([self::TOKEN_HASH_PREFIX . '%']);
            } catch (\PDOException $e) {
                continue;
            }

            $update = $db->prepare("UPDATE users SET {$column} = ? WHERE id = ?");
            foreach ($stmt->fetchAll() as $row) {
                $rawToken = trim((string)($row[$column] ?? ''));
                if ($rawToken === '') {
                    continue;
                }

                $update->execute([self::hashStoredOneTimeToken($rawToken), (int)$row['id']]);
            }
        }
    }

    private static function migrateLegacyTrustedDeviceTokens($db): void
    {
        try {
            $stmt = $db->query("SELECT id, trust_token FROM user_two_factor_devices WHERE trust_token IS NOT NULL AND trust_token <> ''");
        } catch (\PDOException $e) {
            return;
        }

        $update = $db->prepare("UPDATE user_two_factor_devices SET trust_token = ? WHERE id = ?");
        foreach ($stmt->fetchAll() as $row) {
            $token = trim((string)($row['trust_token'] ?? ''));
            if ($token === '' || preg_match('/^[a-f0-9]{64}$/i', $token)) {
                continue;
            }

            $update->execute([hash('sha256', $token), (int)$row['id']]);
        }
    }

    private static function backfillMissingPublicIds($db): void {
        $stmt = $db->query("SELECT id FROM users WHERE public_id IS NULL OR public_id = ''");
        $users = $stmt ? ($stmt->fetchAll() ?: []) : [];
        if ($users === []) {
            return;
        }

        $update = $db->prepare("UPDATE users SET public_id = ? WHERE id = ?");
        foreach ($users as $u) {
            $pid = 'u_' . bin2hex(random_bytes(6));
            $update->execute([$pid, $u['id']]);
        }
    }

    private static function backfillProtectedSuperAdmin($db): void {
        $db->exec("UPDATE users SET is_super_admin = 0 WHERE is_super_admin = 1 AND (role <> 'admin' OR status <> 'active')");

        $stmt = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' AND is_super_admin = 1 ORDER BY created_at ASC, id ASC");
        $protectedIds = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        if (count($protectedIds) === 1) {
            return;
        }

        error_log(
            'Protected super admin review required: runtime compatibility repair detected '
            . count($protectedIds)
            . ' active protected admin account(s). Review the users table manually instead of auto-assigning ownership during upgrade.'
        );
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

    public static function credentialLookupHash(?string $value): string
    {
        return self::buildCredentialLookupHash($value);
    }

    /**
     * @return array<int, string>
     */
    private static function credentialLockKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            $normalized = self::normalizeCredentialValue((string)$value);
            if ($normalized === '') {
                continue;
            }

            $lookupHash = self::buildCredentialLookupHash($normalized);
            $keys[] = 'user_credential:' . ($lookupHash !== '' ? $lookupHash : sha1($normalized));
        }

        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }

    public static function findByEmailOrPendingEmail(string $email, int $excludeUserId = 0): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);

        $normalized = self::normalizeCredentialValue($email);
        if ($normalized === '') {
            return null;
        }

        $lookupHash = self::buildCredentialLookupHash($normalized);
        if ($lookupHash !== '') {
            $sql = "SELECT * FROM users WHERE (email_lookup = ? OR (pending_email_lookup = ? AND (email_change_expires IS NULL OR email_change_expires > NOW())))";
            $params = [$lookupHash, $lookupHash];
            if ($excludeUserId > 0) {
                $sql .= " AND id <> ?";
                $params[] = $excludeUserId;
            }
            $sql .= " LIMIT 1";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch();
            if ($user) {
                return self::decryptRow($user);
            }
        }

        $matchedUser = self::findByEmailFallbackDecryptScan($db, $normalized, $email, $excludeUserId);
        if ($matchedUser !== null) {
            return self::decryptRow($matchedUser);
        }

        return null;
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

    private static function backfillPendingEmailLookupForUser($db, array $user): void {
        $pendingEmailLookup = self::buildCredentialLookupHash(EncryptionService::decrypt((string)($user['pending_email'] ?? '')));
        if (($user['pending_email_lookup'] ?? null) === ($pendingEmailLookup !== '' ? $pendingEmailLookup : null)) {
            return;
        }

        $stmt = $db->prepare("UPDATE users SET pending_email_lookup = ? WHERE id = ?");
        $stmt->execute([$pendingEmailLookup !== '' ? $pendingEmailLookup : null, (int)$user['id']]);
    }

    private static function backfillMissingCredentialLookups($db, int $limit = 250): void {
        $limit = max(1, min(1000, $limit));
        $stmt = $db->query("SELECT * FROM users WHERE username_lookup IS NULL OR email_lookup IS NULL OR (pending_email IS NOT NULL AND pending_email_lookup IS NULL) LIMIT {$limit}");
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        foreach ($rows as $row) {
            self::backfillLookupColumnsForUser($db, $row);
            self::backfillPendingEmailLookupForUser($db, $row);
        }
    }

    private static function findByFallbackDecryptScan($db, string $normalized, string $rawInput, int $excludeUserId = 0): ?array
    {
        $rowsScanned = 0;
        $lastId = 0;

        while (true) {
            $stmt = $db->prepare("
                SELECT * FROM users
                WHERE id > ?
                ORDER BY id ASC
                LIMIT " . self::FALLBACK_SCAN_BATCH_SIZE . "
            ");
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll() ?: [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $rowsScanned++;
                $lastId = max($lastId, (int)($row['id'] ?? 0));

                if ($excludeUserId > 0 && (int)($row['id'] ?? 0) === $excludeUserId) {
                    continue;
                }

                $username = self::normalizeCredentialValue(EncryptionService::decrypt((string)($row['username'] ?? '')));
                $email = self::normalizeCredentialValue(EncryptionService::decrypt((string)($row['email'] ?? '')));
                if ($username === $normalized || $email === $normalized) {
                    if ($rowsScanned > self::FALLBACK_SCAN_LOG_THRESHOLD) {
                        \App\Core\Logger::warning('Credential lookup required a full decrypt scan compatibility match', [
                            'input_length' => strlen($rawInput),
                            'rows_scanned' => $rowsScanned,
                        ]);
                    }
                    return $row;
                }
            }
        }

        if ($rowsScanned > 0) {
            \App\Core\Logger::warning('Credential lookup exhausted decrypt scan without a match', [
                'input_length' => strlen($rawInput),
                'rows_scanned' => $rowsScanned,
                'scan_truncated' => false,
            ]);
        }

        return null;
    }

    private static function findByEmailFallbackDecryptScan($db, string $normalized, string $rawInput, int $excludeUserId = 0): ?array
    {
        $rowsScanned = 0;
        $lastId = 0;

        while (true) {
            $sql = "
                SELECT * FROM users
                WHERE id > ?
            ";
            $params = [$lastId];
            if ($excludeUserId > 0) {
                $sql .= " AND id <> ?";
                $params[] = $excludeUserId;
            }
            $sql .= "
                ORDER BY id ASC
                LIMIT " . self::FALLBACK_SCAN_BATCH_SIZE . "
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $rowsScanned++;
                $lastId = max($lastId, (int)($row['id'] ?? 0));

                $email = self::normalizeCredentialValue(EncryptionService::decrypt((string)($row['email'] ?? '')));
                $pendingEmail = null;
                $pendingEmailExpires = (string)($row['email_change_expires'] ?? '');
                if ($pendingEmailExpires === '' || strtotime($pendingEmailExpires) > time()) {
                    $pendingEmail = self::normalizeCredentialValue(EncryptionService::decrypt((string)($row['pending_email'] ?? '')));
                }
                if ($email === $normalized || $pendingEmail === $normalized) {
                    if ($rowsScanned > self::FALLBACK_SCAN_LOG_THRESHOLD) {
                        \App\Core\Logger::warning('Email uniqueness check required a full decrypt scan compatibility match', [
                            'input_length' => strlen($rawInput),
                            'rows_scanned' => $rowsScanned,
                        ]);
                    }
                    return $row;
                }
            }
        }

        if ($rowsScanned > 0) {
            \App\Core\Logger::warning('Email uniqueness check exhausted decrypt scan without a match', [
                'input_length' => strlen($rawInput),
                'rows_scanned' => $rowsScanned,
                'scan_truncated' => false,
            ]);
        }

        return null;
    }
}
