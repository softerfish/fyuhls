<?php

namespace App\Core;

use App\Service\GarbageCollector;

class App {
    private Router $router;
    private string $cspNonce = '';

    public function __construct() {
        $this->router = new Router();
    }

    private function isHttpsRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
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

        return "default-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'; "
            . "script-src 'self' 'nonce-{$this->cspNonce}' https://challenges.cloudflare.com https://cdn.jsdelivr.net https://static.cloudflareinsights.com; "
            . "script-src-elem 'self' 'nonce-{$this->cspNonce}' https://challenges.cloudflare.com https://cdn.jsdelivr.net https://static.cloudflareinsights.com; "
            . "style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
            . "style-src-elem 'self' 'nonce-{$this->cspNonce}' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
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

    public function run(): void {
        // Secure Session Start
        if (session_status() === PHP_SESSION_NONE) {
            $rootDir = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
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

            // tiered session timeout: admins get 4 hours idle, regular users stay for 30 days
            $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
            $idleTimeout = $isAdmin ? (4 * 3600) : (30 * 86400);

            if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleTimeout) {
                session_unset();
                session_destroy();
                session_start();
            }
            $_SESSION['last_activity'] = time();
        }

        // Don't advertise what we're running - removes "PHP/8.x" from response headers
        header_remove('X-Powered-By');

        $this->cspNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

        // Security Headers
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // disable browser APIs we don't need (mic, camera, geolocation, etc.)
        header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()");
        
        if ($this->isHttpsRequest()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Load configuration
        $rootDir = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        Config::load($rootDir . '/config/app.php');
        $dbConfigPath = $rootDir . '/config/database.php';
        if (file_exists($dbConfigPath)) {
            Config::load($dbConfigPath);
            
            // Initialize Database Encryption Service
            $encryptionKey = Config::get('security.encryption_key', '');
            \App\Service\EncryptionService::setKey($encryptionKey);
        }

        header('Content-Security-Policy: ' . $this->buildContentSecurityPolicy());

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

        // maintenance mode - show holding page to non-admins
        // admins can still access /admin while maintenance is on
        $uri = strtok($_SERVER['REQUEST_URI'], '?');
        $isAdminPath = str_starts_with($uri, '/admin');
        $isAdminSession = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
        
        // Allow login and registration even when VPN/proxy blocking is enabled.
        $isPublicAuth = in_array($uri, ['/login', '/register'], true);

        if (!$isPublicAuth) { 
            try {
                // 1. Maintenance Mode Check
                $maintenanceOn = \App\Model\Setting::get('maintenance_mode', '0') === '1';
                if ($maintenanceOn && !$isAdminPath && !$isAdminSession) {
                    http_response_code(503);
                    $siteName = \App\Model\Setting::getOrConfig('app.name', Config::get('app_name', 'Site'));
                    require_once dirname(__DIR__) . '/View/maintenance.php';
                    exit;
                }

                // 2. Global VPN/Proxy Block
                $vpnMode = \App\Model\Setting::get('vpn_proxy_mode', \App\Model\Setting::get('block_vpn_traffic', '0') === '1' ? 'enforcement' : 'intelligence');
                if ($vpnMode === 'enforcement') {
                    if ($isAdminPath || $isAdminSession) {
                        // error_log("VPN_BLOCK: Skipping check because user is Admin.");
                    } else {
                        $ip = \App\Service\SecurityService::getClientIp();
                        $security = new \App\Service\SecurityService();
                        if ($security->isVpnOrProxy($ip)) {
                            error_log("VPN_BLOCK: Denying access to $ip on $uri");
                            http_response_code(403);
                            
                            // Check if it's an API request
                            if (str_starts_with($uri, '/api') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                                header('Content-Type: application/json');
                                echo json_encode(['error' => 'vpn_proxy_blocked', 'message' => 'Access from VPN/Proxy is not allowed.']);
                            } else {
                                $siteName = \App\Model\Setting::getOrConfig('app.name', Config::get('app_name', 'Site'));
                                require_once dirname(__DIR__) . '/View/errors/vpn_blocked.php';
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
            $demoAllowedPosts = ['/login', '/2fa/verify', '/2fa/recovery'];

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

        // Core 2FA Gatekeeper
        \App\Service\TwoFactorGateService::interceptRequest();

        // Global Boot Hook
        PluginManager::doAction('app_boot');

        // Dispatch
        ob_start(function (string $buffer): string {
            return $this->injectNonceIntoHtml($buffer);
        });
        $this->router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
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

        $looksLikeHtml = stripos($buffer, '<html') !== false || stripos($buffer, '<script') !== false;
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
