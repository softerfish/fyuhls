<?php

namespace App\Core;

use App\Service\GarbageCollector;

class App {
    private Router $router;

    public function __construct() {
        $this->router = new Router();
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
            
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

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

        // Security Headers
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // disable browser APIs we don't need (mic, camera, geolocation, etc.)
        header("Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()");
        
        // Content Security Policy (CSP)
        // Allow self, unsafe-inline for scripts/styles (needed for this legacy-style app), 
        // and allow images/media from self.
        // We also allow Cloudflare Turnstile (challenges.cloudflare.com).
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src 'self' data: https://cdn.buymeacoffee.com; font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; frame-src 'self' https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com; object-src 'none';");

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
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
        $this->router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
    }

    public function getRouter(): Router {
        return $this->router;
    }
}
