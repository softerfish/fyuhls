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
use App\Service\EncryptionService;
use App\Service\MailHostSafetyService;
use App\Service\Migration\EncryptionMigrationService;
use App\Service\Database\ManualJsonColumnMigrationService;
use App\Service\PayoutProcessorService;
use App\Service\RememberMeService;
use App\Service\SecurityService;
use App\Service\StaffActivityService;

/**
 * ConfigurationController - Enterprise Unified Settings Hub
 *
 * Consolidates Site, Security, Email, Ads, Cron, and File Server management.
 * Designed for high-stability and zero-downtime during configuration updates.
 */
class ConfigurationController
{
    private array $allowedTabs = ['general', 'security', 'email', 'storage', 'monetization', 'seo', 'cron', 'downloads', 'uploads', 'link_checker', 'tickets'];
    private const MAX_CUSTOM_HEAD_CODE_LENGTH = 20000;
    private const MAX_AD_CODE_LENGTH = 20000;
    private const MIN_CRON_INTERVAL_MINS = 1;
    private const MAX_CRON_INTERVAL_MINS = 10080;
    private const ALLOWED_IDLE_LOGOUT_MINUTES = [240, 480, 720, 1440, 10080, 20160, 43200];
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

    private function requireConfigurationAccess(): void
    {
        Auth::requireCapability('configuration.manage');
    }

    private function requireBonusOfferFinancialReviewAccess(): void
    {
        Auth::requireCapability('bonus_awards.review');
    }

    private function requireDiagnosticsAccess(): void
    {
        Auth::requireAnyCapability(['configuration.manage', 'support.manage']);
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
        return MailHostSafetyService::normalizeSmtpHost($host);
    }

    private function normalizeSmtpPort($port): int
    {
        return MailHostSafetyService::normalizeSmtpPort($port);
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

        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException('Nginx completion log path must be an absolute path.');
        }

        if (preg_match('/[\x00-\x1F\\\\]/', $path) === 1) {
            throw new \RuntimeException('Nginx completion log path must be a Linux absolute path without backslashes or control characters.');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new \RuntimeException('Nginx completion log path cannot contain parent-directory traversal.');
        }

        $normalized = preg_replace('#/+#', '/', $path) ?? $path;
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
        $bonusReviewOnly = false;

        $this->requireConfigurationAccess();

        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $errors = $_SESSION['config_errors'] ?? [];
        if (!empty($_SESSION['error'])) {
            $errors[] = (string)$_SESSION['error'];
        }
        $successMessage = $_SESSION['config_success_message'] ?? null;
        if ($successMessage === null && !empty($_SESSION['success'])) {
            $successMessage = (string)$_SESSION['success'];
        }
        if ($successMessage === null && !empty($_SESSION['config_success'])) {
            $successMessage = 'Configuration updated successfully.';
        }

        $activeTab = $_GET['tab'] ?? 'general';
        if (!in_array($activeTab, $this->allowedTabs)) {
            $activeTab = 'general';
        }

        // Prepare base data
        $data = [
            'activeTab' => $activeTab,
            'saved' => $successMessage !== null,
            'successMessage' => $successMessage,
            'errors' => $errors,
            'demoAdmin' => $demoAdmin,
            'demoMode' => Setting::get('demo_mode', '0'),
            'bonusReviewOnly' => $bonusReviewOnly,
        ];
        $data = array_merge($data, $this->getConfigurationNoticeData());
        unset($_SESSION['config_success'], $_SESSION['config_success_message'], $_SESSION['config_errors'], $_SESSION['success'], $_SESSION['error']);

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
                $data = array_merge($data, $this->getMonetizationData($bonusReviewOnly));
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
            case 'link_checker':
                $data = array_merge($data, $this->getLinkCheckerData());
                break;
            case 'tickets':
                $data = array_merge($data, $this->getTicketsData());
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
        $ffmpegEnabled = Setting::getOrConfig('video.ffmpeg_enabled', '1');
        $ffmpegPath = $demoAdmin ? '' : Setting::getOrConfig('video.ffmpeg_path', \App\Core\Config::get('video.ffmpeg_path', ''));
        return [
            'appName' => Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
            'allowRegistrations' => Setting::get('allow_registrations', '1'),
            'demoMode' => Setting::get('demo_mode', '0'),
            'maintenanceMode' => Setting::get('maintenance_mode', '0'),
            'requireEmailVer' => Setting::get('require_email_verification', '0'),
            'reservedUsernames' => Setting::get('reserved_usernames', 'administrator,admin,support'),
            'adminEmail' => $demoAdmin ? '' : Setting::get('admin_notification_email', ''),
            'showPoweredBy' => Setting::get('show_powered_by_footer', '1'),
            'gdOk' => function_exists('imagecreatetruecolor') && function_exists('imagejpeg'),
            'ffmpegEnabled' => $ffmpegEnabled,
            'ffmpegPath' => $ffmpegPath,
            'ffmpegOk' => $ffmpegEnabled === '1' && !empty($ffmpegPath) && file_exists($ffmpegPath),
        ];
    }

    private function getSecurityData(): array
    {
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $migrationService = new EncryptionMigrationService();
        $pendingEncryption = $migrationService->getPendingCount();
        $dbDriftError = (string)Setting::get('db_drift_error', '');
        $legacyJsonRepairAvailable =
            str_contains($dbDriftError, 'reward_receipts: drifted column risk_reasons_json')
            || str_contains($dbDriftError, 'earnings: drifted column risk_reasons_json')
            || str_contains($dbDriftError, 'earnings: drifted column metadata')
            || str_contains($dbDriftError, 'download_sessions: drifted column risk_reasons_json')
            || str_contains($dbDriftError, 'download_session_events: drifted column event_payload');
        $legacyJsonRepairPlan = [];
        $pendingEncryptionItems = [];
        if (!$demoAdmin && $pendingEncryption > 0) {
            $pendingEncryptionItems = $migrationService->getPendingItems(5);
        }
        if ($legacyJsonRepairAvailable) {
            try {
                $pdo = Database::getInstance()->getConnection();
                if ($pdo) {
                    $legacyJsonRepairPlan = (new ManualJsonColumnMigrationService($pdo))->inspect();
                }
            } catch (\Throwable $e) {
                $legacyJsonRepairPlan = [];
            }
        }

        $captchaKeys = ['captcha_download_guest','captcha_download_free','captcha_report_file','captcha_contact','captcha_dmca','captcha_register','captcha_user_login','captcha_link_checker'];
        $captchaPlacements = [];
        foreach ($captchaKeys as $ck) {
            $captchaPlacements[$ck] = Setting::get($ck, '0');
        }
        $captchaPlacements['captcha_user_login'] = (
            ($captchaPlacements['captcha_user_login'] ?? '0') === '1'
            || Setting::get('captcha_admin_login', '0') === '1'
        ) ? '1' : '0';

        return [
            'migrationService' => $migrationService,
            'pendingEncryption' => $pendingEncryption,
            'pendingEncryptionItems' => $pendingEncryptionItems,
            'blockVpnTraffic' => \App\Service\SecurityService::getVpnProtectionMode() === 'enforcement',
            'vpnProtectionMode' => \App\Service\SecurityService::getVpnProtectionMode(),
            'vpnProtectionScope' => \App\Service\SecurityService::getVpnProtectionScope(),
            'proxycheckApiKey' => $demoAdmin ? '' : Setting::getEncrypted('proxycheck_api_key', ''),
            'vpnWhitelist' => Setting::get('vpn_whitelist', ''),
            'rateLimitLogin' => (int)Setting::get('rate_limit_login', '5'),
            'rateLimitReg' => (int)Setting::get('rate_limit_registration', '5'),
            'trustCloudflare' => Setting::get('trust_cloudflare', '0') === '1',
            'trustLoopbackProxyHeaders' => Setting::get('trust_loopback_proxy_headers', '0') === '1',
            'captchaSiteKey' => Setting::get('captcha_site_key', ''),
            'captchaSecretKey' => $demoAdmin ? '' : Setting::getEncrypted('captcha_secret_key', ''),
            'captchaPlacements' => $captchaPlacements,
            'twoFactorEnabled' => \App\Service\FeatureService::twoFactorEnabled(),
            'twoFactorEnforceDate' => Setting::get('2fa_enforce_date', '', 'security'),
            'twoFactorSetupRateLimit' => (int)Setting::get('rate_limit_2fa_setup', '5', 'security'),
            'twoFactorVerifyRateLimit' => (int)Setting::get('rate_limit_2fa_verify', '5', 'security'),
            'twoFactorRecoveryRateLimit' => (int)Setting::get('rate_limit_2fa_recovery', '5', 'security'),
            'idleLogoutOptions' => $this->idleLogoutOptions(),
            'adminIdleLogoutMinutes' => \App\Core\Auth::idleLogoutMinutesForRole('admin'),
            'moderatorIdleLogoutMinutes' => \App\Core\Auth::idleLogoutMinutesForRole('moderator'),
            'userIdleLogoutMinutes' => \App\Core\Auth::idleLogoutMinutesForRole('user'),
            'rememberMeEnabled' => RememberMeService::enabled(),
            'securityDbDriftDetected' => Setting::get('db_drift_detected', '0') === '1',
            'dbDriftError' => $dbDriftError,
            'legacyJsonRepairAvailable' => $legacyJsonRepairAvailable,
            'legacyJsonRepairPlan' => $legacyJsonRepairPlan,
        ];
    }

    private function idleLogoutOptions(): array
    {
        return [
            240 => '4 hours',
            480 => '8 hours',
            720 => '12 hours',
            1440 => '1 day',
            10080 => '7 days',
            20160 => '14 days',
            43200 => '30 days',
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

    private function getMonetizationData(bool $bonusReviewOnly = false): array
    {
        if ($bonusReviewOnly) {
            return [
                'bonusMonetizationPane' => 'bonus-offers',
                'bonusOfferEditId' => 0,
            ] + \App\Service\BonusOfferService::getAdminData(0);
        }

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
            'referralCommissionPercent' => Setting::get('referral_commission_percent', '50', 'rewards'),
            'mixedPpdPercent' => Setting::get('mixed_ppd_percent', '30', 'rewards'),
            'mixedPpsPercent' => Setting::get('mixed_pps_percent', '30', 'rewards'),
            'retentionDays' => Setting::get('rewards_retention_days', '7', 'rewards'),
            'minimumWithdrawalAmount' => Setting::get('minimum_withdrawal_amount', '1.00', 'rewards'),
            'supportedWithdrawalMethods' => PayoutProcessorService::activeKeys(),
            'withdrawalProcessors' => PayoutProcessorService::definitions(false),
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
            'bonusMonetizationPane' => trim((string)($_GET['monetization_pane'] ?? '')),
            'bonusOfferEditId' => (int)($_GET['edit_bonus_offer'] ?? 0),
        ] + \App\Service\BonusOfferService::getAdminData((int)($_GET['edit_bonus_offer'] ?? 0));
    }

    private function getCronData(): array
    {
        $db = Database::getInstance()->getConnection();
        $lastRun = Setting::get('last_cron_run_timestamp', 0);
        \App\Service\Database\SchemaService::ensureTables(['cron_tasks'], false);

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
            'uploadReplaceEnabled' => Setting::get('upload_replace_enabled', '0'),
            'uploadAllowedExtensions' => Setting::get('upload_allowed_extensions', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,mp4,mp3,ipa,apk'),
            'downloadPageSaveFree' => Setting::get('download_page_save_free', '1'),
            'downloadPageSavePremium' => Setting::get('download_page_save_premium', '1'),
            'downloadPageSaveAdmin' => Setting::get('download_page_save_admin', '1'),
        ];
    }

    private function getLinkCheckerData(): array
    {
        return [
            'linkCheckerEnabled' => Setting::get('link_checker_enabled', '1'),
            'linkCheckerMaxLinks' => Setting::get('link_checker_max_links', '100'),
            'linkCheckerLinksPerSecond' => Setting::get('link_checker_links_per_second', '25'),
            'linkCheckerAllowCopyToAccount' => Setting::get('link_checker_allow_copy_to_account', '1'),
        ];
    }

    private function getTicketsData(): array
    {
        return [
            'ticketSupportInboxEmail' => Setting::get('ticket_support_inbox_email', ''),
            'ticketEmailsEnabled' => Setting::get('ticket_emails_enabled', '1'),
            'ticketNotifyAdminOnOpen' => Setting::get('ticket_notify_admin_on_open', '1'),
            'ticketNotifyUserOnOpen' => Setting::get('ticket_notify_user_on_open', '1'),
            'ticketNotifyAdminOnUserReply' => Setting::get('ticket_notify_admin_on_user_reply', '1'),
            'ticketNotifyUserOnStaffReply' => Setting::get('ticket_notify_user_on_staff_reply', '1'),
            'ticketNotifyUserOnClose' => Setting::get('ticket_notify_user_on_close', '1'),
            'ticketNotifyAdminOnContact' => Setting::get('ticket_notify_admin_on_contact', '1'),
            'ticketNotifyAdminOnAbuse' => Setting::get('ticket_notify_admin_on_abuse', '1'),
            'ticketNotifyAdminOnDmca' => Setting::get('ticket_notify_admin_on_dmca', '1'),
            'ticketWaitingUserRemindersEnabled' => Setting::get('ticket_waiting_user_reminders_enabled', '1'),
            'ticketWaitingUserReminderDays' => Setting::get('ticket_waiting_user_reminder_days', '3'),
            'ticketRateLimitSupportCreateUser' => Setting::get('ticket_rate_limit_support_create_user', '5'),
            'ticketRateLimitSupportCreateWindow' => Setting::get('ticket_rate_limit_support_create_window', '60'),
            'ticketRateLimitSupportCreateIp' => Setting::get('ticket_rate_limit_support_create_ip', '10'),
            'ticketRateLimitSupportReplyUser' => Setting::get('ticket_rate_limit_support_reply_user', '20'),
            'ticketRateLimitSupportReplyWindow' => Setting::get('ticket_rate_limit_support_reply_window', '60'),
            'ticketRateLimitSupportReplyIp' => Setting::get('ticket_rate_limit_support_reply_ip', '40'),
            'ticketRateLimitContactIp' => Setting::get('ticket_rate_limit_contact_ip', '6'),
            'ticketRateLimitContactWindow' => Setting::get('ticket_rate_limit_contact_window', '60'),
            'ticketRateLimitAbuseIp' => Setting::get('ticket_rate_limit_abuse_ip', '12'),
            'ticketRateLimitAbuseWindow' => Setting::get('ticket_rate_limit_abuse_window', '60'),
            'ticketRateLimitDmcaIp' => Setting::get('ticket_rate_limit_dmca_ip', '30'),
            'ticketRateLimitDmcaWindow' => Setting::get('ticket_rate_limit_dmca_window', '60'),
        ];
    }

    /**
     * Unified Save Entry Point
     */
    public function save()
    {
        $tab = $_POST['section'] ?? 'general';
        $monetizationAction = trim((string)($_POST['monetization_action'] ?? ''));
        $bonusReviewAction = $tab === 'monetization' && in_array($monetizationAction, ['approve_bonus_award', 'reject_bonus_award'], true);

        if ($bonusReviewAction) {
            $this->requireBonusOfferFinancialReviewAccess();
        } else {
            $this->requireConfigurationAccess();
        }
        $this->ensureDemoAdminReadOnly();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method not allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF mismatch");
        }

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
                    $monetizationPane = $this->resolveMonetizationReturnPane(
                        (string)($_POST['monetization_return'] ?? ''),
                        $monetizationAction
                    );
                    if ($monetizationPane !== '') {
                        $tab = 'monetization&monetization_pane=' . urlencode($monetizationPane);
                    }
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
                case 'link_checker':
                    $this->saveLinkCheckerSettings();
                    break;
                case 'tickets':
                    $this->saveTicketSettings();
                    break;
            }
        } catch (\RuntimeException $e) {
            Logger::error('Configuration save failed', [
                'tab' => $tab,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['config_errors'] = [$bonusReviewAction ? $e->getMessage() : 'The settings could not be saved. Review the form values and try again.'];
            if ($bonusReviewAction) {
                $tab = 'monetization&monetization_pane=' . urlencode(
                    $this->resolveMonetizationReturnPane(
                        (string)($_POST['monetization_return'] ?? ''),
                        $monetizationAction
                    ) ?: 'bonus-offers'
                );
            }
            $tab = $this->configurationReturnTab($tab);
            header("Location: /admin/configuration?tab=" . $tab);
            exit;
        }

        $_SESSION['config_success'] = true;
        $tab = $this->configurationReturnTab($tab);
        header("Location: /admin/configuration?tab=" . $tab);
        exit;
    }

    private function configurationReturnTab(string $tab): string
    {
        return match ($tab) {
            'captcha' => 'security&sec_tab=captcha',
            'security_features' => 'security',
            'email_template' => 'email',
            default => $tab,
        };
    }

    private function resolveMonetizationReturnPane(string $requestedPane, string $action): string
    {
        $requestedPane = trim($requestedPane);
        if (in_array($requestedPane, ['rewards', 'bonus-offers', 'ads', 'tiers'], true)) {
            return $requestedPane;
        }

        return match (trim($action)) {
            'rewards_settings' => 'rewards',
            'ads' => 'ads',
            'add_tier', 'delete_tier', 'load_example_tiers', 'update_tiers' => 'tiers',
            'save_bonus_offer', 'delete_bonus_offer', 'approve_bonus_award', 'reject_bonus_award' => 'bonus-offers',
            default => '',
        };
    }

    /**
     * triggerCron - Manual Heartbeat Execution
     */
    private function registerCoreCronTasks(\App\Service\CronManager $manager): void
    {
        $manager->register('cleanup', function() {
            return (new \App\Service\CleanupService())->runExpiredCleanup();
        });

        $manager->register('cf_sync', function() {
            return (new \App\Service\CloudflareSyncService())->sync();
        });

        $manager->register('rl_purge', function() {
            return \App\Service\RateLimiterService::cleanup(86400);
        });

        $manager->register('account_downgrade', function() {
            return (new \App\Service\AutomatedTaskService())->downgradeExpiredAccounts();
        });

        $manager->register('account_expiry', function() {
            return (new \App\Service\AutomatedTaskService())->sendExpiryReminders();
        });

        $manager->register('server_monitoring', function() {
            return (new \App\Service\AutomatedTaskService())->monitorServerHealth();
        });

        $manager->register('mail_queue', function() {
            return \App\Service\MailQueueService::processBatch();
        });

        $manager->register('payment_gateway_sync', function() {
            return \App\Service\PaymentService::processGatewaySyncQueue(25);
        });

        $manager->register('payment_cleanup', function() {
            return (new \App\Service\AutomatedTaskService())->cleanupStalePendingPayments(1440);
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

        $manager->register('db_health', function() {
            return (new \App\Service\Database\SchemaService())->sync(false);
        });

        $manager->register('log_purge', function() {
            return (new \App\Service\AutomatedTaskService())->purgeOldLogs();
        });

        $manager->register('file_purge', function() {
            return (new \App\Service\AutomatedTaskService())->processFilePurgeQueue(50);
        });

        $manager->register('storage_audit', function() {
            return (new \App\Service\AutomatedTaskService())->auditUserStorage(5);
        });

        $manager->register('security_purge', function() {
            return ['purged' => (new \App\Service\SecurityService())->purgeCache(30)];
        });

        $manager->register('refresh_stats', function() {
            $service = new \App\Service\DashboardService();
            $service->refreshSystemStats();
            $retention = (int)\App\Model\Setting::get('stats_history_retention_days', 30);
            $purged = $service->purgeOldHistory($retention);
            return ['status' => 'updated', 'purged' => $purged];
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
    }

    public function triggerCron()
    {
        $this->requireConfigurationAccess();
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

        $manager = new \App\Service\CronManager();
        $manager->sync();
        $this->registerCoreCronTasks($manager);

        // Execute all
        $results = $manager->run(true);
        if (($results['status'] ?? null) === 'skipped') {
            $_SESSION['config_errors'] = ['Cron is already running on this node. Wait for the current run to finish, then try again.'];
            header("Location: /admin/configuration?tab=cron");
            exit;
        }

        $_SESSION['config_success'] = true;
        header("Location: /admin/configuration?tab=cron");
        exit;
    }

    /**
     * testSmtpConnection - AJAX Verification
     */
    public function testSmtpConnection()
    {
        $this->requireConfigurationAccess();
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
            echo json_encode([
                'status' => 'error',
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
        exit;
    }

    /**
     * sendTestEmail - AJAX Verification
     */
    public function sendTestEmail()
    {
        $this->requireConfigurationAccess();
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
            } else {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'The SMTP server accepted the connection, but the test email was not sent.',
                ]);
            }
        } catch (\Exception $e) {
            Logger::error('SMTP test email send failed', [
                'target' => $target,
                'error' => $e->getMessage(),
            ]);
            echo json_encode([
                'status' => 'error',
                'message' => 'Test email failed: ' . $e->getMessage(),
            ]);
        }
        exit;
    }

    private function saveCronSettings(): void
    {
        $db = Database::getInstance()->getConnection();
        $intervals = $_POST['intervals'] ?? [];
        $before = [];
        $after = [];
        foreach ($intervals as $key => $mins) {
            $key = (string)$key;
            if (!preg_match('/^[a-z0-9_]+$/', $key)) {
                $_SESSION['config_errors'] = ['One of the cron task keys was invalid. Reload the page and try again.'];
                header("Location: /admin/configuration?tab=cron");
                exit;
            }

            if (filter_var($mins, FILTER_VALIDATE_INT) === false) {
                $_SESSION['config_errors'] = ['Cron frequencies must be whole-minute values.'];
                header("Location: /admin/configuration?tab=cron");
                exit;
            }

            $mins = (int)$mins;
            if ($mins < self::MIN_CRON_INTERVAL_MINS || $mins > self::MAX_CRON_INTERVAL_MINS) {
                $_SESSION['config_errors'] = ['Cron frequencies must stay between ' . self::MIN_CRON_INTERVAL_MINS . ' minute and ' . self::MAX_CRON_INTERVAL_MINS . ' minutes (7 days).'];
                header("Location: /admin/configuration?tab=cron");
                exit;
            }

            $stmt = $db->prepare("SELECT interval_mins FROM cron_tasks WHERE task_key = ?");
            $stmt->execute([$key]);
            $oldMins = $stmt->fetchColumn();
            if ($oldMins === false) {
                $_SESSION['config_errors'] = ['One of the cron tasks no longer exists. Reload the page and try again.'];
                header("Location: /admin/configuration?tab=cron");
                exit;
            }
            $before[(string)$key] = (string)$oldMins;
            $after[(string)$key] = (string)$mins;
        }

        $this->runConfigurationWriteTransaction(function () use ($db, $before, $after): void {
            foreach ($after as $key => $mins) {
                $oldMins = $before[$key] ?? null;
                if ($oldMins == $mins) {
                    continue;
                }

                $upd = $db->prepare("UPDATE cron_tasks SET interval_mins = ? WHERE task_key = ?");
                $upd->execute([(int)$mins, $key]);
                $this->logActivity('update_cron_interval', $key, null, "Interval: {$oldMins}m -> {$mins}m");
            }

            $this->logConfigChange('cron intervals', $before, $after);
        });
    }

    private function saveGeneralSettings(): bool
    {
        $settingKeys = [
            'app.name',
            'allow_registrations',
            'demo_mode',
            'demo_admin_user_id',
            'maintenance_mode',
            'require_email_verification',
            'reserved_usernames',
            'admin_notification_email',
            'show_powered_by_footer',
            'video.ffmpeg_enabled',
            'video.ffmpeg_path',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);

        $rules = [
            'app_name' => 'required',
            'admin_notification_email' => 'email'
        ];

        if (!$this->validate($_POST, $rules)) {
            header("Location: /admin/configuration?tab=general");
            return false;
        }

        $this->runConfigurationWriteTransaction(function () use ($before, $settingKeys): void {
            $this->updateSetting('app.name', $_POST['app_name'] ?? 'Fyuhls', 'general');
            $this->updateSetting('allow_registrations', isset($_POST['allow_registrations']) ? '1' : '0', 'general');

            $turningDemoOn = isset($_POST['demo_mode']);
            $this->updateSetting('demo_mode', $turningDemoOn ? '1' : '0', 'general');

            // If demo mode is being enabled and no demo admin is designated yet, auto-assign the current admin.
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
            if (array_key_exists('ffmpeg_enabled', $_POST)) {
                $this->updateSetting('video.ffmpeg_enabled', isset($_POST['ffmpeg_enabled']) ? '1' : '0', 'general');
            }
            if (array_key_exists('ffmpeg_path', $_POST)) {
                $this->updateSetting('video.ffmpeg_path', trim((string)($_POST['ffmpeg_path'] ?? '')), 'general');
            }
            $this->logConfigChange('general', $before, $this->captureSettingSnapshot($settingKeys));
        });
        return true;
    }

    private function saveEmailSettings(): bool
    {
        $settingKeys = [
            'email_smtp_host',
            'email_smtp_port',
            'email_from_address',
            'email_secure_method',
            'email_smtp_requires_auth',
            'email_smtp_auth_username',
            'email_smtp_auth_password' => 'encrypted',
            'email_limit_per_minute',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);

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

        $this->runConfigurationWriteTransaction(function () use ($smtpHost, $smtpPort, $before, $settingKeys): void {
            $this->updateSetting('email_smtp_host', $smtpHost, 'email');
            $this->updateSetting('email_smtp_port', (string)$smtpPort, 'email');
            $this->updateSetting('email_from_address', trim((string)($_POST['email_from_address'] ?? '')), 'email');
            $this->updateSetting('email_secure_method', $this->normalizeEmailSecureMethod($_POST['email_secure_method'] ?? 'none'), 'email');
            $this->updateSetting('email_smtp_requires_auth', isset($_POST['email_smtp_requires_auth']) ? '1' : '0', 'email');
            $this->updateSetting('email_smtp_auth_username', trim((string)($_POST['email_smtp_auth_username'] ?? '')), 'email');

            if (!empty($_POST['email_smtp_auth_password'])) {
                $this->setEncryptedSetting('email_smtp_auth_password', (string)$_POST['email_smtp_auth_password'], 'email');
            }

            $this->updateSetting('email_limit_per_minute', (string)max(1, (int)($_POST['email_limit_per_minute'] ?? 20)), 'email');
            $this->logConfigChange('email', $before, $this->captureSettingSnapshot($settingKeys));
        });
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
            $this->runConfigurationWriteTransaction(function () use ($db, $templateKey, $subject, $body): void {
                $stmt = $db->prepare("
                    INSERT INTO email_templates (template_key, subject, body)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        subject = VALUES(subject),
                        body = VALUES(body)
                ");
                if ($stmt->execute([$templateKey, $subject, $body])) {
                    $this->logActivity('update_email_template', $templateKey, null, "Subject: $subject");
                }
            });
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
        $settingKeys = [
            'captcha_site_key',
            'captcha_secret_key' => 'encrypted',
            'captcha_download_guest',
            'captcha_download_free',
            'captcha_report_file',
            'captcha_contact',
            'captcha_dmca',
            'captcha_register',
            'captcha_user_login',
            'captcha_link_checker',
            'captcha_admin_login',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);

        $this->runConfigurationWriteTransaction(function () use ($before, $settingKeys): void {
            $this->updateSetting('captcha_site_key', $_POST['captcha_site_key'] ?? '', 'captcha');
            if (!empty($_POST['captcha_secret_key'])) {
                $this->setEncryptedSetting('captcha_secret_key', (string)$_POST['captcha_secret_key'], 'captcha');
            }

            $captchaKeys = ['captcha_download_guest','captcha_download_free','captcha_report_file','captcha_contact','captcha_dmca','captcha_register','captcha_user_login','captcha_link_checker'];
            foreach ($captchaKeys as $ck) {
                $this->updateSetting($ck, isset($_POST[$ck]) ? '1' : '0', 'captcha');
            }
            $this->updateSetting('captcha_admin_login', isset($_POST['captcha_user_login']) ? '1' : '0', 'captcha');
            $this->logConfigChange('captcha', $before, $this->captureSettingSnapshot($settingKeys));
        });
    }

    private function saveLinkCheckerSettings(): void
    {
        $settingKeys = [
            'link_checker_enabled',
            'link_checker_max_links',
            'link_checker_links_per_second',
            'link_checker_allow_copy_to_account',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);
        $this->runConfigurationWriteTransaction(function () use ($before, $settingKeys): void {
            $this->updateSetting('link_checker_enabled', isset($_POST['link_checker_enabled']) ? '1' : '0', 'link_checker');
            $maxLinks = max(1, min(1000, (int)($_POST['link_checker_max_links'] ?? 100)));
            $this->updateSetting('link_checker_max_links', (string)$maxLinks, 'link_checker');
            $linksPerSecond = max(1, min(250, (int)($_POST['link_checker_links_per_second'] ?? 25)));
            $this->updateSetting('link_checker_links_per_second', (string)$linksPerSecond, 'link_checker');
            $this->updateSetting('link_checker_allow_copy_to_account', isset($_POST['link_checker_allow_copy_to_account']) ? '1' : '0', 'link_checker');
            $this->logConfigChange('link checker', $before, $this->captureSettingSnapshot($settingKeys));
        });
    }

    private function saveTicketSettings(): void
    {
        $settingKeys = [
            'ticket_support_inbox_email',
            'ticket_emails_enabled',
            'ticket_notify_admin_on_open',
            'ticket_notify_user_on_open',
            'ticket_notify_admin_on_user_reply',
            'ticket_notify_user_on_staff_reply',
            'ticket_notify_user_on_close',
            'ticket_notify_admin_on_contact',
            'ticket_notify_admin_on_abuse',
            'ticket_notify_admin_on_dmca',
            'ticket_waiting_user_reminders_enabled',
            'ticket_waiting_user_reminder_days',
            'ticket_rate_limit_support_create_user',
            'ticket_rate_limit_support_create_window',
            'ticket_rate_limit_support_create_ip',
            'ticket_rate_limit_support_reply_user',
            'ticket_rate_limit_support_reply_window',
            'ticket_rate_limit_support_reply_ip',
            'ticket_rate_limit_contact_ip',
            'ticket_rate_limit_contact_window',
            'ticket_rate_limit_abuse_ip',
            'ticket_rate_limit_abuse_window',
            'ticket_rate_limit_dmca_ip',
            'ticket_rate_limit_dmca_window',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);

        $supportInboxEmail = trim((string)($_POST['ticket_support_inbox_email'] ?? ''));
        if ($supportInboxEmail !== '' && filter_var($supportInboxEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Support inbox email must be a valid email address.');
        }

        $this->runConfigurationWriteTransaction(function () use ($supportInboxEmail, $before, $settingKeys): void {
            $this->updateSetting('ticket_support_inbox_email', $supportInboxEmail, 'tickets');
            $this->updateSetting('ticket_emails_enabled', isset($_POST['ticket_emails_enabled']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_admin_on_open', isset($_POST['ticket_notify_admin_on_open']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_user_on_open', isset($_POST['ticket_notify_user_on_open']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_admin_on_user_reply', isset($_POST['ticket_notify_admin_on_user_reply']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_user_on_staff_reply', isset($_POST['ticket_notify_user_on_staff_reply']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_user_on_close', isset($_POST['ticket_notify_user_on_close']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_admin_on_contact', isset($_POST['ticket_notify_admin_on_contact']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_admin_on_abuse', isset($_POST['ticket_notify_admin_on_abuse']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_notify_admin_on_dmca', isset($_POST['ticket_notify_admin_on_dmca']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_waiting_user_reminders_enabled', isset($_POST['ticket_waiting_user_reminders_enabled']) ? '1' : '0', 'tickets');
            $this->updateSetting('ticket_waiting_user_reminder_days', (string)max(1, min(30, (int)($_POST['ticket_waiting_user_reminder_days'] ?? 3))), 'tickets');

            $limitFields = [
                'ticket_rate_limit_support_create_user' => [1, 100],
                'ticket_rate_limit_support_create_window' => [1, 1440],
                'ticket_rate_limit_support_create_ip' => [1, 250],
                'ticket_rate_limit_support_reply_user' => [1, 500],
                'ticket_rate_limit_support_reply_window' => [1, 1440],
                'ticket_rate_limit_support_reply_ip' => [1, 1000],
                'ticket_rate_limit_contact_ip' => [1, 250],
                'ticket_rate_limit_contact_window' => [1, 1440],
                'ticket_rate_limit_abuse_ip' => [1, 500],
                'ticket_rate_limit_abuse_window' => [1, 1440],
                'ticket_rate_limit_dmca_ip' => [1, 2000],
                'ticket_rate_limit_dmca_window' => [1, 1440],
            ];

            foreach ($limitFields as $key => [$min, $max]) {
                $value = max($min, min($max, (int)($_POST[$key] ?? $min)));
                $this->updateSetting($key, (string)$value, 'tickets');
            }
            $this->logConfigChange('tickets', $before, $this->captureSettingSnapshot($settingKeys));
        });
    }

    private function saveDownloadSettings(): void
    {
        $settingKeys = [
            'require_account_to_download',
            'blocked_download_countries',
            'track_current_downloads',
            'remote_url_background',
            'cdn_download_redirects_enabled',
            'cdn_download_base_url',
            'streaming_support_enabled',
            'nginx_completion_log_path',
            'nginx_completion_retention_days',
            'nginx_completion_max_lines_per_run',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);
        $this->runConfigurationWriteTransaction(function () use ($before, $settingKeys): void {
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
            $this->logConfigChange('downloads', $before, $this->captureSettingSnapshot($settingKeys));
        });
    }

    private function saveUploadSettings(): void
    {
        $settingKeys = [
            'upload_concurrent',
            'upload_concurrent_limit',
            'upload_hide_popup',
            'upload_append_filename',
            'upload_chunking_enabled',
            'upload_chunk_size_mb',
            'upload_login_required',
            'upload_detect_duplicates',
            'upload_replace_enabled',
            'upload_allowed_extensions',
            'download_page_save_free',
            'download_page_save_premium',
            'download_page_save_admin',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);
        $this->runConfigurationWriteTransaction(function () use ($before, $settingKeys): void {
            $this->updateSetting('upload_concurrent', isset($_POST['upload_concurrent']) ? '1' : '0', 'uploads');
            $this->updateSetting('upload_concurrent_limit', $_POST['upload_concurrent_limit'] ?? '2', 'uploads');
            $this->updateSetting('upload_hide_popup', isset($_POST['upload_hide_popup']) ? '1' : '0', 'uploads');
            $this->updateSetting('upload_append_filename', isset($_POST['upload_append_filename']) ? '1' : '0', 'uploads');
            $this->updateSetting('upload_chunking_enabled', isset($_POST['upload_chunking_enabled']) ? '1' : '0', 'uploads');
            $this->updateSetting('upload_chunk_size_mb', $_POST['upload_chunk_size_mb'] ?? '100', 'uploads');
            $this->updateSetting('upload_login_required', isset($_POST['upload_login_required']) ? '1' : '0', 'uploads');
            $this->updateSetting('upload_detect_duplicates', isset($_POST['upload_detect_duplicates']) ? '1' : '0', 'uploads');
            $this->updateSetting('upload_replace_enabled', isset($_POST['upload_replace_enabled']) ? '1' : '0', 'uploads');
            $this->updateSetting('upload_allowed_extensions', $_POST['upload_allowed_extensions'] ?? '', 'uploads');
            $this->updateSetting('download_page_save_free', isset($_POST['download_page_save_free']) ? '1' : '0', 'uploads');
            $this->updateSetting('download_page_save_premium', isset($_POST['download_page_save_premium']) ? '1' : '0', 'uploads');
            $this->updateSetting('download_page_save_admin', isset($_POST['download_page_save_admin']) ? '1' : '0', 'uploads');
            $this->logConfigChange('uploads', $before, $this->captureSettingSnapshot($settingKeys));
        });
    }

    private function saveMonetizationSettings(): void
    {
        $db = Database::getInstance()->getConnection();
        $action = $_POST['monetization_action'] ?? 'ads';

        if ($action === 'rewards_settings') {
            $settingKeys = [
                'rewards_enabled',
                'affiliate_enabled',
                'enabled_models',
                'global_model_status',
                'pps_commission_percent',
                'referral_commission_percent',
                'affiliate_hold_days',
                'mixed_ppd_percent',
                'mixed_pps_percent',
                'ppd_ip_reward_limit',
                'ppd_min_download_percent',
                'ppd_max_earn_ip',
                'ppd_max_earn_file',
                'ppd_max_earn_user',
                'ppd_only_guests_count',
                'ppd_min_file_size',
                'ppd_max_file_size',
                'ppd_reward_vpn',
                'rewards_retention_days',
                'minimum_withdrawal_amount',
                'rewards_min_video_watch_percent',
                'rewards_min_video_watch_seconds',
                'supported_withdrawal_methods',
                'withdrawal_method_definitions',
                'payment_stripe_enabled',
                'payment_stripe_secret_key' => 'encrypted',
                'payment_stripe_webhook_secret' => 'encrypted',
                'payment_paypal_enabled',
                'payment_paypal_client_id',
                'payment_paypal_client_secret' => 'encrypted',
                'payment_paypal_webhook_id',
                'payment_paypal_sandbox',
            ];
            $before = $this->captureSettingSnapshot($settingKeys);
            $rewardsEnabled = isset($_POST['rewards_enabled']) ? '1' : '0';
            $affiliateEnabled = $rewardsEnabled === '1' && isset($_POST['affiliate_enabled']) ? '1' : '0';
            $enabledModels = array_values(array_intersect(['ppd', 'pps', 'mixed'], $_POST['enabled_models'] ?? []));
            $minimumWithdrawalAmount = max(0, round((float)($_POST['minimum_withdrawal_amount'] ?? 1), 2));
            $rewardVpn = Setting::get('block_vpn_traffic', '0', 'security') === '1' ? '0' : ((($_POST['ppd_reward_vpn'] ?? '0') === '1') ? '1' : '0');

            $processors = PayoutProcessorService::parseSubmittedDefinitions($_POST);
            $activeProcessorKeys = array_values(array_map(
                static fn(array $definition): string => (string)$definition['key'],
                array_filter($processors, static fn(array $definition): bool => !empty($definition['enabled']))
            ));

            $db->beginTransaction();
            try {
                $this->updateSettingWithConnection($db, 'rewards_enabled', $rewardsEnabled, 'rewards');
                $this->updateSettingWithConnection($db, 'affiliate_enabled', $affiliateEnabled, 'rewards');
                $this->updateSettingWithConnection($db, 'enabled_models', implode(',', $enabledModels), 'rewards');
                $this->updateSettingWithConnection($db, 'global_model_status', empty($enabledModels) ? 'disabled' : 'enabled', 'rewards');
                $this->updateSettingWithConnection($db, 'pps_commission_percent', (string) max(0, min(100, (int) ($_POST['pps_commission_percent'] ?? 50))), 'rewards');
                $referralPercent = (string) max(0, min(100, (int) ($_POST['referral_commission_percent'] ?? 50)));
                $this->updateSettingWithConnection($db, 'referral_commission_percent', $referralPercent, 'rewards');
                $this->updateSettingWithConnection($db, 'affiliate_hold_days', (string) max(0, min(90, (int) ($_POST['affiliate_hold_days'] ?? 5))), 'rewards');
                $this->updateSettingWithConnection($db, 'mixed_ppd_percent', (string) (int) ($_POST['mixed_ppd_percent'] ?? 30), 'rewards');
                $this->updateSettingWithConnection($db, 'mixed_pps_percent', (string) max(0, min(100, (int) ($_POST['mixed_pps_percent'] ?? 30))), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_ip_reward_limit', (string) max(1, (int) ($_POST['ppd_ip_reward_limit'] ?? 1)), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_min_download_percent', (string) min(100, max(0, (int) ($_POST['ppd_min_download_percent'] ?? 0))), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_max_earn_ip', (string) (float) ($_POST['ppd_max_earn_ip'] ?? 0), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_max_earn_file', (string) (float) ($_POST['ppd_max_earn_file'] ?? 0), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_max_earn_user', (string) (float) ($_POST['ppd_max_earn_user'] ?? 0), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_only_guests_count', isset($_POST['ppd_only_guests_count']) && $_POST['ppd_only_guests_count'] === '1' ? '1' : '0', 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_min_file_size', (string) ((float) ($_POST['ppd_min_file_size'] ?? 0) * 1024 * 1024), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_max_file_size', (string) ((float) ($_POST['ppd_max_file_size'] ?? 0) * 1024 * 1024), 'rewards');
                $this->updateSettingWithConnection($db, 'ppd_reward_vpn', $rewardVpn, 'rewards');
                $this->updateSettingWithConnection($db, 'rewards_retention_days', (string) max(1, (int) ($_POST['rewards_retention_days'] ?? 7)), 'rewards');
                $this->updateSettingWithConnection($db, 'minimum_withdrawal_amount', number_format($minimumWithdrawalAmount, 2, '.', ''), 'rewards');
                $this->updateSettingWithConnection($db, 'rewards_min_video_watch_percent', (string) min(100, max(0, (int) ($_POST['rewards_min_video_watch_percent'] ?? 80))), 'rewards');
                $this->updateSettingWithConnection($db, 'rewards_min_video_watch_seconds', (string) max(0, (int) ($_POST['rewards_min_video_watch_seconds'] ?? 30)), 'rewards');
                $this->updateSettingWithConnection($db, 'withdrawal_method_definitions', PayoutProcessorService::encodeDefinitions($processors), 'rewards');
                $this->updateSettingWithConnection($db, 'supported_withdrawal_methods', implode(',', $activeProcessorKeys), 'rewards');
                $this->updateSettingWithConnection($db, 'payment_stripe_enabled', isset($_POST['payment_stripe_enabled']) ? '1' : '0', 'payments');
                if (!empty($_POST['payment_stripe_secret_key'])) {
                    $this->setEncryptedSettingWithConnection($db, 'payment_stripe_secret_key', (string)$_POST['payment_stripe_secret_key'], 'payments');
                }
                if (!empty($_POST['payment_stripe_webhook_secret'])) {
                    $this->setEncryptedSettingWithConnection($db, 'payment_stripe_webhook_secret', (string)$_POST['payment_stripe_webhook_secret'], 'payments');
                }
                $this->updateSettingWithConnection($db, 'payment_paypal_enabled', isset($_POST['payment_paypal_enabled']) ? '1' : '0', 'payments');
                $this->updateSettingWithConnection($db, 'payment_paypal_client_id', trim((string)($_POST['payment_paypal_client_id'] ?? '')), 'payments');
                if (!empty($_POST['payment_paypal_client_secret'])) {
                    $this->setEncryptedSettingWithConnection($db, 'payment_paypal_client_secret', (string)$_POST['payment_paypal_client_secret'], 'payments');
                }
                $this->updateSettingWithConnection($db, 'payment_paypal_webhook_id', trim((string)($_POST['payment_paypal_webhook_id'] ?? '')), 'payments');
                $this->updateSettingWithConnection($db, 'payment_paypal_sandbox', isset($_POST['payment_paypal_sandbox']) ? '1' : '0', 'payments');
                $this->logConfigChangeWithConnection($db, 'monetization rewards', $before, $this->captureSettingSnapshot($settingKeys));
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        } elseif ($action === 'ads') {
            $settingKeys = array_map(static fn(string $key): string => 'ad_' . $key, self::ALLOWED_AD_SLOT_KEYS);
            $before = $this->captureSettingSnapshot($settingKeys);
            $ads = $_POST['ads'] ?? [];
            foreach ($ads as $key => $code) {
                if (!in_array((string)$key, self::ALLOWED_AD_SLOT_KEYS, true)) {
                    continue;
                }
                if (strlen((string)$code) > self::MAX_AD_CODE_LENGTH) {
                    $_SESSION['config_errors'] = ["Ad placement code is too large. Keep each ad block under " . self::MAX_AD_CODE_LENGTH . " characters."];
                    header("Location: /admin/configuration?tab=monetization&monetization_pane=ads");
                    exit;
                }
            }
            $this->runConfigurationWriteTransaction(function () use ($ads, $before, $settingKeys): void {
                foreach ($ads as $key => $code) {
                    if (!in_array((string)$key, self::ALLOWED_AD_SLOT_KEYS, true)) {
                        continue;
                    }
                    $this->updateSetting("ad_{$key}", (string)$code, 'ads');
                }
                $this->logConfigChange('monetization ads', $before, $this->captureSettingSnapshot($settingKeys));
            });
        } elseif ($action === 'add_tier') {
            $name = trim($_POST['new_name'] ?? '');
            $rate = (float)($_POST['new_rate'] ?? 0);
            $countries = array_map('trim', explode(',', $_POST['new_countries'] ?? ''));

            if ($name) {
                $this->runConfigurationWriteTransaction(function () use ($db, $name, $rate, $countries): void {
                    $stmt = $db->prepare("INSERT INTO ppd_tiers (name, rate_per_1000) VALUES (?, ?)");
                    $stmt->execute([$name, $rate]);
                    $tierId = $db->lastInsertId();

                    if (!empty($countries) && $countries[0] !== '') {
                        $cStmt = $db->prepare("INSERT IGNORE INTO ppd_tier_countries (tier_id, country_code) VALUES (?, ?)");
                        foreach ($countries as $code) {
                            $code = strtoupper(substr($code, 0, 2));
                            if ($code) {
                                $cStmt->execute([$tierId, $code]);
                            }
                        }
                    }
                    $this->logActivity('add_ppd_tier', $name, null, "Rate: $rate");
                });
            }
        } elseif ($action === 'delete_tier') {
            $id = (int)$_POST['tier_id'];
            $this->runConfigurationWriteTransaction(function () use ($db, $id): void {
                $db->prepare("DELETE FROM ppd_tiers WHERE id = ?")->execute([$id]);
                $this->logActivity('delete_ppd_tier', (string)$id);
            });
        } elseif ($action === 'load_example_tiers') {
            $hasAnyTiers = (bool)$db->query("SELECT 1 FROM ppd_tiers LIMIT 1")->fetchColumn();
            if (!$hasAnyTiers) {
                $starterTiers = [
                    ['Tier 1', 5.00, ['US', 'CA', 'GB', 'DE', 'FR', 'AU', 'NL', 'SE', 'NO', 'DK']],
                    ['Tier 2', 2.00, ['BR', 'MX', 'PL', 'TR', 'RU', 'AR', 'CL', 'RO', 'HU', 'ZA']],
                    ['Tier 3', 0.50, ['IN', 'PH', 'ID', 'VN', 'TH', 'PK', 'BD', 'EG', 'NG', 'MA']],
                ];

                $this->runConfigurationWriteTransaction(function () use ($db, $starterTiers): void {
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
                });
            }
        } elseif ($action === 'update_tiers') {
            if (!empty($_POST['tiers']) && is_array($_POST['tiers'])) {
                $this->runConfigurationWriteTransaction(function () use ($db): void {
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
                                if ($code) {
                                    $cStmt->execute([$id, $code]);
                                }
                            }
                        }
                    }
                    $this->logActivity('update_ppd_tiers', 'all');
                });
            }
        } elseif ($action === 'save_bonus_offer') {
            $offerName = trim((string)($_POST['name'] ?? ''));
            $offerId = \App\Service\BonusOfferService::saveOfferFromInput(
                $_POST,
                (int)(Auth::id() ?? 0),
                static function (\PDO $db, int $savedOfferId) use ($offerName): void {
                    StaffActivityService::logWithConnection(
                        $db,
                        'save_bonus_offer',
                        'bonus_offer',
                        $savedOfferId,
                        $offerName
                    );
                }
            );
        } elseif ($action === 'delete_bonus_offer') {
            $offerId = (int)($_POST['offer_id'] ?? 0);
            if ($offerId > 0) {
                \App\Service\BonusOfferService::deleteOffer(
                    $offerId,
                    static function (\PDO $db, int $deletedOfferId): void {
                        StaffActivityService::logWithConnection(
                            $db,
                            'delete_bonus_offer',
                            'bonus_offer',
                            $deletedOfferId
                        );
                    }
                );
            }
        } elseif ($action === 'approve_bonus_award') {
            $awardId = (int)($_POST['award_id'] ?? 0);
            if ($awardId > 0) {
                $reviewNote = trim((string)($_POST['review_note'] ?? ''));
                \App\Service\BonusOfferService::reviewAward(
                    $awardId,
                    'approve',
                    (int)(Auth::id() ?? 0),
                    $reviewNote,
                    static function (\PDO $db, array $award, string $result): void {
                        StaffActivityService::logWithConnection(
                            $db,
                            $result === 'credited' ? 'approve_bonus_award' : 'reject_bonus_award',
                            'bonus_award',
                            (int)($award['id'] ?? 0),
                            $result === 'credited' ? '' : 'User was no longer eligible when approval was attempted.'
                        );
                    }
                );
            }
        } elseif ($action === 'reject_bonus_award') {
            $awardId = (int)($_POST['award_id'] ?? 0);
            if ($awardId > 0) {
                \App\Service\BonusOfferService::reviewAward(
                    $awardId,
                    'reject',
                    (int)(Auth::id() ?? 0),
                    trim((string)($_POST['review_note'] ?? '')),
                    static function (\PDO $db, array $award): void {
                        StaffActivityService::logWithConnection(
                            $db,
                            'reject_bonus_award',
                            'bonus_award',
                            (int)($award['id'] ?? 0)
                        );
                    }
                );
            }
        }
    }

    private function saveSecurityFeatureSettings(): void
    {
        $settingKeys = [
            'two_factor_enabled',
            '2fa_enabled',
            '2fa_enforce_date',
            'rate_limit_2fa_setup',
            'rate_limit_2fa_verify',
            'rate_limit_2fa_recovery',
            'admin_idle_logout_minutes',
            'moderator_idle_logout_minutes',
            'user_idle_logout_minutes',
            'remember_me_enabled',
        ];
        $before = $this->captureSettingSnapshot($settingKeys);
        $this->runConfigurationWriteTransaction(function () use ($before, $settingKeys): void {
            $this->updateSetting('two_factor_enabled', isset($_POST['two_factor_enabled']) ? '1' : '0', 'security');
            $this->updateSetting('2fa_enabled', isset($_POST['two_factor_enabled']) ? '1' : '0', 'security');
            $this->updateSetting('2fa_enforce_date', trim((string) ($_POST['2fa_enforce_date'] ?? '')), 'security');
            $setupLimit = isset($_POST['rate_limit_2fa_setup']) ? (int)$_POST['rate_limit_2fa_setup'] : 5;
            $verifyLimit = isset($_POST['rate_limit_2fa_verify']) ? (int)$_POST['rate_limit_2fa_verify'] : 5;
            $recoveryLimit = isset($_POST['rate_limit_2fa_recovery']) ? (int)$_POST['rate_limit_2fa_recovery'] : 5;
            $this->updateSetting('rate_limit_2fa_setup', (string)($setupLimit > 0 ? $setupLimit : 5), 'security');
            $this->updateSetting('rate_limit_2fa_verify', (string)($verifyLimit > 0 ? $verifyLimit : 5), 'security');
            $this->updateSetting('rate_limit_2fa_recovery', (string)($recoveryLimit > 0 ? $recoveryLimit : 5), 'security');
            $this->updateSetting('admin_idle_logout_minutes', (string)$this->normalizeIdleLogoutMinutes($_POST['admin_idle_logout_minutes'] ?? null, 'admin'), 'security');
            $this->updateSetting('moderator_idle_logout_minutes', (string)$this->normalizeIdleLogoutMinutes($_POST['moderator_idle_logout_minutes'] ?? null, 'moderator'), 'security');
            $this->updateSetting('user_idle_logout_minutes', (string)$this->normalizeIdleLogoutMinutes($_POST['user_idle_logout_minutes'] ?? null, 'user'), 'security');
            $rememberMeEnabled = isset($_POST['remember_me_enabled']);
            $this->updateSetting('remember_me_enabled', $rememberMeEnabled ? '1' : '0', 'security');
            if (!$rememberMeEnabled) {
                RememberMeService::revokeAllTokensWithConnection(Database::getInstance()->getConnection());
            }
            $this->logConfigChange('security features', $before, $this->captureSettingSnapshot($settingKeys));
        });
    }

    private function normalizeIdleLogoutMinutes($value, string $role): int
    {
        $minutes = (int)$value;
        if (!in_array($minutes, self::ALLOWED_IDLE_LOGOUT_MINUTES, true)) {
            return \App\Core\Auth::defaultIdleLogoutMinutesForRole($role);
        }

        return $minutes;
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
        $settingKeys = array_merge($scopeConfig['boolean'], $scopeConfig['string']);
        $before = $this->captureSettingSnapshot($settingKeys);

        if (
            in_array('seo_custom_head_code', $scopeConfig['string'], true)
            && strlen(trim((string)($_POST['seo_custom_head_code'] ?? ''))) > self::MAX_CUSTOM_HEAD_CODE_LENGTH
        ) {
            $_SESSION['config_errors'] = ["Custom Head Code is too large. Keep it under " . self::MAX_CUSTOM_HEAD_CODE_LENGTH . " characters."];
            header("Location: /admin/configuration?tab=seo&seo_tab=" . urlencode($scope));
            exit;
        }

        $this->runConfigurationWriteTransaction(function () use ($scopeConfig, $before, $settingKeys, $scope): void {
            foreach ($scopeConfig['boolean'] as $key) {
                $this->updateSetting($key, isset($_POST[$key]) ? '1' : '0', 'seo');
            }

            foreach ($scopeConfig['string'] as $key) {
                $value = trim((string)($_POST[$key] ?? ''));
                $this->updateSetting($key, $value, 'seo');
            }

            $this->logConfigChange('seo ' . $scope, $before, $this->captureSettingSnapshot($settingKeys), [
                'scope' => $scope,
            ]);
        });
    }

    private function activeConfigurationTransaction(): ?\PDO
    {
        try {
            $db = Database::getInstance()->getConnection();
        } catch (\Throwable $e) {
            return null;
        }

        return $db->inTransaction() ? $db : null;
    }

    private function runConfigurationWriteTransaction(callable $callback): void
    {
        $db = Database::getInstance()->getConnection();
        $startedTransaction = !$db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $callback($db);
            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
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

        $normalizedValue = strtolower(trim($value));
        if ($normalizedValue === '1') {
            return '[enabled]';
        }
        if ($normalizedValue === '0') {
            return '[disabled]';
        }

        if (
            str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'private_key')
        ) {
            return $value === '' ? '[not configured]' : '[configured secret]';
        }

        if (
            str_contains($key, 'client_id')
            || str_contains($key, 'webhook_id')
            || str_contains($key, 'api_key')
            || str_contains($key, 'public_key')
        ) {
            $suffix = strlen($value) > 6 ? substr($value, -6) : $value;
            return '[updated identifier ending ' . $suffix . ']';
        }

        if (strlen($value) > 500) {
            return '[long value, ' . strlen($value) . ' bytes]';
        }

        return $value;
    }

    private function captureSettingSnapshot(array $settings): array
    {
        $snapshot = [];
        foreach ($settings as $key => $mode) {
            if (is_int($key)) {
                $key = (string)$mode;
                $mode = 'plain';
            }

            $rawValue = $mode === 'encrypted'
                ? (string)Setting::getEncrypted((string)$key, '')
                : (string)Setting::get((string)$key, '');
            $snapshot[(string)$key] = $this->summarizeSettingValueForLog((string)$key, $rawValue);
        }

        return $snapshot;
    }

    private function logConfigChange(string $section, array $before, array $after, array $extra = []): void
    {
        $changedKeys = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changedKeys[] = (string)$key;
            }
        }

        if ($changedKeys === []) {
            return;
        }

        $changedBefore = [];
        $changedAfter = [];
        foreach ($changedKeys as $key) {
            $changedBefore[$key] = $before[$key] ?? null;
            $changedAfter[$key] = $after[$key] ?? null;
        }

        $db = $this->activeConfigurationTransaction();
        if ($db !== null) {
            $this->logConfigChangeWithConnection($db, $section, $before, $after, $extra);
            return;
        }

        StaffActivityService::log(
            'config_updated',
            'config',
            null,
            'Updated ' . $section . ' settings.',
            array_merge([
                'section' => $section,
                'changed_keys' => $changedKeys,
                'before' => $changedBefore,
                'after' => $changedAfter,
            ], $extra)
        );
    }

    private function logConfigChangeWithConnection(\PDO $db, string $section, array $before, array $after, array $extra = []): void
    {
        $changedKeys = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changedKeys[] = (string)$key;
            }
        }

        if ($changedKeys === []) {
            return;
        }

        $changedBefore = [];
        $changedAfter = [];
        foreach ($changedKeys as $key) {
            $changedBefore[$key] = $before[$key] ?? null;
            $changedAfter[$key] = $after[$key] ?? null;
        }

        StaffActivityService::logWithConnection(
            $db,
            'config_updated',
            'config',
            null,
            'Updated ' . $section . ' settings.',
            array_merge([
                'section' => $section,
                'changed_keys' => $changedKeys,
                'before' => $changedBefore,
                'after' => $changedAfter,
            ], $extra)
        );
    }

    private function updateSetting(string $key, string $value, string $group): void
    {
        $oldValue = Setting::get($key);
        if ($oldValue !== $value) {
            $db = $this->activeConfigurationTransaction();
            if ($db !== null) {
                $this->updateSettingWithConnection($db, $key, $value, $group);
                return;
            }
            Setting::set($key, $value, $group);
            $this->logActivity('update_setting', $key, null, $this->summarizeSettingValueForLog($key, $value));
        }
    }

    private function updateSettingWithConnection(\PDO $db, string $key, string $value, string $group): void
    {
        $oldValue = Setting::get($key);
        if ($oldValue !== $value) {
            Setting::set($key, $value, $group);
            StaffActivityService::logWithConnection(
                $db,
                'update_setting',
                $key,
                null,
                $this->summarizeSettingValueForLog($key, $value)
            );
        }
    }

    private function setEncryptedSettingWithConnection(\PDO $db, string $key, string $value, string $group): void
    {
        Setting::setEncrypted($key, $value, $group);
        StaffActivityService::logWithConnection(
            $db,
            'update_setting',
            $key,
            null,
            '********'
        );
    }

    private function setEncryptedSetting(string $key, string $value, string $group): void
    {
        $db = $this->activeConfigurationTransaction();
        if ($db !== null) {
            $this->setEncryptedSettingWithConnection($db, $key, $value, $group);
            return;
        }

        Setting::setEncrypted($key, $value, $group);
        $this->logActivity('update_setting', $key, null, '********');
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
        $this->requireDiagnosticsAccess();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, 'Diagnostics export must be requested from the authenticated export form.');
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF mismatch');
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

    private function logActivity(string $action, string $itemType, ?int $itemId = null, ?string $details = null, array $metadata = []): void
    {
        $db = $this->activeConfigurationTransaction();
        if ($db !== null) {
            StaffActivityService::logWithConnection($db, $action, $itemType, $itemId, $details, $metadata);
            return;
        }

        try {
            StaffActivityService::log($action, $itemType, $itemId, $details, $metadata);
        } catch (\Exception $e) {
            error_log("Failed to log admin activity: " . $e->getMessage());
        }
    }
}
