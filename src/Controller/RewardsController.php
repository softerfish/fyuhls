<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Model\Setting;
use App\Model\User;
use App\Service\EncryptionService;
use App\Service\FeatureService;
use App\Service\MonetizationModelService;
use App\Service\PackageAllowanceService;
use App\Service\PayoutProcessorService;

class RewardsController
{
    private const STEP_UP_RATE_LIMIT = 5;
    private const STEP_UP_RATE_WINDOW = 600;

    private function humanizeReasonCode(string $reason): array
    {
        $normalized = strtolower(trim($reason));
        if ($normalized === '') {
            return ['label' => 'No reason recorded', 'detail' => 'No specific reason was stored for this traffic.'];
        }

        $map = [
            'duplicate_window' => ['Already counted recently', 'This visitor was already counted during the recent duplicate window.'],
            'already_counted_recently' => ['Already counted recently', 'This visitor was already counted during the recent duplicate window.'],
            'duplicate' => ['Already counted recently', 'This visitor was already counted during the recent duplicate window.'],
            'guest_only' => ['Guest-only traffic rule', 'This file or rule only pays for guest traffic, not signed-in downloads.'],
            'guest_only_rewards' => ['Guest-only traffic rule', 'This file or rule only pays for guest traffic, not signed-in downloads.'],
            'file_size_mismatch' => ['File did not meet size rules', 'The file did not match the size requirements for this reward rule.'],
            'size_requirement' => ['File did not meet size rules', 'The file did not match the size requirements for this reward rule.'],
            'country_not_eligible' => ['Country was not eligible', 'This traffic came from a country group that does not qualify for this reward tier.'],
            'tier_not_found' => ['No reward tier matched', 'This traffic did not match an active payout tier for the file and visitor.'],
            'no_matching_tier' => ['No reward tier matched', 'This traffic did not match an active payout tier for the file and visitor.'],
            'daily_cap_reached' => ['Daily limit reached', 'The reward cap for this period had already been reached.'],
            'limit_reached' => ['Daily limit reached', 'The reward cap for this period had already been reached.'],
            'vpn_proxy_detected' => ['Traffic quality checks blocked it', 'This traffic matched a VPN, proxy, or other quality filter.'],
            'proxy_detected' => ['Traffic quality checks blocked it', 'This traffic matched a VPN, proxy, or other quality filter.'],
            'ip_quality' => ['Traffic quality checks blocked it', 'This traffic matched a VPN, proxy, or other quality filter.'],
            'proof_failed' => ['Did not meet proof checks', 'The system did not receive enough proof that the download completed properly.'],
            'proof_requirement' => ['Did not meet proof checks', 'The system did not receive enough proof that the download completed properly.'],
            'completion_missing' => ['Did not meet proof checks', 'The system did not receive enough proof that the download completed properly.'],
        ];

        if (isset($map[$normalized])) {
            return ['label' => $map[$normalized][0], 'detail' => $map[$normalized][1]];
        }

        if (str_contains($normalized, 'duplicate') || str_contains($normalized, 'recent')) {
            return ['label' => 'Already counted recently', 'detail' => 'This visitor was already counted during the recent duplicate window.'];
        }
        if (str_contains($normalized, 'guest')) {
            return ['label' => 'Guest-only traffic rule', 'detail' => 'This file or rule only pays for guest traffic, not signed-in downloads.'];
        }
        if (str_contains($normalized, 'size')) {
            return ['label' => 'File did not meet size rules', 'detail' => 'The file did not match the size requirements for this reward rule.'];
        }
        if (str_contains($normalized, 'tier') || str_contains($normalized, 'rate')) {
            return ['label' => 'No reward tier matched', 'detail' => 'This traffic did not match an active payout tier for the file and visitor.'];
        }
        if (str_contains($normalized, 'cap') || str_contains($normalized, 'limit')) {
            return ['label' => 'Daily limit reached', 'detail' => 'The reward cap for this period had already been reached.'];
        }
        if (str_contains($normalized, 'vpn') || str_contains($normalized, 'proxy') || str_contains($normalized, 'ip')) {
            return ['label' => 'Traffic quality checks blocked it', 'detail' => 'This traffic matched a VPN, proxy, or other quality filter.'];
        }
        if (str_contains($normalized, 'proof') || str_contains($normalized, 'complete')) {
            return ['label' => 'Did not meet proof checks', 'detail' => 'The system did not receive enough proof that the download completed properly.'];
        }
        if (str_contains($normalized, 'country') || str_contains($normalized, 'geo')) {
            return ['label' => 'Country was not eligible', 'detail' => 'This traffic came from a country group that does not qualify for this reward tier.'];
        }

        $label = ucwords(str_replace(['_', '-'], ' ', $normalized));
        return ['label' => $label, 'detail' => $label . '.'];
    }

    private function buildRewardsTrend(\PDO $db, int $userId): array
    {
        $stmt = $db->prepare("
            SELECT day, downloads, earnings
            FROM stats_daily
            WHERE user_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
            ORDER BY day ASC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $currentDownloads = 0;
        $currentEarnings = 0.0;
        $previousDownloads = 0;
        $previousEarnings = 0.0;

        foreach ($rows as $row) {
            $day = (string)($row['day'] ?? '');
            if ($day === '') {
                continue;
            }

            $daysAgo = (int)floor((time() - strtotime($day . ' 00:00:00')) / 86400);
            if ($daysAgo < 0 || $daysAgo > 13) {
                continue;
            }

            if ($daysAgo <= 6) {
                $currentDownloads += (int)($row['downloads'] ?? 0);
                $currentEarnings += (float)($row['earnings'] ?? 0);
            } else {
                $previousDownloads += (int)($row['downloads'] ?? 0);
                $previousEarnings += (float)($row['earnings'] ?? 0);
            }
        }

        $earningsDelta = $currentEarnings - $previousEarnings;
        $downloadsDelta = $currentDownloads - $previousDownloads;

        return [
            'current_downloads' => $currentDownloads,
            'previous_downloads' => $previousDownloads,
            'downloads_delta' => $downloadsDelta,
            'downloads_direction' => $downloadsDelta > 0 ? 'up' : ($downloadsDelta < 0 ? 'down' : 'flat'),
            'current_earnings' => $currentEarnings,
            'previous_earnings' => $previousEarnings,
            'earnings_delta' => $earningsDelta,
            'earnings_direction' => $earningsDelta > 0 ? 'up' : ($earningsDelta < 0 ? 'down' : 'flat'),
        ];
    }

    private function decoratePromotion(array $promotion): array
    {
        $progressValue = (float)($promotion['progress_value'] ?? 0);
        $thresholdValue = max(0.0, (float)($promotion['threshold_value'] ?? 0));
        $triggerStyle = (string)($promotion['trigger_style'] ?? 'once');
        $displayProgressValue = $progressValue;
        $progressPercent = 0;

        if ($thresholdValue > 0) {
            if ($triggerStyle === 'every_multiple') {
                $remainder = fmod($progressValue, $thresholdValue);
                if ($remainder < 0) {
                    $remainder = 0.0;
                }
                $displayProgressValue = $remainder;
                $progressPercent = max(0, min(100, (int)round(($displayProgressValue / $thresholdValue) * 100)));
            } else {
                $progressPercent = max(0, min(100, (int)round(($progressValue / $thresholdValue) * 100)));
            }
        }

        $promotion['progress_percent'] = $progressPercent;
        $promotion['goal_summary'] = \App\Service\BonusOfferService::formatUserGoalSummary($promotion);
        $promotion['schedule_label'] = \App\Service\BonusOfferService::formatOfferSchedule($promotion);
        $promotion['reward_preview'] = \App\Service\BonusOfferService::formatRewardPreview($promotion, $promotion);
        $progressOffer = $promotion;
        $progressOffer['progress_value'] = $displayProgressValue;
        $promotion['progress_label'] = \App\Service\BonusOfferService::formatUserProgress($progressOffer);
        $promotion['progress_cycle_label'] = $triggerStyle === 'every_multiple'
            ? $promotion['progress_label'] . ' toward the next reward'
            : $promotion['progress_label'];
        $promotion['award_mode_label'] = \App\Service\BonusOfferService::formatUserAwardMode($promotion);

        return $promotion;
    }

    private function verifyCurrentPassword(int $userId, ?string $password): bool
    {
        $password = (string)$password;
        if ($userId <= 0 || $password === '') {
            return false;
        }

        $stmt = Database::getInstance()->getConnection()->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = (string)($stmt->fetchColumn() ?: '');
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    private function checkSensitivePasswordRateLimit(int $userId, string $actionKey): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $ip = \App\Service\SecurityService::getClientIp();
        $ipLimit = max(self::STEP_UP_RATE_LIMIT * 2, 10);

        if (!\App\Service\RateLimiterService::canAttempt($actionKey . '_ip', $ip, $ipLimit, self::STEP_UP_RATE_WINDOW)) {
            return false;
        }

        return \App\Service\RateLimiterService::canAttempt($actionKey . '_user', (string)$userId, self::STEP_UP_RATE_LIMIT, self::STEP_UP_RATE_WINDOW);
    }

    private function verifySensitivePasswordStepUp(int $userId, string $actionKey, ?string $password): array
    {
        if ($userId <= 0) {
            return ['allowed' => false, 'verified' => false];
        }

        $ip = \App\Service\SecurityService::getClientIp();
        $ipLimit = max(self::STEP_UP_RATE_LIMIT * 2, 10);
        $result = \App\Service\RateLimiterService::guardAttempt([
            [
                'action' => $actionKey . '_ip',
                'key' => $ip,
                'limit' => $ipLimit,
                'window' => self::STEP_UP_RATE_WINDOW,
            ],
            [
                'action' => $actionKey . '_user',
                'key' => (string)$userId,
                'limit' => self::STEP_UP_RATE_LIMIT,
                'window' => self::STEP_UP_RATE_WINDOW,
            ],
        ], fn() => $this->verifyCurrentPassword($userId, $password));

        return [
            'allowed' => !empty($result['allowed']),
            'verified' => !empty($result['result']),
        ];
    }

    private function storageQuotaInfo(int $userId): array
    {
        $stmt = Database::getInstance()->getConnection()->prepare('SELECT storage_used FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $used = (int)($stmt->fetchColumn() ?: 0);

        $package = \App\Model\Package::getUserPackage($userId);
        $limit = (int)($package['max_storage_bytes'] ?? 0);

        return ['used' => $used, 'limit' => $limit];
    }

    public function affiliate()
    {
        if (!FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT t.*, (SELECT GROUP_CONCAT(country_code) FROM ppd_tier_countries WHERE tier_id = t.id) as countries
            FROM ppd_tiers t
            ORDER BY t.rate_per_1000 DESC
        ");
        $tiers = $stmt->fetchAll();

        $user = Auth::user();
        $affiliateEnabled = FeatureService::affiliateEnabled();
        $userPackage = $user ? (\App\Model\Package::getUserPackage((int)$user['id']) ?: null) : null;
        $enabledModels = MonetizationModelService::allowedModelsForPackage($userPackage);

        View::render('home/affiliate.php', [
            'tiers' => $tiers,
            'enabledModels' => $enabledModels,
            'userModel' => $user ? ($user['monetization_model'] ?? 'ppd') : null,
            'ppsCommission' => Setting::get('pps_commission_percent', '50', 'rewards'),
            'mixedPpdPercent' => Setting::get('mixed_ppd_percent', '30', 'rewards'),
            'mixedPpsPercent' => Setting::get('mixed_pps_percent', '30', 'rewards'),
            'referralCommission' => Setting::get('referral_commission_percent', '50', 'rewards'),
            'affiliateEnabled' => $affiliateEnabled,
            'user' => $user,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary(Auth::id() ? (int)Auth::id() : null, Auth::id() ? (\App\Model\Package::getUserPackage((int)Auth::id()) ?: []) : []),
            'storageQuota' => Auth::id() ? $this->storageQuotaInfo((int)Auth::id()) : ['used' => 0, 'limit' => 0],
        ]);
    }

    public function rewards()
    {
        if (!FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }

        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $userId = Auth::id();
        $user = Auth::user();
        \App\Service\BonusOfferService::evaluateForUser((int)$userId);
        $userModel = (string)($user['monetization_model'] ?? 'ppd');
        $defaultWithdrawalMethod = trim((string)($user['payment_method'] ?? ''));
        $defaultWithdrawalDetails = '';
        if (!empty($user['payment_details'])) {
            $defaultWithdrawalDetails = (string)EncryptionService::decrypt((string)$user['payment_details']);
        }

        $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status IN ('pending', 'cleared')");
        $stmt->execute([$userId]);
        $totalEarned = (float) $stmt->fetchColumn();

        $totalPaid = 0.0;
        $stmt = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status = 'paid'");
        $stmt->execute([$userId]);
        $totalPaid = (float) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'cleared'");
        $stmt->execute([$userId]);
        $cleared = (float) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved', 'paid')");
        $stmt->execute([$userId]);
        $withdrawn = (float) $stmt->fetchColumn();
        $netPayoutPosition = $cleared - $withdrawn;
        $availableBalance = max(0, $netPayoutPosition);
        $recoveryHoldBalance = max(0, -$netPayoutPosition);

        $stmt = $db->prepare("
            SELECT
                (SELECT COUNT(*) FROM reward_receipts WHERE user_id = ? AND status = 'pending') +
                (SELECT COUNT(*) FROM earnings WHERE user_id = ? AND type = 'download_reward' AND status IN ('held', 'flagged_review'))
        ");
        $stmt->execute([$userId, $userId]);
        $pendingRewards = (int) $stmt->fetchColumn();

        $retentionDays = \App\Service\RewardService::retentionDays();
        $stmt = $db->prepare("SELECT COALESCE(SUM(downloads), 0) FROM stats_daily WHERE user_id = ? AND day < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        $stmt->execute([$userId, $retentionDays]);
        $historicalCountedDownloads = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM earnings
            WHERE user_id = ?
              AND type = 'download_reward'
              AND status IN ('cleared', 'paid')
              AND amount > 0
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$userId, $retentionDays]);
        $recentCountedDownloads = (int)$stmt->fetchColumn();
        $countedDownloads = $historicalCountedDownloads + $recentCountedDownloads;

        $stmt = $db->prepare("
            SELECT
                (SELECT COUNT(*)
                   FROM reward_receipts
                  WHERE user_id = ?
                    AND (
                        status = 'flagged'
                        OR (status = 'processed' AND COALESCE(reward_counted, 0) = 0)
                    )
                ) +
                (
                    SELECT COUNT(*)
                    FROM earnings
                    WHERE user_id = ?
                      AND type = 'download_reward'
                      AND (
                            status IN ('flagged_review', 'reversed', 'cancelled')
                            OR (status IN ('cleared', 'paid') AND amount < 0)
                          )
                )
        ");
        $stmt->execute([$userId, $userId]);
        $rejectedDownloads = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT status, SUM(amount) as total
            FROM earnings
            WHERE user_id = ?
            GROUP BY status
        ");
        $stmt->execute([$userId]);
        $amountsByStatus = [
            'pending' => 0.0,
            'held' => 0.0,
            'cleared' => 0.0,
            'cancelled' => 0.0,
            'flagged_review' => 0.0,
            'paid' => 0.0,
            'reversed' => 0.0,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $amountsByStatus[(string)$row['status']] = (float)$row['total'];
        }

        $stmt = $db->prepare("
            SELECT
                GREATEST(
                    COALESCE(ea.last_activity, '1970-01-01 00:00:00'),
                    COALESCE(ra.last_activity, '1970-01-01 00:00:00')
                ) AS last_activity,
                f.filename,
                f.downloads AS file_downloads,
                f.id AS file_id,
                COALESCE(ea.total_amount, 0) AS total_amount,
                COALESCE(ea.cleared_downloads, 0) + COALESCE(ra.rejected_receipts, 0) AS total_downloads,
                COALESCE(ea.cleared_downloads, 0) AS counted_downloads,
                COALESCE(ea.rejected_earnings, 0) + COALESCE(ra.rejected_receipts, 0) AS rejected_downloads
            FROM files f
            LEFT JOIN (
                SELECT
                    file_id,
                    MAX(created_at) AS last_activity,
                    SUM(CASE WHEN status IN ('cleared', 'paid') THEN amount ELSE 0 END) AS total_amount,
                    SUM(CASE WHEN status IN ('cleared', 'paid') AND amount > 0 THEN 1 ELSE 0 END) AS cleared_downloads,
                    SUM(CASE WHEN status IN ('flagged_review', 'reversed', 'cancelled') OR (status IN ('cleared', 'paid') AND amount < 0) THEN 1 ELSE 0 END) AS rejected_earnings
                FROM earnings
                WHERE user_id = ? AND type = 'download_reward'
                GROUP BY file_id
            ) ea ON ea.file_id = f.id
            LEFT JOIN (
                SELECT
                    file_id,
                    MAX(created_at) AS last_activity,
                    COUNT(*) AS rejected_receipts
                FROM reward_receipts
                WHERE user_id = ?
                  AND (
                        status = 'flagged'
                        OR (status = 'processed' AND COALESCE(reward_counted, 0) = 0)
                      )
                GROUP BY file_id
            ) ra ON ra.file_id = f.id
            WHERE f.user_id = ?
              AND (ea.file_id IS NOT NULL OR ra.file_id IS NOT NULL)
            ORDER BY last_activity DESC
            LIMIT 25
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $recentEarnings = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT day, downloads, earnings FROM stats_daily WHERE user_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ORDER BY day ASC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $analytics = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $match = null;
            foreach ($rows as $row) {
                if ($row['day'] === $date) {
                    $match = $row;
                    break;
                }
            }
            $analytics[] = $match ?: ['day' => $date, 'downloads' => 0, 'earnings' => 0.00];
        }

        $stmt = $db->prepare("
            SELECT DATE(created_at) as day, SUM(amount) as earnings, SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as downloads
            FROM earnings
            WHERE user_id = ? AND type = 'download_reward' AND status IN ('cleared', 'paid')
            GROUP BY DATE(created_at)
            ORDER BY day DESC
            LIMIT 30
        ");
        $stmt->execute([$userId]);
        $earningsByDay = $stmt->fetchAll();

        $stmt = $db->prepare("
            SELECT country_code, network_type, SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as downloads, SUM(amount) as earnings
            FROM earnings
            WHERE user_id = ? AND type = 'download_reward' AND status IN ('cleared', 'paid')
            GROUP BY country_code, network_type
            ORDER BY earnings DESC, downloads DESC
            LIMIT 25
        ");
        $stmt->execute([$userId]);
        $countryTierRows = $stmt->fetchAll();

        $stmt = $db->prepare("
            SELECT rr.created_at, rr.status, rr.risk_level, rr.risk_reasons_json, rr.country_code, f.filename
            FROM reward_receipts rr
            LEFT JOIN files f ON rr.file_id = f.id
            WHERE rr.user_id = ?
              AND (
                    rr.status = 'flagged'
                    OR (rr.status = 'processed' AND COALESCE(rr.reward_counted, 0) = 0)
                  )
            ORDER BY rr.created_at DESC
            LIMIT 25
        ");
        $stmt->execute([$userId]);
        $downloadExplanations = array_map(function (array $row): array {
            $reasons = json_decode((string)($row['risk_reasons_json'] ?? ''), true);
            $reasons = is_array($reasons) ? array_values(array_filter(array_map('strval', $reasons))) : [];

            $friendlyReasons = [];
            foreach ($reasons as $reason) {
                $friendlyReasons[] = $this->humanizeReasonCode($reason);
            }

            $primaryReason = $friendlyReasons[0] ?? ['label' => 'No reason recorded', 'detail' => 'No specific reason was stored for this traffic.'];
            $row['display_status'] = (($row['risk_level'] ?? '') === 'not_counted' || (string)($row['status'] ?? '') === 'processed')
                ? 'Did not count'
                : 'Rejected';
            $row['display_reason'] = $primaryReason['label'];
            $row['display_reason_detail'] = $primaryReason['detail'];
            $row['display_reason_list'] = array_values(array_unique(array_map(static fn(array $item): string => $item['label'], $friendlyReasons)));
            return $row;
        }, $stmt->fetchAll());

        $stmt = $db->prepare("
            SELECT e.created_at, e.type, e.status, e.amount, e.description, e.review_note, e.file_id, f.filename
            FROM earnings e
            LEFT JOIN files f ON e.file_id = f.id
            WHERE e.user_id = ?
              AND e.type IN ('download_reward', 'pps_reward', 'referral', 'bonus', 'aggregate_summary')
            ORDER BY e.created_at DESC, e.id DESC
            LIMIT 40
        ");
        $stmt->execute([$userId]);
        $recentRewardActivity = $stmt->fetchAll();

        $referralCount = 0;
        if (FeatureService::affiliateEnabled()) {
            $stmt = $db->prepare("
                SELECT DISTINCT u.id, u.referrer_id, u.referrer_source, u.status, u.email_lookup, u.payment_method, u.payment_details
                FROM earnings e
                INNER JOIN users u ON u.id = e.user_id
                WHERE u.referrer_id = ?
                  AND COALESCE(u.referrer_source, '') = 'referral'
                  AND e.type IN ('download_reward', 'pps_reward')
                  AND e.status IN ('cleared', 'paid')
                  AND e.amount > 0
            ");
            $stmt->execute([$userId]);
            $eligibleReferrals = 0;
            foreach ($stmt->fetchAll() as $referredUser) {
                if (\App\Service\AffiliateRewardService::isReferralRelationshipEligible($user, $referredUser)) {
                    $eligibleReferrals++;
                }
            }
            $referralCount = $eligibleReferrals;
        }

        $stmt = $db->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $withdrawals = $stmt->fetchAll();
        $hasOpenWithdrawal = false;
        foreach ($withdrawals as $withdrawalRow) {
            if (in_array((string)($withdrawalRow['status'] ?? ''), ['pending', 'approved'], true)) {
                $hasOpenWithdrawal = true;
                break;
            }
        }

        $bonusSummary = \App\Service\BonusOfferService::getRewardsBonusSummary((int)$userId);
        $bonusHistory = \App\Service\BonusOfferService::getRewardsBonusHistory((int)$userId);
        $activePromotions = array_map(fn(array $promotion): array => $this->decoratePromotion($promotion), \App\Service\BonusOfferService::listOffersForUser((int)$userId));
        $minimumWithdrawalAmount = max(0, round((float)Setting::get('minimum_withdrawal_amount', '1.00', 'rewards'), 2));
        $supportedWithdrawalMethods = PayoutProcessorService::activeKeys();
        $withdrawalProcessorMap = [];
        foreach (PayoutProcessorService::definitions(false) as $processor) {
            $withdrawalProcessorMap[(string)$processor['key']] = $processor;
        }
        $trend = $this->buildRewardsTrend($db, (int)$userId);

        View::render('home/rewards.php', [
            'totalEarned' => $totalEarned,
            'totalPaid' => $totalPaid,
            'availableBalance' => $availableBalance,
            'recoveryHoldBalance' => $recoveryHoldBalance,
            'pendingRewards' => $pendingRewards,
            'countedDownloads' => $countedDownloads,
            'rejectedDownloads' => $rejectedDownloads,
            'amountsByStatus' => $amountsByStatus,
            'recentEarnings' => $recentEarnings,
            'analytics' => $analytics,
            'earningsByDay' => $earningsByDay,
            'countryTierRows' => $countryTierRows,
            'downloadExplanations' => $downloadExplanations,
            'recentRewardActivity' => $recentRewardActivity,
            'userModel' => $userModel,
            'referralCount' => $referralCount,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary((int)$userId, \App\Model\Package::getUserPackage((int)$userId) ?: []),
            'storageQuota' => $this->storageQuotaInfo((int)$userId),
            'defaultWithdrawalMethod' => $defaultWithdrawalMethod,
            'defaultWithdrawalDetails' => $defaultWithdrawalDetails,
            'withdrawals' => $withdrawals,
            'hasOpenWithdrawal' => $hasOpenWithdrawal,
            'bonusSummary' => $bonusSummary,
            'bonusHistory' => $bonusHistory,
            'activePromotions' => $activePromotions,
            'minimumWithdrawalAmount' => $minimumWithdrawalAmount,
            'supportedWithdrawalMethods' => $supportedWithdrawalMethods,
            'withdrawalProcessorMap' => $withdrawalProcessorMap,
            'trend' => $trend,
        ]);
    }

    public function promotions()
    {
        if (!FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }

        $isAuthenticated = Auth::check();
        $userId = $isAuthenticated ? (int)(Auth::id() ?? 0) : 0;
        $offers = [];
        $storageQuota = ['used' => 0, 'limit' => 0];
        $dailyDownloadLimitSummary = [];

        if ($isAuthenticated && $userId > 0) {
            $currentUser = Auth::user();
            if (strtolower((string)($currentUser['status'] ?? 'active')) === 'active') {
                \App\Service\BonusOfferService::evaluateForUser($userId);
                $offers = \App\Service\BonusOfferService::listOffersForUser($userId);
            } else {
                $offers = \App\Service\BonusOfferService::listPublicOffers();
            }
            $storageQuota = $this->storageQuotaInfo($userId);
            $dailyDownloadLimitSummary = PackageAllowanceService::dailyDownloadLimitSummary($userId, \App\Model\Package::getUserPackage($userId) ?: []);
        } else {
            $offers = \App\Service\BonusOfferService::listPublicOffers();
        }

        View::render('home/promotions.php', [
            'offers' => $offers,
            'isAuthenticated' => $isAuthenticated,
            'dailyDownloadLimitSummary' => $dailyDownloadLimitSummary,
            'storageQuota' => $storageQuota,
        ]);
    }

    public function exportCsv()
    {
        if (!FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT e.created_at, e.type, e.status, e.amount, e.country_code, e.network_type,
                   e.description, e.risk_score, e.risk_reasons_json, f.filename
            FROM earnings e
            LEFT JOIN files f ON e.file_id = f.id
            WHERE e.user_id = ?
            ORDER BY e.created_at DESC
            LIMIT 5000
        ");
        $stmt->execute([Auth::id()]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="earnings-export.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['date', 'file', 'type', 'status', 'amount', 'country', 'network', 'risk_score', 'reasons', 'description']);
        foreach ($stmt->fetchAll() as $row) {
            fputcsv($out, [
                $row['created_at'],
                \App\Service\EncryptionService::decrypt($row['filename'] ?? '') ?: '',
                $row['type'],
                $row['status'],
                $row['amount'],
                $row['country_code'],
                $row['network_type'],
                $row['risk_score'],
                $row['risk_reasons_json'],
                $row['description'],
            ]);
        }
        fclose($out);
        exit;
    }

    public function withdraw()
    {
        if (!FeatureService::rewardsEnabled()) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Not found']);
            exit;
        }

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh.']);
            exit;
        }

        $userId = Auth::id();
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $minimumWithdrawalAmount = max(0, round((float)Setting::get('minimum_withdrawal_amount', '1.00', 'rewards'), 2));
        $supportedMethods = PayoutProcessorService::activeKeys();

        if ($amount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payout request.']);
            exit;
        }

        $stepUp = $this->verifySensitivePasswordStepUp((int)$userId, 'stepup_rewards_withdraw', $currentPassword);
        if (!$stepUp['allowed']) {
            echo json_encode(['status' => 'error', 'message' => 'Current password confirmation is temporarily locked. Please wait 10 minutes and try again.']);
            exit;
        }

        if (!$stepUp['verified']) {
            echo json_encode(['status' => 'error', 'message' => 'Current password required to request payout.']);
            exit;
        }

        if ($amount < $minimumWithdrawalAmount) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Minimum withdrawal amount is $' . number_format($minimumWithdrawalAmount, 2) . '.',
            ]);
            exit;
        }

        if ($supportedMethods === []) {
            echo json_encode(['status' => 'error', 'message' => 'Payout requests are temporarily unavailable because no payout processors are enabled right now.']);
            exit;
        }

        $rewardService = new \App\Service\RewardService();
        $rewardService->rollupUserHistory($userId, \App\Service\RewardService::retentionDays());

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT id, payment_method, payment_details FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $userRow = $stmt->fetch();
            if (!$userRow) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Account not found.']);
                exit;
            }

            $method = trim((string)($userRow['payment_method'] ?? ''));
            $details = '';
            if (!empty($userRow['payment_details'])) {
                $details = trim((string)(\App\Service\EncryptionService::decrypt((string)$userRow['payment_details']) ?: ''));
            }

            if ($method === '' || !in_array($method, $supportedMethods, true) || $details === '') {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Saved payout processor and destination are required before requesting a payout. Please update your payout settings first.']);
                exit;
            }

            $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'cleared' FOR UPDATE");
            $stmt->execute([$userId]);
            $cleared = (float) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved', 'paid') FOR UPDATE");
            $stmt->execute([$userId]);
            $withdrawn = (float) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved') FOR UPDATE");
            $stmt->execute([$userId]);
            $openWithdrawalCount = (int)$stmt->fetchColumn();
            if ($openWithdrawalCount > 0) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'You already have a payout request waiting to be processed. Please wait for it to be approved, paid, or rejected before submitting another one.']);
                exit;
            }

            $balance = $cleared - $withdrawn;
            if ($amount > $balance) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Insufficient balance. Available: $' . number_format($balance, 2)]);
                exit;
            }

            $encDetails = \App\Service\EncryptionService::encrypt($details);
            $stmt = $db->prepare("INSERT INTO withdrawals (user_id, amount, method, details, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$userId, $amount, $method, $encDetails]);

            $db->commit();
            \App\Service\SystemStatsService::increment('pending_withdrawals');

            $user = User::find((int)$userId);
            if ($user && !empty($user['email'])) {
                \App\Service\MailService::sendTemplate($user['email'], 'withdrawal_request_submitted', [
                    '{username}' => $user['username'] ?? 'User',
                    '{amount}' => '$' . number_format($amount, 2),
                        '{method}' => PayoutProcessorService::label($method),
                ], 'low');
            }

            $adminEmail = Setting::get('admin_notification_email', '');
            if ($adminEmail !== '') {
                \App\Service\MailService::sendTemplate($adminEmail, 'admin_notification', [
                    '{event_type}' => 'New Withdrawal Request',
                    '{details}' => "User ID: {$userId}\nAmount: $" . number_format($amount, 2) . "\nMethod: " . PayoutProcessorService::label($method),
                ], 'low');
            }

            echo json_encode(['status' => 'success', 'message' => 'Withdrawal request submitted successfully.']);
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Rewards withdrawal failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'A transaction error occurred. Please try again.']);
        }

        exit;
    }
}
