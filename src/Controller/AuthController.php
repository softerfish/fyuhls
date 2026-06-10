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
use App\Service\PackageAllowanceService;
use App\Service\LoginDeviceService;
use App\Service\MonetizationModelService;
use App\Service\PayoutProcessorService;
use App\Service\RememberMeService;
use PDO;

class AuthController {
    private const MAX_PAYMENT_DETAILS_LENGTH = 500;
    private const MAX_API_TOKEN_NAME_LENGTH = 100;
    private const TOKEN_HASH_PREFIX = 'sha256:';
    private const EMAIL_VERIFICATION_TTL_SECONDS = 86400;
    private const STEP_UP_RATE_LIMIT = 5;
    private const STEP_UP_RATE_WINDOW = 600;
    private static $beforeEmailVerificationConsumeHandler = null;
    private static $welcomeEmailSenderForTests = null;
    private static $emailVerificationBonusTouchHandler = null;

    private function storageQuotaInfo(int $userId): array
    {
        $stmt = Database::getInstance()->getConnection()->prepare('SELECT storage_used FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $used = (int)($stmt->fetchColumn() ?: 0);

        $package = \App\Model\Package::getUserPackage($userId);
        $limit = (int)($package['max_storage_bytes'] ?? 0);

        return ['used' => $used, 'limit' => $limit];
    }

    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    private function isHttpsRequest(): bool
    {
        return \App\Service\SecurityService::isHttpsRequest();
    }

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

    private function postLoginRedirectForRole(string $role): string
    {
        if ($role === 'admin') {
            if (Setting::get('db_drift_detected', '0') === '1') {
                return '/admin/configuration?tab=security&sec_tab=health';
            }

            return '/admin';
        }

        return '/';
    }

    private function normalizeEmailAddress(?string $email): string
    {
        $email = mb_strtolower(trim((string)$email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function storeOneTimeToken(string $token): string
    {
        return self::TOKEN_HASH_PREFIX . hash('sha256', $token);
    }

    private static function lookupUserByOneTimeToken(\PDO $db, string $tokenColumn, string $token, string $select = '*', string $extraWhere = '', array $extraParams = []): array|false
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        \App\Model\User::ensureRuntimeColumns($db);

        $hashedToken = self::storeOneTimeToken($token);
        $whereSuffix = $extraWhere !== '' ? ' AND ' . $extraWhere : '';

        $stmt = $db->prepare("SELECT {$select} FROM users WHERE ({$tokenColumn} = ? OR {$tokenColumn} = ?){$whereSuffix} LIMIT 1");
        $stmt->execute(array_merge([$hashedToken, $token], $extraParams));
        return $stmt->fetch();
    }

    public static function setBeforeEmailVerificationConsumeHandlerForTests(?callable $handler): void
    {
        self::$beforeEmailVerificationConsumeHandler = $handler;
    }

    public static function setWelcomeEmailSenderForTests(?callable $handler): void
    {
        self::$welcomeEmailSenderForTests = $handler;
    }

    public static function setEmailVerificationBonusTouchHandlerForTests(?callable $handler): void
    {
        self::$emailVerificationBonusTouchHandler = $handler;
    }

    private static function fireBeforeEmailVerificationConsumeForTests(array $context = []): void
    {
        if (!is_callable(self::$beforeEmailVerificationConsumeHandler)) {
            return;
        }

        (self::$beforeEmailVerificationConsumeHandler)($context);
    }

    private static function appendForUpdateClause(PDO $db, string $sql): string
    {
        return Database::appendForUpdateClause($db, $sql);
    }

    private function sendWelcomeEmailAfterVerification(string $email, string $username): void
    {
        if (is_callable(self::$welcomeEmailSenderForTests)) {
            (self::$welcomeEmailSenderForTests)([
                'email' => $email,
                'username' => $username,
            ]);
            return;
        }

        \App\Service\MailService::sendTemplate($email, 'welcome_email', [
            '{username}' => $username,
            '{site_name}' => Setting::get('app.name', 'Fyuhls')
        ]);
    }

    private function touchEmailVerificationBonuses(int $userId): void
    {
        if (is_callable(self::$emailVerificationBonusTouchHandler)) {
            (self::$emailVerificationBonusTouchHandler)([
                'workflow' => 'verify_email',
                'user_id' => $userId,
            ]);
            return;
        }

        \App\Service\BonusOfferService::touchUserFailSoft($userId, true, [
            'workflow' => 'verify_email',
            'user_id' => $userId,
        ]);
    }

    private function normalizePaymentMethod(?string $method): ?string
    {
        $method = trim((string)$method);
        if ($method === '') {
            return null;
        }

        $supportedMethods = PayoutProcessorService::activeKeys();
        return in_array($method, $supportedMethods, true) ? $method : null;
    }

    private function normalizeMonetizationModel(?string $model, ?array $package = null, ?string $currentModel = null): string
    {
        return MonetizationModelService::normalizeRequestedModel($model, $package, $currentModel);
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

    private function allowedApiTokenScopes(): array
    {
        $scopes = ['files.upload', 'files.read', 'files.write', 'remote.upload'];
        if (FeatureService::rewardsEnabled()) {
            $scopes[] = 'stats.read';
        }

        return $scopes;
    }

    private function verifyCurrentPassword(int $userId, ?string $password): bool
    {
        $password = (string)$password;
        if ($userId <= 0 || $password === '') {
            return false;
        }

        $stmt = Database::getInstance()->getConnection()->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = (string)($stmt->fetchColumn() ?: '');
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    private function checkSensitivePasswordRateLimit(int $userId, string $actionKey): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $ip = \App\Service\SecurityService::getClientIp();
        $ipLimit = max(self::STEP_UP_RATE_LIMIT * 2, 10);

        if (!\App\Service\RateLimiterService::canAttempt($actionKey . '_ip', $ip, $ipLimit, self::STEP_UP_RATE_WINDOW)) {
            return false;
        }

        return \App\Service\RateLimiterService::canAttempt($actionKey . '_user', (string)$userId, self::STEP_UP_RATE_LIMIT, self::STEP_UP_RATE_WINDOW);
    }

    private function verifySensitivePasswordStepUp(int $userId, string $actionKey, ?string $password): array
    {
        if ($userId <= 0) {
            return ['allowed' => false, 'verified' => false];
        }

        $ip = \App\Service\SecurityService::getClientIp();
        $ipLimit = max(self::STEP_UP_RATE_LIMIT * 2, 10);
        $result = \App\Service\RateLimiterService::guardAttempt([
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

    private function revokeTrustedTwoFactorDevicesWithConnection(PDO $db, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $db->prepare("DELETE FROM user_two_factor_devices WHERE user_id = ?");
        $stmt->execute([$userId]);
    }

    private function userHasActiveTwoFactor(int $userId): bool
    {
        if ($userId <= 0 || !FeatureService::twoFactorEnabled()) {
            return false;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT is_enabled FROM user_two_factor WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function parseSignedReferralCookie(): ?array
    {
        if (!FeatureService::affiliateEnabled()) {
            return null;
        }

        $raw = trim((string)($_COOKIE['ref'] ?? ''));
        if ($raw === '' || !str_contains($raw, '.')) {
            return null;
        }

        [$payload, $signature] = array_pad(explode('.', $raw, 2), 2, '');
        if ($payload === '' || $signature === '') {
            return null;
        }

        $secret = \App\Service\SecurityService::getSecureAppKey();
        if ($secret === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $referrerId = $payload;
        $source = 'referral';
        if (str_contains($payload, '|')) {
            [$referrerId, $source] = array_pad(explode('|', $payload, 2), 2, 'referral');
        }

        if (!ctype_digit($referrerId) || (int)$referrerId <= 0) {
            return null;
        }

        $source = in_array($source, ['referral', 'pps'], true) ? $source : 'referral';

        return [
            'id' => (int)$referrerId,
            'source' => $source,
        ];
    }

    public function login() {
        if (Auth::check()) {
            header('Location: ' . $this->postLoginRedirectForRole(Auth::role() ?? 'user'));
            exit;
        }

        $captchaUserLogin  = Setting::get('captcha_user_login', '0') === '1'
            || Setting::get('captcha_admin_login', '0') === '1';
        $captchaAdminLogin = false;
        $captchaSiteKey    = Setting::get('captcha_site_key', '');
        $needCaptcha       = $captchaUserLogin;
        $allowRegistrations = Setting::get('allow_registrations', '1') === '1';
        $requireVerification = Setting::get('require_email_verification', '0') === '1';

        $error = '';
        $registeredState = trim((string)($_GET['registered'] ?? ''));
        $success = '';
        if ($registeredState === 'pending') {
            $success = 'Account created. Check your email to confirm the address before logging in.';
        } elseif ($registeredState !== '') {
            $success = 'Account created! You can now login.';
        } elseif (($_GET['verified'] ?? '') === '1') {
            $success = 'Email verified successfully. You can now log in.';
        } elseif (($_GET['email_changed'] ?? '') === '1') {
            $success = 'Email address confirmed successfully. You can now sign in with the new address.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } else {
                // verify captcha if enabled
                if ($needCaptcha && $captchaSiteKey === '') {
                    $error = 'Login is temporarily unavailable because CAPTCHA is enabled but not fully configured.';
                } elseif ($needCaptcha && !self::verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                    $error = 'Please complete the captcha.';
                } else {
                    $username = $_POST['username'] ?? '';
                    $password = $_POST['password'] ?? '';

                    $rlLimit = (int)Setting::get('rate_limit_login', 5);
                    $rlWindow = 300; // 5 minutes
                    $ip = \App\Service\SecurityService::getClientIp();
                    $rateKey = md5($ip . '|' . mb_strtolower(trim($username)));

                    $loginSprayLimit = max($rlLimit * 4, 20);
                    if (!\App\Service\RateLimiterService::check('login_ip', $ip, $loginSprayLimit, $rlWindow)) {
                        $mins = ceil($rlWindow / 60);
                        $error = "Too many login attempts from your network. Please wait $mins minutes.";
                        Logger::warning('login ip-wide rate limit hit', ['ip' => $ip]);
                    } elseif (!\App\Service\RateLimiterService::check('login', $rateKey, $rlLimit, $rlWindow)) {
                        $mins = ceil($rlWindow / 60);
                        $error = "Too many login attempts. Please wait $mins minutes.";
                        Logger::warning('login rate limit hit', ['ip' => $ip, 'username' => $username]);
                    } else {
                        $user = \App\Model\User::findByCredentials($username);

                        if ($user && password_verify($password, $user['password'])) {
                            if (($user['status'] ?? 'active') !== 'active') {
                                $error = "Invalid credentials.";
                                Logger::warning('login blocked: inactive account', ['user_id' => $user['id'], 'ip' => $ip]);
                            // Check for email verification if enabled
                            } elseif ($requireVerification && $user['role'] !== 'admin' && (int)$user['email_verified'] === 0) {
                                $error = "Please verify your email address before logging in.";
                                Logger::warning('login blocked: email not verified', ['user_id' => $user['id'], 'ip' => $ip]);
                            } else {
                                Auth::login($user['id'], $user['role']);
                                if (RememberMeService::enabled() && isset($_POST['remember_me'])) {
                                    RememberMeService::issueForUser((int)$user['id'], (string)$user['role']);
                                } else {
                                    RememberMeService::clearCookie();
                                }
                                if (!$this->userHasActiveTwoFactor((int)$user['id'])) {
                                    LoginDeviceService::handleSuccessfulLogin($user, $ip);
                                }
                                Auth::logActivity('login', "User logged in via " . ($username === $user['email'] ? 'email' : 'username'));
                                Logger::info('login success', ['user_id' => $user['id'], 'role' => $user['role'], 'ip' => $ip]);
                                header('Location: ' . $this->postLoginRedirectForRole((string)$user['role']));
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
            'allowRegistrations' => $allowRegistrations,
            'requireVerification' => $requireVerification,
            'rememberMeEnabled' => RememberMeService::enabled(),
        ]);
    }

    public function register() {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $allowRegistrations = Setting::get('allow_registrations', '1') === '1';
        $requireVerification = Setting::get('require_email_verification', '0') === '1';

        // check if registrations are open
        if (!$allowRegistrations) {
            View::render('home/register.php', [
                'error' => 'Registrations are currently closed.',
                'allowRegistrations' => $allowRegistrations,
                'requireVerification' => $requireVerification,
            ]);
            return;
        }

        $captchaRegister = Setting::get('captcha_register', '0') === '1';
        $captchaSiteKey  = Setting::get('captcha_site_key', '');
        $needCaptcha     = $captchaRegister;

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } elseif ($needCaptcha && $captchaSiteKey === '') {
                $error = 'Registration is temporarily unavailable because CAPTCHA is enabled but not fully configured.';
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
                        if (\App\Model\User::findByCredentials($username) || \App\Model\User::findByEmailOrPendingEmail($email)) {
                            $error = "Username or email already taken.";
                        } else {
                        // validate referral cookie strictly - must be a positive integer
                        $referrer = $this->parseSignedReferralCookie();

                        $credentialLockKeys = [];
                        try {
                            $db->beginTransaction();
                            $credentialLockKeys = \App\Model\User::lockCredentialValues($db, [$username, $email]);
                            \App\Model\User::assertCredentialsAvailable($db, $username, $email);
                            $userId = \App\Model\User::create([
                                'username' => $username,
                                'email' => $email,
                                'password' => password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]),
                                'role' => 'user',
                                'referrer_id' => $referrer['id'] ?? null,
                                'referrer_source' => $referrer['source'] ?? null,
                            ]);

                            if (!$userId) {
                                throw new \RuntimeException('Failed to create account. Please try again.');
                            }

                            $token = null;
                            if ($requireVerification) {
                                $token = bin2hex(random_bytes(32));
                                $verificationExpiry = date('Y-m-d H:i:s', time() + self::EMAIL_VERIFICATION_TTL_SECONDS);
                                $stmt = $db->prepare("UPDATE users SET verification_token = ?, verification_expires = ? WHERE id = ?");
                                $stmt->execute([self::storeOneTimeToken($token), $verificationExpiry, $userId]);
                            } else {
                                $token = null;
                            }

                            $db->commit();

                            setcookie('ref', '', [
                                'expires' => time() - 3600,
                                'path' => '/',
                                'secure' => $this->isHttpsRequest(),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);

                            if ($requireVerification && $token !== null) {
                                $confirmLink = \App\Service\SeoService::trustedBaseUrl() . "/verify-email/$token";
                                \App\Service\MailService::sendTemplate($email, 'confirm_email', [
                                    '{username}' => $username,
                                    '{confirm_link}' => $confirmLink
                                ], 'high');

                                Logger::info('user registered: verification required', ['user_id' => $userId]);
                                header('Location: /login?registered=pending');
                            } else {
                                \App\Service\MailService::sendTemplate($email, 'welcome_email', [
                                    '{username}' => $username,
                                    '{site_name}' => Setting::get('app.name', 'Fyuhls')
                                ]);

                                Auth::login($userId, 'user');
                                Auth::logActivity('register', "New user registered");
                                header('Location: /');
                            }
                            exit;
                        } catch (\Throwable $e) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $error = $e instanceof \RuntimeException ? $e->getMessage() : "Failed to create account. Please try again.";
                        } finally {
                            \App\Model\User::releaseCredentialLocks($db, $credentialLockKeys);
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
            'allowRegistrations' => $allowRegistrations,
            'requireVerification' => $requireVerification,
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
        if (!\App\Core\Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF mismatch");
        }

        $model = $_POST['model'] ?? 'ppd';
        $package = \App\Model\Package::getUserPackage((int)(Auth::id() ?? 0));
        $valid = MonetizationModelService::allowedModelsForPackage($package);
        if (in_array($model, $valid, true)) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE users SET monetization_model = ? WHERE id = ?");
            $stmt->execute([$model, Auth::id()]);
            Auth::logActivity('monetization_update', "User switched to $model model");
        }

        header('Location: ' . (FeatureService::rewardsEnabled() ? '/affiliate' : '/settings'));
        exit;
    }

    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, 'Method Not Allowed');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF mismatch');
        }

        Auth::logout();
        header('Location: /login');
        exit;
    }

    public function settings() {
        if (!Auth::check()) { header('Location: /login'); exit; }

        $userId = Auth::id();
        $db = Database::getInstance()->getConnection();
        \App\Model\User::ensureRuntimeColumns($db);
        $error = '';
        $success = '';
        $currentUser = \App\Model\User::find((int)$userId);
        if (!$currentUser) {
            header('Location: /login');
            exit;
        }
        $newApiToken = null;

        if (isset($_GET['updated'])) {
            if ($_GET['updated'] == '1') $success = "Preferences updated successfully.";
            if ($_GET['updated'] == '2') $success = "Password changed successfully.";
        }
        if (($_GET['email_change'] ?? '') === 'pending') {
            $success = 'We sent a confirmation link to your new email address. The change will finish after you open that link.';
        } elseif (($_GET['email_change'] ?? '') === 'confirmed') {
            $success = 'Your email address has been updated successfully.';
        }
        if (($_GET['success'] ?? '') === '2fa_enabled') {
            $success = "Two-factor authentication enabled successfully.";
        }
        $paymentNotice = trim((string)($_GET['payment'] ?? ''));
        if ($paymentNotice !== '') {
            $paymentMessages = [
                'stripe_success' => ['success', 'Stripe payment completed and your account has been upgraded.'],
                'stripe_pending' => ['error', 'Stripe payment is still pending confirmation. If you were charged, please refresh in a moment or contact support.'],
                'stripe_missing_session' => ['error', 'Stripe return was missing the checkout session.'],
                'paypal_success' => ['success', 'PayPal payment completed and your account has been upgraded.'],
                'paypal_pending' => ['error', 'Your PayPal subscription is active, but Fyuhls is still waiting for the first settled payment before granting premium access. Please refresh in a moment or contact support if this does not clear shortly.'],
                'paypal_missing_order' => ['error', 'PayPal return was missing the order details needed to finalize the upgrade.'],
                'paypal_failed' => ['error', $_SESSION['payment_error'] ?? 'We could not finalize your PayPal checkout. Please contact support if the payment completed on PayPal.'],
                'paypal_cancelled' => ['error', 'The PayPal checkout was cancelled. You can try again whenever you are ready.'],
                'stripe_cancelled' => ['error', 'The Stripe checkout was cancelled. You can try again whenever you are ready.'],
            ];

            if (isset($paymentMessages[$paymentNotice])) {
                [$type, $message] = $paymentMessages[$paymentNotice];
                if ($type === 'success') {
                    $success = $message;
                } else {
                    $error = $message;
                }
            }
            unset($_SESSION['payment_error']);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "CSRF Token Mismatch";
            } else {
                $action = $_POST['action'] ?? 'general';

                if ($action === 'profile') {
                    $requestedEmail = $this->normalizeEmailAddress($_POST['email'] ?? '');
                    if ($requestedEmail === '') {
                        $error = "Please enter a valid email address.";
                    }

                    $updateData = [
                        'timezone' => $this->normalizeUserTimezone($_POST['timezone'] ?? 'UTC'),
                        'default_privacy' => $this->normalizeDefaultPrivacy($_POST['default_privacy'] ?? 'public'),
                    ];

                    if ($error === '') {
                        if (FeatureService::rewardsEnabled()) {
                            $newPaymentMethod = $this->normalizePaymentMethod($_POST['payment_method'] ?? null);
                            $newPaymentDetails = $this->normalizePaymentDetails($_POST['payment_details'] ?? '');
                            $currentPaymentMethod = trim((string)($currentUser['payment_method'] ?? ''));
                            $currentPaymentDetails = '';
                            if (!empty($currentUser['payment_details'])) {
                                $currentPaymentDetails = $this->normalizePaymentDetails(
                                    \App\Service\EncryptionService::decrypt((string)$currentUser['payment_details']) ?: ''
                                );
                            }

                            $payoutSettingsChanged = $newPaymentMethod !== $currentPaymentMethod
                                || $newPaymentDetails !== $currentPaymentDetails;

                            if ($payoutSettingsChanged) {
                                $stepUp = $this->verifySensitivePasswordStepUp((int)$userId, 'stepup_payout_settings', $_POST['payout_current_password'] ?? '');
                                if (!$stepUp['allowed']) {
                                    $error = "Current password confirmation is temporarily locked. Please wait 10 minutes and try again.";
                                } elseif (!$stepUp['verified']) {
                                    $error = "Current password required to change payout details.";
                                }
                            }

                            $updateData['payment_method'] = $newPaymentMethod;
                            $updateData['payment_details'] = \App\Service\EncryptionService::encrypt($newPaymentDetails);
                            $updateData['monetization_model'] = $this->normalizeMonetizationModel(
                                $_POST['monetization_model'] ?? 'ppd',
                                \App\Model\Package::getUserPackage((int)$userId),
                                (string)($currentUser['monetization_model'] ?? 'ppd')
                            );
                        }

                        $currentEmail = $this->normalizeEmailAddress($currentUser['email'] ?? '');
                        $emailChangeRequested = false;
                        $emailChangeMailContext = null;
                        $emailChanged = $requestedEmail !== '' && $requestedEmail !== $currentEmail;

                        if ($error === '' && $emailChanged) {
                            $stepUp = $this->verifySensitivePasswordStepUp((int)$userId, 'stepup_email_change', $_POST['profile_current_password'] ?? '');
                            if (!$stepUp['allowed']) {
                                $error = "Current password confirmation is temporarily locked. Please wait 10 minutes and try again.";
                            } elseif (!$stepUp['verified']) {
                                $error = "Current password required to change your email address.";
                            }
                        }

                        if ($requestedEmail !== '' && $requestedEmail !== $currentEmail) {
                            $emailOwner = \App\Model\User::findByEmailOrPendingEmail($requestedEmail, (int)$userId);

                            if ($emailOwner) {
                                $error = "That email address is already in use or waiting to be confirmed.";
                            } else {
                                $token = bin2hex(random_bytes(32));
                                $expiresAt = date('Y-m-d H:i:s', time() + 86400);
                                $updateData['pending_email'] = \App\Service\EncryptionService::encrypt($requestedEmail);
                                $updateData['pending_email_lookup'] = \App\Model\User::credentialLookupHash($requestedEmail);
                                $updateData['email_change_token'] = self::storeOneTimeToken($token);
                                $updateData['email_change_expires'] = $expiresAt;
                                $emailChangeRequested = true;
                                $emailChangeMailContext = [
                                    'requested_email' => $requestedEmail,
                                    'token' => $token,
                                ];
                            }
                        }
                    }

                    if ($error === '') {
                        $fields = [];
                        $values = [];
                        foreach ($updateData as $k => $v) {
                            $fields[] = "$k = ?";
                            $values[] = $v;
                        }
                        $values[] = $userId;
                        $credentialLockKeys = [];
                        try {
                            $db->beginTransaction();
                            if ($emailChangeRequested && !empty($requestedEmail)) {
                                $credentialLockKeys = \App\Model\User::lockCredentialValues($db, [$requestedEmail]);
                                \App\Model\User::assertCredentialsAvailable($db, null, $requestedEmail, (int)$userId);
                            }

                            $stmt = $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
                            $stmt->execute($values);
                            $db->commit();
                        } catch (\Throwable $e) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $error = $e instanceof \RuntimeException
                                ? $e->getMessage()
                                : "We couldn't save your profile changes right now. Nothing was applied.";
                        } finally {
                            \App\Model\User::releaseCredentialLocks($db, $credentialLockKeys);
                        }
                    }

                    if ($error === '') {
                        if ($emailChangeRequested && is_array($emailChangeMailContext)) {
                            $confirmLink = \App\Service\SeoService::trustedBaseUrl() . "/confirm-email-change/" . (string)$emailChangeMailContext['token'];
                            $mailQueued = \App\Service\MailService::sendTemplate((string)$emailChangeMailContext['requested_email'], 'confirm_email_change', [
                                '{username}' => (string)($currentUser['username'] ?? ''),
                                '{confirm_link}' => $confirmLink,
                                '{new_email}' => (string)$emailChangeMailContext['requested_email'],
                            ], 'high');

                            if (!$mailQueued) {
                                try {
                                    $revert = $db->prepare("
                                        UPDATE users
                                        SET pending_email = NULL,
                                            pending_email_lookup = NULL,
                                            email_change_token = NULL,
                                            email_change_expires = NULL
                                        WHERE id = ?
                                    ");
                                    $revert->execute([$userId]);
                                } catch (\Throwable $revertError) {
                                    \App\Core\Logger::warning('email change confirmation rollback failed', [
                                        'user_id' => $userId,
                                        'error' => $revertError->getMessage(),
                                    ]);
                                }
                                $error = "We could not queue the confirmation email right now. Please try again in a moment.";
                                $emailChangeRequested = false;
                            }
                        }
                    }

                    if ($error === '') {
                        Auth::logActivity(
                            $emailChangeRequested ? 'email_change_requested' : 'settings_update',
                            $emailChangeRequested
                                ? 'User requested an email address change confirmation.'
                                : 'User updated account profile settings.'
                        );

                        header('Location: /settings?updated=1' . ($emailChangeRequested ? '&email_change=pending' : '') . '#profileSection');
                        exit;
                    }
                } elseif ($action === 'api_token_create') {
                    $tokenName = $this->normalizeApiTokenName($_POST['token_name'] ?? 'Desktop API Token');
                    $expiryDays = max(0, (int)($_POST['token_expiry_days'] ?? 0));
                    $requestedScopes = array_values(array_intersect(
                        $this->allowedApiTokenScopes(),
                        array_map('strval', $_POST['token_scopes'] ?? [])
                    ));

                    if ($tokenName === '') {
                        $error = "Token name is required.";
                    } elseif (empty($requestedScopes)) {
                        $error = "Select at least one API token scope.";
                    } else {
                        $stepUp = $this->verifySensitivePasswordStepUp((int)$userId, 'stepup_api_token_create', $_POST['token_current_password'] ?? '');
                        if (!$stepUp['allowed']) {
                            $error = "Current password confirmation is temporarily locked. Please wait 10 minutes and try again.";
                        } elseif (!$stepUp['verified']) {
                            $error = "Current password required to create an API token.";
                        }
                    }

                    if ($error === '') {
                        $expiresAt = $expiryDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$expiryDays} days")) : null;
                        try {
                            $created = ApiToken::create([
                                'user_id' => $userId,
                                'name' => $tokenName,
                                'scopes' => $requestedScopes,
                                'expires_at' => $expiresAt,
                            ]);
                            $newApiToken = $created['token'];
                            $success = "API token created. Copy it now. You will not be able to see it again.";
                            Auth::logActivity('api_token_create', "Created API token {$created['public_id']}");
                        } catch (\RuntimeException $e) {
                            $error = $e->getMessage();
                        }
                    }
                } elseif ($action === 'api_token_revoke') {
                    $tokenId = (int)($_POST['token_id'] ?? 0);
                    if ($tokenId <= 0) {
                        $error = "Invalid API token.";
                    } else {
                        try {
                            ApiToken::revoke($tokenId, $userId);
                            $success = "API token revoked.";
                            Auth::logActivity('api_token_revoke', "Revoked API token ID {$tokenId}");
                        } catch (\RuntimeException $e) {
                            $error = $e->getMessage();
                        }
                    }
                } elseif ($action === 'password') {
                    $current = $_POST['current_password'] ?? '';
                    $new = $_POST['new_password'] ?? '';
                    $confirm = $_POST['confirm_password'] ?? '';

                    $stepUp = $this->verifySensitivePasswordStepUp((int)$userId, 'stepup_password_change', $current);
                    if (!$stepUp['allowed']) {
                        $error = "Current password confirmation is temporarily locked. Please wait 10 minutes and try again.";
                    } elseif (!$stepUp['verified']) {
                        $error = "Current password incorrect.";
                    } elseif (strlen($new) < 10) {
                        $error = "New password must be at least 10 characters.";
                    } elseif ($new !== $confirm) {
                        $error = "Passwords do not match.";
                    } else {
                        $hash = password_hash($new, PASSWORD_DEFAULT, ['cost' => 12]);
                        try {
                            $db->beginTransaction();
                            $stmt = $db->prepare("UPDATE users SET password = ?, session_version = session_version + 1 WHERE id = ?");
                            $stmt->execute([$hash, $userId]);
                            ApiToken::revokeAllForUserWithConnection($db, (int)$userId);
                            RememberMeService::revokeAllForUserWithConnection($db, (int)$userId);
                            $this->revokeTrustedTwoFactorDevicesWithConnection($db, (int)$userId);
                            $db->commit();
                        } catch (\Throwable $e) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $error = $e instanceof \RuntimeException
                                ? $e->getMessage()
                                : "We couldn't update your password safely right now. Nothing was applied.";
                        }
                    }

                    if ($error === '') {
                        $_SESSION['session_version'] = (int)($_SESSION['session_version'] ?? 1) + 1;
                        Auth::logActivity('password_change', "User updated their password.");
                        header('Location: /settings?updated=2#securitySection');
                        exit;
                    }
                }
            }
        }

        $stmt = $db->prepare("SELECT u.*, p.name as package_name, p.level_type as package_level_type FROM users u JOIN packages p ON u.package_id = p.id WHERE u.id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user) {
            $user = \App\Model\User::decryptRow($user);
        }

        $enabledModels = FeatureService::rewardsEnabled()
            ? MonetizationModelService::allowedModelsForPackage(\App\Model\Package::getUserPackage((int)$userId))
            : [];
        $apiTokens = ApiToken::getByUser((int)$userId);

        View::render('home/settings.php', [
            'user' => $user,
            'error' => $error,
            'success' => $success,
            'enabledModels' => $enabledModels,
            'apiTokens' => $apiTokens,
            'newApiToken' => $newApiToken,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary((int)$userId, \App\Model\Package::getUserPackage((int)$userId) ?: []),
            'storageQuota' => $this->storageQuotaInfo((int)$userId),
        ]);
    }

    public function verifyEmail($token) {
        if (empty($token)) { header('Location: /login'); exit; }

        $db = Database::getInstance()->getConnection();
        \App\Model\User::ensureRuntimeColumns($db);
        $hashedToken = self::storeOneTimeToken((string)$token);
        $user = self::lookupUserByOneTimeToken(
            $db,
            'verification_token',
            (string)$token,
            'id, username, email',
            "status = 'active' AND (verification_expires IS NULL OR verification_expires > NOW())"
        );

        if ($user) {
            $committed = false;
            try {
                $db->beginTransaction();
                $lockStmt = $db->prepare(self::appendForUpdateClause($db, "
                    SELECT id, username, email, status, verification_token, verification_expires
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                "));
                $lockStmt->execute([(int)$user['id']]);
                $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                $tokenStillValid = is_array($lockedUser)
                    && (string)($lockedUser['status'] ?? '') === 'active'
                    && in_array((string)($lockedUser['verification_token'] ?? ''), [$hashedToken, (string)$token], true)
                    && (
                        empty($lockedUser['verification_expires'])
                        || strtotime((string)$lockedUser['verification_expires']) > time()
                    );
                if (!$tokenStillValid) {
                    throw new \RuntimeException('Invalid token.');
                }

                self::fireBeforeEmailVerificationConsumeForTests([
                    'user_id' => (int)$user['id'],
                    'token' => (string)$token,
                    'hashed_token' => $hashedToken,
                ]);

                $stmt = $db->prepare("
                    UPDATE users
                    SET email_verified = 1,
                        verification_token = NULL,
                        verification_expires = NULL
                    WHERE id = ?
                      AND (verification_token = ? OR verification_token = ?)
                      AND status = 'active'
                      AND (verification_expires IS NULL OR verification_expires > NOW())
                ");
                $stmt->execute([(int)$user['id'], $hashedToken, (string)$token]);
                if ($stmt->rowCount() !== 1) {
                    throw new \RuntimeException('Invalid token.');
                }

                $db->commit();
                $committed = true;

                $username = \App\Service\EncryptionService::decrypt((string)($lockedUser['username'] ?? $user['username']));
                $email = \App\Service\EncryptionService::decrypt((string)($lockedUser['email'] ?? $user['email']));

                try {
                    $this->sendWelcomeEmailAfterVerification($email, $username);
                } catch (\Throwable $sideEffectError) {
                    Logger::warning('welcome email send failed after email verification commit', [
                        'user_id' => (int)$user['id'],
                        'error' => $sideEffectError->getMessage(),
                    ]);
                }

                Logger::info('email verified', ['user_id' => $user['id']]);
                try {
                    $this->touchEmailVerificationBonuses((int)$user['id']);
                } catch (\Throwable $sideEffectError) {
                    Logger::warning('email verification bonus touch failed after verification commit', [
                        'user_id' => (int)$user['id'],
                        'error' => $sideEffectError->getMessage(),
                    ]);
                }
                header('Location: /login?verified=1');
            } catch (\Throwable $e) {
                if (!$committed && $db->inTransaction()) {
                    $db->rollBack();
                }
                header('Location: /login?error=invalid_token');
            }
        } else {
            header('Location: /login?error=invalid_token');
        }
        exit;
    }

    public function confirmEmailChange($token) {
        if (empty($token)) { header('Location: /login?error=invalid_token'); exit; }

        $db = Database::getInstance()->getConnection();
        \App\Model\User::ensureRuntimeColumns($db);
        $hashedToken = self::storeOneTimeToken((string)$token);
        $user = self::lookupUserByOneTimeToken(
            $db,
            'email_change_token',
            (string)$token,
            '*',
            "status = 'active' AND (email_change_expires IS NULL OR email_change_expires > NOW())"
        );

        if (!$user) {
            header('Location: /login?error=invalid_token');
            exit;
        }

        $user = \App\Model\User::decryptRow($user);
        $pendingEmail = $this->normalizeEmailAddress($user['pending_email'] ?? '');
        if ($pendingEmail === '') {
            header('Location: /login?error=invalid_token');
            exit;
        }

        $credentialLockKeys = [];
        try {
            $db->beginTransaction();
            $credentialLockKeys = \App\Model\User::lockCredentialValues($db, [$pendingEmail]);
            \App\Model\User::assertCredentialsAvailable($db, null, $pendingEmail, (int)$user['id']);

            $lockStmt = $db->prepare(self::appendForUpdateClause($db, "SELECT id FROM users WHERE id = ? LIMIT 1"));
            $lockStmt->execute([(int)$user['id']]);
            if ((int)($lockStmt->fetchColumn() ?: 0) !== (int)$user['id']) {
                throw new \RuntimeException('Invalid token.');
            }

            $lookupHash = \App\Model\User::credentialLookupHash($pendingEmail);
            $stateStmt = $db->prepare(self::appendForUpdateClause($db, "
                SELECT status, pending_email_lookup, email_change_token, email_change_expires
                FROM users
                WHERE id = ?
                LIMIT 1
            "));
            $stateStmt->execute([(int)$user['id']]);
            $lockedState = $stateStmt->fetch(\PDO::FETCH_ASSOC);
            $tokenMatches = is_array($lockedState)
                && in_array((string)($lockedState['email_change_token'] ?? ''), [$hashedToken, (string)$token], true)
                && (string)($lockedState['pending_email_lookup'] ?? '') === $lookupHash
                && (string)($lockedState['status'] ?? '') === 'active'
                && (
                    empty($lockedState['email_change_expires'])
                    || strtotime((string)$lockedState['email_change_expires']) > time()
                );
            if (!$tokenMatches) {
                throw new \RuntimeException('Invalid token.');
            }

            $stmt = $db->prepare("
                UPDATE users
                SET email = ?,
                    email_lookup = ?,
                    email_verified = 1,
                    pending_email = NULL,
                    pending_email_lookup = NULL,
                    email_change_token = NULL,
                    email_change_expires = NULL
                WHERE id = ?
                  AND (email_change_token = ? OR email_change_token = ?)
                  AND pending_email_lookup = ?
                  AND status = 'active'
                  AND (email_change_expires IS NULL OR email_change_expires > NOW())
            ");
            $stmt->execute([
                \App\Service\EncryptionService::encrypt($pendingEmail),
                $lookupHash,
                (int)$user['id'],
                $hashedToken,
                (string)$token,
                $lookupHash,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Invalid token.');
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::warning('email change confirmation blocked: target email already claimed', ['user_id' => (int)$user['id']]);
            header('Location: /login?error=invalid_token');
            exit;
        } finally {
            \App\Model\User::releaseCredentialLocks($db, $credentialLockKeys);
        }

        Auth::logActivity('email_change_confirmed', 'User confirmed a new email address.');
        Logger::info('email change confirmed', ['user_id' => (int)$user['id']]);

        if (Auth::check() && Auth::id() === (int)$user['id']) {
            header('Location: /settings?email_change=confirmed#profileSection');
        } else {
            header('Location: /login?email_changed=1');
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
                $ip = \App\Service\SecurityService::getClientIp();
                if (!\App\Service\RateLimiterService::check('forgot_password', $ip, 5, 900)) {
                    $success = "If an account exists with that email, a reset link has been sent.";
                    View::render('home/forgot_password.php', ['error' => $error, 'success' => $success]);
                    return;
                }
                $user = \App\Model\User::findByCredentials($email);

                if ($user && ($user['status'] ?? 'active') === 'active') {
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                    $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                    $stmt->execute([self::storeOneTimeToken($token), $expiry, $user['id']]);

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
        $hashedToken = self::storeOneTimeToken((string)$token);
        $user = self::lookupUserByOneTimeToken(
            $db,
            'reset_token',
            (string)$token,
            'id',
            "status = 'active' AND reset_expires > NOW()"
        );

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
                    try {
                        $db->beginTransaction();
                        $lockStmt = $db->prepare(self::appendForUpdateClause($db, "
                            SELECT id, status, reset_token, reset_expires
                            FROM users
                            WHERE id = ?
                            LIMIT 1
                        "));
                        $lockStmt->execute([(int)$user['id']]);
                        $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                        $tokenStillValid = is_array($lockedUser)
                            && (string)($lockedUser['status'] ?? '') === 'active'
                            && in_array((string)($lockedUser['reset_token'] ?? ''), [$hashedToken, (string)$token], true)
                            && !empty($lockedUser['reset_expires'])
                            && strtotime((string)$lockedUser['reset_expires']) > time();
                        if (!$tokenStillValid) {
                            throw new \RuntimeException('Invalid token.');
                        }

                        $stmt = $db->prepare("
                            UPDATE users
                            SET password = ?, session_version = session_version + 1, reset_token = NULL, reset_expires = NULL
                            WHERE id = ?
                              AND (reset_token = ? OR reset_token = ?)
                              AND status = 'active'
                              AND reset_expires > NOW()
                        ");
                        $stmt->execute([$hash, $user['id'], $hashedToken, (string)$token]);
                        if ($stmt->rowCount() !== 1) {
                            throw new \RuntimeException('Invalid token.');
                        }
                        ApiToken::revokeAllForUserWithConnection($db, (int)$user['id']);
                        RememberMeService::revokeAllForUserWithConnection($db, (int)$user['id']);
                        $this->revokeTrustedTwoFactorDevicesWithConnection($db, (int)$user['id']);
                        $db->commit();
                    } catch (\Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = $e instanceof \RuntimeException
                            ? $e->getMessage()
                            : "We couldn't reset that password safely right now. Nothing was applied.";
                    }
                }

                if ($error === '') {
                    Auth::logActivity('password_reset', "User reset their password via token");
                    header('Location: /login?reset=1');
                    exit;
                }
            }
        }

        View::render('home/reset_password.php', ['error' => $error, 'token' => $token]);
    }
}
