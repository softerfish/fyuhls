<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Model\User;
use App\Service\EncryptionService;
use App\Service\FeatureService;
use App\Service\PackageAllowanceService;
use App\Service\RateLimiterService;
use App\Service\SecurityService;
use App\Service\TwoFactor\TotpService;

class TwoFactorController
{
    private const STEP_UP_RATE_LIMIT = 5;
    private const STEP_UP_RATE_WINDOW = 600;
    private const SETUP_AUTH_WINDOW = 900;

    private TotpService $totp;

    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    public function __construct()
    {
        $this->totp = new TotpService();
    }

    private function isHttpsRequest(): bool
    {
        return \App\Service\SecurityService::isHttpsRequest();
    }

    private function hashTrustedDeviceToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function rateLimitWindowSeconds(): int
    {
        return 600;
    }

    private function hasFreshSetupAuthorization(): bool
    {
        return (int)($_SESSION['2fa_setup_authorized_until'] ?? 0) >= time();
    }

    private function markFreshSetupAuthorization(): void
    {
        $_SESSION['2fa_setup_authorized_until'] = time() + self::SETUP_AUTH_WINDOW;
    }

    private function clearSetupAuthorization(): void
    {
        unset($_SESSION['2fa_setup_authorized_until']);
    }

    private function twoFactorAttemptLimit(string $settingKey, int $default): int
    {
        return max(1, (int)\App\Model\Setting::get($settingKey, (string)$default, 'security'));
    }

    private function guardTwoFactorAttempt(string $actionKey, string $settingKey, int $defaultLimit, int $userId, callable $attempt): array
    {
        if ($userId <= 0) {
            return ['allowed' => false, 'verified' => false];
        }

        $limit = $this->twoFactorAttemptLimit($settingKey, $defaultLimit);
        $window = $this->rateLimitWindowSeconds();
        $ip = SecurityService::getClientIp();

        $result = RateLimiterService::guardAttempt([
            [
                'action' => $actionKey . '_user',
                'key' => (string)$userId,
                'limit' => $limit,
                'window' => $window,
            ],
            [
                'action' => $actionKey . '_ip',
                'key' => $ip,
                'limit' => max($limit * 2, $limit),
                'window' => $window,
            ],
        ], $attempt);

        return [
            'allowed' => !empty($result['allowed']),
            'verified' => !empty($result['result']),
        ];
    }

    private function verifyCurrentPassword(int $userId, ?string $password): bool
    {
        $password = (string)$password;
        if ($userId <= 0 || $password === '') {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = (string)($stmt->fetchColumn() ?: '');
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    private function verifySensitivePasswordStepUp(int $userId, string $actionKey, ?string $password): array
    {
        if ($userId <= 0) {
            return ['allowed' => false, 'verified' => false];
        }

        $ip = SecurityService::getClientIp();
        $ipLimit = max(self::STEP_UP_RATE_LIMIT * 2, 10);
        $result = RateLimiterService::guardAttempt([
            [
                'action' => $actionKey . '_ip',
                'key' => $ip,
                'limit' => $ipLimit,
                'window' => self::STEP_UP_RATE_WINDOW,
            ],
            [
                'action' => $actionKey . '_user',
                'key' => (string)$userId,
                'limit' => self::STEP_UP_RATE_LIMIT,
                'window' => self::STEP_UP_RATE_WINDOW,
            ],
        ], fn() => $this->verifyCurrentPassword($userId, $password));

        return [
            'allowed' => !empty($result['allowed']),
            'verified' => !empty($result['result']),
        ];
    }

    private function consumeRecoveryCode(int $userId, string $inputCode): bool
    {
        if ($userId <= 0 || $inputCode === '') {
            return false;
        }

        $db = Database::getInstance()->getConnection();

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT recovery_codes FROM user_two_factor WHERE user_id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $fa = $stmt->fetch();

            if (!$fa) {
                $db->commit();
                return false;
            }

            $codes = json_decode(EncryptionService::decrypt($fa['recovery_codes']), true);
            if (!is_array($codes)) {
                $db->commit();
                return false;
            }

            $key = array_search($inputCode, $codes, true);
            if ($key === false) {
                $db->commit();
                return false;
            }

            unset($codes[$key]);
            $newCodes = EncryptionService::encrypt(json_encode(array_values($codes)));
            $db->prepare("UPDATE user_two_factor SET recovery_codes = ? WHERE user_id = ?")->execute([$newCodes, $userId]);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    private function storageQuotaInfo(int $userId): array
    {
        $stmt = Database::getInstance()->getConnection()->prepare('SELECT storage_used FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $used = (int)($stmt->fetchColumn() ?: 0);

        $package = \App\Model\Package::getUserPackage($userId);
        $limit = (int)($package['max_storage_bytes'] ?? 0);

        return ['used' => $used, 'limit' => $limit];
    }

    public function showSetup()
    {
        if (!FeatureService::twoFactorEnabled()) {
            http_response_code(404);
            exit('Not found');
        }

        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $userId = Auth::id();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM user_two_factor WHERE user_id = ?");
        $stmt->execute([$userId]);
        $fa = $stmt->fetch();

        if ($fa && (int) $fa['is_enabled'] === 1) {
            $_SESSION['2fa_error'] = '2FA is already enabled.';
            header('Location: /settings');
            exit;
        }

        $setupAuthorized = $this->hasFreshSetupAuthorization();

        if ($setupAuthorized && !isset($_SESSION['2fa_secret'])) {
            $_SESSION['2fa_secret'] = $this->totp->createSecret();
            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $codes[] = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            }
            $_SESSION['2fa_recovery'] = $codes;
        }

        $user = Auth::user();
        $qrUrl = $setupAuthorized && isset($_SESSION['2fa_secret'])
            ? $this->totp->getQrCodeUrl($user['username'], $_SESSION['2fa_secret'])
            : '';

        View::render('home/two_factor_setup.php', [
            'qrUrl' => $qrUrl,
            'secret' => $setupAuthorized ? ($_SESSION['2fa_secret'] ?? '') : '',
            'recoveryCodes' => $setupAuthorized ? ($_SESSION['2fa_recovery'] ?? []) : [],
            'setupAuthorized' => $setupAuthorized,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary((int)$userId, \App\Model\Package::getUserPackage((int)$userId) ?: []),
            'storageQuota' => $this->storageQuotaInfo((int)$userId),
        ]);
    }

    public function setup()
    {
        if (!FeatureService::twoFactorEnabled() || !Auth::check()) {
            http_response_code(404);
            exit('Not found');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF mismatch');
        }

        $userId = Auth::id();
        $intent = (string)($_POST['intent'] ?? 'enable');

        if ($intent === 'authorize') {
            $stepUp = $this->verifySensitivePasswordStepUp((int)$userId, 'stepup_two_factor_setup', $_POST['current_password'] ?? '');
            if (!$stepUp['allowed']) {
                $_SESSION['2fa_error'] = 'Current password confirmation is temporarily locked. Please wait 10 minutes and try again.';
            } elseif (!$stepUp['verified']) {
                $_SESSION['2fa_error'] = 'Current password required before you can set up two-factor authentication.';
            } else {
                $this->markFreshSetupAuthorization();
                unset($_SESSION['2fa_secret'], $_SESSION['2fa_recovery']);
            }

            header('Location: /2fa/setup');
            exit;
        }

        if (!$this->hasFreshSetupAuthorization()) {
            $_SESSION['2fa_error'] = 'Confirm your current password before setting up two-factor authentication.';
            header('Location: /2fa/setup');
            exit;
        }

        $code = trim($_POST['code'] ?? '');
        $secret = $_SESSION['2fa_secret'] ?? '';

        if ($secret === '') {
            $_SESSION['2fa_error'] = 'Two-factor setup session expired. Please start setup again.';
            $this->clearSetupAuthorization();
            header('Location: /2fa/setup');
            exit;
        }

        $verification = $this->guardTwoFactorAttempt(
            'two_factor_setup',
            'rate_limit_2fa_setup',
            5,
            (int)$userId,
            fn() => $this->totp->verifyCode($secret, $code)
        );

        if (!$verification['allowed']) {
            $_SESSION['2fa_error'] = 'Too many setup verification attempts. Please wait 10 minutes before trying again.';
            header('Location: /2fa/setup');
            exit;
        }

        if ($verification['verified']) {
            $db = Database::getInstance()->getConnection();
            $encSecret = EncryptionService::encrypt($secret);
            $encCodes = EncryptionService::encrypt(json_encode($_SESSION['2fa_recovery']));
            $stmt = $db->prepare("REPLACE INTO user_two_factor (user_id, secret_key, is_enabled, recovery_codes) VALUES (?, ?, 1, ?)");
            $stmt->execute([$userId, $encSecret, $encCodes]);
            $user = User::find($userId);
            if ($user && !empty($user['email'])) {
                \App\Service\MailService::sendTemplate($user['email'], 'two_factor_enabled', [
                    '{username}' => $user['username'] ?? 'User',
                ], 'high');
            }
            unset($_SESSION['2fa_secret'], $_SESSION['2fa_recovery']);
            $this->clearSetupAuthorization();
            $_SESSION['2fa_verified'] = true;
            header('Location: /settings?success=2fa_enabled');
            exit;
        }

        $_SESSION['2fa_error'] = 'Invalid verification code. Please try again.';
        header('Location: /2fa/setup');
        exit;
    }

    public function showVerify()
    {
        if (!FeatureService::twoFactorEnabled()) {
            http_response_code(404);
            exit('Not found');
        }

        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        if (isset($_SESSION['2fa_verified']) && $_SESSION['2fa_verified'] === true) {
            header('Location: /');
            exit;
        }

        View::render('home/two_factor_verify.php', [
            'error' => $_SESSION['2fa_error'] ?? null,
        ]);
        unset($_SESSION['2fa_error']);
    }

    public function verify()
    {
        if (!FeatureService::twoFactorEnabled() || !Auth::check()) {
            http_response_code(404);
            exit('Not found');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF mismatch');
        }

        $userId = Auth::id();
        $code = trim($_POST['code'] ?? '');
        $trust = isset($_POST['trust_device']);

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT secret_key FROM user_two_factor WHERE user_id = ? AND is_enabled = 1");
        $stmt->execute([$userId]);
        $fa = $stmt->fetch();

        if (!$fa) {
            header('Location: /');
            exit;
        }

        $secret = EncryptionService::decrypt($fa['secret_key']);
        $verification = $this->guardTwoFactorAttempt(
            'two_factor_verify',
            'rate_limit_2fa_verify',
            5,
            (int)$userId,
            fn() => $this->totp->verifyCode($secret, $code)
        );

        if (!$verification['allowed']) {
            $_SESSION['2fa_error'] = 'Too many verification attempts. Please wait 10 minutes before trying again.';
            header('Location: /2fa/verify');
            exit;
        }

        if ($verification['verified']) {
            $_SESSION['2fa_verified'] = true;
            if ($trust) {
                $this->trustDevice($userId);
            }
            $user = User::find((int)$userId);
            if ($user) {
                \App\Service\LoginDeviceService::handleSuccessfulLogin($user, SecurityService::getClientIp());
            }
            header('Location: /');
            exit;
        }

        $_SESSION['2fa_error'] = 'Invalid 6-digit code.';
        header('Location: /2fa/verify');
        exit;
    }

    public function useRecoveryCode()
    {
        if (!FeatureService::twoFactorEnabled() || !Auth::check()) {
            http_response_code(404);
            exit('Not found');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF mismatch');
        }

        $userId = Auth::id();
        $inputCode = strtoupper(trim($_POST['recovery_code'] ?? ''));
        $recovery = null;
        try {
            $recovery = $this->guardTwoFactorAttempt(
                'two_factor_recovery',
                'rate_limit_2fa_recovery',
                5,
                (int)$userId,
                fn() => $this->consumeRecoveryCode((int)$userId, $inputCode)
            );
        } catch (\Throwable $e) {
            error_log('Two-factor recovery code verification failed: ' . $e->getMessage());
            $_SESSION['2fa_error'] = 'Unable to verify that recovery code right now. Please try again.';
            header('Location: /2fa/verify');
            exit;
        }

        if (!$recovery['allowed']) {
            $_SESSION['2fa_error'] = 'Too many recovery code attempts. Please wait 10 minutes before trying again.';
            header('Location: /2fa/verify');
            exit;
        }

        if ($recovery['verified']) {
            $_SESSION['2fa_verified'] = true;
            $user = User::find((int)$userId);
            if ($user) {
                \App\Service\LoginDeviceService::handleSuccessfulLogin($user, SecurityService::getClientIp());
            }
            header('Location: /');
            exit;
        }

        $_SESSION['2fa_error'] = 'Invalid recovery code.';
        header('Location: /2fa/verify');
        exit;
    }

    private function trustDevice(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + (30 * 86400));
        $db = Database::getInstance()->getConnection();
        $db->prepare("INSERT INTO user_two_factor_devices (user_id, trust_token, expires_at) VALUES (?, ?, ?)")->execute([$userId, $this->hashTrustedDeviceToken($token), $expiry]);
        setcookie('2fa_trust_' . $userId, $token, [
            'expires' => time() + (30 * 86400),
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
