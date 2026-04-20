<?php

namespace App\Service;

use App\Core\Database;
use App\Model\File;
use App\Model\Setting;

class NginxDownloadLogService
{
    private const SOURCE = 'nginx_log';
    private const DEFAULT_MAX_LINES_PER_RUN = 5000;
    private const PURGE_BATCH_SIZE = 5000;
    private const HEALTH_REASON_CODES = ['missing_viewer_identity', 'missing_client_ip'];
    private static bool $schemaEnsured = false;

    private function validateConfiguredLogPath(string $path): void
    {
        $isUnixAbsolute = str_starts_with($path, '/');
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
        if (!$isUnixAbsolute && !$isWindowsAbsolute) {
            throw new \RuntimeException('Configured Nginx completion log path must be absolute.');
        }

        if (preg_match('/[\x00-\x1F]/', $path) === 1) {
            throw new \RuntimeException('Configured Nginx completion log path contains invalid characters.');
        }

        if (preg_match('/(^|[\\\\\\/])\.\.([\\\\\\/]|$)/', $path) === 1) {
            throw new \RuntimeException('Configured Nginx completion log path cannot contain parent-directory traversal.');
        }

        $normalized = str_replace('\\', '/', $path);
        $basename = strtolower((string)pathinfo($normalized, PATHINFO_BASENAME));
        $extension = strtolower((string)pathinfo($normalized, PATHINFO_EXTENSION));
        $looksLikeLogFile = in_array($extension, ['log', 'txt'], true)
            || str_contains($basename, 'access')
            || str_contains($basename, 'download');

        if (!$looksLikeLogFile) {
            throw new \RuntimeException('Configured Nginx completion log path must point to a plausible log file such as *.log, *.txt, or an access/download log name.');
        }
    }

    public function process(?int $maxLines = null): array
    {
        $path = trim((string)Setting::get('nginx_completion_log_path', '', 'downloads'));
        if ($path === '') {
            return ['status' => 'disabled', 'processed' => 0, 'credited' => 0, 'skipped' => 0, 'purged' => 0];
        }

        $this->validateConfiguredLogPath($path);

        $maxLines = $this->resolveMaxLinesPerRun($maxLines);

        $this->ensureSchema();

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Configured Nginx completion log path is missing or unreadable.');
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Could not open the configured Nginx completion log path.');
        }

        $state = $this->loadState($path);
        $stat = @fstat($handle) ?: [];
        $inode = (string)($stat['ino'] ?? '');
        $size = (int)($stat['size'] ?? 0);
        $offset = (int)($state['offset'] ?? 0);

        if (($state['inode'] ?? '') !== '' && $inode !== '' && (string)$state['inode'] !== $inode) {
            $offset = 0;
        } elseif ($offset > $size) {
            $offset = 0;
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $results = [
            'status' => 'ok',
            'processed' => 0,
            'credited' => 0,
            'skipped' => 0,
            'purged' => 0,
        ];

        $lineCount = 0;
        while ($lineCount < $maxLines) {
            $position = ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $lineCount++;
            if (!str_ends_with($line, "\n")) {
                fseek($handle, $position);
                break;
            }
            $results['processed']++;
            $event = $this->normalizeEvent($line);
            if ($event === null) {
                $results['skipped']++;
                $this->log('event_skipped', ['reason_code' => 'invalid_json']);
                continue;
            }

            $outcome = $this->processEvent($event);
            if ($outcome === 'credited') {
                $results['credited']++;
            } elseif ($outcome !== 'processed') {
                $results['skipped']++;
            }
        }

        $this->saveState($path, [
            'inode' => $inode,
            'offset' => ftell($handle),
            'updated_at' => time(),
        ]);

        fclose($handle);
        $results['purged'] = $this->purgeProcessedEvents();

        return $results;
    }

    public function getHealthSummary(int $lookbackHours = 24): array
    {
        $path = trim((string)Setting::get('nginx_completion_log_path', '', 'downloads'));
        if ($path === '') {
            return [
                'enabled' => false,
                'has_warning' => false,
                'skipped_total' => 0,
                'missing_viewer_identity' => 0,
                'missing_client_ip' => 0,
                'last_issue_at' => null,
            ];
        }

        $this->validateConfiguredLogPath($path);

        $this->ensureSchema();

        $lookbackHours = max(1, $lookbackHours);
        $db = Database::getInstance()->getConnection();
        $reasonPlaceholders = implode(', ', array_fill(0, count(self::HEALTH_REASON_CODES), '?'));

        $summaryStmt = $db->prepare("
            SELECT
                SUM(CASE WHEN reason_code = 'missing_viewer_identity' THEN 1 ELSE 0 END) AS missing_viewer_identity,
                SUM(CASE WHEN reason_code = 'missing_client_ip' THEN 1 ELSE 0 END) AS missing_client_ip,
                COUNT(*) AS skipped_total,
                MAX(processed_at) AS last_issue_at
            FROM download_completion_events
            WHERE source = ?
              AND processing_status = 'skipped'
              AND reason_code IN ($reasonPlaceholders)
              AND processed_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");

        $summaryStmt->execute(array_merge([self::SOURCE], self::HEALTH_REASON_CODES, [$lookbackHours]));
        $row = $summaryStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $missingViewerIdentity = (int)($row['missing_viewer_identity'] ?? 0);
        $missingClientIp = (int)($row['missing_client_ip'] ?? 0);
        $skippedTotal = (int)($row['skipped_total'] ?? 0);

        return [
            'enabled' => true,
            'has_warning' => $skippedTotal > 0,
            'skipped_total' => $skippedTotal,
            'missing_viewer_identity' => $missingViewerIdentity,
            'missing_client_ip' => $missingClientIp,
            'last_issue_at' => $row['last_issue_at'] ?? null,
        ];
    }

    private function processEvent(array $event): string
    {
        $downloadId = (int)($event['download_id'] ?? 0);
        if ($downloadId <= 0) {
            $this->log('event_skipped', ['reason_code' => 'missing_download_id']);
            return 'skipped';
        }

        $db = Database::getInstance()->getConnection();
        $sourceEventKey = $this->buildSourceEventKey($event);
        $eventId = $this->ensureEventRecord($sourceEventKey, $event);
        if ($eventId === null) {
            return 'skipped';
        }

        $stmt = $db->prepare("SELECT processed_at FROM download_completion_events WHERE id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        $processedAt = $stmt->fetchColumn();
        if ($processedAt) {
            return 'processed';
        }

        $stmt = $db->prepare("SELECT * FROM active_downloads WHERE id = ? LIMIT 1");
        $stmt->execute([$downloadId]);
        $activeDownload = $stmt->fetch();
        $resolvedFileId = $activeDownload ? (int)$activeDownload['file_id'] : (int)($event['file_id'] ?? 0);
        $file = $resolvedFileId > 0 ? File::find($resolvedFileId) : null;
        if (!$file) {
            if ($activeDownload) {
                $this->removeActiveDownload($downloadId);
            }
            $reasonCode = $activeDownload ? 'file_not_found' : 'reconcile_file_not_found';
            $this->markEventProcessed($eventId, 'skipped', $reasonCode);
            $this->log('event_skipped', [
                'download_id' => $downloadId,
                'file_id' => $resolvedFileId > 0 ? $resolvedFileId : null,
                'delivery_mode' => 'nginx',
                'reason_code' => $reasonCode,
                'status' => $event['status'],
            ]);
            return 'skipped';
        }

        $minPercent = (int)Setting::get('ppd_min_download_percent', '0', 'rewards');
        $isRewardEligible = !empty($file['user_id']) && !$this->isVideoFile($file);
        $outcomeRecorded = false;

        if (!$activeDownload && empty($event['has_valid_viewer_user_id'])) {
            $reasonCode = 'missing_viewer_identity';
            $this->markEventProcessed($eventId, 'skipped', $reasonCode);
            $outcomeRecorded = true;
            $this->log('event_skipped', [
                'file_id' => (int)$file['id'],
                'download_id' => $downloadId,
                'delivery_mode' => 'nginx',
                'reason_code' => $reasonCode,
                'status' => $event['status'],
            ]);
            return 'skipped';
        }

        $credited = false;
        if ($isRewardEligible) {
            $decision = (new StandardFilePayoutPolicy())->evaluate([
                'delivery_mode' => 'nginx',
                'file_size' => (int)($file['file_size'] ?? 0),
                'bytes_sent' => $event['bytes_sent'],
                'status' => $event['status'],
                'min_percent' => $minPercent,
                'stream_mode' => false,
            ]);

            if ($decision['eligible']) {
                $ip = $this->resolveClientIp($activeDownload ?: null, (string)($event['remote_ip'] ?? ''));
                if ($ip !== '') {
                    $downloaderUserId = $this->resolveDownloaderUserId($activeDownload ?: null, $event);
                    $credited = (new RewardService())->trackDownload((int)$file['id'], $ip, $downloaderUserId, $this->buildRewardContext($activeDownload ?: null, $sourceEventKey));
                    $reasonCode = $credited ? $decision['reason_code'] : 'receipt_not_created';
                    $this->log($credited ? 'event_credited' : 'event_skipped', [
                        'file_id' => (int)$file['id'],
                        'download_id' => $downloadId,
                        'delivery_mode' => 'nginx',
                        'reason_code' => $reasonCode,
                        'status' => $event['status'],
                        'observed_bytes' => $decision['observed_bytes'],
                        'required_bytes' => $decision['required_bytes'],
                        'min_percent' => $decision['min_percent'],
                    ]);
                    $this->markEventProcessed($eventId, $credited ? 'credited' : 'skipped', $reasonCode);
                    $outcomeRecorded = true;
                } else {
                    $reasonCode = 'missing_client_ip';
                    $this->log('event_skipped', [
                        'file_id' => (int)$file['id'],
                        'download_id' => $downloadId,
                        'delivery_mode' => 'nginx',
                        'reason_code' => $reasonCode,
                        'status' => $event['status'],
                    ]);
                    $this->markEventProcessed($eventId, 'skipped', $reasonCode);
                    $outcomeRecorded = true;
                }
            } else {
                $reasonCode = (string)$decision['reason_code'];
                $this->log('event_skipped', [
                    'file_id' => (int)$file['id'],
                    'download_id' => $downloadId,
                    'delivery_mode' => 'nginx',
                    'reason_code' => $reasonCode,
                    'status' => $event['status'],
                    'observed_bytes' => $decision['observed_bytes'],
                    'required_bytes' => $decision['required_bytes'],
                    'min_percent' => $decision['min_percent'],
                ]);
                $this->markEventProcessed($eventId, 'skipped', $reasonCode);
                $outcomeRecorded = true;
            }
        }

        if ($activeDownload) {
            $this->removeActiveDownload($downloadId);
        }
        if (!$credited && !$outcomeRecorded) {
            $this->markEventProcessed($eventId, 'processed', $isRewardEligible ? 'not_reward_credited' : 'not_reward_eligible');
        }

        return $credited ? 'credited' : 'processed';
    }

    private function normalizeEvent(string $line): ?array
    {
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) {
            return null;
        }

        $status = trim((string)($decoded['status'] ?? ''));
        $bytesSent = $decoded['bytes_sent'] ?? $decoded['body_bytes_sent'] ?? null;
        $downloadId = $decoded['download_id'] ?? null;
        $fileId = $decoded['file_id'] ?? null;

        return [
            'msec' => (string)($decoded['msec'] ?? ''),
            'remote_ip' => trim((string)($decoded['remote_addr'] ?? $decoded['remote_ip'] ?? '')),
            'status' => $status,
            'bytes_sent' => is_numeric($bytesSent) ? (int)$bytesSent : $bytesSent,
            'request_time' => (string)($decoded['request_time'] ?? ''),
            'download_id' => ctype_digit((string)$downloadId) ? (int)$downloadId : 0,
            'file_id' => ctype_digit((string)$fileId) ? (int)$fileId : 0,
            'viewer_user_id' => ctype_digit((string)($decoded['viewer_user_id'] ?? '')) ? (int)$decoded['viewer_user_id'] : null,
            'has_valid_viewer_user_id' => ctype_digit((string)($decoded['viewer_user_id'] ?? '')),
            'original_uri' => trim((string)($decoded['original_uri'] ?? '')),
            'raw' => $decoded,
        ];
    }

    private function buildSourceEventKey(array $event): string
    {
        $parts = [
            self::SOURCE,
            (string)($event['download_id'] ?? 0),
            (string)($event['file_id'] ?? 0),
            (string)($event['status'] ?? ''),
            (string)($event['bytes_sent'] ?? ''),
            (string)($event['msec'] ?? ''),
            (string)($event['remote_ip'] ?? ''),
            (string)($event['original_uri'] ?? ''),
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function ensureEventRecord(string $sourceEventKey, array $event): ?int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO download_completion_events (
                source, source_event_key, download_id, file_id, status_code, bytes_sent, remote_ip, request_time_ms, event_payload, processing_status, reason_code
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
        ");

        $requestTimeMs = is_numeric($event['request_time']) ? (int)round(((float)$event['request_time']) * 1000) : null;
        $payloadJson = json_encode($event['raw'], JSON_UNESCAPED_SLASHES);
        $stmt->execute([
            self::SOURCE,
            $sourceEventKey,
            (int)$event['download_id'],
            (int)$event['file_id'],
            substr((string)$event['status'], 0, 3),
            is_numeric($event['bytes_sent']) ? (int)$event['bytes_sent'] : null,
            $event['remote_ip'] !== '' ? $event['remote_ip'] : null,
            $requestTimeMs,
            $payloadJson !== false ? $payloadJson : null,
        ]);

        $id = (int)$db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    private function markEventProcessed(int $eventId, string $processingStatus = 'processed', ?string $reasonCode = null): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE download_completion_events
            SET processed_at = NOW(), processing_status = ?, reason_code = ?
            WHERE id = ?
        ");
        $stmt->execute([$processingStatus, $reasonCode, $eventId]);
    }

    private function removeActiveDownload(int $downloadId): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM active_downloads WHERE id = ?");
        $stmt->execute([$downloadId]);
    }

    private function resolveClientIp(?array $activeDownload, string $fallbackIp): string
    {
        if ($activeDownload && !empty($activeDownload['ip_address'])) {
            try {
                $decrypted = EncryptionService::decrypt((string)$activeDownload['ip_address']);
                if ($decrypted !== '') {
                    return $decrypted;
                }
            } catch (\Throwable $e) {
            }
        }

        return $fallbackIp;
    }

    private function resolveDownloaderUserId(?array $activeDownload, array $event): ?int
    {
        if ($activeDownload && array_key_exists('user_id', $activeDownload) && $activeDownload['user_id'] !== null) {
            return (int)$activeDownload['user_id'];
        }

        $viewerUserId = $event['viewer_user_id'] ?? null;
        if (is_int($viewerUserId) && $viewerUserId > 0) {
            return $viewerUserId;
        }

        return null;
    }

    private function buildRewardContext(?array $activeDownload, string $sourceEventKey): array
    {
        $context = [
            'source_event_key' => $sourceEventKey,
        ];

        if (!$activeDownload) {
            return $context;
        }

        foreach ([
            'session_id',
            'ip_hash',
            'ua_hash',
            'visitor_cookie_hash',
            'accept_language_hash',
            'timezone_offset',
            'platform_bucket',
            'screen_bucket',
            'asn',
            'network_type',
            'country_code',
        ] as $field) {
            if (array_key_exists($field, $activeDownload) && $activeDownload[$field] !== null && $activeDownload[$field] !== '') {
                $context[$field] = $activeDownload[$field];
            }
        }

        return $context;
    }

    private function isVideoFile(array $file): bool
    {
        $mimeType = (string)($file['mime_type'] ?? 'application/octet-stream');
        if (str_starts_with($mimeType, 'ENC:')) {
            $mimeType = EncryptionService::decrypt($mimeType);
        }

        return str_starts_with($mimeType, 'video/');
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `download_completion_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source` VARCHAR(32) NOT NULL,
                `source_event_key` VARCHAR(64) NOT NULL,
                `download_id` BIGINT UNSIGNED NOT NULL,
                `file_id` BIGINT UNSIGNED NULL,
                `status_code` VARCHAR(8) NULL,
                `bytes_sent` BIGINT UNSIGNED NULL,
                `remote_ip` VARCHAR(64) NULL,
                `request_time_ms` INT UNSIGNED NULL,
                `event_payload` LONGTEXT NULL,
                `processing_status` VARCHAR(32) NULL,
                `reason_code` VARCHAR(64) NULL,
                `processed_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `download_completion_source_event` (`source_event_key`),
                KEY `download_completion_status_reason` (`source`, `processing_status`, `reason_code`, `processed_at`),
                KEY `download_completion_processed` (`processed_at`, `id`),
                KEY `download_completion_download` (`download_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
        }

        try {
            $columns = $db->query("SHOW COLUMNS FROM `download_completion_events`")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('processing_status', $columns, true)) {
                $db->exec("ALTER TABLE `download_completion_events` ADD COLUMN `processing_status` VARCHAR(32) NULL AFTER `event_payload`");
            }
            if (!in_array('reason_code', $columns, true)) {
                $db->exec("ALTER TABLE `download_completion_events` ADD COLUMN `reason_code` VARCHAR(64) NULL AFTER `processing_status`");
            }
            $indexes = $db->query("SHOW INDEX FROM `download_completion_events`")->fetchAll(\PDO::FETCH_ASSOC);
            $indexNames = array_column($indexes, 'Key_name');
            if (!in_array('download_completion_status_reason', $indexNames, true)) {
                $db->exec("ALTER TABLE `download_completion_events` ADD KEY `download_completion_status_reason` (`source`, `processing_status`, `reason_code`, `processed_at`)");
            }
        } catch (\Throwable $e) {
        }

        self::$schemaEnsured = true;
    }

    private function loadState(string $path): array
    {
        $statePath = $this->getStatePath($path);
        if (!is_file($statePath)) {
            return ['offset' => 0, 'inode' => ''];
        }

        $decoded = json_decode((string)file_get_contents($statePath), true);
        return is_array($decoded) ? $decoded : ['offset' => 0, 'inode' => ''];
    }

    private function saveState(string $path, array $state): void
    {
        $statePath = $this->getStatePath($path);
        $dir = dirname($statePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function getStatePath(string $path): string
    {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $root . '/storage/cache/nginx_download_log_state_' . sha1($path) . '.json';
    }

    private function purgeProcessedEvents(): int
    {
        $db = Database::getInstance()->getConnection();
        $retentionDays = max(1, (int)Setting::get('nginx_completion_retention_days', '7', 'downloads'));
        $stmt = $db->prepare("
            DELETE FROM download_completion_events
            WHERE processed_at IS NOT NULL
              AND processed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY id ASC
            LIMIT " . self::PURGE_BATCH_SIZE
        );
        $stmt->execute([$retentionDays]);

        return $stmt->rowCount();
    }

    private function resolveMaxLinesPerRun(?int $maxLines): int
    {
        if ($maxLines !== null && $maxLines > 0) {
            return $maxLines;
        }

        return max(1, (int)Setting::get(
            'nginx_completion_max_lines_per_run',
            (string)self::DEFAULT_MAX_LINES_PER_RUN,
            'downloads'
        ));
    }

    private function log(string $event, array $context = []): void
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
            'has_valid_viewer_user_id',
        ];

        $parts = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $context) || $context[$key] === null || $context[$key] === '') {
                continue;
            }
            $parts[] = $key . '=' . (string)$context[$key];
        }

        error_log('[NginxDownloadLog] ' . $event . ($parts ? ' ' . implode(' ', $parts) : ''));
    }
}
