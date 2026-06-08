<?php

namespace App\Core;

use App\Model\Setting;
use App\Service\Database\SchemaService;

class Auth {
    private static ?array $sessionUserMetaCache = null;
    private static ?bool $activityLogSchemaAvailable = null;
    private const DEFAULT_IDLE_LOGOUT_MINUTES = [
        'admin' => 240,
        'moderator' => 480,
        'user' => 43200,
    ];
    private const ALLOWED_IDLE_LOGOUT_MINUTES = [240, 480, 720, 1440, 10080, 20160, 43200];

    public static function denyAccess(string $message, int $status = 403, string $title = 'Access Denied'): void
    {
        http_response_code($status);

        View::render('home/access_denied.php', [
            'accessDeniedTitle' => $title,
            'accessDeniedMessage' => $message,
            'isLoggedIn' => self::check(),
            'isStaffUser' => self::isStaff(),
        ]);
        exit;
    }

    public static function logActivity(string $type, ?string $description = null): void {
        $userId = self::id();
        if ($userId === null) {
            return;
        }

        if (!self::activityLogAvailable()) {
            return;
        }

        $ip = \App\Service\SecurityService::getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $db = Database::getInstance()->getConnection();

        $encDescription = $description !== null && $description !== ''
            ? \App\Service\EncryptionService::encrypt($description)
            : null;
        $encIp = \App\Service\EncryptionService::encrypt($ip);
        $encUa = \App\Service\EncryptionService::encrypt($ua);

        try {
            $stmt = $db->prepare("INSERT INTO user_activity_log (user_id, activity_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $type, $encDescription, $encIp, $encUa]);
        } catch (\Throwable $e) {
            Logger::warning('user activity log write skipped', [
                'user_id' => $userId,
                'activity_type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function activityLogAvailable(): bool
    {
        if (self::$activityLogSchemaAvailable === true) {
            return true;
        }

        try {
            SchemaService::ensureTables(['user_activity_log'], false);
            self::$activityLogSchemaAvailable = true;
            return true;
        } catch (\Throwable $e) {
            self::$activityLogSchemaAvailable = false;
            Logger::warning('user activity log schema unavailable', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public static function login(int $userId, string $role): void {
        if (session_status() === PHP_SESSION_NONE) session_start();

        session_regenerate_id(true); // Prevent Session Fixation
        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = $role;
        $_SESSION['last_activity'] = time();
        unset($_SESSION['2fa_verified']);
        unset($_SESSION['session_version']);
        self::$sessionUserMetaCache = null;
    }

    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        \App\Service\RememberMeService::revokeCurrentToken();
        $_SESSION = [];
        self::$sessionUserMetaCache = null;

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_unset();
        session_destroy();
    }

    public static function allowedIdleLogoutMinuteOptions(): array
    {
        return self::ALLOWED_IDLE_LOGOUT_MINUTES;
    }

    public static function defaultIdleLogoutMinutesForRole(?string $role): int
    {
        $normalizedRole = strtolower(trim((string)$role));
        return self::DEFAULT_IDLE_LOGOUT_MINUTES[$normalizedRole] ?? self::DEFAULT_IDLE_LOGOUT_MINUTES['user'];
    }

    public static function idleLogoutMinutesForRole(?string $role): int
    {
        $normalizedRole = strtolower(trim((string)$role));
        $settingKey = match ($normalizedRole) {
            'admin' => 'admin_idle_logout_minutes',
            'moderator' => 'moderator_idle_logout_minutes',
            default => 'user_idle_logout_minutes',
        };

        $default = self::defaultIdleLogoutMinutesForRole($normalizedRole);
        $configured = (int)Setting::get($settingKey, (string)$default);
        return in_array($configured, self::ALLOWED_IDLE_LOGOUT_MINUTES, true) ? $configured : $default;
    }

    public static function idleLogoutSecondsForRole(?string $role): int
    {
        return self::idleLogoutMinutesForRole($role) * 60;
    }

    public static function check(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return self::getSessionUserMeta() !== null;
    }

    public static function id(): ?int {
        if (!self::check()) {
            return null;
        }

        return (int)($_SESSION['user_id'] ?? 0) ?: null;
    }

    public static function isAdmin(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userMeta = self::getSessionUserMeta();
        return $userMeta !== null && ($userMeta['role'] ?? '') === 'admin';
    }

    public static function isSuperAdmin(): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userMeta = self::getSessionUserMeta();
        return $userMeta !== null && !empty($userMeta['is_super_admin']);
    }

    public static function isModerator(): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userMeta = self::getSessionUserMeta();
        return $userMeta !== null && ($userMeta['role'] ?? '') === 'moderator';
    }

    public static function isStaff(): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userMeta = self::getSessionUserMeta();
        $role = (string)($userMeta['role'] ?? '');
        return in_array($role, ['admin', 'moderator'], true);
    }

    public static function role(): ?string
    {
        $userMeta = self::getSessionUserMeta();
        return $userMeta !== null ? (string)($userMeta['role'] ?? '') : null;
    }

    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            self::denyAccess('Admin privileges are required to open this area.');
        }
    }

    public static function requireStaff(): void
    {
        if (!self::isStaff()) {
            self::denyAccess('A staff account is required to open this area.');
        }
    }

    public static function hasCapability(string $capability): bool
    {
        if (!self::isStaff()) {
            return false;
        }

        if (self::isSuperAdmin()) {
            return true;
        }

        $userId = self::id();
        $role = self::role();
        if ($userId === null || $role === null) {
            return false;
        }

        return \App\Service\StaffPermissionService::userHasCapability($userId, $role, $capability);
    }

    public static function hasAnyCapability(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (self::hasCapability((string)$capability)) {
                return true;
            }
        }

        return false;
    }

    public static function requireCapability(string $capability): void
    {
        if (!self::hasCapability($capability)) {
            self::denyAccess('Additional staff permission is required to open this area.');
        }
    }

    public static function requireAnyCapability(array $capabilities): void
    {
        if (!self::hasAnyCapability($capabilities)) {
            self::denyAccess('Additional staff permission is required to open this area.');
        }
    }

    public static function username(): ?string
    {
        $user = self::user();
        return $user ? (string)($user['username'] ?? '') : null;
    }

    /**
     * Get the currently logged in user's full database record, automatically decrypted.
     */
    public static function user(): ?array {
        $id = self::id();
        if (!$id) return null;

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if ($user) {
            $user['username'] = \App\Service\EncryptionService::decrypt($user['username']);
            $user['email'] = \App\Service\EncryptionService::decrypt($user['email']);
            if (isset($user['payment_details'])) {
                $user['payment_details'] = \App\Service\EncryptionService::decrypt($user['payment_details']);
            }
            if (isset($user['api_key'])) {
                $user['api_key'] = \App\Service\EncryptionService::decrypt($user['api_key']);
            }
        }

        return $user ?: null;
    }

    private static function getSessionUserMeta(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            self::$sessionUserMetaCache = null;
            return null;
        }

        if (self::$sessionUserMetaCache !== null && (int)(self::$sessionUserMetaCache['id'] ?? 0) === $userId) {
            return self::$sessionUserMetaCache;
        }

        try {
            $db = Database::getInstance()->getConnection();
            try {
                $stmt = $db->prepare("SELECT id, role, status, is_super_admin, session_version FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            } catch (\PDOException $e) {
                \App\Model\User::ensureRuntimeColumns($db);
                $stmt = $db->prepare("SELECT id, role, status, is_super_admin, session_version FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            }

            if (!$user || ($user['status'] ?? '') !== 'active') {
                self::logout();
                return null;
            }

            $currentSessionVersion = (int)($user['session_version'] ?? 1);
            $storedSessionVersion = (int)($_SESSION['session_version'] ?? 0);
            if ($storedSessionVersion > 0 && $storedSessionVersion !== $currentSessionVersion) {
                self::logout();
                return null;
            }

            $_SESSION['role'] = $user['role'] ?? 'user';
            $_SESSION['session_version'] = $currentSessionVersion;
            self::$sessionUserMetaCache = $user;
            return $user;
        } catch (\Throwable $e) {
            self::logout();
            return null;
        }
    }
}
