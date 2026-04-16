<?php

namespace App\Controller\Admin;

use App\Model\Package;
use App\Model\Setting;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;
use App\Core\Database;
use App\Core\Logger;
use App\Service\EncryptionService;
use App\Service\DemoModeService;
use App\Service\DiagnosticsService;
use App\Service\MailService;
use App\Service\UpdateService;

/**
 * AdminController - General Admin Operations
 * 
 * Logic for non-configuration administrative tasks like user management,
 * reports, and server monitoring history.
 */
class AdminController
{
    private function ensureDemoAdminReadOnly(bool $json = false, string $redirect = '/admin'): void
    {
        if (!DemoModeService::currentViewerIsDemoAdmin()) {
            return;
        }

        $message = 'This demo admin account is read-only while demo mode is enabled.';

        if ($json) {
            $this->jsonResponse(['success' => false, 'message' => $message], 403);
        }

        $_SESSION['error'] = $message;
        header('Location: ' . $redirect);
        exit;
    }

    private function formatReadableBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : 2;
        return number_format($value, $precision) . ' ' . $units[$unitIndex];
    }

    private function projectRoot(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
    }

    private function normalizeFilesystemSeparators(string $path): string
    {
        return preg_replace('#[\\\\/]+#', DIRECTORY_SEPARATOR, $path) ?? $path;
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        return $path !== '' && (
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 ||
            str_starts_with($path, '\\\\') ||
            str_starts_with($path, '/')
        );
    }

    private function canonicalizePathForValidation(string $path): string
    {
        $normalized = $this->normalizeFilesystemSeparators($path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1) {
            $prefix = strtoupper(substr($normalized, 0, 2)) . DIRECTORY_SEPARATOR;
            $normalized = substr($normalized, 3);
        } elseif (str_starts_with($normalized, '\\\\')) {
            $prefix = '\\\\';
            $normalized = ltrim(substr($normalized, 2), '\\');
        } elseif (str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);
        }

        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $normalized) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (!empty($parts) && end($parts) !== '..') {
                    array_pop($parts);
                    continue;
                }
                if ($prefix === '') {
                    $parts[] = '..';
                }
                continue;
            }
            $parts[] = $part;
        }

        return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function pathStartsWith(string $candidate, string $base): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');
        return $candidate === $base || str_starts_with($candidate . '/', $base . '/');
    }

    private function validateLocalStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \RuntimeException('Storage Path is required.');
        }

        $safeBase = $this->canonicalizePathForValidation($this->projectRoot() . DIRECTORY_SEPARATOR . 'storage');
        if ($this->isAbsoluteFilesystemPath($path)) {
            $candidate = $this->canonicalizePathForValidation($path);
        } else {
            $relative = ltrim($this->normalizeFilesystemSeparators($path), DIRECTORY_SEPARATOR);
            $candidate = $this->canonicalizePathForValidation($this->projectRoot() . DIRECTORY_SEPARATOR . $relative);
        }

        if (!$this->pathStartsWith($candidate, $safeBase)) {
            throw new \RuntimeException('Local storage paths must stay inside the Fyuhls storage directory. Use a path under storage/, such as storage/uploads.');
        }

        return $path;
    }

    private function normalizeProviderPreset(?string $preset, string $serverType): string
    {
        $preset = strtolower(trim((string)$preset));
        if ($serverType === 'local') {
            return 'local';
        }

        return in_array($preset, ['b2', 'r2', 'wasabi', 's3'], true) ? $preset : 's3';
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);
            return $normalized === '::1'
                || str_starts_with($normalized, 'fc')
                || str_starts_with($normalized, 'fd')
                || str_starts_with($normalized, 'fe80:')
                || str_starts_with($normalized, '::ffff:127.')
                || str_starts_with($normalized, '::ffff:10.')
                || str_starts_with($normalized, '::ffff:192.168.')
                || preg_match('/^::ffff:172\.(1[6-9]|2\d|3[0-1])\./', $normalized) === 1;
        }

        return true;
    }

    private function validateResolvableHostSafety(string $host): void
    {
        $host = strtolower(trim($host));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost')) {
            throw new \RuntimeException('Storage endpoints cannot point at localhost or loopback hosts.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && $this->isPrivateOrReservedIp($host)) {
            throw new \RuntimeException('Storage endpoints cannot use private, loopback, or reserved IP addresses.');
        }

        $resolvedIps = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $resolvedIps = array_merge($resolvedIps, $ipv4);
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (!empty($record['ipv6'])) {
                        $resolvedIps[] = $record['ipv6'];
                    }
                }
            }
        }

        foreach (array_unique($resolvedIps) as $ip) {
            if ($this->isPrivateOrReservedIp((string)$ip)) {
                throw new \RuntimeException('Storage endpoints cannot resolve to private, loopback, or reserved IP addresses.');
            }
        }
    }

    private function normalizeEndpointUrl(string $endpoint, string $label): array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new \RuntimeException($label . ' is required.');
        }

        if (!str_starts_with($endpoint, 'http://') && !str_starts_with($endpoint, 'https://')) {
            $endpoint = 'https://' . $endpoint;
        }

        $parts = parse_url($endpoint);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int)$parts['port'] : null;

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException($label . ' must be a valid http:// or https:// URL.');
        }
        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['path']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new \RuntimeException($label . ' must only contain the endpoint host and optional port.');
        }

        $this->validateResolvableHostSafety($host);

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'url' => $scheme . '://' . $host . ($port !== null ? ':' . $port : ''),
        ];
    }

    private function normalizePublicUrl(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Public Download URL must start with http:// or https://');
        }

        $validated = filter_var($url, FILTER_VALIDATE_URL);
        if ($validated === false) {
            throw new \RuntimeException('Public Download URL is not a valid URL.');
        }

        return rtrim((string)$validated, '/') . '/';
    }

    private function normalizeDeliveryMethod(?string $method): string
    {
        $method = trim((string)$method);
        return in_array($method, ['php', 'nginx', 'apache', 'litespeed'], true) ? $method : 'php';
    }

    private function normalizeFileServerType(?string $type): string
    {
        $type = trim((string)$type);
        if (!in_array($type, ['local', 's3'], true)) {
            throw new \RuntimeException('Unsupported storage server type.');
        }

        return $type;
    }

    private function validateStorageAutomationOrigin(string $origin): string
    {
        $origin = rtrim(trim($origin), '/');
        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        if ($origin === '' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \RuntimeException('Set your real production URL in Config Hub > SEO before using automatic storage CORS. Fyuhls should not apply upload CORS for localhost.');
        }

        return $origin;
    }

    private function getStorageAutomationOrigins(): array
    {
        $origins = [];
        $origins[] = $this->validateStorageAutomationOrigin(\App\Service\SeoService::trustedBaseUrl());

        $requestHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($requestHost !== '') {
            $requestScheme = 'http';
            $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            if ($forwardedProto !== '') {
                $requestScheme = explode(',', $forwardedProto)[0] === 'https' ? 'https' : 'http';
            } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
                $requestScheme = strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
            } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                $requestScheme = 'https';
            }

            $origins[] = $this->validateStorageAutomationOrigin($requestScheme . '://' . $requestHost);
        }

        return array_values(array_unique($origins));
    }

    private function requestArchiveStatusMap(): array
    {
        return [
            'site_request' => ['archived'],
            'dmca_report' => ['accepted', 'rejected'],
            'abuse_report' => ['action_taken', 'ignored'],
        ];
    }

    private function isArchivedRequestStatus(string $type, string $status): bool
    {
        return in_array($status, $this->requestArchiveStatusMap()[$type] ?? [], true);
    }

    private function ensureRequestInboxSchema(): void
    {
        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_request_activity (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_type VARCHAR(50) NOT NULL,
                request_id BIGINT UNSIGNED NOT NULL,
                admin_user_id BIGINT UNSIGNED NULL,
                activity_type VARCHAR(32) NOT NULL,
                subject VARCHAR(255) NULL,
                body TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_request_lookup (request_type, request_id, created_at),
                KEY idx_activity_type (activity_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function addRequestActivity(string $requestType, int $requestId, string $activityType, ?string $subject = null, ?string $body = null, array $metadata = []): void
    {
        $this->ensureRequestInboxSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO admin_request_activity (request_type, request_id, admin_user_id, activity_type, subject, body, metadata_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $requestType,
            $requestId,
            Auth::id() ? (int)Auth::id() : null,
            $activityType,
            $subject,
            $body,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    private function fetchRequestActivityMap(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $this->ensureRequestInboxSchema();
        $db = Database::getInstance()->getConnection();
        $clauses = [];
        $params = [];

        foreach ($items as $item) {
            $clauses[] = '(request_type = ? AND request_id = ?)';
            $params[] = (string)$item['type_key'];
            $params[] = (int)$item['id'];
        }

        $sql = "
            SELECT a.*, u.username
            FROM admin_request_activity a
            LEFT JOIN users u ON a.admin_user_id = u.id
            WHERE " . implode(' OR ', $clauses) . "
            ORDER BY a.created_at DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['request_type'] . ':' . $row['request_id'];
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
            $row['username'] = EncryptionService::decrypt((string)($row['username'] ?? ''));
            $map[$key][] = $row;
        }

        return $map;
    }

    private function updateInboxStatus(string $type, int $id, string $status): void
    {
        $db = Database::getInstance()->getConnection();

        if ($type === 'site_request') {
            $allowed = ['new', 'read', 'replied', 'archived', 'closed'];
            if (!in_array($status, $allowed, true)) {
                throw new \RuntimeException('Invalid contact status.');
            }
            $db->prepare("UPDATE contact_messages SET status = ? WHERE id = ?")->execute([$status === 'closed' ? 'archived' : $status, $id]);
            return;
        }

        if ($type === 'dmca_report') {
            $allowed = ['pending', 'investigating', 'accepted', 'rejected', 'resolved'];
            if (!in_array($status, $allowed, true)) {
                throw new \RuntimeException('Invalid DMCA status.');
            }
            $db->prepare("UPDATE dmca_reports SET status = ? WHERE id = ?")->execute([$status === 'resolved' ? 'accepted' : $status, $id]);
            return;
        }

        if ($type === 'abuse_report') {
            $allowed = ['pending', 'reviewed', 'action_taken', 'ignored', 'dismissed'];
            if (!in_array($status, $allowed, true)) {
                throw new \RuntimeException('Invalid abuse status.');
            }
            $db->prepare("UPDATE abuse_reports SET status = ? WHERE id = ?")->execute([$status === 'dismissed' ? 'ignored' : $status, $id]);
            return;
        }

        throw new \RuntimeException('Unknown request type.');
    }

    private function assertRequestExists(string $type, int $id): void
    {
        $db = Database::getInstance()->getConnection();

        if ($type === 'site_request') {
            $stmt = $db->prepare("SELECT 1 FROM contact_messages WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Contact message not found.');
            }
            return;
        }

        if ($type === 'dmca_report') {
            $stmt = $db->prepare("SELECT 1 FROM dmca_reports WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('DMCA report not found.');
            }
            return;
        }

        if ($type === 'abuse_report') {
            $stmt = $db->prepare("SELECT 1 FROM abuse_reports WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Abuse report not found.');
            }
            return;
        }

        throw new \RuntimeException('Unknown request type.');
    }

    private function sanitizeInternalRedirect(?string $target, string $fallback = '/admin'): string
    {
        if (!is_string($target) || $target === '') {
            return $fallback;
        }

        if ($target[0] !== '/' || str_starts_with($target, '//')) {
            return $fallback;
        }

        return $target;
    }

    private function checkAuth()
    {
        Auth::requireAdmin();
    }

    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    public function dashboard()
    {
        $this->checkAuth();
        $statsService = new \App\Service\DashboardService();
        $hostService = new \App\Service\HostService();
        
        View::render('admin/dashboard.php', [
            'bundle' => $statsService->getStatsBundle(),
            'host' => $hostService->getMetrics()
        ]);
    }

    public function viewLogs()
    {
        $this->checkAuth();
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $logFile = dirname(__DIR__, 3) . '/storage/logs/app.log';
        if (!file_exists($logFile)) {
            $dir = dirname($logFile);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($logFile, '--- Log file started ---' . PHP_EOL);
        }
        clearstatcache(true, $logFile);
        $logSizeBytes = is_file($logFile) ? (int)filesize($logFile) : 0;

        $lines = [];
        if ($demoAdmin) {
            $lines[] = "Raw application logs are hidden for the demo admin account. Use a non-demo admin account for direct log access." . PHP_EOL;
        } else {
            $fp = fopen($logFile, 'r');
            fseek($fp, 0, SEEK_END);
            $pos = ftell($fp);
            $count = 0;
            while ($pos > 0 && $count < 200) {
                fseek($fp, $pos--);
                if (fgetc($fp) === "\n") {
                    $count++;
                }
            }
            while ($line = fgets($fp)) {
                $decoded = json_decode($line, true);
                if ($decoded && isset($decoded['ctx'])) {
                    foreach ($decoded['ctx'] as $key => &$val) {
                        if (is_string($val) && str_starts_with($val, 'ENC:')) {
                            $val = EncryptionService::decrypt($val);
                        }
                    }
                    $line = json_encode($decoded) . PHP_EOL;
                }
                $lines[] = $line;
            }
            fclose($fp);
        }

        View::render('admin/logs.php', [
            'logContent' => implode('', array_reverse($lines)),
            'demoAdmin' => $demoAdmin,
            'logSizeBytes' => $logSizeBytes,
            'logSizeReadable' => $this->formatReadableBytes($logSizeBytes),
            'logMaxBytes' => 26214400,
            'logMaxReadable' => $this->formatReadableBytes(26214400),
        ]);
    }

    public function clearLogs()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            
            $logFile = dirname(__DIR__, 3) . '/storage/logs/app.log';
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
            }
        }
        $target = $this->sanitizeInternalRedirect($_POST['redirect'] ?? null, '/admin/logs');
        header("Location: " . $target); exit;
    }

    public function deleteSetupFile()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            
            $type = $_POST['type'] ?? 'install';
            $root = defined('BASE_PATH') ? BASE_PATH : realpath(__DIR__ . '/../../..');
            $targets = [
                'install' => $root . '/public/install.php',
                'schema' => $root . '/database',
                'post_install_check' => $root . '/public/post_install_check.php',
            ];
            $target = $targets[$type] ?? $targets['install'];
            $target = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target);
            
            if (file_exists($target)) {
                if (is_dir($target)) {
                    $this->deleteDirectoryRecursively($target);
                } else {
                    unlink($target);
                }
                $_SESSION['success'] = "Maintenance cleanup successful.";
            }
        }
        header("Location: /admin"); exit;
    }

    private function deleteDirectoryRecursively(string $path): void
    {
        $items = @scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->deleteDirectoryRecursively($itemPath);
            } elseif (file_exists($itemPath)) {
                @unlink($itemPath);
            }
        }

        @rmdir($path);
    }

    public function subscriptions()
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT s.*, u.username, p.name as package_name FROM subscriptions s LEFT JOIN users u ON s.user_id = u.id LEFT JOIN packages p ON s.package_id = p.id ORDER BY s.created_at DESC");
        $subscriptions = $stmt->fetchAll();
        
        foreach ($subscriptions as &$sub) {
            $sub['username'] = EncryptionService::decrypt($sub['username'] ?? '');
        }
        
        View::render('admin/subscriptions.php', ['subscriptions' => $subscriptions]);
    }

    public function resources()
    {
        $this->checkAuth();

        $resourceSections = [
            [
                'title' => 'Affiliates',
                'description' => 'Affiliate programs that can help Fyuhls operators monetize traffic, test offer quality, or compare ad networks while building out their download pages and landing flows.',
                'items' => [
                    [
                        'name' => 'HilltopAds',
                        'url' => 'https://hilltopads.com/?ref=327244',
                        'description' => 'A mainstream ad network worth reviewing if you want additional monetization options around download pages, redirects, and broader traffic monetization tests.',
                    ],
                    [
                        'name' => 'Monetag',
                        'url' => 'https://monetag.com/?ref_id=zlFr',
                        'description' => 'A traffic monetization platform that can be useful when you want to compare ad formats, payout approaches, and fill quality against your existing setup.',
                    ],
                ],
            ],
            [
                'title' => 'Technology Partners',
                'description' => 'Services and software resources that can strengthen fraud controls, operational insight, and the business side of a new file hosting site.',
                'items' => [
                    [
                        'name' => 'proxycheck.io',
                        'url' => 'https://proxycheck.io/',
                        'description' => 'A powerful API service for detecting VPNs, proxies, Tor exit nodes, and bad actors. It\'s an excellent tool to integrate if you want to block bots from inflating download counts or protect your platform from serial abusers and fraudulent reward claims. They offer a generous free tier, making it very easy to test out their intelligence feed alongside your own security rules.',
                    ],
                    [
                        'name' => 'themasoftware.com',
                        'url' => 'https://themasoftware.com/',
                        'description' => 'A suite of mass-posting and content automation software widely used by top-tier uploaders and affiliates. Their tools (like themaPoster and themaManager) help users blast file links across hundreds of forums and blogs automatically. Understanding how these tools work is incredibly useful if you want to attract high-volume uploaders to your platform and monetize their traffic.',
                    ],
                ],
            ],
            [
                'title' => 'Hosting Partners',
                'description' => 'Reserved for future web-host and infrastructure partnerships that can help new Fyuhls operators launch faster.',
                'items' => [],
            ],
        ];

        View::render('admin/resources.php', [
            'resourceSections' => $resourceSections,
            'sponsorEmail' => 'fyuhls.script@gmail.com',
        ]);
    }

    public function withdrawals()
    {
        $this->checkAuth();
        if (!\App\Service\FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT w.*, u.username, u.email as user_email FROM withdrawals w JOIN users u ON w.user_id = u.id ORDER BY w.created_at DESC");
        $withdrawals = $stmt->fetchAll();
        
        foreach ($withdrawals as &$w) {
            $w['username']   = EncryptionService::decrypt($w['username']);
            $w['user_email'] = EncryptionService::decrypt($w['user_email']);
            $w['details']    = EncryptionService::decrypt($w['details']);
            $w['admin_note'] = EncryptionService::decrypt($w['admin_note'] ?? '');
        }
        
        View::render('admin/withdrawals.php', ['withdrawals' => $withdrawals]);
    }

    public function updateWithdrawal()
    {
        $this->checkAuth();
        if (!\App\Service\FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $id = (int)$_POST['id'];
            $newStatus = trim((string)($_POST['status'] ?? ''));
            $note = $_POST['admin_note'] ?? '';
            $encNote = EncryptionService::encrypt($note);
            $adminId = Auth::id();
            $allowedStatuses = ['pending', 'approved', 'paid', 'rejected'];

            if (!in_array($newStatus, $allowedStatuses, true)) {
                $_SESSION['error'] = "Invalid withdrawal status.";
                header("Location: /admin/withdrawals"); exit;
            }

            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT status, user_id, amount, method FROM withdrawals WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch();

            if (!$current) die("Withdrawal not found");

            if (in_array($current['status'], ['paid', 'rejected'])) {
                $_SESSION['error'] = "This withdrawal is locked and cannot be modified.";
                header("Location: /admin/withdrawals"); exit;
            }

            $stmt = $db->prepare("UPDATE withdrawals SET status = ?, admin_note = ?, processed_at = NOW(), processed_by_admin_id = ? WHERE id = ?");
            if ($stmt->execute([$newStatus, $encNote, $adminId, $id])) {
                if (($current['status'] ?? '') === 'pending' && $newStatus !== 'pending') {
                    \App\Service\SystemStatsService::decrement('pending_withdrawals');
                } elseif (($current['status'] ?? '') !== 'pending' && $newStatus === 'pending') {
                    \App\Service\SystemStatsService::increment('pending_withdrawals');
                }
            }

            $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
            $userStmt->execute([$current['user_id']]);
            $user = $userStmt->fetch();
            if ($user) {
                $email = EncryptionService::decrypt((string)$user['email']);
                $username = EncryptionService::decrypt((string)$user['username']);
                $templateMap = [
                    'approved' => 'withdrawal_status_approved',
                    'paid' => 'withdrawal_status_paid',
                    'rejected' => 'withdrawal_status_rejected',
                ];
                if (isset($templateMap[$newStatus]) && $email !== '') {
                    MailService::sendTemplate($email, $templateMap[$newStatus], [
                        '{username}' => $username,
                        '{amount}' => '$' . number_format((float)$current['amount'], 2),
                        '{method}' => strtoupper((string)($current['method'] ?? 'PAYOUT')),
                        '{admin_note}' => $note !== '' ? $note : 'No additional note provided.',
                    ], 'low');
                }
            }

            \App\Service\NotificationService::send(
                $current['user_id'],
                "Withdrawal Updated",
                "Your withdrawal request for $" . number_format($current['amount'], 2) . " has been " . strtoupper($newStatus),
                ($newStatus === 'paid' ? 'success' : 'info')
            );
        }
        header("Location: /admin/withdrawals"); exit;
    }

    public function rewardsFraud()
    {
        $this->checkAuth();
        if (!\App\Service\FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }

        $fraud = new \App\Service\RewardFraudService();
        View::render('admin/rewards_fraud.php', [
            'overview' => $fraud->getOverview(),
            'reviewQueue' => $fraud->getReviewQueue(100),
            'uploaderScores' => $fraud->getUploaderScores(50),
            'networkInsights' => $fraud->getNetworkInsights(25),
            'cloudflareHealth' => $fraud->getCloudflareHealth(),
            'settings' => [
                'rewards_fraud_enabled' => Setting::get('rewards_fraud_enabled', '1'),
                'rewards_verified_completion_required' => Setting::get('rewards_verified_completion_required', '1'),
                'rewards_auto_clear_low_risk' => Setting::get('rewards_auto_clear_low_risk', '0'),
                'rewards_hold_days' => Setting::get('rewards_hold_days', '7'),
                'rewards_review_threshold' => Setting::get('rewards_review_threshold', '25'),
                'rewards_flag_threshold' => Setting::get('rewards_flag_threshold', '50'),
                'rewards_fraud_event_retention_days' => Setting::get('rewards_fraud_event_retention_days', '30'),
                'rewards_fraud_trim_mb' => Setting::get('rewards_fraud_trim_mb', '1024'),
                'rewards_use_cloudflare_intel' => Setting::get('rewards_use_cloudflare_intel', '1'),
                'rewards_use_proxy_intel' => Setting::get('rewards_use_proxy_intel', '0'),
                'rewards_use_ip_hash' => Setting::get('rewards_use_ip_hash', '1'),
                'rewards_use_ua_hash' => Setting::get('rewards_use_ua_hash', '1'),
                'rewards_use_cookie_hash' => Setting::get('rewards_use_cookie_hash', '1'),
                'rewards_use_accept_language_hash' => Setting::get('rewards_use_accept_language_hash', '1'),
                'rewards_use_timezone_offset' => Setting::get('rewards_use_timezone_offset', '1'),
                'rewards_use_platform_screen' => Setting::get('rewards_use_platform_screen', '1'),
                'rewards_use_asn_network' => Setting::get('rewards_use_asn_network', '1'),
                'rewards_ppd_guests_only' => Setting::get('rewards_ppd_guests_only', '0'),
                'rewards_require_downloader_verification' => Setting::get('rewards_require_downloader_verification', '0'),
                'rewards_min_downloader_account_age_days' => Setting::get('rewards_min_downloader_account_age_days', '0'),
                'rewards_block_linked_downloader_accounts' => Setting::get('rewards_block_linked_downloader_accounts', '0'),
                'rewards_hold_new_account_downloads' => Setting::get('rewards_hold_new_account_downloads', '0'),
            ],
        ]);
    }

    public function saveRewardsFraud()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $boolKeys = [
            'rewards_fraud_enabled',
            'rewards_verified_completion_required',
            'rewards_auto_clear_low_risk',
            'rewards_use_cloudflare_intel',
            'rewards_use_proxy_intel',
            'rewards_use_ip_hash',
            'rewards_use_ua_hash',
            'rewards_use_cookie_hash',
            'rewards_use_accept_language_hash',
            'rewards_use_timezone_offset',
            'rewards_use_platform_screen',
            'rewards_use_asn_network',
            'rewards_ppd_guests_only',
            'rewards_require_downloader_verification',
            'rewards_block_linked_downloader_accounts',
            'rewards_hold_new_account_downloads',
        ];

        foreach ($boolKeys as $key) {
            Setting::set($key, isset($_POST[$key]) ? '1' : '0', 'rewards_fraud');
        }

        Setting::set('rewards_hold_days', (string)max(0, (int)($_POST['rewards_hold_days'] ?? 7)), 'rewards_fraud');
        Setting::set('rewards_review_threshold', (string)max(0, (int)($_POST['rewards_review_threshold'] ?? 25)), 'rewards_fraud');
        Setting::set('rewards_flag_threshold', (string)max(1, (int)($_POST['rewards_flag_threshold'] ?? 50)), 'rewards_fraud');
        Setting::set('rewards_fraud_event_retention_days', (string)max(7, (int)($_POST['rewards_fraud_event_retention_days'] ?? 30)), 'rewards_fraud');
        Setting::set('rewards_fraud_trim_mb', (string)max(64, (int)($_POST['rewards_fraud_trim_mb'] ?? 1024)), 'rewards_fraud');
        Setting::set('rewards_min_downloader_account_age_days', (string)max(0, (int)($_POST['rewards_min_downloader_account_age_days'] ?? 0)), 'rewards_fraud');

        $_SESSION['success'] = 'Rewards fraud settings updated.';
        header('Location: /admin/rewards-fraud');
        exit;
    }

    public function reviewRewardsFraud()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $earningId = (int)($_POST['earning_id'] ?? 0);
        $action = trim((string)($_POST['review_action'] ?? ''));
        $note = trim((string)($_POST['review_note'] ?? ''));
        $fraud = new \App\Service\RewardFraudService();

        if ($earningId <= 0 || !in_array($action, ['clear', 'hold', 'reverse'], true)) {
            $_SESSION['error'] = 'Invalid review action.';
            header('Location: /admin/rewards-fraud');
            exit;
        }

        if ($fraud->reviewEarning($earningId, $action, (int)(Auth::id() ?? 0), $note)) {
            $_SESSION['success'] = 'Rewards fraud review updated.';
        } else {
            $_SESSION['error'] = 'Could not update that earning review.';
        }

        header('Location: /admin/rewards-fraud');
        exit;
    }

    public function abuseReports()
    {
        $this->checkAuth();
        header("Location: /admin/requests");
        exit;
    }

    public function handleAbuseReport()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $id = (int)($_POST['report_id'] ?? $_POST['id'] ?? 0);
            $action = $_POST['action'] ?? ''; // delete_file, dismiss, ignore

            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT r.*, f.user_id, f.filename FROM abuse_reports r JOIN files f ON r.file_id = f.id WHERE r.id = ?");
            $stmt->execute([$id]);
            $report = $stmt->fetch();

            if (!$report) die("Report not found");

            if ($action === 'delete_file') {
                $fileService = new \App\Service\FileService();
                $fileService->deleteFile((int)$report['file_id']);
                $this->updateInboxStatus('abuse_report', $id, 'action_taken');
                $this->addRequestActivity('abuse_report', $id, 'status', 'Status changed', 'Marked as action taken after file deletion.', [
                    'status' => 'action_taken',
                ]);
                $_SESSION['success'] = "File deleted and report marked as action taken.";
            } elseif (in_array($action, ['dismiss', 'ignore'], true)) {
                $this->updateInboxStatus('abuse_report', $id, 'dismissed');
                $this->addRequestActivity('abuse_report', $id, 'status', 'Status changed', 'Abuse report dismissed.', [
                    'status' => 'dismissed',
                ]);
                $_SESSION['success'] = "Report dismissed.";
            }
        }
        header("Location: /admin/requests"); exit;
    }

    public function contacts()
    {
        $this->checkAuth();
        header("Location: /admin/requests");
        exit;
    }

    public function requests()
    {
        $this->checkAuth();
        $this->ensureRequestInboxSchema();
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $db = Database::getInstance()->getConnection();
        $items = [];
        $filterType = (string)($_GET['type'] ?? 'all');
        $filterStatus = trim((string)($_GET['status'] ?? ''));
        $showArchived = $filterType === 'archived';

        $messages = $db->query("SELECT * FROM contact_messages ORDER BY created_at DESC")->fetchAll();
        foreach ($messages as $m) {
            $items[] = [
                'request_type' => 'Site Request',
                'type_key' => 'site_request',
                'id' => (int)$m['id'],
                'created_at' => $m['created_at'],
                'submitter_name' => EncryptionService::decrypt($m['name']),
                'submitter_email' => EncryptionService::decrypt($m['email']),
                'target' => EncryptionService::decrypt($m['subject']),
                'summary' => EncryptionService::decrypt($m['message']),
                'details' => EncryptionService::decrypt($m['message']),
                'status' => $m['status'],
            ];
        }

        $abuseReports = $db->query("SELECT r.*, f.filename, f.short_id FROM abuse_reports r JOIN files f ON r.file_id = f.id ORDER BY r.created_at DESC")->fetchAll();
        foreach ($abuseReports as $r) {
            $items[] = [
                'request_type' => 'Abuse Report',
                'type_key' => 'abuse_report',
                'id' => (int)$r['id'],
                'created_at' => $r['created_at'],
                'submitter_name' => 'Reporter IP',
                'submitter_email' => EncryptionService::decrypt($r['reporter_ip']),
                'target' => EncryptionService::decrypt($r['filename']) . ' (' . ($r['short_id'] ?? '') . ')',
                'summary' => strtoupper((string)$r['reason']) . (!empty($r['details']) ? ' - ' . EncryptionService::decrypt($r['details']) : ''),
                'details' => EncryptionService::decrypt((string)($r['details'] ?? '')),
                'reason' => strtoupper((string)$r['reason']),
                'status' => $r['status'],
            ];
        }

        $dmcaReports = $db->query("SELECT * FROM dmca_reports ORDER BY created_at DESC")->fetchAll();
        foreach ($dmcaReports as $r) {
            $items[] = [
                'request_type' => 'DMCA Report',
                'type_key' => 'dmca_report',
                'id' => (int)$r['id'],
                'created_at' => $r['created_at'],
                'submitter_name' => EncryptionService::decrypt($r['reporter_name']),
                'submitter_email' => EncryptionService::decrypt($r['reporter_email']),
                'target' => EncryptionService::decrypt($r['infringing_url']),
                'summary' => EncryptionService::decrypt($r['description']),
                'details' => EncryptionService::decrypt($r['description']),
                'status' => $r['status'],
                'signature' => EncryptionService::decrypt($r['signature']),
            ];
        }

        if (!$showArchived && $filterType !== 'all') {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['type_key'] === $filterType));
        }

        $items = array_values(array_filter($items, function (array $item) use ($showArchived): bool {
            $status = (string)($item['status'] ?? '');
            $type = (string)($item['type_key'] ?? '');
            $archived = $this->isArchivedRequestStatus($type, $status);
            return $showArchived ? $archived : !$archived;
        }));

        if ($filterStatus !== '') {
            $items = array_values(array_filter($items, static fn (array $item): bool => strcasecmp((string)$item['status'], $filterStatus) === 0));
        }

        $activityMap = $this->fetchRequestActivityMap($items);
        foreach ($items as &$item) {
            $key = $item['type_key'] . ':' . $item['id'];
            $activities = $activityMap[$key] ?? [];
            $item['activities'] = $activities;
            $item['latest_reply'] = null;
            foreach ($activities as $activity) {
                if ($item['latest_reply'] === null && $activity['activity_type'] === 'reply') {
                    $item['latest_reply'] = $activity;
                }
            }
        }
        unset($item);

        if ($demoAdmin) {
            foreach ($items as &$item) {
                $item['submitter_name'] = DemoModeService::maskPerson((string)($item['submitter_name'] ?? ''));
                $rawSubmitter = (string)($item['submitter_email'] ?? '');
                $item['submitter_email'] = str_contains($rawSubmitter, '@')
                    ? DemoModeService::maskEmail($rawSubmitter)
                    : DemoModeService::maskIp($rawSubmitter);
                foreach (['summary', 'details', 'signature'] as $field) {
                    if (isset($item[$field])) {
                        $item[$field] = DemoModeService::hiddenLabel();
                    }
                }
            }
            unset($item);
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)$b['created_at'], (string)$a['created_at']);
        });

        View::render('admin/requests.php', [
            'items' => $items,
            'filterType' => $filterType,
            'filterStatus' => $filterStatus,
            'showArchived' => $showArchived,
            'demoAdmin' => $demoAdmin,
        ]);
    }

    public function replyToRequest()
    {
        $this->checkAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/requests');
            exit;
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $type = (string)($_POST['request_type'] ?? '');
        $id = (int)($_POST['request_id'] ?? 0);
        $subject = trim((string)($_POST['reply_subject'] ?? ''));
        $message = trim((string)($_POST['reply_message'] ?? ''));
        $statusAfterReply = trim((string)($_POST['status_after_reply'] ?? ''));

        if ($id <= 0 || $subject === '' || $message === '') {
            $_SESSION['error'] = 'Reply subject and message are required.';
            header('Location: /admin/requests');
            exit;
        }

        $db = Database::getInstance()->getConnection();

        try {
            if ($type === 'site_request') {
                $this->assertRequestExists($type, $id);
                $stmt = $db->prepare("SELECT email FROM contact_messages WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch();

                $email = EncryptionService::decrypt((string)$row['email']);
                $mail = MailService::createFromSettings();
                $mail->send($email, $subject, $message);

                $this->updateInboxStatus($type, $id, $statusAfterReply !== '' ? $statusAfterReply : 'replied');
                $this->addRequestActivity($type, $id, 'reply', $subject, $message, [
                    'recipient' => $email,
                    'status' => $statusAfterReply !== '' ? $statusAfterReply : 'replied',
                ]);
                $_SESSION['success'] = 'Reply sent to the contact request successfully.';
            } elseif ($type === 'dmca_report') {
                $this->assertRequestExists($type, $id);
                $stmt = $db->prepare("SELECT reporter_email FROM dmca_reports WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch();

                $email = EncryptionService::decrypt((string)$row['reporter_email']);
                $mail = MailService::createFromSettings();
                $mail->send($email, $subject, $message);

                $this->updateInboxStatus($type, $id, $statusAfterReply !== '' ? $statusAfterReply : 'investigating');
                $this->addRequestActivity($type, $id, 'reply', $subject, $message, [
                    'recipient' => $email,
                    'status' => $statusAfterReply !== '' ? $statusAfterReply : 'investigating',
                ]);
                $_SESSION['success'] = 'Reply sent to the DMCA reporter successfully.';
            } else {
                throw new \RuntimeException('This request type does not support replies.');
            }
        } catch (\Throwable $e) {
            Logger::error('Admin request reply failed', [
                'request_type' => $type,
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Reply failed. Check the request details and mail settings, then try again.';
        }

        header('Location: /admin/requests');
        exit;
    }

    public function addRequestNote()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/requests');
            exit;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $type = (string)($_POST['request_type'] ?? '');
        $id = (int)($_POST['request_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));

        if ($id <= 0 || $note === '') {
            $_SESSION['error'] = 'A note is required.';
            header('Location: /admin/requests');
            exit;
        }

        try {
            $this->assertRequestExists($type, $id);
            $this->addRequestActivity($type, $id, 'note', 'Internal note', $note);
            $_SESSION['success'] = 'Internal note added.';
        } catch (\Throwable $e) {
            Logger::error('Admin request note save failed', [
                'request_type' => $type,
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Could not save that note. Please try again.';
        }

        header('Location: /admin/requests');
        exit;
    }

    public function updateRequestStatus()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /admin/requests');
            exit;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $type = (string)($_POST['request_type'] ?? '');
        $id = (int)($_POST['request_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));

        try {
            if ($id <= 0 || $status === '') {
                throw new \RuntimeException('A request and status are required.');
            }

            $this->assertRequestExists($type, $id);
            $this->updateInboxStatus($type, $id, $status);
            $this->addRequestActivity($type, $id, 'status', 'Status changed', 'Request status updated.', [
                'status' => $status,
            ]);
            $_SESSION['success'] = 'Request status updated.';
        } catch (\Throwable $e) {
            Logger::error('Admin request status update failed', [
                'request_type' => $type,
                'request_id' => $id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Status update failed. Please try again.';
        }

        header('Location: /admin/requests');
        exit;
    }

    public function dmcaReports()
    {
        $this->checkAuth();
        header("Location: /admin/requests");
        exit;
    }

    public function currentDownloadsView()
    {
        $this->checkAuth();
        View::render('admin/current_downloads.php', [
            'demoAdmin' => DemoModeService::currentViewerIsDemoAdmin(),
        ]);
    }

    public function currentDownloadsData()
    {
        $this->checkAuth();
        header('Content-Type: application/json');
        
        if (Setting::get('track_current_downloads', '0') !== '1') {
            echo json_encode([]);
            exit;
        }

        $db = Database::getInstance()->getConnection();
        // clean up stalled connections older than 2 hours just in case PHP didn't clean them up
        $db->exec("DELETE FROM active_downloads WHERE last_ping_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");

        $stmt = $db->query("
            SELECT a.file_id, a.user_id, a.ip_address, a.started_at, f.filename, f.short_id
            FROM active_downloads a
            LEFT JOIN files f ON a.file_id = f.id
            ORDER BY a.started_at DESC
        ");
        $downloads = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        foreach ($downloads as &$dl) {
            $dl['filename'] = EncryptionService::decrypt($dl['filename'] ?? '');
            $dl['ip_address'] = EncryptionService::decrypt($dl['ip_address'] ?? '');
            if ($demoAdmin) {
                $dl['ip_address'] = DemoModeService::maskIp((string)$dl['ip_address']);
            }
        }
        
        echo json_encode($downloads);
        exit;
    }

    public function serverMonitoringHistory()
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();
        $limit = (int)Setting::get('monitoring_log_limit', '50');
        $stmt = $db->prepare("SELECT l.*, s.name as server_name FROM server_monitoring_log l JOIN file_servers s ON l.server_id = s.id ORDER BY l.checked_at DESC LIMIT ?");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        View::render('admin/server_monitoring.php', ['logs' => $stmt->fetchAll()]);
    }

    public function migrateFiles()
    {
        $this->checkAuth();
        $this->ensureDemoAdminReadOnly(false, '/admin/file-servers/migrate');
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM file_servers ORDER BY id ASC");
        $servers = $stmt->fetchAll();

        $results = null;
        $migrationForm = [
            'from_server' => isset($_SESSION['migration_form']['from_server']) ? (int)$_SESSION['migration_form']['from_server'] : (int)($servers[0]['id'] ?? 0),
            'to_server' => isset($_SESSION['migration_form']['to_server']) ? (int)$_SESSION['migration_form']['to_server'] : (int)($servers[0]['id'] ?? 0),
            'batch_limit' => isset($_SESSION['migration_form']['batch_limit']) ? max(1, (int)$_SESSION['migration_form']['batch_limit']) : 50,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            $migrationForm = [
                'from_server' => (int)($_POST['from_server'] ?? 0),
                'to_server' => (int)($_POST['to_server'] ?? 0),
                'batch_limit' => max(1, (int)($_POST['batch_limit'] ?? 50)),
            ];
            $_SESSION['migration_form'] = $migrationForm;
            $service = new \App\Service\MigrationService();
            $results = $service->migrate($migrationForm['from_server'], $migrationForm['to_server'], $migrationForm['batch_limit']);
        }

        View::render('admin/file_servers/migrate.php', [
            'servers' => $servers,
            'results' => $results,
            'migrationForm' => $migrationForm,
        ]);
    }

    public function addFileServer()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/file-server/add');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            if (($_POST['type'] ?? '') === 'ftp') {
                $_SESSION['error'] = 'FTP storage is not implemented in this build.';
                header("Location: /admin/file-server/add");
                exit;
            }

            $db = Database::getInstance()->getConnection();
            $status = in_array($_POST['status'] ?? 'active', ['active', 'disabled', 'read-only'], true) ? $_POST['status'] : 'active';
            $stmt = $db->prepare("INSERT INTO file_servers (name, server_type, status, storage_path, public_url, config, max_capacity_bytes, delivery_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            try {
                $serverType = $this->normalizeFileServerType($_POST['type'] ?? 'local');
                $preset = $this->normalizeProviderPreset($_POST['provider_preset'] ?? $_POST['type'] ?? 'local', $serverType);
                $name = trim((string)($_POST['name'] ?? ''));
                $storagePath = trim((string)($_POST['path'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException('Server Friendly Name is required.');
                }
                if ($storagePath === '') {
                    throw new \RuntimeException('Storage Path or Bucket Name is required.');
                }
                if ($serverType === 'local') {
                    $storagePath = $this->validateLocalStoragePath($storagePath);
                }
                $config = $this->normalizeFileServerConfig($_POST['config'] ?? [], $preset);
                $encConfig = EncryptionService::encrypt(json_encode($config));
                $encPath = EncryptionService::encrypt($storagePath);
                $publicUrl = $this->normalizePublicUrl($_POST['url'] ?? '');
                $deliveryMethod = $this->normalizeDeliveryMethod($_POST['delivery_method'] ?? 'php');
                $stmt->execute([$name, $serverType, $status, $encPath, $publicUrl, $encConfig, max(0, (int)$_POST['capacity']), $deliveryMethod]);
                header("Location: /admin/configuration?tab=storage"); exit;
            } catch (\RuntimeException $e) {
                Logger::error('File server add failed', [
                    'provider_preset' => $preset,
                    'error' => $e->getMessage(),
                ]);
                $_SESSION['error'] = 'The storage server could not be saved. Review the form values and try again.';
                header("Location: /admin/file-server/add?tab=" . rawurlencode($preset));
                exit;
            }
        }
        View::render('admin/file_servers/add.php');
    }

    public function editFileServer(string $id)
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) die("Server not found");
        $server = $this->decryptFileServerRow($server);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/file-server/edit/' . rawurlencode($id));
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            if ($server['server_type'] === 'ftp') {
                $_SESSION['error'] = 'FTP storage is not implemented in this build.';
                header("Location: /admin/configuration?tab=storage");
                exit;
            }

            $status = in_array($_POST['status'] ?? 'active', ['active', 'disabled', 'read-only'], true) ? $_POST['status'] : 'disabled';
            $stmt = $db->prepare("UPDATE file_servers SET name = ?, status = ?, storage_path = ?, public_url = ?, config = ?, max_capacity_bytes = ?, delivery_method = ? WHERE id = ?");
            try {
                $preset = $this->normalizeProviderPreset($_POST['provider_preset'] ?? $server['server_type'], (string)$server['server_type']);
                $name = trim((string)($_POST['name'] ?? ''));
                $storagePath = trim((string)($_POST['path'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException('Server Friendly Name is required.');
                }
                if ($storagePath === '') {
                    throw new \RuntimeException('Storage Path or Bucket Name is required.');
                }
                if (($server['server_type'] ?? '') === 'local') {
                    $storagePath = $this->validateLocalStoragePath($storagePath);
                }
                $config = $this->normalizeFileServerConfig($_POST['config'] ?? [], $preset, $server['config'] ?? []);
                $encConfig = EncryptionService::encrypt(json_encode($config));
                $encPath = EncryptionService::encrypt($storagePath);
                $publicUrl = $this->normalizePublicUrl($_POST['url'] ?? '');
                $deliveryMethod = $this->normalizeDeliveryMethod($_POST['delivery_method'] ?? 'php');
                $stmt->execute([$name, $status, $encPath, $publicUrl, $encConfig, max(0, (int)$_POST['capacity']), $deliveryMethod, $id]);
                header("Location: /admin/configuration?tab=storage"); exit;
            } catch (\RuntimeException $e) {
                Logger::error('File server update failed', [
                    'server_id' => $id,
                    'provider_preset' => $preset,
                    'error' => $e->getMessage(),
                ]);
                $_SESSION['error'] = 'The storage server could not be saved. Review the form values and try again.';
                header("Location: /admin/file-server/edit/" . rawurlencode($id));
                exit;
            }
        }

        View::render('admin/file_servers/edit.php', ['server' => $server, 'config' => $server['config'] ?? []]);
    }

    public function deleteFileServer()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/configuration?tab=storage');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            
            $id = (int)$_POST['server_id'];
            $db = Database::getInstance()->getConnection();
            
            // Check if server is in use
            $stmt = $db->prepare("SELECT COUNT(*) FROM stored_files WHERE file_server_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error'] = "Cannot delete server while it still contains files. Please migrate them first.";
            } else {
                $db->prepare("DELETE FROM file_servers WHERE id = ?")->execute([$id]);
                $_SESSION['success'] = "File server deleted.";
            }
        }
        header("Location: /admin/configuration?tab=storage"); exit;
    }

    public function setDefaultFileServer()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/configuration?tab=storage');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            
            $id = (int)$_POST['server_id'];
            $db = Database::getInstance()->getConnection();
            
            $db->exec("UPDATE file_servers SET is_default = 0");
            $db->prepare("UPDATE file_servers SET is_default = 1 WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = "Default storage server updated.";
        }
        header("Location: /admin/configuration?tab=storage"); exit;
    }

    public function testFileServerDelivery(string $id)
    {
        $this->checkAuth();
        $this->ensureDemoAdminReadOnly(false, '/admin/file-server/edit/' . rawurlencode($id));
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) die("Server not found");
        $server = $this->decryptFileServerRow($server);

        $content = "Fyuhls Server Delivery Test\nTimestamp: " . date('Y-m-d H:i:s') . "\nServer: " . $server['name'] . "\nMethod: " . $server['delivery_method'];
        $testPath = '__fyuhls_test/fyuhls_test.txt';
        $tmpPath = tempnam(sys_get_temp_dir(), 'fy_srv_');
        if ($tmpPath === false) {
            http_response_code(500);
            exit('Failed to allocate test file.');
        }
        file_put_contents($tmpPath, $content);

        $provider = \App\Service\Storage\ServerProviderFactory::make($server);
        $provider->delete($testPath);
        if (!$provider->save($tmpPath, $testPath)) {
            @unlink($tmpPath);
            http_response_code(500);
            exit('Failed to write the delivery test file to the selected storage server.');
        }
        
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="fyuhls_test.txt"');
        header('Content-Length: ' . strlen($content));
        
        // Disable caching
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $method = $server['delivery_method'] ?? 'php';
        $path = $provider->getAbsolutePath($testPath);
        $looksLikeFilesystemPath = preg_match('/^[A-Za-z]:[\\\\\\/]|^\/|^\\\\\\\\/', (string)$path) === 1;

        if ($method === 'nginx') {
            $safePath = preg_replace('/[^a-zA-Z0-9\/\._-]/', '', $testPath);
            header('X-Accel-Redirect: /protected_uploads/' . $safePath);
            exit;
        }

        if ($method === 'apache') {
            if (!$looksLikeFilesystemPath) {
                http_response_code(422);
                exit('Apache handoff delivery tests require a filesystem-backed storage path. This server currently resolves to an object-storage key instead of a local file path, so this test cannot safely verify X-SendFile.');
            }
            header('X-SendFile: ' . $path);
            exit;
        }

        if ($method === 'litespeed') {
            if (!$looksLikeFilesystemPath) {
                http_response_code(422);
                exit('LiteSpeed handoff delivery tests require a filesystem-backed storage path. This server currently resolves to an object-storage key instead of a local file path, so this test cannot safely verify X-LiteSpeed-Location.');
            }
            header('X-LiteSpeed-Location: ' . $path);
            exit;
        }

        $provider->stream($testPath);
        exit;
    }

    public function testFileServerConnection()
    {
        $this->checkAuth();
        $this->ensureDemoAdminReadOnly(true);
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'CSRF Token Mismatch']);
            return;
        }
        header('Content-Type: application/json');

        $id = (int)($_POST['server_id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid server ID']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            echo json_encode(['success' => false, 'message' => 'Server not found']);
            return;
        }

        try {
            $provider = \App\Service\Storage\ServerProviderFactory::make($server);
            $result = $provider->testConnection();
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Connection successful!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Connection failed. Check your configuration and permissions.']);
            }
        } catch (\Exception $e) {
            Logger::error('File server connection test failed', [
                'server_id' => $id,
                'error' => $e->getMessage(),
            ]);
            echo json_encode(['success' => false, 'message' => 'Connection test failed. Check the server configuration and logs.']);
        }
    }

    public function discoverBackblazeBuckets(): void
    {
        $this->checkAuth();
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $service = new \App\Service\BackblazeB2Service();
            $result = $service->discoverBuckets(
                (string)($_POST['key_id'] ?? ''),
                (string)($_POST['application_key'] ?? '')
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'Backblaze buckets loaded successfully.',
                'account_id' => $result['account_id'],
                'region' => $result['region'],
                'endpoint' => $result['endpoint'],
                'buckets' => $result['buckets'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Backblaze bucket discovery failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'Backblaze bucket discovery failed. Check the credentials and logs.'], 422);
        }
    }

    public function applyBackblazeCors(): void
    {
        $this->checkAuth();
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $origins = $this->getStorageAutomationOrigins();
            $service = new \App\Service\BackblazeB2Service();
            $result = $service->applyFyuhlsCors(
                (string)($_POST['key_id'] ?? ''),
                (string)($_POST['application_key'] ?? ''),
                trim((string)($_POST['bucket_name'] ?? '')),
                $origins
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'The recommended Fyuhls CORS rule was applied to the selected B2 bucket.',
                'origin' => $result['applied_origin'],
                'origins' => $result['applied_origins'] ?? [$result['applied_origin']],
                'bucket_name' => $result['bucket_name'],
                'bucket_type' => $result['bucket_type'],
                'cors_rule_count' => $result['cors_rule_count'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Backblaze CORS apply failed', [
                'bucket_name' => trim((string)($_POST['bucket_name'] ?? '')),
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'The CORS update failed. Check the bucket settings and logs.'], 422);
        }
    }

    public function discoverWasabiBuckets(): void
    {
        $this->checkAuth();
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $service = new \App\Service\WasabiService();
            $result = $service->discoverBuckets(
                (string)($_POST['access_key'] ?? ''),
                (string)($_POST['secret_key'] ?? ''),
                trim((string)($_POST['region'] ?? 'us-east-1')),
                trim((string)($_POST['endpoint'] ?? ''))
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'Wasabi buckets loaded successfully.',
                'region' => $result['region'],
                'endpoint' => $result['endpoint'],
                'buckets' => $result['buckets'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Wasabi bucket discovery failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'Wasabi bucket discovery failed. Check the credentials, region, endpoint, and logs.'], 422);
        }
    }

    public function applyWasabiCors(): void
    {
        $this->checkAuth();
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $origins = $this->getStorageAutomationOrigins();
            $service = new \App\Service\WasabiService();
            $result = $service->applyFyuhlsCors(
                (string)($_POST['access_key'] ?? ''),
                (string)($_POST['secret_key'] ?? ''),
                trim((string)($_POST['bucket_name'] ?? '')),
                $origins,
                trim((string)($_POST['region'] ?? 'us-east-1')),
                trim((string)($_POST['endpoint'] ?? ''))
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'The recommended Fyuhls CORS rule was applied to the selected Wasabi bucket.',
                'origin' => $result['applied_origin'],
                'origins' => $result['applied_origins'] ?? [$result['applied_origin']],
                'bucket_name' => $result['bucket_name'],
                'cors_rule_count' => $result['cors_rule_count'],
                'region' => $result['region'],
                'endpoint' => $result['endpoint'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Wasabi CORS apply failed', [
                'bucket_name' => trim((string)($_POST['bucket_name'] ?? '')),
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'The Wasabi CORS update failed. Check the bucket settings and logs.'], 422);
        }
    }

    public function packages()
    {
        $this->checkAuth();
        View::render('admin/packages/index.php', ['packages' => Package::getAll()]);
    }

    private function clampPackageInt($value, int $min = 0, ?int $max = null): int
    {
        $value = (int)$value;
        if ($value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }
        return $value;
    }

    private function clampPackageFloat($value, float $min = 0.0, ?float $max = null): float
    {
        $value = (float)$value;
        if ($value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }
        return $value;
    }

    public function editPackage(string $id)
    {
        $this->checkAuth();
        $package = Package::find((int)$id);

        if (!$package)
            die("Package not found");

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $_SESSION['error'] = 'Package name is required.';
                header("Location: /admin/package/edit/" . rawurlencode((string)$package['id']));
                exit;
            }

            $data = [
                'name' => $name,
                'price' => number_format($this->clampPackageFloat($_POST['price'] ?? 0, 0.0), 2, '.', ''),
                'max_storage_bytes' => $this->clampPackageInt($_POST['max_storage_bytes'] ?? 0, 0),
                'max_upload_size' => $this->clampPackageInt($_POST['max_upload_size'] ?? 0, 0),
                'max_daily_downloads' => $this->clampPackageInt($_POST['max_daily_downloads'] ?? 0, 0),
                'download_speed' => $this->clampPackageInt($_POST['download_speed'] ?? 0, 0),
                'wait_time' => $this->clampPackageInt($_POST['wait_time'] ?? 0, 0),
                'file_expiry_days' => $this->clampPackageInt($_POST['file_expiry_days'] ?? 0, 0),
                'show_ads' => isset($_POST['show_ads']) ? 1 : 0,
                'allow_direct_links' => isset($_POST['allow_direct_links']) ? 1 : 0,
                'allow_remote_upload' => isset($_POST['allow_remote_upload']) ? 1 : 0,
                'wait_time_enabled' => isset($_POST['wait_time_enabled']) ? 1 : 0,
                'concurrent_uploads' => max(1, $this->clampPackageInt($_POST['concurrent_uploads'] ?? 1, 0)),
                'concurrent_downloads' => $this->clampPackageInt($_POST['concurrent_downloads'] ?? 1, 0),
            ];

            if (($data['concurrent_downloads'] ?? 0) > 0) {
                Setting::set('track_current_downloads', '1', 'downloads');
            }
            Package::update($package['id'], $data);
            header("Location: /admin/packages");
            exit;
        }

        View::render('admin/packages/edit.php', ['package' => $package]);
    }

    private function decryptFileServerRow(array $server): array
    {
        if (!empty($server['storage_path'])) {
            $server['storage_path'] = EncryptionService::decrypt($server['storage_path']);
        }

        if (!empty($server['config'])) {
            $dec = EncryptionService::decrypt($server['config']);
            $server['config'] = json_decode($dec, true) ?? json_decode($server['config'], true) ?? [];
        } else {
            $server['config'] = [];
        }

        return $server;
    }

    private function normalizeFileServerConfig(array $postedConfig, string $preset, array $existingConfig = []): array
    {
        $config = $postedConfig;
        $config['provider_preset'] = $preset;

        foreach (['s3_key', 's3_secret'] as $secretKey) {
            $postedValue = trim((string)($config[$secretKey] ?? ''));
            if ($postedValue === '' && isset($existingConfig[$secretKey])) {
                $config[$secretKey] = $existingConfig[$secretKey];
            }
        }

        if ($preset === 'b2') {
            $region = trim((string)($config['s3_region'] ?? 'us-west-004'));
            $endpointInput = trim((string)($config['s3_endpoint'] ?? ''));
            $endpointHost = '';

            if ($endpointInput !== '') {
                $normalized = $this->normalizeEndpointUrl($endpointInput, 'B2 endpoint');
                $endpointHost = $normalized['host'];
            }
            if ($endpointHost !== '' && preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/i', $endpointHost, $matches)) {
                $region = strtolower($matches[1]);
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://' . $endpointHost;
            } else {
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://s3.' . $region . '.backblazeb2.com';
            }
        } elseif ($preset === 'wasabi') {
            $region = trim((string)($config['s3_region'] ?? 'us-east-1'));
            $endpointInput = trim((string)($config['s3_endpoint'] ?? ''));
            $endpointHost = '';

            if ($endpointInput !== '') {
                $normalized = $this->normalizeEndpointUrl($endpointInput, 'Wasabi endpoint');
                $endpointHost = $normalized['host'];
            }

            if ($endpointHost !== '' && preg_match('/(?:^|\.)(s3\.([a-z0-9-]+)\.wasabisys\.com)$/i', $endpointHost, $matches)) {
                $region = strtolower($matches[2]);
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://' . strtolower($matches[1]);
            } else {
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://s3.' . $region . '.wasabisys.com';
            }
        } elseif ($preset === 'r2') {
            $config['s3_region'] = 'auto';
            $accountId = trim((string)($config['s3_endpoint'] ?? ''));
            if (!preg_match('/^[a-f0-9]{32}$/i', $accountId)) {
                throw new \RuntimeException('Cloudflare Account ID must be a 32-character hexadecimal string.');
            }
            $config['s3_endpoint'] = strtolower($accountId);
        } elseif ($preset === 's3') {
            $normalized = $this->normalizeEndpointUrl((string)($config['s3_endpoint'] ?? ''), 'Endpoint URL');
            $config['s3_endpoint'] = $normalized['url'];
        }

        return $config;
    }

    public function status()
    {
        $this->checkAuth();
        
        // Initialize default values to prevent view warnings
        $writable = 'unknown';
        $blocked = 0;
        $logs = [];
        $errorsOnly = [];
        
        $hostService = new \App\Service\HostService();
        $logFile = dirname(__DIR__, 3) . '/storage/logs/app.log';
        if (file_exists($logFile)) {
            $lines = @file($logFile);
            $logs = array_slice($lines ?? [], -50);
        }
        clearstatcache(true, $logFile);
        $logSizeBytes = file_exists($logFile) ? (int)filesize($logFile) : 0;
        
        $metrics = $hostService->getMetrics();
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $uploadStorageRoot = dirname(__DIR__, 3) . '/storage/uploads';
        $writable = is_dir($uploadStorageRoot) && is_writable($uploadStorageRoot) ? 'ok' : 'not writable';
        $gdOk = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
        $ffmpegPath = Setting::getOrConfig('video.ffmpeg_path', Config::get('video.ffmpeg_path', ''));
        $ffmpegEnabled = Setting::getOrConfig('video.ffmpeg_enabled', '1');
        $ffmpegOk = $ffmpegEnabled === '1' && !empty($ffmpegPath) && file_exists($ffmpegPath);
        $updater = new UpdateService();
        $updateStatus = $updater->getStatus(isset($_GET['refresh_update']) && $_GET['refresh_update'] === '1');
        
        $limit = Config::get('security.rate_limit.download.limit', 5);
        $window = (int)Config::get('security.rate_limit.download.window', 600);
        $currentWindow = floor(time() / $window) * $window;
        $db = Database::getInstance()->getConnection();
        
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM download_limits WHERE window_start = ? AND attempt_count > ?");
            $stmt->execute([$currentWindow, $limit]);
            $blocked = (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        $uploadStats = [
            'active_sessions' => 0,
            'stale_sessions' => 0,
            'failed_sessions' => 0,
            'active_reservations' => 0,
            'reserved_bytes' => 0,
            'stuck_completing' => 0,
            'checksum_backlog' => 0,
            'expired_reservations' => 0,
        ];

        $deliveryStats = [
            'public_object_files' => 0,
            'private_object_files' => 0,
            'local_files' => 0,
            'cdn_eligible_files' => 0,
            'signed_origin_files' => 0,
            'app_controlled_files' => 0,
            'cdn_enabled' => Setting::get('cdn_download_redirects_enabled', '0') === '1',
            'cdn_base_configured' => trim(Setting::get('cdn_download_base_url', '')) !== '',
            'ppd_progress_tracking' => (int)Setting::get('ppd_min_download_percent', '0'),
        ];

        try {
            $stmt = $db->query("
                SELECT
                    SUM(CASE WHEN status IN ('pending', 'uploading', 'completing', 'processing') THEN 1 ELSE 0 END) AS active_sessions,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_sessions,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() AND status IN ('pending', 'uploading', 'completing', 'processing') THEN 1 ELSE 0 END) AS stale_sessions,
                    SUM(CASE WHEN status = 'completing' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) AS stuck_completing
                FROM upload_sessions
            ");
            $row = $stmt->fetch() ?: [];
            $uploadStats['active_sessions'] = (int)($row['active_sessions'] ?? 0);
            $uploadStats['failed_sessions'] = (int)($row['failed_sessions'] ?? 0);
            $uploadStats['stale_sessions'] = (int)($row['stale_sessions'] ?? 0);
            $uploadStats['stuck_completing'] = (int)($row['stuck_completing'] ?? 0);
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT
                    COUNT(*) AS active_reservations,
                    COALESCE(SUM(reserved_bytes), 0) AS reserved_bytes
                FROM quota_reservations
                WHERE status = 'active'
            ");
            $row = $stmt->fetch() ?: [];
            $uploadStats['active_reservations'] = (int)($row['active_reservations'] ?? 0);
            $uploadStats['reserved_bytes'] = (int)($row['reserved_bytes'] ?? 0);
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT COUNT(*)
                FROM quota_reservations
                WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()
            ");
            $uploadStats['expired_reservations'] = (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT COUNT(*)
                FROM stored_files
                WHERE file_hash IS NOT NULL
                  AND (checksum_verified_at IS NULL OR checksum_verified_at = '0000-00-00 00:00:00')
            ");
            $uploadStats['checksum_backlog'] = (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT
                    SUM(CASE WHEN sf.storage_provider <> 'local' AND f.is_public = 1 THEN 1 ELSE 0 END) AS public_object_files,
                    SUM(CASE WHEN sf.storage_provider <> 'local' AND f.is_public = 0 THEN 1 ELSE 0 END) AS private_object_files,
                    SUM(CASE WHEN sf.storage_provider = 'local' THEN 1 ELSE 0 END) AS local_files
                FROM files f
                JOIN stored_files sf ON sf.id = f.stored_file_id
                WHERE f.status IN ('active', 'ready', 'processing', 'hidden')
            ");
            $row = $stmt->fetch() ?: [];
            $deliveryStats['public_object_files'] = (int)($row['public_object_files'] ?? 0);
            $deliveryStats['private_object_files'] = (int)($row['private_object_files'] ?? 0);
            $deliveryStats['local_files'] = (int)($row['local_files'] ?? 0);

            $objectFiles = $deliveryStats['public_object_files'] + $deliveryStats['private_object_files'];
            if ($deliveryStats['ppd_progress_tracking'] > 0) {
                $deliveryStats['app_controlled_files'] = $objectFiles + $deliveryStats['local_files'];
            } else {
                $deliveryStats['cdn_eligible_files'] = ($deliveryStats['cdn_enabled'] && $deliveryStats['cdn_base_configured'])
                    ? $deliveryStats['public_object_files']
                    : 0;
                $deliveryStats['signed_origin_files'] = max(0, $objectFiles - $deliveryStats['cdn_eligible_files']);
                $deliveryStats['app_controlled_files'] = $deliveryStats['local_files'];
            }
        } catch (\PDOException $e) {
            // Tables might not exist yet
        }

        $recentUploadSessions = [];
        try {
            $stmt = $db->query("
                SELECT
                    us.public_id,
                    us.user_id,
                    us.original_filename,
                    us.storage_provider,
                    us.expected_size,
                    us.uploaded_bytes,
                    us.completed_parts,
                    us.status,
                    us.error_message,
                    us.expires_at,
                    us.created_at,
                    us.updated_at,
                    u.username
                FROM upload_sessions us
                LEFT JOIN users u ON u.id = us.user_id
                ORDER BY us.id DESC
                LIMIT 25
            ");
            $recentUploadSessions = $stmt->fetchAll() ?: [];
            foreach ($recentUploadSessions as &$session) {
                if (!empty($session['username']) && str_starts_with((string)$session['username'], 'ENC:')) {
                    $session['username'] = EncryptionService::decrypt($session['username']);
                }
                if (!empty($session['original_filename']) && str_starts_with((string)$session['original_filename'], 'ENC:')) {
                    $session['original_filename'] = EncryptionService::decrypt($session['original_filename']);
                }
            }
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        $recentReservations = [];
        try {
            $stmt = $db->query("
                SELECT
                    qr.public_id,
                    qr.user_id,
                    qr.upload_session_id,
                    qr.storage_server_id,
                    qr.reserved_bytes,
                    qr.status,
                    qr.expires_at,
                    qr.created_at,
                    u.username,
                    us.public_id AS upload_public_id
                FROM quota_reservations qr
                LEFT JOIN users u ON u.id = qr.user_id
                LEFT JOIN upload_sessions us ON us.id = qr.upload_session_id
                ORDER BY qr.id DESC
                LIMIT 25
            ");
            $recentReservations = $stmt->fetchAll() ?: [];
            foreach ($recentReservations as &$reservation) {
                if (!empty($reservation['username']) && str_starts_with((string)$reservation['username'], 'ENC:')) {
                    $reservation['username'] = EncryptionService::decrypt($reservation['username']);
                }
            }
        } catch (\PDOException $e) {
            // Table might not exist yet
        }
        
        $formattedLogs = [];
        foreach ($logs as $line) {
            $formattedLogs[] = $this->formatApplicationLogLine($line);
        }

        if ($demoAdmin) {
            foreach ($formattedLogs as &$entry) {
                $entry['context'] = DemoModeService::redactContext((array)($entry['context'] ?? []));
                $entry['message'] = DemoModeService::redactTextContent((string)($entry['message'] ?? ''));
                $entry['raw'] = DemoModeService::hiddenLabel();
            }
            unset($entry);
        }

        foreach (array_reverse($logs) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                // Decrypt context if present
                if (isset($decoded['ctx'])) {
                    foreach ($decoded['ctx'] as $key => &$val) {
                        if (is_string($val) && str_contains($val, 'ENC:')) {
                            $val = EncryptionService::decrypt($val);
                        }
                    }
                    $line = json_encode($decoded);
                }

                if (($decoded['level'] ?? '') === 'error') {
                    $errorsOnly[] = $demoAdmin ? DemoModeService::redactTextContent($line) : $line;
                    if (count($errorsOnly) >= 20)
                        break;
                }
            }
        }
        View::render('admin/status.php', [
            'writable' => $writable,
            'gdOk' => $gdOk,
            'ffmpegOk' => $ffmpegOk,
            'blocked' => $blocked,
            'errors' => $errorsOnly,
            'logs' => $logs,
            'formattedLogs' => $formattedLogs,
            'metrics' => $metrics,
            'supportEmail' => $demoAdmin ? DemoModeService::hiddenLabel() : DiagnosticsService::SUPPORT_EMAIL,
            'smtpConfigured' => $this->isSupportEmailAvailable(),
            'updateStatus' => $updateStatus,
            'uploadStats' => $uploadStats,
            'deliveryStats' => $deliveryStats,
            'recentUploadSessions' => $recentUploadSessions,
            'recentReservations' => $recentReservations,
            'demoAdmin' => $demoAdmin,
            'logSizeBytes' => $logSizeBytes,
            'logSizeReadable' => $this->formatReadableBytes($logSizeBytes),
            'logMaxBytes' => 26214400,
            'logMaxReadable' => $this->formatReadableBytes(26214400),
        ]);
    }

    private function formatApplicationLogLine(string $line): array
    {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return [
                'timestamp' => '',
                'level' => 'raw',
                'message' => trim($line),
                'context' => [],
                'raw' => trim($line),
            ];
        }

        $context = [];
        if (isset($decoded['ctx']) && is_array($decoded['ctx'])) {
            foreach ($decoded['ctx'] as $key => $val) {
                if (is_string($val) && str_contains($val, 'ENC:')) {
                    $val = EncryptionService::decrypt($val);
                }
                $context[$key] = $val;
            }
        }

        return [
            'timestamp' => (string)($decoded['ts'] ?? ''),
            'level' => (string)($decoded['level'] ?? 'info'),
            'message' => (string)($decoded['msg'] ?? trim($line)),
            'context' => $context,
            'raw' => trim($line),
        ];
    }

    public function abortUploadSession(): void
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $publicId = (string)($_POST['session_id'] ?? '');
        if ($publicId === '') {
            $_SESSION['error'] = 'Missing upload session ID.';
            header('Location: /admin/status');
            exit;
        }

        try {
            $session = \App\Model\UploadSession::findByPublicId($publicId);
            if (!$session) {
                throw new \RuntimeException('Upload session not found.');
            }

            (new \App\Service\MultipartUploadService())->abort($session);
            $_SESSION['success'] = 'Upload session aborted: ' . $publicId;
        } catch (\Throwable $e) {
            Logger::error('Admin upload session abort failed', [
                'session_id' => $publicId,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Could not abort the upload session. Check the logs and try again.';
        }

        header('Location: /admin/status');
        exit;
    }

    public function applyUpdate(): void
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        try {
            $result = (new UpdateService())->applyLatestRelease();
            $_SESSION['success'] = sprintf(
                'Updated from %s to %s. Refreshed %d files and created %d directories.',
                $result['from_version'],
                $result['to_version'],
                $result['files_copied'],
                $result['directories_created']
            );
        } catch (\Throwable $e) {
            Logger::error('Admin update apply failed', [
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Update failed. Check the application logs for details.';
        }

        header('Location: /admin/status');
        exit;
    }

    public function documentation() { $this->checkAuth(); View::render('admin/docs.php'); }
    public function supportUs()
    {
        $this->checkAuth();
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();

        $issueDescription = $_SESSION['support_issue_description'] ?? '';
        unset($_SESSION['support_issue_description']);
        $bundle = [];
        $preview = '{}';
        if (!$demoAdmin) {
            $service = new DiagnosticsService();
            $bundle = $service->generateSupportBundle([
                'issue_description' => $issueDescription,
            ]);
            $preview = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        View::render('admin/support.php', [
            'supportBundle' => $bundle,
            'issueDescription' => $issueDescription,
            'supportEmail' => $demoAdmin ? DemoModeService::hiddenLabel() : DiagnosticsService::SUPPORT_EMAIL,
            'smtpConfigured' => $this->isSupportEmailAvailable(),
            'supportJsonPreview' => $preview,
            'demoAdmin' => $demoAdmin,
        ]);
    }

    public function downloadSupportBundle(): void
    {
        $this->checkAuth();
        if (DemoModeService::currentViewerIsDemoAdmin()) {
            $_SESSION['error'] = 'Support bundle export is hidden for the demo admin account.';
            header('Location: /admin/support');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $bundle = $this->buildSupportBundleFromRequest();
        $token = $bundle['metadata']['support_token'] ?? ('support_' . date('Ymd_His'));

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $token . '.json"');
        echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function emailSupportBundle(): void
    {
        $this->checkAuth();
        if (DemoModeService::currentViewerIsDemoAdmin()) {
            $_SESSION['error'] = 'Support bundle email is hidden for the demo admin account.';
            header('Location: /admin/support');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $_SESSION['support_issue_description'] = trim((string)($_POST['issue_description'] ?? ''));

        if (($_POST['approve_data_share'] ?? '') !== '1') {
            $_SESSION['error'] = 'Please confirm that you reviewed and approve sending the sanitized bundle.';
            header('Location: /admin/support');
            exit;
        }

        if (!$this->isSupportEmailAvailable()) {
            $_SESSION['error'] = 'SMTP is not configured. Download the support bundle and send it manually instead.';
            header('Location: /admin/support');
            exit;
        }

        $bundle = $this->buildSupportBundleFromRequest();
        $service = new DiagnosticsService();
        $token = $bundle['metadata']['support_token'] ?? 'support_bundle';

        try {
            $mail = MailService::createFromSettings();
            $mail->send(
                DiagnosticsService::SUPPORT_EMAIL,
                'Fyuhls Support Bundle ' . $token,
                $service->generateSupportEmailBody($bundle),
                [[
                    'filename' => $token . '.json',
                    'content' => json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'content_type' => 'application/json',
                ]]
            );
            $_SESSION['success'] = 'Sanitized support bundle emailed to ' . DiagnosticsService::SUPPORT_EMAIL . '.';
        } catch (\Throwable $e) {
            Logger::error('Support bundle email failed', [
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Support email failed. Check the mail settings and logs, then try again.';
        }

        header('Location: /admin/support');
        exit;
    }

    private function buildSupportBundleFromRequest(): array
    {
        $service = new DiagnosticsService();

        return $service->generateSupportBundle([
            'issue_description' => trim((string)($_POST['issue_description'] ?? '')),
        ]);
    }

    private function isSupportEmailAvailable(): bool
    {
        return trim(Setting::get('email_smtp_host', '')) !== '' && trim(Setting::get('email_from_address', '')) !== '';
    }
}
