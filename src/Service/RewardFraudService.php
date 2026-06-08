<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Model\Setting;
use App\Service\Database\SchemaService;

class RewardFraudService
{
    private static bool $schemaReady = false;
    private static bool $legacyReferralBackfillReady = false;
    private array $trustTierCache = [];
    private const VISITOR_COOKIE = 'fyu_vid';
    private const REMOTE_EVENT_WINDOW_SECONDS = 300;
    private const CLEANUP_BATCH_SIZE = 10000;
    private const CLUSTER_REVIEW_BATCH_SIZE = 2000;
    private const STREAM_MAX_CLOCK_SKEW_SECONDS = 5;
    private const STREAM_MIN_HEARTBEAT_COUNT = 2;
    private const STREAM_MIN_HEARTBEAT_WINDOW_SECONDS = 10;
    private const STREAM_MAX_DURATION_DRIFT_SECONDS = 5;
    private const REMOTE_COMPLETE_MIN_TELEMETRY_WINDOW_SECONDS = 5;
    private const DECRYPT_SEARCH_SCAN_LIMIT = 1000;
    private const TRUST_TIER_VALUES = ['trusted', 'normal', 'watched', 'restricted', 'blocked'];

    public function ensureSchema(bool $repairDrift = false, bool $runCompatibilityBackfills = false): void
    {
        if (self::$schemaReady && (!$repairDrift && (!$runCompatibilityBackfills || self::$legacyReferralBackfillReady))) {
            return;
        }

        if ($repairDrift) {
            SchemaService::beginRepairWindow();
        }

        try {
            SchemaService::ensureTables([
                'reward_receipts',
                'earnings',
                'download_sessions',
                'download_session_events',
                'fraud_account_scores',
                'fraud_account_controls',
                'fraud_network_summaries',
                'remote_reward_event_nonces',
            ], $repairDrift);

            if ($runCompatibilityBackfills) {
                $db = Database::getInstance()->getConnection();
                $this->backfillLegacyReferralParents($db);
                self::$legacyReferralBackfillReady = true;
            }

            self::$schemaReady = true;
        } finally {
            if ($repairDrift) {
                SchemaService::endRepairWindow();
            }
        }
    }

    private function backfillLegacyReferralParents(\PDO $db): void
    {
        foreach ([
            "UPDATE `earnings`
             SET `parent_earning_id` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.parent_earning_id')) AS UNSIGNED)
             WHERE `type` = 'referral'
               AND `parent_earning_id` IS NULL
               AND JSON_EXTRACT(`metadata`, '$.parent_earning_id') IS NOT NULL",
            "UPDATE `earnings`
             SET `parent_earning_id` = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(`description`, '#', -1), ' ', 1) AS UNSIGNED)
             WHERE `type` = 'referral'
               AND `parent_earning_id` IS NULL
               AND `description` LIKE 'Referral commission for earning #%'"
        ] as $statement) {
            try {
                $db->exec($statement);
            } catch (\Throwable $e) {
            }
        }
    }

    public function ensureVisitorCookie(): string
    {
        $current = trim((string)($_COOKIE[self::VISITOR_COOKIE] ?? ''));
        if ($current !== '') {
            return $current;
        }

        $value = bin2hex(random_bytes(16));
        $isHttps = \App\Service\SecurityService::isHttpsRequest();
        setcookie(self::VISITOR_COOKIE, $value, [
            'expires' => time() + (86400 * 365),
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::VISITOR_COOKIE] = $value;
        return $value;
    }

    public function rememberDownloadPageReferrer(int $fileId): void
    {
        $fileId = max(0, $fileId);
        if ($fileId <= 0) {
            return;
        }

        $referrer = $this->normalizeReferrer((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referrer === null) {
            return;
        }

        if (!isset($_SESSION['fraud_download_page_referrers']) || !is_array($_SESSION['fraud_download_page_referrers'])) {
            $_SESSION['fraud_download_page_referrers'] = [];
        }

        $entries = $_SESSION['fraud_download_page_referrers'];
        foreach ($entries as $key => $entry) {
            $capturedAt = (int)($entry['captured_at'] ?? 0);
            if ($capturedAt > 0 && $capturedAt < (time() - 86400)) {
                unset($entries[$key]);
            }
        }

        $entries[$fileId] = $referrer + ['captured_at' => time()];
        $_SESSION['fraud_download_page_referrers'] = $entries;
    }

    public function buildClientSignals(array $clientHints = [], ?string $ip = null): array
    {
        $this->ensureSchema();
        $visitorId = $this->ensureVisitorCookie();
        $ip = SecurityService::normalizeIp($ip ?? SecurityService::getClientIp());
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $acceptLanguage = trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        $timezoneOffset = isset($clientHints['timezone_offset']) && $clientHints['timezone_offset'] !== ''
            ? (int)$clientHints['timezone_offset']
            : null;
        $platformBucket = trim((string)($clientHints['platform_bucket'] ?? ''));
        $screenBucket = trim((string)($clientHints['screen_bucket'] ?? ''));
        $countryCode = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $countryCode = null;
        }

        return [
            'ip_hash' => $this->hashValue($ip),
            'ua_hash' => $ua !== '' ? $this->hashValue($ua) : null,
            'visitor_cookie_hash' => $this->hashValue($visitorId),
            'accept_language_hash' => $acceptLanguage !== '' ? $this->hashValue($acceptLanguage) : null,
            'timezone_offset' => $timezoneOffset,
            'platform_bucket' => $platformBucket !== '' ? substr($platformBucket, 0, 64) : null,
            'screen_bucket' => $screenBucket !== '' ? substr($screenBucket, 0, 32) : null,
            'asn' => trim((string)($clientHints['asn'] ?? '')) ?: null,
            'network_type' => trim((string)($clientHints['network_type'] ?? '')) ?: null,
            'country_code' => $countryCode,
        ];
    }

    public function isFraudProtectionEnabled(): bool
    {
        return Setting::get('rewards_fraud_enabled', '1') === '1';
    }

    public function shouldRequireVerifiedCompletion(array $file): bool
    {
        return $this->isFraudProtectionEnabled()
            && $this->isFileRewardEligible($file)
            && Setting::get('rewards_verified_completion_required', '1') === '1';
    }

    public function isFileRewardEligible(array $file): bool
    {
        if (!FeatureService::rewardsEnabled()) {
            return false;
        }

        if (array_key_exists('allow_ppd', $file) && (int)$file['allow_ppd'] !== 1) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT u.monetization_model, p.ppd_enabled, p.pps_enabled
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)($file['user_id'] ?? 0)]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        return MonetizationModelService::ppdEligible(
            (string)($row['monetization_model'] ?? 'ppd'),
            [
                'ppd_enabled' => (int)($row['ppd_enabled'] ?? 0),
                'pps_enabled' => (int)($row['pps_enabled'] ?? 0),
            ]
        );
    }

    private function guestsOnlyRewardsEnabled(): bool
    {
        return Setting::get('rewards_ppd_guests_only', '0') === '1'
            || Setting::get('ppd_only_guests_count', '0') === '1';
    }

    public function createDownloadSession(array $file, ?int $downloaderUserId, array $clientHints = [], string $rewardMode = 'download'): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $signals = $this->buildClientSignals($clientHints);
        $publicId = bin2hex(random_bytes(16));
        $bytesExpected = (int)($file['file_size'] ?? 0);
        $referrer = $this->resolveDownloadPageReferrer((int)($file['id'] ?? 0));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);

        $stmt = $db->prepare("
            INSERT INTO download_sessions (
                public_id, file_id, uploader_user_id, downloader_user_id, delivery_mode, reward_mode, status,
                ip_hash, ua_hash, visitor_cookie_hash, accept_language_hash, timezone_offset, platform_bucket,
                screen_bucket, asn, network_type, country_code, download_page_referrer_url, download_page_referrer_host,
                download_page_referrer_internal, bytes_expected, expires_at
            ) VALUES (
                :public_id, :file_id, :uploader_user_id, :downloader_user_id, 'php_proxy', :reward_mode, 'created',
                :ip_hash, :ua_hash, :visitor_cookie_hash, :accept_language_hash, :timezone_offset, :platform_bucket,
                :screen_bucket, :asn, :network_type, :country_code, :download_page_referrer_url, :download_page_referrer_host,
                :download_page_referrer_internal, :bytes_expected, :expires_at
            )
        ");
        $stmt->execute([
            'public_id' => $publicId,
            'file_id' => (int)$file['id'],
            'uploader_user_id' => (int)$file['user_id'],
            'downloader_user_id' => $downloaderUserId,
            'reward_mode' => $rewardMode,
            'ip_hash' => $signals['ip_hash'],
            'ua_hash' => $signals['ua_hash'],
            'visitor_cookie_hash' => $signals['visitor_cookie_hash'],
            'accept_language_hash' => $signals['accept_language_hash'],
            'timezone_offset' => $signals['timezone_offset'],
            'platform_bucket' => $signals['platform_bucket'],
            'screen_bucket' => $signals['screen_bucket'],
            'asn' => $signals['asn'],
            'network_type' => $signals['network_type'],
            'country_code' => $signals['country_code'],
            'download_page_referrer_url' => $referrer['url'] ?? null,
            'download_page_referrer_host' => $referrer['host'] ?? null,
            'download_page_referrer_internal' => !empty($referrer['internal']) ? 1 : 0,
            'bytes_expected' => $bytesExpected,
            'expires_at' => $expiresAt,
        ]);

        $sessionId = (int)$db->lastInsertId();

        $this->recordSessionEventById($sessionId, 'start', [
            'event_public_id' => bin2hex(random_bytes(16)),
            'signature_valid' => 1,
            'bytes_sent' => 0,
        ]);

        // In intelligence mode, persist proxy intel onto the download session
        // so fraud scoring can use it later without hard-blocking the visitor.
        if ($this->shouldCaptureProxyIntel()) {
            try {
                $security = new \App\Service\SecurityService();
                $intel = $security->lookupProxyIntel(\App\Service\SecurityService::getClientIp());
                $db->prepare("
                    UPDATE download_sessions
                    SET proxy_intel_risk_score = ?,
                        proxy_intel_type = ?,
                        proxy_intel_provider = ?,
                        proxy_intel_last_seen = ?
                    WHERE id = ?
                ")->execute([
                    $intel['risk'],
                    $intel['type'],
                    $intel['provider'],
                    $intel['last_seen'],
                    $sessionId,
                ]);
            } catch (\Throwable $e) {
                // non-fatal; don't break the download over an intel lookup failure
                error_log("PROXY_INTEL: failed to stamp session $publicId: " . $e->getMessage());
            }
        }

        return $this->findSessionByPublicId($publicId) ?? ['public_id' => $publicId] + $signals;
    }

    public function findSessionByPublicId(string $publicId): ?array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM download_sessions WHERE public_id = ? LIMIT 1");
        $stmt->execute([$publicId]);
        return $stmt->fetch() ?: null;
    }

    public function validateSessionForCurrentVisitor(string $publicId, array $file): ?array
    {
        $session = $this->findSessionByPublicId($publicId);
        if (!$session) {
            return null;
        }

        if ((int)$session['file_id'] !== (int)$file['id']) {
            return null;
        }

        if (!in_array((string)$session['status'], ['created', 'started', 'progressing'], true)) {
            return null;
        }

        if (!empty($session['expires_at']) && strtotime((string)$session['expires_at']) < time()) {
            return null;
        }

        $signals = $this->buildClientSignals([
            'timezone_offset' => $_REQUEST['tz'] ?? $_POST['timezone_offset'] ?? null,
            'platform_bucket' => $_REQUEST['platform_bucket'] ?? $_POST['platform_bucket'] ?? '',
            'screen_bucket' => $_REQUEST['screen_bucket'] ?? $_POST['screen_bucket'] ?? '',
        ]);

        if (($session['ip_hash'] ?? '') !== $signals['ip_hash']) {
            return null;
        }

        if (!empty($session['ua_hash']) && !empty($signals['ua_hash']) && $session['ua_hash'] !== $signals['ua_hash']) {
            return null;
        }

        if (!empty($session['visitor_cookie_hash']) && !empty($signals['visitor_cookie_hash']) && $session['visitor_cookie_hash'] !== $signals['visitor_cookie_hash']) {
            return null;
        }

        return $session;
    }

    public function markSessionStarted(string $publicId, string $deliveryMode): void
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE download_sessions
            SET status = 'started', delivery_mode = ?, updated_at = NOW()
            WHERE public_id = ? AND status = 'created'
        ");
        $stmt->execute([$deliveryMode, $publicId]);
    }

    public function claimDownloadCountForSession(string $publicId): bool
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE download_sessions
            SET download_counted_at = NOW()
            WHERE public_id = ? AND download_counted_at IS NULL
        ");
        $stmt->execute([$publicId]);
        return $stmt->rowCount() === 1;
    }

    public function claimDownloadCountForSessionId(int $sessionId): bool
    {
        if ($sessionId <= 0) {
            return false;
        }

        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE download_sessions
            SET download_counted_at = NOW()
            WHERE id = ? AND download_counted_at IS NULL
        ");
        $stmt->execute([$sessionId]);
        return $stmt->rowCount() === 1;
    }

    public function exportRewardSignalContext(array $session): array
    {
        return [
            'ip_hash' => (string)($session['ip_hash'] ?? ''),
            'ua_hash' => (string)($session['ua_hash'] ?? ''),
            'visitor_cookie_hash' => (string)($session['visitor_cookie_hash'] ?? ''),
            'accept_language_hash' => (string)($session['accept_language_hash'] ?? ''),
            'timezone_offset' => isset($session['timezone_offset']) && $session['timezone_offset'] !== null ? (int)$session['timezone_offset'] : null,
            'platform_bucket' => isset($session['platform_bucket']) && $session['platform_bucket'] !== '' ? (string)$session['platform_bucket'] : null,
            'screen_bucket' => isset($session['screen_bucket']) && $session['screen_bucket'] !== '' ? (string)$session['screen_bucket'] : null,
            'asn' => isset($session['asn']) && $session['asn'] !== '' ? (string)$session['asn'] : null,
            'network_type' => isset($session['network_type']) && $session['network_type'] !== '' ? (string)$session['network_type'] : null,
            'country_code' => isset($session['country_code']) && preg_match('/^[A-Z]{2}$/', strtoupper((string)$session['country_code'])) ? strtoupper((string)$session['country_code']) : null,
            'proxy_intel_risk_score' => max(0, min(100, (int)($session['proxy_intel_risk_score'] ?? 0))),
            'proxy_intel_type' => isset($session['proxy_intel_type']) && $session['proxy_intel_type'] !== '' ? (string)$session['proxy_intel_type'] : null,
            'proxy_intel_provider' => isset($session['proxy_intel_provider']) && $session['proxy_intel_provider'] !== '' ? (string)$session['proxy_intel_provider'] : null,
            'proxy_intel_last_seen' => isset($session['proxy_intel_last_seen']) && $session['proxy_intel_last_seen'] !== '' ? (string)$session['proxy_intel_last_seen'] : null,
        ];
    }

    private function shouldCaptureProxyIntel(): bool
    {
        return \App\Service\SecurityService::getVpnProtectionMode() === 'intelligence'
            && trim((string)Setting::getEncrypted('proxycheck_api_key', '')) !== '';
    }

    public function recordDownloadProgress(string $publicId, int $bytesSent, int $bytesExpected): void
    {
        $this->ensureSchema();
        $percent = $bytesExpected > 0 ? min(100, round(($bytesSent / $bytesExpected) * 100, 2)) : 100;
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE download_sessions
            SET status = 'progressing',
                bytes_sent = GREATEST(bytes_sent, ?),
                percent_complete = GREATEST(percent_complete, ?),
                updated_at = NOW()
            WHERE public_id = ?
        ");
        $stmt->execute([$bytesSent, $percent, $publicId]);
    }

    public function finalizeDownloadSession(string $publicId, array $file, string $ip, ?int $downloaderUserId = null): ?array
    {
        $this->ensureSchema();
        $session = $this->findSessionByPublicId($publicId);
        if (!$session) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $bytesExpected = max(1, (int)($session['bytes_expected'] ?? $file['file_size'] ?? 0));
        $bytesSent = max(0, (int)($session['bytes_sent'] ?? 0));
        $bytesSent = min($bytesSent, $bytesExpected);
        $percent = min(100, round(($bytesSent / $bytesExpected) * 100, 2));

        $reasons = [];
        $requiredPercent = max(1, (int)Setting::get('rewards_verified_completion_percent', '95'));
        if ($percent < $requiredPercent) {
            $reasons[] = "Completion proof below required threshold ({$percent}% < {$requiredPercent}%).";
        }

        $score = $this->calculateRiskScore($session, $file, $reasons);
        $level = $score >= (int)Setting::get('rewards_flag_threshold', '50') ? 'high'
            : ($score >= (int)Setting::get('rewards_review_threshold', '25') ? 'medium' : 'low');

        $status = empty($reasons) ? 'completed' : 'flagged';
        $stmt = $db->prepare("
            UPDATE download_sessions
            SET status = ?, bytes_sent = GREATEST(bytes_sent, ?), percent_complete = GREATEST(percent_complete, ?),
                risk_score = ?, risk_level = ?, risk_reasons_json = ?, updated_at = NOW()
            WHERE public_id = ?
        ");
        $stmt->execute([
            $status,
            $bytesSent,
            $percent,
            $score,
            $level,
            json_encode($reasons, JSON_UNESCAPED_SLASHES),
            $publicId,
        ]);

        return [
            'session' => $this->findSessionByPublicId($publicId),
            'risk_score' => $score,
            'risk_level' => $level,
            'reasons' => $reasons,
            'proof_status' => empty($reasons) ? 'verified' : 'flagged',
            'downloader_user_id' => $downloaderUserId,
        ];
    }

    public function recordStreamHeartbeat(string $publicId, array $file, int $watchSeconds, float $watchPercent, array $meta = []): ?array
    {
        $this->ensureSchema();
        $session = $this->findSessionByPublicId($publicId);
        if (!$session || (int)$session['file_id'] !== (int)$file['id']) {
            return null;
        }

        $telemetry = $this->normalizeStreamTelemetry($session, $watchSeconds, $watchPercent, $meta);
        if (!$telemetry['accepted']) {
            return null;
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE download_sessions
            SET reward_mode = 'stream',
                status = CASE WHEN status = 'created' THEN 'progressing' ELSE status END,
                watch_seconds = GREATEST(watch_seconds, ?),
                watch_percent = GREATEST(watch_percent, ?),
                updated_at = NOW()
            WHERE public_id = ?
        ");
        $stmt->execute([$telemetry['watch_seconds'], $telemetry['watch_percent'], $publicId]);

        $this->recordSessionEventById((int)$session['id'], 'stream_heartbeat', [
            'event_public_id' => bin2hex(random_bytes(16)),
            'signature_valid' => 1,
            'watch_seconds' => $telemetry['watch_seconds'],
            'watch_percent' => $telemetry['watch_percent'],
            'event_payload' => [
                'current_time' => $telemetry['current_time'],
                'duration' => $telemetry['duration'],
                'state' => (string)($meta['state'] ?? 'progress'),
                'rejected_reason' => $telemetry['reason'] ?? null,
            ],
        ]);

        return $this->findSessionByPublicId($publicId);
    }

    public function completeStreamSession(string $publicId, array $file, ?int $downloaderUserId = null): ?array
    {
        $this->ensureSchema();
        $session = $this->findSessionByPublicId($publicId);
        if (!$session || (int)$session['file_id'] !== (int)$file['id']) {
            return null;
        }

        $requiredPercent = max(0, min(100, (int)Setting::get('rewards_min_video_watch_percent', '80')));
        $requiredSeconds = max(0, (int)Setting::get('rewards_min_video_watch_seconds', '30'));
        $watchPercent = (float)($session['watch_percent'] ?? 0);
        $watchSeconds = (int)($session['watch_seconds'] ?? 0);
        $reasons = [];
        $elapsedSeconds = max(0, time() - strtotime((string)($session['created_at'] ?? 'now')));
        $heartbeatSummary = $this->getStreamHeartbeatSummary((int)$session['id']);

        if ($watchPercent < $requiredPercent) {
            $reasons[] = "Video watch percent below required threshold ({$watchPercent}% < {$requiredPercent}%).";
        }
        if ($watchSeconds < $requiredSeconds) {
            $reasons[] = "Video watch seconds below required threshold ({$watchSeconds}s < {$requiredSeconds}s).";
        }
        if ($elapsedSeconds + self::STREAM_MAX_CLOCK_SKEW_SECONDS < $requiredSeconds) {
            $reasons[] = "Playback session elapsed time was shorter than the required {$requiredSeconds} seconds.";
        }
        if (($heartbeatSummary['count'] ?? 0) < self::STREAM_MIN_HEARTBEAT_COUNT) {
            $reasons[] = 'Insufficient playback heartbeat telemetry was recorded.';
        }
        if (($heartbeatSummary['window_seconds'] ?? 0) < self::STREAM_MIN_HEARTBEAT_WINDOW_SECONDS) {
            $reasons[] = 'Playback heartbeat window was too short to trust completion proof.';
        }

        $score = $this->calculateRiskScore($session, $file, $reasons);
        $level = $score >= (int)Setting::get('rewards_flag_threshold', '50') ? 'high'
            : ($score >= (int)Setting::get('rewards_review_threshold', '25') ? 'medium' : 'low');
        $status = empty($reasons) ? 'completed' : 'flagged';

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE download_sessions
            SET reward_mode = 'stream',
                status = ?,
                risk_score = ?,
                risk_level = ?,
                risk_reasons_json = ?,
                updated_at = NOW()
            WHERE public_id = ?
        ");
        $stmt->execute([
            $status,
            $score,
            $level,
            json_encode($reasons, JSON_UNESCAPED_SLASHES),
            $publicId,
        ]);

        return [
            'session' => $this->findSessionByPublicId($publicId),
            'risk_score' => $score,
            'risk_level' => $level,
            'reasons' => $reasons,
            'proof_status' => empty($reasons) ? 'verified_stream' : 'flagged_stream',
            'downloader_user_id' => $downloaderUserId,
        ];
    }

    public function verifyAndRecordRemoteReceipt(array $payload, string $sourceIp): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();

        $serverId = (int)($payload['server_id'] ?? 0);
        $sessionPublicId = trim((string)($payload['session_id'] ?? ''));
        $eventId = trim((string)($payload['event_id'] ?? ''));
        $nonce = trim((string)($payload['nonce'] ?? ''));
        $timestamp = (int)($payload['timestamp'] ?? 0);
        $completionState = trim((string)($payload['completion_state'] ?? ''));
        $signature = trim((string)($payload['signature'] ?? ''));
        $bytesSent = max(0, (int)($payload['bytes_sent'] ?? 0));
        $watchSeconds = max(0, (int)($payload['watch_seconds'] ?? 0));
        $watchPercent = max(0, min(100, (float)($payload['watch_percent'] ?? 0)));
        $clientIp = SecurityService::normalizeIp((string)($payload['client_ip'] ?? ''));

        if ($serverId <= 0 || $sessionPublicId === '' || $eventId === '' || $nonce === '' || $timestamp <= 0 || $completionState === '' || $signature === '') {
            return ['ok' => false, 'code' => 400, 'error' => 'Incomplete receipt payload.'];
        }

        if (abs(time() - $timestamp) > self::REMOTE_EVENT_WINDOW_SECONDS) {
            return ['ok' => false, 'code' => 401, 'error' => 'Receipt timestamp is outside the allowed window.'];
        }

        if (!in_array($completionState, ['started', 'progress', 'complete'], true)) {
            return ['ok' => false, 'code' => 400, 'error' => 'Invalid receipt completion state.'];
        }

        if ($clientIp === '') {
            return ['ok' => false, 'code' => 400, 'error' => 'Client IP is required for remote reward receipts.'];
        }

        $session = $this->findSessionByPublicId($sessionPublicId);
        if (!$session) {
            return ['ok' => false, 'code' => 404, 'error' => 'Unknown download session.'];
        }
        if (!in_array((string)($session['status'] ?? ''), ['created', 'started', 'progressing'], true)) {
            return ['ok' => false, 'code' => 409, 'error' => 'Download session is no longer active.'];
        }
        if (!empty($session['expires_at']) && strtotime((string)$session['expires_at']) < time()) {
            return ['ok' => false, 'code' => 410, 'error' => 'Download session has expired.'];
        }
        if (!empty($session['ip_hash']) && $clientIp !== '' && $this->hashValue($clientIp) !== (string)$session['ip_hash']) {
            return ['ok' => false, 'code' => 401, 'error' => 'Client IP does not match the download session.'];
        }

        $server = $this->loadRemoteServer($serverId);
        if (!$server) {
            return ['ok' => false, 'code' => 401, 'error' => 'Unknown reporting server.'];
        }

        if (!$this->hasRemoteSourceIpAllowlist($server)) {
            return ['ok' => false, 'code' => 401, 'error' => 'Reward callback IP allowlist is required for remote reporting.'];
        }

        if (!$this->hasRemoteCallbackSecret($server)) {
            return ['ok' => false, 'code' => 401, 'error' => 'Reward callback secret is required for remote reporting.'];
        }

        if (!$this->validateSourceIp($sourceIp, $server)) {
            return ['ok' => false, 'code' => 401, 'error' => 'Source IP is not allowed for this reporting server.'];
        }

        $expectedSignature = $this->buildRemoteReceiptSignature($payload, $server);
        if (!hash_equals($expectedSignature, $signature)) {
            return ['ok' => false, 'code' => 401, 'error' => 'Invalid receipt signature.'];
        }

        $file = \App\Model\File::find((int)$session['file_id']);
        if (!$file) {
            return ['ok' => false, 'code' => 404, 'error' => 'Session file no longer exists.'];
        }

        if (!$this->remoteServerMatchesFile($serverId, $file)) {
            return ['ok' => false, 'code' => 401, 'error' => 'Reporting server does not match the file storage server.'];
        }

        $bytesExpected = max(1, (int)($session['bytes_expected'] ?? $file['file_size'] ?? 0));
        $boundedBytes = min($bytesSent, (int)ceil($bytesExpected * 1.05));
        $percent = $bytesExpected > 0 ? min(100, round(($boundedBytes / $bytesExpected) * 100, 2)) : 100;
        try {
            $db->beginTransaction();

            if ($this->remoteEventExists($eventId, $db)) {
                $db->commit();
                return ['ok' => true, 'code' => 202, 'status' => 'duplicate', 'session' => $session];
            }

            if (!$this->claimRemoteNonce($nonce, $db)) {
                $db->rollBack();
                return ['ok' => false, 'code' => 409, 'error' => 'Receipt nonce has already been used.'];
            }

            $this->recordSessionEventById((int)$session['id'], 'edge_receipt', [
                'server_id' => $serverId,
                'event_public_id' => $eventId,
                'nonce' => $nonce,
                'signature_valid' => 1,
                'bytes_sent' => $boundedBytes,
                'watch_seconds' => $watchSeconds,
                'watch_percent' => $watchPercent,
                'source_ip_hash' => $this->hashValue(SecurityService::normalizeIp($sourceIp)),
                'event_payload' => [
                    'completion_state' => $completionState,
                    'client_ip' => $clientIp,
                ],
            ]);

            [$sessionStatus, $resultStatus, $proofStatus] = $this->evaluateRemoteReceiptProof($session, $file, $completionState, $percent, $watchSeconds, $watchPercent);

            $stmt = $db->prepare("
                UPDATE download_sessions
                SET status = CASE
                        WHEN ? IN ('completed', 'flagged') THEN ?
                        WHEN ? IN ('progress', 'started') THEN 'progressing'
                        ELSE status
                    END,
                    delivery_mode = 'remote_node',
                    bytes_sent = GREATEST(bytes_sent, ?),
                    percent_complete = GREATEST(percent_complete, ?),
                    watch_seconds = GREATEST(watch_seconds, ?),
                    watch_percent = GREATEST(watch_percent, ?),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $sessionStatus,
                $sessionStatus,
                $completionState,
                $boundedBytes,
                $percent,
                $watchSeconds,
                $watchPercent,
                (int)$session['id'],
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $session = $this->findSessionByPublicId($sessionPublicId) ?? $session;

        return [
            'ok' => true,
            'code' => 202,
            'status' => $resultStatus,
            'session' => $session,
            'file' => $file,
            'client_ip' => $clientIp,
            'downloader_user_id' => !empty($session['downloader_user_id']) ? (int)$session['downloader_user_id'] : null,
            'proof_status' => $proofStatus,
        ];
    }

    public function evaluateReceipt(array $receipt, array $file): array
    {
        $reasons = [];
        $score = 0;
        $trustTier = $this->getUploaderTrustTier((int)($receipt['user_id'] ?? 0));

        if (!empty($receipt['downloader_user_id']) && (int)$receipt['downloader_user_id'] === (int)$file['user_id']) {
            $score += 100;
            $reasons[] = 'Uploader attempted to credit their own file.';
        }

        $db = Database::getInstance()->getConnection();
        if ($this->isSignalEnabled('rewards_use_cookie_hash') && !empty($receipt['visitor_cookie_hash'])) {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT ip_hash)
                FROM reward_receipts
                WHERE user_id = ? AND visitor_cookie_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([(int)$receipt['user_id'], (string)$receipt['visitor_cookie_hash']]);
            $distinctIps = (int)$stmt->fetchColumn();
            if ($distinctIps >= 3) {
                $score += 25;
                $reasons[] = "Same visitor cookie observed across {$distinctIps} IPs in 24 hours.";
            }
        }

        if ($this->isSignalEnabled('rewards_use_ua_hash') && !empty($receipt['ua_hash'])) {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM reward_receipts
                WHERE user_id = ? AND file_id = ? AND ua_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([(int)$receipt['user_id'], (int)$receipt['file_id'], (string)$receipt['ua_hash']]);
            $similarUaCount = (int)$stmt->fetchColumn();
            if ($similarUaCount >= 5) {
                $score += 15;
                $reasons[] = "Repeated reward attempts with the same browser signature ({$similarUaCount} in 24 hours).";
            }
        }

        if (!in_array((string)($receipt['proof_status'] ?? ''), ['verified', 'verified_stream'], true)) {
            $score += 35;
            $reasons[] = 'Reward credited without strong verified completion proof.';
        }

        if (
            $this->isSignalEnabled('rewards_use_asn_network')
            && !empty($receipt['country_code'])
            && !empty($receipt['network_type'])
            && in_array($receipt['network_type'], ['hosting', 'proxy', 'datacenter'], true)
        ) {
            $score += 20;
            $reasons[] = 'High-value country traffic arrived from a non-consumer network type.';
        }

        if ($this->isSignalEnabled('rewards_use_proxy_intel')) {
            $proxyIntelScore = (int)($receipt['proxy_intel_risk_score'] ?? 0);
            // if the receipt doesn't carry the score directly, pull it from the linked session
            if ($proxyIntelScore === 0 && !empty($receipt['session_id'])) {
                try {
                    $piStmt = $db->prepare("SELECT proxy_intel_risk_score FROM download_sessions WHERE id = ? LIMIT 1");
                    $piStmt->execute([(int)$receipt['session_id']]);
                    $proxyIntelScore = (int)($piStmt->fetchColumn() ?: 0);
                } catch (\Throwable $e) { }
            }
            if ($proxyIntelScore > 0) {
                $score += $proxyIntelScore;
                $reasons[] = "Proxy intelligence risk score: {$proxyIntelScore}.";
            }
        }

        if ($this->isSignalEnabled('rewards_use_cloudflare_intel')) {
            $score += (int)($receipt['cloudflare_risk_score'] ?? 0);
        }

        $downloader = $this->getDownloaderMeta(isset($receipt['downloader_user_id']) ? (int)$receipt['downloader_user_id'] : null);
        if ($downloader !== null) {
            if ($this->guestsOnlyRewardsEnabled()) {
                $score += 40;
                $reasons[] = 'Logged-in downloader traffic is disabled for PPD in Rewards Fraud settings.';
            }

            if (Setting::get('rewards_require_downloader_verification', '0') === '1' && (int)($downloader['email_verified'] ?? 0) !== 1) {
                $score += 30;
                $reasons[] = 'Downloader account is not email verified.';
            }

            $minAgeDays = max(0, (int)Setting::get('rewards_min_downloader_account_age_days', '0'));
            if ($minAgeDays > 0 && !empty($downloader['created_at'])) {
                $ageSeconds = time() - strtotime((string)$downloader['created_at']);
                if ($ageSeconds < ($minAgeDays * 86400)) {
                    $score += 25;
                    $reasons[] = "Downloader account is newer than the required {$minAgeDays}-day minimum.";
                }
            }

            if (Setting::get('rewards_hold_new_account_downloads', '0') === '1' && !empty($downloader['created_at'])) {
                $ageSeconds = time() - strtotime((string)$downloader['created_at']);
                if ($ageSeconds < 86400) {
                    $score += 20;
                    $reasons[] = 'Downloader account is less than 24 hours old.';
                }
            }
        }

        if (Setting::get('rewards_block_linked_downloader_accounts', '0') === '1' && !empty($receipt['downloader_user_id'])) {
            if ($this->looksLikeLinkedDownloader((int)$receipt['user_id'], (int)$receipt['downloader_user_id'], (string)($receipt['visitor_cookie_hash'] ?? ''), (string)($receipt['ip_hash'] ?? ''))) {
                $score += 45;
                $reasons[] = 'Downloader appears linked to a recent visitor signature cluster for this uploader.';
            }
        }

        [$score, $reasons] = $this->applyTrustTierRiskAdjustments($score, $reasons, $trustTier);

        $level = $score >= (int)Setting::get('rewards_flag_threshold', '50') ? 'high'
            : ($score >= (int)Setting::get('rewards_review_threshold', '25') ? 'medium' : 'low');

        return [
            'score' => $score,
            'level' => $level,
            'reasons' => array_values(array_unique($reasons)),
            'trust_tier' => $trustTier,
        ];
    }

    public function recomputeAccountScores(): int
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $db->exec("TRUNCATE TABLE fraud_network_summaries");

        $stmt = $db->query("
            SELECT user_id,
                   AVG(risk_score) AS avg_score,
                   SUM(CASE WHEN status = 'held' THEN 1 ELSE 0 END) AS held_count,
                   SUM(CASE WHEN status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count,
                   COUNT(DISTINCT file_id) AS suspicious_file_count,
                   COUNT(DISTINCT CONCAT(COALESCE(country_code, ''), ':', COALESCE(network_type, ''))) AS suspicious_network_count
            FROM earnings
            WHERE risk_score > 0
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY user_id
        ");
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $upsert = $db->prepare("
                INSERT INTO fraud_account_scores (user_id, risk_score, held_count, flagged_count, suspicious_file_count, suspicious_network_count)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    risk_score = VALUES(risk_score),
                    held_count = VALUES(held_count),
                    flagged_count = VALUES(flagged_count),
                    suspicious_file_count = VALUES(suspicious_file_count),
                    suspicious_network_count = VALUES(suspicious_network_count)
            ");
            $upsert->execute([
                (int)$row['user_id'],
                (int)round((float)$row['avg_score']),
                (int)$row['held_count'],
                (int)$row['flagged_count'],
                (int)$row['suspicious_file_count'],
                (int)$row['suspicious_network_count'],
            ]);
        }

        $networkRows = $db->query("
            SELECT
                asn,
                country_code,
                network_type,
                COUNT(*) AS session_count,
                SUM(CASE WHEN status = 'held' THEN 1 ELSE 0 END) AS held_count,
                SUM(CASE WHEN status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count
            FROM earnings
            WHERE risk_score > 0
              AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND (country_code IS NOT NULL OR network_type IS NOT NULL OR asn IS NOT NULL)
            GROUP BY asn, country_code, network_type
        ")->fetchAll() ?: [];

        foreach ($networkRows as $row) {
            $insert = $db->prepare("
                INSERT INTO fraud_network_summaries (asn, country_code, network_type, session_count, held_count, flagged_count)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $row['asn'] ?? null,
                $row['country_code'] ?? null,
                $row['network_type'] ?? null,
                (int)$row['session_count'],
                (int)$row['held_count'],
                (int)$row['flagged_count'],
            ]);
        }

        return count($rows);
    }

    public function clearHeldEarnings(): int
    {
        $this->ensureSchema();
        $this->applyAutomaticQueueDecisions();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT id
            FROM earnings
            WHERE status = 'held'
              AND hold_until IS NOT NULL
              AND hold_until <= NOW()
              AND type <> 'referral'
            ORDER BY id ASC
            LIMIT 5000
        ");
        $rows = $stmt->fetchAll() ?: [];
        if (empty($rows)) {
            return 0;
        }

        $cleared = 0;
        foreach ($rows as $row) {
            try {
                if ($this->clearHeldEarningById((int)($row['id'] ?? 0))) {
                    $cleared++;
                }
            } catch (\Throwable $e) {
                \App\Core\Logger::error('Held earning clearance failed', [
                    'earning_id' => (int)($row['id'] ?? 0),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        BonusOfferService::flushQueuedTouchesFailSoft([
            'workflow' => 'clear_held_earnings',
        ]);

        return $cleared;
    }

    public function applyAutomaticQueueDecisions(int $limit = 1000): array
    {
        $this->ensureSchema();
        $limit = max(50, min(5000, $limit));
        $db = Database::getInstance()->getConnection();
        $reviewThreshold = max(0, (int)Setting::get('rewards_review_threshold', '25'));
        $autoReverseThreshold = max($reviewThreshold + 1, (int)Setting::get('rewards_auto_reverse_threshold', '85'));
        $autoClearLowRisk = Setting::get('rewards_auto_clear_low_risk', '0') === '1';
        $autoReverseHighRisk = Setting::get('rewards_auto_reverse_high_risk', '1') === '1';

        $actions = ['cleared' => 0, 'reversed' => 0];
        $queues = [];

        $reverseSql = "
            SELECT e.id, e.risk_score, COALESCE(fac.trust_tier, 'normal') AS trust_tier
            FROM earnings e
            LEFT JOIN fraud_account_controls fac ON fac.user_id = e.user_id
            WHERE e.status IN ('held', 'flagged_review')
              AND (
                COALESCE(fac.trust_tier, 'normal') = 'blocked'
        ";
        if ($autoReverseHighRisk) {
            $reverseSql .= "
                OR COALESCE(fac.trust_tier, 'normal') = 'restricted'
                OR e.risk_score >= ?
            ";
        }
        $reverseSql .= "
              )
            ORDER BY
                CASE COALESCE(fac.trust_tier, 'normal')
                    WHEN 'blocked' THEN 0
                    WHEN 'restricted' THEN 1
                    ELSE 2
                END,
                e.risk_score DESC,
                e.created_at ASC
            LIMIT ?
        ";
        $reverseStmt = $db->prepare($reverseSql);
        $bindIndex = 1;
        if ($autoReverseHighRisk) {
            $reverseStmt->bindValue($bindIndex++, $autoReverseThreshold, \PDO::PARAM_INT);
        }
        $reverseStmt->bindValue($bindIndex, $limit, \PDO::PARAM_INT);
        $reverseStmt->execute();
        $queues[] = [
            'rows' => $reverseStmt->fetchAll() ?: [],
            'disposition' => 'reversed',
            'action' => 'reverse',
            'note' => 'Automatically reversed by rewards fraud rules.',
        ];

        if ($autoClearLowRisk) {
            $clearStmt = $db->prepare("
                SELECT e.id, e.risk_score, COALESCE(fac.trust_tier, 'normal') AS trust_tier
                FROM earnings e
                LEFT JOIN fraud_account_controls fac ON fac.user_id = e.user_id
                WHERE e.status IN ('held', 'flagged_review')
                  AND e.risk_score < ?
                  AND COALESCE(fac.trust_tier, 'normal') IN ('trusted', 'normal')
                ORDER BY e.created_at ASC, e.risk_score ASC
                LIMIT ?
            ");
            $clearStmt->bindValue(1, $reviewThreshold, \PDO::PARAM_INT);
            $clearStmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $clearStmt->execute();
            $queues[] = [
                'rows' => $clearStmt->fetchAll() ?: [],
                'disposition' => 'cleared',
                'action' => 'clear',
                'note' => 'Automatically cleared by rewards fraud rules.',
            ];
        }

        foreach ($queues as $queue) {
            foreach ($queue['rows'] as $row) {
                $disposition = $this->resolveAutomaticDisposition((int)($row['risk_score'] ?? 0), (string)($row['trust_tier'] ?? 'normal'));
                if ($disposition !== $queue['disposition']) {
                    continue;
                }
                if ($this->reviewEarning((int)$row['id'], $queue['action'], 0, $queue['note'], true)) {
                    $actions[$queue['disposition']]++;
                }
            }
        }

        BonusOfferService::flushQueuedTouchesFailSoft([
            'workflow' => 'automatic_reward_queue_review',
        ]);

        return $actions;
    }

    private function clearHeldEarningById(int $earningId): bool
    {
        if ($earningId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $earning = null;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT id, user_id, amount, type, created_at, status, hold_until
                FROM earnings
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$earningId]);
            $earning = $stmt->fetch();
            if (
                !$earning
                || (string)($earning['status'] ?? '') !== 'held'
                || (string)($earning['type'] ?? '') === 'referral'
                || empty($earning['hold_until'])
                || strtotime((string)$earning['hold_until']) > time()
            ) {
                $db->commit();
                return false;
            }

            $update = $db->prepare("
                UPDATE earnings
                SET status = 'cleared', hold_until = NULL
                WHERE id = ?
                  AND status = 'held'
                  AND hold_until IS NOT NULL
                  AND hold_until <= NOW()
            ");
            $update->execute([$earningId]);
            if ($update->rowCount() !== 1) {
                $db->commit();
                return false;
            }

            AffiliateRewardService::syncReferralChildrenForParent($db, $earningId, 'cleared');
            $this->applyClearedStats((int)$earning['user_id'], (float)$earning['amount'], (string)$earning['created_at'], (string)($earning['type'] ?? ''));

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if (!empty($earning['user_id'])) {
            BonusOfferService::queueUserTouch((int)$earning['user_id'], true);
        }

        return true;
    }

    public function reviewEarning(int $earningId, string $action, int $adminId, string $note = '', bool $deferBonusTouch = false): bool
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $earning = null;
        $reviewed = false;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT e.*, u.username, f.filename
                FROM earnings e
                LEFT JOIN users u ON u.id = e.user_id
                LEFT JOIN files f ON f.id = e.file_id
                WHERE e.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$earningId]);
            $earning = $stmt->fetch();
            if (!$earning || !in_array((string)$earning['status'], ['held', 'flagged_review'], true)) {
                $db->commit();
                return false;
            }

            ReviewIntegrityService::assertNotSelfRewardReview($adminId, (int)($earning['user_id'] ?? 0));

            if ($action === 'recommended') {
                $action = $this->resolveRecommendedActionForEarning($earning);
            }

            $targetStatus = match ($action) {
                'clear' => 'cleared',
                'hold' => 'held',
                'reverse' => 'reversed',
                default => null,
            };
            if ($targetStatus === null) {
                $db->commit();
                return false;
            }

            $holdUntil = null;
            if ($targetStatus === 'held') {
                $holdDays = max(0, (int)Setting::get('rewards_hold_days', '7'));
                $holdUntil = date('Y-m-d H:i:s', strtotime("+{$holdDays} days"));
            }

            $currentStatus = (string)$earning['status'];
            $update = $db->prepare("
                UPDATE earnings
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?, hold_until = ?
                WHERE id = ? AND status = ?
            ");
            $update->execute([$targetStatus, $adminId, $note, $holdUntil, $earningId, $currentStatus]);
            if ($update->rowCount() !== 1) {
                $db->commit();
                return false;
            }

            AffiliateRewardService::syncReferralChildrenForParent($db, $earningId, $targetStatus, $holdUntil);

            if ($targetStatus === 'cleared') {
                $this->applyClearedStats((int)$earning['user_id'], (float)$earning['amount'], (string)$earning['created_at'], (string)($earning['type'] ?? ''));
            }

            $db->commit();
            $reviewed = true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if (!$reviewed || !$earning) {
            return false;
        }

        if ($deferBonusTouch) {
            BonusOfferService::queueUserTouch((int)$earning['user_id'], true);
        } else {
            BonusOfferService::touchUserFailSoft((int)$earning['user_id'], true, [
                'workflow' => 'review_single_earning',
                'earning_id' => $earningId,
                'action' => $action,
            ]);
        }

        return true;
    }

    public function reviewEarningsBulk(array $earningIds, string $action, int $adminId, string $note = ''): int
    {
        $processed = 0;
        $earningIds = array_values(array_unique(array_filter(array_map('intval', $earningIds), static fn (int $id): bool => $id > 0)));
        $this->assertReviewerCanProcessEarnings($earningIds, $adminId);
        foreach ($earningIds as $earningId) {
            if ($this->reviewEarning($earningId, $action, $adminId, $note, true)) {
                $processed++;
            }
        }
        BonusOfferService::flushQueuedTouchesFailSoft([
            'workflow' => 'bulk_reward_review',
            'action' => $action,
        ]);
        return $processed;
    }

    public function purgeOldEventData(): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $days = max(7, (int)Setting::get('rewards_fraud_event_retention_days', '30'));
        $trimMb = max(64, (int)Setting::get('rewards_fraud_trim_mb', '1024'));

        $deleteEvents = $db->prepare("
            DELETE FROM download_session_events
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            LIMIT " . self::CLEANUP_BATCH_SIZE);
        $deleteEvents->execute([$days]);

        $deleteSessions = $db->prepare("
            DELETE FROM download_sessions
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'aborted', 'expired')
            LIMIT " . self::CLEANUP_BATCH_SIZE);
        $deleteSessions->execute([$days]);

        $deleteNonces = $db->prepare("
            DELETE FROM remote_reward_event_nonces
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
        ");
        $deleteNonces->execute();

        $trimmedForSize = 0;
        $tableSizeMb = $this->getApproximateFraudLogSizeMb();
        if ($tableSizeMb > $trimMb) {
            $trimStmt = $db->exec("
                DELETE FROM download_session_events
                WHERE id IN (
                    SELECT id FROM (
                        SELECT dse.id
                        FROM download_session_events dse
                        INNER JOIN download_sessions ds ON ds.id = dse.session_id
                        WHERE ds.status IN ('completed', 'aborted', 'expired')
                        ORDER BY dse.created_at ASC
                        LIMIT " . self::CLEANUP_BATCH_SIZE . "
                    ) trim_batch
                )
            ");
            $trimmedForSize = is_int($trimStmt) ? $trimStmt : 0;
        }

        return [
            'events_deleted' => $deleteEvents->rowCount(),
            'sessions_deleted' => $deleteSessions->rowCount(),
            'nonces_deleted' => $deleteNonces->rowCount(),
            'trimmed_for_size' => $trimmedForSize,
            'fraud_log_size_mb' => $this->getApproximateFraudLogSizeMb(),
        ];
    }

    public function getCloudflareHealth(): array
    {
        $db = Database::getInstance()->getConnection();
        return [
            'trust_cloudflare' => Setting::get('trust_cloudflare', '0') === '1',
            'trusted_proxy_count' => (int)$db->query("SELECT COUNT(*) FROM trusted_proxies WHERE is_active = 1")->fetchColumn(),
            'cf_header_seen' => !empty($_SERVER['HTTP_CF_CONNECTING_IP']),
            'real_ip_source' => SecurityService::getClientIp(),
        ];
    }

    public function getOverview(): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $overview = [
            'held_earnings' => 0.0,
            'flagged_earnings' => 0.0,
            'cleared_today' => 0.0,
            'reversed_today' => 0.0,
            'high_risk_uploaders' => 0,
            'review_queue' => 0,
            'likely_safe_queue_amount' => 0.0,
            'likely_fraud_queue_amount' => 0.0,
            'auto_action_candidates' => 0,
            'blocked_uploaders' => 0,
        ];

        $stmt = $db->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'held' THEN amount ELSE 0 END), 0) AS held_earnings,
                COALESCE(SUM(CASE WHEN status = 'flagged_review' THEN amount ELSE 0 END), 0) AS flagged_earnings,
                COALESCE(SUM(CASE WHEN status = 'cleared' AND DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS cleared_today,
                COALESCE(SUM(CASE WHEN status = 'reversed' AND DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS reversed_today
            FROM earnings
        ");
        $row = $stmt->fetch() ?: [];
        foreach ($overview as $key => $value) {
            if (isset($row[$key])) {
                $overview[$key] = is_numeric($row[$key]) ? (float)$row[$key] : $row[$key];
            }
        }

        $overview['high_risk_uploaders'] = (int)$db->query("SELECT COUNT(*) FROM fraud_account_scores WHERE risk_score >= 50")->fetchColumn();
        $overview['review_queue'] = (int)$db->query("SELECT COUNT(*) FROM earnings WHERE status IN ('held', 'flagged_review')")->fetchColumn();
        $overview['blocked_uploaders'] = (int)$db->query("SELECT COUNT(*) FROM fraud_account_controls WHERE trust_tier = 'blocked'")->fetchColumn();

        $reviewThreshold = max(0, (int)Setting::get('rewards_review_threshold', '25'));
        $autoReverseThreshold = max($reviewThreshold + 1, (int)Setting::get('rewards_auto_reverse_threshold', '85'));
        $autoClearLowRisk = Setting::get('rewards_auto_clear_low_risk', '0') === '1';
        $autoReverseHighRisk = Setting::get('rewards_auto_reverse_high_risk', '1') === '1';
        $queueStmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE
                    WHEN e.status IN ('held', 'flagged_review')
                     AND ?
                     AND e.risk_score < ?
                     AND COALESCE(fac.trust_tier, 'normal') IN ('trusted', 'normal')
                    THEN e.amount ELSE 0 END), 0) AS likely_safe_queue_amount,
                COALESCE(SUM(CASE
                    WHEN e.status IN ('held', 'flagged_review')
                      AND (
                        COALESCE(fac.trust_tier, 'normal') = 'blocked'
                        OR (
                            ?
                            AND (
                                e.risk_score >= ?
                                OR COALESCE(fac.trust_tier, 'normal') = 'restricted'
                            )
                        )
                      )
                    THEN e.amount ELSE 0 END), 0) AS likely_fraud_queue_amount,
                COALESCE(SUM(CASE
                    WHEN e.status IN ('held', 'flagged_review')
                      AND (
                        (? AND e.risk_score < ? AND COALESCE(fac.trust_tier, 'normal') IN ('trusted', 'normal'))
                        OR COALESCE(fac.trust_tier, 'normal') = 'blocked'
                        OR (
                            ?
                            AND (
                                e.risk_score >= ?
                                OR COALESCE(fac.trust_tier, 'normal') = 'restricted'
                            )
                        )
                      )
                    THEN 1 ELSE 0 END), 0) AS auto_action_candidates
            FROM earnings e
            LEFT JOIN fraud_account_controls fac ON fac.user_id = e.user_id
        ");
        $queueStmt->execute([
            $autoClearLowRisk ? 1 : 0,
            $reviewThreshold,
            $autoReverseHighRisk ? 1 : 0,
            $autoReverseThreshold,
            $autoClearLowRisk ? 1 : 0,
            $reviewThreshold,
            $autoReverseHighRisk ? 1 : 0,
            $autoReverseThreshold,
        ]);
        $queueRow = $queueStmt->fetch() ?: [];
        foreach (['likely_safe_queue_amount', 'likely_fraud_queue_amount'] as $moneyKey) {
            if (isset($queueRow[$moneyKey])) {
                $overview[$moneyKey] = (float)$queueRow[$moneyKey];
            }
        }
        if (isset($queueRow['auto_action_candidates'])) {
            $overview['auto_action_candidates'] = (int)$queueRow['auto_action_candidates'];
        }

        return $overview;
    }

    public function getTrustTierOptions(): array
    {
        return [
            'trusted' => 'Trusted',
            'normal' => 'Normal',
            'watched' => 'Watched',
            'restricted' => 'Restricted',
            'blocked' => 'Blocked from rewards',
        ];
    }

    public function getUploaderTrustControls(array $userIds = []): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        if (empty($userIds)) {
            $rows = $db->query("SELECT * FROM fraud_account_controls")->fetchAll() ?: [];
        } else {
            $userIds = array_values(array_filter(array_unique(array_map('intval', $userIds)), static fn (int $id): bool => $id > 0));
            if (empty($userIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $db->prepare("SELECT * FROM fraud_account_controls WHERE user_id IN ({$placeholders})");
            $stmt->execute($userIds);
            $rows = $stmt->fetchAll() ?: [];
        }

        $controls = [];
        foreach ($rows as $row) {
            $controls[(int)$row['user_id']] = $row;
        }
        return $controls;
    }

    public function saveUploaderTrustTier(int $userId, string $tier, int $adminId, string $note = ''): bool
    {
        $this->ensureSchema();
        $userId = max(0, $userId);
        $tier = $this->normalizeTrustTier($tier);
        if ($userId <= 0 || $tier === '') {
            return false;
        }

        ReviewIntegrityService::assertNotSelfTrustTierChange($adminId, $userId);

        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            INSERT INTO fraud_account_controls (user_id, trust_tier, review_note, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                trust_tier = VALUES(trust_tier),
                review_note = VALUES(review_note),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $ok = $stmt->execute([$userId, $tier, $note !== '' ? $note : null, $adminId > 0 ? $adminId : null]);
        if ($ok) {
            $this->trustTierCache[$userId] = $tier;
        }
        return $ok;
    }

    private function assertReviewerCanProcessEarnings(array $earningIds, int $adminId): void
    {
        $adminId = max(0, $adminId);
        if ($adminId <= 0 || $earningIds === []) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $placeholders = implode(',', array_fill(0, count($earningIds), '?'));
        $stmt = $db->prepare("SELECT DISTINCT user_id FROM earnings WHERE id IN ({$placeholders})");
        $stmt->execute($earningIds);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $ownerUserId) {
            ReviewIntegrityService::assertNotSelfRewardReview($adminId, (int)$ownerUserId);
        }
    }

    public function getReviewQueuePage(array $filters, int $page = 1, int $perPage = 50): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();

        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params, $meta] = $this->buildReviewQueueConstraints($filters);
        $reviewThreshold = (int)$meta['review_threshold'];
        $flagThreshold = (int)$meta['flag_threshold'];

        $uploaderNameQuery = trim((string)($filters['uploader_name'] ?? ''));
        $fileNameQuery = trim((string)($filters['file_name'] ?? ''));
        $needsDecryptedNameSearch = $uploaderNameQuery !== '' || $fileNameQuery !== '';

        $sort = trim((string)($filters['sort'] ?? 'risk_desc'));
        $orderBy = match ($sort) {
            'newest' => 'e.created_at DESC',
            'oldest' => 'e.created_at ASC',
            'amount_desc' => 'e.amount DESC, e.created_at DESC',
            'amount_asc' => 'e.amount ASC, e.created_at ASC',
            default => 'e.risk_score DESC, e.created_at DESC',
        };

        $countStmt = $db->prepare("SELECT COUNT(*) FROM earnings e WHERE {$whereSql}");
        $countStmt->execute($params);
        $sqlTotal = (int)$countStmt->fetchColumn();
        $total = $sqlTotal;
        $rows = [];
        $nameSearchCapped = false;
        $nameSearchCap = self::DECRYPT_SEARCH_SCAN_LIMIT;

        if ($needsDecryptedNameSearch) {
            $candidateLimit = min($sqlTotal, self::DECRYPT_SEARCH_SCAN_LIMIT);
            $candidateRows = $this->fetchReviewQueueRows($whereSql, $params, $orderBy, $candidateLimit, 0);
            $filteredRows = [];
            foreach ($candidateRows as $row) {
                $row = $this->decodeReviewQueueRow($row);
                if (!$this->matchesDecryptedNameFilters($row, $uploaderNameQuery, $fileNameQuery)) {
                    continue;
                }
                $filteredRows[] = $row;
            }

            $nameSearchCapped = $sqlTotal > self::DECRYPT_SEARCH_SCAN_LIMIT;
            $total = count($filteredRows);
            $rows = array_slice($filteredRows, $offset, $perPage);
        } else {
            $rows = $this->fetchReviewQueueRows($whereSql, $params, $orderBy, $perPage, $offset);
            foreach ($rows as &$row) {
                $row = $this->decodeReviewQueueRow($row);
            }
            unset($row);
        }

        return [
            'items' => $rows,
            'total' => $total,
            'sql_total' => $sqlTotal,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'review_threshold' => $reviewThreshold,
            'flag_threshold' => $flagThreshold,
            'name_search_active' => $needsDecryptedNameSearch,
            'name_search_capped' => $nameSearchCapped,
            'name_search_cap' => $nameSearchCap,
        ];
    }

    private function buildReviewQueueConstraints(array $filters): array
    {
        $reviewThreshold = max(0, (int)Setting::get('rewards_review_threshold', '25'));
        $flagThreshold = max($reviewThreshold + 1, (int)Setting::get('rewards_flag_threshold', '50'));

        $where = ["e.status IN ('held', 'flagged_review')"];
        $params = [];

        $status = trim((string)($filters['status'] ?? ''));
        if (in_array($status, ['held', 'flagged_review'], true)) {
            $where[] = "e.status = ?";
            $params[] = $status;
        }

        $riskBand = trim((string)($filters['risk_band'] ?? ''));
        if ($riskBand === 'high') {
            $where[] = 'e.risk_score >= ?';
            $params[] = $flagThreshold;
        } elseif ($riskBand === 'medium') {
            $where[] = 'e.risk_score >= ? AND e.risk_score < ?';
            $params[] = $reviewThreshold;
            $params[] = $flagThreshold;
        } elseif ($riskBand === 'low') {
            $where[] = 'e.risk_score < ?';
            $params[] = $reviewThreshold;
        }

        $countryCode = strtoupper(trim((string)($filters['country_code'] ?? '')));
        if ($countryCode !== '') {
            $where[] = 'e.country_code = ?';
            $params[] = substr($countryCode, 0, 2);
        }

        $networkType = trim((string)($filters['network_type'] ?? ''));
        if ($networkType !== '') {
            $where[] = 'e.network_type = ?';
            $params[] = $networkType;
        }

        $uploaderId = (int)($filters['uploader_id'] ?? 0);
        if ($uploaderId > 0) {
            $where[] = 'e.user_id = ?';
            $params[] = $uploaderId;
        }

        $fileId = (int)($filters['file_id'] ?? 0);
        if ($fileId > 0) {
            $where[] = 'e.file_id = ?';
            $params[] = $fileId;
        }

        $query = trim((string)($filters['query'] ?? ''));
        if ($query !== '') {
            if (ctype_digit($query)) {
                $where[] = '(e.id = ? OR e.user_id = ? OR e.file_id = ? OR e.session_id = ?)';
                $params[] = (int)$query;
                $params[] = (int)$query;
                $params[] = (int)$query;
                $params[] = (int)$query;
            } else {
                $like = '%' . mb_strtolower($query) . '%';
                $where[] = '(LOWER(COALESCE(e.review_note, "")) LIKE ? OR LOWER(COALESCE(e.asn, "")) LIKE ? OR LOWER(COALESCE(e.network_type, "")) LIKE ? OR LOWER(COALESCE(CAST(e.risk_reasons_json AS CHAR), "")) LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        return [
            implode(' AND ', $where),
            $params,
            [
                'review_threshold' => $reviewThreshold,
                'flag_threshold' => $flagThreshold,
                'auto_reverse_threshold' => max($flagThreshold, (int)Setting::get('rewards_auto_reverse_threshold', '85')),
            ],
        ];
    }

    private function fetchReviewQueueRows(string $whereSql, array $params, string $orderBy, int $limit, int $offset): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT e.*, u.username, f.filename
            FROM earnings e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN files f ON f.id = e.file_id
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param, is_int($param) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue($bindIndex++, max(0, $limit), \PDO::PARAM_INT);
        $stmt->bindValue($bindIndex, max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    private function fetchClusterRows(string $sql, array $params, int $limit, callable $mapper): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param, is_int($param) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue($bindIndex, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row = $mapper($row);
        }
        unset($row);
        return $rows;
    }

    private function decodeReviewQueueRow(array $row): array
    {
        if (!empty($row['username']) && str_starts_with((string)$row['username'], 'ENC:')) {
            $row['username'] = EncryptionService::decrypt((string)$row['username']);
        }
        if (!empty($row['filename']) && str_starts_with((string)$row['filename'], 'ENC:')) {
            $row['filename'] = EncryptionService::decrypt((string)$row['filename']);
        }
        $row['risk_reasons'] = json_decode((string)($row['risk_reasons_json'] ?? '[]'), true) ?: [];
        return $row;
    }

    private function buildQueueRecommendation(array $row, ?array $trustControl, ?array $uploaderStats, ?array $fileStats, ?array $signalCounts, array $referrers): array
    {
        $tier = $this->normalizeTrustTier((string)($trustControl['trust_tier'] ?? 'normal'));
        $riskScore = (int)($row['risk_score'] ?? 0);
        $reviewThreshold = max(0, (int)Setting::get('rewards_review_threshold', '25'));
        $autoReverseThreshold = max($reviewThreshold + 1, (int)Setting::get('rewards_auto_reverse_threshold', '85'));
        $sameCookie = (int)($signalCounts['same_cookie'] ?? 0);
        $sameIp = (int)($signalCounts['same_ip'] ?? 0);
        $sameUa = (int)($signalCounts['same_ua'] ?? 0);
        $heldReferrers = 0;
        $clearedReferrers = 0;
        foreach ($referrers as $referrer) {
            $heldReferrers += (int)($referrer['held_count'] ?? 0);
            $clearedReferrers += (int)($referrer['cleared_count'] ?? 0);
        }

        if ($tier === 'blocked') {
            return ['tone' => 'danger', 'label' => 'Auto-reverse candidate', 'detail' => 'Uploader is blocked from rewards, so this should not sit in the human queue long.'];
        }
        if ($riskScore >= $autoReverseThreshold || $tier === 'restricted') {
            return ['tone' => 'danger', 'label' => 'Likely abuse', 'detail' => 'Risk is already in the hard-abuse range or the uploader is operating under a restricted trust tier.'];
        }
        if ($sameCookie >= 3 || $sameIp >= 4 || $sameUa >= 5) {
            return ['tone' => 'warning', 'label' => 'Investigate pattern', 'detail' => 'Recent visitor/browser clustering is repeating fast enough to merit a pattern check before clearing.'];
        }
        if ($heldReferrers > max(0, $clearedReferrers * 2) && $heldReferrers >= 3) {
            return ['tone' => 'warning', 'label' => 'Investigate referrer funnel', 'detail' => 'Recent referring pages are producing more held traffic than cleared traffic for this file.'];
        }
        if ($riskScore < $reviewThreshold && in_array($tier, ['trusted', 'normal'], true) && (int)($uploaderStats['cleared_count'] ?? 0) >= ((int)($uploaderStats['flagged_count'] ?? 0) + (int)($uploaderStats['held_count'] ?? 0))) {
            return ['tone' => 'success', 'label' => 'Good auto-clear candidate', 'detail' => 'Low risk plus a stronger cleared history than held or flagged history makes this a good fit for auto-clear or a quick staff approval.'];
        }
        if ($fileStats && (int)($fileStats['reversed_count'] ?? 0) >= 3) {
            return ['tone' => 'warning', 'label' => 'Investigate file', 'detail' => 'This file already has a recent reversal pattern, so even ordinary-looking rows deserve a second look.'];
        }

        return ['tone' => 'secondary', 'label' => 'Needs review', 'detail' => 'There is not enough confidence to auto-clear or auto-reverse this one yet.'];
    }

    private function buildClusterRecommendation(array $cluster, array $meta): array
    {
        $maxRisk = (int)round((float)($cluster['max_risk'] ?? 0));
        $avgRisk = (int)round((float)($cluster['avg_risk'] ?? 0));
        $queueCount = (int)($cluster['queue_count'] ?? 0);
        $totalAmount = (float)($cluster['total_amount'] ?? 0);
        $autoReverseThreshold = (int)($meta['auto_reverse_threshold'] ?? 85);
        $reviewThreshold = (int)($meta['review_threshold'] ?? 25);

        if ($maxRisk >= $autoReverseThreshold || $queueCount >= 25 || $totalAmount >= 5) {
            return ['tone' => 'danger', 'label' => 'Investigate now', 'detail' => 'This cluster is large enough or risky enough that it should be treated as an active queue driver.'];
        }
        if ($avgRisk < $reviewThreshold && $queueCount <= 5) {
            return ['tone' => 'success', 'label' => 'Good auto-clear candidate', 'detail' => 'This is a smaller low-risk pattern, so it is a better fit for automation or a quick cluster clear than deep manual triage.'];
        }
        return ['tone' => 'warning', 'label' => 'Review as a group', 'detail' => 'This pattern is not extreme, but it is big enough that you should review the cluster instead of working child rows one by one.'];
    }

    private function buildSessionSignalCounts(array $session): array
    {
        $sessionId = (int)($session['id'] ?? 0);
        if ($sessionId <= 0) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN ? <> '' AND visitor_cookie_hash = ? THEN 1 ELSE 0 END) AS same_cookie,
                SUM(CASE WHEN ? <> '' AND ip_hash = ? THEN 1 ELSE 0 END) AS same_ip,
                SUM(CASE WHEN ? <> '' AND ua_hash = ? THEN 1 ELSE 0 END) AS same_ua
            FROM download_sessions
            WHERE uploader_user_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([
            (string)($session['visitor_cookie_hash'] ?? ''),
            (string)($session['visitor_cookie_hash'] ?? ''),
            (string)($session['ip_hash'] ?? ''),
            (string)($session['ip_hash'] ?? ''),
            (string)($session['ua_hash'] ?? ''),
            (string)($session['ua_hash'] ?? ''),
            (int)($session['uploader_user_id'] ?? 0),
        ]);
        $row = $stmt->fetch() ?: [];

        return [
            'same_cookie' => max(0, ((int)($row['same_cookie'] ?? 0)) - (!empty($session['visitor_cookie_hash']) ? 1 : 0)),
            'same_ip' => max(0, ((int)($row['same_ip'] ?? 0)) - (!empty($session['ip_hash']) ? 1 : 0)),
            'same_ua' => max(0, ((int)($row['same_ua'] ?? 0)) - (!empty($session['ua_hash']) ? 1 : 0)),
        ];
    }

    private function matchesDecryptedNameFilters(array $row, string $uploaderNameQuery, string $fileNameQuery): bool
    {
        if ($uploaderNameQuery !== '') {
            $username = mb_strtolower(trim((string)($row['username'] ?? '')));
            if ($username === '' || !str_contains($username, mb_strtolower($uploaderNameQuery))) {
                return false;
            }
        }

        if ($fileNameQuery !== '') {
            $filename = mb_strtolower(trim((string)($row['filename'] ?? '')));
            if ($filename === '' || !str_contains($filename, mb_strtolower($fileNameQuery))) {
                return false;
            }
        }

        return true;
    }

    public function reviewCluster(string $clusterType, string $clusterKey, string $action, int $adminId, string $note = ''): array
    {
        $this->ensureSchema();
        $clusterType = trim($clusterType);
        $clusterKey = trim($clusterKey);
        if ($clusterType === '' || $clusterKey === '' || !in_array($action, ['clear', 'hold', 'reverse', 'recommended'], true)) {
            return ['processed' => 0, 'matched' => 0];
        }

        $db = Database::getInstance()->getConnection();
        $sql = '';
        $params = [];
        if ($clusterType === 'uploader' && ctype_digit($clusterKey)) {
            $sql = "SELECT id FROM earnings WHERE status IN ('held', 'flagged_review') AND user_id = ? ORDER BY risk_score DESC, created_at ASC LIMIT " . self::CLUSTER_REVIEW_BATCH_SIZE;
            $params[] = (int)$clusterKey;
        } elseif ($clusterType === 'file' && ctype_digit($clusterKey)) {
            $sql = "SELECT id FROM earnings WHERE status IN ('held', 'flagged_review') AND file_id = ? ORDER BY risk_score DESC, created_at ASC LIMIT " . self::CLUSTER_REVIEW_BATCH_SIZE;
            $params[] = (int)$clusterKey;
        } elseif ($clusterType === 'referrer') {
            $sql = "
                SELECT e.id
                FROM earnings e
                INNER JOIN download_sessions ds ON ds.id = e.session_id
                WHERE e.status IN ('held', 'flagged_review')
                  AND ds.download_page_referrer_url = ?
                ORDER BY e.risk_score DESC, e.created_at ASC
                LIMIT " . self::CLUSTER_REVIEW_BATCH_SIZE;
            $params[] = $clusterKey;
        } elseif ($clusterType === 'network') {
            [$asn, $countryCode, $networkType] = array_pad(explode('|', $clusterKey, 3), 3, '');
            $sql = "
                SELECT id
                FROM earnings
                WHERE status IN ('held', 'flagged_review')
                  AND COALESCE(asn, '') = ?
                  AND COALESCE(country_code, '') = ?
                  AND COALESCE(network_type, '') = ?
                ORDER BY risk_score DESC, created_at ASC
                LIMIT " . self::CLUSTER_REVIEW_BATCH_SIZE;
            $params = [$asn, $countryCode, $networkType];
        }

        if ($sql === '') {
            return ['processed' => 0, 'matched' => 0];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $earningIds = array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
        if (empty($earningIds)) {
            return ['processed' => 0, 'matched' => 0];
        }

        if ($action === 'recommended') {
            $action = $this->resolveRecommendedActionForCluster($clusterType, $clusterKey, $earningIds);
        }

        $processed = $this->reviewEarningsBulk($earningIds, $action, $adminId, $note);
        return ['processed' => $processed, 'matched' => count($earningIds)];
    }

    private function resolveRecommendedActionForEarning(array $earning): string
    {
        $earning = $this->decodeReviewQueueRow($earning);
        $context = $this->buildReviewCaseContext([$earning]);
        $recommendation = $context['recommendations'][(int)($earning['id'] ?? 0)] ?? null;
        return $this->mapRecommendationToAction($recommendation);
    }

    private function resolveRecommendedActionForCluster(string $clusterType, string $clusterKey, array $earningIds): string
    {
        if ($earningIds === []) {
            return 'hold';
        }

        $db = Database::getInstance()->getConnection();
        $placeholders = implode(',', array_fill(0, count($earningIds), '?'));
        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS queue_count,
                COALESCE(SUM(amount), 0) AS total_amount,
                COALESCE(AVG(risk_score), 0) AS avg_risk,
                COALESCE(MAX(risk_score), 0) AS max_risk
            FROM earnings
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute($earningIds);
        $cluster = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $cluster['queue_count'] = (int)($cluster['queue_count'] ?? 0);
        $cluster['total_amount'] = (float)($cluster['total_amount'] ?? 0);
        $cluster['avg_risk'] = (float)($cluster['avg_risk'] ?? 0);
        $cluster['max_risk'] = (float)($cluster['max_risk'] ?? 0);
        $cluster['cluster_type'] = $clusterType;
        $cluster['cluster_key'] = $clusterKey;

        $recommendation = $this->buildClusterRecommendation($cluster, [
            'review_threshold' => max(0, (int)Setting::get('rewards_review_threshold', '25')),
            'auto_reverse_threshold' => max(1, (int)Setting::get('rewards_auto_reverse_threshold', '85')),
        ]);

        return $this->mapRecommendationToAction($recommendation);
    }

    private function mapRecommendationToAction(?array $recommendation): string
    {
        $tone = strtolower(trim((string)($recommendation['tone'] ?? 'secondary')));
        return match ($tone) {
            'success' => 'clear',
            'danger' => 'reverse',
            default => 'hold',
        };
    }

    public function getReviewFilterOptions(): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();

        $countries = $db->query("
            SELECT country_code, COUNT(*) AS total
            FROM earnings
            WHERE status IN ('held', 'flagged_review') AND country_code IS NOT NULL AND country_code <> ''
            GROUP BY country_code
            ORDER BY total DESC, country_code ASC
            LIMIT 25
        ")->fetchAll() ?: [];

        $networks = $db->query("
            SELECT network_type, COUNT(*) AS total
            FROM earnings
            WHERE status IN ('held', 'flagged_review') AND network_type IS NOT NULL AND network_type <> ''
            GROUP BY network_type
            ORDER BY total DESC, network_type ASC
            LIMIT 25
        ")->fetchAll() ?: [];

        return [
            'countries' => $countries,
            'networks' => $networks,
        ];
    }

    public function getReviewQueue(int $limit = 100): array
    {
        return $this->getReviewQueuePage([], 1, $limit)['items'];
    }

    public function getRecentRewardActivity(int $days = 14, int $limit = 20): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $days = max(1, min(90, $days));
        $limit = max(5, min(100, $limit));

        $stmt = $db->prepare("
            SELECT e.*, u.username, f.filename
            FROM earnings e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN files f ON f.id = e.file_id
            WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND e.status IN ('held', 'flagged_review', 'cleared', 'reversed', 'paid', 'cancelled')
            ORDER BY e.created_at DESC, e.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $days, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row = $this->decodeReviewQueueRow($row);
        }
        unset($row);

        return $rows;
    }

    public function buildReviewCaseContext(array $queueRows): array
    {
        $this->ensureSchema();
        if (empty($queueRows)) {
            return [
                'uploader_stats' => [],
                'file_stats' => [],
                'session_details' => [],
                'signal_counts' => [],
                'downloader_meta' => [],
                'recent_referrers' => [],
                'trust_controls' => [],
                'recommendations' => [],
            ];
        }

        $db = Database::getInstance()->getConnection();
        $userIds = [];
        $fileIds = [];
        $sessionIds = [];
        foreach ($queueRows as $row) {
            $userIds[] = (int)($row['user_id'] ?? 0);
            $fileIds[] = (int)($row['file_id'] ?? 0);
            $sessionIds[] = (int)($row['session_id'] ?? 0);
        }

        $userIds = array_values(array_filter(array_unique($userIds), static fn (int $id): bool => $id > 0));
        $fileIds = array_values(array_filter(array_unique($fileIds), static fn (int $id): bool => $id > 0));
        $sessionIds = array_values(array_filter(array_unique($sessionIds), static fn (int $id): bool => $id > 0));

        $uploaderStats = [];
        if (!empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $db->prepare("
                SELECT user_id,
                       COUNT(*) AS total_rows,
                       SUM(CASE WHEN status = 'held' THEN 1 ELSE 0 END) AS held_count,
                       SUM(CASE WHEN status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count,
                       SUM(CASE WHEN status = 'cleared' THEN 1 ELSE 0 END) AS cleared_count,
                       SUM(CASE WHEN status = 'reversed' THEN 1 ELSE 0 END) AS reversed_count,
                       SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                       SUM(amount) AS total_amount,
                       COUNT(DISTINCT file_id) AS file_count
                FROM earnings
                WHERE user_id IN ({$placeholders})
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY user_id
            ");
            $stmt->execute($userIds);
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $uploaderStats[(int)$row['user_id']] = $row;
            }
        }

        $fileStats = [];
        if (!empty($fileIds)) {
            $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
            $stmt = $db->prepare("
                SELECT file_id,
                       COUNT(*) AS total_rows,
                       SUM(CASE WHEN status = 'held' THEN 1 ELSE 0 END) AS held_count,
                       SUM(CASE WHEN status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count,
                       SUM(CASE WHEN status = 'cleared' THEN 1 ELSE 0 END) AS cleared_count,
                       SUM(CASE WHEN status = 'reversed' THEN 1 ELSE 0 END) AS reversed_count,
                       SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                       SUM(amount) AS total_amount,
                       COUNT(DISTINCT user_id) AS uploader_count
                FROM earnings
                WHERE file_id IN ({$placeholders})
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY file_id
            ");
            $stmt->execute($fileIds);
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $fileStats[(int)$row['file_id']] = $row;
            }
        }

        $sessionDetails = [];
        $downloaderIds = [];
        if (!empty($sessionIds)) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $stmt = $db->prepare("SELECT * FROM download_sessions WHERE id IN ({$placeholders})");
            $stmt->execute($sessionIds);
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $sessionId = (int)($row['id'] ?? 0);
                if ($sessionId <= 0) {
                    continue;
                }
                $sessionDetails[$sessionId] = $row;
                $downloaderId = (int)($row['downloader_user_id'] ?? 0);
                if ($downloaderId > 0) {
                    $downloaderIds[] = $downloaderId;
                }
            }
        }

        $signalCounts = [];
        foreach ($sessionDetails as $sessionId => $session) {
            $signalCounts[$sessionId] = $this->buildSessionSignalCounts($session);
        }

        $downloaderMeta = [];
        $downloaderIds = array_values(array_filter(array_unique($downloaderIds), static fn (int $id): bool => $id > 0));
        if (!empty($downloaderIds)) {
            $placeholders = implode(',', array_fill(0, count($downloaderIds), '?'));
            $stmt = $db->prepare("SELECT id, username, email_verified, created_at FROM users WHERE id IN ({$placeholders})");
            $stmt->execute($downloaderIds);
            foreach (($stmt->fetchAll() ?: []) as $row) {
                if (!empty($row['username']) && str_starts_with((string)$row['username'], 'ENC:')) {
                    $row['username'] = EncryptionService::decrypt((string)$row['username']);
                }
                $downloaderMeta[(int)$row['id']] = $row;
            }
        }

        $recentReferrers = [];
        if (!empty($fileIds)) {
            $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
            $stmt = $db->prepare("
                SELECT ds.file_id,
                       ds.download_page_referrer_url,
                       ds.download_page_referrer_host,
                       ds.download_page_referrer_internal,
                       MIN(e.created_at) AS first_seen,
                       MAX(e.created_at) AS last_seen,
                       COUNT(*) AS session_count,
                       SUM(CASE WHEN e.status = 'held' THEN 1 ELSE 0 END) AS held_count,
                       SUM(CASE WHEN e.status = 'cleared' THEN 1 ELSE 0 END) AS cleared_count,
                       SUM(CASE WHEN e.status = 'reversed' THEN 1 ELSE 0 END) AS reversed_count
                FROM earnings e
                INNER JOIN download_sessions ds ON ds.id = e.session_id
                WHERE ds.file_id IN ({$placeholders})
                  AND ds.download_page_referrer_url IS NOT NULL
                  AND ds.download_page_referrer_url <> ''
                  AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY ds.file_id, ds.download_page_referrer_url, ds.download_page_referrer_host, ds.download_page_referrer_internal
                ORDER BY last_seen DESC
            ");
            $stmt->execute($fileIds);
            foreach (($stmt->fetchAll() ?: []) as $row) {
                $fileId = (int)($row['file_id'] ?? 0);
                if ($fileId <= 0) {
                    continue;
                }
                $recentReferrers[$fileId] ??= [];
                if (count($recentReferrers[$fileId]) >= 5) {
                    continue;
                }
                $recentReferrers[$fileId][] = $row;
            }
        }

        $trustControls = $this->getUploaderTrustControls($userIds);
        $recommendations = [];
        foreach ($queueRows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId <= 0) {
                continue;
            }
            $uploaderId = (int)($row['user_id'] ?? 0);
            $sessionId = (int)($row['session_id'] ?? 0);
            $recommendations[$rowId] = $this->buildQueueRecommendation(
                $row,
                $trustControls[$uploaderId] ?? null,
                $uploaderStats[$uploaderId] ?? null,
                $fileStats[(int)($row['file_id'] ?? 0)] ?? null,
                $sessionId > 0 ? ($signalCounts[$sessionId] ?? null) : null,
                $recentReferrers[(int)($row['file_id'] ?? 0)] ?? []
            );
        }

        return [
            'uploader_stats' => $uploaderStats,
            'file_stats' => $fileStats,
            'session_details' => $sessionDetails,
            'signal_counts' => $signalCounts,
            'downloader_meta' => $downloaderMeta,
            'recent_referrers' => $recentReferrers,
            'trust_controls' => $trustControls,
            'recommendations' => $recommendations,
        ];
    }

    public function getReviewClusters(array $filters, int $limit = 8): array
    {
        $this->ensureSchema();
        [$whereSql, $params, $meta] = $this->buildReviewQueueConstraints($filters);
        $limit = max(3, min(20, $limit));
        $db = Database::getInstance()->getConnection();

        $uploaderRows = $this->fetchClusterRows("
            SELECT
                'uploader' AS cluster_type,
                CAST(e.user_id AS CHAR) AS cluster_key,
                e.user_id,
                u.username,
                COUNT(*) AS queue_count,
                SUM(e.amount) AS total_amount,
                AVG(e.risk_score) AS avg_risk,
                MAX(e.risk_score) AS max_risk,
                SUM(CASE WHEN e.status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count,
                SUM(CASE WHEN e.status = 'held' THEN 1 ELSE 0 END) AS held_count
            FROM earnings e
            LEFT JOIN users u ON u.id = e.user_id
            WHERE {$whereSql}
            GROUP BY e.user_id, u.username
            ORDER BY max_risk DESC, total_amount DESC, queue_count DESC
            LIMIT ?
        ", $params, $limit, static function (array $row): array {
            if (!empty($row['username']) && str_starts_with((string)$row['username'], 'ENC:')) {
                $row['username'] = EncryptionService::decrypt((string)$row['username']);
            }
            $row['label'] = $row['username'] ?? ('User #' . (int)($row['user_id'] ?? 0));
            return $row;
        });

        $fileRows = $this->fetchClusterRows("
            SELECT
                'file' AS cluster_type,
                CAST(e.file_id AS CHAR) AS cluster_key,
                e.file_id,
                f.filename,
                COUNT(*) AS queue_count,
                SUM(e.amount) AS total_amount,
                AVG(e.risk_score) AS avg_risk,
                MAX(e.risk_score) AS max_risk,
                SUM(CASE WHEN e.status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count,
                SUM(CASE WHEN e.status = 'held' THEN 1 ELSE 0 END) AS held_count
            FROM earnings e
            LEFT JOIN files f ON f.id = e.file_id
            WHERE {$whereSql}
            GROUP BY e.file_id, f.filename
            ORDER BY max_risk DESC, total_amount DESC, queue_count DESC
            LIMIT ?
        ", $params, $limit, static function (array $row): array {
            if (!empty($row['filename']) && str_starts_with((string)$row['filename'], 'ENC:')) {
                $row['filename'] = EncryptionService::decrypt((string)$row['filename']);
            }
            $row['label'] = $row['filename'] ?? ('File #' . (int)($row['file_id'] ?? 0));
            return $row;
        });

        $referrerRows = $this->fetchClusterRows("
            SELECT
                'referrer' AS cluster_type,
                ds.download_page_referrer_url AS cluster_key,
                ds.download_page_referrer_url,
                ds.download_page_referrer_internal,
                COUNT(*) AS queue_count,
                SUM(e.amount) AS total_amount,
                AVG(e.risk_score) AS avg_risk,
                MAX(e.risk_score) AS max_risk,
                SUM(CASE WHEN e.status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count,
                SUM(CASE WHEN e.status = 'held' THEN 1 ELSE 0 END) AS held_count
            FROM earnings e
            INNER JOIN download_sessions ds ON ds.id = e.session_id
            WHERE {$whereSql}
              AND ds.download_page_referrer_url IS NOT NULL
              AND ds.download_page_referrer_url <> ''
            GROUP BY ds.download_page_referrer_url, ds.download_page_referrer_internal
            ORDER BY max_risk DESC, total_amount DESC, queue_count DESC
            LIMIT ?
        ", $params, $limit, static function (array $row): array {
            $row['label'] = $row['download_page_referrer_url'] ?? 'Unknown referrer';
            return $row;
        });

        $networkRows = $this->fetchClusterRows("
            SELECT
                'network' AS cluster_type,
                CONCAT(COALESCE(e.asn, ''), '|', COALESCE(e.country_code, ''), '|', COALESCE(e.network_type, '')) AS cluster_key,
                e.asn,
                e.country_code,
                e.network_type,
                COUNT(*) AS queue_count,
                SUM(e.amount) AS total_amount,
                AVG(e.risk_score) AS avg_risk,
                MAX(e.risk_score) AS max_risk,
                SUM(CASE WHEN e.status = 'flagged_review' THEN 1 ELSE 0 END) AS flagged_count,
                SUM(CASE WHEN e.status = 'held' THEN 1 ELSE 0 END) AS held_count
            FROM earnings e
            WHERE {$whereSql}
            GROUP BY e.asn, e.country_code, e.network_type
            ORDER BY max_risk DESC, total_amount DESC, queue_count DESC
            LIMIT ?
        ", $params, $limit, static function (array $row): array {
            $row['label'] = trim(
                implode(' / ', array_filter([
                    $row['asn'] ?? '',
                    $row['country_code'] ?? '',
                    $row['network_type'] ?? '',
                ]))
            ) ?: 'Unknown network';
            return $row;
        });

        foreach (['uploader' => &$uploaderRows, 'file' => &$fileRows, 'referrer' => &$referrerRows, 'network' => &$networkRows] as $type => &$rows) {
            foreach ($rows as &$row) {
                $row['recommendation'] = $this->buildClusterRecommendation($row, $meta);
            }
            unset($row);
        }
        unset($rows);

        return [
            'uploader' => $uploaderRows,
            'file' => $fileRows,
            'referrer' => $referrerRows,
            'network' => $networkRows,
        ];
    }

    public function getUploaderScores(int $limit = 50): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT fas.*, u.username, u.email, fac.trust_tier, fac.review_note
            FROM fraud_account_scores fas
            JOIN users u ON u.id = fas.user_id
            LEFT JOIN fraud_account_controls fac ON fac.user_id = fas.user_id
            ORDER BY fas.risk_score DESC, fas.updated_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            if (!empty($row['username']) && str_starts_with((string)$row['username'], 'ENC:')) {
                $row['username'] = EncryptionService::decrypt($row['username']);
            }
            if (!empty($row['email']) && str_starts_with((string)$row['email'], 'ENC:')) {
                $row['email'] = EncryptionService::decrypt($row['email']);
            }
            $row['trust_tier'] = $this->normalizeTrustTier((string)($row['trust_tier'] ?? 'normal'));
        }
        return $rows;
    }

    public function getNetworkInsights(int $limit = 25): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT *
            FROM fraud_network_summaries
            ORDER BY flagged_count DESC, held_count DESC, session_count DESC, updated_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    private function normalizeStreamTelemetry(array $session, int $watchSeconds, float $watchPercent, array $meta): array
    {
        $elapsedSeconds = max(0, time() - strtotime((string)($session['created_at'] ?? 'now')));
        $maxTrustedSeconds = $elapsedSeconds + self::STREAM_MAX_CLOCK_SKEW_SECONDS;
        $state = trim((string)($meta['state'] ?? 'progress'));
        $reportedCurrentTime = max(0, (float)($meta['current_time'] ?? 0));
        $reportedDuration = max(0, (float)($meta['duration'] ?? 0));
        $previousWatchSeconds = (int)($session['watch_seconds'] ?? 0);
        $previousWatchPercent = (float)($session['watch_percent'] ?? 0);
        $lastHeartbeat = $this->getLastStreamHeartbeat((int)($session['id'] ?? 0));

        if ($state === 'complete' && $reportedDuration <= 0 && $reportedCurrentTime <= 0) {
            return [
                'accepted' => false,
                'reason' => 'missing_completion_telemetry',
                'watch_seconds' => $previousWatchSeconds,
                'watch_percent' => $previousWatchPercent,
                'current_time' => 0.0,
                'duration' => 0.0,
            ];
        }

        if (
            $lastHeartbeat !== null
            && $reportedDuration > 0
            && (float)($lastHeartbeat['duration'] ?? 0) > 0
            && abs($reportedDuration - (float)$lastHeartbeat['duration']) > self::STREAM_MAX_DURATION_DRIFT_SECONDS
        ) {
            return [
                'accepted' => false,
                'reason' => 'duration_mismatch',
                'watch_seconds' => $previousWatchSeconds,
                'watch_percent' => $previousWatchPercent,
                'current_time' => 0.0,
                'duration' => round($reportedDuration, 2),
            ];
        }

        $candidateWatchSeconds = max(0, $watchSeconds, (int)floor($reportedCurrentTime));
        if ($reportedDuration > 0) {
            $candidateWatchSeconds = min($candidateWatchSeconds, (int)ceil($reportedDuration));
        }

        $maxHeartbeatAdvance = $maxTrustedSeconds;
        if ($lastHeartbeat !== null) {
            $lastHeartbeatAt = strtotime((string)($lastHeartbeat['created_at'] ?? 'now'));
            $secondsSinceHeartbeat = max(0, time() - ($lastHeartbeatAt ?: time()));
            $maxHeartbeatAdvance = min(
                $maxTrustedSeconds,
                (int)($lastHeartbeat['watch_seconds'] ?? 0) + $secondsSinceHeartbeat + self::STREAM_MAX_CLOCK_SKEW_SECONDS
            );
        }

        $trustedWatchSeconds = min($candidateWatchSeconds, $maxHeartbeatAdvance);
        $trustedWatchSeconds = max($previousWatchSeconds, $trustedWatchSeconds);

        if ($reportedDuration > 0) {
            $trustedWatchPercent = min(100, round(($trustedWatchSeconds / max(1.0, $reportedDuration)) * 100, 2));
            $trustedCurrentTime = min($reportedCurrentTime > 0 ? $reportedCurrentTime : (float)$trustedWatchSeconds, $reportedDuration, (float)$maxTrustedSeconds);
        } else {
            $trustedWatchPercent = max($previousWatchPercent, min(100, round($watchPercent, 2)));
            $trustedCurrentTime = min($reportedCurrentTime > 0 ? $reportedCurrentTime : (float)$trustedWatchSeconds, (float)$maxTrustedSeconds);
        }

        return [
            'accepted' => true,
            'reason' => null,
            'watch_seconds' => $trustedWatchSeconds,
            'watch_percent' => $trustedWatchPercent,
            'current_time' => round($trustedCurrentTime, 2),
            'duration' => round($reportedDuration, 2),
        ];
    }

    private function getStreamHeartbeatSummary(int $sessionId): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total_events, MIN(created_at) AS first_event_at, MAX(created_at) AS last_event_at
            FROM download_session_events
            WHERE session_id = ? AND event_type = 'stream_heartbeat'
        ");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch() ?: [];

        $count = (int)($row['total_events'] ?? 0);
        $first = !empty($row['first_event_at']) ? strtotime((string)$row['first_event_at']) : null;
        $last = !empty($row['last_event_at']) ? strtotime((string)$row['last_event_at']) : null;

        return [
            'count' => $count,
            'window_seconds' => ($first !== null && $last !== null) ? max(0, $last - $first) : 0,
        ];
    }

    private function getLastStreamHeartbeat(int $sessionId): ?array
    {
        if ($sessionId <= 0) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT watch_seconds, watch_percent, event_payload, created_at
            FROM download_session_events
            WHERE session_id = ? AND event_type = 'stream_heartbeat'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch() ?: null;
        if (!$row) {
            return null;
        }

        $payload = [];
        if (!empty($row['event_payload'])) {
            $payload = json_decode((string)$row['event_payload'], true) ?: [];
        }

        return [
            'watch_seconds' => (int)($row['watch_seconds'] ?? 0),
            'watch_percent' => (float)($row['watch_percent'] ?? 0),
            'duration' => (float)($payload['duration'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function evaluateRemoteReceiptProof(array $session, array $file, string $completionState, float $percent, int $watchSeconds, float $watchPercent): array
    {
        if ($completionState !== 'complete') {
            return ['progressing', 'accepted_progress', 'progress'];
        }

        $telemetry = $this->getRemoteReceiptTelemetrySummary((int)($session['id'] ?? 0));
        if (!$telemetry['has_prior_started_or_progress'] || $telemetry['window_seconds'] < self::REMOTE_COMPLETE_MIN_TELEMETRY_WINDOW_SECONDS) {
            return ['flagged', 'flagged_complete', 'flagged'];
        }

        $rewardMode = (string)($session['reward_mode'] ?? 'download');
        if ($rewardMode === 'stream') {
            $requiredPercent = max(0, min(100, (int)Setting::get('rewards_min_video_watch_percent', '80')));
            $requiredSeconds = max(0, (int)Setting::get('rewards_min_video_watch_seconds', '30'));
            if ($watchPercent >= $requiredPercent && $watchSeconds >= $requiredSeconds) {
                return ['completed', 'verified_complete', 'verified_stream'];
            }

            return ['flagged', 'flagged_complete', 'flagged_stream'];
        }

        $requiredPercent = max(1, (int)Setting::get('rewards_verified_completion_percent', '95'));
        if ($percent >= $requiredPercent) {
            return ['completed', 'verified_complete', 'verified'];
        }

        return ['flagged', 'flagged_complete', 'flagged'];
    }

    private function getRemoteReceiptTelemetrySummary(int $sessionId): array
    {
        if ($sessionId <= 0) {
            return [
                'has_prior_started_or_progress' => false,
                'window_seconds' => 0,
            ];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT event_payload, created_at
            FROM download_session_events
            WHERE session_id = ?
              AND event_type = 'edge_receipt'
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll() ?: [];

        $firstAt = null;
        $lastAt = null;
        $hasPriorStartedOrProgress = false;

        foreach ($rows as $row) {
            $createdAt = strtotime((string)($row['created_at'] ?? ''));
            if ($createdAt !== false) {
                $firstAt ??= $createdAt;
                $lastAt = $createdAt;
            }

            $payload = json_decode((string)($row['event_payload'] ?? ''), true);
            $state = trim((string)($payload['completion_state'] ?? ''));
            if (in_array($state, ['started', 'progress'], true)) {
                $hasPriorStartedOrProgress = true;
            }
        }

        return [
            'has_prior_started_or_progress' => $hasPriorStartedOrProgress,
            'window_seconds' => ($firstAt !== null && $lastAt !== null) ? max(0, $lastAt - $firstAt) : 0,
        ];
    }

    private function calculateRiskScore(array $session, array $file, array &$reasons): int
    {
        $score = 0;
        $trustTier = $this->getUploaderTrustTier((int)($file['user_id'] ?? 0));
        if ((int)($session['downloader_user_id'] ?? 0) > 0 && (int)($session['downloader_user_id'] ?? 0) === (int)$file['user_id']) {
            $score += 100;
            $reasons[] = 'Uploader attempted to reward their own account.';
        }

        if ($this->isSignalEnabled('rewards_use_cookie_hash') && empty($session['visitor_cookie_hash'])) {
            $score += 10;
            $reasons[] = 'Visitor cookie was missing for this rewardable session.';
        }

        if ($this->isSignalEnabled('rewards_use_ua_hash') && empty($session['ua_hash'])) {
            $score += 5;
            $reasons[] = 'Browser signature was unavailable.';
        }

        if ($this->isSignalEnabled('rewards_use_asn_network') && !empty($session['country_code']) && in_array((string)$session['network_type'], ['hosting', 'proxy', 'datacenter'], true)) {
            $score += 20;
            $reasons[] = 'Country and network type combination suggests a high-risk source.';
        }

        [$score, $reasons] = $this->applyTrustTierRiskAdjustments($score, $reasons, $trustTier);

        return $score;
    }

    public function decideEarningDisposition(array $risk, int $uploaderId): array
    {
        $trustTier = $this->getUploaderTrustTier($uploaderId);
        $autoClearLowRisk = Setting::get('rewards_auto_clear_low_risk', '0') === '1';
        $autoReverseHighRisk = Setting::get('rewards_auto_reverse_high_risk', '1') === '1';
        $reviewThreshold = max(0, (int)Setting::get('rewards_review_threshold', '25'));
        $autoReverseThreshold = max($reviewThreshold + 1, (int)Setting::get('rewards_auto_reverse_threshold', '85'));
        $holdDays = max(0, (int)Setting::get('rewards_hold_days', '7'));
        $score = (int)($risk['score'] ?? 0);

        if ($trustTier === 'blocked') {
            return [
                'status' => 'reversed',
                'hold_until' => null,
                'label' => 'Auto-reversed',
                'system_note' => 'Uploader trust tier is blocked from rewards.',
            ];
        }

        if ($autoReverseHighRisk && ($score >= $autoReverseThreshold || $trustTier === 'restricted')) {
            return [
                'status' => 'reversed',
                'hold_until' => null,
                'label' => 'Auto-reversed',
                'system_note' => $trustTier === 'restricted'
                    ? 'Uploader trust tier is restricted and this reward stayed in the reversal lane.'
                    : 'Risk score reached the automatic reversal threshold.',
            ];
        }

        if ($score < $reviewThreshold && $autoClearLowRisk && in_array($trustTier, ['trusted', 'normal'], true)) {
            return [
                'status' => 'cleared',
                'hold_until' => null,
                'label' => 'Auto-cleared',
                'system_note' => 'Low-risk reward cleared automatically.',
            ];
        }

        if (($risk['level'] ?? 'low') === 'high' || $trustTier === 'restricted') {
            return [
                'status' => 'flagged_review',
                'hold_until' => null,
                'label' => 'Flagged for review',
                'system_note' => 'High-risk reward routed into the exception queue.',
            ];
        }

        return [
            'status' => 'held',
            'hold_until' => date('Y-m-d H:i:s', strtotime("+{$holdDays} days")),
            'label' => 'Held for review',
            'system_note' => 'Reward held until either an automatic clear or a human review resolves it.',
        ];
    }

    private function resolveAutomaticDisposition(int $riskScore, string $trustTier): ?string
    {
        $trustTier = $this->normalizeTrustTier($trustTier);
        $reviewThreshold = max(0, (int)Setting::get('rewards_review_threshold', '25'));
        $autoReverseThreshold = max($reviewThreshold + 1, (int)Setting::get('rewards_auto_reverse_threshold', '85'));
        $autoClearLowRisk = Setting::get('rewards_auto_clear_low_risk', '0') === '1';
        $autoReverseHighRisk = Setting::get('rewards_auto_reverse_high_risk', '1') === '1';

        if ($trustTier === 'blocked') {
            return 'reversed';
        }
        if ($autoReverseHighRisk && ($riskScore >= $autoReverseThreshold || $trustTier === 'restricted')) {
            return 'reversed';
        }
        if ($autoClearLowRisk && $riskScore < $reviewThreshold && in_array($trustTier, ['trusted', 'normal'], true)) {
            return 'cleared';
        }

        return null;
    }

    private function applyTrustTierRiskAdjustments(int $score, array $reasons, string $trustTier): array
    {
        $trustTier = $this->normalizeTrustTier($trustTier);
        if ($trustTier === 'trusted') {
            $score = max(0, $score - 10);
            $reasons[] = 'Uploader is on a trusted rewards tier, which lowers baseline review pressure.';
        } elseif ($trustTier === 'watched') {
            $score += 10;
            $reasons[] = 'Uploader is on a watched rewards tier, so suspicious signals weigh a little more heavily.';
        } elseif ($trustTier === 'restricted') {
            $score += 25;
            $reasons[] = 'Uploader is on a restricted rewards tier, so new earnings stay in a stricter lane.';
        } elseif ($trustTier === 'blocked') {
            $score += 100;
            $reasons[] = 'Uploader is blocked from earning creator rewards until a staff review changes that trust tier.';
        }

        return [$score, array_values(array_unique($reasons))];
    }

    private function recordSessionEventById(int $sessionId, string $eventType, array $payload = []): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO download_session_events (session_id, event_type, server_id, event_public_id, nonce, signature_valid, bytes_sent, watch_seconds, watch_percent, source_ip_hash, event_payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sessionId,
            $eventType,
            $payload['server_id'] ?? null,
            $payload['event_public_id'] ?? null,
            $payload['nonce'] ?? null,
            isset($payload['signature_valid']) ? (int)$payload['signature_valid'] : 0,
            (int)($payload['bytes_sent'] ?? 0),
            (int)($payload['watch_seconds'] ?? 0),
            (float)($payload['watch_percent'] ?? 0),
            $payload['source_ip_hash'] ?? null,
            isset($payload['event_payload']) ? json_encode($payload['event_payload'], JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    private function hashValue(string $value): string
    {
        $secret = SecurityService::getSecureAppKey();
        if ($secret === null) {
            throw new \RuntimeException('Rewards fraud protections require a rotated application key.');
        }

        return hash_hmac('sha256', $value, $secret);
    }

    private function resolveDownloadPageReferrer(int $fileId): ?array
    {
        $stored = $this->loadStoredDownloadPageReferrer($fileId);
        if ($stored !== null && empty($stored['internal'])) {
            return $stored;
        }

        $current = $this->normalizeReferrer((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($current !== null && empty($current['internal'])) {
            return $current;
        }

        return $stored ?? $current;
    }

    private function loadStoredDownloadPageReferrer(int $fileId): ?array
    {
        $entries = $_SESSION['fraud_download_page_referrers'] ?? null;
        if (!is_array($entries) || $fileId <= 0 || empty($entries[$fileId]) || !is_array($entries[$fileId])) {
            return null;
        }

        $entry = $entries[$fileId];
        $capturedAt = (int)($entry['captured_at'] ?? 0);
        if ($capturedAt > 0 && $capturedAt < (time() - 86400)) {
            unset($_SESSION['fraud_download_page_referrers'][$fileId]);
            return null;
        }

        return [
            'url' => (string)($entry['url'] ?? ''),
            'host' => (string)($entry['host'] ?? ''),
            'internal' => !empty($entry['internal']),
        ];
    }

    private function normalizeReferrer(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $parts = parse_url($raw);
        if ($parts === false) {
            return null;
        }

        $base = parse_url(SeoService::trustedBaseUrl());
        $baseScheme = strtolower((string)($base['scheme'] ?? 'https'));
        $baseHost = strtolower((string)($base['host'] ?? ($_SERVER['HTTP_HOST'] ?? '')));

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        if ($host === '' && $path !== '' && str_starts_with($path, '/')) {
            $host = $baseHost;
            $scheme = $baseScheme;
        }

        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
            foreach (array_keys($queryParams) as $key) {
                $lower = strtolower((string)$key);
                if (
                    str_starts_with($lower, 'utm_') ||
                    in_array($lower, ['fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'], true)
                ) {
                    unset($queryParams[$key]);
                }
            }
            if (!empty($queryParams)) {
                $query = '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $normalizedPath = $path !== '' ? $path : '/';
        $url = substr($scheme . '://' . $host . $normalizedPath . $query, 0, 2048);

        return [
            'url' => $url,
            'host' => substr($host, 0, 255),
            'internal' => $baseHost !== '' && $host === $baseHost,
        ];
    }

    private function loadRemoteServer(int $serverId): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ? LIMIT 1");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch() ?: null;
        if (!$server) {
            return null;
        }

        $server['_config'] = $this->normalizeRemoteServerConfig($server['config'] ?? []);
        return $server;
    }

    private function normalizeRemoteServerConfig(mixed $rawConfig): array
    {
        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        if (!is_string($rawConfig) || $rawConfig === '') {
            return [];
        }

        $decoded = json_decode($rawConfig, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        try {
            $decrypted = EncryptionService::decrypt($rawConfig);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_string($decrypted) || $decrypted === '') {
            return [];
        }

        $decoded = json_decode($decrypted, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildRemoteReceiptSignature(array $payload, array $server): string
    {
        $config = $server['_config'] ?? [];
        $secret = trim((string)($config['reward_callback_secret'] ?? ''));
        $parts = [
            'bytes_sent' => (string)max(0, (int)($payload['bytes_sent'] ?? 0)),
            'client_ip' => SecurityService::normalizeIp((string)($payload['client_ip'] ?? '')),
            'completion_state' => trim((string)($payload['completion_state'] ?? '')),
            'event_id' => trim((string)($payload['event_id'] ?? '')),
            'nonce' => trim((string)($payload['nonce'] ?? '')),
            'server_id' => (string)(int)($payload['server_id'] ?? 0),
            'session_id' => trim((string)($payload['session_id'] ?? '')),
            'timestamp' => (string)(int)($payload['timestamp'] ?? 0),
            'user_id' => (string)(int)($payload['user_id'] ?? 0),
            'watch_percent' => number_format((float)($payload['watch_percent'] ?? 0), 2, '.', ''),
            'watch_seconds' => (string)max(0, (int)($payload['watch_seconds'] ?? 0)),
        ];

        ksort($parts);
        return hash_hmac('sha256', http_build_query($parts, '', '&', PHP_QUERY_RFC3986), $secret);
    }

    private function hasRemoteCallbackSecret(array $server): bool
    {
        return trim((string)(($server['_config']['reward_callback_secret'] ?? ''))) !== '';
    }

    private function validateSourceIp(string $sourceIp, array $server): bool
    {
        $sourceIp = SecurityService::normalizeIp($sourceIp);
        $allowlist = trim((string)(($server['_config']['reward_callback_ips'] ?? '')));

        $allowed = array_filter(array_map('trim', explode(',', $allowlist)));
        foreach ($allowed as $entry) {
            if (SecurityService::ipInCidr($sourceIp, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function hasRemoteSourceIpAllowlist(array $server): bool
    {
        return trim((string)(($server['_config']['reward_callback_ips'] ?? ''))) !== '';
    }

    private function claimRemoteNonce(string $nonce, ?\PDO $db = null): bool
    {
        $db = $db ?: Database::getInstance()->getConnection();
        try {
            $stmt = $db->prepare("INSERT INTO remote_reward_event_nonces (nonce) VALUES (?)");
            $stmt->execute([$nonce]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function remoteEventExists(string $eventId, ?\PDO $db = null): bool
    {
        $db = $db ?: Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT 1 FROM download_session_events WHERE event_public_id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        return (bool)$stmt->fetchColumn();
    }

    private function remoteServerMatchesFile(int $serverId, array $file): bool
    {
        return (int)($file['file_server_id'] ?? 0) === $serverId;
    }

    private function getApproximateFraudLogSizeMb(): float
    {
        try {
            $db = Database::getInstance()->getConnection();
            $schemaStmt = $db->query('SELECT DATABASE()');
            $schema = (string)$schemaStmt->fetchColumn();
            if ($schema === '') {
                return 0.0;
            }

            $stmt = $db->prepare("
                SELECT COALESCE(SUM(data_length + index_length), 0)
                FROM information_schema.TABLES
                WHERE table_schema = ?
                  AND table_name IN ('download_sessions', 'download_session_events')
            ");
            $stmt->execute([$schema]);
            return round(((float)$stmt->fetchColumn()) / 1024 / 1024, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function isSignalEnabled(string $settingKey): bool
    {
        return Setting::get($settingKey, '1') === '1';
    }

    private function getUploaderTrustTier(int $userId): string
    {
        if ($userId <= 0) {
            return 'normal';
        }
        if (isset($this->trustTierCache[$userId])) {
            return $this->trustTierCache[$userId];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT trust_tier FROM fraud_account_controls WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $this->trustTierCache[$userId] = $this->normalizeTrustTier((string)($stmt->fetchColumn() ?: 'normal'));
    }

    private function normalizeTrustTier(string $tier): string
    {
        $tier = strtolower(trim($tier));
        return in_array($tier, self::TRUST_TIER_VALUES, true) ? $tier : 'normal';
    }

    private function getDownloaderMeta(?int $userId): ?array
    {
        if (!$userId) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, email_verified, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    private function looksLikeLinkedDownloader(int $ownerId, int $downloaderUserId, string $visitorCookieHash, string $ipHash): bool
    {
        $db = Database::getInstance()->getConnection();
        if ($visitorCookieHash !== '') {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT downloader_user_id)
                FROM reward_receipts
                WHERE user_id = ?
                  AND visitor_cookie_hash = ?
                  AND downloader_user_id IS NOT NULL
                  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$ownerId, $visitorCookieHash]);
            if ((int)$stmt->fetchColumn() >= 2) {
                return true;
            }
        }

        if ($ipHash !== '') {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT downloader_user_id)
                FROM reward_receipts
                WHERE user_id = ?
                  AND ip_hash = ?
                  AND downloader_user_id IS NOT NULL
                  AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$ownerId, $ipHash]);
            if ((int)$stmt->fetchColumn() >= 2) {
                return true;
            }
        }

        return false;
    }

    private function applyClearedStats(int $userId, float $amount, string $createdAt, string $earningType): void
    {
        if ($earningType !== 'download_reward') {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $day = date('Y-m-d', strtotime($createdAt));
        $stmt = $db->prepare("
            INSERT INTO stats_daily (user_id, day, downloads, earnings)
            VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE downloads = downloads + 1, earnings = earnings + VALUES(earnings)
        ");
        $stmt->execute([$userId, $day, $amount]);
    }
}
