<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Model\File;
use App\Model\Setting;

/**
 * RewardService - High-Scale Enterprise Edition
 *
 * Futureproofed for 100k+ downloads/day using Asynchronous Buffer & Flush logic.
 */
class RewardService
{
    private static bool $schemaEnsured = false;
    private const CLAIM_TTL_MINUTES = 10;

    public static function retentionDays(): int
    {
        return max(1, (int)Setting::get('rewards_retention_days', '7'));
    }

    private function isRewardsDisabled(): bool
    {
        return !FeatureService::rewardsEnabled();
    }

    /**
     * trackDownload (The Fast Path)
     *
     * Injects a download receipt into the high-speed buffer.
     * Completes in <5ms to prevent 504 timeouts on high-traffic sites.
     */
    public function trackDownload(int $fileId, string $ip, ?int $currentUserId = null, array $context = []): bool
    {
        if ($this->isRewardsDisabled()) {
            return false;
        }

        $db = null;
        $receiptLockKey = null;
        try {
            $this->ensureSchema();

            $ip = SecurityService::normalizeIp($ip);
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }

            $file = File::find($fileId);
            if (!$file || empty($file['user_id']) || !$this->isFileRewardEligible($file)) {
                return false;
            }

            $ownerId = (int)$file['user_id'];
            if ($currentUserId !== null && $currentUserId === $ownerId) {
                return false;
            }

            $db = Database::getInstance()->getConnection();
            $fraud = new RewardFraudService();
            $fraud->ensureSchema();
            $sourceEventKey = trim((string)($context['source_event_key'] ?? ''));
            if ($sourceEventKey !== '') {
                $dupStmt = $db->prepare("SELECT 1 FROM reward_receipts WHERE source_event_key = ? LIMIT 1");
                $dupStmt->execute([$sourceEventKey]);
                if ($dupStmt->fetchColumn()) {
                    return false;
                }
            }
            $receiptLockKey = $this->acquireReceiptLock($db, $sourceEventKey, isset($context['session_id']) ? (int)$context['session_id'] : null);
            if ($receiptLockKey === false) {
                return false;
            }
            if (!empty($context['session_id'])) {
                $dupStmt = $db->prepare("SELECT 1 FROM reward_receipts WHERE session_id = ? LIMIT 1");
                $dupStmt->execute([(int)$context['session_id']]);
                if ($dupStmt->fetchColumn()) {
                    return false;
                }
            }
            $signals = $this->resolveReceiptSignals($fraud, $context, $ip);

            $stmt = $db->prepare("
                INSERT INTO reward_receipts (
                    file_id, session_id, source_event_key, user_id, downloader_user_id, ip_address, ip_hash, ua_hash,
                    visitor_cookie_hash, accept_language_hash, timezone_offset, platform_bucket,
                    screen_bucket, asn, network_type, country_code, proof_status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $fileId,
                $context['session_id'] ?? null,
                $sourceEventKey !== '' ? $sourceEventKey : null,
                $ownerId,
                $currentUserId,
                EncryptionService::encrypt($ip),
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
                $context['proof_status'] ?? 'legacy',
            ]);
            return true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            error_log("Rewards: Failed to drop receipt: " . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log("Rewards: Failed to drop receipt: " . $e->getMessage());
            return false;
        } finally {
            if ($db && is_string($receiptLockKey) && $receiptLockKey !== '') {
                $this->releaseReceiptLock($db, $receiptLockKey);
            }
        }
    }

    /**
     * flushQueue (The Batch Engine)
     *
     * Processes receipts in bulk. Run via Cron every 1 minute.
     */
    public function flushQueue(int $batchSize = 5000): array
    {
        if ($this->isRewardsDisabled()) {
            return ['processed' => 0, 'credited' => 0, 'flagged' => 0, 'errors' => []];
        }

        $this->ensureSchema();

        $db = Database::getInstance()->getConnection();
        $results = ['processed' => 0, 'credited' => 0, 'flagged' => 0, 'errors' => []];

        try {
            $receipts = $this->claimPendingReceipts($batchSize);

            if (empty($receipts)) {
                return $results;
            }

            $ipLimit = max(1, (int)Setting::get('ppd_ip_reward_limit', '1'));
            $minSize = (int)Setting::get('ppd_min_file_size', '1048576');
            $maxSize = (int)Setting::get('ppd_max_file_size', '0');
            $onlyGuestsCount = Setting::get('ppd_only_guests_count', '0') === '1';
            $rewardVpnTraffic = Setting::get('ppd_reward_vpn', '0') === '1';
            $maxEarnIp = (float)Setting::get('ppd_max_earn_ip', '0');
            $maxEarnFile = (float)Setting::get('ppd_max_earn_file', '0');
            $maxEarnUser = (float)Setting::get('ppd_max_earn_user', '0');
            $security = new SecurityService();
            $fraud = new RewardFraudService();

            foreach ($receipts as $receipt) {
                try {
                    $results['processed']++;

                    $receiptId = (int)$receipt['id'];
                    $fileId = (int)$receipt['file_id'];
                    $ownerId = (int)($receipt['user_id'] ?? 0);
                    $downloaderUserId = isset($receipt['downloader_user_id']) ? (int)$receipt['downloader_user_id'] : null;
                    $ip = EncryptionService::decrypt($receipt['ip_address']);
                    $ipHash = (string)($receipt['ip_hash'] ?? $this->hashIp($ip));

                    $file = File::find($fileId);
                    if (!$file || !$file['user_id'] || (int)$file['user_id'] !== $ownerId || !$this->isFileRewardEligible($file)) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    if ($downloaderUserId !== null && $downloaderUserId === $ownerId) {
                        $this->markReceipt($receiptId, 'flagged');
                        $results['flagged']++;
                        continue;
                    }

                    if ($onlyGuestsCount && $downloaderUserId !== null) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    if ($file['file_size'] < $minSize || ($maxSize > 0 && $file['file_size'] > $maxSize)) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    if (!$rewardVpnTraffic && $security->isVpnOrProxy($ip)) {
                        $this->markReceipt($receiptId, 'flagged');
                        $results['flagged']++;
                        continue;
                    }

                    if ($this->hasProcessedReceiptForWindow($ownerId, $fileId, $ipHash, $receiptId, (string)($receipt['visitor_cookie_hash'] ?? ''), (string)($receipt['ua_hash'] ?? ''))) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    if ($this->countRecentIpRewards($ownerId, $ipHash, (string)($receipt['visitor_cookie_hash'] ?? '')) >= $ipLimit) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    $amount = $this->calculateReward($file, $ip);
                    if ($amount <= 0) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    if ($maxEarnIp > 0 && ($this->sumRecentEarnings($ownerId, $ipHash, null) + $amount) > $maxEarnIp) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    if ($maxEarnFile > 0 && ($this->sumRecentEarnings($ownerId, null, $fileId) + $amount) > $maxEarnFile) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    if ($maxEarnUser > 0 && ($this->sumRecentUserEarnings($ownerId) + $amount) > $maxEarnUser) {
                        $this->markReceipt($receiptId, 'processed');
                        continue;
                    }

                    $risk = $fraud->evaluateReceipt($receipt, $file);
                    $holdDays = max(0, (int)Setting::get('rewards_hold_days', '7'));
                    $autoClearLowRisk = Setting::get('rewards_auto_clear_low_risk', '0') === '1';
                    $earningStatus = $risk['level'] === 'high'
                        ? 'flagged_review'
                        : (($risk['level'] === 'low' && $autoClearLowRisk) ? 'cleared' : 'held');
                    $holdUntil = $earningStatus === 'held' ? date('Y-m-d H:i:s', strtotime("+{$holdDays} days")) : null;

                    $db->beginTransaction();

                    $stmtE = $db->prepare("
                        INSERT INTO earnings (user_id, file_id, session_id, amount, type, status, ip_hash, risk_score, risk_reasons_json, hold_until, description, country_code, network_type, asn)
                        VALUES (?, ?, ?, ?, 'download_reward', ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtE->execute([
                        $ownerId,
                        $fileId,
                        $receipt['session_id'] ?? null,
                        $amount,
                        $earningStatus,
                        $ipHash,
                        $risk['score'],
                        json_encode($risk['reasons'], JSON_UNESCAPED_SLASHES),
                        $holdUntil,
                        $earningStatus === 'flagged_review' ? 'PPD Reward (Flagged for review)' : ($earningStatus === 'held' ? 'PPD Reward (Held for review)' : 'PPD Reward'),
                        $receipt['country_code'] ?? null,
                        $receipt['network_type'] ?? null,
                        $receipt['asn'] ?? null,
                    ]);

                    $this->updateDailyStats($ownerId, $amount, $earningStatus);
                    $db->prepare("UPDATE reward_receipts SET risk_score = ?, risk_level = ?, risk_reasons_json = ?, proof_status = ? WHERE id = ?")
                        ->execute([
                            $risk['score'],
                            $risk['level'],
                            json_encode($risk['reasons'], JSON_UNESCAPED_SLASHES),
                            $receipt['proof_status'] ?? 'legacy',
                            $receiptId,
                        ]);
                    $this->markReceipt($receiptId, 'processed');
                    $db->commit();

                    $results['credited']++;
                } catch (\Throwable $ex) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $this->releaseClaimedReceipt((int)$receipt['id']);
                    $results['errors'][] = "Receipt #{$receipt['id']}: " . $ex->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $results['errors'][] = "Global Error: " . $e->getMessage();
        }

        return $results;
    }

    /**
     * rollupUserHistory (JIT Payout Optimization)
     *
     * Summarizes individual records for a specific user.
     * Called before payout or viewing rewards to ensure O(1) balance calculation.
     */
    public function rollupUserHistory(int $userId, ?int $daysOld = null): int
    {
        if ($this->isRewardsDisabled()) {
            return 0;
        }

        $daysOld = $daysOld ?? self::retentionDays();

        $db = Database::getInstance()->getConnection();

        try {
            $stmt = $db->prepare("
                SELECT DATE(created_at) as day, SUM(amount) as total, COUNT(*) as count
                FROM earnings
                WHERE user_id = ?
                AND status = 'cleared'
                AND type = 'download_reward'
                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
            ");
            $stmt->execute([$userId, $daysOld]);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return 0;
            }

            foreach ($rows as $row) {
                $db->beginTransaction();

                $ins = $db->prepare("
                    INSERT INTO earnings (user_id, amount, type, status, description, created_at)
                    VALUES (?, ?, 'aggregate_summary', 'cleared', ?, ?)
                ");
                $ins->execute([
                    $userId,
                    $row['total'],
                    "JIT Rollup ({$row['count']} downloads)",
                    $row['day'] . " 00:00:00",
                ]);

                $del = $db->prepare("
                    DELETE FROM earnings
                    WHERE user_id = ?
                    AND type = 'download_reward'
                    AND DATE(created_at) = ?
                    AND status = 'cleared'
                ");
                $del->execute([$userId, $row['day']]);

                $db->commit();
            }
            return count($rows);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Rewards JIT Rollup Failed for User $userId: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * rollupHistory (Global Batch Engine)
     *
     * Summarizes individual reward rows into Daily Summaries to prevent 100M+ row explosion.
     * Run daily.
     */
    public function rollupHistory(?int $daysOld = null): int
    {
        if ($this->isRewardsDisabled()) {
            return 0;
        }

        $daysOld = $daysOld ?? self::retentionDays();

        $db = Database::getInstance()->getConnection();
        $processed = 0;

        try {
            $stmt = $db->prepare("
                SELECT user_id, DATE(created_at) as day, SUM(amount) as total, COUNT(*) as count
                FROM earnings
                WHERE status = 'cleared'
                AND type = 'download_reward'
                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY user_id, DATE(created_at)
            ");
            $stmt->execute([$daysOld]);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $db->beginTransaction();

                $ins = $db->prepare("
                    INSERT INTO earnings (user_id, amount, type, status, description, created_at)
                    VALUES (?, ?, 'aggregate_summary', 'cleared', ?, ?)
                ");
                $ins->execute([
                    $row['user_id'],
                    $row['total'],
                    "Daily Rollup ({$row['count']} downloads)",
                    $row['day'] . " 00:00:00",
                ]);

                $del = $db->prepare("
                    DELETE FROM earnings
                    WHERE user_id = ?
                    AND type = 'download_reward'
                    AND DATE(created_at) = ?
                    AND status = 'cleared'
                ");
                $del->execute([$row['user_id'], $row['day']]);

                $db->commit();
                $processed++;
            }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Rewards Rollup Failed: " . $e->getMessage());
        }

        return $processed;
    }

    public function aggregateOldEarnings(): void
    {
        $this->rollupHistory(self::retentionDays());
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `reward_receipts` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `file_id` BIGINT UNSIGNED NOT NULL,
                `session_id` BIGINT UNSIGNED NULL,
                `source_event_key` VARCHAR(191) NULL,
                `user_id` BIGINT UNSIGNED NULL,
                `downloader_user_id` BIGINT UNSIGNED NULL,
                `ip_address` TEXT NOT NULL,
                `ip_hash` VARCHAR(64) NOT NULL,
                `processing_token` VARCHAR(64) NULL,
                `processing_started_at` DATETIME NULL,
                `status` ENUM('pending', 'flagged', 'processed') NOT NULL DEFAULT 'pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `status_idx` (`status`),
                INDEX `receipt_source_event_idx` (`source_event_key`),
                INDEX `receipt_guard_idx` (`user_id`, `file_id`, `ip_hash`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `session_id` BIGINT UNSIGNED NULL AFTER `file_id`");
            $db->exec("ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `source_event_key` VARCHAR(191) NULL AFTER `session_id`");
            $db->exec("ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `downloader_user_id` BIGINT UNSIGNED NULL AFTER `user_id`");
            $db->exec("ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `ip_hash` VARCHAR(64) NOT NULL DEFAULT '' AFTER `ip_address`");
            $db->exec("ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `processing_token` VARCHAR(64) NULL AFTER `ip_hash`");
            $db->exec("ALTER TABLE `reward_receipts` ADD COLUMN IF NOT EXISTS `processing_started_at` DATETIME NULL AFTER `processing_token`");
            $db->exec("ALTER TABLE `reward_receipts` ADD INDEX IF NOT EXISTS `receipt_source_event_idx` (`source_event_key`)");
            $db->exec("ALTER TABLE `reward_receipts` ADD INDEX IF NOT EXISTS `receipt_processing_idx` (`status`, `processing_token`, `processing_started_at`, `id`)");
            $db->exec("ALTER TABLE `reward_receipts` ADD UNIQUE INDEX IF NOT EXISTS `receipt_source_event_unique` (`source_event_key`)");
            $db->exec("ALTER TABLE `reward_receipts` ADD UNIQUE INDEX IF NOT EXISTS `receipt_session_unique` (`session_id`)");
            $db->exec("ALTER TABLE `earnings` ADD COLUMN IF NOT EXISTS `ip_hash` VARCHAR(64) NULL AFTER `file_id`");
        } catch (\Throwable $e) {
            // Schema self-heal is best-effort.
        }

        self::$schemaEnsured = true;
    }

    private function markReceipt(int $id, string $status): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("
            UPDATE reward_receipts
            SET status = ?, processing_token = NULL, processing_started_at = NULL
            WHERE id = ?
        ")->execute([$status, $id]);
    }

    private function releaseClaimedReceipt(int $id): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("
            UPDATE reward_receipts
            SET processing_token = NULL, processing_started_at = NULL
            WHERE id = ? AND status = 'pending'
        ")->execute([$id]);
    }

    private function isFileRewardEligible(array $file): bool
    {
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
        $stmt->execute([(int)$file['user_id']]);
        $row = $stmt->fetch();

        if (!$row || (int)($row['ppd_enabled'] ?? 0) !== 1) {
            return false;
        }

        return in_array((string)($row['monetization_model'] ?? 'ppd'), ['ppd', 'mixed'], true);
    }

    private function countRecentIpRewards(int $userId, string $ipHash, string $visitorCookieHash = ''): int
    {
        $db = Database::getInstance()->getConnection();
        if ($visitorCookieHash !== '') {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM reward_receipts
                WHERE user_id = ?
                AND (ip_hash = ? OR visitor_cookie_hash = ?)
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND status = 'processed'
            ");
            $stmt->execute([$userId, $ipHash, $visitorCookieHash]);
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM earnings
                WHERE user_id = ?
                AND ip_hash = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$userId, $ipHash]);
        }
        return (int)$stmt->fetchColumn();
    }

    private function hasProcessedReceiptForWindow(int $userId, int $fileId, string $ipHash, int $receiptId, string $visitorCookieHash = '', string $uaHash = ''): bool
    {
        $db = Database::getInstance()->getConnection();
        $clauses = [
            "user_id = ?",
            "file_id = ?",
            "status = 'processed'",
            "id < ?",
            "created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        ];
        $params = [$userId, $fileId, $receiptId];
        $dedupe = ["ip_hash = ?"];
        $params[] = $ipHash;
        if ($visitorCookieHash !== '') {
            $dedupe[] = "visitor_cookie_hash = ?";
            $params[] = $visitorCookieHash;
        }
        if ($uaHash !== '') {
            $dedupe[] = "ua_hash = ?";
            $params[] = $uaHash;
        }
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM reward_receipts
            WHERE " . implode(' AND ', $clauses) . "
              AND (" . implode(' OR ', $dedupe) . ")
        ");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function sumRecentEarnings(int $userId, ?string $ipHash, ?int $fileId): float
    {
        $db = Database::getInstance()->getConnection();
        $clauses = ["user_id = ?", "created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"];
        $params = [$userId];

        if ($ipHash !== null) {
            $clauses[] = "ip_hash = ?";
            $params[] = $ipHash;
        }

        if ($fileId !== null) {
            $clauses[] = "file_id = ?";
            $params[] = $fileId;
        }

        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM earnings WHERE " . implode(' AND ', $clauses));
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    private function sumRecentUserEarnings(int $userId): float
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM earnings
            WHERE user_id = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$userId]);
        return (float)$stmt->fetchColumn();
    }

    private function calculateReward(array $file, string $ip): float
    {
        $ratePer1000 = $this->resolvePpdRateForIp($ip);
        if ($ratePer1000 <= 0) {
            return 0.0;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT monetization_model FROM users WHERE id = ?");
        $stmt->execute([(int)$file['user_id']]);
        $model = (string)($stmt->fetchColumn() ?: 'ppd');

        if ($model === 'pps') {
            return 0.0;
        }

        if ($model === 'mixed') {
            $mixedPercent = max(0, min(100, (int)Setting::get('mixed_ppd_percent', '30')));
            $ratePer1000 *= ($mixedPercent / 100);
        }

        return round($ratePer1000 / 1000, 4);
    }

    private function resolvePpdRateForIp(string $ip): float
    {
        $countryCode = $this->resolveCountryCode($ip);
        $db = Database::getInstance()->getConnection();

        if ($countryCode !== null) {
            $stmt = $db->prepare("
                SELECT t.rate_per_1000
                FROM ppd_tiers t
                INNER JOIN ppd_tier_countries c ON c.tier_id = t.id
                WHERE c.country_code = ?
                ORDER BY t.rate_per_1000 DESC
                LIMIT 1
            ");
            $stmt->execute([$countryCode]);
            $rate = $stmt->fetchColumn();
            if ($rate !== false) {
                return (float)$rate;
            }
        }

        $stmt = $db->query("
            SELECT t.rate_per_1000
            FROM ppd_tiers t
            LEFT JOIN ppd_tier_countries c ON c.tier_id = t.id
            GROUP BY t.id, t.rate_per_1000
            HAVING COUNT(c.country_code) = 0
            ORDER BY t.rate_per_1000 DESC
            LIMIT 1
        ");
        $fallbackTierRate = $stmt->fetchColumn();
        if ($fallbackTierRate !== false) {
            return (float)$fallbackTierRate;
        }

        return (float)Setting::get('ppd_rate_per_1000', '1.00');
    }

    private function resolveCountryCode(string $ip): ?string
    {
        $url = "https://ip-api.com/json/{$ip}?fields=countryCode";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Fyuhls/Rewards');
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        $countryCode = strtoupper((string)($data['countryCode'] ?? ''));
        return preg_match('/^[A-Z]{2}$/', $countryCode) ? $countryCode : null;
    }

    private function updateDailyStats(int $userId, float $amount, string $status = 'cleared'): void
    {
        if ($status !== 'cleared') {
            return;
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO stats_daily (user_id, day, downloads, earnings)
            VALUES (?, CURDATE(), 1, ?)
            ON DUPLICATE KEY UPDATE downloads = downloads + 1, earnings = earnings + VALUES(earnings)
        ");
        $stmt->execute([$userId, $amount]);
    }

    private function hashIp(string $ip): string
    {
        return hash_hmac('sha256', SecurityService::normalizeIp($ip), Config::get('app_key', 'change_this_to_a_random_string'));
    }

    private function resolveReceiptSignals(RewardFraudService $fraud, array $context, string $ip): array
    {
        $hasProvidedSignals = false;
        foreach ([
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
        ] as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== null && $context[$key] !== '') {
                $hasProvidedSignals = true;
                break;
            }
        }

        if (!$hasProvidedSignals) {
            return $fraud->buildClientSignals([
                'timezone_offset' => $context['timezone_offset'] ?? null,
                'platform_bucket' => $context['platform_bucket'] ?? '',
                'screen_bucket' => $context['screen_bucket'] ?? '',
                'asn' => $context['asn'] ?? '',
                'network_type' => $context['network_type'] ?? '',
            ], $ip);
        }

        return [
            'ip_hash' => (string)($context['ip_hash'] ?? $this->hashIp($ip)),
            'ua_hash' => isset($context['ua_hash']) && $context['ua_hash'] !== '' ? (string)$context['ua_hash'] : null,
            'visitor_cookie_hash' => isset($context['visitor_cookie_hash']) && $context['visitor_cookie_hash'] !== '' ? (string)$context['visitor_cookie_hash'] : null,
            'accept_language_hash' => isset($context['accept_language_hash']) && $context['accept_language_hash'] !== '' ? (string)$context['accept_language_hash'] : null,
            'timezone_offset' => isset($context['timezone_offset']) && $context['timezone_offset'] !== '' ? (int)$context['timezone_offset'] : null,
            'platform_bucket' => isset($context['platform_bucket']) && $context['platform_bucket'] !== '' ? substr((string)$context['platform_bucket'], 0, 64) : null,
            'screen_bucket' => isset($context['screen_bucket']) && $context['screen_bucket'] !== '' ? substr((string)$context['screen_bucket'], 0, 32) : null,
            'asn' => isset($context['asn']) && $context['asn'] !== '' ? substr((string)$context['asn'], 0, 64) : null,
            'network_type' => isset($context['network_type']) && $context['network_type'] !== '' ? substr((string)$context['network_type'], 0, 32) : null,
            'country_code' => isset($context['country_code']) && preg_match('/^[A-Z]{2}$/', strtoupper((string)$context['country_code'])) ? strtoupper((string)$context['country_code']) : null,
        ];
    }

    private function acquireReceiptLock(\PDO $db, string $sourceEventKey, ?int $sessionId)
    {
        $lockSeed = $sourceEventKey !== ''
            ? 'source:' . $sourceEventKey
            : ($sessionId !== null && $sessionId > 0 ? 'session:' . $sessionId : '');
        if ($lockSeed === '') {
            return null;
        }

        $lockKey = 'fyuhls_reward_receipt_' . hash('sha256', $lockSeed);
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute([$lockKey]);
        return (int)$stmt->fetchColumn() === 1 ? $lockKey : false;
    }

    private function releaseReceiptLock(\PDO $db, string $lockKey): void
    {
        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockKey]);
        } catch (\Throwable $e) {
        }
    }

    private function claimPendingReceipts(int $batchSize): array
    {
        $db = Database::getInstance()->getConnection();
        $token = bin2hex(random_bytes(16));

        $stmt = $db->prepare("
            UPDATE reward_receipts
            SET processing_token = ?, processing_started_at = NOW()
            WHERE status = 'pending'
              AND (processing_token IS NULL OR processing_started_at < DATE_SUB(NOW(), INTERVAL " . self::CLAIM_TTL_MINUTES . " MINUTE))
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->execute([$token, $batchSize]);

        if ($stmt->rowCount() <= 0) {
            return [];
        }

        $select = $db->prepare("SELECT * FROM reward_receipts WHERE processing_token = ? ORDER BY id ASC");
        $select->execute([$token]);
        return $select->fetchAll() ?: [];
    }
}
