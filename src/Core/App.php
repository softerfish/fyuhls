<?php

namespace App\Core;

use App\Service\ConfigPointerService;
use App\Service\GarbageCollector;

class App {
    private const INSECURE_APP_KEYS = [
        '',
        'REPLACE_DURING_INSTALL',
        'a8b3c9d2e1f4g7h0i5j6k1l8m9n2o3p4',
    ];

    private Router $router;
    private string $cspNonce = '';
    private static array $runtimeSecurityNotices = [];

    public function __construct() {
        $this->router = new Router();
    }

    private function isHttpsRequest(): bool
    {
        return \App\Service\SecurityService::isHttpsRequest();
    }

    private function resolveSafeRefererRedirect(string $fallback): string
    {
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return $fallback;
        }

        $parts = parse_url($referer);
        if ($parts === false) {
            return $fallback;
        }

        if (empty($parts['host'])) {
            $path = (string)($parts['path'] ?? '');
            if ($path !== '' && str_starts_with($path, '/')) {
                $query = isset($parts['query']) ? '?' . $parts['query'] : '';
                return $path . $query;
            }
            return $fallback;
        }

        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $refererHost = strtolower((string)$parts['host']);
        if ($currentHost !== '' && $refererHost === $currentHost) {
            $path = (string)($parts['path'] ?? '/');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            return ($path !== '' ? $path : '/') . $query;
        }

        return $fallback;
    }

    private function buildContentSecurityPolicy(): string
    {
        $connectSrc = array_merge([
            "'self'",
            'https://challenges.cloudflare.com',
            'https://static.cloudflareinsights.com',
        ], $this->resolveStorageConnectSources());

        $connectSrc = array_values(array_unique(array_filter($connectSrc)));

        $formAction = [
            "'self'",
            'https://www.paypal.com',
            'https://www.sandbox.paypal.com',
            'https://checkout.stripe.com',
        ];

        return "default-src 'self'; "
            . "base-uri 'self'; "
            . 'form-action ' . implode(' ', $formAction) . '; '
            . "frame-ancestors 'self'; "
            . "script-src 'self' 'nonce-{$this->cspNonce}' https://challenges.cloudflare.com https://cdn.jsdelivr.net https://static.cloudflareinsights.com; "
            . "script-src-elem 'self' 'nonce-{$this->cspNonce}' https://challenges.cloudflare.com https://cdn.jsdelivr.net https://static.cloudflareinsights.com; "
            . "style-src 'self' 'nonce-{$this->cspNonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com https://challenges.cloudflare.com; "
            . "style-src-elem 'self' 'nonce-{$this->cspNonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com https://challenges.cloudflare.com; "
            . "style-src-attr 'unsafe-inline'; "
            . "img-src 'self' data: https://cdn.buymeacoffee.com; "
            . "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; "
            . "frame-src 'self' https://challenges.cloudflare.com; "
            . 'connect-src ' . implode(' ', $connectSrc) . '; '
            . "object-src 'none';";
    }

    private function resolveStorageConnectSources(): array
    {
        $sources = [
            'https://*.wasabisys.com',
            'https://*.backblazeb2.com',
            'https://*.r2.cloudflarestorage.com',
            'https://*.amazonaws.com',
        ];

        try {
            $db = Database::getInstance()->getConnection();
            if (!$db) {
                return $sources;
            }

            $rows = $db->query("SELECT config FROM file_servers WHERE LOWER(status) = 'active'")->fetchAll();

            foreach ($rows as $row) {
                $config = $this->decodeFileServerConfig((string)($row['config'] ?? ''));
                $preset = strtolower(trim((string)($config['provider_preset'] ?? '')));
                $endpoint = trim((string)($config['s3_endpoint'] ?? ''));
                $region = strtolower(trim((string)($config['s3_region'] ?? '')));

                if ($preset === 'r2' && preg_match('/^[a-f0-9]{32}$/i', $endpoint)) {
                    $sources[] = 'https://' . strtolower($endpoint) . '.r2.cloudflarestorage.com';
                    continue;
                }

                if ($preset === 'wasabi' && $endpoint === '' && $region !== '') {
                    $sources[] = 'https://s3.' . $region . '.wasabisys.com';
                    continue;
                }

                if ($preset === 'b2' && $endpoint === '' && $region !== '') {
                    $sources[] = 'https://s3.' . $region . '.backblazeb2.com';
                    continue;
                }

                if ($endpoint !== '') {
                    $origin = $this->normalizeConnectSourceOrigin($endpoint);
                    if ($origin !== null) {
                        $sources[] = $origin;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('CSP storage connect-src resolution failed: ' . $e->getMessage());
        }

        return $sources;
    }

    private function decodeFileServerConfig(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = \App\Service\EncryptionService::decrypt($raw);
        if (is_string($decoded) && $decoded !== '') {
            $parsed = json_decode($decoded, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        $parsed = json_decode($raw, true);
        return is_array($parsed) ? $parsed : [];
    }

    private function normalizeConnectSourceOrigin(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
            $value = 'https://' . $value;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int)$parts['port'] : null;

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    private function appKeyIsSecure(?string $appKey): bool
    {
        $appKey = trim((string)$appKey);
        return $appKey !== '' && !in_array($appKey, self::INSECURE_APP_KEYS, true);
    }

    private function resolveWritableConfigTarget(string $dbConfigPath): ?string
    {
        $dbConfigPath = realpath($dbConfigPath) ?: $dbConfigPath;
        $raw = @file_get_contents($dbConfigPath);
        if (!is_string($raw) || $raw === '') {
            return is_writable($dbConfigPath) ? $dbConfigPath : null;
        }

        if (preg_match('/return\s+require\s+([\'"])(.+?)\1\s*;/s', $raw, $matches) === 1) {
            $target = $matches[2];
            if (!preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\/|\\\\\\\\)/', $target)) {
                $target = dirname($dbConfigPath) . DIRECTORY_SEPARATOR . $target;
            }

            return is_file($target) && is_writable($target) ? $target : null;
        }

        return is_writable($dbConfigPath) ? $dbConfigPath : null;
    }

    private function persistAppKey(string $dbConfigPath, string $appKey): bool
    {
        $target = $this->resolveWritableConfigTarget($dbConfigPath);
        if ($target === null) {
            return false;
        }

        $config = require $target;
        if (!is_array($config)) {
            return false;
        }

        $config['app_key'] = $appKey;
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        return @file_put_contents($target, $content, LOCK_EX) !== false;
    }

    private function ensureSecureAppKey(?string $dbConfigPath): void
    {
        $currentKey = (string)Config::get('app_key', '');
        if ($this->appKeyIsSecure($currentKey)) {
            return;
        }

        if ($dbConfigPath === null || !file_exists($dbConfigPath)) {
            return;
        }

        $generatedKey = bin2hex(random_bytes(16));
        $target = $this->resolveWritableConfigTarget($dbConfigPath);
        self::$runtimeSecurityNotices['app_key'] = [
            'title' => 'Application key still uses the insecure default',
            'message' => 'Fyuhls detected the insecure default application key. Rotate it from an explicit maintenance window before relying on signed download, referral, reward, or callback secrets.',
            'config_path' => $target ?? $dbConfigPath,
            'suggested_value' => $generatedKey,
        ];
        error_log('Security warning: application key is still using the insecure default and must be rotated from an explicit maintenance window.');
    }

    private function abortBootstrapConfigurationFailure(string $message): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Configuration Recovery Needed</title></head><body style="font-family:Segoe UI,Arial,sans-serif;background:#f3f4f6;color:#111827;padding:2rem;"><div style="max-width:760px;margin:0 auto;background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 10px 30px rgba(15,23,42,.08);"><h1 style="margin-top:0;">Configuration Recovery Needed</h1><p>The application could not load its hidden configuration file safely, so startup has been paused to avoid a fatal error or partial runtime.</p><p><strong>Recovery note:</strong> Restore the hidden config file referenced by <code>config/database.php</code>, or repair that pointer before continuing.</p><p style="color:#991b1b;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
        exit;
    }

    private function isSchemaRecoveryException(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_starts_with($message, 'Database schema drift detected for required tables (')
            || str_starts_with($message, 'Schema validation failed for required tables (')
            || str_starts_with($message, 'Schema repair for required tables aborted before making changes');
    }

    private function abortSchemaRecovery(\Throwable $e): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Retry-After: 300');

        $viewerIsStaff = false;
        try {
            $viewerIsStaff = Auth::isStaff();
        } catch (\Throwable $ignored) {
            $viewerIsStaff = false;
        }

        $primaryHref = $viewerIsStaff ? '/admin/configuration?tab=security&sec_tab=health' : '/login';
        $primaryLabel = $viewerIsStaff ? 'Open Database Health' : 'Staff Login';
        $secondaryCopy = $viewerIsStaff
            ? 'Run Schema Sync first, then use Deep Repair if the health panel still reports drift.'
            : 'After signing in with a staff account, open Admin > Configuration > Security > Database Health.';
        $secondaryAction = $viewerIsStaff
            ? '<a href="/admin/configuration?tab=security&sec_tab=health" style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#eff6ff;color:#1d4ed8;text-decoration:none;font-weight:800;padding:.8rem 1.15rem;">Database Health</a>'
            : '';

        error_log('Schema recovery page shown: ' . $e->getMessage());

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Database Maintenance Required</title></head><body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:linear-gradient(135deg,#eef2ff,#f8fafc 42%,#fff7ed);color:#111827;"><main style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;"><section style="max-width:780px;background:#fff;border:1px solid #e5e7eb;border-radius:22px;padding:2rem;box-shadow:0 24px 70px rgba(15,23,42,.14);"><div style="display:inline-flex;align-items:center;border-radius:999px;background:#fff7ed;color:#9a3412;font-weight:700;font-size:.8rem;padding:.38rem .78rem;margin-bottom:1rem;">Database health check paused this request</div><h1 style="font-size:clamp(2rem,4vw,3rem);line-height:1.05;margin:.25rem 0 1rem;">Database maintenance is required before this area can open.</h1><p style="font-size:1.05rem;line-height:1.7;color:#4b5563;margin:0 0 1rem;">Fyuhls detected schema drift and stopped this request instead of continuing with partial or unsafe package, upload, billing, or account behavior.</p><p style="font-size:1rem;line-height:1.65;color:#4b5563;margin:0 0 1.35rem;">' . htmlspecialchars($secondaryCopy, ENT_QUOTES, 'UTF-8') . '</p><div style="display:flex;flex-wrap:wrap;gap:.75rem;"><a href="' . htmlspecialchars($primaryHref, ENT_QUOTES, 'UTF-8') . '" style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#1d4ed8;color:#fff;text-decoration:none;font-weight:800;padding:.8rem 1.15rem;">' . htmlspecialchars($primaryLabel, ENT_QUOTES, 'UTF-8') . '</a>' . $secondaryAction . '</div><p style="margin:1.35rem 0 0;color:#6b7280;font-size:.9rem;">This page does not repair the database or bypass staff permissions. It only replaces the raw PHP fatal with a controlled recovery path.</p></section></main></body></html>';
        exit;
    }

    private function ensureBootstrapDatabaseReady(): void
    {
        $db = Database::getInstance()->getConnection();
        if ($db instanceof \PDO) {
            return;
        }

        $error = Database::getLastConnectionError();
        if ($error === null || $error === '') {
            $error = 'The database connection could not be initialized.';
        }

        $this->abortBootstrapConfigurationFailure('The hidden config loaded, but the database connection failed. Verify the database host, name, username, password, and server availability. Details: ' . $error);
    }

    public static function getRuntimeSecurityNotices(): array
    {
        return array_values(self::$runtimeSecurityNotices);
    }

    public function run(): void {
        // Load configuration
        $rootDir = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        Config::load($rootDir . '/config/app.php');
        $dbConfigPath = $rootDir . '/config/database.php';
        if (!file_exists($dbConfigPath)) {
            if (\App\Service\InstallSecurityService::hasInstalledMarker($rootDir)) {
                $this->abortBootstrapConfigurationFailure('The config/database.php pointer is missing. Restore the pointer to the hidden config file before continuing.');
            }
        }
        if (file_exists($dbConfigPath)) {
            try {
                Config::loadArray(ConfigPointerService::loadLinkedConfigArray($dbConfigPath));
                $encryptionKey = Config::get('security.encryption_key', '');
                \App\Service\EncryptionService::setKey($encryptionKey);
            } catch (\RuntimeException $e) {
                $this->abortBootstrapConfigurationFailure($e->getMessage());
            }

            $this->ensureBootstrapDatabaseReady();
            try {
                \App\Service\InstallSecurityService::writeInstalledMarker($rootDir);
            } catch (\RuntimeException $e) {
                self::$runtimeSecurityNotices['install_marker'] = [
                    'title' => 'Install marker could not be refreshed',
                    'message' => 'Fyuhls loaded the hidden config successfully, but it could not refresh the local install lock files. Recovery and installer lockout become less trustworthy until filesystem permissions are corrected.',
                    'config_path' => $dbConfigPath,
                ];
                error_log('Security warning: install marker refresh failed: ' . $e->getMessage());
            }
        }

        $this->ensureSecureAppKey(file_exists($dbConfigPath) ? $dbConfigPath : null);

        // Secure Session Start
        if (session_status() === PHP_SESSION_NONE) {
            $localSessionPath = $rootDir . '/storage/sessions';

            // If system session path is not writable, use project's storage/sessions
            if (!is_writable(session_save_path() ?: sys_get_temp_dir())) {
                if (!is_dir($localSessionPath)) {
                    mkdir($localSessionPath, 0700, true);
                }
                session_save_path($localSessionPath);
            }

            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_samesite', 'Lax');

            $isHttps = $this->isHttpsRequest();
            if ($isHttps) {
                ini_set('session.cookie_secure', 1);
            }

            session_start();

            $role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : null;
            $idleTimeout = \App\Core\Auth::idleLogoutSecondsForRole($role);

            if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
                session_unset();
                session_destroy();
                session_start();
            }

            if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
                \App\Service\RememberMeService::restoreSessionFromCookie();
            }

            $_SESSION['last_activity'] = time();
        }

        // Don't advertise what we're running - removes "PHP/8.x" from response headers
        header_remove('X-Powered-By');

        $this->cspNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

        // Security Headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // disable browser APIs we don't need (mic, camera, geolocation, etc.)
        header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()");

        if ($this->isHttpsRequest()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header('Content-Security-Policy: ' . $this->buildContentSecurityPolicy());

        ob_start(function (string $buffer): string {
            return $this->injectNonceIntoHtml($buffer);
        });

        // Load Plugins
        PluginManager::loadPlugins($this->router);

        // 1% chance to run garbage collection on any request (Simulating Cron for simple hosting)
        if (rand(1, 100) === 1) {
            GarbageCollector::cleanupChunks();
        }

        // Load Routes
        $router = $this->router;
        $rootDir = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        require $rootDir . '/config/routes.php';

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $requestPath = strtok($requestUri, '?') ?: '/';
        $smartViewMap = [
            '/starred' => 'starred',
            '/largest' => 'largest',
            '/duplicates' => 'duplicates',
        ];
        if (isset($smartViewMap[$requestPath])) {
            $_GET['view'] = $_GET['view'] ?? $smartViewMap[$requestPath];
            $_SERVER['REQUEST_URI'] = '/?view=' . rawurlencode((string)$_GET['view']);
            $requestUri = (string)$_SERVER['REQUEST_URI'];
        }

        // maintenance mode - show holding page to non-admins
        // admins can still access /admin while maintenance is on
        $uri = strtok($requestUri, '?');
        $isAdminPath = str_starts_with($uri, '/admin');
        $viewerIsAdmin = \App\Core\Auth::isAdmin();
        $viewerIsStaff = \App\Core\Auth::isStaff();
        $isStaticAssetRequest =
            str_starts_with($uri, '/assets/')
            || str_starts_with($uri, '/themes/')
            || preg_match('/\.(?:css|js|mjs|png|jpe?g|gif|svg|ico|webp|avif|woff2?|ttf|eot|map)$/i', $uri) === 1;

        // Allow a small set of public support/auth pages even when VPN/proxy blocking is enabled.
        $isPublicAuth = in_array($uri, ['/login', '/register', '/contact'], true);

        if (!$isPublicAuth && !$isStaticAssetRequest) {
            try {
                // 1. Maintenance Mode Check
                $maintenanceOn = \App\Model\Setting::get('maintenance_mode', '0') === '1';
                if ($maintenanceOn && !$isAdminPath && !$viewerIsStaff) {
                    http_response_code(503);
                    $siteName = \App\Model\Setting::getOrConfig('app.name', Config::get('app_name', 'Site'));
                    require_once dirname(__DIR__) . '/View/maintenance.php';
                    exit;
                }

                // 2. Global VPN/Proxy Block
                $vpnMode = \App\Service\SecurityService::getVpnProtectionMode();
                $vpnScope = \App\Service\SecurityService::getVpnProtectionScope();
                if ($vpnMode === 'enforcement' && $vpnScope === 'all_pages') {
                    if ($isAdminPath || $viewerIsStaff) {
                        // error_log("VPN_BLOCK: Skipping check because user is Admin.");
                    } else {
                        $ip = \App\Service\SecurityService::getClientIp();
                        $security = new \App\Service\SecurityService();
                        $proxyIntel = $security->lookupProxyIntel($ip);
                        if (!empty($proxyIntel['is_proxy']) || \App\Service\SecurityService::proxyIntelRequiresFailClosed($proxyIntel)) {
                            error_log("VPN_BLOCK: Denying access to $ip on $uri");
                            http_response_code(403);

                            // Check if it's an API request
                            if (str_starts_with($uri, '/api') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                                header('Content-Type: application/json');
                                echo json_encode(['error' => 'vpn_proxy_blocked', 'message' => 'Access from VPN/Proxy is not allowed.']);
                            } else {
                                $siteName = \App\Model\Setting::getOrConfig('app.name', Config::get('app_name', 'Site'));
                                $title = 'VPN / Proxy Detected - ' . $siteName;
                                $metaDescription = 'Access from VPN or proxy services is blocked for this request.';
                                require_once dirname(__DIR__) . '/View/home/header.php';
                                \App\Core\View::render('errors/vpn_blocked.php', [
                                    'ip' => $ip,
                                    'siteName' => $siteName,
                                    'title' => $title,
                                    'metaDescription' => $metaDescription,
                                ]);
                                require_once dirname(__DIR__) . '/View/home/footer.php';
                            }
                            exit;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("VPN_BLOCK_ERROR: " . $e->getMessage());
            }
        }

        try {
            $viewerIsDemoAdmin = \App\Service\DemoModeService::currentViewerIsDemoAdmin();
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $isReadOnlyMethod = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
            $demoAllowedPosts = ['/login', '/logout', '/2fa/verify', '/2fa/recovery'];

            // The designated demo admin account is always read-only.
            // Other admins and normal users continue to operate normally.
            if ($viewerIsDemoAdmin && !$isReadOnlyMethod && !in_array($uri, $demoAllowedPosts, true)) {
                $message = 'This demo admin account is read-only while demo mode is enabled.';
                $wantsJson = str_starts_with($uri, '/api')
                    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
                    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

                if ($wantsJson) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'demo_mode_enabled', 'message' => $message]);
                    exit;
                }

                $_SESSION['warning'] = $message;
                $fallback = str_starts_with($uri, '/admin') ? '/admin' : '/';
                $redirect = $this->resolveSafeRefererRedirect($fallback);
                header('Location: ' . $redirect);
                exit;
            }
        } catch (\Throwable $e) {
            error_log("DEMO_MODE_ERROR: " . $e->getMessage());
        }

        // Core 2FA Gatekeeper.
        // Some production installs can end up with stale Composer autoload metadata
        // during partial updates, so fall back to the known class file before fataling.
        if (!class_exists(\App\Service\TwoFactorGateService::class)) {
            $rootDir = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
            $twoFactorGatePath = $rootDir . '/src/Service/TwoFactorGateService.php';
            if (is_file($twoFactorGatePath)) {
                require_once $twoFactorGatePath;
            }
        }
        \App\Service\TwoFactorGateService::interceptRequest();

        // Global Boot Hook
        PluginManager::doAction('app_boot');

        // Dispatch
        try {
            $this->router->dispatch($requestUri, $_SERVER['REQUEST_METHOD']);
        } catch (\Throwable $e) {
            if ($this->isSchemaRecoveryException($e)) {
                $this->abortSchemaRecovery($e);
            }

            throw $e;
        }
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    public function getRouter(): Router {
        return $this->router;
    }

    private function injectNonceIntoHtml(string $buffer): string
    {
        $contentType = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, strlen('Content-Type:')));
                break;
            }
        }

        $looksLikeHtml = stripos($buffer, '<html') !== false
            || stripos($buffer, '<body') !== false
            || stripos($buffer, '<script') !== false
            || stripos($buffer, '<style') !== false;
        if (($contentType !== '' && stripos($contentType, 'text/html') === false) || !$looksLikeHtml) {
            return $buffer;
        }

        $buffer = preg_replace_callback(
            '#<script\b(?![^>]*\bnonce=)([^>]*)>#i',
            fn(array $matches): string => '<script nonce="' . htmlspecialchars($this->cspNonce, ENT_QUOTES, 'UTF-8') . '"' . $matches[1] . '>',
            $buffer
        ) ?? $buffer;

        return preg_replace_callback(
            '#<style\b(?![^>]*\bnonce=)([^>]*)>#i',
            fn(array $matches): string => '<style nonce="' . htmlspecialchars($this->cspNonce, ENT_QUOTES, 'UTF-8') . '"' . $matches[1] . '>',
            $buffer
        ) ?? $buffer;
    }
}
