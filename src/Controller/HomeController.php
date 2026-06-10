<?php

namespace App\Controller;

use App\Model\File;
use App\Model\FileDeletionLog;
use App\Model\Folder;
use App\Model\Package;
use App\Model\Setting;
use App\Model\User;
use App\Core\Auth;
use App\Core\View;
use App\Core\Database;
use App\Core\Csrf;
use App\Service\PackageAllowanceService;
use App\Service\RateLimiterService;
use App\Service\SecurityService;

class HomeController {
    private bool $packageSchemaUnavailable = false;

    private function currentUserOwnsRecord(?int $ownerUserId): bool
    {
        return Auth::check() && $ownerUserId !== null && $ownerUserId === (int)(Auth::id() ?? 0);
    }

    private function dashboardFolderForCurrentUser(?string $folderId, int $userId): ?array
    {
        if ($folderId === null || $folderId === '') {
            return null;
        }

        $folder = \App\Model\Folder::find($folderId);
        if (
            !$folder ||
            ($folder['status'] ?? 'active') !== 'active' ||
            !$this->currentUserOwnsRecord(isset($folder['user_id']) ? (int)$folder['user_id'] : null)
        ) {
            return null;
        }

        return $folder;
    }

    private function isHttpsRequest(): bool
    {
        return \App\Service\SecurityService::isHttpsRequest();
    }

    private function issueReferralCookie(int $referrerId, string $source = 'referral'): void
    {
        if ($referrerId <= 0) {
            return;
        }

        $source = in_array($source, ['referral', 'pps'], true) ? $source : 'referral';

        $secret = \App\Service\SecurityService::getSecureAppKey();
        if ($secret === null) {
            return;
        }

        $payload = $referrerId . '|' . $source;
        $signature = hash_hmac('sha256', $payload, $secret);
        $cookieValue = $payload . '.' . $signature;
        setcookie('ref', $cookieValue, [
            'expires' => time() + (86400 * 30),
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['ref'] = $cookieValue;
    }

    private function resolveReferralUserId(string $ref): ?int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        if (preg_match('/^u_[a-f0-9]{12}$/i', $ref)) {
            $user = User::findByPublicId($ref);
            return $user ? (int)($user['id'] ?? 0) : null;
        }

        return null;
    }

    private function verifyTurnstile(string $token): bool
    {
        $secret = Setting::getEncrypted('captcha_secret_key', \App\Core\Config::get('turnstile.secret_key'));
        if (!$secret || !$token) {
            return false;
        }

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => \App\Service\SecurityService::getClientIp(),
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        if (!$resp) {
            return false;
        }

        $decoded = json_decode($resp, true);
        return !empty($decoded['success']);
    }

    private function decryptFileRows(array $files): array
    {
        foreach ($files as &$file) {
            foreach (['filename', 'mime_type', 'storage_path'] as $field) {
                if (!isset($file[$field]) || !is_string($file[$field])) {
                    continue;
                }
                $file[$field] = \App\Service\EncryptionService::decrypt($file[$field]);
            }
        }

        return $files;
    }

    private function siteName(): string
    {
        return Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
    }

    private function packageSchemaRecoveryMessage(): string
    {
        return 'Package plans are temporarily unavailable while a staff member completes database maintenance.';
    }

    private function isPackageSchemaRecoveryError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_starts_with($message, 'Database schema drift detected for required tables (packages)')
            || str_starts_with($message, 'Schema validation failed for required tables (packages)');
    }

    private function markPackageSchemaUnavailable(\Throwable $e): void
    {
        if (!$this->isPackageSchemaRecoveryError($e)) {
            throw $e;
        }

        $this->packageSchemaUnavailable = true;
    }

    private function packageSchemaViewData(): array
    {
        return [
            'packageSchemaUnavailable' => $this->packageSchemaUnavailable,
            'packageSchemaRecoveryMessage' => $this->packageSchemaUnavailable ? $this->packageSchemaRecoveryMessage() : '',
        ];
    }

    private function currentOrGuestPackage(?int $userId = null): ?array
    {
        if ($this->packageSchemaUnavailable) {
            return null;
        }

        try {
            return $userId !== null && $userId > 0
                ? Package::getUserPackage($userId)
                : Package::getGuestPackage();
        } catch (\Throwable $e) {
            $this->markPackageSchemaUnavailable($e);
            return null;
        }
    }

    private function publicPackagesForDisplay(): array
    {
        if ($this->packageSchemaUnavailable) {
            return [];
        }

        try {
            return array_values(array_filter(Package::getAll(), static function (array $pkg): bool {
                return ($pkg['level_type'] ?? '') !== 'admin';
            }));
        } catch (\Throwable $e) {
            $this->markPackageSchemaUnavailable($e);
            return [];
        }
    }

    private function dailyDownloadLimitSummary(): array
    {
        $userId = Auth::id() ? (int)Auth::id() : null;
        $package = $this->currentOrGuestPackage($userId);
        if ($this->packageSchemaUnavailable) {
            return [
                'label' => 'Daily limit left',
                'value' => 'Maintenance',
                'used_bytes' => 0,
                'remaining_bytes' => 0,
                'limit_bytes' => 0,
                'has_limit' => false,
                'unavailable' => true,
            ];
        }

        return PackageAllowanceService::dailyDownloadLimitSummary($userId, $package ?: []);
    }

    private function storageQuotaInfo(): array
    {
        $userId = Auth::id() ? (int)Auth::id() : null;
        if (!$userId) {
            return ['used' => 0, 'limit' => 0];
        }

        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT storage_used FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $used = (int)($stmt->fetchColumn() ?: 0);

        $package = $this->currentOrGuestPackage($userId);
        if ($this->packageSchemaUnavailable) {
            return ['used' => $used, 'limit' => 0, 'unavailable' => true];
        }

        $limit = (int)($package['max_storage_bytes'] ?? 0);

        return ['used' => $used, 'limit' => $limit];
    }

    public function index(?string $id = null) {
        if (\App\Service\FeatureService::affiliateEnabled() && isset($_GET['ref'])) {
            $ref = trim((string) $_GET['ref']);
            $refId = $this->resolveReferralUserId($ref);
            if ($refId) {
                $this->issueReferralCookie($refId, 'referral');
            }
        }

        $requestLocale = \App\Service\SiteContentService::requestLocale();
        $homepagePreviewActive = \App\Service\SiteContentService::previewIsActiveForPage('homepage', $requestLocale);
        $footerPreviewActive = \App\Service\SiteContentService::previewIsActiveForPage('footer', $requestLocale);

        if (!Auth::check() || $homepagePreviewActive || $footerPreviewActive) {
            $packages = $this->publicPackagesForDisplay();
            View::render('home/landing.php', array_merge([
                'packages' => $packages,
            ], $this->packageSchemaViewData()));
            return;
        }

        $userId = Auth::id() ?? 0;
        $package = $this->currentOrGuestPackage((int)$userId);
        $folderId = $id ?: null;

        $currentFolder = $this->dashboardFolderForCurrentUser($folderId, $userId);
        if ($folderId && $currentFolder === null) {
            header('Location: /'); exit;
        }

        $idToFetch = $currentFolder ? $currentFolder['id'] : null;
        $folders = \App\Model\Folder::getByUser($userId, $idToFetch);
        $files = File::getByUser($userId, $idToFetch);

        $breadcrumbPath = [];
        if ($currentFolder) {
            $temp = $currentFolder;
            while ($temp && $temp['parent_id']) {
                $parent = \App\Model\Folder::find($temp['parent_id']);
                if ($parent) {
                    array_unshift($breadcrumbPath, [
                        'name' => $parent['name'],
                        'url' => '/folder/' . $parent['short_id']
                    ]);
                    $temp = $parent;
                } else {
                    break;
                }
            }
        }

        View::render('home/index.php', array_merge([
            'files'   => $files,
            'folders' => $folders,
            'currentFolder'   => $currentFolder,
            'breadcrumbPath'  => $breadcrumbPath,
            'pageHeading' => $currentFolder ? $currentFolder['name'] : 'All Files',
            'pageTitle'   => $currentFolder ? ($currentFolder['name'] . " - " . $this->siteName()) : "Dashboard - " . $this->siteName(),
            'package' => $package,
            'dailyDownloadLimitSummary' => $this->dailyDownloadLimitSummary(),
            'storageQuota' => $this->storageQuotaInfo(),
        ], $this->packageSchemaViewData()));
    }

    public function guestUpload() {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        if (Setting::get('upload_login_required', '0') === '1') {
            header('Location: /login');
            exit;
        }

        $guestPackage = $this->currentOrGuestPackage();
        if (!$guestPackage) {
            if ($this->packageSchemaUnavailable) {
                View::render('home/landing.php', array_merge([
                    'packages' => [],
                ], $this->packageSchemaViewData()));
                return;
            }

            header('Location: /');
            exit;
        }

        View::render('home/index.php', array_merge([
            'files' => [],
            'folders' => [],
            'currentFolder' => null,
            'breadcrumbPath' => [],
            'guestMode' => true,
            'pageHeading' => 'Guest Upload',
            'pageTitle' => 'Guest Upload - ' . $this->siteName(),
            'package' => $guestPackage,
        ], $this->packageSchemaViewData()));
    }

    public function trash() {
        if (!Auth::check()) {
            header('Location: /login'); exit;
        }

        $userId = Auth::id() ?? 0;
        $files = File::getDeletedByUser($userId);
        $folders = \App\Model\Folder::getDeletedByUser($userId);
        $deletionScope = 'admin';
        $deletionPage = max(1, (int)($_GET['deletion_page'] ?? 1));
        $deletionPerPage = 20;
        $deletionHistoryTotal = FileDeletionLog::countByUploader((int)$userId, $deletionScope);
        $deletionHistoryPages = max(1, (int)ceil($deletionHistoryTotal / $deletionPerPage));
        if ($deletionPage > $deletionHistoryPages) {
            $deletionPage = $deletionHistoryPages;
        }
        $fileDeletionHistory = FileDeletionLog::getByUploaderPage((int)$userId, $deletionPage, $deletionPerPage, $deletionScope);

        View::render('home/index.php', array_merge([
            'files'   => $files,
            'folders' => $folders,
            'currentFolder' => null,
            'isTrash' => true,
            'pageHeading' => 'Trash',
            'pageTitle'   => "Trash - " . $this->siteName(),
            'fileDeletionHistory' => $fileDeletionHistory,
            'deletionHistoryScope' => $deletionScope,
            'deletionHistoryPage' => $deletionPage,
            'deletionHistoryPerPage' => $deletionPerPage,
            'deletionHistoryTotal' => $deletionHistoryTotal,
            'deletionHistoryPages' => $deletionHistoryPages,
            'package' => $this->currentOrGuestPackage((int)$userId),
            'dailyDownloadLimitSummary' => $this->dailyDownloadLimitSummary(),
            'storageQuota' => $this->storageQuotaInfo(),
        ], $this->packageSchemaViewData()));
    }

    public function recent() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        $userId = Auth::id() ?? 0;

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT f.*, sf.file_size, sf.mime_type, sf.storage_path, sf.storage_provider, sf.file_hash,
                   sf.file_server_id, sf.provider_etag
            FROM files f
            JOIN stored_files sf ON f.stored_file_id = sf.id
            WHERE f.user_id = ? AND f.status = 'active'
            ORDER BY f.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $files = $this->decryptFileRows($stmt->fetchAll());

        View::render('home/index.php', array_merge([
            'files'   => $files,
            'folders' => [],
            'currentFolder' => null,
            'pageHeading' => 'Recent Files',
            'isRecent' => true,
            'pageTitle'   => "Recent Files - " . $this->siteName(),
            'package' => $this->currentOrGuestPackage((int)$userId),
            'dailyDownloadLimitSummary' => $this->dailyDownloadLimitSummary(),
            'storageQuota' => $this->storageQuotaInfo(),
        ], $this->packageSchemaViewData()));
    }

    public function shared() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        $userId = Auth::id() ?? 0;

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT f.*, sf.file_size, sf.mime_type, sf.storage_path, sf.storage_provider, sf.file_hash,
                   sf.file_server_id, sf.provider_etag
            FROM files f
            JOIN stored_files sf ON f.stored_file_id = sf.id
            WHERE f.user_id = ? AND f.status = 'active' AND f.is_public = 1
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$userId]);
        $files = $this->decryptFileRows($stmt->fetchAll());

        View::render('home/index.php', array_merge([
            'files'   => $files,
            'folders' => [],
            'currentFolder' => null,
            'pageHeading' => 'Shared Files',
            'pageTitle'   => "Shared Files - " . $this->siteName(),
            'isShared' => true,
            'package' => $this->currentOrGuestPackage((int)$userId),
            'dailyDownloadLimitSummary' => $this->dailyDownloadLimitSummary(),
            'storageQuota' => $this->storageQuotaInfo(),
        ], $this->packageSchemaViewData()));
    }

    public function faq() {
        $packages = $this->publicPackagesForDisplay();
        View::render('home/faq.php', array_merge([
            'packages' => $packages,
        ], $this->packageSchemaViewData()));
    }

    public function api() {
        View::render('home/api.php');
    }

    public function linkChecker() {
        if (Setting::get('link_checker_enabled', '1') !== '1') {
            http_response_code(404);
            exit('Page not found');
        }

        $error = '';
        $success = '';
        $results = [];
        $submittedLinks = trim((string)($_POST['links'] ?? ''));
        $summary = [
            'submitted' => 0,
            'unique' => 0,
            'duplicates_removed' => 0,
            'invalid_submitted' => 0,
            'available' => 0,
            'unavailable' => 0,
            'invalid' => 0,
        ];
        $allowCopyToAccount = Setting::get('link_checker_allow_copy_to_account', '1') === '1';
        $maxLinks = max(1, min(1000, (int)Setting::get('link_checker_max_links', '100')));
        $linksPerSecond = max(1, min(250, (int)Setting::get('link_checker_links_per_second', '25')));
        $captchaEnabled = Setting::get('captcha_link_checker', '0') === '1';
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $captchaActive = $captchaEnabled;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $linkCheckerAction = (string)($_POST['link_checker_action'] ?? 'check');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } elseif ($linkCheckerAction === 'check' && $captchaActive && $captchaSiteKey === '') {
                $error = "Link checking is temporarily unavailable because CAPTCHA is enabled but not fully configured.";
            } elseif ($linkCheckerAction === 'check' && $captchaActive && !$this->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                $error = "Captcha verification failed. Please try again.";
            } else {
                $normalized = $this->normalizeLinkCheckerUrls($submittedLinks);
                $links = $normalized['urls'];
                $summary['submitted'] = $normalized['submitted_count'];
                $summary['unique'] = count($links);
                $summary['duplicates_removed'] = (int)($normalized['duplicate_count'] ?? 0);
                $summary['invalid_submitted'] = (int)($normalized['invalid_count'] ?? 0);

                if (empty($links)) {
                    $error = "Paste at least one valid URL to check.";
                } elseif (count($links) > $maxLinks) {
                    $error = "You can check up to {$maxLinks} links at a time.";
                } elseif (!RateLimiterService::checkWeighted('link_checker_ip', SecurityService::getClientIp(), count($links), $linksPerSecond, 1)) {
                    $error = "Too many links are being checked from your connection too quickly. Please wait a second and try again.";
                } else {
                    $results = $this->buildLinkCheckerResults($links);
                    $submittedLinks = implode("\n", $links);
                    $summary = $this->summarizeLinkCheckerResults($summary, $results);

                    if ($linkCheckerAction === 'copy' && $allowCopyToAccount) {
                        [$copySuccess, $copyError] = $this->processLinkCheckerCopyAction($results, $_POST);
                        if ($copySuccess !== '') {
                            $success = $copySuccess;
                        }
                        if ($copyError !== '') {
                            $error = $copyError;
                        }
                    }
                }
            }
        }

        View::render('home/link_checker.php', array_merge([
            'error' => $error,
            'success' => $success,
            'results' => $results,
            'submittedLinks' => $submittedLinks,
            'summary' => $summary,
            'allowCopyToAccount' => $allowCopyToAccount,
            'maxLinks' => $maxLinks,
            'captchaEnabled' => $captchaActive,
            'captchaSiteKey' => $captchaSiteKey,
        ], $this->packageSchemaViewData()));
    }





    public function notifications() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        $userId = Auth::id();
        $notifications = \App\Service\NotificationService::getRecent((int)($userId ?? 0), 50);

        View::render('home/notifications.php', array_merge([
            'notifications' => $notifications,
            'package' => $this->currentOrGuestPackage((int)($userId ?? 0)),
            'dailyDownloadLimitSummary' => $this->dailyDownloadLimitSummary(),
            'storageQuota' => $this->storageQuotaInfo(),
        ], $this->packageSchemaViewData()));
    }

    public function markNotificationsRead() {
        if (!Auth::check()) die("Login required");
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF mismatch");
        }
        \App\Service\NotificationService::markAllRead(Auth::id() ?? 0);
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            return;
        }

        header('Location: /notifications');
        exit;
    }

    public function markNotificationRead(string $id): void
    {
        if (!Auth::check()) {
            die("Login required");
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF mismatch");
        }

        \App\Service\NotificationService::markRead((int)(Auth::id() ?? 0), $id);

        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            return;
        }

        header('Location: /notifications');
        exit;
    }

    public function contact() {
        $error = '';
        $success = '';
        $captchaEnabled = Setting::get('captcha_contact', '0') === '1';
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $captchaActive = $captchaEnabled;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } elseif ($captchaActive && $captchaSiteKey === '') {
                $error = "Contact form submissions are temporarily unavailable because CAPTCHA is enabled but not fully configured.";
            } elseif ($captchaActive && !$this->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                $error = "Captcha verification failed. Please try again.";
            } else {
                $contactRateLimit = \App\Service\TicketService::getRateLimitConfig('contact_ip');
                if (!RateLimiterService::check('contact_form', SecurityService::getClientIp(), (int)$contactRateLimit['max'], (int)$contactRateLimit['window'])) {
                    $error = "Too many messages have been sent from your connection. Please wait a few minutes and try again.";
                }
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $subject = trim($_POST['subject'] ?? '');
                $message = trim($_POST['message'] ?? '');

                if (empty($name) || empty($email) || empty($subject) || empty($message)) {
                    $error = "All fields are required.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email address.";
                } else {
                    try {
                        \App\Service\TicketService::createExternalTicket('contact', [
                            'subject' => $subject,
                            'body' => $message,
                            'name' => $name,
                            'email' => $email,
                            'ip_address' => \App\Service\SecurityService::getClientIp(),
                            'source' => 'contact_form',
                            'metadata' => [],
                        ]);
                        $success = "Your message has been sent successfully. We will get back to you soon.";

                        \App\Service\MailService::sendTemplate($email, 'contact_form_responder', [
                            '{username}' => $name,
                            '{subject}' => $subject
                        ]);
                    } catch (\Throwable $e) {
                        $error = "Failed to send message. Please try again later.";
                    }
                }
            }
        }

        View::render('home/contact.php', [
            'error' => $error,
            'success' => $success,
            'captchaEnabled' => $captchaActive,
            'captchaSiteKey' => $captchaSiteKey,
        ]);
    }

    public function dmca() {
        $error = '';
        $success = '';
        $captchaEnabled = Setting::get('captcha_dmca', '0') === '1';
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $captchaActive = $captchaEnabled;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $signature = trim($_POST['signature'] ?? '');
            $confirmationAccepted = isset($_POST['dmca_confirmation']) && (string)$_POST['dmca_confirmation'] === '1';
            $normalizedUrlList = $this->normalizeDmcaUrls($url);
            $normalizedUrlValue = implode("\n", $normalizedUrlList);

            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } elseif (empty($name) || empty($email) || empty($normalizedUrlList) || empty($description) || empty($signature)) {
                $error = "All fields are required for a valid DMCA notice.";
            } elseif (!$confirmationAccepted) {
                $error = "You must confirm the DMCA statements before submitting a notice.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email address.";
            } elseif ($captchaActive && $captchaSiteKey === '') {
                $error = "DMCA submissions are temporarily unavailable because CAPTCHA is enabled but not fully configured.";
            } elseif ($captchaActive && !$this->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                $error = "Captcha verification failed. Please try again.";
            } else {
                $dmcaRateLimit = \App\Service\TicketService::getRateLimitConfig('dmca_ip');
                if (!RateLimiterService::check('dmca_form', SecurityService::getClientIp(), (int)$dmcaRateLimit['max'], (int)$dmcaRateLimit['window'])) {
                    $error = "Too many notices have been submitted from your connection. Please wait a few minutes and try again.";
                }
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
                try {
                    \App\Service\TicketService::createExternalTicket('dmca', [
                        'subject' => 'DMCA Notice from ' . $name,
                        'body' => $description,
                        'name' => $name,
                        'email' => $email,
                        'ip_address' => \App\Service\SecurityService::getClientIp(),
                        'source' => 'dmca_form',
                        'metadata' => [
                            'infringing_url' => $normalizedUrlValue,
                            'signature' => $signature,
                        ],
                    ]);
                    $success = "Your message has been submitted. Our legal team will review it within 48 hours.";

                    \App\Service\MailService::sendTemplate($email, 'dmca_form_responder', [
                        '{username}' => $name,
                        '{subject}' => 'DMCA Notice',
                    ]);
                } catch (\Throwable $e) {
                    $error = "Failed to submit report. Please try again later.";
                }
            }
        }

        View::render('home/dmca.php', [
            'error' => $error,
            'success' => $success,
            'captchaEnabled' => $captchaActive,
            'captchaSiteKey' => $captchaSiteKey,
        ]);
    }

    private function normalizeDmcaUrls(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $urls = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (!preg_match('~^https?://~i', $part)) {
                $part = 'https://' . ltrim($part, '/');
            }

            if (filter_var($part, FILTER_VALIDATE_URL)) {
                $urls[] = $part;
            }
        }

        return array_values(array_unique($urls));
    }

    private function normalizeLinkCheckerUrls(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($raw === '') {
            return ['urls' => [], 'submitted_count' => 0, 'duplicate_count' => 0, 'invalid_count' => 0];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $urls = [];
        $submittedCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;
        $seen = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $submittedCount++;

            if (!preg_match('~^https?://~i', $part)) {
                $part = 'https://' . ltrim($part, '/');
            }

            if (filter_var($part, FILTER_VALIDATE_URL)) {
                $key = strtolower($part);
                if (isset($seen[$key])) {
                    $duplicateCount++;
                    continue;
                }
                $seen[$key] = true;
                $urls[] = $part;
            } else {
                $invalidCount++;
            }
        }

        return [
            'urls' => $urls,
            'submitted_count' => $submittedCount,
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
        ];
    }

    private function isLikelyLinkCheckerShortId(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}$/i', $value) === 1;
    }

    private function buildLinkCheckerResults(array $urls): array
    {
        $results = [];

        foreach ($urls as $url) {
            $results[] = $this->classifyCheckedLink($url);
        }

        return $results;
    }

    private function classifyCheckedLink(string $url): array
    {
        $result = [
            'url' => $url,
            'kind' => 'file',
            'status' => 'Invalid',
            'status_class' => 'invalid',
            'label' => 'Invalid link',
            'filename' => null,
            'size' => null,
            'details' => 'Only local file or folder links can be checked right now.',
            'short_id' => null,
            'copy_eligible' => false,
        ];

        $parts = parse_url($url);
        if (!is_array($parts) || !$this->isLocalLinkCheckerTarget($parts)) {
            $result['details'] = 'This link does not belong to the current site.';
            return $result;
        }

        $path = trim((string)($parts['path'] ?? ''), '/');
        $segments = $path !== '' ? explode('/', $path) : [];
        if (count($segments) < 2 || !in_array(strtolower($segments[0]), ['file', 'folder'], true)) {
            $result['details'] = 'Only /file/{id} and /folder/{id} links are supported right now.';
            return $result;
        }

        $type = strtolower((string)$segments[0]);
        $shortId = trim(rawurldecode((string)$segments[1]));
        if ($shortId === '' || !$this->isLikelyLinkCheckerShortId($shortId)) {
            $result['details'] = $type === 'folder'
                ? 'The folder link format is not supported.'
                : 'The file link format is not supported.';
            return $result;
        }

        if ($type === 'folder') {
            return $this->classifyCheckedFolderLink($result, $shortId);
        }

        $file = File::findPublicByShortId($shortId);
        if (!$file) {
            $result['status'] = 'Unavailable';
            $result['status_class'] = 'deleted';
            $result['label'] = 'Not available';
            $result['details'] = 'This link is not currently available.';
            return $result;
        }

        $result['short_id'] = (string)($file['short_id'] ?? $shortId);
        $result['filename'] = (string)($file['filename'] ?? '');
        $result['size'] = isset($file['file_size']) ? $this->formatLinkCheckerBytes((int)$file['file_size']) : null;
        $result['status'] = 'Available';
        $result['status_class'] = 'active';
        $result['label'] = 'Available';
        $result['details'] = 'The file link resolves to a public file.';
        $result['copy_eligible'] = true;
        return $result;
    }

    private function classifyCheckedFolderLink(array $result, string $shortId): array
    {
        $result['kind'] = 'folder';
        $result['label'] = 'Not available';
        $result['size'] = 'Folder';

        $folder = Folder::findByShortId($shortId);
        if (!$folder) {
            $result['status'] = 'Unavailable';
            $result['status_class'] = 'deleted';
            $result['details'] = 'This link is not currently available.';
            return $result;
        }

        $folderUserId = (int)($folder['user_id'] ?? 0);
        $viewerId = (int)(Auth::id() ?? 0);
        $canAccess = Auth::check() && $folderUserId === $viewerId;
        if (!$canAccess) {
            $result['status'] = 'Unavailable';
            $result['status_class'] = 'deleted';
            $result['details'] = 'This link is not currently available.';
            return $result;
        }

        $status = strtolower((string)($folder['status'] ?? 'active'));
        if ($status !== 'active') {
            $result['status'] = 'Unavailable';
            $result['status_class'] = 'deleted';
            $result['details'] = 'This link is not currently available.';
            return $result;
        }

        $result['short_id'] = (string)($folder['short_id'] ?? $shortId);
        $result['filename'] = (string)($folder['name'] ?? '');
        $result['status'] = 'Available';
        $result['status_class'] = 'active';
        $result['label'] = 'Available folder';
        $result['details'] = 'The folder link belongs to your account and is ready to open.';
        return $result;
    }

    private function summarizeLinkCheckerResults(array $summary, array $results): array
    {
        foreach ($results as $row) {
            switch ((string)($row['status'] ?? 'Invalid')) {
                case 'Available':
                    $summary['available']++;
                    break;
                case 'Unavailable':
                    $summary['unavailable']++;
                    break;
                default:
                    $summary['invalid']++;
                    break;
            }
        }

        return $summary;
    }

    private function processLinkCheckerCopyAction(array $results, array $post): array
    {
        if (!Auth::check()) {
            return ['', 'You must be logged in to copy files into your account.'];
        }

        $package = $this->currentOrGuestPackage((int)(Auth::id() ?? 0));
        if ($this->packageSchemaUnavailable) {
            return ['', 'Copy to account is temporarily unavailable while database maintenance is completed.'];
        }

        if (!$this->canCurrentUserUseLinkCheckerCopy($package)) {
            return ['', 'Copy to account from Link Checker is disabled for your account level or by site configuration.'];
        }

        $requested = array_values(array_filter(array_map('strval', $post['copy_short_ids'] ?? [])));
        if (($post['copy_mode'] ?? '') === 'all') {
            $requested = [];
            foreach ($results as $row) {
                if (!empty($row['copy_eligible']) && !empty($row['short_id'])) {
                    $requested[] = (string)$row['short_id'];
                }
            }
        }

        $requested = array_values(array_unique($requested));
        if (empty($requested)) {
            return ['', 'Select at least one available file link to copy into your account.'];
        }

        $copied = 0;
        $alreadySaved = 0;
        $skipped = 0;
        $userId = (int)(Auth::id() ?? 0);
        $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
        $dedupeEnabled = \App\Model\Setting::get('upload_detect_duplicates', '1') === '1';

        foreach ($requested as $shortId) {
            if (!$this->isLikelyLinkCheckerShortId($shortId)) {
                $skipped++;
                continue;
            }

            $file = File::findPublicByShortId($shortId);
            if (!$file || !$this->isLinkCheckerCopyCandidate($file)) {
                $skipped++;
                continue;
            }

            if ($dedupeEnabled && File::userHasStoredFile($userId, (int)$file['stored_file_id'])) {
                $alreadySaved++;
                continue;
            }

            $newFileId = File::createSavedCopyForUser((int)$file['id'], $userId, null, $maxStorage);
            if ($newFileId) {
                $copied++;
                Auth::logActivity('save_file', 'Saved file from link checker: ' . $file['filename'] . ' (Source ID: ' . $file['id'] . ', New ID: ' . $newFileId . ')');
            } else {
                $skipped++;
            }
        }

        if ($dedupeEnabled && $copied === 0 && $alreadySaved > 0 && $skipped === 0) {
            return ['All selected files were already in your account.', ''];
        }

        if ($copied === 0) {
            return ['', 'No selected files could be copied into your account.'];
        }

        $message = "Added {$copied} file(s) to your account.";
        if ($dedupeEnabled && $alreadySaved > 0) {
            $message .= " {$alreadySaved} were already saved.";
        }
        if ($skipped > 0) {
            $message .= " {$skipped} could not be copied.";
        }

        return [$message, ''];
    }

    private function canCurrentUserUseLinkCheckerCopy(?array $package): bool
    {
        if (!Auth::check()) {
            return false;
        }
        if (Setting::get('link_checker_allow_copy_to_account', '1') !== '1') {
            return false;
        }

        $tier = $this->resolveLinkCheckerAccountTier($package);
        $settingMap = [
            'free' => 'download_page_save_free',
            'premium' => 'download_page_save_premium',
            'admin' => 'download_page_save_admin',
        ];
        $settingKey = $tier !== null ? ($settingMap[$tier] ?? null) : null;
        return $settingKey !== null && Setting::get($settingKey, '1') === '1';
    }

    private function resolveLinkCheckerAccountTier(?array $package): ?string
    {
        if (!Auth::check()) {
            return null;
        }
        if (Auth::isAdmin()) {
            return 'admin';
        }
        $levelType = strtolower((string)($package['level_type'] ?? 'free'));
        return $levelType === 'paid' ? 'premium' : 'free';
    }

    private function isLinkCheckerCopyCandidate(array $file): bool
    {
        $status = strtolower((string)($file['status'] ?? ''));
        if (!in_array($status, ['active', 'ready', 'processing'], true)) {
            return false;
        }

        return (int)($file['is_public'] ?? 0) === 1;
    }

    private function isLocalLinkCheckerTarget(array $parts): bool
    {
        $trusted = parse_url(\App\Service\SeoService::trustedBaseUrl());
        if (!is_array($trusted)) {
            return false;
        }

        $incomingHost = strtolower((string)($parts['host'] ?? ''));
        $trustedHost = strtolower((string)($trusted['host'] ?? ''));
        if ($incomingHost === '' || $trustedHost === '' || $incomingHost !== $trustedHost) {
            return false;
        }

        $incomingScheme = strtolower((string)($parts['scheme'] ?? ''));
        $trustedScheme = strtolower((string)($trusted['scheme'] ?? 'https'));
        return $incomingScheme === '' || $incomingScheme === $trustedScheme;
    }

    private function formatLinkCheckerBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        foreach ($units as $unit) {
            if ($size < 1024 || $unit === 'TB') {
                return number_format($size, $size >= 10 ? 1 : 2) . ' ' . $unit;
            }
            $size /= 1024;
        }

        return number_format($size, 2) . ' TB';
    }
}
