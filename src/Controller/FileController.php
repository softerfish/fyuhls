<?php

namespace App\Controller;

use App\Service\FileProcessor;
use App\Service\DownloadManager;
use App\Service\DownloadPageService;
use App\Service\SecurityService;
use App\Service\StandardFilePayoutPolicy;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\View;
use App\Model\File;
use App\Model\Package;
use App\Model\Setting;
use App\Core\Config;

class FileController
{
    private const MAX_FILENAME_LENGTH = 255;
    private static bool $downloadBandwidthTableReady = false;
    private static $fileProcessorFactoryForTests = null;

    public static function setFileProcessorFactoryForTests(?callable $factory): void
    {
        self::$fileProcessorFactoryForTests = $factory;
    }

    private function makeFileProcessor(): object
    {
        if (is_callable(self::$fileProcessorFactoryForTests)) {
            return (self::$fileProcessorFactoryForTests)();
        }

        return new FileProcessor();
    }

    private function currentUserOwnsRecord(?int $ownerUserId): bool
    {
        return Auth::check() && $ownerUserId !== null && $ownerUserId === (int)(Auth::id() ?? 0);
    }

    private function currentUserOwnsFile(?array $file): bool
    {
        return is_array($file) && $this->currentUserOwnsRecord(isset($file['user_id']) ? (int)$file['user_id'] : null);
    }

    private function currentUserOwnsFolder(?array $folder): bool
    {
        return is_array($folder) && $this->currentUserOwnsRecord(isset($folder['user_id']) ? (int)$folder['user_id'] : null);
    }

    private function downloadWaitSessionKey(array $file, ?int $userId): string
    {
        $fileKey = trim((string)($file['short_id'] ?? $file['id'] ?? ''));
        $actorKey = $userId !== null && $userId > 0 ? 'user:' . $userId : 'guest:' . session_id();
        return $actorKey . '|file:' . $fileKey;
    }

    private function respondJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($payload);
    }

    private function resolveOwnedBulkItems(array $items, string $fileScope = 'active', string $folderScope = 'active'): array
    {
        $resolved = [];
        $seen = [];

        foreach ($items as $item) {
            $type = strtolower(trim((string)($item['type'] ?? '')));
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0 || !in_array($type, ['file', 'folder'], true)) {
                throw new \RuntimeException('Every selected item must include a valid type and id.');
            }

            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ($type === 'file') {
                $row = $fileScope === 'active' ? File::find($id) : File::findAnyStatus($id);
                if (!$this->currentUserOwnsFile($row)) {
                    throw new \RuntimeException('One or more selected files are no longer available to this account.');
                }
                $status = (string)($row['status'] ?? 'active');
                if ($fileScope === 'deleted' && $status !== 'deleted') {
                    throw new \RuntimeException('One or more selected files are no longer in the expected state.');
                }
                if ($fileScope === 'active' && $status !== 'active') {
                    throw new \RuntimeException('One or more selected files are no longer active.');
                }
            } else {
                $row = \App\Model\Folder::find($id);
                if (!$this->currentUserOwnsFolder($row)) {
                    throw new \RuntimeException('One or more selected folders are no longer available to this account.');
                }
                $status = (string)($row['status'] ?? 'active');
                if ($folderScope === 'deleted' && $status !== 'deleted') {
                    throw new \RuntimeException('One or more selected folders are no longer in the expected state.');
                }
                if ($folderScope === 'active' && $status !== 'active') {
                    throw new \RuntimeException('One or more selected folders are no longer active.');
                }
            }

            $resolved[] = [
                'type' => $type,
                'id' => $id,
                'row' => $row,
                'request' => is_array($item) ? $item : [],
            ];
        }

        if ($resolved === []) {
            throw new \RuntimeException('No items selected.');
        }

        return $resolved;
    }

    private function issueTrackedDownloadUrl(array $file, array $clientHints = []): string
    {
        $this->maybeIssuePpsReferralCookie($file);
        $fraud = new \App\Service\RewardFraudService();
        $fraud->ensureVisitorCookie();
        $fraud->rememberDownloadPageReferrer((int)($file['id'] ?? 0));
        $session = $fraud->createDownloadSession($file, Auth::id() ? (int)Auth::id() : null, $clientHints);
        $sessionId = trim((string)($session['public_id'] ?? ''));
        if ($sessionId === '') {
            throw new \RuntimeException('Could not issue a tracked download session.');
        }

        return (new DownloadManager())->issueNormalDownloadLink((string)($file['short_id'] ?? $file['id']), $file['filename'], $sessionId);
    }

    private function buildDownloadBandwidthEventKey(string $fileId, string $token, int $expires, string $sessionId, bool $streamMode, bool $isOwner): string
    {
        if ($isOwner && $token === '' && $expires <= 0 && $sessionId === '') {
            return 'direct-owner:' . bin2hex(random_bytes(16));
        }

        return hash('sha256', implode('|', [
            $fileId,
            $token,
            (string)$expires,
            $sessionId,
            $streamMode ? 'stream' : 'download',
        ]));
    }

    private function buildPublicShareFields(array $file): array
    {
        if (empty($file['is_public']) || empty($file['short_id'])) {
            return [];
        }

        $baseUrl = rtrim(\App\Service\SeoService::trustedBaseUrl(), '/');
        $pageUrl = $baseUrl . '/file/' . rawurlencode((string)$file['short_id']);
        $safeFilename = htmlspecialchars((string)($file['filename'] ?? ''), ENT_QUOTES, 'UTF-8');
        $pageUrlHtml = htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8');
        $shareFields = [
            [
                'label' => 'Page Link',
                'value' => $pageUrl,
            ],
            [
                'label' => 'HTML Code',
                'value' => '<a href="' . $pageUrlHtml . '" target="_blank" rel="noopener">' . $safeFilename . '</a>',
            ],
            [
                'label' => 'Forum Code',
                'value' => '[url=' . $pageUrl . ']' . (string)($file['filename'] ?? '') . '[/url]',
            ],
        ];

        $thumbnailUrl = null;
        $isImageFile = str_starts_with($this->resolveDisplayMimeType($file), 'image/');
        if ($isImageFile && !empty($file['storage_path'])) {
            $thumbnailPath = \App\Model\StoredFile::buildThumbnailVariantPathFromStoragePath((string)$file['storage_path']);
            if ($thumbnailPath !== null) {
                $normalized = trim(substr($thumbnailPath, strlen('thumbnails/')), '/');
                $thumbnailUrl = $baseUrl . '/thumbnail/' . implode('/', array_map('rawurlencode', explode('/', $normalized)));
            }
        }

        if ($thumbnailUrl !== null) {
            $thumbHtml = htmlspecialchars($thumbnailUrl, ENT_QUOTES, 'UTF-8');
            $shareFields[] = [
                'label' => 'Embed HTML Code',
                'value' => '<a href="' . $pageUrlHtml . '" target="_blank" rel="noopener"><img src="' . $thumbHtml . '" alt="' . $safeFilename . '"></a>',
            ];
            $shareFields[] = [
                'label' => 'Embed Forum Code',
                'value' => '[url=' . $pageUrl . '][img]' . $thumbnailUrl . '[/img][/url]',
            ];
        }

        return $shareFields;
    }

    private function renderDownloadSharePanel(array $shareFields): void
    {
        if (empty($shareFields)) {
            return;
        }

        $primaryField = $shareFields[0];
        $extraFields = array_slice($shareFields, 1);
        $panelId = 'downloadSharePanel' . substr(md5(json_encode($shareFields)), 0, 8);
        $extraId = $panelId . 'Extra';
        $primaryInputId = $panelId . 'Primary';

        echo '<div class="download-share-panel">';
        echo '<h2 class="download-share-heading">Share This File</h2>';
        echo '<div class="download-share-row download-share-row--primary">';
        echo '<label class="download-share-label" for="' . $primaryInputId . '">' . htmlspecialchars($primaryField['label']) . '</label>';
        echo '<div class="download-share-control">';
        echo '<input type="text" readonly class="download-share-input" id="' . $primaryInputId . '" value="' . htmlspecialchars($primaryField['value'], ENT_QUOTES, 'UTF-8') . '">';
        echo '<button type="button" class="download-share-copy" data-copy-target="' . $primaryInputId . '">Copy</button>';
        echo '</div>';
        echo '</div>';

        if (!empty($extraFields)) {
            echo '<button type="button" class="download-share-toggle" data-share-toggle="' . $extraId . '" aria-expanded="false">';
            echo 'More share options';
            echo '</button>';
            echo '<div class="download-share-extra" id="' . $extraId . '" hidden>';
            foreach ($extraFields as $index => $field) {
                $inputId = $panelId . 'Field' . $index;
                echo '<div class="download-share-row">';
                echo '<label class="download-share-label" for="' . $inputId . '">' . htmlspecialchars($field['label']) . '</label>';
                echo '<div class="download-share-control">';
                echo '<input type="text" readonly class="download-share-input" id="' . $inputId . '" value="' . htmlspecialchars($field['value'], ENT_QUOTES, 'UTF-8') . '">';
                echo '<button type="button" class="download-share-copy" data-copy-target="' . $inputId . '">Copy</button>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function renderDownloadStatePage(string $titleText, string $heading, string $message, int $statusCode = 200, ?array $package = null, ?array $file = null, array $shareFields = []): void
    {
        $downloadPageService = new DownloadPageService();
        $viewModel = $downloadPageService->buildStatePageViewModel(
            $titleText,
            $heading,
            $message,
            $statusCode,
            $package,
            $file,
            $shareFields
        );

        http_response_code((int)$viewModel['statusCode']);
        $title = (string)$viewModel['title'];
        $metaDescription = (string)$viewModel['metaDescription'];

        require_once dirname(__DIR__, 1) . '/View/home/header.php';
        View::render('home/partials/download_state_page.php', $viewModel);
        require_once dirname(__DIR__, 1) . '/View/home/footer.php';
    }

    private function verifyTurnstile(string $token): bool
    {
        $secret = Setting::getEncrypted('captcha_secret_key', Config::get('turnstile.secret_key'));
        if (!$secret) {
            return false;
        }

        if ($token === '') {
            return false;
        }

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => \App\Service\SecurityService::getClientIp(),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return false;
        }

        $data = json_decode($response, true);
        return !empty($data['success']);
    }

    private function isHttpsRequest(): bool
    {
        return \App\Service\SecurityService::isHttpsRequest();
    }

    private function issueReferralCookie(int $referrerId, string $source = 'pps'): void
    {
        if ($referrerId <= 0) {
            return;
        }

        $source = in_array($source, ['referral', 'pps'], true) ? $source : 'pps';

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

    private function maybeIssuePpsReferralCookie(array $file): void
    {
        if (
            !\App\Service\FeatureService::affiliateEnabled()
            || Setting::get('pps_global_status', '1') !== '1'
            || Auth::check()
            || !empty($_COOKIE['ref'])
        ) {
            return;
        }

        $referrerId = (int)($file['user_id'] ?? 0);
        if ($referrerId <= 0) {
            return;
        }

        $this->issueReferralCookie($referrerId, 'pps');
    }

    private function cleanupStaleActiveDownloadsForActor(?int $userId, ?string $ipHash): void
    {
        $db = \App\Core\Database::getInstance()->getConnection();
        $staleClause = "
            (
                started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                OR last_ping_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                OR (
                    COALESCE(bytes_sent, 0) = 0
                    AND started_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                )
                OR (
                    COALESCE(bytes_sent, 0) > 0
                    AND last_ping_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                )
            )
        ";

        if ($userId !== null && $userId > 0) {
            $stmt = $db->prepare("
                DELETE FROM active_downloads
                WHERE user_id = ?
                  AND {$staleClause}
            ");
            $stmt->execute([$userId]);
            return;
        }

        if ($ipHash !== null && $ipHash !== '') {
            $stmt = $db->prepare("
                DELETE FROM active_downloads
                WHERE user_id IS NULL
                  AND ip_hash = ?
                  AND {$staleClause}
            ");
            $stmt->execute([$ipHash]);
        }
    }

    private function registerActiveDownload(int $fileId, ?int $userId, string $ip, array $context = []): int
    {
        $db = \App\Core\Database::getInstance()->getConnection();
        $encIp = \App\Service\EncryptionService::encrypt($ip);
        try {
            $stmt = $db->prepare("
                INSERT INTO active_downloads (
                    file_id, user_id, session_id, ip_address, ip_hash, ua_hash, visitor_cookie_hash, accept_language_hash,
                    timezone_offset, platform_bucket, screen_bucket, asn, network_type, country_code
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $fileId,
                $userId,
                !empty($context['session_id']) ? (int)$context['session_id'] : null,
                $encIp,
                $context['ip_hash'] ?? null,
                $context['ua_hash'] ?? null,
                $context['visitor_cookie_hash'] ?? null,
                $context['accept_language_hash'] ?? null,
                isset($context['timezone_offset']) && $context['timezone_offset'] !== '' ? (int)$context['timezone_offset'] : null,
                $context['platform_bucket'] ?? null,
                $context['screen_bucket'] ?? null,
                $context['asn'] ?? null,
                $context['network_type'] ?? null,
                $context['country_code'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $stmt = $db->prepare("INSERT INTO active_downloads (file_id, user_id, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([$fileId, $userId, $encIp]);
        }
        return (int)$db->lastInsertId();
    }

    private function removeActiveDownload(int $downloadId): void
    {
        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM active_downloads WHERE id = ?");
        $stmt->execute([$downloadId]);
    }

    private function ensureDownloadBandwidthTable(): void
    {
        if (self::$downloadBandwidthTableReady) {
            return;
        }

        \App\Service\Database\SchemaService::ensureTables(['download_bandwidth_usage'], false);

        self::$downloadBandwidthTableReady = true;
    }

    private function buildDownloadBandwidthActorKey(?int $userId, string $ip): string
    {
        if ($userId !== null && $userId > 0) {
            return 'user:' . $userId;
        }

        return 'ip:' . hash('sha256', $ip);
    }

    private function acquireDownloadBandwidthLock(\PDO $db, string $actorKey, string $usageDate): bool
    {
        $lockKey = 'fyuhls_download_bandwidth_' . hash('sha256', $actorKey . '|' . $usageDate);
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute([$lockKey]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseDownloadBandwidthLock(\PDO $db, string $actorKey, string $usageDate): void
    {
        try {
            $lockKey = 'fyuhls_download_bandwidth_' . hash('sha256', $actorKey . '|' . $usageDate);
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockKey]);
        } catch (\Throwable $e) {
        }
    }

    private function enforceDailyDownloadLimit(array $package, array $file, ?int $userId, string $ip, string $eventKey): void
    {
        $dailyLimit = (int)($package['max_daily_downloads'] ?? 0);
        $fileSize = (int)($file['file_size'] ?? 0);

        if ($dailyLimit <= 0 || $fileSize <= 0 || $eventKey === '') {
            return;
        }

        $this->ensureDownloadBandwidthTable();
        $db = \App\Core\Database::getInstance()->getConnection();
        $actorKey = $this->buildDownloadBandwidthActorKey($userId, $ip);
        $usageDate = gmdate('Y-m-d');
        if (!$this->acquireDownloadBandwidthLock($db, $actorKey, $usageDate)) {
            $this->renderDownloadStatePage(
                'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Please Try Again',
                'This download could not be started safely right now. Please try again in a moment.',
                503,
                $package,
                $file,
                $this->buildPublicShareFields($file)
            );
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT bytes_used FROM download_bandwidth_usage WHERE event_key = ? LIMIT 1");
            $stmt->execute([$eventKey]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return;
            }

            $stmt = $db->prepare("
                SELECT COALESCE(SUM(bytes_used), 0)
                FROM download_bandwidth_usage
                WHERE actor_key = ? AND usage_date = ?
            ");
            $stmt->execute([$actorKey, $usageDate]);
            $usedToday = (int)$stmt->fetchColumn();

            if (($usedToday + $fileSize) > $dailyLimit) {
                $message = $fileSize > $dailyLimit
                    ? 'This file is larger than your package\'s total daily download bandwidth allowance.'
                    : 'You have reached your daily download bandwidth limit for this package. Please try again later.';

                $this->renderDownloadStatePage(
                    'Download Limit Reached - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Download Limit Reached',
                    $message,
                    429,
                    $package,
                    $file,
                    $this->buildPublicShareFields($file)
                );
                exit;
            }

            $stmt = $db->prepare("
                INSERT INTO download_bandwidth_usage (usage_date, actor_key, user_id, event_key, bytes_used)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$usageDate, $actorKey, $userId, $eventKey, $fileSize]);
        } finally {
            $this->releaseDownloadBandwidthLock($db, $actorKey, $usageDate);
        }
    }

    private function logStandardFilePayoutEvent(string $event, array $context = []): void
    {
        $allowed = [
            'file_id',
            'download_id',
            'delivery_mode',
            'reason_code',
            'status',
            'observed_bytes',
            'required_bytes',
            'min_percent',
        ];

        $parts = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $context) || $context[$key] === null || $context[$key] === '') {
                continue;
            }

            $parts[] = $key . '=' . (string)$context[$key];
        }

        error_log('[StandardFilePayout] ' . $event . ($parts ? ' ' . implode(' ', $parts) : ''));
    }

    private function buildStandardFilePayoutEventKey(int $downloadId): string
    {
        return 'active_download:' . $downloadId;
    }

    private function buildConcurrentDownloadActorId(?int $userId, string $ip, array $context = []): array
    {
        if ($userId !== null && $userId > 0) {
            return [
                'lock_key' => 'user:' . $userId,
                'user_id' => $userId,
                'ip_hash' => null,
            ];
        }

        $ipHash = trim((string)($context['ip_hash'] ?? ''));
        if ($ipHash === '') {
            $ipHash = hash('sha256', $ip);
        }

        return [
            'lock_key' => 'guest:' . $ipHash,
            'user_id' => null,
            'ip_hash' => $ipHash,
        ];
    }

    private function acquireConcurrentDownloadLock(\PDO $db, string $actorLockKey): bool
    {
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute(['fyuhls_concurrent_download_' . hash('sha256', $actorLockKey)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseConcurrentDownloadLock(\PDO $db, string $actorLockKey): void
    {
        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_concurrent_download_' . hash('sha256', $actorLockKey)]);
        } catch (\Throwable $e) {
        }
    }

    private function packageHasTrackedConcurrentLimit(array $package): bool
    {
        if (Setting::get('track_current_downloads', '0') !== '1') {
            return false;
        }

        return max(0, (int)($package['concurrent_downloads'] ?? 0)) > 0;
    }

    private function claimConcurrentDownloadSlot(array $package, array $file, string $ip, array $context = []): int
    {
        if (Setting::get('track_current_downloads', '0') !== '1') {
            return 0;
        }

        $userId = Auth::id() ? (int)Auth::id() : null;
        $limit = max(0, (int)($package['concurrent_downloads'] ?? 0));
        if ($limit === 0) {
            return $this->registerActiveDownload((int)$file['id'], $userId, $ip, $context);
        }

        $db = \App\Core\Database::getInstance()->getConnection();
        $actor = $this->buildConcurrentDownloadActorId($userId, $ip, $context);
        if (!$this->acquireConcurrentDownloadLock($db, $actor['lock_key'])) {
            $this->renderDownloadStatePage(
                'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Please Try Again',
                'This download could not be started safely right now. Please try again in a moment.',
                503,
                $package,
                $file,
                $this->buildPublicShareFields($file)
            );
            exit;
        }

        try {
            $this->cleanupStaleActiveDownloadsForActor($actor['user_id'], $actor['ip_hash']);

            if ($actor['user_id'] !== null) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM active_downloads WHERE user_id = ?");
                $stmt->execute([$actor['user_id']]);
            } else {
                $stmt = $db->prepare("SELECT COUNT(*) FROM active_downloads WHERE user_id IS NULL AND ip_hash = ?");
                $stmt->execute([$actor['ip_hash']]);
            }

            $activeCount = (int)$stmt->fetchColumn();
            if ($activeCount >= $limit) {
                $this->renderDownloadStatePage(
                    'Concurrent Download Limit Reached - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Concurrent Download Limit Reached',
                    'You have reached your concurrent download limit for this package. Please wait for an active download to finish before starting another.',
                    429,
                    $package,
                    $file,
                    $this->buildPublicShareFields($file)
                );
                exit;
            }

            if ($actor['user_id'] === null && empty($context['ip_hash']) && !empty($actor['ip_hash'])) {
                $context['ip_hash'] = $actor['ip_hash'];
            }

            return $this->registerActiveDownload((int)$file['id'], $actor['user_id'], $ip, $context);
        } finally {
            $this->releaseConcurrentDownloadLock($db, $actor['lock_key']);
        }
    }

    private function canAccessFile(array $file): bool
    {
        if (!empty($file['is_public'])) {
            return true;
        }

        return $this->currentUserOwnsFile($file);
    }

    private function enforceFileAccess(array $file): void
    {
        if ($this->canAccessFile($file)) {
            return;
        }

        http_response_code(404);
        die('File not found');
    }

    private function renderPrivateFilePage(array $file): void
    {
        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
        $this->renderDownloadStatePage(
            'File Not Available - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
            'File Not Available',
            "There is no file available at this link. It's either deleted, set to private or has not been processed just yet. Try contacting the user that uploaded the file.",
            403,
            $package
        );
    }

    private function renderVpnBlockedStatePage(?array $package = null, ?array $file = null): void
    {
        $shareFields = $file !== null ? $this->buildPublicShareFields($file) : [];
        $this->renderDownloadStatePage(
            'VPN / Proxy Detected - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
            'VPN / Proxy Detected',
            'VPN, proxy, and similar relay services are not allowed for this download tier. Please disable them and refresh the page to continue.',
            403,
            $package,
            $file,
            $shareFields
        );
    }

    private function normalizeFilename(?string $name): string
    {
        $name = trim((string)$name);
        return mb_substr($name, 0, self::MAX_FILENAME_LENGTH);
    }

    private function sanitizeAbuseEmailText(?string $value, int $maxLength = 1000): string
    {
        $value = trim((string)$value);
        $value = strip_tags($value);
        $value = preg_replace("/\r\n?/", "\n", $value) ?? $value;
        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? $value;
        return mb_substr($value, 0, $maxLength);
    }

    private function isStoredObjectHealthy(\App\Interface\StorageProvider $storage, array $file): bool
    {
        $path = trim((string)($file['storage_path'] ?? ''));
        if ($path === '') {
            return false;
        }

        $head = $storage->head($path);
        if ($head === null) {
            return false;
        }

        $expectedSize = (int)($file['file_size'] ?? 0);
        $actualSize = (int)($head['content_length'] ?? 0);
        if ($expectedSize > 0 && $actualSize > 0 && $actualSize !== $expectedSize) {
            return false;
        }

        return true;
    }

    public function delete()
    {
        header('Content-Type: application/json');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Login required']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
            return;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Security token mismatch']);
            return;
        }

        $fileRef = trim((string)($_POST['file_id'] ?? $_POST['id'] ?? ''));
        $file = $this->resolvePostedFileReference($fileRef);

        if (!$file) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
            return;
        }

        // idor check
        if (!$this->currentUserOwnsFile($file)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            return;
        }

        $adminAction = false;
        $deleteReason = trim((string)($_POST['delete_reason'] ?? ''));
        $deleteFileEarnings = false;
        if ($adminAction && $deleteReason === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'A delete reason is required for admin removals.']);
            return;
        }

        try {
            File::trash((int)$file['id']);
        } catch (\Throwable $e) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            return;
        }
        $activityMessage = "Moved file to trash: " . $file['filename'] . " (ID: " . $file['id'] . ")";
        Auth::logActivity('delete', $activityMessage);
        Logger::info('file moved to trash', [
            'file_id' => $file['id'],
            'user_id' => Auth::id(),
            'admin_action' => $adminAction,
        ]);
        echo json_encode(['status' => 'success', 'message' => 'File moved to trash', 'redirect_url' => '/trash']);
    }

    public function saveToAccount()
    {
        header('Content-Type: application/json');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Login required']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
            return;
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Security token mismatch']);
            return;
        }

        $fileRef = trim((string)($_POST['file_id'] ?? ''));
        $file = $this->resolvePostedFileReference($fileRef);
        if (!$file) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
            return;
        }

        $this->enforceFileAccess($file);

        $userId = (int)(Auth::id() ?? 0);
        $package = Package::getUserPackage($userId);
        if (!$this->canCurrentUserSaveDownloadedFile($file, $package)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Saving files from the download page is disabled for your account level.']);
            return;
        }

        $dedupeEnabled = Setting::get('upload_detect_duplicates', '1') === '1';
        if ($dedupeEnabled && File::userHasStoredFile($userId, (int)$file['stored_file_id'])) {
            echo json_encode(['status' => 'success', 'message' => 'This file is already in your account.', 'already_saved' => true]);
            return;
        }

        $newFileId = File::createSavedCopyForUser((int)$file['id'], $userId, null, (int)($package['max_storage_bytes'] ?? 0));
        if (!$newFileId) {
            http_response_code(409);
            $quotaExceeded = (int)($package['max_storage_bytes'] ?? 0) > 0
                && ((int)($file['file_size'] ?? 0) > 0)
                && (!$dedupeEnabled || !File::userHasStoredFile($userId, (int)$file['stored_file_id']));
            echo json_encode([
                'status' => 'error',
                'message' => $quotaExceeded
                    ? 'Saving this file would exceed your storage limit.'
                    : ($dedupeEnabled ? 'This file is already in your account.' : 'This file could not be added to your account right now.')
            ]);
            return;
        }

        Auth::logActivity('save_file', 'Saved file to account: ' . $file['filename'] . ' (Source ID: ' . $file['id'] . ', New ID: ' . $newFileId . ')');
        echo json_encode(['status' => 'success', 'message' => 'File added to your account.', 'file_id' => $newFileId]);
    }

    private function resolvePostedFileReference(string $fileRef): ?array
    {
        if ($fileRef === '') {
            return null;
        }

        if (ctype_digit($fileRef)) {
            return File::find((int)$fileRef);
        }

        return File::findByShortId($fileRef);
    }

    public function remoteUpload()
    {
        if (!Auth::check()) die(json_encode(['error' => 'Login required']));
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die(json_encode(['error' => 'Method not allowed']));
        }

        $userId = (int)(Auth::id() ?? 0);
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!Csrf::verify($csrfToken)) {
            http_response_code(403);
            die(json_encode(['error' => 'CSRF Token Invalid']));
        }

        $package = \App\Model\Package::getUserPackage($userId);
        if (!$package['allow_remote_upload']) die(json_encode(['error' => 'Remote upload not allowed for your package.']));
        if (!$this->allowRemoteUploadQueueRequest($userId, \App\Service\SecurityService::getClientIp())) {
            http_response_code(429);
            die(json_encode(['error' => 'Too many remote upload requests are already pending for this account. Please wait for existing jobs to finish.']));
        }

        $url = $_POST['url'] ?? '';
        $url = trim($url);
        if ($url === '') {
            die(json_encode(['error' => 'A remote URL is required.']));
        }

        // ssrf protection: check the protocol
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            die(json_encode(['error' => 'Invalid protocol. Only HTTP and HTTPS allowed.']));
        }

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            die(json_encode(['error' => 'Embedded credentials are not allowed in remote URLs.']));
        }

        // ssrf protection: check the host and ip
        $host = parse_url($url, PHP_URL_HOST);
        $approvedIps = $this->resolveApprovedRemoteIps($host);

        if (empty($approvedIps)) {
            die(json_encode(['error' => 'Could not resolve host.']));
        }

        $maxRemoteBytes = $this->resolveRemoteUploadByteLimit($userId, $package);
        if ($maxRemoteBytes <= 0) {
            die(json_encode(['error' => 'Remote upload is not available because your remaining limits are exhausted.']));
        }

        // 3. Check if we should process in background
        $bg = \App\Model\Setting::get('remote_url_background', '0') === '1';
        $folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        if ($folderId) {
            $folder = \App\Model\Folder::find($folderId);
            if ($this->currentUserOwnsFolder($folder)) {
                $folderId = (int)$folder['id'];
            } else {
                $folderId = null;
            }
        }

        if ($bg) {
            try {
                $jobId = $this->queueRemoteUploadJob($userId, $folderId, $url);
                \App\Service\NotificationService::sendEvent(
                    $userId,
                    'remote_uploads',
                    'remote_upload:' . $jobId,
                    'Remote upload queued',
                    'Your remote upload was added to the queue and will be processed in the background.',
                    'info',
                    '/notifications'
                );
                die(json_encode(['success' => true, 'message' => 'Upload queued in background. It will appear in your files shortly.']));
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'remote_upload_pending_limit_reached') {
                    http_response_code(429);
                    die(json_encode(['error' => 'Too many remote upload requests are already pending for this account. Please wait for existing jobs to finish.']));
                }
                die(json_encode(['error' => 'Could not queue download.']));
            } catch (\Exception $e) {
                die(json_encode(['error' => 'Could not queue download.']));
            }
        }

        // 4. Synchronous Download to temp file
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $tempPath = \App\Service\TemporaryArtifactService::createTempFile('remote_');
        $ch = false;
        $fp = false;

        try {
            // Use cURL for better security (prevents file:// or other wrapper escapes)
            $ch = curl_init($url);
            $fp = fopen($tempPath, 'wb');
            if ($ch === false || $fp === false) {
                echo json_encode(['error' => 'Could not prepare the remote upload on this server.']);
                return;
            }

            $resolvedHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
            $port = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
            $resolveEntries = array_map(static fn(string $ip): string => $resolvedHost . ':' . $port . ':' . $ip, $approvedIps);
            $downloadedBytes = 0;
            $contentLengthChecked = false;
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Slightly larger for remote urls
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Do not follow redirects into internal networks
            curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS); // Force protocols again
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_RESOLVE, $resolveEntries);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function($curl, string $headerLine) use ($maxRemoteBytes, &$contentLengthChecked) {
                $length = null;
                if (stripos($headerLine, 'Content-Length:') === 0) {
                    $length = (int)trim(substr($headerLine, strlen('Content-Length:')));
                    $contentLengthChecked = true;
                    if ($length > 0 && $length > $maxRemoteBytes) {
                        return -1;
                    }
                }
                return strlen($headerLine);
            });

            // Cancel Hook: Abort if client disconnects
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($curl, float $downloadTotal, float $downloadNow) use ($maxRemoteBytes, &$downloadedBytes) {
                $downloadedBytes = (int)$downloadNow;
                if ($downloadNow > $maxRemoteBytes) {
                    return 1;
                }
                return connection_aborted() ? 1 : 0; // Return non-zero to abort cURL
            });

            $success = curl_exec($ch);
            $curlErrNo = curl_errno($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);
            $ch = false;
            fclose($fp);
            $fp = false;

            $tempFileSize = file_exists($tempPath) ? (int)filesize($tempPath) : 0;

            if (!$success) {
                if ($downloadedBytes > $maxRemoteBytes || $curlErrNo === 23 || $curlErrNo === 63) {
                    echo json_encode(['error' => 'Remote file exceeds your allowed upload size or remaining storage quota.']);
                    return;
                }
                if (!$contentLengthChecked && $tempFileSize > $maxRemoteBytes) {
                    echo json_encode(['error' => 'Remote file exceeds your allowed upload size or remaining storage quota.']);
                    return;
                }
                echo json_encode(['error' => 'Could not fetch file from URL. ' . ($curlErr ? 'Transfer error: ' . $curlErr : '')]);
                return;
            }

            if ($tempFileSize > $maxRemoteBytes) {
                echo json_encode(['error' => 'Remote file exceeds your allowed upload size or remaining storage quota.']);
                return;
            }

            $originalName = basename(parse_url($url, PHP_URL_PATH)) ?: 'downloaded_file';

            try {
                $processor = $this->makeFileProcessor();
                $result = $processor->processUpload($tempPath, $originalName, $userId, $folderId);
                echo json_encode($result);
                return;
            } catch (\Exception $e) {
                Logger::error('Remote upload processing failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                echo json_encode(['error' => 'The remote upload could not be completed.']);
                return;
            }
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if ($ch !== false) {
                curl_close($ch);
            }
            \App\Service\TemporaryArtifactService::cleanup($tempPath);
        }
    }

    /**
     * Helper to check if IP is in CIDR range
     */
    private function ipInRage(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) $range .= '/32';
        list($range, $netmask) = explode('/', $range, 2);
        $range_decimal = ip2long($range);
        $ip_decimal = ip2long($ip);
        $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
        $netmask_decimal = ~ $wildcard_decimal;
        return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
    }

    private function resolveApprovedRemoteIps(?string $host): array
    {
        return \App\Service\SecurityService::resolveApprovedRemoteDestinationIps($host);
    }

    private function allowRemoteUploadQueueRequest(int $userId, string $clientIp): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $perMinuteLimit = max(1, (int)\App\Model\Setting::get('remote_upload_rate_limit', '12'));
        if (!\App\Service\RateLimiterService::check('remote_upload_user', (string)$userId, $perMinuteLimit, 60)) {
            return false;
        }

        if (!\App\Service\RateLimiterService::check('remote_upload_ip', $clientIp, max($perMinuteLimit, 20), 60)) {
            return false;
        }

        return true;
    }

    private function queueRemoteUploadJob(int $userId, ?int $folderId, string $url): int
    {
        $pendingLimit = max(1, (int)\App\Model\Setting::get('remote_upload_pending_limit', '10'));
        $db = \App\Core\Database::getInstance()->getConnection();
        $this->ensureRemoteUploadQueueSchema($db);
        $lockKey = 'fyuhls_remote_upload_queue_' . $userId;
        $lockStmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $lockStmt->execute([$lockKey]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new \RuntimeException('remote_upload_queue_lock_failed');
        }

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM remote_upload_queue WHERE user_id = ? AND status IN ('pending', 'processing')");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() >= $pendingLimit) {
                throw new \RuntimeException('remote_upload_pending_limit_reached');
            }

            $stmt = $db->prepare("INSERT INTO remote_upload_queue (user_id, folder_id, url) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $folderId, $url]);
            return (int)$db->lastInsertId();
        } finally {
            try {
                $releaseStmt = $db->prepare("SELECT RELEASE_LOCK(?)");
                $releaseStmt->execute([$lockKey]);
            } catch (\Throwable $e) {
            }
        }
    }

    private function isAllowedRemoteIp(string $ip): bool
    {
        return \App\Service\SecurityService::isAllowedRemoteDestinationIp($ip);
    }

    private function resolveRemoteUploadByteLimit(int $userId, array $package): int
    {
        $limit = (int)($package['max_upload_size'] ?? 0);
        if ($limit <= 0) {
            $limit = PHP_INT_MAX;
        }

        $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
        if ($maxStorage > 0 && $userId > 0) {
            $db = \App\Core\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $storageUsed = (int)$stmt->fetchColumn();
            $activeReserved = \App\Model\QuotaReservation::activeReservedBytesForUser($userId);
            $remaining = max(0, $maxStorage - $storageUsed - $activeReserved);
            $limit = min($limit, $remaining);
        }

        return max(0, $limit);
    }

    private function resolveDownloadActionTier(?array $package): ?string
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

    private function canCurrentUserSaveDownloadedFile(array $file, ?array $package): bool
    {
        if (!Auth::check() || empty($file['id']) || empty($file['stored_file_id'])) {
            return false;
        }

        $tier = $this->resolveDownloadActionTier($package);
        $settingMap = [
            'free' => 'download_page_save_free',
            'premium' => 'download_page_save_premium',
            'admin' => 'download_page_save_admin',
        ];
        $settingKey = $tier !== null ? ($settingMap[$tier] ?? null) : null;
        if ($settingKey === null) {
            return false;
        }

        return Setting::get($settingKey, '1') === '1';
    }

    private function currentActorLabel(bool $adminAction): string
    {
        if (!$adminAction) {
            return 'You';
        }

        return 'Administrator';
    }

    private function renderCountryLookupUnavailableStatePage(?array $package = null, ?array $file = null): void
    {
        $shareFields = $file !== null ? $this->buildPublicShareFields($file) : [];
        $this->renderDownloadStatePage(
            'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
            'Download Temporarily Unavailable',
            'Your region could not be verified safely right now, so downloads are temporarily unavailable. Please try again later.',
            503,
            $package,
            $file,
            $shareFields
        );
    }

    private function enforceCountryDownloadPolicy(SecurityService $security, ?array $package = null, ?array $file = null, bool $ajax = false): bool
    {
        $decision = $security->evaluateCountryBlock(\App\Service\SecurityService::getClientIp());
        if (!empty($decision['blocked'])) {
            if ($ajax) {
                $this->respondJson([
                    'status' => 'error',
                    'message' => 'Downloads are not available in your region.',
                ], 403);
            } else {
                $this->renderDownloadStatePage(
                    'Region Blocked - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Downloads Unavailable',
                    'Downloads are not available in your region.',
                    403,
                    $package,
                    $file,
                    $file !== null ? $this->buildPublicShareFields($file) : []
                );
            }
            return false;
        }

        if (\App\Service\SecurityService::countryLookupRequiresFailClosed($decision)) {
            if ($ajax) {
                $this->respondJson([
                    'status' => 'error',
                    'message' => 'Your region could not be verified safely right now. Please try again later.',
                ], 503);
            } else {
                $this->renderCountryLookupUnavailableStatePage($package, $file);
            }
            return false;
        }

        return true;
    }

    public function emptyTrash()
    {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Login required']);
            return;
        }

        // Allow admins to empty trash regardless of the 'user_can_empty_trash' setting
        if (\App\Model\Setting::get('user_can_empty_trash', '1') !== '1' && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Emptying the trash is statically disabled by the server administrator.']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!\App\Core\Csrf::verify($csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF Token Invalid']);
            return;
        }

        $userId = Auth::id();
        $db = \App\Core\Database::getInstance()->getConnection();

        // Find all hard-deleted eligible files for this user
        $stmt = $db->prepare("SELECT id, stored_file_id FROM files WHERE user_id = ? AND status = 'deleted'");
        $stmt->execute([$userId]);
        $filesToEmpty = $stmt->fetchAll();
        $fileIdsToEmpty = array_values(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $filesToEmpty));
        $trashAudit = [
            'deleted_by_user_id' => $userId,
            'deleted_by_role' => Auth::isAdmin() ? 'admin' : 'user',
            'deleted_by_label' => $this->currentActorLabel(Auth::isAdmin()),
            'delete_reason' => 'Removed from trash.',
        ];

        try {
            \App\Model\File::validateHardDeleteBatch($fileIdsToEmpty, $trashAudit);
        } catch (\Throwable $e) {
            http_response_code(409);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        foreach ($filesToEmpty as $file) {
            try {
                \App\Model\File::hardDelete((int)$file['id'], $trashAudit);
            } catch (\Exception $e) {
                // Ignore silent errors for individual files so others continue
                \App\Core\Logger::error("Failed to empty trash file ID: " . $file['id'], ['error' => $e->getMessage()]);
            }
        }

        \App\Model\Folder::purgeDeletedByUser((int)$userId);

        echo json_encode(['success' => true, 'message' => 'Trash emptied successfully.', 'deleted_count' => count($filesToEmpty)]);
    }

    public function reportAbuse()
    {
        if (\App\Model\Setting::get('enable_abuse_reports', '1') === '0') die("Reports disabled.");
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Invalid Request");
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("Invalid request token.");
        }

        $captchaEnabled = Setting::get('captcha_report_file', '0') === '1';
        $captchaSiteKey = Setting::get('captcha_site_key', Config::get('turnstile.site_key'));
        if ($captchaEnabled && $captchaSiteKey === '') {
            http_response_code(503);
            die("File reports are temporarily unavailable because CAPTCHA is enabled but not fully configured.");
        }
        if ($captchaEnabled && !$this->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
            http_response_code(403);
            die("Captcha verification failed.");
        }

        $abuseRateLimit = \App\Service\TicketService::getRateLimitConfig('abuse_ip');
        if (!\App\Service\RateLimiterService::check('abuse_report_form', \App\Service\SecurityService::getClientIp(), (int)$abuseRateLimit['max'], (int)$abuseRateLimit['window'])) {
            http_response_code(429);
            die("Too many abuse reports have been submitted from your connection. Please wait and try again.");
        }

        $fileRef = trim((string)($_POST['file_id'] ?? ''));
        $reason = $_POST['reason'];
        $details = $_POST['details'] ?? '';
        $file = $this->resolvePostedFileReference($fileRef);
        if (!$file) {
            http_response_code(404);
            die("File not found.");
        }
        $fileId = (int)$file['id'];

        $reporterName = '';
        $reporterEmail = '';
        if (Auth::check()) {
            $user = \App\Model\User::find(Auth::id());
            if ($user) {
                $reporterName = Auth::username();
                $reporterEmail = !empty($user['email']) ? \App\Service\EncryptionService::decrypt($user['email']) : '';
            }
        }

        try {
            \App\Service\TicketService::createExternalTicket('abuse', [
                'subject' => 'Abuse report for ' . (string)$file['filename'],
                'body' => trim((string)$details) !== '' ? (string)$details : ('Reported reason: ' . strtoupper((string)$reason)),
                'name' => $reporterName,
                'email' => $reporterEmail,
                'user_id' => Auth::check() ? (int)Auth::id() : null,
                'related_file_id' => $fileId,
                'ip_address' => \App\Service\SecurityService::getClientIp(),
                'source' => 'abuse_form',
                'metadata' => [
                    'reason' => (string)$reason,
                    'file_name' => (string)$file['filename'],
                    'short_id' => (string)($file['short_id'] ?? ''),
                    'details' => (string)$details,
                ],
            ]);

            $filename = $file ? $file['filename'] : 'Unknown File';

            // Send Confirmation to Reporter if logged in and has email
            if ($reporterEmail !== '') {
                \App\Service\MailService::sendTemplate($reporterEmail, 'abuse_report_confirmation', [
                    '{username}' => $reporterName !== '' ? $reporterName : 'Reporter',
                    '{file_name}' => $filename
                ]);
            }

            echo "Success: Your report has been submitted.";
        } catch (\Throwable $e) {
            echo "Error: Failed to submit report.";
        }
    }

    public function togglePpd()
    {
        if (!Auth::check()) die("Login required");
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Method Not Allowed");
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF Mismatch");

        $fileId = (int)$_POST['file_id'];
        $status = (int)$_POST['status']; // 1 or 0

        $file = File::find($fileId);
        if ($file && $file['user_id'] === Auth::id()) {
            $db = \App\Core\Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE files SET allow_ppd = ? WHERE id = ?");
            $stmt->execute([$status, $fileId]);
            echo json_encode(['status' => 'success']);
        }
    }

    public function show(string $id)
    {
        // prevent clickjacking / iframe embedding
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: frame-ancestors \'self\'');

        $file = File::findByShortId($id);

        if (!$file) {
            $this->renderDownloadStatePage(
                'File Not Found - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'File Not Found',
                'This file is no longer available or the link is invalid.',
                404
            );
            return;
        }

        if (!$this->canAccessFile($file)) {
            $this->renderPrivateFilePage($file);
            return;
        }

        // Determine User Package
        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();

        $security = new SecurityService();

        // check require_account_to_download
        if (!Auth::check() && Setting::get('require_account_to_download', '0') === '1') {
            $this->renderDownloadStatePage(
                'Account Required - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Account Required',
                'You must register an account and log in before this file can be downloaded.',
                403,
                $package,
                $file,
                $this->buildPublicShareFields($file)
            );
            return;
        }

        if (!$this->enforceCountryDownloadPolicy($security, $package, $file)) {
            return;
        }

        // check vpn/proxy block (only hard-block in enforcement mode)
        $vpnMode = \App\Service\SecurityService::getVpnProtectionMode();
        $vpnScope = \App\Service\SecurityService::getVpnProtectionScope();
        $clientIp = \App\Service\SecurityService::getClientIp();
        $downloadVpnProtectionEnabled = $vpnScope === 'download_pages' || !empty($package['block_vpn']);
        $proxyIntel = null;
        if ($vpnMode === 'enforcement' && $downloadVpnProtectionEnabled) {
            $proxyIntel = $security->lookupProxyIntel($clientIp);
        }
        if ($vpnMode === 'enforcement' && $downloadVpnProtectionEnabled && (!empty($proxyIntel['is_proxy']) || \App\Service\SecurityService::proxyIntelRequiresFailClosed($proxyIntel ?? []))) {
            $this->renderVpnBlockedStatePage($package, $file);
            return;
        }

        // Eligible packages should auto-start downloads from the normal file URL.
        // The legacy `?direct=1` hint still works, but it is no longer required.
        if ($package['allow_direct_links']) {
            try {
                header('Location: ' . $this->issueTrackedDownloadUrl($file));
            } catch (\Throwable $e) {
                Logger::error('Direct download session issue failed', [
                    'file_id' => (int)($file['id'] ?? 0),
                    'error' => $e->getMessage(),
                ]);
                $this->renderDownloadStatePage(
                    'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Download Temporarily Unavailable',
                    'This download could not be started right now. Please refresh the file page and try again.',
                    503,
                    $package,
                    $file,
                    $this->buildPublicShareFields($file)
                );
            }
            exit;
        }

        // figure out if we show captcha for this user tier
        $downloadPageService = new DownloadPageService();
        $viewModel = $downloadPageService->buildShowViewModel($file, $package);
        $captchaDownload = (bool)$viewModel['captchaDownload'];
        $captchaSiteKey = (string)$viewModel['captchaSiteKey'];
        $waitTime = (int)$viewModel['waitTime'];
        if ($waitTime > 0) {
            $_SESSION['download_wait_starts'][$this->downloadWaitSessionKey($file, Auth::id() ? (int)Auth::id() : null)] = time();
        }
        $fraud = new \App\Service\RewardFraudService();
        $fraud->ensureVisitorCookie();
        $fraud->rememberDownloadPageReferrer((int)$file['id']);
        $streamingEligible = (bool)$viewModel['streamingEligible'];
        $streamSessionId = null;
        $streamUrl = null;
        $streamCsrf = Csrf::generate();
        $shareFields = $viewModel['shareFields'];
        if ($streamingEligible && !$captchaDownload && $waitTime <= 0) {
            $streamSession = $fraud->createDownloadSession($file, Auth::id() ? (int)Auth::id() : null, [], 'stream');
            $streamSessionId = $streamSession['public_id'] ?? null;
            if ($streamSessionId !== null) {
                $streamUrl = (new DownloadManager())->generateSignedUrl((string)($file['short_id'] ?? $file['id']), $file['filename'], $streamSessionId, 'stream') . '&stream=1';
            }
        }
        $displayMimeType = $downloadPageService->resolveDisplayMimeType($file);
        $downloadActionVisible = $this->canCurrentUserSaveDownloadedFile($file, $package);
        $downloadAlreadySaved = Auth::check() && Setting::get('upload_detect_duplicates', '1') === '1'
            ? File::userHasStoredFile((int)(Auth::id() ?? 0), (int)$file['stored_file_id'])
            : false;
        $canDeleteFile = $this->currentUserOwnsFile($file);
        $deleteRequiresReason = false;
        $title = (string)$viewModel['title'];
        $metaDescription = (string)$viewModel['metaDescription'];

        require_once dirname(__DIR__, 1) . '/View/home/header.php';
        View::render('home/partials/download_show_page.php', array_merge($viewModel, [
            'streamUrl' => $streamUrl,
            'displayMimeType' => $displayMimeType,
            'streamSessionId' => $streamSessionId,
            'streamCsrf' => $streamCsrf,
            'downloadActionVisible' => $downloadActionVisible,
            'downloadAlreadySaved' => $downloadAlreadySaved,
            'canDeleteFile' => $canDeleteFile,
            'deleteRequiresReason' => $deleteRequiresReason,
        ]));
        require_once dirname(__DIR__, 1) . '/View/home/footer.php';
    }

    public function generateLink()
    {
        // prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $respondJsonError = static function (string $message, int $statusCode = 400): void {
            http_response_code($statusCode);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => $message,
            ]);
            exit;
        };

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($isAjax) {
                $respondJsonError('Invalid request.', 405);
            }
            die('Invalid Request');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            if ($isAjax) {
                $respondJsonError('Security token expired. Please refresh the file page and try again.', 403);
            }
            die('Error: Security Token Expired. Please refresh.');
        }

        $fileId = trim((string)($_POST['file_id'] ?? ''));
        $manager = new DownloadManager();
        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
        $security = new SecurityService();
        $fraud = new \App\Service\RewardFraudService();
        $file = File::findByShortId($fileId);
        if (is_array($file)) {
            $this->maybeIssuePpsReferralCookie($file);
        }
        $shareFields = is_array($file) ? $this->buildPublicShareFields($file) : [];

        // check require_account_to_download (again, to stop direct POST manipulation)
        if (!Auth::check() && Setting::get('require_account_to_download', '0') === '1') {
            if ($isAjax) {
                $respondJsonError('You must register an account and log in before this file can be downloaded.', 403);
            }
            $this->renderDownloadStatePage(
                'Account Required - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Account Required',
                'You must register an account and log in before this file can be downloaded.',
                403,
                $package,
                is_array($file) ? $file : null,
                $shareFields
            );
            return;
        }

        if (!$this->enforceCountryDownloadPolicy($security, $package, is_array($file) ? $file : null, $isAjax)) {
            return;
        }

        // check vpn/proxy (again, to prevent bypassing ui check - enforcement mode only)
        $vpnMode = $vpnMode ?? \App\Service\SecurityService::getVpnProtectionMode();
        $vpnScope = $vpnScope ?? \App\Service\SecurityService::getVpnProtectionScope();
        $downloadVpnProtectionEnabled = $vpnScope === 'download_pages' || !empty($package['block_vpn']);
        $downloadClientIp = \App\Service\SecurityService::getClientIp();
        $proxyIntel = null;
        if ($vpnMode === 'enforcement' && $downloadVpnProtectionEnabled) {
            $proxyIntel = $security->lookupProxyIntel($downloadClientIp);
        }
        if ($vpnMode === 'enforcement' && $downloadVpnProtectionEnabled && (!empty($proxyIntel['is_proxy']) || \App\Service\SecurityService::proxyIntelRequiresFailClosed($proxyIntel ?? []))) {
            $file = File::findByShortId($fileId);
            if ($isAjax) {
                $respondJsonError('VPN or proxy use is blocked for this download. Disable it, refresh the file page, and try again.', 403);
            }
            $this->renderVpnBlockedStatePage($package, is_array($file) ? $file : null);
            return;
        }

        // check rate limit
        try {
            $withinRateLimit = $manager->checkRateLimit(\App\Service\SecurityService::getClientIp());
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === DownloadManager::DOWNLOAD_RATE_LIMIT_STORAGE_UNAVAILABLE_MESSAGE) {
                if ($isAjax) {
                    $respondJsonError($e->getMessage(), 503);
                }
                $this->renderDownloadStatePage(
                    'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Download Temporarily Unavailable',
                    $e->getMessage(),
                    503,
                    $package,
                    is_array($file) ? $file : null,
                    $shareFields
                );
                return;
            }
            throw $e;
        }

        if (!$withinRateLimit) {
            if ($isAjax) {
                $respondJsonError('Too many download attempts were made from your connection. Please try again in 10 minutes.', 429);
            }
            $this->renderDownloadStatePage(
                'Download Rate Limit Reached - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Please Wait Before Trying Again',
                'Too many download attempts were made from your connection. Please try again in 10 minutes.',
                429,
                $package,
                is_array($file) ? $file : null,
                $shareFields
            );
            return;
        }

        // check referrer (anti-hotlink for the button click)
        if (!$manager->validateRequestSource()) {
            if ($isAjax) {
                $respondJsonError('Please start this download from the official file page.', 403);
            }
            $this->renderDownloadStatePage(
                'Invalid Download Source - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Invalid Download Source',
                'Please start this download from the official file page.',
                403,
                $package,
                is_array($file) ? $file : null,
                $shareFields
            );
            return;
        }

        // verify turnstile captcha only if it was shown for this user tier
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $isGuest = !Auth::check();
        $needCaptcha = false;
        if ($isGuest && Setting::get('captcha_download_guest', '0') === '1') $needCaptcha = true;
        if (!$isGuest && Setting::get('captcha_download_free', '0')  === '1') $needCaptcha = true;
        if ($needCaptcha && $captchaSiteKey === '') {
            if ($isAjax) {
                $respondJsonError('Download verification is temporarily unavailable because CAPTCHA is enabled but not fully configured.', 503);
            }
            $this->renderDownloadStatePage(
                'Download Verification Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Download Verification Unavailable',
                'This download is temporarily unavailable because CAPTCHA verification is enabled but not fully configured.',
                503,
                $package,
                is_array($file) ? $file : null,
                $shareFields
            );
            return;
        }
        if ($needCaptcha && !$manager->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
            if ($isAjax) {
                $respondJsonError('CAPTCHA verification failed. Please try again.', 403);
            }
            $this->renderDownloadStatePage(
                'CAPTCHA Verification Failed - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'Verification Failed',
                'CAPTCHA verification failed. Please return to the file page and try again.',
                403,
                $package,
                is_array($file) ? $file : null,
                $shareFields
            );
            return;
        }

        // generate signed url
        if (!$file) {
            if ($isAjax) {
                $respondJsonError('This file is no longer available or the link is invalid.', 404);
            }
            $this->renderDownloadStatePage(
                'File Not Found - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'File Not Found',
                'This file is no longer available or the link is invalid.',
                404,
                $package
            );
            return;
        }

        if (!$this->canAccessFile($file)) {
            if ($isAjax) {
                $respondJsonError('This file is not available.', 403);
            }
            $this->renderPrivateFilePage($file);
            return;
        }

        $waitTime = ((int)($package['wait_time_enabled'] ?? 0)) === 1 ? max(0, (int)($package['wait_time'] ?? 0)) : 0;
        if ($waitTime > 0) {
            $waitKey = $this->downloadWaitSessionKey($file, Auth::id() ? (int)Auth::id() : null);
            $startedAt = (int)($_SESSION['download_wait_starts'][$waitKey] ?? 0);
            if ($startedAt <= 0 || (time() - $startedAt) < $waitTime) {
                $remaining = $startedAt > 0 ? max(1, $waitTime - (time() - $startedAt)) : $waitTime;
                $message = 'Please wait ' . $remaining . ' more seconds on the download page before starting this file.';
                if ($isAjax) {
                    $respondJsonError($message, 429);
                }
                $this->renderDownloadStatePage(
                    'Please Wait Before Downloading - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Please Wait Before Downloading',
                    $message,
                    429,
                    $package,
                    $file,
                    $shareFields
                );
                return;
            }
        }
        try {
            $url = $this->issueTrackedDownloadUrl($file, [
                'timezone_offset' => $_POST['timezone_offset'] ?? null,
                'platform_bucket' => $_POST['platform_bucket'] ?? '',
                'screen_bucket' => $_POST['screen_bucket'] ?? '',
            ]);
            if ($waitTime > 0) {
                unset($_SESSION['download_wait_starts'][$waitKey]);
            }
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === DownloadManager::DOWNLOAD_LINK_TRACKING_UNAVAILABLE_MESSAGE) {
                if ($isAjax) {
                    $respondJsonError($e->getMessage(), 503);
                }
                $this->renderDownloadStatePage(
                    'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Download Temporarily Unavailable',
                    $e->getMessage(),
                    503,
                    $package,
                    is_array($file) ? $file : null,
                    $shareFields
                );
                return;
            }
            throw $e;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'download_url' => $url,
            ]);
            exit;
        }

        // Redirect to the signed URL (which triggers the download)
        header("Location: $url");
        exit;
    }

    public function download(string $id)
    {
        $token = $_GET['token'] ?? '';
        $expires = (int)($_GET['expires'] ?? 0);
        $sessionId = trim((string)($_GET['session'] ?? ''));
        $streamMode = isset($_GET['stream']) && $_GET['stream'] === '1';
        $downloadLinkContext = null;
        $file = File::findByShortId($id);
        if (!$file) {
            $this->renderDownloadStatePage(
                'File Not Found - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'File Not Found',
                'This file is no longer available or the link is invalid.',
                404
            );
            return;
        }

        if (!$this->canAccessFile($file)) {
            $this->renderPrivateFilePage($file);
            return;
        }

        // validate token (anti-leech)
        // Bypass signature check if the user is the owner or an admin
        $isOwner = $this->currentUserOwnsFile($file);
        if (!$isOwner) {
            $manager = new \App\Service\DownloadManager();
            if (!$manager->validateSignature($id, $token, $expires, $sessionId !== '' ? $sessionId : null, $streamMode ? 'stream' : 'download')) {
                $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
                $this->renderDownloadStatePage(
                    'Download Link Expired - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Download Link Expired',
                    'This download link is invalid or has expired. Please return to the file page and try again.',
                    403,
                    $package,
                    $file,
                    $this->buildPublicShareFields($file)
                );
                return;
            }
            if (!$streamMode) {
                $downloadLinkContext = [
                    'file_id' => (string)$id,
                    'token' => (string)$token,
                    'session_id' => $sessionId !== '' ? $sessionId : null,
                ];
            }
        }

        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
        $security = new SecurityService();
        if (!$this->enforceCountryDownloadPolicy($security, $package, $file)) {
            return;
        }
        $clientIp = \App\Service\SecurityService::getClientIp();
        $downloadEventKey = $this->buildDownloadBandwidthEventKey((string)$id, (string)$token, (int)$expires, (string)$sessionId, $streamMode, $isOwner);
        $this->enforceDailyDownloadLimit($package ?? [], $file, Auth::id() ? (int)Auth::id() : null, $clientIp, $downloadEventKey);

        $fraud = new \App\Service\RewardFraudService();
        $rewardSessionId = null;
        $validatedSession = null;
        if ($sessionId !== '') {
            $validatedSession = $fraud->validateSessionForCurrentVisitor($sessionId, $file);
            if (!$validatedSession) {
                $this->renderDownloadStatePage(
                    'Download Session Expired - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Download Session Expired',
                    'This download session is invalid or has expired. Please return to the file page and try again.',
                    403,
                    $package,
                    $file,
                    $this->buildPublicShareFields($file)
                );
                return;
            }
            if ($streamMode || $fraud->shouldRequireVerifiedCompletion($file)) {
                $rewardSessionId = $sessionId;
            }
        }

        // use the database ID for subsequent calls
        $fileId = $file['id'];

        $shouldCommitObservedStart = $this->shouldCommitObservedDownloadStart($streamMode, $validatedSession);

        // serving logic
        $this->serveFile($file, $rewardSessionId, true, $streamMode, $validatedSession, $shouldCommitObservedStart, $downloadLinkContext);
    }

    public function streamHeartbeat()
    {
        header('Content-Type: application/json');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF mismatch']);
            exit;
        }

        if (Setting::get('streaming_support_enabled', '0') !== '1') {
            http_response_code(404);
            echo json_encode(['error' => 'Streaming support is disabled']);
            exit;
        }

        $fileRef = trim((string)($_POST['file_id'] ?? ''));
        $file = $this->resolvePostedFileReference($fileRef);
        if (!$file || !$this->isVideoFile($file)) {
            http_response_code(404);
            echo json_encode(['error' => 'Video file not found']);
            exit;
        }

        $this->enforceFileAccess($file);

        $fraud = new \App\Service\RewardFraudService();
        $sessionId = trim((string)($_POST['session_id'] ?? ''));
        $session = $fraud->validateSessionForCurrentVisitor($sessionId, $file);
        if (!$session) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid streaming session']);
            exit;
        }

        $watchSeconds = max(0, (int)($_POST['watch_seconds'] ?? 0));
        $watchPercent = max(0, min(100, (float)($_POST['watch_percent'] ?? 0)));
        $state = trim((string)($_POST['state'] ?? 'progress'));

        $telemetry = $fraud->recordStreamHeartbeat($sessionId, $file, $watchSeconds, $watchPercent, [
            'current_time' => (float)($_POST['current_time'] ?? 0),
            'duration' => (float)($_POST['duration'] ?? 0),
            'state' => $state,
        ]);
        if (!$telemetry) {
            http_response_code(422);
            echo json_encode(['error' => 'Streaming telemetry was rejected']);
            exit;
        }

        if ($state === 'complete') {
            $result = $fraud->completeStreamSession($sessionId, $file, Auth::id() ? (int)Auth::id() : null);
            if ($result) {
                if (($result['proof_status'] ?? '') === 'verified_stream') {
                    (new \App\Service\RewardService())->trackDownload($file['id'], \App\Service\SecurityService::getClientIp(), Auth::id() ? (int)Auth::id() : null, [
                        'session_id' => $result['session']['id'] ?? null,
                        'proof_status' => 'verified_stream',
                        'asn' => $result['session']['asn'] ?? '',
                        'network_type' => $result['session']['network_type'] ?? '',
                    ] + $fraud->exportRewardSignalContext($result['session'] ?? []));
                }
                echo json_encode([
                    'status' => $result['proof_status'] ?? 'verified_stream',
                    'message' => empty($result['reasons']) ? 'Watch threshold met. Reward session verified.' : 'Playback completed, but the session is still under review.',
                ]);
                exit;
            }
        }

        echo json_encode([
            'status' => 'progress',
            'message' => 'Streaming progress saved.',
        ]);
        exit;
    }

    private function serveFile(array $file, ?string $rewardSessionId = null, bool $allowRewardTracking = true, bool $streamMode = false, ?array $validatedSession = null, bool $shouldCommitObservedStart = true, ?array $downloadLinkContext = null)
    {
        $db = \App\Core\Database::getInstance()->getConnection();
        $minPercent = (int)\App\Model\Setting::get('ppd_min_download_percent', '0');
        $payoutPolicy = new StandardFilePayoutPolicy();
        $fraud = new \App\Service\RewardFraudService();
        $requiresVerified = $allowRewardTracking && $rewardSessionId !== null && $fraud->shouldRequireVerifiedCompletion($file);
        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
        $requiresTrackedConcurrency = $this->packageHasTrackedConcurrentLimit($package);
        $speedLimit = (int)($package['download_speed'] ?? 0);
        $needsObservedStartCommit = $shouldCommitObservedStart && $this->downloadStartWouldMutateState($file, $validatedSession);

        // Try the fast provider-direct path before any storage HEAD/repair work.
        // For cloud-backed downloads this avoids an extra round trip before the browser
        // is redirected to the provider.
        if (!$needsObservedStartCommit && $minPercent <= 0 && !$requiresVerified && !$streamMode && !$requiresTrackedConcurrency && $speedLimit <= 0) {
            try {
                $delivery = (new DownloadManager())->previewDelivery($file);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === \App\Core\StorageManager::MISSING_FILE_SERVER_MESSAGE) {
                    $this->renderStorageNodeUnavailableStatePage($package, $file);
                    return;
                }
                throw $e;
            }
            if (!empty($delivery['url'])) {
                if (!$this->consumeDownloadLinkAtDeliveryBoundary($downloadLinkContext, $package, $file)) {
                    return;
                }
                if ($file['user_id']) {
                    (new \App\Service\RewardService())->trackDownload(
                        $file['id'],
                        \App\Service\SecurityService::getClientIp(),
                        Auth::id() ? (int)Auth::id() : null
                    );
                }
                header("Location: " . $delivery['url']);
                exit;
            }
        }

        try {
            $storage = \App\Core\StorageManager::getProviderById($file['file_server_id'] ? (int)$file['file_server_id'] : null, $db);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === \App\Core\StorageManager::MISSING_FILE_SERVER_MESSAGE) {
                $this->renderStorageNodeUnavailableStatePage($package, $file);
                return;
            }
            throw $e;
        }

        if (!$this->isStoredObjectHealthy($storage, $file)) {
            Logger::error('Download blocked because stored object is missing or unhealthy', [
                'file_id' => (int)($file['id'] ?? 0),
                'stored_file_id' => (int)($file['stored_file_id'] ?? 0),
                'storage_path' => (string)($file['storage_path'] ?? ''),
                'file_server_id' => (int)($file['file_server_id'] ?? 0),
            ]);
            $this->renderDownloadStatePage(
                'File Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'File Temporarily Unavailable',
                'This file is temporarily unavailable. Please try again later.',
                404,
                $package,
                $file,
                $this->buildPublicShareFields($file)
            );
            exit;
        }

        // fallback to proxy/local serve
        $path = $storage->getAbsolutePath($file['storage_path']);

        // Security: never trust mime_type stored in DB for Content-Type.
        // Always force application/octet-stream so browsers download instead of execute.
        $mimeType = $streamMode ? $this->resolveDisplayMimeType($file) : 'application/octet-stream';

        // Security: strip any characters that could break the Content-Disposition header
        $safeFilename = preg_replace('/[\x00-\x1F"\'\\\\]/', '_', $file['filename']);

        // Clear output buffers so the download can start streaming immediately.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $seekStart = 0;
        $rangeEnd = max(0, ((int)($file['file_size'] ?? 0)) - 1);
        $contentLength = (int)($file['file_size'] ?? 0);
        if ($streamMode && !empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $matches)) {
            $seekStart = (int)$matches[1];
            if ($matches[2] !== '') {
                $rangeEnd = min($rangeEnd, (int)$matches[2]);
            }
            $contentLength = max(0, ($rangeEnd - $seekStart) + 1);
            http_response_code(206);
            header('Accept-Ranges: bytes');
            header("Content-Range: bytes {$seekStart}-{$rangeEnd}/" . (int)($file['file_size'] ?? 0));
        }

        header("Content-Type: $mimeType");
        header("Content-Disposition: " . ($streamMode ? "inline" : "attachment") . "; filename=\"$safeFilename\"");
        header("Content-Length: " . $contentLength);
        header("X-Content-Type-Options: nosniff");
        header("Content-Security-Policy: default-src 'none'");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("X-Accel-Buffering: no");

        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        session_write_close();

        $method = 'php';
        if (!empty($file['file_server_id'])) {
            $stmt = $db->prepare("SELECT delivery_method FROM file_servers WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$file['file_server_id']]);
            $method = $stmt->fetchColumn() ?: 'php';
        }

        $capabilities = method_exists($storage, 'getCapabilities') ? $storage->getCapabilities() : [];
        if (in_array($method, ['apache', 'litespeed'], true) && !empty($capabilities['presigned_download'])) {
            $method = 'php';
        }

        if ($speedLimit > 0 && !in_array($method, ['php', 'nginx'], true)) {
            $method = 'php';
        }

        $requiresPercentVerification = $minPercent > 0 && !$streamMode && !$requiresVerified;

        // Apache and LiteSpeed still fall back to PHP when percent-based reward proof is required.
        if ($requiresVerified || $streamMode || ($requiresPercentVerification && !in_array($method, ['php', 'nginx'], true))) {
            $method = 'php';
        }

        if ($needsObservedStartCommit && !in_array($method, ['php', 'nginx'], true)) {
            $method = 'php';
        }

        $activeSessionId = $rewardSessionId;
        if ($activeSessionId === null && $streamMode) {
            $activeSessionId = trim((string)($_GET['session'] ?? '')) ?: null;
        }

        $clientIp = \App\Service\SecurityService::getClientIp();
        $activeDownloadId = 0;
        $shouldTrackConnections = Setting::get('track_current_downloads', '0') === '1';
        $needsNginxPayoutState = $method === 'nginx'
            && !$streamMode
            && $rewardSessionId === null
            && !empty($file['user_id'])
            && \App\Service\FeatureService::rewardsEnabled();
        $needsNginxDownloadStartState = $method === 'nginx' && $needsObservedStartCommit;
        $activeDownloadContext = [];
        if ($shouldTrackConnections || $needsNginxPayoutState || $needsNginxDownloadStartState) {
            if (is_array($validatedSession) && !empty($validatedSession)) {
                $activeDownloadContext = $fraud->exportRewardSignalContext($validatedSession);
                $activeDownloadContext['session_id'] = (int)($validatedSession['id'] ?? 0);
            } else {
                $activeDownloadContext = $fraud->buildClientSignals([], $clientIp);
            }
        }
        if ($shouldTrackConnections) {
            $activeDownloadId = $this->claimConcurrentDownloadSlot($package, $file, $clientIp, $activeDownloadContext);
        } elseif ($needsNginxPayoutState || $needsNginxDownloadStartState) {
            $activeDownloadId = $this->registerActiveDownload((int)$file['id'], Auth::id() ? (int)Auth::id() : null, $clientIp, $activeDownloadContext);
        }

        if (!$this->consumeDownloadLinkAtDeliveryBoundary($downloadLinkContext, $package, $file, $activeDownloadId)) {
            return;
        }

        if ($activeSessionId !== null) {
            $fraud->markSessionStarted($activeSessionId, $streamMode ? 'stream_php' : (string)$method);
        }

        if ($method === 'nginx') {
            $safePath = preg_replace('/[^a-zA-Z0-9\/\._-]/', '', $file['storage_path']);
            if ($activeDownloadId > 0) {
                header("X-FYUHLS-Download-Id: " . $activeDownloadId);
            }
            header("X-FYUHLS-File-Id: " . (int)$file['id']);
            header("X-FYUHLS-Viewer-Id: " . (Auth::id() ? (int)Auth::id() : 0));
            header("X-FYUHLS-Original-URI: " . (string)($_SERVER['REQUEST_URI'] ?? ''));
            if ($requiresPercentVerification) {
                header("X-FYUHLS-Payout-Mode: standard-file-threshold");
            }
            header("X-Accel-Redirect: /protected_uploads/" . $safePath);
            if ($speedLimit > 0) header("X-Accel-Limit-Rate: $speedLimit");
        }
        elseif ($method === 'apache') {
            if ($activeDownloadId > 0) {
                header("X-FYUHLS-Download-Id: " . $activeDownloadId);
            }
            header("X-SendFile: $path");
            if ($file['user_id']) {
                (new \App\Service\RewardService())->trackDownload($file['id'], $clientIp, Auth::id() ? (int)Auth::id() : null, [
                    'proof_status' => 'handoff_start',
                ]);
            }
        }
        elseif ($method === 'litespeed') {
            if ($activeDownloadId > 0) {
                header("X-FYUHLS-Download-Id: " . $activeDownloadId);
            }
            header("X-LiteSpeed-Location: $path");
            if ($file['user_id']) {
                (new \App\Service\RewardService())->trackDownload($file['id'], $clientIp, Auth::id() ? (int)Auth::id() : null, [
                    'proof_status' => 'handoff_start',
                ]);
            }
        }
        else {
            // Manual Streaming (php method)
            $ip = $clientIp;
            $downloadId = $activeDownloadId;

            set_time_limit(0);
            ignore_user_abort(true);

            $credited = false;
            $downloadStartCommitted = !$needsObservedStartCommit;
            $fileSize = (float)$file['file_size'];

            // 2. Stream with Progress Callback
            $storage->stream($file['storage_path'], $seekStart, function($bytesSent) use ($db, $downloadId, $file, $ip, $minPercent, $fileSize, &$credited, $rewardSessionId, $fraud, $streamMode, $payoutPolicy, &$downloadStartCommitted, $validatedSession) {
                // Update active_downloads periodically
                static $lastUpdate = 0;
                if ($downloadId > 0 && time() - $lastUpdate >= 2) {
                    $upd = $db->prepare("UPDATE active_downloads SET bytes_sent = ? WHERE id = ?");
                    $upd->execute([$bytesSent, $downloadId]);
                    $lastUpdate = time();
                }

                if (!$downloadStartCommitted && $bytesSent > 0) {
                    $this->commitObservedDownloadStart($file, $validatedSession);
                    $downloadStartCommitted = true;
                }

                if ($rewardSessionId !== null && !$streamMode) {
                    $fraud->recordDownloadProgress($rewardSessionId, (int)$bytesSent, (int)$fileSize);
                }

                // Check for PPD Credit
                if (!$credited && $rewardSessionId === null && $minPercent > 0 && $file['user_id']) {
                    $decision = $payoutPolicy->evaluate([
                        'delivery_mode' => 'php',
                        'file_size' => (int)$fileSize,
                        'bytes_sent' => $bytesSent,
                        'min_percent' => $minPercent,
                        'stream_mode' => $streamMode,
                    ]);

                    if ($decision['eligible']) {
                        $context = [];
                        if ($downloadId > 0) {
                            $context['source_event_key'] = $this->buildStandardFilePayoutEventKey($downloadId);
                        }
                        (new \App\Service\RewardService())->trackDownload($file['id'], $ip, Auth::id() ? (int)Auth::id() : null, $context);
                        $this->logStandardFilePayoutEvent('credited', [
                            'file_id' => (int)$file['id'],
                            'download_id' => $downloadId > 0 ? $downloadId : null,
                            'delivery_mode' => 'php',
                            'reason_code' => $decision['reason_code'],
                            'observed_bytes' => $decision['observed_bytes'],
                            'required_bytes' => $decision['required_bytes'],
                            'min_percent' => $decision['min_percent'],
                        ]);
                        $credited = true;
                    }
                }
            }, $contentLength);

            if (!$downloadStartCommitted && $contentLength === 0) {
                $this->commitObservedDownloadStart($file, $validatedSession);
                $downloadStartCommitted = true;
            }

            // 3. Final instant credit if minPercent is 0 and we haven't credited yet
            if ($rewardSessionId !== null && !$streamMode && $file['user_id']) {
                $sessionResult = $fraud->finalizeDownloadSession($rewardSessionId, $file, $ip, Auth::id() ? (int)Auth::id() : null);
                if ($sessionResult && ($sessionResult['proof_status'] ?? '') === 'verified') {
                    (new \App\Service\RewardService())->trackDownload($file['id'], $ip, Auth::id() ? (int)Auth::id() : null, [
                        'session_id' => $sessionResult['session']['id'] ?? null,
                        'proof_status' => $sessionResult['proof_status'] ?? 'verified',
                        'asn' => $sessionResult['session']['asn'] ?? '',
                        'network_type' => $sessionResult['session']['network_type'] ?? '',
                    ] + $fraud->exportRewardSignalContext($sessionResult['session'] ?? []));
                }
            } elseif (!$credited && $minPercent <= 0 && $file['user_id']) {
                (new \App\Service\RewardService())->trackDownload($file['id'], $ip, Auth::id() ? (int)Auth::id() : null);
            }

            // 4. Cleanup
            if ($downloadId > 0) {
                $this->removeActiveDownload($downloadId);
            }
        }
        exit;
    }

    private function consumeDownloadLinkAtDeliveryBoundary(?array $downloadLinkContext, array $package, array $file, int $activeDownloadId = 0): bool
    {
        if (!is_array($downloadLinkContext) || $downloadLinkContext === []) {
            return true;
        }

        try {
            $consumed = (new DownloadManager())->consumeIssuedDownloadLink(
                (string)($downloadLinkContext['file_id'] ?? ''),
                (string)($downloadLinkContext['token'] ?? ''),
                isset($downloadLinkContext['session_id']) && $downloadLinkContext['session_id'] !== ''
                    ? (string)$downloadLinkContext['session_id']
                    : null
            );
        } catch (\RuntimeException $e) {
            if ($activeDownloadId > 0) {
                $this->removeActiveDownload($activeDownloadId);
            }
            if ($e->getMessage() === DownloadManager::DOWNLOAD_LINK_TRACKING_UNAVAILABLE_MESSAGE) {
                $this->renderDownloadStatePage(
                    'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'Download Temporarily Unavailable',
                    $e->getMessage(),
                    503,
                    $package,
                    $file,
                    $this->buildPublicShareFields($file)
                );
                return false;
            }
            throw $e;
        }

        if ($consumed) {
            return true;
        }

        if ($activeDownloadId > 0) {
            $this->removeActiveDownload($activeDownloadId);
        }

        $this->renderDownloadStatePage(
            'Download Link Expired - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
            'Download Link Expired',
            'This download link has already been used or has expired. Please return to the file page and try again.',
            403,
            $package,
            $file,
            $this->buildPublicShareFields($file)
        );
        return false;
    }

    private function isVideoFile(array $file): bool
    {
        return str_starts_with($this->resolveDisplayMimeType($file), 'video/');
    }

    private function resolveDisplayMimeType(array $file): string
    {
        $mimeType = (string)($file['mime_type'] ?? 'application/octet-stream');
        if (str_starts_with($mimeType, 'ENC:')) {
            $mimeType = \App\Service\EncryptionService::decrypt($mimeType);
        }
        return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType) ? $mimeType : 'application/octet-stream';
    }

    private function shouldCommitObservedDownloadStart(bool $streamMode, ?array $validatedSession = null): bool
    {
        if (!$streamMode) {
            return true;
        }

        if (!is_array($validatedSession) || empty($validatedSession)) {
            return true;
        }

        return empty($validatedSession['download_counted_at']);
    }

    private function downloadStartWouldMutateState(array $file, ?array $validatedSession = null): bool
    {
        return (new \App\Service\DownloadStartService())->wouldMutateState(
            $file,
            Auth::id() ? (int)Auth::id() : null,
            $validatedSession
        );
    }

    private function commitObservedDownloadStart(array $file, ?array $validatedSession = null): bool
    {
        $sessionId = is_array($validatedSession) && !empty($validatedSession['id'])
            ? (int)$validatedSession['id']
            : null;

        return (new \App\Service\DownloadStartService())->commit(
            $file,
            Auth::id() ? (int)Auth::id() : null,
            $sessionId
        );
    }

    public function nginxDownloadCompleted()
    {
        $this->logStandardFilePayoutEvent('callback_deprecated', [
            'delivery_mode' => 'nginx',
            'reason_code' => 'use_nginx_completion_log',
            'download_id' => ctype_digit((string)($_SERVER['HTTP_X_FYUHLS_DOWNLOAD_ID'] ?? '')) ? (int)$_SERVER['HTTP_X_FYUHLS_DOWNLOAD_ID'] : null,
            'status' => (string)($_SERVER['HTTP_X_STATUS'] ?? ''),
        ]);
        http_response_code(204);
        exit;
    }

    public function upload()
    {
        http_response_code(410);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Legacy browser uploads have been removed. Use the multipart upload API under /api/v1/uploads/sessions.'
        ]);
    }

    public function cancelUpload()
    {
        http_response_code(410);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Legacy chunk cancellation has been removed. Use /api/v1/uploads/sessions/{id}/abort.'
        ]);
    }

    public function thumbnail($hash)
    {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $thumbnailRoot = $root . '/storage/uploads/thumbnails';
        $hash = trim((string)$hash, '/');
        if ($hash === '' || str_contains($hash, '..')) {
            http_response_code(404);
            exit;
        }

        $segments = explode('/', str_replace('\\', '/', $hash));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[a-zA-Z0-9._-]+$/', $segment)) {
                http_response_code(404);
                exit;
            }
        }

        $path = $thumbnailRoot . '/' . implode('/', $segments);

        if (!file_exists($path)) {
            http_response_code(404);
            exit;
        }

        $realRoot = realpath($thumbnailRoot);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
        readfile($realPath);
        exit;
    }

    public function bulkDelete()
    {
        try {
            $this->checkAuth();
            $ids = $_POST['ids'] ?? [];
            if (empty($ids)) {
                $this->respondJson(['status' => 'error', 'error' => 'No items selected'], 422);
                return;
            }
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $this->respondJson(['status' => 'error', 'error' => 'CSRF mismatch'], 403);
                return;
            }

            $items = $this->resolveOwnedBulkItems((array)$ids, 'any', 'any');
            $deletedCount = 0;
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $file = $item['row'];
                    File::hardDelete((int)$file['id'], [
                        'deleted_by_user_id' => Auth::id(),
                        'deleted_by_role' => 'user',
                        'deleted_by_label' => $this->currentActorLabel(false),
                        'delete_reason' => 'Deleted from bulk action.',
                    ]);
                    $deletedCount++;
                    Auth::logActivity('delete_file', "Bulk deleted file: " . $file['filename']);
                } else {
                    $folder = $item['row'];
                    $folderId = (int)$folder['id'];
                    $subfolderIds = \App\Model\Folder::getRecursiveSubfolderIds($folderId);
                    $allFolderIds = array_merge([$folderId], $subfolderIds);
                    $db = \App\Core\Database::getInstance()->getConnection();
                    $inClause = implode(',', array_map('intval', $allFolderIds));
                    $stmt = $db->query("SELECT COUNT(*) FROM files WHERE folder_id IN ($inClause)");
                    $deletedCount += (int)$stmt->fetchColumn();

                    \App\Model\Folder::hardDeleteTree($folderId, [
                        'deleted_by_user_id' => Auth::id(),
                        'deleted_by_role' => 'user',
                        'deleted_by_label' => $this->currentActorLabel(false),
                        'delete_reason' => 'Deleted from bulk action.',
                    ]);
                    $deletedCount++;
                    Auth::logActivity('delete_folder', "Bulk deleted folder (and contents): " . $folder['name']);
                }
            }
            $this->respondJson(['status' => 'success', 'message' => "Deleted $deletedCount items"]);
        } catch (\RuntimeException $e) {
            $this->respondJson(['status' => 'error', 'error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            Logger::error('Bulk delete failed', ['error' => $e->getMessage()]);
            $this->respondJson(['status' => 'error', 'error' => 'Server error. Please try again.'], 500);
        }
    }

    private function renderStorageNodeUnavailableStatePage(array $package, array $file): void
    {
        $this->renderDownloadStatePage(
            'Download Temporarily Unavailable - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
            'Download Temporarily Unavailable',
            'This file is temporarily unavailable until an administrator repairs file storage.',
            503,
            $package,
            $file,
            $this->buildPublicShareFields($file)
        );
    }

    public function bulkTrash()
    {
        $this->checkAuth();
        $ids = $_POST['ids'] ?? [];
        if (empty($ids)) die(json_encode(['status' => 'error', 'error' => 'No items selected']));
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF mismatch']));

        $db = \App\Core\Database::getInstance()->getConnection();
        $bonusTouchUserIds = [];
        try {
            $items = $this->resolveOwnedBulkItems((array)$ids, 'active', 'active');
            $db->beginTransaction();
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $bonusTouchUserIds = array_merge($bonusTouchUserIds, File::trash((int)$item['row']['id']));
                } else {
                    $bonusTouchUserIds = array_merge($bonusTouchUserIds, \App\Model\Folder::trashTree((int)$item['row']['id']));
                }
            }
            $db->commit();
            $bonusTouchUserIds = array_values(array_unique(array_filter(array_map('intval', $bonusTouchUserIds), static fn (int $userId): bool => $userId > 0)));
            if ($bonusTouchUserIds !== []) {
                \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                    'workflow' => 'bulk_trash',
                    'item_count' => count($items),
                ]);
            }
            $this->respondJson(['status' => 'success']);
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondJson(['status' => 'error', 'error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::error('Bulk trash failed', ['error' => $e->getMessage()]);
            $this->respondJson(['status' => 'error', 'error' => 'Server error. Please try again.'], 500);
        }
    }

    public function bulkRestore()
    {
        $this->checkAuth();
        $ids = $_POST['ids'] ?? [];
        if (empty($ids)) die(json_encode(['status' => 'error', 'error' => 'No items selected']));
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF mismatch']));

        $db = \App\Core\Database::getInstance()->getConnection();
        $bonusTouchUserIds = [];
        try {
            $items = $this->resolveOwnedBulkItems((array)$ids, 'deleted', 'deleted');
            $db->beginTransaction();
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $bonusTouchUserIds = array_merge($bonusTouchUserIds, File::restore((int)$item['row']['id']));
                    continue;
                }

                $bonusTouchUserIds = array_merge($bonusTouchUserIds, \App\Model\Folder::restoreTree((int)$item['row']['id']));
            }
            $db->commit();
            $bonusTouchUserIds = array_values(array_unique(array_filter(array_map('intval', $bonusTouchUserIds), static fn (int $userId): bool => $userId > 0)));
            if ($bonusTouchUserIds !== []) {
                \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                    'workflow' => 'bulk_restore',
                    'item_count' => count($items),
                ]);
            }
            $this->respondJson(['status' => 'success']);
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondJson(['status' => 'error', 'error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::error('Bulk restore failed', ['error' => $e->getMessage()]);
            $this->respondJson(['status' => 'error', 'error' => 'Server error. Please try again.'], 500);
        }
    }

    public function bulkMove()
    {
        $this->checkAuth();
        $ids = $_POST['ids'] ?? [];
        $targetFolderId = ($_POST['target_folder_id'] ?? 'root');
        $targetFolderId = ($targetFolderId === 'root') ? null : $targetFolderId;

        if (empty($ids)) die(json_encode(['status' => 'error', 'error' => 'No items selected']));
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF mismatch']));

        // Resolve target folder if it's a slug
        if ($targetFolderId !== null) {
            $target = \App\Model\Folder::find($targetFolderId);
            if (!$target || ($target['status'] ?? 'active') !== 'active' || !$this->currentUserOwnsFolder($target)) {
                die(json_encode(['status' => 'error', 'error' => 'Invalid destination']));
            }
            $targetFolderId = (int)$target['id'];
        }

        $db = \App\Core\Database::getInstance()->getConnection();
        $bonusTouchUserIds = [];
        try {
            $items = $this->resolveOwnedBulkItems((array)$ids, 'any', 'any');
            foreach ($items as $item) {
                if ($item['type'] === 'folder' && $targetFolderId !== null && \App\Model\Folder::isSubfolderOf($targetFolderId, (int)$item['row']['id'])) {
                    throw new \RuntimeException('A folder cannot be moved into itself or one of its descendants.');
                }
            }

            $db->beginTransaction();
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $file = $item['row'];
                    if (($file['status'] ?? 'active') === 'deleted') {
                        $bonusTouchUserIds = array_merge($bonusTouchUserIds, File::restore((int)$file['id']));
                    }
                    File::update((int)$file['id'], ['folder_id' => $targetFolderId]);
                    continue;
                }

                $folder = $item['row'];
                if (($folder['status'] ?? 'active') === 'deleted') {
                    $bonusTouchUserIds = array_merge($bonusTouchUserIds, \App\Model\Folder::restoreTree((int)$folder['id']));
                }

                \App\Model\Folder::update((int)$folder['id'], ['parent_id' => $targetFolderId]);
            }
            $db->commit();
            $bonusTouchUserIds = array_values(array_unique(array_filter(array_map('intval', $bonusTouchUserIds), static fn (int $userId): bool => $userId > 0)));
            if ($bonusTouchUserIds !== []) {
                \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                    'workflow' => 'bulk_move_restore',
                    'item_count' => count($items),
                ]);
            }
            $this->respondJson(['status' => 'success']);
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondJson(['status' => 'error', 'error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::error('Bulk move failed', ['error' => $e->getMessage()]);
            $this->respondJson(['status' => 'error', 'error' => 'Server error. Please try again.'], 500);
        }
    }


    public function rename()
    {
        $this->checkAuth();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF Mismatch']));

        $id = $_POST['id'] ?? 0;
        $name = $this->normalizeFilename($_POST['name'] ?? '');
        if (empty($name)) die(json_encode(['status' => 'error', 'error' => 'Name cannot be empty']));

        $file = File::find($id);
        if (!$this->currentUserOwnsFile($file)) {
            http_response_code(403);
            die(json_encode(['status' => 'error', 'error' => 'Unauthorized']));
        }

        File::update($file['id'], ['filename' => $name]);
        $oldName = $file['filename']; // File::find() already decrypts this
        Auth::logActivity('file_rename', "Renamed file from $oldName to $name");

        echo json_encode(['status' => 'success']);
    }

    public function bulkCopy()
    {
        $this->checkAuth();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF Mismatch']));

        $ids = $_POST['ids'] ?? [];
        $targetFolderId = $_POST['target_folder_id'] ?? null;

        if ($targetFolderId === 'root') $targetFolderId = null;

        if ($targetFolderId !== null) {
            $target = \App\Model\Folder::find($targetFolderId);
            if (!$target || ($target['status'] ?? 'active') !== 'active' || !$this->currentUserOwnsFolder($target)) {
                die(json_encode(['status' => 'error', 'error' => 'Invalid destination']));
            }
            $targetFolderId = (int)$target['id'];
        }

        try {
            $items = $this->resolveOwnedBulkItems((array)$ids, 'active', 'active');
            $db = \App\Core\Database::getInstance()->getConnection();
            $db->beginTransaction();
            try {
                foreach ($items as $item) {
                    if ($item['type'] === 'file') {
                        if (!File::copy((int)$item['row']['id'], $targetFolderId)) {
                            throw new \RuntimeException('Could not copy every selected item.');
                        }
                        continue;
                    }

                    if (\App\Model\Folder::copyTree((int)$item['row']['id'], (int)$item['row']['user_id'], $targetFolderId) === null) {
                        throw new \RuntimeException('Could not copy every selected item.');
                    }
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            $this->respondJson(['status' => 'success']);
        } catch (\RuntimeException $e) {
            $this->respondJson(['status' => 'error', 'error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            $this->respondJson(['status' => 'error', 'error' => 'Could not copy every selected item.'], 500);
        }
    }

    public function bulkRename()
    {
        $this->checkAuth();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die(json_encode(['status' => 'error', 'error' => 'CSRF Mismatch']));
        }

        $items = $_POST['ids'] ?? [];
        $action = ($_POST['action'] ?? 'preview') === 'apply' ? 'apply' : 'preview';
        if (empty($items)) {
            die(json_encode(['status' => 'error', 'error' => 'No items selected']));
        }

        try {
            $resolvedItems = $this->resolveOwnedBulkItems((array)$items, 'active', 'active');
        } catch (\RuntimeException $e) {
            $this->respondJson(['status' => 'error', 'error' => $e->getMessage()], 409);
            return;
        }

        $preview = [];
        $updated = 0;
        $index = 0;
        foreach ($resolvedItems as $item) {
            $type = $item['type'];
            $id = (int)$item['row']['id'];
            $row = $item['row'];
            $oldName = (string)($type === 'file' ? $row['filename'] : $row['name']);
            $newName = $this->buildMassRenameName($oldName, $index);
            $index++;
            if ($newName === '' || $newName === $oldName) {
                continue;
            }

            $preview[] = [
                'id' => $id,
                'type' => $type,
                'old_name' => $oldName,
                'new_name' => $newName,
            ];

            if ($action === 'apply') {
                if ($type === 'file') {
                    File::update($id, ['filename' => $newName]);
                } else {
                    \App\Model\Folder::update($id, ['name' => $newName]);
                }
                $updated++;
            }
        }

        $this->respondJson(['status' => 'success', 'preview' => $preview, 'updated' => $updated]);
    }

    private function buildMassRenameName(string $name, int $index): string
    {
        $newName = $name;
        $find = (string)($_POST['find'] ?? '');
        $replace = (string)($_POST['replace'] ?? '');
        $remove = (string)($_POST['remove'] ?? '');
        $regex = Auth::isAdmin() && ($_POST['regex'] ?? '0') === '1';

        if ($find !== '') {
            if ($regex) {
                $find = substr($find, 0, 120);
                $pattern = '/' . str_replace('/', '\/', $find) . '/u';
                $candidate = @preg_replace($pattern, $replace, $newName);
                if (is_string($candidate)) {
                    $newName = $candidate;
                }
            } else {
                $newName = str_replace($find, $replace, $newName);
            }
        }

        if ($remove !== '') {
            $newName = str_replace($remove, '', $newName);
        }

        $separator = (string)($_POST['separator'] ?? '');
        if ($separator === 'spaces') {
            $newName = preg_replace('/[._]+/', ' ', $newName) ?? $newName;
        } elseif ($separator === 'dots') {
            $newName = preg_replace('/[\s_]+/', '.', $newName) ?? $newName;
        } elseif ($separator === 'underscores') {
            $newName = preg_replace('/[\s.]+/', '_', $newName) ?? $newName;
        }

        $prefix = (string)($_POST['prefix'] ?? '');
        $suffix = (string)($_POST['suffix'] ?? '');
        $sequence = (string)($_POST['sequence'] ?? '');
        $start = max(0, (int)($_POST['start'] ?? 1));
        $number = str_pad((string)($start + $index), 3, '0', STR_PAD_LEFT);

        if ($sequence === 'prefix') {
            $prefix .= $number . ' ';
        } elseif ($sequence === 'suffix') {
            $suffix = ' ' . $number . $suffix;
        }

        $newName = $this->normalizeFilename($prefix . trim($newName) . $suffix);
        return substr($newName, 0, self::MAX_FILENAME_LENGTH);
    }

    public function bulkSetVisibility()
    {
        $this->checkAuth();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die(json_encode(['status' => 'error', 'error' => 'CSRF Mismatch']));
        }

        $ids        = $_POST['ids'] ?? [];
        $visibility = $_POST['visibility'] ?? '';

        if (!in_array($visibility, ['public', 'private'], true)) {
            http_response_code(400);
            die(json_encode(['status' => 'error', 'error' => 'Invalid visibility value']));
        }

        $isPublic = $visibility === 'public' ? 1 : 0;
        $userId   = Auth::id();
        $updated  = 0;

        $db = \App\Core\Database::getInstance()->getConnection();

        try {
            $items = $this->resolveOwnedBulkItems((array)$ids, 'active', 'active');
            $db->beginTransaction();
            foreach ($items as $item) {
                if ($item['type'] !== 'file') {
                    throw new \RuntimeException('Visibility changes only apply to files.');
                }

                $stmt = $db->prepare('UPDATE files SET is_public = ? WHERE id = ?');
                if ($stmt->execute([$isPublic, (int)$item['row']['id']])) {
                    $updated++;
                }
            }
            $db->commit();
            $this->respondJson(['status' => 'success', 'updated' => $updated]);
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondJson(['status' => 'error', 'error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::error('Bulk visibility failed', ['error' => $e->getMessage()]);
            $this->respondJson(['status' => 'error', 'error' => 'Server error. Please try again.'], 500);
        }
    }

    public function cancelRemoteUpload()
    {
        if (!\App\Core\Auth::check()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'error' => 'Login required']);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
            return;
        }

        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!\App\Core\Csrf::verify($csrfToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'error' => 'CSRF Token Invalid']);
            return;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['status' => 'error', 'error' => 'Invalid job ID']);
            return;
        }

        $db = \App\Core\Database::getInstance()->getConnection();
        $this->ensureRemoteUploadQueueSchema($db);

        $stmt = $db->prepare("SELECT user_id, status FROM remote_upload_queue WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            echo json_encode(['status' => 'error', 'error' => 'Job not found']);
            return;
        }

        if ((int)($job['user_id'] ?? 0) !== (int)(\App\Core\Auth::id() ?? 0)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
            return;
        }

        if (in_array($job['status'], ['completed', 'failed', 'canceled'])) {
            echo json_encode(['status' => 'error', 'error' => 'Job already finished or canceled']);
            return;
        }

        $stmt = $db->prepare("UPDATE remote_upload_queue SET status = 'canceled', error_message = 'Canceled by user.', processed_at = NOW() WHERE id = ? AND status IN ('pending', 'processing')");
        $stmt->execute([$jobId]);

        \App\Core\Logger::info("Remote Upload Canceled", ['job_id' => $jobId, 'user' => \App\Core\Auth::id()]);
        echo json_encode(['status' => 'success', 'message' => 'Remote upload canceled.']);
    }

    private function ensureRemoteUploadQueueSchema(\PDO $db): void
    {
        \App\Service\Database\SchemaService::ensureTables(['remote_upload_queue'], false);
    }

    private function checkAuth()
    {
        if (!Auth::check()) {
            http_response_code(401);
            die(json_encode(['status' => 'error', 'error' => 'Login required']));
        }
    }
}
