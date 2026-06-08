<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Model\File;
use App\Model\Setting;
use App\Service\Database\SchemaService;

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

    public static function prepareFileEarningsReversalRuntime(): void
    {
        $service = new self();
        $service->ensureSchema();
        (new RewardFraudService())->ensureSchema();
    }

    public static function reverseFileEarnings(int $fileId, ?int $reviewerId = null, string $reason = ''): array
    {
        if ($fileId <= 0 || !FeatureService::rewardsEnabled()) {
            return ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
        }

        $service = new self();
        $service->ensureSchema();
        (new RewardFraudService())->ensureSchema();

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT id, user_id
                FROM files
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();
            if (!$file) {
                $db->rollBack();
                return ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
            }

            $result = self::reverseFileEarningsWithinTransaction(
                $db,
                (int)$fileId,
                (int)($file['user_id'] ?? 0),
                $reviewerId,
                $reason
            );
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if (($result['user_ids'] ?? []) !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($result['user_ids'], true, [
                'workflow' => 'reverse_file_earnings',
                'file_id' => $fileId,
            ]);
        }

        return $result;
    }

    public static function reverseFileEarningsWithinTransaction(\PDO $db, int $fileId, int $ownerId, ?int $reviewerId = null, string $reason = ''): array
    {
        if ($fileId <= 0 || $ownerId <= 0 || !FeatureService::rewardsEnabled()) {
            return ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
        }

        ReviewIntegrityService::assertNotSelfRewardReview($reviewerId, $ownerId);

        $service = new self();
        if (!$db->inTransaction()) {
            $service->ensureSchema();
            (new RewardFraudService())->ensureSchema();
        }

        $lockKey = $service->acquireProcessingLock($db, $ownerId);
        if ($lockKey === false) {
            throw new \RuntimeException('Could not acquire reward processing lock for this file deletion.');
        }

        try {
            $service->assertNoAmbiguousHistoricalRewards($db, $fileId, $ownerId);

            $note = trim($reason);
            $baseNote = 'Removed because staff deleted the source file.';
            $reviewNote = $note !== '' ? ($baseNote . ' Reason: ' . $note) : $baseNote;

            $db->prepare("
                UPDATE reward_receipts
                SET status = 'processed',
                    reward_counted = 0,
                    risk_level = 'not_counted',
                    risk_reasons_json = ?,
                    processing_token = NULL,
                    processing_started_at = NULL
                WHERE file_id = ?
                  AND user_id = ?
                  AND status = 'pending'
            ")->execute([
                json_encode([$baseNote], JSON_UNESCAPED_SLASHES),
                $fileId,
                $ownerId,
            ]);

            $stmt = $db->prepare("
                SELECT id, user_id, file_id, session_id, parent_earning_id, type, amount, ip_hash, risk_score,
                       risk_reasons_json, review_note, country_code, network_type, asn, metadata, status, created_at
                FROM earnings
                WHERE file_id = ?
                  AND type IN ('download_reward', 'aggregate_summary')
                  AND status IN ('pending', 'held', 'flagged_review', 'cleared', 'paid')
                FOR UPDATE
            ");
            $stmt->execute([$fileId]);
            $rows = $stmt->fetchAll() ?: [];

            if ($rows === []) {
                return ['count' => 0, 'amount' => 0.0, 'user_ids' => []];
            }

            $update = $db->prepare("
                UPDATE earnings
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?, hold_until = NULL
                WHERE id = ? AND status = ?
            ");

            $affectedCount = 0;
            $affectedAmount = 0.0;
            $touchUserIds = [];
            $dayAdjustments = [];

            foreach ($rows as $row) {
                $currentStatus = (string)($row['status'] ?? '');
                $reversalCreated = false;

                if (in_array($currentStatus, ['pending', 'held', 'flagged_review'], true)) {
                    $targetStatus = 'cancelled';
                    $update->execute([
                        $targetStatus,
                        $reviewerId,
                        $reviewNote,
                        (int)$row['id'],
                        $currentStatus,
                    ]);

                    if ($update->rowCount() !== 1) {
                        continue;
                    }

                    $parentEarningId = (int)($row['id'] ?? 0);
                    if ($parentEarningId > 0) {
                        AffiliateRewardService::syncReferralChildrenForParent($db, $parentEarningId, $targetStatus);
                    }
                } else {
                    $reversal = self::ensureLedgerReversalEntry(
                        $db,
                        $row,
                        $reviewNote,
                        $reviewerId,
                        [
                            'source' => 'file_delete',
                            'source_file_id' => $fileId,
                        ]
                    );
                    if (($reversal['id'] ?? 0) <= 0) {
                        continue;
                    }
                    $reversalCreated = !empty($reversal['created']);
                    $touchUserIds = array_merge(
                        $touchUserIds,
                        AffiliateRewardService::reverseReferralChildrenForParent($db, (int)$row['id'], $reviewNote, $reviewerId)
                    );
                }

                if ($reversalCreated && in_array($currentStatus, ['cleared', 'paid'], true)) {
                    $day = date('Y-m-d', strtotime((string)($row['created_at'] ?? 'now')));
                    $downloadCountDelta = self::earningDownloadCountForStats($row);
                    if (!isset($dayAdjustments[$day])) {
                        $dayAdjustments[$day] = ['downloads' => 0, 'earnings' => 0.0];
                    }
                    $dayAdjustments[$day]['downloads'] += $downloadCountDelta;
                    $dayAdjustments[$day]['earnings'] += (float)($row['amount'] ?? 0);
                }

                $affectedCount++;
                $affectedAmount += (float)($row['amount'] ?? 0);
                $touchUserIds[] = (int)($row['user_id'] ?? 0);
            }

            if ($dayAdjustments !== []) {
                $adjustStats = $db->prepare("
                    UPDATE stats_daily
                    SET downloads = GREATEST(0, downloads - ?),
                        earnings = GREATEST(0, earnings - ?)
                    WHERE user_id = ? AND day = ?
                ");
                foreach ($dayAdjustments as $day => $delta) {
                    $adjustStats->execute([
                        (int)$delta['downloads'],
                        round((float)$delta['earnings'], 4),
                        $ownerId,
                        $day,
                    ]);
                }
            }

            $touchUserIds = array_values(array_unique(array_filter($touchUserIds, static fn (int $id): bool => $id > 0)));

            return [
                'count' => $affectedCount,
                'amount' => round($affectedAmount, 4),
                'user_ids' => $touchUserIds,
            ];
        } finally {
            $service->releaseReceiptLock($db, $lockKey);
        }
    }

    public static function ensureLedgerReversalEntry(\PDO $db, array $earning, string $reason, ?int $reviewerId = null, array $extraMetadata = []): array
    {
        $earningId = (int)($earning['id'] ?? 0);
        $currentStatus = strtolower(trim((string)($earning['status'] ?? '')));
        $amount = round((float)($earning['amount'] ?? 0), 4);
        if ($earningId <= 0 || $amount <= 0 || !in_array($currentStatus, ['cleared', 'paid'], true)) {
            return ['id' => 0, 'created' => false];
        }

        $metadata = self::decodeEarningMetadata($earning['metadata'] ?? null);
        $existingId = (int)($metadata['ledger_reversal_entry_id'] ?? 0);
        if ($existingId > 0) {
            return ['id' => $existingId, 'created' => false];
        }

        $reason = trim($reason);
        $description = 'Ledger reversal for earning #' . $earningId . ': ' . $reason;
        $existingStmt = $db->prepare("
            SELECT id
            FROM earnings
            WHERE parent_earning_id = ?
              AND type = ?
              AND amount = ?
              AND description = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $existingStmt->execute([
            $earningId,
            (string)($earning['type'] ?? ''),
            -abs($amount),
            $description,
        ]);
        $existingReversalId = (int)($existingStmt->fetchColumn() ?: 0);

        if ($existingReversalId <= 0) {
            $reversalMetadata = array_merge([
                'kind' => 'ledger_reversal',
                'reversal_of_earning_id' => $earningId,
                'original_status' => $currentStatus,
                'source_parent_earning_id' => isset($earning['parent_earning_id']) ? (int)$earning['parent_earning_id'] : null,
            ], $extraMetadata);

            $insert = $db->prepare("
                INSERT INTO earnings (
                    user_id, file_id, session_id, parent_earning_id, type, amount, ip_hash, risk_score,
                    risk_reasons_json, hold_until, reviewed_by, reviewed_at, review_note, country_code,
                    network_type, asn, status, description, metadata, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
                )
            ");
            $insert->execute([
                (int)($earning['user_id'] ?? 0),
                isset($earning['file_id']) ? (int)$earning['file_id'] : null,
                isset($earning['session_id']) ? (int)$earning['session_id'] : null,
                $earningId,
                (string)($earning['type'] ?? ''),
                -abs($amount),
                $earning['ip_hash'] ?? null,
                (int)($earning['risk_score'] ?? 0),
                $earning['risk_reasons_json'] ?? null,
                $reviewerId,
                $reason,
                $earning['country_code'] ?? null,
                $earning['network_type'] ?? null,
                $earning['asn'] ?? null,
                $currentStatus,
                $description,
                json_encode($reversalMetadata, JSON_UNESCAPED_SLASHES),
            ]);
            $existingReversalId = (int)$db->lastInsertId();
        }

        $metadata['ledger_reversal_entry_id'] = $existingReversalId;
        $metadata['ledger_reversed_at'] = gmdate('Y-m-d H:i:s');
        $metadata['ledger_reversal_reason'] = $reason;

        $updateOriginal = $db->prepare("
            UPDATE earnings
            SET reviewed_by = COALESCE(?, reviewed_by),
                reviewed_at = CURRENT_TIMESTAMP,
                review_note = ?,
                metadata = ?
            WHERE id = ?
        ");
        $updateOriginal->execute([
            $reviewerId,
            $reason,
            json_encode($metadata, JSON_UNESCAPED_SLASHES),
            $earningId,
        ]);

        return ['id' => $existingReversalId, 'created' => $existingId <= 0];
    }

    public static function decodeEarningMetadata($rawMetadata): array
    {
        if (is_array($rawMetadata)) {
            return $rawMetadata;
        }
        if (!is_string($rawMetadata) || trim($rawMetadata) === '') {
            return [];
        }
        $decoded = json_decode($rawMetadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function earningDownloadCountForStats(array $earning): int
    {
        if ((string)($earning['type'] ?? '') !== 'aggregate_summary') {
            return 1;
        }

        $metadata = self::decodeEarningMetadata($earning['metadata'] ?? null);
        return max(1, (int)($metadata['rolled_up_reward_count'] ?? 1));
    }

    private function assertNoAmbiguousHistoricalRewards(\PDO $db, int $fileId, int $ownerId): void
    {
        $this->adoptSafeLegacyAggregateSummaries($db, $fileId, $ownerId);

        $legacySummaryStmt = $db->prepare("
            SELECT COUNT(*)
            FROM earnings
            WHERE user_id = ?
              AND file_id IS NULL
              AND type = 'aggregate_summary'
              AND status IN ('cleared', 'paid')
              AND DATE(created_at) IN (
                    SELECT DISTINCT DATE(created_at)
                    FROM reward_receipts
                    WHERE file_id = ?
                      AND user_id = ?
                      AND reward_counted = 1
                )
        ");
        $legacySummaryStmt->execute([$ownerId, $fileId, $ownerId]);
        if ((int)$legacySummaryStmt->fetchColumn() > 0) {
            throw new \RuntimeException(
                'This file has older rolled-up cleared rewards. Fyuhls cannot safely remove them from the file delete flow after historical rollup.'
            );
        }
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
            $proxyIntel = $this->resolveReceiptProxyIntel($context, $ip);

            $stmt = $db->prepare("
                INSERT INTO reward_receipts (
                    file_id, session_id, source_event_key, user_id, downloader_user_id, ip_address, ip_hash, ua_hash,
                    visitor_cookie_hash, accept_language_hash, timezone_offset, platform_bucket,
                    screen_bucket, asn, network_type, country_code, proof_status,
                    proxy_intel_risk_score, proxy_intel_type, proxy_intel_provider, proxy_intel_last_seen
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $proxyIntel['risk'],
                $proxyIntel['type'],
                $proxyIntel['provider'],
                $proxyIntel['last_seen'],
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
        $bonusTouchUserIds = [];

        try {
            $receipts = $this->claimPendingReceipts($batchSize);

            if (empty($receipts)) {
                return $results;
            }

            $ipLimit = max(1, (int)Setting::get('ppd_ip_reward_limit', '1'));
            $minSize = (int)Setting::get('ppd_min_file_size', '1048576');
            $maxSize = (int)Setting::get('ppd_max_file_size', '0');
            $onlyGuestsCount = Setting::get('ppd_only_guests_count', '0') === '1'
                || Setting::get('rewards_ppd_guests_only', '0') === '1';
            $rewardVpnTraffic = Setting::get('ppd_reward_vpn', '0') === '1';
            $maxEarnIp = (float)Setting::get('ppd_max_earn_ip', '0');
            $maxEarnFile = (float)Setting::get('ppd_max_earn_file', '0');
            $maxEarnUser = (float)Setting::get('ppd_max_earn_user', '0');
            $security = new SecurityService();
            $fraud = new RewardFraudService();

            foreach ($receipts as $receipt) {
                $processingLockKey = null;
                try {
                    $results['processed']++;

                    $receiptId = (int)$receipt['id'];
                    $fileId = (int)$receipt['file_id'];
                    $ownerId = (int)($receipt['user_id'] ?? 0);
                    $downloaderUserId = isset($receipt['downloader_user_id']) ? (int)$receipt['downloader_user_id'] : null;
                    $ip = EncryptionService::decrypt($receipt['ip_address']);
                    $ipHash = (string)($receipt['ip_hash'] ?? $this->hashIp($ip));
                    $processingLockKey = $this->acquireProcessingLock($db, $ownerId);
                    if ($processingLockKey === false) {
                        throw new \RuntimeException('Could not acquire reward processing lock.');
                    }

                    $file = File::find($fileId);
                    if (!$file || !$file['user_id'] || (int)$file['user_id'] !== $ownerId || !$this->isFileRewardEligible($file)) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'This file is no longer eligible for rewards.',
                        ]);
                        continue;
                    }

                    if ($downloaderUserId !== null && $downloaderUserId === $ownerId) {
                        $this->markReceiptWithReasons($receiptId, 'flagged', [
                            'Uploader attempted to credit their own file.',
                        ], 'high');
                        $results['flagged']++;
                        continue;
                    }

                    if ($onlyGuestsCount && $downloaderUserId !== null) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'Logged-in downloader traffic does not count because PPD is currently limited to guests only.',
                        ]);
                        continue;
                    }

                    if ($file['file_size'] < $minSize || ($maxSize > 0 && $file['file_size'] > $maxSize)) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'File size fell outside the configured rewardable range.',
                        ]);
                        continue;
                    }

                    if (!$rewardVpnTraffic) {
                        $proxyIntel = $security->lookupProxyIntel($ip);
                        if (!empty($proxyIntel['is_proxy'])) {
                            $this->markReceiptWithReasons($receiptId, 'flagged', [
                                'Download came from a VPN or proxy while VPN/proxy reward counting is disabled.',
                            ], 'high');
                            $results['flagged']++;
                            continue;
                        }

                        if (SecurityService::proxyIntelRequiresFailClosed($proxyIntel)) {
                            $this->markReceiptWithReasons($receiptId, 'processed', [
                                'Proxy/VPN verification was unavailable while VPN/proxy reward counting is disabled, so this receipt was not counted.',
                            ]);
                            continue;
                        }
                    }

                    if ($this->hasProcessedReceiptForWindow($ownerId, $fileId, $ipHash, $receiptId, (string)($receipt['visitor_cookie_hash'] ?? ''), (string)($receipt['ua_hash'] ?? ''))) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'Duplicate visitor activity was detected inside the 24-hour reward window.',
                        ]);
                        continue;
                    }

                    if ($this->countRecentIpRewards($ownerId, $ipHash, (string)($receipt['visitor_cookie_hash'] ?? '')) >= $ipLimit) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'The daily reward limit for this IP or visitor signature has already been reached.',
                        ]);
                        continue;
                    }

                    $amount = $this->calculateReward($file, $ip);
                    if ($amount <= 0) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'No active reward tier matched this download under the current payout configuration.',
                        ]);
                        continue;
                    }

                    if ($maxEarnIp > 0 && ($this->sumRecentEarnings($ownerId, $ipHash, null) + $amount) > $maxEarnIp) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'The per-IP daily earnings cap has already been reached.',
                        ]);
                        continue;
                    }

                    if ($maxEarnFile > 0 && ($this->sumRecentEarnings($ownerId, null, $fileId) + $amount) > $maxEarnFile) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'The per-file daily earnings cap has already been reached.',
                        ]);
                        continue;
                    }

                    if ($maxEarnUser > 0 && ($this->sumRecentUserEarnings($ownerId) + $amount) > $maxEarnUser) {
                        $this->markReceiptWithReasons($receiptId, 'processed', [
                            'The uploader daily earnings cap has already been reached.',
                        ]);
                        continue;
                    }

                    $risk = $fraud->evaluateReceipt($receipt, $file);
                    $disposition = $fraud->decideEarningDisposition($risk, $ownerId);
                    $earningStatus = (string)($disposition['status'] ?? 'held');
                    $holdUntil = $disposition['hold_until'] ?? null;

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
                        match ($earningStatus) {
                            'flagged_review' => 'PPD Reward (Flagged for review)',
                            'held' => 'PPD Reward (Held for review)',
                            'reversed' => 'PPD Reward (Auto-reversed)',
                            'cleared' => 'PPD Reward (Auto-cleared)',
                            default => 'PPD Reward',
                        },
                        $receipt['country_code'] ?? null,
                        $receipt['network_type'] ?? null,
                        $receipt['asn'] ?? null,
                    ]);
                    $earningId = (int)$db->lastInsertId();

                    $referrerId = AffiliateRewardService::awardReferralForUserEarning(
                        $db,
                        $ownerId,
                        $amount,
                        $earningId,
                        $earningStatus,
                        $holdUntil,
                        'PPD reward'
                    );

                    $this->updateDailyStats($ownerId, $amount, $earningStatus);
                    $db->prepare("UPDATE reward_receipts SET risk_score = ?, risk_level = ?, risk_reasons_json = ?, proof_status = ? WHERE id = ?")
                        ->execute([
                        $risk['score'],
                        $risk['level'],
                        json_encode($risk['reasons'], JSON_UNESCAPED_SLASHES),
                        $receipt['proof_status'] ?? 'legacy',
                        $receiptId,
                    ]);
                    if (!empty($disposition['system_note'])) {
                        $db->prepare("UPDATE earnings SET review_note = ? WHERE id = ?")
                            ->execute([(string)$disposition['system_note'], $earningId]);
                    }
                    $this->markReceipt($receiptId, 'processed', true);
                    $db->commit();

                    $bonusTouchUserIds[] = $ownerId;
                    if ($referrerId !== null && $referrerId > 0) {
                        $bonusTouchUserIds[] = $referrerId;
                    }

                    $results['credited']++;
                } catch (\Throwable $ex) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $this->releaseClaimedReceipt((int)$receipt['id']);
                    $results['errors'][] = "Receipt #{$receipt['id']}: " . $ex->getMessage();
                } finally {
                    if (is_string($processingLockKey) && $processingLockKey !== '') {
                        $this->releaseReceiptLock($db, $processingLockKey);
                    }
                }
            }
        } catch (\Throwable $e) {
            $results['errors'][] = "Global Error: " . $e->getMessage();
        }

        if ($bonusTouchUserIds !== []) {
            BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'process_reward_receipts',
            ]);
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
                SELECT file_id, DATE(created_at) as day, SUM(amount) as total, COUNT(*) as count
                FROM earnings
                WHERE user_id = ?
                AND status = 'cleared'
                AND type = 'download_reward'
                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY file_id, DATE(created_at)
            ");
            $stmt->execute([$userId, $daysOld]);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return 0;
            }

            $processed = 0;
            foreach ($rows as $row) {
                if ($this->rollupDayForUserFile($db, (int)$userId, (int)($row['file_id'] ?? 0), (string)$row['day'], 'JIT Rollup')) {
                    $processed++;
                }
            }
            return $processed;
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
                SELECT user_id, file_id, DATE(created_at) as day, SUM(amount) as total, COUNT(*) as count
                FROM earnings
                WHERE status = 'cleared'
                AND type = 'download_reward'
                AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY user_id, file_id, DATE(created_at)
            ");
            $stmt->execute([$daysOld]);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                if ($this->rollupDayForUserFile($db, (int)$row['user_id'], (int)($row['file_id'] ?? 0), (string)$row['day'], 'Daily Rollup')) {
                    $processed++;
                }
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

    private function rollupDayForUserFile(\PDO $db, int $userId, int $fileId, string $day, string $label): bool
    {
        if ($userId <= 0 || $fileId <= 0) {
            return false;
        }

        $lockKey = $this->rollupLockKey($userId, $fileId, $day);
        if (!$this->acquireRollupLock($db, $lockKey)) {
            throw new \RuntimeException('Could not acquire rewards rollup lock.');
        }

        try {
            $db->beginTransaction();

            $sourceStmt = $db->prepare("
                SELECT id, amount
                FROM earnings
                WHERE user_id = ?
                  AND file_id = ?
                  AND type = 'download_reward'
                  AND DATE(created_at) = ?
                  AND status = 'cleared'
                ORDER BY id ASC
                FOR UPDATE
            ");
            $sourceStmt->execute([$userId, $fileId, $day]);
            $sourceRows = $sourceStmt->fetchAll();
            if (empty($sourceRows)) {
                $db->commit();
                return false;
            }

            $total = 0.0;
            foreach ($sourceRows as $sourceRow) {
                $total += (float)($sourceRow['amount'] ?? 0);
            }
            $total = round($total, 4);
            $description = $this->aggregateSummaryDescription($label, $day, $fileId);
            $summaryCreatedAt = $day . ' 00:00:00';
            $summaryMetadata = json_encode([
                'kind' => 'aggregate_summary',
                'rolled_up_source_type' => 'download_reward',
                'rolled_up_reward_count' => count($sourceRows),
                'rolled_up_day' => $day,
                'rolled_up_file_id' => $fileId,
            ], JSON_UNESCAPED_SLASHES);

            $summaryStmt = $db->prepare("
                SELECT id
                FROM earnings
                WHERE user_id = ?
                  AND file_id = ?
                  AND type = 'aggregate_summary'
                  AND status = 'cleared'
                  AND description = ?
                  AND created_at = ?
                ORDER BY id ASC
                FOR UPDATE
            ");
            $summaryStmt->execute([$userId, $fileId, $description, $summaryCreatedAt]);
            $summaryRows = $summaryStmt->fetchAll();

            if (!empty($summaryRows)) {
                $primarySummaryId = (int)$summaryRows[0]['id'];
                $updateSummary = $db->prepare("UPDATE earnings SET amount = ?, metadata = ? WHERE id = ?");
                $updateSummary->execute([$total, $summaryMetadata, $primarySummaryId]);

                if (count($summaryRows) > 1) {
                    $duplicateIds = array_map(static fn(array $row): int => (int)$row['id'], array_slice($summaryRows, 1));
                    $this->deleteEarningsByIds($db, $duplicateIds);
                }
            } else {
                $insertSummary = $db->prepare("
                    INSERT INTO earnings (user_id, file_id, amount, type, status, description, metadata, created_at)
                    VALUES (?, ?, ?, 'aggregate_summary', 'cleared', ?, ?, ?)
                ");
                $insertSummary->execute([
                    $userId,
                    $fileId,
                    $total,
                    $description,
                    $summaryMetadata,
                    $summaryCreatedAt,
                ]);
            }

            $sourceIds = array_map(static fn(array $row): int => (int)$row['id'], $sourceRows);
            $this->deleteEarningsByIds($db, $sourceIds);

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        } finally {
            $this->releaseRollupLock($db, $lockKey);
        }
    }

    private function deleteEarningsByIds(\PDO $db, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM earnings WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    }

    private function aggregateSummaryDescription(string $label, string $day, int $fileId): string
    {
        return $label . ' [' . $day . '] file #' . $fileId;
    }

    private function adoptSafeLegacyAggregateSummaries(\PDO $db, int $fileId, int $ownerId): void
    {
        if ($fileId <= 0 || $ownerId <= 0) {
            return;
        }

        $dayStmt = $db->prepare("
            SELECT DISTINCT DATE(created_at) AS day
            FROM reward_receipts
            WHERE file_id = ?
              AND user_id = ?
              AND reward_counted = 1
        ");
        $dayStmt->execute([$fileId, $ownerId]);
        $days = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['day'] ?? ''), $dayStmt->fetchAll() ?: [])));
        if ($days === []) {
            return;
        }

        $summaryLookup = $db->prepare("
            SELECT id, amount, status
            FROM earnings
            WHERE user_id = ?
              AND file_id IS NULL
              AND type = 'aggregate_summary'
              AND DATE(created_at) = ?
            ORDER BY id ASC
            FOR UPDATE
        ");
        $receiptScope = $db->prepare("
            SELECT COUNT(DISTINCT file_id)
            FROM reward_receipts
            WHERE user_id = ?
              AND reward_counted = 1
              AND DATE(created_at) = ?
        ");
        $downloadCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM reward_receipts
            WHERE user_id = ?
              AND file_id = ?
              AND reward_counted = 1
              AND DATE(created_at) = ?
        ");
        $sourceCheck = $db->prepare("
            SELECT COUNT(*)
            FROM earnings
            WHERE user_id = ?
              AND type = 'download_reward'
              AND DATE(created_at) = ?
        ");
        $updateSummary = $db->prepare("
            UPDATE earnings
            SET file_id = ?, description = ?, metadata = ?
            WHERE id = ?
        ");

        foreach ($days as $day) {
            $receiptScope->execute([$ownerId, $day]);
            if ((int)$receiptScope->fetchColumn() !== 1) {
                continue;
            }

            $sourceCheck->execute([$ownerId, $day]);
            if ((int)$sourceCheck->fetchColumn() > 0) {
                continue;
            }

            $summaryLookup->execute([$ownerId, $day]);
            $summaryRows = $summaryLookup->fetchAll() ?: [];
            if (count($summaryRows) !== 1) {
                continue;
            }

            $downloadCountStmt->execute([$ownerId, $fileId, $day]);
            $rolledUpCount = (int)$downloadCountStmt->fetchColumn();
            if ($rolledUpCount <= 0) {
                continue;
            }

            $summaryId = (int)($summaryRows[0]['id'] ?? 0);
            if ($summaryId <= 0) {
                continue;
            }

            $metadata = [
                'kind' => 'aggregate_summary',
                'rolled_up_source_type' => 'download_reward',
                'rolled_up_reward_count' => $rolledUpCount,
                'rolled_up_day' => $day,
                'rolled_up_file_id' => $fileId,
                'adopted_from_legacy_summary' => true,
            ];
            $updateSummary->execute([
                $fileId,
                $this->aggregateSummaryDescription('Legacy Rollup', $day, $fileId),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $summaryId,
            ]);
        }
    }

    private function rollupLockKey(int $userId, int $fileId, string $day): string
    {
        return 'rewards_rollup:' . $userId . ':' . $fileId . ':' . $day;
    }

    private function acquireRollupLock(\PDO $db, string $lockKey): bool
    {
        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute([$lockKey]);
        return (bool)$stmt->fetchColumn();
    }

    private function releaseRollupLock(\PDO $db, string $lockKey): void
    {
        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockKey]);
        } catch (\Throwable $e) {
        }
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        SchemaService::ensureTables(['reward_receipts', 'earnings'], false);

        self::$schemaEnsured = true;
    }

    private function markReceipt(int $id, string $status, bool $rewardCounted = false): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("
            UPDATE reward_receipts
            SET status = ?, reward_counted = ?, processing_token = NULL, processing_started_at = NULL
            WHERE id = ?
        ")->execute([$status, $rewardCounted ? 1 : 0, $id]);
    }

    private function markReceiptWithReasons(int $id, string $status, array $reasons, string $riskLevel = 'not_counted'): void
    {
        $db = Database::getInstance()->getConnection();
        $db->prepare("
            UPDATE reward_receipts
            SET status = ?, reward_counted = 0, risk_level = ?, risk_reasons_json = ?, processing_token = NULL, processing_started_at = NULL
            WHERE id = ?
        ")->execute([
            $status,
            $riskLevel,
            json_encode(array_values(array_unique(array_map('strval', $reasons))), JSON_UNESCAPED_SLASHES),
            $id,
        ]);
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
            SELECT u.monetization_model, p.ppd_enabled, p.pps_enabled
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$file['user_id']]);
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
                AND reward_counted = 1
                AND status = 'processed'
            ");
            $stmt->execute([$userId, $ipHash, $visitorCookieHash]);
        } else {
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM earnings
                WHERE user_id = ?
                AND ip_hash = ?
                AND type = 'download_reward'
                AND status IN ('pending', 'held', 'flagged_review', 'cleared', 'paid')
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
            "reward_counted = 1",
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
        $clauses = [
            "user_id = ?",
            "type = 'download_reward'",
            "status IN ('held', 'flagged_review', 'cleared', 'paid', 'pending')",
            "created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        ];
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
            AND type = 'download_reward'
            AND status IN ('held', 'flagged_review', 'cleared', 'paid', 'pending')
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
        $stmt = $db->prepare("
            SELECT u.monetization_model, p.ppd_enabled, p.pps_enabled
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$file['user_id']]);
        $row = $stmt->fetch();
        $model = (string)($row['monetization_model'] ?? 'ppd');
        $package = [
            'ppd_enabled' => (int)($row['ppd_enabled'] ?? 0),
            'pps_enabled' => (int)($row['pps_enabled'] ?? 0),
        ];

        if (!MonetizationModelService::ppdEligible($model, $package)) {
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
        $secret = SecurityService::getSecureAppKey();
        if ($secret === null) {
            throw new \RuntimeException('Rewards fraud protections require a rotated application key.');
        }

        return hash_hmac('sha256', SecurityService::normalizeIp($ip), $secret);
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

    private function resolveReceiptProxyIntel(array $context, string $ip): array
    {
        $emptyIntel = [
            'risk' => 0,
            'type' => null,
            'provider' => null,
            'last_seen' => null,
        ];

        if (!$this->shouldCaptureProxyIntel()) {
            return $emptyIntel;
        }

        if (
            !empty($context['proxy_intel_risk_score'])
            || !empty($context['proxy_intel_type'])
            || !empty($context['proxy_intel_provider'])
            || !empty($context['proxy_intel_last_seen'])
        ) {
            return [
                'risk' => max(0, min(100, (int)($context['proxy_intel_risk_score'] ?? 0))),
                'type' => isset($context['proxy_intel_type']) && $context['proxy_intel_type'] !== '' ? substr((string)$context['proxy_intel_type'], 0, 32) : null,
                'provider' => isset($context['proxy_intel_provider']) && $context['proxy_intel_provider'] !== '' ? substr((string)$context['proxy_intel_provider'], 0, 128) : null,
                'last_seen' => isset($context['proxy_intel_last_seen']) && $context['proxy_intel_last_seen'] !== '' ? substr((string)$context['proxy_intel_last_seen'], 0, 64) : null,
            ];
        }

        $intel = (new SecurityService())->lookupProxyIntel($ip);

        return [
            'risk' => max(0, min(100, (int)($intel['risk'] ?? 0))),
            'type' => isset($intel['type']) && $intel['type'] !== '' ? substr((string)$intel['type'], 0, 32) : null,
            'provider' => isset($intel['provider']) && $intel['provider'] !== '' ? substr((string)$intel['provider'], 0, 128) : null,
            'last_seen' => isset($intel['last_seen']) && $intel['last_seen'] !== '' ? substr((string)$intel['last_seen'], 0, 64) : null,
        ];
    }

    private function shouldCaptureProxyIntel(): bool
    {
        return \App\Service\SecurityService::getVpnProtectionMode() === 'intelligence'
            && trim((string)Setting::getEncrypted('proxycheck_api_key', '')) !== '';
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

    private function acquireProcessingLock(\PDO $db, int $ownerId)
    {
        if ($ownerId <= 0) {
            return null;
        }

        $lockKey = 'fyuhls_reward_process_' . $ownerId;
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
        $batchSize = max(1, min(5000, (int)$batchSize));

        $stmt = $db->prepare("
            UPDATE reward_receipts
            SET processing_token = ?, processing_started_at = NOW()
            WHERE status = 'pending'
              AND (processing_token IS NULL OR processing_started_at < DATE_SUB(NOW(), INTERVAL " . self::CLAIM_TTL_MINUTES . " MINUTE))
            ORDER BY id ASC
            LIMIT {$batchSize}
        ");
        $stmt->execute([$token]);

        if ($stmt->rowCount() <= 0) {
            return [];
        }

        $select = $db->prepare("SELECT * FROM reward_receipts WHERE processing_token = ? ORDER BY id ASC");
        $select->execute([$token]);
        return $select->fetchAll() ?: [];
    }
}
