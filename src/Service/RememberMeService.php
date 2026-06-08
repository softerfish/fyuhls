<?php

namespace App\Service;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Model\Setting;
use PDO;

class RememberMeService
{
    public const COOKIE_NAME = 'fyuhls_remember';
    private const COOKIE_LIFETIME_SECONDS = 2592000; // 30 days
    private const SELECTOR_BYTES = 9;
    private const VALIDATOR_BYTES = 32;
    private static ?bool $schemaReady = null;
    private static $beforeRotationHandler = null;

    public static function enabled(): bool
    {
        return Setting::get('remember_me_enabled', '0') === '1';
    }

    public static function supportsRole(?string $role): bool
    {
        return strtolower(trim((string)$role)) === 'user';
    }

    public static function tokenLifetimeSeconds(): int
    {
        return self::COOKIE_LIFETIME_SECONDS;
    }

    public static function restoreSessionFromCookie(): bool
    {
        if (Auth::check()) {
            return true;
        }

        if (!self::enabled()) {
            self::clearCookie();
            return false;
        }

        $cookie = trim((string)($_COOKIE[self::COOKIE_NAME] ?? ''));
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $validator] = array_pad(explode(':', $cookie, 2), 2, '');
        if ($selector === '' || $validator === '') {
            self::clearCookie();
            return false;
        }

        if (!self::schemaAvailable()) {
            self::clearCookie();
            return false;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT t.*, u.role, u.status
                FROM remember_login_tokens t
                JOIN users u ON u.id = t.user_id
                WHERE t.selector = ?
                  AND t.revoked_at IS NULL
                  AND t.expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$selector]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                self::clearCookie();
                return false;
            }

            $userId = (int)($row['user_id'] ?? 0);
            $role = (string)($row['role'] ?? '');
            $status = (string)($row['status'] ?? '');
            $expectedHash = (string)($row['validator_hash'] ?? '');
            $validatorHash = hash('sha256', $validator);
            $storedUaHash = (string)($row['user_agent_hash'] ?? '');
            $currentUaHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

            if (
                $userId <= 0
                || $status !== 'active'
                || !self::supportsRole($role)
                || $expectedHash === ''
                || !hash_equals($expectedHash, $validatorHash)
                || ($storedUaHash !== '' && !hash_equals($storedUaHash, $currentUaHash))
            ) {
                self::revokeSelectorWithConnection($db, $selector);
                self::clearCookie();
                return false;
            }

            if (is_callable(self::$beforeRotationHandler)) {
                call_user_func(self::$beforeRotationHandler, $db, $selector, $userId);
            }

            if (!self::rotateTokenWithConnection($db, $selector, $userId)) {
                self::revokeSelectorWithConnection($db, $selector);
                self::clearCookie();
                return false;
            }

            Auth::login($userId, $role);
            return true;
        } catch (\Throwable $e) {
            Logger::warning('remember me restore skipped', [
                'error' => $e->getMessage(),
            ]);
            self::clearCookie();
            return false;
        }
    }

    /**
     * @internal Test-only hook for remember-me restore races.
     */
    public static function setBeforeRotationHandlerForTests(?callable $handler): void
    {
        self::$beforeRotationHandler = $handler;
    }

    public static function issueForUser(int $userId, ?string $role = null): void
    {
        if ($userId <= 0 || !self::enabled()) {
            self::clearCookie();
            return;
        }

        $role = $role !== null ? strtolower(trim($role)) : $role;
        if ($role !== null && !self::supportsRole($role)) {
            self::clearCookie();
            return;
        }

        if (!self::schemaAvailable()) {
            self::clearCookie();
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $userRole = $role;
            if ($userRole === null || $userRole === '') {
                $stmt = $db->prepare("SELECT role, status FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $userRole = strtolower(trim((string)($user['role'] ?? '')));
                if ((string)($user['status'] ?? '') !== 'active') {
                    self::clearCookie();
                    return;
                }
            }

            if (!self::supportsRole($userRole)) {
                self::clearCookie();
                return;
            }

            self::issueFreshTokenWithConnection($db, $userId);
        } catch (\Throwable $e) {
            Logger::warning('remember me issuance skipped', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            self::clearCookie();
        }
    }

    public static function revokeCurrentToken(): void
    {
        $cookie = trim((string)($_COOKIE[self::COOKIE_NAME] ?? ''));
        if ($cookie === '' || !str_contains($cookie, ':') || !self::schemaAvailable()) {
            self::clearCookie();
            return;
        }

        [$selector] = array_pad(explode(':', $cookie, 2), 2, '');
        if ($selector === '') {
            self::clearCookie();
            return;
        }

        try {
            self::revokeSelectorWithConnection(Database::getInstance()->getConnection(), $selector);
        } catch (\Throwable $e) {
            Logger::warning('remember me token revoke skipped', [
                'error' => $e->getMessage(),
            ]);
        }

        self::clearCookie();
    }

    public static function revokeAllForUser(int $userId): void
    {
        self::requireRevocationStorage();
        if ($userId <= 0) {
            self::clearCookie();
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            self::revokeAllForUserWithConnection($db, $userId);
        } catch (\Throwable $e) {
            Logger::warning('remember me token bulk revoke failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ((int)(Auth::id() ?? 0) === $userId) {
            self::clearCookie();
        }
    }

    public static function revokeAllTokensWithConnection(PDO $db): void
    {
        self::requireRevocationStorage();

        $db->exec("UPDATE remember_login_tokens SET revoked_at = NOW() WHERE revoked_at IS NULL");
        self::clearCookie();
    }

    public static function revokeAllForUserWithConnection(PDO $db, int $userId): void
    {
        self::requireRevocationStorage();
        if ($userId <= 0) {
            return;
        }

        $stmt = $db->prepare("UPDATE remember_login_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $stmt->execute([$userId]);

        if ((int)(Auth::id() ?? 0) === $userId) {
            self::clearCookie();
        }
    }

    private static function requireRevocationStorage(): void
    {
        if (!self::schemaAvailable()) {
            throw new \RuntimeException('Remember Me session revocation is temporarily unavailable until an administrator repairs the database schema.');
        }
    }

    private static function schemaAvailable(): bool
    {
        if (self::$schemaReady === true) {
            return true;
        }

        try {
            \App\Service\Database\SchemaService::ensureTables(['remember_login_tokens'], false);
            self::$schemaReady = true;
            return true;
        } catch (\Throwable $e) {
            self::$schemaReady = false;
            Logger::warning('remember me storage unavailable', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private static function revokeSelectorWithConnection(PDO $db, string $selector): void
    {
        $stmt = $db->prepare("UPDATE remember_login_tokens SET revoked_at = NOW() WHERE selector = ? AND revoked_at IS NULL");
        $stmt->execute([$selector]);
    }

    private static function issueFreshTokenWithConnection(PDO $db, int $userId): void
    {
        self::revokeAllForUserWithConnection($db, $userId);

        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        $validatorHash = hash('sha256', $validator);
        $userAgentHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $encryptedIp = EncryptionService::encrypt(SecurityService::getClientIp());
        $encryptedUa = EncryptionService::encrypt((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $expiresAt = date('Y-m-d H:i:s', time() + self::COOKIE_LIFETIME_SECONDS);

        $stmt = $db->prepare("
            INSERT INTO remember_login_tokens
                (user_id, selector, validator_hash, user_agent_hash, last_used_ip, user_agent, expires_at, last_used_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $selector,
            $validatorHash,
            $userAgentHash,
            $encryptedIp,
            $encryptedUa,
            $expiresAt,
        ]);

        self::setCookie($selector, $validator, time() + self::COOKIE_LIFETIME_SECONDS);
    }

    private static function rotateTokenWithConnection(PDO $db, string $selector, int $userId): bool
    {
        $newSelector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $newValidator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        $newValidatorHash = hash('sha256', $newValidator);
        $userAgentHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $encryptedIp = EncryptionService::encrypt(SecurityService::getClientIp());
        $encryptedUa = EncryptionService::encrypt((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $expiresAt = date('Y-m-d H:i:s', time() + self::COOKIE_LIFETIME_SECONDS);

        $stmt = $db->prepare("
            UPDATE remember_login_tokens
            SET selector = ?,
                validator_hash = ?,
                user_agent_hash = ?,
                last_used_ip = ?,
                user_agent = ?,
                last_used_at = NOW(),
                expires_at = ?,
                revoked_at = NULL
            WHERE selector = ?
              AND user_id = ?
              AND revoked_at IS NULL
        ");
        $stmt->execute([
            $newSelector,
            $newValidatorHash,
            $userAgentHash,
            $encryptedIp,
            $encryptedUa,
            $expiresAt,
            $selector,
            $userId,
        ]);

        if ($stmt->rowCount() !== 1) {
            return false;
        }

        self::setCookie($newSelector, $newValidator, time() + self::COOKIE_LIFETIME_SECONDS);
        return true;
    }

    private static function setCookie(string $selector, string $validator, int $expiresAt): void
    {
        setcookie(self::COOKIE_NAME, $selector . ':' . $validator, [
            'expires' => $expiresAt,
            'path' => '/',
            'domain' => '',
            'secure' => SecurityService::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $selector . ':' . $validator;
    }

    public static function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'domain' => '',
            'secure' => SecurityService::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }
}
