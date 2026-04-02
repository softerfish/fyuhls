<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Core\Config;
use App\Model\ApiToken;
use App\Model\Setting;
use App\Service\FeatureService;
use App\Service\LoginDeviceService;

class AuthController {
    private const MAX_PAYMENT_DETAILS_LENGTH = 500;
    private const MAX_API_TOKEN_NAME_LENGTH = 100;

    private function normalizeUserTimezone(?string $timezone): string
    {
        $timezone = trim((string)$timezone);
        if ($timezone === '') {
            return 'UTC';
        }

        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private function normalizeDefaultPrivacy(?string $privacy): string
    {
        $privacy = trim((string)$privacy);
        return in_array($privacy, ['public', 'private'], true) ? $privacy : 'public';
    }

    private function normalizePaymentMethod(?string $method): ?string
    {
        $method = trim((string)$method);
        if ($method === '') {
            return null;
        }

        $supportedMethods = array_filter(array_map('trim', explode(',', Setting::get('supported_withdrawal_methods', 'paypal,bitcoin', 'rewards'))));
        return in_array($method, $supportedMethods, true) ? $method : null;
    }

    private function normalizeMonetizationModel(?string $model): string
    {
        $enabledModels = FeatureService::rewardsEnabled()
            ? array_filter(array_map('trim', explode(',', Setting::get('enabled_models', 'ppd,pps,mixed', 'rewards'))))
            : [];

        $model = trim((string)$model);
        if (in_array($model, $enabledModels, true)) {
            return $model;
        }

        return in_array('ppd', $enabledModels, true) ? 'ppd' : ($enabledModels[0] ?? 'ppd');
    }

    private function normalizePaymentDetails(?string $details): string
    {
        $details = trim((string)$details);
        return mb_substr($details, 0, self::MAX_PAYMENT_DETAILS_LENGTH);
    }

    private function normalizeApiTokenName(?string $name): string
    {
        $name = trim((string)$name);
        if ($name === '') {
            return 'Desktop API Token';
        }

        return mb_substr($name, 0, self::MAX_API_TOKEN_NAME_LENGTH);
    }

    private function parseSignedReferralCookie(): ?int
    {
        if (!FeatureService::affiliateEnabled()) {
            return null;
        }

        $raw = trim((string)($_COOKIE['ref'] ?? ''));
        if ($raw === '' || !str_contains($raw, '.')) {
            return null;
        }

        [$referrerId, $signature] = array_pad(explode('.', $raw, 2), 2, '');
        if (!ctype_digit($referrerId) || (int)$referrerId <= 0 || $signature === '') {
            return null;
        }

        $secret = (string)Config::get('app_key', '');
        if ($secret === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $referrerId, $secret);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return (int)$referrerId;
    }

    public function login() {
        if (Auth::check()) {
            if (Auth::isAdmin()) {
                header('Location: /admin');
            } else {
                header('Location: /');
            }
            exit;
        }

        $captchaUserLogin  = Setting::get('captcha_user_login', '0') === '1';
        $captchaAdminLogin = Setting::get('captcha_admin_login', '0') === '1';
        $captchaSiteKey    = Setting::get('captcha_site_key', '');
        $needCaptcha       = ($captchaUserLogin || $captchaAdminLogin) && $captchaSiteKey;

        $error = '';
        $success = ($_GET['registered'] ?? false) ? 'Account created! You can now login.' : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } else {
                // verify captcha if enabled
                if ($needCaptcha && !self::verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                    $error = 'Please complete the captcha.';
                } else {
                    $username = $_POST['username'] ?? '';
                    $password = $_POST['password'] ?? '';

                    $rlLimit = (int)Setting::get('rate_limit_login', 5);
                    $rlWindow = 300; // 5 minutes
                    $ip = \App\Service\SecurityService::getClientIp();
                    $rateKey = md5($ip . '|' . $username);

                    if (!\App\Service\RateLimiterService::check('login', $rateKey, $rlLimit, $rlWindow)) {
                        $mins = ceil($rlWindow / 60);
                        $error = "Too many login attempts. Please wait $mins minutes.";
                        Logger::warning('login rate limit hit', ['ip' => $ip, 'username' => $username]);
                    } else {
                        $user = \App\Model\User::findByCredentials($username);

                        if ($user && password_verify($password, $user['password'])) {
                            // Check for email verification if enabled
                            $requireVerification = Setting::get('require_email_verification', '0') === '1';
                            if ($requireVerification && $user['role'] !== 'admin' && (int)$user['email_verified'] === 0) {
                                $error = "Please verify your email address before logging in.";
                                Logger::warning('login blocked: email not verified', ['user_id' => $user['id'], 'ip' => $ip]);
                            } else {
                                Auth::login($user['id'], $user['role']);
                                LoginDeviceService::handleSuccessfulLogin($user, $ip);
                                Auth::logActivity('login', "User logged in via " . ($username === $user['email'] ? 'email' : 'username'));
                                Logger::info('login success', ['user_id' => $user['id'], 'role' => $user['role'], 'ip' => $ip]);
                                if ($user['role'] === 'admin') {
                                    header('Location: /admin');
                                } else {
                                    header('Location: /');
                                }
                                exit;
                            }
                        } else {
                            $error = "Invalid credentials.";
                            Logger::warning('login failed', ['ip' => $ip, 'username' => $username]);
                        }
                    }
                } // end captcha-else
            }
        }

        View::render('home/login.php', [
            'error'             => $error,
            'success'           => $success,
            'captchaUserLogin'  => $captchaUserLogin,
            'captchaAdminLogin' => $captchaAdminLogin,
            'captchaSiteKey'    => $captchaSiteKey,
        ]);
    }

    public function register() {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        $db = Database::getInstance()->getConnection();

        // check if registrations are open
        if (Setting::get('allow_registrations', '1') !== '1') {
            View::render('home/register.php', ['error' => 'Registrations are currently closed.']);
            return;
        }

        $captchaRegister = Setting::get('captcha_register', '0') === '1';
        $captchaSiteKey  = Setting::get('captcha_site_key', '');
        $needCaptcha     = $captchaRegister && $captchaSiteKey;

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } elseif ($needCaptcha && !self::verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                $error = 'Please complete the captcha.';
            } else {
                // rate limit registrations - per IP
                $ip = \App\Service\SecurityService::getClientIp();
                $rlLimit = (int)Setting::get('rate_limit_registration', 5);
                $rlWindow = 600; // 10 minutes

                if (!\App\Service\RateLimiterService::check('registration', $ip, $rlLimit, $rlWindow)) {
                    $error = 'Too many registration attempts. Please wait 10 minutes.';
                    Logger::warning('registration rate limit hit', ['ip' => $ip]);
                } else {
                    $username = $_POST['username'] ?? '';
                    $email = $_POST['email'] ?? '';
                    $password = $_POST['password'] ?? '';
                    $passwordConfirm = $_POST['password_confirm'] ?? '';

                $reservedUsernamesRaw = Setting::get('reserved_usernames', 'administrator,admin,support');
                $reservedUsernames = array_map('trim', explode(',', strtolower($reservedUsernamesRaw)));

                if (strlen($username) < 3) {
                    $error = "Username must be at least 3 characters.";
                } elseif (strlen($username) > 30) {
                    $error = "Username must be 30 characters or less.";
                } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                    $error = "Username can only contain letters, numbers, underscores, dots, and hyphens.";
                } elseif (in_array(strtolower($username), $reservedUsernames)) {
                    $error = "This username is reserved and cannot be registered.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email address.";
                } elseif (strlen($password) < 10) {
                    $error = "Password must be at least 10 characters.";
                } elseif ($password !== $passwordConfirm) {
                    $error = "Passwords do not match.";
                    } else {
                        // Check if exists
                        if (\App\Model\User::findByCredentials($username) || \App\Model\User::findByCredentials($email)) {
                            $error = "Username or email already taken.";
                        } else {
                        // validate referral cookie strictly - must be a positive integer
                        $referrerId = $this->parseSignedReferralCookie();
                        
                        $userId = \App\Model\User::create([
                            'username' => $username,
                            'email' => $email,
                            'password' => password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]),
                            'role' => 'user',
                            'referrer_id' => $referrerId
                        ]);

                            if ($userId) {
                                setcookie('ref', '', [
                                    'expires' => time() - 3600,
                                    'path' => '/',
                                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                                    'httponly' => true,
                                    'samesite' => 'Lax',
                                ]);
                                
                                $requireVerification = Setting::get('require_email_verification', '0') === '1';
                                if ($requireVerification) {
                                    // Generate token
                                    $token = bin2hex(random_bytes(32));
                                    $stmt = $db->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                                    $stmt->execute([$token, $userId]);
                                    
                                    // Send Confirm Email
                                    $confirmLink = \App\Service\SeoService::trustedBaseUrl() . "/verify-email/$token";
                                    \App\Service\MailService::sendTemplate($email, 'confirm_email', [
                                        '{username}' => $username,
                                        '{confirm_link}' => $confirmLink
                                    ], 'high');
                                    
                                    Logger::info('user registered: verification required', ['user_id' => $userId]);
                                    header('Location: /login?registered=pending');
                                } else {
                                    // Send Welcome Email
                                    \App\Service\MailService::sendTemplate($email, 'welcome_email', [
                                        '{username}' => $username,
                                        '{site_name}' => Setting::get('app.name', 'Fyuhls')
                                    ]);

                                    Auth::login($userId, 'user');
                                    Auth::logActivity('register', "New user registered");
                                    header('Location: /');
                                }
                                exit;
                            } else {
                            $error = "Failed to create account. Please try again.";
                        }
                    }
                }
                } // end rate limit check
            }
        }

        View::render('home/register.php', [
            'error'           => $error,
            'captchaRegister' => $captchaRegister,
            'captchaSiteKey'  => $captchaSiteKey,
        ]);
    }

    /**
     * Verify a Cloudflare Turnstile token server-side.
     * Returns true if valid, false otherwise (or if key not set).
     */
    private static function verifyTurnstile(string $token): bool {
        $secret = Setting::getEncrypted('captcha_secret_key', Config::get('turnstile.secret_key', ''));
        if (!$secret || !$token) return false;

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'response' => $token]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body   = curl_exec($ch);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err || !$body) return false;
        $data = json_decode($body, true);
        return ($data['success'] ?? false) === true;
    }

    public function updateMonetization() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        if (!FeatureService::rewardsEnabled()) { header('Location: /'); exit; }
        if (!\App\Core\Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

        $model = $_POST['model'] ?? 'ppd';
        $enabledModels = explode(',', \App\Model\Setting::get('enabled_models', 'ppd,pps,mixed', 'rewards'));
        $valid = array_values(array_intersect(['ppd', 'pps', 'mixed'], $enabledModels));
        if (in_array($model, $valid)) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE users SET monetization_model = ? WHERE id = ?");
            $stmt->execute([$model, Auth::id()]);
            Auth::logActivity('monetization_update', "User switched to $model model");
        }

        header('Location: ' . (FeatureService::affiliateEnabled() ? '/affiliate' : '/settings'));
        exit;
    }

    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('CSRF mismatch');
        }

        Auth::logout();
        header('Location: /login');
        exit;
    }

    public function settings() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        
        $userId = Auth::id();
        $db = Database::getInstance()->getConnection();
        $error = '';
        $success = '';
        $newApiToken = null;

        if (isset($_GET['updated'])) {
            if ($_GET['updated'] == '1') $success = "Preferences updated successfully.";
            if ($_GET['updated'] == '2') $success = "Password changed successfully.";
        }
        if (($_GET['success'] ?? '') === '2fa_enabled') {
            $success = "Two-factor authentication enabled successfully.";
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "CSRF Token Mismatch";
            } else {
                $action = $_POST['action'] ?? 'general';

                if ($action === 'profile') {
                    $updateData = [
                        'timezone' => $this->normalizeUserTimezone($_POST['timezone'] ?? 'UTC'),
                        'default_privacy' => $this->normalizeDefaultPrivacy($_POST['default_privacy'] ?? 'public'),
                    ];

                    if (FeatureService::rewardsEnabled()) {
                        $updateData['payment_method'] = $this->normalizePaymentMethod($_POST['payment_method'] ?? null);
                        $updateData['payment_details'] = \App\Service\EncryptionService::encrypt($this->normalizePaymentDetails($_POST['payment_details'] ?? ''));
                        $updateData['monetization_model'] = $this->normalizeMonetizationModel($_POST['monetization_model'] ?? 'ppd');
                    }

                    $fields = [];
                    $values = [];
                    foreach ($updateData as $k => $v) {
                        $fields[] = "$k = ?";
                        $values[] = $v;
                    }
                    $values[] = $userId;

                    $stmt = $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
                    $stmt->execute($values);
                    
                    header('Location: /settings?updated=1');
                    exit;
                } elseif ($action === 'api_token_create') {
                    $tokenName = $this->normalizeApiTokenName($_POST['token_name'] ?? 'Desktop API Token');
                    $expiryDays = max(0, (int)($_POST['token_expiry_days'] ?? 0));
                    $requestedScopes = array_values(array_intersect(
                        ['files.upload', 'files.read'],
                        array_map('strval', $_POST['token_scopes'] ?? [])
                    ));

                    if ($tokenName === '') {
                        $error = "Token name is required.";
                    } elseif (empty($requestedScopes)) {
                        $error = "Select at least one API token scope.";
                    } else {
                        $expiresAt = $expiryDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$expiryDays} days")) : null;
                        $created = ApiToken::create([
                            'user_id' => $userId,
                            'name' => $tokenName,
                            'scopes' => $requestedScopes,
                            'expires_at' => $expiresAt,
                        ]);
                        $newApiToken = $created['token'];
                        $success = "API token created. Copy it now. You will not be able to see it again.";
                        Auth::logActivity('api_token_create', "Created API token {$created['public_id']}");
                    }
                } elseif ($action === 'api_token_revoke') {
                    $tokenId = (int)($_POST['token_id'] ?? 0);
                    if ($tokenId <= 0) {
                        $error = "Invalid API token.";
                    } else {
                        ApiToken::revoke($tokenId, $userId);
                        $success = "API token revoked.";
                        Auth::logActivity('api_token_revoke', "Revoked API token ID {$tokenId}");
                    }
                } elseif ($action === 'password') {
                    $current = $_POST['current_password'] ?? '';
                    $new = $_POST['new_password'] ?? '';
                    $confirm = $_POST['confirm_password'] ?? '';

                    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();

                    if (!password_verify($current, $user['password'])) {
                        $error = "Current password incorrect.";
                    } elseif (strlen($new) < 10) {
                        $error = "New password must be at least 10 characters.";
                    } elseif ($new !== $confirm) {
                        $error = "Passwords do not match.";
                    } else {
                        $hash = password_hash($new, PASSWORD_DEFAULT, ['cost' => 12]);
                        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hash, $userId]);
                        Auth::logActivity('password_change', "User updated their password.");
                        header('Location: /settings?updated=2#securitySection');
                        exit;
                    }
                }
            }
        }

        $stmt = $db->prepare("SELECT u.*, p.name as package_name FROM users u JOIN packages p ON u.package_id = p.id WHERE u.id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user) {
            $user = \App\Model\User::decryptRow($user);
        }

        $enabledModels = FeatureService::rewardsEnabled()
            ? explode(',', \App\Model\Setting::get('enabled_models', 'ppd,pps,mixed', 'rewards'))
            : [];
        $apiTokens = ApiToken::getByUser((int)$userId);

        View::render('home/settings.php', [
            'user' => $user, 
            'error' => $error, 
            'success' => $success,
            'enabledModels' => $enabledModels,
            'apiTokens' => $apiTokens,
            'newApiToken' => $newApiToken,
        ]);
    }

    public function verifyEmail($token) {
        if (empty($token)) { header('Location: /login'); exit; }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE verification_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $stmt = $db->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            $username = \App\Service\EncryptionService::decrypt($user['username']);
            $email = \App\Service\EncryptionService::decrypt($user['email']);

            // Send Welcome Email now that they are verified
            \App\Service\MailService::sendTemplate($email, 'welcome_email', [
                '{username}' => $username,
                '{site_name}' => Setting::get('app.name', 'Fyuhls')
            ]);

            Logger::info('email verified', ['user_id' => $user['id']]);
            header('Location: /login?verified=1');
        } else {
            header('Location: /login?error=invalid_token');
        }
        exit;
    }

    public function forgotPassword() {
        if (Auth::check()) { header('Location: /'); exit; }
        
        $error = '';
        $success = '';
        $db = Database::getInstance()->getConnection();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired.";
            } else {
                $email = $_POST['email'] ?? '';
                $user = \App\Model\User::findByCredentials($email);

                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour
                    
                    $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                    $stmt->execute([$token, $expiry, $user['id']]);
                    
                    $resetLink = \App\Service\SeoService::trustedBaseUrl() . "/reset-password/$token";
                    \App\Service\MailService::sendTemplate($email, 'forgot_password', [
                        '{username}' => $user['username'],
                        '{reset_link}' => $resetLink
                    ], 'high');
                    
                    Logger::info('password reset requested', ['user_id' => $user['id']]);
                }
                
                // Always show success to prevent user enumeration
                $success = "If an account exists with that email, a reset link has been sent.";
            }
        }

        View::render('home/forgot_password.php', ['error' => $error, 'success' => $success]);
    }

    public function resetPassword($token) {
        if (empty($token)) { header('Location: /login'); exit; }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            header('Location: /forgot-password?error=invalid_token');
            exit;
        }

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired.";
            } else {
                $password = $_POST['password'] ?? '';
                $confirm = $_POST['password_confirm'] ?? '';

                if (strlen($password) < 10) {
                    $error = "Password must be at least 10 characters.";
                } elseif ($password !== $confirm) {
                    $error = "Passwords do not match.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
                    $stmt = $db->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
                    $stmt->execute([$hash, $user['id']]);
                    
                    Auth::logActivity('password_reset', "User reset their password via token");
                    header('Location: /login?reset=1');
                    exit;
                }
            }
        }

        View::render('home/reset_password.php', ['error' => $error, 'token' => $token]);
    }
}
