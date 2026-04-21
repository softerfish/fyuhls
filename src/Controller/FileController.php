<?php

namespace App\Controller;

use App\Service\FileProcessor;
use App\Service\DownloadManager;
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
        if ($isImageFile && !empty($file['file_hash']) && !empty($file['storage_path'])) {
            $pathParts = explode('/', trim((string)$file['storage_path'], '/'));
            if (count($pathParts) >= 3) {
                $thumbnailUrl = $baseUrl . '/thumbnail/' . rawurlencode($pathParts[0]) . '/' . rawurlencode($pathParts[1]) . '/' . rawurlencode((string)$file['file_hash']) . '.jpg';
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
        http_response_code($statusCode);

        $siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
        $title = $titleText;
        $metaDescription = $message;

        require_once dirname(__DIR__, 1) . '/View/home/header.php';

        $showAds = (bool)($package['show_ads'] ?? false);
        $adLeft = $showAds ? Setting::get('ad_download_left', '') : '';
        $adRight = $showAds ? Setting::get('ad_download_right', '') : '';
        $adTop = $showAds ? Setting::get('ad_download_top', '') : '';
        $adBottom = $showAds ? Setting::get('ad_download_bottom', '') : '';

        echo '<style>
            .download-page-shell{display:flex;justify-content:center;align-items:center;flex:1;padding:2rem;gap:2rem;max-width:1400px;margin:0 auto;width:100%}
            .download-page-sidebar{flex:0 0 300px;max-width:300px;display:none;align-self:center}
            .download-page-sidebar-card{background:#f1f5f9;padding:1rem;border-radius:8px;text-align:center;overflow-wrap:anywhere;word-break:break-all}
            .download-page-center{flex:1 1 auto;max-width:560px;min-width:0;width:100%}
            .download-page-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;box-sizing:border-box}
            .download-page-top-ad,.download-page-bottom-ad{background:#f1f5f9;padding:.75rem;text-align:center;border-radius:8px;overflow-wrap:anywhere;word-break:break-all}
            .download-page-top-ad{margin-bottom:1.5rem}
            .download-page-bottom-ad{margin-top:1.5rem}
            .download-page-title{font-size:1.25rem;font-weight:700;margin:0 0 .25rem;overflow-wrap:anywhere;word-break:break-all}
            .download-page-meta{color:#64748b;font-size:.875rem;margin:0 0 2rem}
            .download-state-copy{color:#64748b;font-size:.95rem;margin:0;line-height:1.7;text-align:center}
            .download-share-panel{margin-top:1.5rem;padding:1rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}
            .download-share-heading{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 .75rem}
            .download-share-row{margin-bottom:.875rem}
            .download-share-row:last-child{margin-bottom:0}
            .download-share-label{display:block;font-size:.85rem;font-weight:600;color:#334155;margin-bottom:.4rem}
            .download-share-control{display:flex;gap:.5rem;align-items:stretch}
            .download-share-input{flex:1;min-width:0;padding:.75rem .875rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;font-size:.88rem}
            .download-share-copy{padding:.75rem 1rem;border:0;border-radius:8px;background:#e2e8f0;color:#0f172a;font-weight:600;cursor:pointer;white-space:nowrap}
            .download-share-copy:hover{background:#cbd5e1}
            .download-share-toggle{margin-top:.25rem;padding:0;background:none;border:0;color:#2563eb;font-size:.875rem;font-weight:600;cursor:pointer}
            .download-share-toggle:hover{text-decoration:underline}
            .download-share-extra{margin-top:.875rem;padding-top:.875rem;border-top:1px solid #e2e8f0}
            @media (min-width: 1100px){.download-page-sidebar{display:block}}
            @media (max-width: 640px){.download-share-control{flex-direction:column}.download-share-copy{width:100%}}
        </style>';

        echo '<div class="download-page-shell">';

        if ($adLeft !== '') {
            echo '<div class="download-page-sidebar">';
            echo '<div class="download-page-sidebar-card">' . $adLeft . '</div>';
            echo '</div>';
        }

        echo '<div class="download-page-center">';
        if ($adTop !== '') {
            echo '<div class="download-page-top-ad">' . $adTop . '</div>';
        }
        echo '<div class="download-page-card">';
        if ($file !== null) {
            echo '<h1 class="download-page-title">' . htmlspecialchars((string)($file['filename'] ?? $heading)) . '</h1>';
            echo '<p class="download-page-meta">' . round(((int)($file['file_size'] ?? 0)) / 1024 / 1024, 2) . ' MB</p>';
        } else {
            echo '<h1 class="download-page-title">' . htmlspecialchars($heading) . '</h1>';
        }
        echo '<p class="download-state-copy">' . htmlspecialchars($message) . '</p>';
        $this->renderDownloadSharePanel($shareFields);
        echo '</div>';
        if ($adBottom !== '') {
            echo '<div class="download-page-bottom-ad">' . $adBottom . '</div>';
        }
        echo '</div>';

        if ($adRight !== '') {
            echo '<div class="download-page-sidebar">';
            echo '<div class="download-page-sidebar-card">' . $adRight . '</div>';
            echo '</div>';
        }

        echo '</div>';

        if (!empty($shareFields)) {
            echo '<script>
document.querySelectorAll("[data-copy-target]").forEach(function(button) {
    button.addEventListener("click", async function() {
        const target = document.getElementById(button.getAttribute("data-copy-target"));
        if (!target) {
            return;
        }
        try {
            await navigator.clipboard.writeText(target.value);
            const original = button.textContent;
            button.textContent = "Copied";
            setTimeout(function() {
                button.textContent = original;
            }, 1400);
        } catch (err) {
            target.focus();
            target.select();
        }
    });
});
document.querySelectorAll("[data-share-toggle]").forEach(function(button) {
    button.addEventListener("click", function() {
        const target = document.getElementById(button.getAttribute("data-share-toggle"));
        if (!target) {
            return;
        }
        const expanded = button.getAttribute("aria-expanded") === "true";
        target.hidden = expanded;
        button.setAttribute("aria-expanded", expanded ? "false" : "true");
        button.textContent = expanded ? "More share options" : "Fewer share options";
    });
});
</script>';
        }

        require_once dirname(__DIR__, 1) . '/View/home/footer.php';
    }

    private function verifyTurnstile(string $token): bool
    {
        $secret = Setting::getEncrypted('captcha_secret_key', Config::get('turnstile.secret_key'));
        if (!$secret) {
            return true;
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
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function issueReferralCookie(int $referrerId): void
    {
        if ($referrerId <= 0) {
            return;
        }

        $secret = (string)\App\Core\Config::get('app_key', '');
        if ($secret === '') {
            return;
        }

        $payload = (string)$referrerId;
        $signature = hash_hmac('sha256', $payload, $secret);
        setcookie('ref', $payload . '.' . $signature, [
            'expires' => time() + (86400 * 30),
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function cleanupStaleActiveDownloadsForUser(int $userId): void
    {
        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            DELETE FROM active_downloads
            WHERE user_id = ?
              AND (
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
        ");
        $stmt->execute([$userId]);
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

        $db = \App\Core\Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS download_bandwidth_usage (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usage_date DATE NOT NULL,
                actor_key VARCHAR(96) NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                event_key VARCHAR(80) NOT NULL,
                bytes_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY download_bandwidth_event_unique (event_key),
                KEY download_bandwidth_actor_date_idx (actor_key, usage_date),
                KEY download_bandwidth_user_date_idx (user_id, usage_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::$downloadBandwidthTableReady = true;
    }

    private function buildDownloadBandwidthActorKey(?int $userId, string $ip): string
    {
        if ($userId !== null && $userId > 0) {
            return 'user:' . $userId;
        }

        return 'ip:' . hash('sha256', $ip);
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

    private function packageHasTrackedConcurrentLimit(array $package): bool
    {
        if (!Auth::check()) {
            return false;
        }

        if (Setting::get('track_current_downloads', '0') !== '1') {
            return false;
        }

        return max(0, (int)($package['concurrent_downloads'] ?? 0)) > 0;
    }

    private function enforceConcurrentDownloadLimit(array $package, array $file): void
    {
        if (!Auth::check()) {
            return;
        }

        if (Setting::get('track_current_downloads', '0') !== '1') {
            return;
        }

        $limit = max(0, (int)($package['concurrent_downloads'] ?? 0));
        if ($limit === 0) {
            return;
        }

        $this->cleanupStaleActiveDownloadsForUser((int)Auth::id());

        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM active_downloads WHERE user_id = ?");
        $stmt->execute([(int)Auth::id()]);
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
    }

    private function canAccessFile(array $file): bool
    {
        if (!empty($file['is_public'])) {
            return true;
        }

        return Auth::check() && ($file['user_id'] === Auth::id() || Auth::isAdmin());
    }

    private function enforceFileAccess(array $file): void
    {
        if ($this->canAccessFile($file)) {
            return;
        }

        http_response_code(404);
        die('File not found');
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

    private function tryRepairBrokenStoredFileLink(array $file): ?array
    {
        $currentStoredFileId = (int)($file['stored_file_id'] ?? 0);
        $fileHash = trim((string)($file['file_hash'] ?? ''));
        $fileSize = (int)($file['file_size'] ?? 0);

        if ($currentStoredFileId <= 0 || $fileHash === '' || $fileSize <= 0) {
            return null;
        }

        $db = \App\Core\Database::getInstance()->getConnection();
        $alternatives = \App\Model\StoredFile::findAlternativesByHashAndSize($fileHash, $fileSize, $currentStoredFileId);

        foreach ($alternatives as $candidate) {
            try {
                $candidateStorage = \App\Core\StorageManager::getProviderById($candidate['file_server_id'] ? (int)$candidate['file_server_id'] : null, $db);
                if (!$this->isStoredObjectHealthy($candidateStorage, $candidate)) {
                    continue;
                }

                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE files SET stored_file_id = ? WHERE id = ?")->execute([(int)$candidate['id'], (int)$file['id']]);
                    \App\Model\StoredFile::incrementRefCount((int)$candidate['id']);
                    \App\Model\StoredFile::decrementRefCount($currentStoredFileId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }

                Logger::warning('Repaired broken stored file link during download', [
                    'file_id' => (int)$file['id'],
                    'from_stored_file_id' => $currentStoredFileId,
                    'to_stored_file_id' => (int)$candidate['id'],
                    'file_hash' => $fileHash,
                ]);

                return \App\Model\File::findAnyStatus((int)$file['id']);
            } catch (\Throwable $e) {
                Logger::error('Stored file link repair failed', [
                    'file_id' => (int)$file['id'],
                    'candidate_stored_file_id' => (int)($candidate['id'] ?? 0),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
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

        $fileId = $_POST['file_id'] ?? $_POST['id'] ?? 0;
        $file = File::find($fileId);

        if (!$file) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'File not found']);
            return;
        }

        // idor check
        if ($file['user_id'] !== Auth::id() && !Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            return;
        }

        $adminAction = Auth::isAdmin();
        $deleteReason = trim((string)($_POST['delete_reason'] ?? ''));
        if ($adminAction && $deleteReason === '') {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'A delete reason is required for admin removals.']);
            return;
        }

        File::hardDelete((int)$file['id'], [
            'deleted_by_user_id' => Auth::id(),
            'deleted_by_role' => $adminAction ? 'admin' : 'user',
            'deleted_by_label' => $this->currentActorLabel($adminAction),
            'delete_reason' => $deleteReason,
        ]);
        $activityMessage = "Deleted file: " . $file['filename'] . " (ID: " . $file['id'] . ")";
        Auth::logActivity('delete', $activityMessage);
        Logger::info('file deleted', ['file_id' => $file['id'], 'user_id' => Auth::id(), 'admin_action' => $adminAction]);
        echo json_encode(['status' => 'success', 'message' => 'File deleted', 'redirect_url' => '/']);
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

        $fileId = (int)($_POST['file_id'] ?? 0);
        $file = File::find($fileId);
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

        if (File::userHasStoredFile($userId, (int)$file['stored_file_id'])) {
            echo json_encode(['status' => 'success', 'message' => 'This file is already in your account.', 'already_saved' => true]);
            return;
        }

        $newFileId = File::createSavedCopyForUser((int)$file['id'], $userId, null, (int)($package['max_storage_bytes'] ?? 0));
        if (!$newFileId) {
            http_response_code(409);
            $quotaExceeded = (int)($package['max_storage_bytes'] ?? 0) > 0
                && ((int)($file['file_size'] ?? 0) > 0)
                && !File::userHasStoredFile($userId, (int)$file['stored_file_id']);
            echo json_encode([
                'status' => 'error',
                'message' => $quotaExceeded
                    ? 'Saving this file would exceed your storage limit.'
                    : 'This file is already in your account.'
            ]);
            return;
        }

        Auth::logActivity('save_file', 'Saved file to account: ' . $file['filename'] . ' (Source ID: ' . $file['id'] . ', New ID: ' . $newFileId . ')');
        echo json_encode(['status' => 'success', 'message' => 'File added to your account.', 'file_id' => $newFileId]);
    }

    public function remoteUpload()
    {
        if (!Auth::check()) die(json_encode(['error' => 'Login required']));
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die(json_encode(['error' => 'Method not allowed']));
        }

        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!Csrf::verify($csrfToken)) {
            http_response_code(403);
            die(json_encode(['error' => 'CSRF Token Invalid']));
        }
        
        $package = \App\Model\Package::getUserPackage(Auth::id() ?? 0);
        if (!$package['allow_remote_upload']) die(json_encode(['error' => 'Remote upload not allowed for your package.']));

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

        $maxRemoteBytes = $this->resolveRemoteUploadByteLimit((int)(Auth::id() ?? 0), $package);
        if ($maxRemoteBytes <= 0) {
            die(json_encode(['error' => 'Remote upload is not available because your remaining limits are exhausted.']));
        }

        // 3. Check if we should process in background
        $bg = \App\Model\Setting::get('remote_url_background', '0') === '1';
        $folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        if ($folderId) {
            $folder = \App\Model\Folder::find($folderId);
            if ($folder && ($folder['user_id'] == (Auth::id() ?? 0) || \App\Core\Auth::isAdmin())) {
                $folderId = (int)$folder['id'];
            } else {
                $folderId = null;
            }
        }

        if ($bg) {
            try {
                $db = \App\Core\Database::getInstance()->getConnection();
                $stmt = $db->prepare("INSERT INTO remote_upload_queue (user_id, folder_id, url) VALUES (?, ?, ?)");
                $stmt->execute([Auth::id(), $folderId, $url]);
                die(json_encode(['success' => true, 'message' => 'Upload queued in background. It will appear in your files shortly.']));
            } catch (\Exception $e) {
                die(json_encode(['error' => 'Could not queue download.']));
            }
        }

        // 4. Synchronous Download to temp file
        $tempPath = sys_get_temp_dir() . '/' . uniqid('remote_');
        
        // Use cURL for better security (prevents file:// or other wrapper escapes)
        $ch = curl_init($url);
        $fp = fopen($tempPath, 'wb');
        if ($ch === false || $fp === false) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            @unlink($tempPath);
            die(json_encode(['error' => 'Could not prepare the remote upload on this server.']));
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
        fclose($fp);

        $tempFileSize = file_exists($tempPath) ? (int)filesize($tempPath) : 0;

        if (!$success) {
            if ($downloadedBytes > $maxRemoteBytes || $curlErrNo === 23 || $curlErrNo === 63) {
                @unlink($tempPath);
                die(json_encode(['error' => 'Remote file exceeds your allowed upload size or remaining storage quota.']));
            }
            if (!$contentLengthChecked && $tempFileSize > $maxRemoteBytes) {
                @unlink($tempPath);
                die(json_encode(['error' => 'Remote file exceeds your allowed upload size or remaining storage quota.']));
            }
            @unlink($tempPath);
            die(json_encode(['error' => 'Could not fetch file from URL. ' . ($curlErr ? 'Transfer error: ' . $curlErr : '')]));
        }

        if ($tempFileSize > $maxRemoteBytes) {
            @unlink($tempPath);
            die(json_encode(['error' => 'Remote file exceeds your allowed upload size or remaining storage quota.']));
        }
        
        $originalName = basename(parse_url($url, PHP_URL_PATH)) ?: 'downloaded_file';

        try {
            $processor = new \App\Service\FileProcessor();
            $result = $processor->processUpload($tempPath, $originalName, Auth::id() ?? 0, $folderId);
            echo json_encode($result);
        } catch (\Exception $e) {
            Logger::error('Remote upload processing failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            echo json_encode(['error' => 'The remote upload could not be completed.']);
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
        if (!$host || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $approved = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$ip || !$this->isAllowedRemoteIp($ip)) {
                continue;
            }
            $approved[] = $ip;
        }

        return array_values(array_unique($approved));
    }

    private function isAllowedRemoteIp(string $ip): bool
    {
        $blockedRanges = [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '0.0.0.0/8',
            '100.64.0.0/10',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
            '::1/128',
            'fc00::/7',
            'fe80::/10',
            '2001:db8::/32',
        ];

        foreach ($blockedRanges as $range) {
            if (\App\Service\SecurityService::ipInCidr($ip, $range)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
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
            $remaining = max(0, $maxStorage - $storageUsed);
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

        foreach ($filesToEmpty as $file) {
            try {
                \App\Model\File::hardDelete((int)$file['id'], [
                    'deleted_by_user_id' => $userId,
                    'deleted_by_role' => Auth::isAdmin() ? 'admin' : 'user',
                    'deleted_by_label' => $this->currentActorLabel(Auth::isAdmin()),
                    'delete_reason' => 'Removed from trash.',
                ]);
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
        if ($captchaEnabled && $captchaSiteKey && !$this->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
            http_response_code(403);
            die("Captcha verification failed.");
        }

        $fileId = (int)$_POST['file_id'];
        $reason = $_POST['reason'];
        $details = $_POST['details'] ?? '';
        $file = File::find($fileId);
        if (!$file) {
            http_response_code(404);
            die("File not found.");
        }

        $encIp = \App\Service\EncryptionService::encrypt(\App\Service\SecurityService::getClientIp());
        $encDetails = \App\Service\EncryptionService::encrypt($details);

        $db = \App\Core\Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO abuse_reports (file_id, reporter_ip, reason, details) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$fileId, $encIp, $reason, $encDetails])) {
            $filename = $file ? $file['filename'] : 'Unknown File';

            // Send Alert to Admin
            $adminEmail = Setting::get('admin_notification_email', '');
            if ($adminEmail) {
                $safeReason = $this->sanitizeAbuseEmailText($reason, 200);
                $safeDetails = $this->sanitizeAbuseEmailText($details, 2000);
                \App\Service\MailService::sendTemplate($adminEmail, 'admin_notification', [
                    '{event_type}' => 'New Abuse Report',
                    '{details}' => "File: $filename (ID: $fileId)\nReason: $safeReason\nDetails: $safeDetails"
                ]);
            }

            // Send Confirmation to Reporter if logged in and has email
            if (Auth::check()) {
                $user = \App\Model\User::find(Auth::id());
                if ($user && !empty($user['email'])) {
                    $email = \App\Service\EncryptionService::decrypt($user['email']);
                    \App\Service\MailService::sendTemplate($email, 'abuse_report_confirmation', [
                        '{username}' => Auth::username(),
                        '{file_name}' => $filename
                    ]);
                }
            }

            echo "Success: Your report has been submitted.";
        } else {
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

        $file = File::find($id);

        if (!$file) {
            $this->renderDownloadStatePage(
                'File Not Found - ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                'File Not Found',
                'This file is no longer available or the link is invalid.',
                404
            );
            return;
        }

        $this->enforceFileAccess($file);

        // Referral Tracking (PPS)
        if (\App\Service\FeatureService::affiliateEnabled() && $file['user_id'] && Setting::get('pps_global_status', '1') === '1' && !Auth::check() && empty($_COOKIE['ref'])) {
            // Set referral cookie for 30 days
            $this->issueReferralCookie((int)$file['user_id']);
        }

        // Determine User Package
        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();

        $security = new SecurityService();

        // check require_account_to_download
        if (!Auth::check() && Setting::get('require_account_to_download', '0') === '1') {
            http_response_code(403);
            die('Access Denied: You must register an account and log in to download files. <a href="/register">Register</a> | <a href="/login">Log in</a>');
        }

        // check blocked countries
        if ($security->isCountryBlocked(\App\Service\SecurityService::getClientIp())) {
            http_response_code(403);
            die("Access Denied: Downloads are not available in your region.");
        }

        // check vpn/proxy block
        if (($package['block_vpn'] ?? 0) && $security->isVpnOrProxy(\App\Service\SecurityService::getClientIp())) {
            http_response_code(403);
            die("Access Denied: VPNs and Proxies are not allowed for this user level.");
        }

        // Only honor direct links after access policy checks complete.
        if ($package['allow_direct_links'] && isset($_GET['direct'])) {
            File::incrementDownloads((int)$file['id']);
            $this->serveFile($file, null, false);
            exit;
        }

        // figure out if we show captcha for this user tier
        $isGuest = !Auth::check();
        $isFree  = Auth::check() && ($package['name'] ?? 'Guest') !== 'Guest' && !($package['allow_direct_links'] ?? false);
        $captchaSiteKey    = Setting::get('captcha_site_key', '');
        $captchaDownload   = false;
        if ($captchaSiteKey) {
            if ($isGuest  && Setting::get('captcha_download_guest', '0') === '1') $captchaDownload = true;
            if (!$isGuest && Setting::get('captcha_download_free', '0') === '1')  $captchaDownload = true;
        }

        // countdown settings from package
        $waitEnabled = ($package['wait_time_enabled'] ?? 0) == 1;
        $waitTime    = $waitEnabled ? max(0, (int)($package['wait_time'] ?? 0)) : 0;

        // Set title and other data for header.php
        $title = 'Download: ' . $file['filename'];
        $metaDescription = 'Download ' . $file['filename'] . ' from ' . \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')) . '.';
        $filename = $file['filename'];
        $isPublic = !empty($file['is_public']);
        $fraud = new \App\Service\RewardFraudService();
        $fraud->ensureVisitorCookie();
        $streamingEnabled = Setting::get('streaming_support_enabled', '0') === '1';
        $streamingEligible = $streamingEnabled && $this->isVideoFile($file);
        $streamSessionId = null;
        $streamUrl = null;
        $streamCsrf = Csrf::generate();
        $shareFields = $this->buildPublicShareFields($file);
        if ($streamingEligible && !$captchaDownload && $waitTime <= 0) {
            $streamSession = $fraud->createDownloadSession($file, Auth::id() ? (int)Auth::id() : null, [], 'stream');
            $streamSessionId = $streamSession['public_id'] ?? null;
            if ($streamSessionId !== null) {
                $streamUrl = (new DownloadManager())->generateSignedUrl((int)$file['id'], $file['filename'], $streamSessionId) . '&stream=1';
            }
        }
        
        $adLeft = $package['show_ads'] ? Setting::get('ad_download_left', '') : '';
        $adRight = $package['show_ads'] ? Setting::get('ad_download_right', '') : '';
        $adTop = $package['show_ads'] ? Setting::get('ad_download_top', '') : '';
        $adBottom = $package['show_ads'] ? Setting::get('ad_download_bottom', '') : '';
        $adOverlay = $package['show_ads'] ? Setting::get('ad_download_overlay', '') : '';
        $reportCaptchaEnabled = Setting::get('captcha_report_file', '0') === '1';
        $reportCaptchaSiteKey = Setting::get('captcha_site_key', Config::get('turnstile.site_key'));
        $abuseReportsEnabled = Setting::get('enable_abuse_reports', '1') === '1';
        $displayMimeType = $this->resolveDisplayMimeType($file);
        $downloadActionVisible = $this->canCurrentUserSaveDownloadedFile($file, $package);
        $downloadAlreadySaved = Auth::check()
            ? File::userHasStoredFile((int)(Auth::id() ?? 0), (int)$file['stored_file_id'])
            : false;
        $canDeleteFile = Auth::check() && ((int)($file['user_id'] ?? 0) === (int)(Auth::id() ?? 0) || Auth::isAdmin());
        $deleteRequiresReason = Auth::isAdmin();
        $showAds = (bool)($package['show_ads'] ?? false);

        require_once dirname(__DIR__, 1) . '/View/home/header.php';
        View::render('home/partials/download_show_page.php', compact(
            'file',
            'package',
            'showAds',
            'adLeft',
            'adRight',
            'adTop',
            'adBottom',
            'adOverlay',
            'streamUrl',
            'displayMimeType',
            'streamingEligible',
            'captchaDownload',
            'captchaSiteKey',
            'waitTime',
            'shareFields',
            'abuseReportsEnabled',
            'reportCaptchaEnabled',
            'reportCaptchaSiteKey',
            'streamSessionId',
            'streamCsrf',
            'downloadActionVisible',
            'downloadAlreadySaved',
            'canDeleteFile',
            'deleteRequiresReason'
        ));
        require_once dirname(__DIR__, 1) . '/View/home/footer.php';
    }

    public function generateLink()
    {
        // prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            die('Invalid Request');
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('Error: Security Token Expired. Please refresh.');
        }

        $fileId = $_POST['file_id'] ?? 0;
        $manager = new DownloadManager();
        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
        $security = new SecurityService();
        $fraud = new \App\Service\RewardFraudService();

        // check require_account_to_download (again, to stop direct POST manipulation)
        if (!Auth::check() && Setting::get('require_account_to_download', '0') === '1') {
            http_response_code(403);
            die("Access Denied: Account required.");
        }

        // check blocked countries (again)
        if ($security->isCountryBlocked(\App\Service\SecurityService::getClientIp())) {
            http_response_code(403);
            die("Access Denied: Region blocked.");
        }

        // check vpn/proxy (again, to prevent bypassing ui check)
        if (($package['block_vpn'] ?? 0) && $security->isVpnOrProxy(\App\Service\SecurityService::getClientIp())) {
            http_response_code(403);
            die("Access Denied: VPN detected.");
        }

        // check rate limit
        if (!$manager->checkRateLimit(\App\Service\SecurityService::getClientIp())) {
            http_response_code(429);
            die('Rate limit exceeded. Please try again in 10 minutes.');
        }

        // check referrer (anti-hotlink for the button click)
        if (!$manager->validateRequestSource()) {
            die('Invalid source. Please download from the official page.');
        }

        // verify turnstile captcha only if it was shown for this user tier
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $isGuest = !Auth::check();
        $needCaptcha = false;
        if ($captchaSiteKey) {
            if ($isGuest  && Setting::get('captcha_download_guest', '0') === '1') $needCaptcha = true;
            if (!$isGuest && Setting::get('captcha_download_free', '0')  === '1') $needCaptcha = true;
        }
        if ($needCaptcha && !$manager->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
            die('CAPTCHA verification failed. Please go back and try again.');
        }

        // generate signed url
        $file = File::find($fileId);
        if (!$file)
            die('File not found');

        $this->enforceFileAccess($file);
        $sessionId = null;
        if ($fraud->shouldRequireVerifiedCompletion($file)) {
            $session = $fraud->createDownloadSession($file, Auth::id() ? (int)Auth::id() : null, [
                'timezone_offset' => $_POST['timezone_offset'] ?? null,
                'platform_bucket' => $_POST['platform_bucket'] ?? '',
                'screen_bucket' => $_POST['screen_bucket'] ?? '',
            ]);
            $sessionId = $session['public_id'] ?? null;
        }

        $url = $manager->generateSignedUrl($fileId, $file['filename'], $sessionId);

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
        $file = File::find($id);
        if (!$file) {
            http_response_code(404);
            die("File not found");
        }

        $this->enforceFileAccess($file);

        // validate token (anti-leech)
        // Bypass signature check if the user is the owner or an admin
        $isOwner = Auth::check() && ($file['user_id'] === Auth::id() || Auth::isAdmin());
        if (!$isOwner) {
            $manager = new \App\Service\DownloadManager();
            if (!$manager->validateSignature($id, $token, $expires, $sessionId !== '' ? $sessionId : null)) {
                http_response_code(403);
                die("Error: Download link expired or invalid.");
            }
        }

        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
        $clientIp = \App\Service\SecurityService::getClientIp();
        $downloadEventKey = hash('sha256', implode('|', [
            (string)$id,
            (string)$token,
            (string)$expires,
            (string)$sessionId,
            $streamMode ? 'stream' : 'download',
        ]));
        $this->enforceDailyDownloadLimit($package ?? [], $file, Auth::id() ? (int)Auth::id() : null, $clientIp, $downloadEventKey);

        $fraud = new \App\Service\RewardFraudService();
        $rewardSessionId = null;
        $validatedSession = null;
        if ($sessionId !== '' && ($streamMode || $fraud->shouldRequireVerifiedCompletion($file))) {
            $validatedSession = $fraud->validateSessionForCurrentVisitor($sessionId, $file);
            if (!$validatedSession) {
                http_response_code(403);
                die("Error: Download session is invalid or expired.");
            }
            if ($fraud->shouldRequireVerifiedCompletion($file)) {
                $rewardSessionId = $sessionId;
            }
        }

        // use the database ID for subsequent calls
        $fileId = $file['id'];

        if (!$streamMode || $validatedSession === null || (string)($validatedSession['status'] ?? 'created') === 'created') {
            $this->markDownloadStarted($file);
        }

        // serving logic
        $this->serveFile($file, $rewardSessionId, true, $streamMode, $validatedSession);
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

        $file = File::find((string)($_POST['file_id'] ?? '0'));
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

    private function serveFile(array $file, ?string $rewardSessionId = null, bool $allowRewardTracking = true, bool $streamMode = false, ?array $validatedSession = null)
    {
        $db = \App\Core\Database::getInstance()->getConnection();
        $minPercent = (int)\App\Model\Setting::get('ppd_min_download_percent', '0');
        $payoutPolicy = new StandardFilePayoutPolicy();
        $fraud = new \App\Service\RewardFraudService();
        $requiresVerified = $allowRewardTracking && $rewardSessionId !== null && $fraud->shouldRequireVerifiedCompletion($file);
        $package = Auth::check() ? Package::getUserPackage(Auth::id() ?? 0) : Package::getGuestPackage();
        $requiresTrackedConcurrency = $this->packageHasTrackedConcurrentLimit($package);
        $speedLimit = (int)($package['download_speed'] ?? 0);

        // Try the fast provider-direct path before any storage HEAD/repair work.
        // For cloud-backed downloads this avoids an extra round trip before the browser
        // is redirected to the provider.
        if ($minPercent <= 0 && !$requiresVerified && !$streamMode && !$requiresTrackedConcurrency && $speedLimit <= 0) {
            $delivery = (new DownloadManager())->previewDelivery($file);
            if (!empty($delivery['url'])) {
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

        $storage = \App\Core\StorageManager::getProviderById($file['file_server_id'] ? (int)$file['file_server_id'] : null, $db);

        if (!$this->isStoredObjectHealthy($storage, $file)) {
            $repaired = $this->tryRepairBrokenStoredFileLink($file);
            if ($repaired) {
                $file = $repaired;
                $storage = \App\Core\StorageManager::getProviderById($file['file_server_id'] ? (int)$file['file_server_id'] : null, $db);
            }
        }

        if (!$this->isStoredObjectHealthy($storage, $file)) {
            Logger::error('Download blocked because stored object is missing or unhealthy', [
                'file_id' => (int)($file['id'] ?? 0),
                'stored_file_id' => (int)($file['stored_file_id'] ?? 0),
                'storage_path' => (string)($file['storage_path'] ?? ''),
                'file_server_id' => (int)($file['file_server_id'] ?? 0),
            ]);
            http_response_code(404);
            die('File is temporarily unavailable.');
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

        $activeSessionId = $rewardSessionId;
        if ($activeSessionId === null && $streamMode) {
            $activeSessionId = trim((string)($_GET['session'] ?? '')) ?: null;
        }
        if ($activeSessionId !== null) {
            $fraud->markSessionStarted($activeSessionId, $streamMode ? 'stream_php' : (string)$method);
        }

        $clientIp = \App\Service\SecurityService::getClientIp();
        $activeDownloadId = 0;
        $shouldTrackConnections = Setting::get('track_current_downloads', '0') === '1';
        $needsNginxPayoutState = $method === 'nginx'
            && !$streamMode
            && $rewardSessionId === null
            && !empty($file['user_id'])
            && \App\Service\FeatureService::rewardsEnabled();
        if ($shouldTrackConnections) {
            $this->enforceConcurrentDownloadLimit($package, $file);
        }
        $activeDownloadContext = [];
        if ($shouldTrackConnections || $needsNginxPayoutState) {
            if (is_array($validatedSession) && !empty($validatedSession)) {
                $activeDownloadContext = $fraud->exportRewardSignalContext($validatedSession);
                $activeDownloadContext['session_id'] = (int)($validatedSession['id'] ?? 0);
            } else {
                $activeDownloadContext = $fraud->buildClientSignals([], $clientIp);
            }
        }
        if ($shouldTrackConnections || $needsNginxPayoutState) {
            $activeDownloadId = $this->registerActiveDownload((int)$file['id'], Auth::id() ? (int)Auth::id() : null, $clientIp, $activeDownloadContext);
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
            $fileSize = (float)$file['file_size'];

            // 2. Stream with Progress Callback
            $storage->stream($file['storage_path'], $seekStart, function($bytesSent) use ($db, $downloadId, $file, $ip, $minPercent, $fileSize, &$credited, $rewardSessionId, $fraud, $streamMode, $payoutPolicy) {
                // Update active_downloads periodically
                static $lastUpdate = 0;
                if ($downloadId > 0 && time() - $lastUpdate >= 2) {
                    $upd = $db->prepare("UPDATE active_downloads SET bytes_sent = ? WHERE id = ?");
                    $upd->execute([$bytesSent, $downloadId]);
                    $lastUpdate = time();
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

    private function markDownloadStarted(array $file): void
    {
        $fileId = (int)$file['id'];
        File::incrementDownloads($fileId);

        if ($file['user_id']) {
            $ownerPackage = Package::getUserPackage((int)$file['user_id']);
        } else {
            $ownerPackage = Package::getGuestPackage();
        }

        $expiryDays = (int)($ownerPackage['file_expiry_days'] ?? 0);
        $db = \App\Core\Database::getInstance()->getConnection();
        if ($expiryDays > 0) {
            $newDeleteAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
            $db->prepare("UPDATE files SET delete_at = ? WHERE id = ?")->execute([$newDeleteAt, $fileId]);
        } else {
            $db->prepare("UPDATE files SET delete_at = NULL WHERE id = ?")->execute([$fileId]);
        }
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
                echo json_encode(['status' => 'error', 'error' => 'No items selected']);
                return;
            }
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                echo json_encode(['status' => 'error', 'error' => 'CSRF mismatch']);
                return;
            }

            $deletedCount = 0;
            foreach ($ids as $item) {
                $rawId = $item['id'];
                if ($item['type'] === 'file') {
                    $file = File::find($rawId);
                    if ($file && ($file['user_id'] === Auth::id() || Auth::isAdmin())) {
                        File::hardDelete((int)$file['id'], [
                            'deleted_by_user_id' => Auth::id(),
                            'deleted_by_role' => Auth::isAdmin() ? 'admin' : 'user',
                            'deleted_by_label' => $this->currentActorLabel(Auth::isAdmin()),
                            'delete_reason' => 'Deleted from bulk action.',
                        ]);
                        $deletedCount++;
                        Auth::logActivity('delete_file', "Bulk deleted file: " . $file['filename']);
                    }
                } else {
                    $folder = \App\Model\Folder::find($rawId);
                    if ($folder && ($folder['user_id'] === Auth::id() || Auth::isAdmin())) {
                        $folderId = (int)$folder['id'];
                        $subfolderIds = \App\Model\Folder::getRecursiveSubfolderIds($folderId);
                        $allFolderIds = array_merge([$folderId], $subfolderIds);
                        $db = \App\Core\Database::getInstance()->getConnection();
                        $inClause = implode(',', array_map('intval', $allFolderIds));
                        $stmt = $db->query("SELECT COUNT(*) FROM files WHERE folder_id IN ($inClause)");
                        $deletedCount += (int)$stmt->fetchColumn();

                        \App\Model\Folder::hardDeleteTree($folderId);
                        $deletedCount++;
                        Auth::logActivity('delete_folder', "Bulk deleted folder (and contents): " . $folder['name']);
                    }
                }
            }
            echo json_encode(['status' => 'success', 'message' => "Deleted $deletedCount items"]);
        } catch (\Throwable $e) {
            Logger::error('Bulk delete failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'error' => 'Server error. Please try again.']);
        }
    }

    public function bulkTrash()
    {
        $this->checkAuth();
        $ids = $_POST['ids'] ?? [];
        if (empty($ids)) die(json_encode(['status' => 'error', 'error' => 'No items selected']));
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF mismatch']));

        foreach ($ids as $item) {
            $id = (int)$item['id'];
            if ($item['type'] === 'file') {
                $file = File::find($id);
                if ($file && ($file['user_id'] === Auth::id() || Auth::isAdmin())) {
                    File::trash($id);
                }
            } else {
                $folder = \App\Model\Folder::find($id);
                if ($folder && ($folder['user_id'] === Auth::id() || Auth::isAdmin())) {
                    $subfolderIds = \App\Model\Folder::getRecursiveSubfolderIds($id);
                    $allFolderIds = array_merge([$id], $subfolderIds);
                    $db = \App\Core\Database::getInstance()->getConnection();
                    $inClause = implode(',', array_map('intval', $allFolderIds));
                    $db->exec("
                        UPDATE files
                        SET deleted_restore_status = CASE
                                WHEN status <> 'deleted' THEN status
                                ELSE deleted_restore_status
                            END,
                            status = 'deleted'
                        WHERE folder_id IN ($inClause)
                    ");
                    \App\Model\Folder::softDeleteTree($id);
                }
            }
        }
        echo json_encode(['status' => 'success']);
    }

    public function bulkRestore()
    {
        $this->checkAuth();
        $ids = $_POST['ids'] ?? [];
        if (empty($ids)) die(json_encode(['status' => 'error', 'error' => 'No items selected']));
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF mismatch']));

        foreach ($ids as $item) {
            $id = (int)$item['id'];
            if (($item['type'] ?? '') === 'file') {
                $file = File::findAnyStatus($id);
                if ($file && $file['status'] === 'deleted' && ($file['user_id'] === Auth::id() || Auth::isAdmin())) {
                    File::restore((int)$file['id']);
                }
                continue;
            }

            $folder = \App\Model\Folder::find($id);
            if ($folder && ($folder['status'] ?? 'active') === 'deleted' && ($folder['user_id'] === Auth::id() || Auth::isAdmin())) {
                $allFolderIds = \App\Model\Folder::getTreeIds((int)$folder['id']);
                $db = \App\Core\Database::getInstance()->getConnection();
                $inClause = implode(',', array_map('intval', $allFolderIds));
                $db->exec("
                    UPDATE files
                    SET status = COALESCE(NULLIF(deleted_restore_status, ''), 'active'),
                        deleted_restore_status = NULL
                    WHERE folder_id IN ($inClause) AND status = 'deleted'
                ");
                \App\Model\Folder::restoreTree((int)$folder['id']);
            }
        }

        echo json_encode(['status' => 'success']);
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
            if (!$target || ($target['status'] ?? 'active') !== 'active' || ($target['user_id'] !== Auth::id() && !Auth::isAdmin())) {
                die(json_encode(['status' => 'error', 'error' => 'Invalid destination']));
            }
            $targetFolderId = (int)$target['id'];
        }

        foreach ($ids as $item) {
            $id = $item['id'];
            if ($item['type'] === 'file') {
                $file = File::findAnyStatus($id);
                if ($file && ($file['user_id'] === Auth::id() || Auth::isAdmin())) {
                    $update = ['folder_id' => $targetFolderId];
                    if ($file['status'] === 'deleted') {
                        $update['status'] = (!empty($file['deleted_restore_status'])) ? $file['deleted_restore_status'] : 'active';
                        $update['deleted_restore_status'] = null;
                    }
                    File::update($file['id'], $update);
                }
            } else {
                $folder = \App\Model\Folder::find($id);
                if (!$folder || ($folder['user_id'] !== Auth::id() && !Auth::isAdmin())) continue;
                
                $folderId = $folder['id'];
                // Folder recursion check
                if ($targetFolderId !== null && \App\Model\Folder::isSubfolderOf($targetFolderId, $folderId)) {
                    continue;
                }

                if (($folder['status'] ?? 'active') === 'deleted') {
                    $allFolderIds = \App\Model\Folder::getTreeIds((int)$folderId);
                    $db = \App\Core\Database::getInstance()->getConnection();
                    $inClause = implode(',', array_map('intval', $allFolderIds));
                    $db->exec("
                        UPDATE files
                        SET status = COALESCE(NULLIF(deleted_restore_status, ''), 'active'),
                            deleted_restore_status = NULL
                        WHERE folder_id IN ($inClause) AND status = 'deleted'
                    ");
                    \App\Model\Folder::restoreTree((int)$folderId);
                }
                
                \App\Model\Folder::update($folderId, ['parent_id' => $targetFolderId]);
            }
        }
        echo json_encode(['status' => 'success']);
    }


    public function rename()
    {
        $this->checkAuth();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die(json_encode(['status' => 'error', 'error' => 'CSRF Mismatch']));

        $id = $_POST['id'] ?? 0;
        $name = $this->normalizeFilename($_POST['name'] ?? '');
        if (empty($name)) die(json_encode(['status' => 'error', 'error' => 'Name cannot be empty']));

        $file = File::find($id);
        if (!$file || ($file['user_id'] !== Auth::id() && !Auth::isAdmin())) {
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
            if (!$target || ($target['status'] ?? 'active') !== 'active' || ($target['user_id'] !== Auth::id() && !Auth::isAdmin())) {
                die(json_encode(['status' => 'error', 'error' => 'Invalid destination']));
            }
            $targetFolderId = (int)$target['id'];
        }

        foreach ($ids as $item) {
            $id = $item['id'];
            if ($item['type'] === 'file') {
                $file = File::find($id);
                if ($file && ($file['user_id'] === Auth::id() || Auth::isAdmin())) {
                    File::copy($file['id'], $targetFolderId);
                }
            } else {
                // Folder copy is more complex (recursive), maybe just skip for now or implement simply
                $folder = \App\Model\Folder::find($id);
                if ($folder && ($folder['user_id'] === Auth::id() || Auth::isAdmin())) {
                    // Simple folder "copy" just creates a new folder with same name in target
                    \App\Model\Folder::create(Auth::id(), $folder['name'] . ' (Copy)', $targetFolderId);
                }
            }
        }

        echo json_encode(['status' => 'success']);
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

        foreach ($ids as $item) {
            $id   = $item['id'] ?? null;
            $type = $item['type'] ?? '';

            // visibility only applies to files, not folders
            if ($type !== 'file' || !$id) {
                continue;
            }

            $file = File::find($id);
            if (!$file) {
                continue;
            }

            // IDOR check - must own the file or be admin
            if ($file['user_id'] !== $userId && !Auth::isAdmin()) {
                continue;
            }

            $stmt = $db->prepare('UPDATE files SET is_public = ? WHERE id = ?');
            if ($stmt->execute([$isPublic, (int)$file['id']])) {
                $updated++;
            }
        }

        echo json_encode(['status' => 'success', 'updated' => $updated]);
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
        
        $stmt = $db->prepare("SELECT user_id, status FROM remote_upload_queue WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            echo json_encode(['status' => 'error', 'error' => 'Job not found']);
            return;
        }

        if ($job['user_id'] != \App\Core\Auth::id() && !\App\Core\Auth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
            return;
        }

        if (in_array($job['status'], ['completed', 'failed', 'canceled'])) {
            echo json_encode(['status' => 'error', 'error' => 'Job already finished or canceled']);
            return;
        }

        $stmt = $db->prepare("UPDATE remote_upload_queue SET status = 'canceled' WHERE id = ?");
        $stmt->execute([$jobId]);

        \App\Core\Logger::info("Remote Upload Canceled", ['job_id' => $jobId, 'user' => \App\Core\Auth::id()]);
        echo json_encode(['status' => 'success', 'message' => 'Remote upload canceled.']);
    }

    private function checkAuth()
    {
        if (!Auth::check()) {
            http_response_code(401);
            die(json_encode(['status' => 'error', 'error' => 'Login required']));
        }
    }
}
