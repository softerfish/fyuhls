<?php

namespace App\Controller\Admin;

use PDOException;
use App\Core\Auth;
use App\Core\View;
use App\Core\Csrf;
use App\Core\Logger;
use App\Model\Setting;
use App\Core\Database;
use App\Service\DemoModeService;
use App\Service\Migration\EncryptionMigrationService;
use App\Service\SecurityService;

/**
 * ConfigurationController - Enterprise Unified Settings Hub
 * 
 * Consolidates Site, Security, Email, Ads, Cron, and File Server management.
 * Designed for high-stability and zero-downtime during configuration updates.
 */
class ConfigurationController
{
    private array $allowedTabs = ['general', 'security', 'email', 'storage', 'monetization', 'seo', 'cron', 'downloads', 'uploads'];
    private const MAX_CUSTOM_HEAD_CODE_LENGTH = 20000;
    private const MAX_AD_CODE_LENGTH = 20000;
    private const ALLOWED_AD_SLOT_KEYS = [
        'download_top',
        'download_bottom',
        'download_left',
        'download_right',
        'download_overlay',
    ];

    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    private function ensureDemoAdminReadOnly(bool $json = false): void
    {
        if (!DemoModeService::currentViewerIsDemoAdmin()) {
            return;
        }

        $message = 'This demo admin account is read-only while demo mode is enabled.';

        if ($json) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $message]);
            exit;
        }

        $_SESSION['config_errors'] = [$message];
        header('Location: /admin/configuration');
        exit;
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
            throw new \RuntimeException('SMTP host cannot point at localhost or loopback hosts.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && $this->isPrivateOrReservedIp($host)) {
            throw new \RuntimeException('SMTP host cannot use private, loopback, or reserved IP addresses.');
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
                throw new \RuntimeException('SMTP host cannot resolve to private, loopback, or reserved IP addresses.');
            }
        }
    }

    private function normalizeSmtpHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            throw new \RuntimeException('SMTP host is required.');
        }

        if (preg_match('#^[a-z]+://#i', $host) === 1) {
            throw new \RuntimeException('SMTP host should be a hostname or IP only, without http:// or https://.');
        }
        if (preg_match('/[\/?#]/', $host) === 1) {
            throw new \RuntimeException('SMTP host should not include a path, query string, or fragment.');
        }

        $hostOnly = $host;
        if (preg_match('/^\[(.+)\]$/', $hostOnly, $matches) === 1) {
            $hostOnly = $matches[1];
        }

        $hostPortParts = explode(':', $hostOnly);
        if (count($hostPortParts) > 2 && filter_var($hostOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \RuntimeException('SMTP host should not include a port. Use the SMTP port field instead.');
        }
        if (count($hostPortParts) === 2 && filter_var($hostOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \RuntimeException('SMTP host should not include a port. Use the SMTP port field instead.');
        }

        $this->validateResolvableHostSafety($hostOnly);
        return $hostOnly;
    }

    private function normalizeSmtpPort($port): int
    {
        $port = (int)$port;
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('SMTP port must be between 1 and 65535.');
        }
        return $port;
    }

    private function normalizeEmailSecureMethod(?string $method): string
    {
        $method = strtolower(trim((string)$method));
        return in_array($method, ['none', 'ssl', 'tls'], true) ? $method : 'none';
    }

    private function normalizeCdnDownloadBaseUrl(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('CDN download base URL must be a valid absolute URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if ($scheme !== 'https' || $host === '') {
            throw new \RuntimeException('CDN download base URL must use HTTPS and include a valid host.');
        }

        if (!empty($parts['user']) || !empty($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('CDN download base URL cannot include credentials, a query string, or a fragment.');
        }

        return rtrim($url, '/');
    }

    private function normalizeNginxCompletionLogPath(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }

        $isUnixAbsolute = str_starts_with($path, '/');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
        if (!$isUnixAbsolute && !$isWindowsAbsolute) {
            throw new \RuntimeException('Nginx completion log path must be an absolute path.');
        }

        if (preg_match('/[\x00-\x1F]/', $path) === 1) {
            throw new \RuntimeException('Nginx completion log path contains invalid control characters.');
        }

        if (preg_match('/(^|[\\\\\\/])\.\.([\\\\\\/]|$)/', $path) === 1) {
            throw new \RuntimeException('Nginx completion log path cannot contain parent-directory traversal.');
        }

        $normalized = str_replace('\\', '/', $path);
        $basename = strtolower((string)pathinfo($normalized, PATHINFO_BASENAME));
        $extension = strtolower((string)pathinfo($normalized, PATHINFO_EXTENSION));
        $looksLikeLogFile = in_array($extension, ['log', 'txt'], true)
            || str_contains($basename, 'access')
            || str_contains($basename, 'download');

        if (!$looksLikeLogFile) {
            throw new \RuntimeException('Nginx completion log path must point to a plausible log file such as *.log, *.txt, or an access/download log name.');
        }

        return $path;
    }

    public function index()
    {
        Auth::requireAdmin();
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();

        $activeTab = $_GET['tab'] ?? 'general';
        if (!in_array($activeTab, $this->allowedTabs)) {
            $activeTab = 'general';
        }

        // Prepare base data
        $data = [
            'activeTab' => $activeTab,
            'saved' => $_SESSION['config_success'] ?? false,
            'errors' => $_SESSION['config_errors'] ?? [],
            'demoAdmin' => $demoAdmin,
        ];
        $data = array_merge($data, $this->getConfigurationNoticeData());
        unset($_SESSION['config_success'], $_SESSION['config_errors']);

        // Lazy Load tab-specific data
        switch ($activeTab) {
            case 'general':
                $data = array_merge($data, $this->getGeneralData());
                break;
            case 'security':
                $data = array_merge($data, $this->getSecurityData());
                break;
            case 'email':
                $data = array_merge($data, $this->getEmailData());
                break;
            case 'storage':
                $data = array_merge($data, $this->getStorageData());
                break;
            case 'monetization':
                $data = array_merge($data, $this->getMonetizationData());
                break;
            case 'seo':
                $data = array_merge($data, $this->getSeoData());
                break;
            case 'cron':
                $data = array_merge($data, $this->getCronData());
                break;
            case 'downloads':
                $data = array_merge($data, $this->getDownloadData());
                break;
            case 'uploads':
                $data = array_merge($data, $this->getUploadData());
                break;
        }

        View::render('admin/configuration/hub.php', $data);
    }

    private function getConfigurationNoticeData(): array
    {
        $migrationService = new EncryptionMigrationService();
        $pendingEncryption = $migrationService->getPendingCount();

        $currentKey = \App\Core\Config::get('security.encryption_key', '');
        $decodedKey = base64_decode($currentKey, true);
        $isBase64 = ($decodedKey !== false && strlen($decodedKey) === 32);
        $isHex = (ctype_xdigit($currentKey) && strlen($currentKey) === 32);
        $keyNeedsAttention = !$isBase64;
        $dbDriftDetected = Setting::get('db_drift_detected', '0') === '1';

        $securityNoticeCounts = [
            'keys' => $keyNeedsAttention ? 1 : 0,
            'migration' => $pendingEncryption > 0 ? 1 : 0,
            'health' => $dbDriftDetected ? 1 : 0,
        ];

        return [
            'pendingEncryption' => $pendingEncryption,
            'securityNoticeCounts' => $securityNoticeCounts,
            'securityNoticeCount' => array_sum($securityNoticeCounts),
            'securityKeyNeedsAttention' => $keyNeedsAttention,
            'securityKeyStatus' => $isBase64 ? 'enterprise' : ($isHex ? 'legacy' : 'weak'),
            'securityDbDriftDetected' => $dbDriftDetected,
        ];
    }

    private function getGeneralData(): array
    {
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        return [
            'appName' => Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
            'allowRegistrations' => Setting::get('allow_registrations', '1'),
            'demoMode' => Setting::get('demo_mode', '0'),
            'maintenanceMode' => Setting::get('maintenance_mode', '0'),
            'requireEmailVer' => Setting::get('require_email_verification', '0'),
            'reservedUsernames' => Setting::get('reserved_usernames', 'administrator,admin,support'),
            'adminEmail' => $demoAdmin ? '' : Setting::get('admin_notification_email', ''),
            'showPoweredBy' => Setting::get('show_powered_by_footer', '1'),
            'ffmpegEnabled' => Setting::getOrConfig('video.ffmpeg_enabled', '1'),
            'ffmpegPath' => $demoAdmin ? '' : Setting::getOrConfig('video.ffmpeg_path', \App\Core\Config::get('video.ffmpeg_path', '')),
        ];
    }

    private function getSecurityData(): array
    {
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $migrationService = new EncryptionMigrationService();
        
        $captchaKeys = ['captcha_download_guest','captcha_download_free','captcha_report_file','captcha_contact','captcha_dmca','captcha_register','captcha_user_login','captcha_admin_login'];
        $captchaPlacements = [];
        foreach ($captchaKeys as $ck) {
            $captchaPlacements[$ck] = Setting::get($ck, '0');
        }

        return [
            'migrationService' => $migrationService,
            'pendingEncryption' => $migrationService->getPendingCount(),
            'blockVpnTraffic' => Setting::get('block_vpn_traffic', '0') === '1',
            'vpnProtectionMode' => Setting::get('vpn_proxy_mode', 'enforcement'),
            'proxycheckApiKey' => $demoAdmin ? '' : Setting::getEncrypted('proxycheck_api_key', ''),
            'vpnWhitelist' => Setting::get('vpn_whitelist', ''),
            'rateLimitLogin' => (int)Setting::get('rate_limit_login', '5'),
            'rateLimitReg' => (int)Setting::get('rate_limit_registration', '5'),
            'trustCloudflare' => Setting::get('trust_cloudflare', '1') === '1',
            'captchaSiteKey' => Setting::get('captcha_site_key', ''),
            'captchaSecretKey' => $demoAdmin ? '' : Setting::getEncrypted('captcha_secret_key', ''),
            'captchaPlacements' => $captchaPlacements,
            'twoFactorEnabled' => \App\Service\FeatureService::twoFactorEnabled(),
            'twoFactorEnforceDate' => Setting::get('2fa_enforce_date', '', 'security'),
            'securityDbDriftDetected' => Setting::get('db_drift_detected', '0') === '1',
        ]; 
    }

    private function getEmailData(): array
    {
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        return [
            'emailSmtpHost' => $demoAdmin ? '' : Setting::get('email_smtp_host', ''),
            'emailSmtpPort' => Setting::get('email_smtp_port', '25'),
            'emailFromAddress' => $demoAdmin ? '' : Setting::get('email_from_address', ''),
            'emailSecureMethod' => Setting::get('email_secure_method', 'none'),
            'emailSmtpRequiresAuth' => Setting::get('email_smtp_requires_auth', '0') === '1',
            'emailSmtpAuthUsername' => $demoAdmin ? '' : Setting::get('email_smtp_auth_username', ''),
            'emailLimitPerMinute' => Setting::get('email_limit_per_minute', '20')
        ];
    }

    private function getStorageData(): array 
    { 
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM file_servers ORDER BY id ASC");
        $servers = $stmt->fetchAll();

        $activeServers = 0;
        $totalUsed = 0;
        $totalLimit = 0;

        foreach($servers as &$s) {
            if (!empty($s['storage_path'])) {
                $s['storage_path'] = \App\Service\EncryptionService::decrypt($s['storage_path']);
            }
            if(($s['status'] ?? '') === 'active') $activeServers++;
            $totalUsed += (float)($s['current_usage_bytes'] ?? 0);
            $totalLimit += (float)($s['max_capacity_bytes'] ?? 0);
        }

        return [
            'servers' => $servers,
            'activeServers' => $activeServers,
            'totalServers' => count($servers),
            'totalUsed' => $totalUsed,
            'totalLimit' => $totalLimit,
            'usagePercent' => $totalLimit > 0 ? round(($totalUsed / $totalLimit) * 100, 1) : 0
        ];
    }

    private function getMonetizationData(): array
    {
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $db = Database::getInstance()->getConnection();
        $tiers = [];
        $exampleTiers = [
            [
                'name' => 'Tier 1',
                'rate_per_1000' => '5.00',
                'countries' => 'US, CA, GB, DE, FR, AU, NL, SE, NO, DK',
            ],
            [
                'name' => 'Tier 2',
                'rate_per_1000' => '2.00',
                'countries' => 'BR, MX, PL, TR, RU, AR, CL, RO, HU, ZA',
            ],
            [
                'name' => 'Tier 3',
                'rate_per_1000' => '0.50',
                'countries' => 'IN, PH, ID, VN, TH, PK, BD, EG, NG, MA',
            ],
        ];
        
        try {
            $stmt = $db->query("SELECT t.*, (SELECT GROUP_CONCAT(country_code) FROM ppd_tier_countries WHERE tier_id = t.id) as countries FROM ppd_tiers t ORDER BY t.rate_per_1000 DESC");
            $tiers = $stmt->fetchAll();
            foreach ($tiers as &$tier) {
                $tier['countries'] = $tier['countries'] ?? '';
            }
        } catch (\PDOException $e) {
            // Gracefully handle missing table
        }

        return [
            'adTop' => (string)Setting::get('ad_download_top', ''),
            'adBottom' => (string)Setting::get('ad_download_bottom', ''),
            'adLeft' => (string)Setting::get('ad_download_left', ''),
            'adRight' => (string)Setting::get('ad_download_right', ''),
            'adOverlay' => (string)Setting::get('ad_download_overlay', ''),
            'tiers' => $tiers,
            'exampleTiers' => $exampleTiers,
            'rewardsEnabled' => \App\Service\FeatureService::rewardsEnabled(),
            'affiliateEnabled' => \App\Service\FeatureService::affiliateEnabled(),
            'enabledModels' => array_filter(array_map('trim', explode(',', Setting::get('enabled_models', 'ppd,pps,mixed', 'rewards')))),
            'ppsCommission' => Setting::get('pps_commission_percent', '50', 'rewards'),
            'mixedPpdPercent' => Setting::get('mixed_ppd_percent', '30', 'rewards'),
            'mixedPpsPercent' => Setting::get('mixed_pps_percent', '30', 'rewards'),
            'retentionDays' => Setting::get('rewards_retention_days', '7', 'rewards'),
            'supportedWithdrawalMethods' => array_filter(array_map('trim', explode(',', Setting::get('supported_withdrawal_methods', 'paypal,bitcoin', 'rewards')))),
            'minVideoWatchPercent' => Setting::get('rewards_min_video_watch_percent', '80', 'rewards'),
            'minVideoWatchSeconds' => Setting::get('rewards_min_video_watch_seconds', '30', 'rewards'),
            'stripeEnabled' => Setting::get('payment_stripe_enabled', '0', 'payments'),
            'stripePublishableKey' => Setting::get('payment_stripe_publishable_key', '', 'payments'),
            'stripeSecretKey' => $demoAdmin ? '' : Setting::getEncrypted('payment_stripe_secret_key', ''),
            'stripeWebhookSecret' => $demoAdmin ? '' : Setting::getEncrypted('payment_stripe_webhook_secret', ''),
            'paypalEnabled' => Setting::get('payment_paypal_enabled', '0', 'payments'),
            'paypalClientId' => $demoAdmin ? '' : Setting::get('payment_paypal_client_id', '', 'payments'),
            'paypalClientSecret' => $demoAdmin ? '' : Setting::getEncrypted('payment_paypal_client_secret', ''),
            'paypalWebhookId' => $demoAdmin ? '' : Setting::get('payment_paypal_webhook_id', '', 'payments'),
            'paypalSandbox' => Setting::get('payment_paypal_sandbox', '1', 'payments'),
        ];
    }

    private function getCronData(): array
    {
        $db = Database::getInstance()->getConnection();
        $lastRun = Setting::get('last_cron_run_timestamp', 0);

        $manager = new \App\Service\CronManager();
        $manager->sync();
        
        $stmt = $db->query("SELECT * FROM cron_tasks ORDER BY task_name ASC");
        return [
            'lastRun' => $lastRun > 0 ? date('Y-m-d H:i:s', (int)$lastRun) : 'Never',
            'tasks' => $stmt->fetchAll()
        ];
    }

    private function getSeoData(): array
    {
        return [
            'seoConfig' => \App\Service\SeoService::getAdminConfig(),
            'seoHealth' => \App\Service\SeoService::getHealthReport(),
            'seoTab' => $_GET['seo_tab'] ?? 'overview',
        ];
    }

    private function getDownloadData(): array
    {
        return [
            'requireAccountToDownload' => Setting::get('require_account_to_download', '0'),
            'blockedDownloadCountries' => Setting::get('blocked_download_countries', ''),
            'trackCurrentDownloads' => Setting::get('track_current_downloads', '0'),
            'remoteUrlBackground' => Setting::get('remote_url_background', '0'),
            'cdnDownloadRedirectsEnabled' => Setting::get('cdn_download_redirects_enabled', '0'),
            'cdnDownloadBaseUrl' => Setting::get('cdn_download_base_url', ''),
            'streamingSupportEnabled' => Setting::get('streaming_support_enabled', '0'),
            'nginxCompletionLogPath' => Setting::get('nginx_completion_log_path', ''),
            'nginxCompletionRetentionDays' => Setting::get('nginx_completion_retention_days', '7'),
            'nginxCompletionMaxLinesPerRun' => Setting::get('nginx_completion_max_lines_per_run', '5000'),
        ];
    }

    private function getUploadData(): array
    {
        return [
            'uploadConcurrent' => Setting::get('upload_concurrent', '0'),
            'uploadConcurrentLimit' => Setting::get('upload_concurrent_limit', '2'),
            'uploadHidePopup' => Setting::get('upload_hide_popup', '0'),
            'uploadAppendFilename' => Setting::get('upload_append_filename', '0'),
            'uploadChunkingEnabled' => Setting::get('upload_chunking_enabled', '1'),
            'uploadChunkSizeMb' => Setting::get('upload_chunk_size_mb', '100'),
            'uploadLoginRequired' => Setting::get('upload_login_required', '0'),
            'uploadDetectDuplicates' => Setting::get('upload_detect_duplicates', '1'),
            'uploadAllowedExtensions' => Setting::get('upload_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4,mp3,ipa,apk')
        ];
    }

    /**
     * Unified Save Entry Point
     */
    public function save()
    {
        Auth::requireAdmin();
        $this->ensureDemoAdminReadOnly();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method not allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF mismatch");
        }

        $tab = $_POST['section'] ?? 'general';

        try {
            switch ($tab) {
                case 'general':
                    if (!$this->saveGeneralSettings()) return;
                    break;
                case 'security':
                    (new SecurityController())->updateSettings();
                    return;
                case 'email':
                    if (!$this->saveEmailSettings()) return;
                    break;
                case 'email_template':
                    $this->saveEmailTemplate();
                    break;
                case 'captcha':
                    $this->saveCaptchaSettings();
                    break;
                case 'security_features':
                    $this->saveSecurityFeatureSettings();
                    $tab = 'security';
                    break;
                case 'monetization':
                    $this->saveMonetizationSettings();
                    break;
                case 'seo':
                    $this->saveSeoSettings();
                    $tab = 'seo&seo_tab=' . urlencode($_POST['seo_scope'] ?? 'overview');
                    break;
                case 'cron':
                    $this->saveCronSettings();
                    break;
                case 'downloads':
                    $this->saveDownloadSettings();
                    break;
                case 'uploads':
                    $this->saveUploadSettings();
                    break;
            }
        } catch (\RuntimeException $e) {
            Logger::error('Configuration save failed', [
                'tab' => $tab,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['config_errors'] = ['The settings could not be saved. Review the form values and try again.'];
            header("Location: /admin/configuration?tab=" . $tab);
            exit;
        }

        $_SESSION['config_success'] = true;
        header("Location: /admin/configuration?tab=" . $tab);
        exit;
    }

    /**
     * triggerCron - Manual Heartbeat Execution
     */
    public function triggerCron()
    {
        Auth::requireAdmin();
        $this->ensureDemoAdminReadOnly();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method not allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF mismatch");
        }

        // Cooldown check (60 seconds)
        $lastRun = (int)Setting::get('last_cron_run_timestamp', 0);
        if ((time() - $lastRun) < 60) {
            $_SESSION['config_errors'] = ["Please wait at least 1 minute between manual task executions."];
            header("Location: /admin/configuration?tab=cron"); exit;
        }

        $db = Database::getInstance()->getConnection();
        
        // Force reset last_run_at so manager picks them up
        $db->exec("UPDATE cron_tasks SET last_run_at = NULL");

        $manager = new \App\Service\CronManager();
        
        // 1. Core Cleanup
        $manager->register('cleanup', function() {
            return (new \App\Service\CleanupService())->runExpiredCleanup();
        });

        // 2. Cloudflare Sync
        $manager->register('cf_sync', function() {
            return (new \App\Service\CloudflareSyncService())->sync();
        });

        // 3. Premium Expiry
        $manager->register('account_downgrade', function() {
            return (new \App\Service\AutomatedTaskService())->downgradeExpiredAccounts();
        });

        // 4. Monitoring
        $manager->register('server_monitoring', function() {
            return (new \App\Service\AutomatedTaskService())->monitorServerHealth();
        });

        // 5. Background Workers
        $manager->register('mail_queue', function() {
            return \App\Service\MailQueueService::processBatch();
        });

        if (\App\Service\FeatureService::rewardsEnabled()) {
            $manager->register('reward_flush', function() {
                return (new \App\Service\RewardService())->flushQueue(5000);
            });

            $manager->register('reward_rollup', function() {
                return ['rolled_up' => (new \App\Service\RewardService())->rollupHistory(\App\Service\RewardService::retentionDays())];
            });

            $manager->register('fraud_scores', function() {
                return ['recomputed' => (new \App\Service\RewardFraudService())->recomputeAccountScores()];
            });

            $manager->register('fraud_clearance', function() {
                return ['cleared' => (new \App\Service\RewardFraudService())->clearHeldEarnings()];
            });

            $manager->register('fraud_cleanup', function() {
                return ['purged' => (new \App\Service\RewardFraudService())->purgeOldEventData()];
            });
        }

        // 7. Background Purge & Audit
        $manager->register('file_purge', function() {
            return (new \App\Service\AutomatedTaskService())->processFilePurgeQueue(50);
        });

        $manager->register('storage_audit', function() {
            return (new \App\Service\AutomatedTaskService())->auditUserStorage(5);
        });

        $manager->register('remote_uploads', function() {
            return (new \App\Service\AutomatedTaskService())->processRemoteUploadQueue(5);
        });

        $manager->register('nginx_download_logs', function() {
            return (new \App\Service\NginxDownloadLogService())->process();
        });

        $manager->register('upload_sessions', function() {
            $service = new \App\Service\MultipartUploadService();
            return [
                'sessions' => $service->expireStaleSessions(200),
                'reservations' => $service->releaseExpiredReservations(200),
            ];
        });

        $manager->register('upload_reconcile', function() {
            return (new \App\Service\MultipartUploadService())->reconcileActiveSessions(100);
        });

        $manager->register('checksum_jobs', function() {
            return (new \App\Service\MultipartUploadService())->reconcileCompletedChecksums(200);
        });

        // Execute all
        $manager->run();

        $_SESSION['config_success'] = true;
        header("Location: /admin/configuration?tab=cron");
        exit;
    }

    /**
     * testSmtpConnection - AJAX Verification
     */
    public function testSmtpConnection()
    {
        Auth::requireAdmin();
        $this->ensureDemoAdminReadOnly(true);
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF Token Mismatch']); exit;
        }
        header('Content-Type: application/json');

        try {
            $service = $this->buildMailServiceFromRequest();

            if ($service->testConnection()) {
                echo json_encode(['status' => 'success', 'message' => 'SMTP Connection Successful!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Connected, but failed to authenticate or greet server.']);
            }
        } catch (\Exception $e) {
            Logger::error('SMTP connection test failed', [
                'error' => $e->getMessage(),
            ]);
            echo json_encode(['status' => 'error', 'message' => 'Connection failed. Check the SMTP settings and logs.']);
        }
        exit;
    }

    /**
     * sendTestEmail - AJAX Verification
     */
    public function sendTestEmail()
    {
        Auth::requireAdmin();
        $this->ensureDemoAdminReadOnly(true);
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF Token Mismatch']); exit;
        }
        header('Content-Type: application/json');

        $target = $_POST['test_email_address'] ?? '';
        if (!$target) {
            echo json_encode(['status' => 'error', 'message' => 'No target email provided.']); exit;
        }

        try {
            $service = $this->buildMailServiceFromRequest();

            if ($service->send($target, "fyuhls Test Email", "If you are reading this, your SMTP settings are working perfectly!")) {
                echo json_encode(['status' => 'success', 'message' => 'Test email sent successfully to ' . $target]);
            }
        } catch (\Exception $e) {
            Logger::error('SMTP test email send failed', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            echo json_encode(['status' => 'error', 'message' => 'Test email failed. Check the SMTP settings and logs.']);
        }
        exit;
    }

    private function saveCronSettings(): void
    {
        $db = Database::getInstance()->getConnection();
        $intervals = $_POST['intervals'] ?? [];
        foreach ($intervals as $key => $mins) {
            $stmt = $db->prepare("SELECT interval_mins FROM cron_tasks WHERE task_key = ?");
            $stmt->execute([$key]);
            $oldMins = $stmt->fetchColumn();

            if ($oldMins != $mins) {
                $upd = $db->prepare("UPDATE cron_tasks SET interval_mins = ? WHERE task_key = ?");
                $upd->execute([(int)$mins, $key]);
                $this->logActivity('update_cron_interval', $key, "Interval: {$oldMins}m -> {$mins}m");
            }
        }
    }

    private function saveGeneralSettings(): bool
    {
        $rules = [
            'app_name' => 'required',
            'admin_notification_email' => 'required|email'
        ];

        if (!$this->validate($_POST, $rules)) {
            header("Location: /admin/configuration?tab=general");
            return false;
        }

        $this->updateSetting('app.name', $_POST['app_name'] ?? 'Fyuhls', 'general');
        $this->updateSetting('allow_registrations', isset($_POST['allow_registrations']) ? '1' : '0', 'general');

        $turningDemoOn = isset($_POST['demo_mode']);
        $this->updateSetting('demo_mode', $turningDemoOn ? '1' : '0', 'general');

        // if demo mode is being enabled and no demo admin is designated yet, auto-assign the current admin
        if ($turningDemoOn) {
            $currentDemoAdminId = (int)Setting::get('demo_admin_user_id', '0');
            if ($currentDemoAdminId === 0) {
                $currentAdminId = (int)(\App\Core\Auth::id() ?? 0);
                if ($currentAdminId > 0) {
                    $this->updateSetting('demo_admin_user_id', (string)$currentAdminId, 'general');
                }
            }
        }

        $this->updateSetting('maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0', 'general');
        $this->updateSetting('require_email_verification', isset($_POST['require_email_verification']) ? '1' : '0', 'general');
        $this->updateSetting('reserved_usernames', $_POST['reserved_usernames'] ?? 'administrator,admin,support', 'general');
        $this->updateSetting('admin_notification_email', $_POST['admin_notification_email'] ?? '', 'general');
        $this->updateSetting('show_powered_by_footer', isset($_POST['show_powered_by_footer']) ? '1' : '0', 'general');
        $this->updateSetting('video.ffmpeg_enabled', isset($_POST['ffmpeg_enabled']) ? '1' : '0', 'general');
        $this->updateSetting('video.ffmpeg_path', trim((string)($_POST['ffmpeg_path'] ?? '')), 'general');
        return true;
    }

    private function saveEmailSettings(): bool
    {
        $rules = [
            'email_smtp_host' => 'required',
            'email_smtp_port' => 'required|numeric',
            'email_from_address' => 'required|email'
        ];

        if (!$this->validate($_POST, $rules)) {
            header("Location: /admin/configuration?tab=email");
            return false;
        }

        try {
            $smtpHost = $this->normalizeSmtpHost((string)($_POST['email_smtp_host'] ?? ''));
            $smtpPort = $this->normalizeSmtpPort($_POST['email_smtp_port'] ?? 25);
        } catch (\RuntimeException $e) {
            Logger::error('Email settings validation failed', [
                'error' => $e->getMessage(),
            ]);
            $_SESSION['config_errors'] = ['Email settings could not be saved. Review the SMTP host and port values and try again.'];
            header("Location: /admin/configuration?tab=email");
            return false;
        }

        $this->updateSetting('email_smtp_host', $smtpHost, 'email');
        $this->updateSetting('email_smtp_port', (string)$smtpPort, 'email');
        $this->updateSetting('email_from_address', trim((string)($_POST['email_from_address'] ?? '')), 'email');
        $this->updateSetting('email_secure_method', $this->normalizeEmailSecureMethod($_POST['email_secure_method'] ?? 'none'), 'email');
        $this->updateSetting('email_smtp_requires_auth', isset($_POST['email_smtp_requires_auth']) ? '1' : '0', 'email');
        $this->updateSetting('email_smtp_auth_username', trim((string)($_POST['email_smtp_auth_username'] ?? '')), 'email');
        
        if (!empty($_POST['email_smtp_auth_password'])) {
            Setting::setEncrypted('email_smtp_auth_password', $_POST['email_smtp_auth_password'], 'email');
            $this->logActivity('update_setting', 'email_smtp_auth_password', '********');
        }
        
        $this->updateSetting('email_limit_per_minute', (string)max(1, (int)($_POST['email_limit_per_minute'] ?? 20)), 'email');
        return true;
    }

    private function saveEmailTemplate(): void
    {
        \App\Service\MailService::ensureDefaultTemplates();
        $db = Database::getInstance()->getConnection();
        $templateKey = trim((string)($_POST['template_key'] ?? ''));
        $subject = $_POST['subject'] ?? '';
        $body = $_POST['body'] ?? '';

        if ($templateKey !== '') {
            $stmt = $db->prepare("
                INSERT INTO email_templates (template_key, subject, body)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    subject = VALUES(subject),
                    body = VALUES(body)
            ");
            if ($stmt->execute([$templateKey, $subject, $body])) {
                $this->logActivity('update_email_template', $templateKey, "Subject: $subject");
            }
        }
    }

    private function buildMailServiceFromRequest(): \App\Service\MailService
    {
        $postedPassword = (string)($_POST['email_smtp_auth_password'] ?? '');
        $password = $postedPassword !== '' ? $postedPassword : Setting::getEncrypted('email_smtp_auth_password', '');
        $host = $this->normalizeSmtpHost((string)($_POST['email_smtp_host'] ?? ''));
        $port = $this->normalizeSmtpPort($_POST['email_smtp_port'] ?? 25);

        return new \App\Service\MailService(
            $host,
            $port,
            trim((string)($_POST['email_from_address'] ?? '')),
            $this->normalizeEmailSecureMethod($_POST['email_secure_method'] ?? 'none'),
            isset($_POST['email_smtp_requires_auth']),
            trim((string)($_POST['email_smtp_auth_username'] ?? '')),
            $password
        );
    }

    private function saveCaptchaSettings(): void
    {
        $this->updateSetting('captcha_site_key', $_POST['captcha_site_key'] ?? '', 'captcha');
        if (!empty($_POST['captcha_secret_key'])) {
            Setting::setEncrypted('captcha_secret_key', $_POST['captcha_secret_key'], 'captcha');
            $this->logActivity('update_setting', 'captcha_secret_key', '********');
        }

        $captchaKeys = ['captcha_download_guest','captcha_download_free','captcha_report_file','captcha_contact','captcha_dmca','captcha_register','captcha_user_login','captcha_admin_login'];
        foreach ($captchaKeys as $ck) {
            $this->updateSetting($ck, isset($_POST[$ck]) ? '1' : '0', 'captcha');
        }
    }

    private function saveDownloadSettings(): void
    {
        $this->updateSetting('require_account_to_download', isset($_POST['require_account_to_download']) ? '1' : '0', 'downloads');
        $this->updateSetting('blocked_download_countries', $_POST['blocked_download_countries'] ?? '', 'downloads');
        $this->updateSetting('track_current_downloads', isset($_POST['track_current_downloads']) ? '1' : '0', 'downloads');
        $this->updateSetting('remote_url_background', isset($_POST['remote_url_background']) ? '1' : '0', 'downloads');
        $this->updateSetting('cdn_download_redirects_enabled', isset($_POST['cdn_download_redirects_enabled']) ? '1' : '0', 'downloads');
        $this->updateSetting('cdn_download_base_url', $this->normalizeCdnDownloadBaseUrl($_POST['cdn_download_base_url'] ?? ''), 'downloads');
        $this->updateSetting('streaming_support_enabled', isset($_POST['streaming_support_enabled']) ? '1' : '0', 'downloads');
        $this->updateSetting('nginx_completion_log_path', $this->normalizeNginxCompletionLogPath($_POST['nginx_completion_log_path'] ?? ''), 'downloads');
        $this->updateSetting('nginx_completion_retention_days', (string)max(1, (int)($_POST['nginx_completion_retention_days'] ?? 7)), 'downloads');
        $this->updateSetting('nginx_completion_max_lines_per_run', (string)max(100, (int)($_POST['nginx_completion_max_lines_per_run'] ?? 5000)), 'downloads');
    }

    private function saveUploadSettings(): void
    {
        $this->updateSetting('upload_concurrent', isset($_POST['upload_concurrent']) ? '1' : '0', 'uploads');
        $this->updateSetting('upload_concurrent_limit', $_POST['upload_concurrent_limit'] ?? '2', 'uploads');
        $this->updateSetting('upload_hide_popup', isset($_POST['upload_hide_popup']) ? '1' : '0', 'uploads');
        $this->updateSetting('upload_append_filename', isset($_POST['upload_append_filename']) ? '1' : '0', 'uploads');
        $this->updateSetting('upload_chunking_enabled', isset($_POST['upload_chunking_enabled']) ? '1' : '0', 'uploads');
        $this->updateSetting('upload_chunk_size_mb', $_POST['upload_chunk_size_mb'] ?? '100', 'uploads');
        $this->updateSetting('upload_login_required', isset($_POST['upload_login_required']) ? '1' : '0', 'uploads');
        $this->updateSetting('upload_detect_duplicates', isset($_POST['upload_detect_duplicates']) ? '1' : '0', 'uploads');
        $this->updateSetting('upload_allowed_extensions', $_POST['upload_allowed_extensions'] ?? '', 'uploads');
    }

    private function saveMonetizationSettings(): void
    {
        $db = Database::getInstance()->getConnection();
        $action = $_POST['monetization_action'] ?? 'ads';

        if ($action === 'rewards_settings') {
            $rewardsEnabled = isset($_POST['rewards_enabled']) ? '1' : '0';
            $affiliateEnabled = $rewardsEnabled === '1' && isset($_POST['affiliate_enabled']) ? '1' : '0';
            $enabledModels = array_values(array_intersect(['ppd', 'pps', 'mixed'], $_POST['enabled_models'] ?? []));

            $this->updateSetting('rewards_enabled', $rewardsEnabled, 'rewards');
            $this->updateSetting('affiliate_enabled', $affiliateEnabled, 'rewards');
            $this->updateSetting('enabled_models', implode(',', $enabledModels), 'rewards');
            $this->updateSetting('global_model_status', empty($enabledModels) ? 'disabled' : 'enabled', 'rewards');
            $this->updateSetting('pps_commission_percent', (string) (int) ($_POST['pps_commission_percent'] ?? 0), 'rewards');
            $this->updateSetting('mixed_ppd_percent', (string) (int) ($_POST['mixed_ppd_percent'] ?? 30), 'rewards');
            $this->updateSetting('mixed_pps_percent', (string) (int) ($_POST['mixed_pps_percent'] ?? 30), 'rewards');
            $this->updateSetting('ppd_ip_reward_limit', (string) max(1, (int) ($_POST['ppd_ip_reward_limit'] ?? 1)), 'rewards');
            $this->updateSetting('ppd_min_download_percent', (string) min(100, max(0, (int) ($_POST['ppd_min_download_percent'] ?? 0))), 'rewards');
            $this->updateSetting('ppd_max_earn_ip', (string) (float) ($_POST['ppd_max_earn_ip'] ?? 0), 'rewards');
            $this->updateSetting('ppd_max_earn_file', (string) (float) ($_POST['ppd_max_earn_file'] ?? 0), 'rewards');
            $this->updateSetting('ppd_max_earn_user', (string) (float) ($_POST['ppd_max_earn_user'] ?? 0), 'rewards');
            $this->updateSetting('ppd_only_guests_count', isset($_POST['ppd_only_guests_count']) && $_POST['ppd_only_guests_count'] === '1' ? '1' : '0', 'rewards');
            $this->updateSetting('ppd_min_file_size', (string) ((float) ($_POST['ppd_min_file_size'] ?? 0) * 1024 * 1024), 'rewards');
            $this->updateSetting('ppd_max_file_size', (string) ((float) ($_POST['ppd_max_file_size'] ?? 0) * 1024 * 1024), 'rewards');
            $rewardVpn = Setting::get('block_vpn_traffic', '0', 'security') === '1' ? '0' : ((($_POST['ppd_reward_vpn'] ?? '0') === '1') ? '1' : '0');
            $this->updateSetting('ppd_reward_vpn', $rewardVpn, 'rewards');
            $this->updateSetting('rewards_retention_days', (string) max(1, (int) ($_POST['rewards_retention_days'] ?? 7)), 'rewards');
            $this->updateSetting('rewards_min_video_watch_percent', (string) min(100, max(0, (int) ($_POST['rewards_min_video_watch_percent'] ?? 80))), 'rewards');
            $this->updateSetting('rewards_min_video_watch_seconds', (string) max(0, (int) ($_POST['rewards_min_video_watch_seconds'] ?? 30)), 'rewards');
            $methods = array_values(array_intersect(['paypal', 'stripe', 'bitcoin', 'wire'], $_POST['supported_withdrawal_methods'] ?? []));
            $this->updateSetting('supported_withdrawal_methods', implode(',', $methods), 'rewards');
            $this->updateSetting('payment_stripe_enabled', isset($_POST['payment_stripe_enabled']) ? '1' : '0', 'payments');
            if (!empty($_POST['payment_stripe_secret_key'])) {
                Setting::setEncrypted('payment_stripe_secret_key', $_POST['payment_stripe_secret_key'], 'payments');
                $this->logActivity('update_setting', 'payment_stripe_secret_key', '********');
            }
            if (!empty($_POST['payment_stripe_webhook_secret'])) {
                Setting::setEncrypted('payment_stripe_webhook_secret', $_POST['payment_stripe_webhook_secret'], 'payments');
                $this->logActivity('update_setting', 'payment_stripe_webhook_secret', '********');
            }
            $this->updateSetting('payment_paypal_enabled', isset($_POST['payment_paypal_enabled']) ? '1' : '0', 'payments');
            $this->updateSetting('payment_paypal_client_id', trim((string)($_POST['payment_paypal_client_id'] ?? '')), 'payments');
            if (!empty($_POST['payment_paypal_client_secret'])) {
                Setting::setEncrypted('payment_paypal_client_secret', $_POST['payment_paypal_client_secret'], 'payments');
                $this->logActivity('update_setting', 'payment_paypal_client_secret', '********');
            }
            $this->updateSetting('payment_paypal_webhook_id', trim((string)($_POST['payment_paypal_webhook_id'] ?? '')), 'payments');
            $this->updateSetting('payment_paypal_sandbox', isset($_POST['payment_paypal_sandbox']) ? '1' : '0', 'payments');
        } elseif ($action === 'ads') {
            $ads = $_POST['ads'] ?? [];
            foreach ($ads as $key => $code) {
                if (!in_array((string)$key, self::ALLOWED_AD_SLOT_KEYS, true)) {
                    continue;
                }
                $code = (string)$code;
                if (strlen($code) > self::MAX_AD_CODE_LENGTH) {
                    $_SESSION['config_errors'] = ["Ad placement code is too large. Keep each ad block under " . self::MAX_AD_CODE_LENGTH . " characters."];
                    header("Location: /admin/configuration?tab=monetization");
                    exit;
                }
                $this->updateSetting("ad_{$key}", $code, 'ads');
            }
        } elseif ($action === 'add_tier') {
            $name = trim($_POST['new_name'] ?? '');
            $rate = (float)($_POST['new_rate'] ?? 0);
            $countries = array_map('trim', explode(',', $_POST['new_countries'] ?? ''));
            
            if ($name) {
                $stmt = $db->prepare("INSERT INTO ppd_tiers (name, rate_per_1000) VALUES (?, ?)");
                $stmt->execute([$name, $rate]);
                $tierId = $db->lastInsertId();
                
                if (!empty($countries) && $countries[0] !== '') {
                    $cStmt = $db->prepare("INSERT IGNORE INTO ppd_tier_countries (tier_id, country_code) VALUES (?, ?)");
                    foreach ($countries as $code) {
                        $code = strtoupper(substr($code, 0, 2));
                        if ($code) $cStmt->execute([$tierId, $code]);
                    }
                }
                $this->logActivity('add_ppd_tier', $name, "Rate: $rate");
            }
        } elseif ($action === 'delete_tier') {
            $id = (int)$_POST['tier_id'];
            $db->prepare("DELETE FROM ppd_tiers WHERE id = ?")->execute([$id]);
            $this->logActivity('delete_ppd_tier', (string)$id);
        } elseif ($action === 'load_example_tiers') {
            $hasAnyTiers = (bool)$db->query("SELECT 1 FROM ppd_tiers LIMIT 1")->fetchColumn();
            if (!$hasAnyTiers) {
                $starterTiers = [
                    ['Tier 1', 5.00, ['US', 'CA', 'GB', 'DE', 'FR', 'AU', 'NL', 'SE', 'NO', 'DK']],
                    ['Tier 2', 2.00, ['BR', 'MX', 'PL', 'TR', 'RU', 'AR', 'CL', 'RO', 'HU', 'ZA']],
                    ['Tier 3', 0.50, ['IN', 'PH', 'ID', 'VN', 'TH', 'PK', 'BD', 'EG', 'NG', 'MA']],
                ];

                $tierStmt = $db->prepare("INSERT INTO ppd_tiers (name, rate_per_1000) VALUES (?, ?)");
                $countryStmt = $db->prepare("INSERT IGNORE INTO ppd_tier_countries (tier_id, country_code) VALUES (?, ?)");

                foreach ($starterTiers as [$name, $rate, $countries]) {
                    $tierStmt->execute([$name, $rate]);
                    $tierId = (int)$db->lastInsertId();
                    foreach ($countries as $code) {
                        $countryStmt->execute([$tierId, $code]);
                    }
                }

                $this->logActivity('load_example_ppd_tiers', 'starter');
            }
        } elseif ($action === 'update_tiers') {
            if (!empty($_POST['tiers']) && is_array($_POST['tiers'])) {
                foreach ($_POST['tiers'] as $id => $data) {
                    $name = trim($data['name'] ?? '');
                    $rate = (float)($data['rate'] ?? 0);
                    $db->prepare("UPDATE ppd_tiers SET name = ?, rate_per_1000 = ? WHERE id = ?")->execute([$name, $rate, $id]);
                    $db->prepare("DELETE FROM ppd_tier_countries WHERE tier_id = ?")->execute([$id]);
                    $countries = array_map('trim', explode(',', $data['countries'] ?? ''));
                    if (!empty($countries) && $countries[0] !== '') {
                        $cStmt = $db->prepare("INSERT IGNORE INTO ppd_tier_countries (tier_id, country_code) VALUES (?, ?)");
                        foreach ($countries as $code) {
                            $code = strtoupper(substr($code, 0, 2));
                            if ($code) $cStmt->execute([$id, $code]);
                        }
                    }
                }
                $this->logActivity('update_ppd_tiers', 'all');
            }
        }
    }

    private function saveSecurityFeatureSettings(): void
    {
        $this->updateSetting('two_factor_enabled', isset($_POST['two_factor_enabled']) ? '1' : '0', 'security');
        $this->updateSetting('2fa_enabled', isset($_POST['two_factor_enabled']) ? '1' : '0', 'security');
        $this->updateSetting('2fa_enforce_date', trim((string) ($_POST['2fa_enforce_date'] ?? '')), 'security');
    }

    private function saveSeoSettings(): void
    {
        $scope = $_POST['seo_scope'] ?? 'overview';
        $scopes = [
            'general' => [
                'boolean' => [],
                'string' => [
                    'seo_title_template',
                    'seo_default_meta_description',
                    'seo_canonical_base_url',
                    'seo_default_robots',
                    'seo_default_social_image',
                    'seo_organization_name',
                ],
            ],
            'homepage' => [
                'boolean' => ['seo_home_faq_schema', 'seo_home_software_schema'],
                'string' => ['seo_home_title', 'seo_home_description', 'seo_home_h1', 'seo_home_intro', 'seo_home_robots'],
            ],
            'templates' => [
                'boolean' => ['seo_file_noindex_private'],
                'string' => ['seo_file_title_template', 'seo_file_description_template'],
            ],
            'indexing' => [
                'boolean' => ['seo_sitemap_enabled', 'seo_sitemap_include_files', 'seo_robots_block_auth_pages', 'seo_noindex_internal_pages'],
                'string' => [],
            ],
            'verification' => [
                'boolean' => [],
                'string' => ['seo_verification_google', 'seo_verification_bing', 'seo_custom_head_code'],
            ],
        ];

        $scopeConfig = $scopes[$scope] ?? ['boolean' => [], 'string' => []];

        foreach ($scopeConfig['boolean'] as $key) {
            $this->updateSetting($key, isset($_POST[$key]) ? '1' : '0', 'seo');
        }

        foreach ($scopeConfig['string'] as $key) {
            $value = trim((string)($_POST[$key] ?? ''));
            if ($key === 'seo_custom_head_code' && strlen($value) > self::MAX_CUSTOM_HEAD_CODE_LENGTH) {
                $_SESSION['config_errors'] = ["Custom Head Code is too large. Keep it under " . self::MAX_CUSTOM_HEAD_CODE_LENGTH . " characters."];
                header("Location: /admin/configuration?tab=seo&seo_tab=" . urlencode($scope));
                exit;
            }
            $this->updateSetting($key, $value, 'seo');
        }
    }

    private function summarizeSettingValueForLog(string $key, string $value): string
    {
        $sensitiveKeys = [
            'seo_custom_head_code',
            'ad_download_top',
            'ad_download_bottom',
            'ad_download_left',
            'ad_download_right',
            'ad_download_overlay',
        ];

        if (in_array($key, $sensitiveKeys, true) || str_starts_with($key, 'ad_')) {
            return '[redacted code block, ' . strlen($value) . ' bytes]';
        }

        if (strlen($value) > 500) {
            return '[long value, ' . strlen($value) . ' bytes]';
        }

        return $value;
    }

    private function updateSetting(string $key, string $value, string $group): void
    {
        $oldValue = Setting::get($key);
        if ($oldValue !== $value) {
            Setting::set($key, $value, $group);
            $this->logActivity('update_setting', $key, $this->summarizeSettingValueForLog($key, $value));
        }
    }

    private function validate(array $data, array $rules): bool
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $value = trim($data[$field] ?? '');
            $fieldRules = explode('|', $ruleString);
            $label = str_replace('_', ' ', ucfirst($field));

            foreach ($fieldRules as $r) {
                if ($r === 'required' && empty($value)) {
                    $errors[] = "{$label} is a required field.";
                }
                if ($r === 'email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "{$label} must be a valid email address.";
                }
                if ($r === 'numeric' && !empty($value) && !is_numeric($value)) {
                    $errors[] = "{$label} must be a number.";
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['config_errors'] = $errors;
            return false;
        }
        return true;
    }

    public function exportDiagnostics(): void
    {
        if (!Auth::isAdmin()) {
            http_response_code(403);
            die("Access denied");
        }

        if (DemoModeService::currentViewerIsDemoAdmin()) {
            http_response_code(403);
            die("Diagnostics export is hidden for the demo admin account.");
        }

        $service = new \App\Service\DiagnosticsService();
        $bundle = $service->generateBundle();

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="diagnostics_' . date('Ymd_His') . '.json"');
        
        echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function logActivity(string $action, string $itemType, ?string $details = null): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Self-Healing: Ensure the audit log table exists before writing
            static $tableVerified = false;
            if (!$tableVerified) {
                $db->exec("CREATE TABLE IF NOT EXISTS `admin_activity_log` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `admin_id` BIGINT UNSIGNED NOT NULL,
                    `action` VARCHAR(100) NOT NULL,
                    `item_type` VARCHAR(50) NULL,
                    `item_id` BIGINT UNSIGNED NULL,
                    `details` TEXT NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `admin_id` (`admin_id`),
                    INDEX `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $tableVerified = true;
            }

            $stmt = $db->prepare("INSERT INTO admin_activity_log (admin_id, action, item_type, details, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                Auth::id() ?? 0,
                $action,
                $itemType,
                substr($details ?? '', 0, 1000),
                SecurityService::getClientIp()
            ]);
        } catch (\Exception $e) {
            error_log("Failed to log admin activity: " . $e->getMessage());
        }
    }
}
