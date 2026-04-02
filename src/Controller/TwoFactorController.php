<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Model\User;
use App\Service\EncryptionService;
use App\Service\FeatureService;
use App\Service\TwoFactor\TotpService;

class TwoFactorController
{
    private TotpService $totp;

    public function __construct()
    {
        $this->totp = new TotpService();
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

        if (!isset($_SESSION['2fa_secret'])) {
            $_SESSION['2fa_secret'] = $this->totp->createSecret();
            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $codes[] = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            }
            $_SESSION['2fa_recovery'] = $codes;
        }

        $user = Auth::user();
        $qrUrl = $this->totp->getQrCodeUrl($user['username'], $_SESSION['2fa_secret']);

        View::render('home/two_factor_setup.php', [
            'qrUrl' => $qrUrl,
            'secret' => $_SESSION['2fa_secret'],
            'recoveryCodes' => $_SESSION['2fa_recovery'],
        ]);
    }

    public function setup()
    {
        if (!FeatureService::twoFactorEnabled() || !Auth::check()) {
            http_response_code(404);
            exit('Not found');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $code = trim($_POST['code'] ?? '');
        $secret = $_SESSION['2fa_secret'] ?? '';

        if ($this->totp->verifyCode($secret, $code)) {
            $db = Database::getInstance()->getConnection();
            $userId = Auth::id();
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
            die('CSRF mismatch');
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
        if ($this->totp->verifyCode($secret, $code)) {
            $_SESSION['2fa_verified'] = true;
            if ($trust) {
                $this->trustDevice($userId);
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
            die('CSRF mismatch');
        }

        $userId = Auth::id();
        $inputCode = strtoupper(trim($_POST['recovery_code'] ?? ''));
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT recovery_codes FROM user_two_factor WHERE user_id = ?");
        $stmt->execute([$userId]);
        $fa = $stmt->fetch();

        if ($fa) {
            $codes = json_decode(EncryptionService::decrypt($fa['recovery_codes']), true);
            $key = array_search($inputCode, $codes, true);
            if ($key !== false) {
                unset($codes[$key]);
                $newCodes = EncryptionService::encrypt(json_encode(array_values($codes)));
                $db->prepare("UPDATE user_two_factor SET recovery_codes = ? WHERE user_id = ?")->execute([$newCodes, $userId]);
                $_SESSION['2fa_verified'] = true;
                header('Location: /');
                exit;
            }
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
        $db->prepare("INSERT INTO user_two_factor_devices (user_id, trust_token, expires_at) VALUES (?, ?, ?)")->execute([$userId, $token, $expiry]);
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie('2fa_trust_' . $userId, $token, [
            'expires' => time() + (30 * 86400),
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
