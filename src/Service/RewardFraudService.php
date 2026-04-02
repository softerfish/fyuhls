<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Model\Setting;

class RewardFraudService
{
    private static bool $schemaReady = false;
    private const VISITOR_COOKIE = 'fyu_vid';
    private const REMOTE_EVENT_WINDOW_SECONDS = 300;
    private const CLEANUP_BATCH_SIZE = 10000;
    private const STREAM_MAX_CLOCK_SKEW_SECONDS = 5;
    private const STREAM_MIN_HEARTBEAT_COUNT = 2;
    private const STREAM_MIN_HEARTBEAT_WINDOW_SECONDS = 10;
    private const STREAM_MAX_DURATION_DRIFT_SECONDS = 5;

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::getInstance()->getConnection();

        $db->exec("CREATE TABLE IF NOT EXISTS `download_sessions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_id` CHAR(32) NOT NULL,
            `file_id` BIGINT UNSIGNED NOT NULL,
            `uploader_user_id` BIGINT UNSIGNED NOT NULL,
            `downloader_user_id` BIGINT UNSIGNED NULL,
            `delivery_mode` VARCHAR(32) NOT NULL DEFAULT 'php_proxy',
            `reward_mode` ENUM('download','stream') NOT NULL DEFAULT 'download',
            `status` ENUM('created','started','progressing','completed','aborted','expired','flagged') NOT NULL DEFAULT 'created',
            `ip_hash` VARCHAR(64) NOT NULL,
            `ua_hash` VARCHAR(64) NULL,
            `visitor_cookie_hash` VARCHAR(64) NULL,
            `accept_language_hash` VARCHAR(64) NULL,
            `timezone_offset` SMALLINT NULL,
            `platform_bucket` VARCHAR(64) NULL,
            `screen_bucket` VARCHAR(32) NULL,
            `asn` VARCHAR(64) NULL,
            `network_type` VARCHAR(32) NULL,
            `country_code` CHAR(2) NULL,
            `cloudflare_risk_score` INT NOT NULL DEFAULT 0,
            `proxy_intel_risk_score` INT NOT NULL DEFAULT 0,
            `proxy_intel_type` VARCHAR(32) NULL,
            `proxy_intel_provider` VARCHAR(128) NULL,
            `proxy_intel_last_seen` VARCHAR(64) NULL,
            `bytes_expected` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `percent_complete` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `watch_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
            `watch_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `risk_score` INT NOT NULL DEFAULT 0,
            `risk_level` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
            `risk_reasons_json` JSON NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `expires_at` TIMESTAMP NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `public_id` (`public_id`),
            KEY `session_status_idx` (`status`, `created_at`),
            KEY `session_file_idx` (`file_id`, `created_at`),
            KEY `session_uploader_idx` (`uploader_user_id`, `created_at`),
            KEY `session_signature_idx` (`visitor_cookie_hash`, `ip_hash`, `ua_hash`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `download_session_events` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id` BIGINT UNSIGNED NOT NULL,
            `event_type` VARCHAR(32) NOT NULL,
            `server_id` BIGINT UNSIGNED NULL,
            `event_public_id` CHAR(32) NULL,
            `nonce` VARCHAR(128) NULL,
            `signature_valid` TINYINT(1) NOT NULL DEFAULT 0,
            `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `watch_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
            `watch_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `source_ip_hash` VARCHAR(64) NULL,
            `event_payload` JSON NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_public_id` (`event_public_id`),
            UNIQUE KEY `nonce` (`nonce`),
            KEY `session_event_idx` (`session_id`, `created_at`),
            CONSTRAINT `download_session_events_session_fk` FOREIGN KEY (`session_id`) REFERENCES `download_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `fraud_account_scores` (
            `user_id` BIGINT UNSIGNED NOT NULL,
            `risk_score` INT NOT NULL DEFAULT 0,
            `held_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `flagged_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `suspicious_file_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `suspicious_network_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `fraud_network_summaries` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `asn` VARCHAR(64) NULL,
            `country_code` CHAR(2) NULL,
            `network_type` VARCHAR(32) NULL,
            `session_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `held_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `flagged_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `fraud_network_lookup_idx` (`country_code`, `network_type`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS `remote_reward_event_nonces` (
            `nonce` VARCHAR(128) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`nonce`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `session_id` BIGINT UNSIGNED NULL AFTER `file_id`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `ua_hash` VARCHAR(64) NULL AFTER `ip_hash`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `visitor_cookie_hash` VARCHAR(64) NULL AFTER `ua_hash`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `accept_language_hash` VARCHAR(64) NULL AFTER `visitor_cookie_hash`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `timezone_offset` SMALLINT NULL AFTER `accept_language_hash`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `platform_bucket` VARCHAR(64) NULL AFTER `timezone_offset`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `screen_bucket` VARCHAR(32) NULL AFTER `platform_bucket`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `asn` VARCHAR(64) NULL AFTER `screen_bucket`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `network_type` VARCHAR(32) NULL AFTER `asn`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `country_code` CHAR(2) NULL AFTER `network_type`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `risk_score` INT NOT NULL DEFAULT 0 AFTER `country_code`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `risk_level` VARCHAR(16) NULL AFTER `risk_score`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `risk_reasons_json` JSON NULL AFTER `risk_level`",
            "ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `proof_status` VARCHAR(32) NULL AFTER `risk_reasons_json`",
            "ALTER TABLE `earnings` MODIFY COLUMN `status` ENUM('held','flagged_review','cleared','reversed','paid','cancelled','pending') NOT NULL DEFAULT 'held'",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `session_id` BIGINT UNSIGNED NULL AFTER `file_id`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `risk_score` INT NOT NULL DEFAULT 0 AFTER `ip_hash`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `risk_reasons_json` JSON NULL AFTER `risk_score`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `hold_until` TIMESTAMP NULL AFTER `risk_reasons_json`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `reviewed_by` BIGINT UNSIGNED NULL AFTER `hold_until`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `reviewed_at` TIMESTAMP NULL AFTER `reviewed_by`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `review_note` TEXT NULL AFTER `reviewed_at`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `country_code` CHAR(2) NULL AFTER `review_note`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `network_type` VARCHAR(32) NULL AFTER `country_code`",
            "ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `asn` VARCHAR(64) NULL AFTER `network_type`",
            "ALTER TABLE `earnings` ADD INDEX IF NOT EXISTS `earnings_status_hold_idx` (`status`, `hold_until`, `created_at`)",
            "ALTER TABLE `reward_receipts` ADD INDEX IF NOT EXISTS `receipt_status_created_idx` (`status`, `created_at`)",
            "ALTER TABLE `reward_receipts` ADD INDEX IF NOT EXISTS `receipt_cookie_idx` (`user_id`, `visitor_cookie_hash`, `created_at`)"
        ] as $statement) {
            try {
                $db->exec($statement);
            } catch (\Throwable $e) {
            }
        }

        self::$schemaReady = true;
    }

    public function ensureVisitorCookie(): string
    {
        $current = trim((string)($_COOKIE[self::VISITOR_COOKIE] ?? ''));
        if ($current !== '') {
            return $current;
        }

        $value = bin2hex(random_bytes(16));
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
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
            SELECT u.monetization_model, p.ppd_enabled
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)($file['user_id'] ?? 0)]);
        $row = $stmt->fetch();

        if (!$row || (int)($row['ppd_enabled'] ?? 0) !== 1) {
            return false;
        }

        return in_array((string)($row['monetization_model'] ?? 'ppd'), ['ppd', 'mixed'], true);
    }

    public function createDownloadSession(array $file, ?int $downloaderUserId, array $clientHints = [], string $rewardMode = 'download'): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $signals = $this->buildClientSignals($clientHints);
        $publicId = bin2hex(random_bytes(16));
        $bytesExpected = (int)($file['file_size'] ?? 0);

        $stmt = $db->prepare("
            INSERT INTO download_sessions (
                public_id, file_id, uploader_user_id, downloader_user_id, delivery_mode, reward_mode, status,
                ip_hash, ua_hash, visitor_cookie_hash, accept_language_hash, timezone_offset, platform_bucket,
                screen_bucket, asn, network_type, country_code, bytes_expected, expires_at
            ) VALUES (?, ?, ?, ?, 'php_proxy', ?, 'created', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
        ");
        $stmt->execute([
            $publicId,
            (int)$file['id'],
            (int)$file['user_id'],
            $downloaderUserId,
            $rewardMode,
            $signals['ip_hash'],
            $signals['ua_hash'],
            $signals['visitor_cookie_hash'],
            $signals['accept_language_hash'],
            $signals['timezone_offset'],
            $signals['platform_bucket'],
            $signals['screen_bucket'],
            $signals['asn'],
            $signals['network_type'],
            $signals['country_code'],
            $bytesExpected,
        ]);

        $this->recordSessionEventById((int)$db->lastInsertId(), 'start', [
            'event_public_id' => bin2hex(random_bytes(16)),
            'signature_valid' => 1,
            'bytes_sent' => 0,
        ]);

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
        ];
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

        if (!$this->claimRemoteNonce($nonce)) {
            return ['ok' => false, 'code' => 409, 'error' => 'Receipt nonce has already been used.'];
        }

        if ($this->remoteEventExists($eventId)) {
            return ['ok' => true, 'code' => 202, 'status' => 'duplicate', 'session' => $session];
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
            $score += (int)($receipt['proxy_intel_risk_score'] ?? 0);
        }

        if ($this->isSignalEnabled('rewards_use_cloudflare_intel')) {
            $score += (int)($receipt['cloudflare_risk_score'] ?? 0);
        }

        $downloader = $this->getDownloaderMeta(isset($receipt['downloader_user_id']) ? (int)$receipt['downloader_user_id'] : null);
        if ($downloader !== null) {
            if (Setting::get('rewards_ppd_guests_only', '0') === '1') {
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

        $level = $score >= (int)Setting::get('rewards_flag_threshold', '50') ? 'high'
            : ($score >= (int)Setting::get('rewards_review_threshold', '25') ? 'medium' : 'low');

        return [
            'score' => $score,
            'level' => $level,
            'reasons' => array_values(array_unique($reasons)),
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
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT id, user_id, amount, created_at
            FROM earnings
            WHERE status = 'held' AND hold_until IS NOT NULL AND hold_until <= NOW()
            ORDER BY id ASC
            LIMIT 5000
        ");
        $rows = $stmt->fetchAll() ?: [];
        if (empty($rows)) {
            return 0;
        }

        $update = $db->prepare("UPDATE earnings SET status = 'cleared' WHERE id = ?");
        foreach ($rows as $row) {
            $update->execute([(int)$row['id']]);
            $this->applyClearedStats((int)$row['user_id'], (float)$row['amount'], (string)$row['created_at']);
        }

        return count($rows);
    }

    public function reviewEarning(int $earningId, string $action, int $adminId, string $note = ''): bool
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM earnings WHERE id = ? LIMIT 1");
        $stmt->execute([$earningId]);
        $earning = $stmt->fetch();
        if (!$earning || !in_array((string)$earning['status'], ['held', 'flagged_review'], true)) {
            return false;
        }

        $targetStatus = match ($action) {
            'clear' => 'cleared',
            'hold' => 'held',
            'reverse' => 'reversed',
            default => null,
        };
        if ($targetStatus === null) {
            return false;
        }

        $holdUntil = null;
        if ($targetStatus === 'held') {
            $holdDays = max(0, (int)Setting::get('rewards_hold_days', '7'));
            $holdUntil = date('Y-m-d H:i:s', strtotime("+{$holdDays} days"));
        }

        $update = $db->prepare("
            UPDATE earnings
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?, hold_until = ?
            WHERE id = ?
        ");
        $update->execute([$targetStatus, $adminId, $note, $holdUntil, $earningId]);

        if ($targetStatus === 'cleared') {
            $this->applyClearedStats((int)$earning['user_id'], (float)$earning['amount'], (string)$earning['created_at']);
        }

        return true;
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
            'trust_cloudflare' => Setting::get('trust_cloudflare', '1') === '1',
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

        return $overview;
    }

    public function getReviewQueue(int $limit = 100): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT e.*, u.username, f.filename
            FROM earnings e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN files f ON f.id = e.file_id
            WHERE e.status IN ('held', 'flagged_review')
            ORDER BY e.risk_score DESC, e.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            if (!empty($row['username']) && str_starts_with((string)$row['username'], 'ENC:')) {
                $row['username'] = EncryptionService::decrypt($row['username']);
            }
            if (!empty($row['filename']) && str_starts_with((string)$row['filename'], 'ENC:')) {
                $row['filename'] = EncryptionService::decrypt($row['filename']);
            }
        }
        return $rows;
    }

    public function getUploaderScores(int $limit = 50): array
    {
        $this->ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT fas.*, u.username, u.email
            FROM fraud_account_scores fas
            JOIN users u ON u.id = fas.user_id
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

    private function calculateRiskScore(array $session, array $file, array &$reasons): int
    {
        $score = 0;
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

        return $score;
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
        return hash_hmac('sha256', $value, Config::get('app_key', 'change_this_to_a_random_string'));
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

        $rawConfig = (string)($server['config'] ?? '{}');
        if ($rawConfig !== '' && !str_starts_with($rawConfig, '{')) {
            try {
                $rawConfig = EncryptionService::decrypt($rawConfig);
            } catch (\Throwable $e) {
                $rawConfig = '{}';
            }
        }
        $server['_config'] = json_decode($rawConfig, true) ?: [];
        return $server;
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

    private function claimRemoteNonce(string $nonce): bool
    {
        $db = Database::getInstance()->getConnection();
        try {
            $stmt = $db->prepare("INSERT INTO remote_reward_event_nonces (nonce) VALUES (?)");
            $stmt->execute([$nonce]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function remoteEventExists(string $eventId): bool
    {
        $db = Database::getInstance()->getConnection();
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

    private function applyClearedStats(int $userId, float $amount, string $createdAt): void
    {
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
