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
use App\Service\PackageAllowanceService;

class RewardsController
{
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
        if (!FeatureService::affiliateEnabled()) {
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

        $enabledModels = array_filter(array_map('trim', explode(',', Setting::get('enabled_models', 'ppd,pps,mixed', 'rewards'))));
        $user = Auth::user();

        View::render('home/affiliate.php', [
            'tiers' => $tiers,
            'enabledModels' => $enabledModels,
            'userModel' => $user ? ($user['monetization_model'] ?? 'ppd') : null,
            'ppsCommission' => Setting::get('pps_commission_percent', '50', 'rewards'),
            'mixedPpdPercent' => Setting::get('mixed_ppd_percent', '30', 'rewards'),
            'mixedPpsPercent' => Setting::get('mixed_pps_percent', '30', 'rewards'),
            'referralCommission' => Setting::get('referral_commission_percent', '50', 'rewards'),
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
        $userModel = (string)($user['monetization_model'] ?? 'ppd');
        $defaultWithdrawalMethod = trim((string)($user['payment_method'] ?? ''));
        $defaultWithdrawalDetails = '';
        if (!empty($user['payment_details'])) {
            $defaultWithdrawalDetails = (string)EncryptionService::decrypt((string)$user['payment_details']);
        }

        $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status IN ('pending', 'cleared')");
        $stmt->execute([$userId]);
        $totalEarned = (float) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'paid'");
        $stmt->execute([$userId]);
        $totalPaid = (float) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'cleared'");
        $stmt->execute([$userId]);
        $cleared = (float) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved', 'paid')");
        $stmt->execute([$userId]);
        $withdrawn = (float) $stmt->fetchColumn();
        $availableBalance = max(0, $cleared - $withdrawn);

        $stmt = $db->prepare("SELECT COUNT(*) FROM reward_receipts WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $pendingRewards = (int) $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM earnings WHERE user_id = ? AND type = 'download_reward' AND status IN ('held', 'cleared', 'paid')");
        $stmt->execute([$userId]);
        $countedDownloads = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT
                (SELECT COUNT(*) FROM reward_receipts WHERE user_id = ? AND status = 'flagged') +
                (SELECT COUNT(*) FROM earnings WHERE user_id = ? AND type = 'download_reward' AND status IN ('flagged_review', 'reversed', 'cancelled'))
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
            SELECT MAX(e.created_at) as last_activity, f.filename, f.downloads as file_downloads, e.file_id,
                   SUM(e.amount) as total_amount, COUNT(e.id) as total_downloads,
                   SUM(CASE WHEN e.status IN ('held', 'cleared', 'paid') THEN 1 ELSE 0 END) as counted_downloads,
                   SUM(CASE WHEN e.status IN ('flagged_review', 'reversed', 'cancelled') THEN 1 ELSE 0 END) as rejected_downloads
            FROM earnings e
            LEFT JOIN files f ON e.file_id = f.id
            WHERE e.user_id = ? AND e.type = 'download_reward'
            GROUP BY e.file_id, f.filename
            ORDER BY last_activity DESC
            LIMIT 25
        ");
        $stmt->execute([$userId]);
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
            SELECT DATE(created_at) as day, SUM(amount) as earnings, COUNT(*) as downloads
            FROM earnings
            WHERE user_id = ? AND type = 'download_reward'
            GROUP BY DATE(created_at)
            ORDER BY day DESC
            LIMIT 30
        ");
        $stmt->execute([$userId]);
        $earningsByDay = $stmt->fetchAll();

        $stmt = $db->prepare("
            SELECT country_code, network_type, COUNT(*) as downloads, SUM(amount) as earnings
            FROM earnings
            WHERE user_id = ? AND type = 'download_reward'
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
            WHERE rr.user_id = ? AND rr.status IN ('flagged', 'processed')
            ORDER BY rr.created_at DESC
            LIMIT 25
        ");
        $stmt->execute([$userId]);
        $downloadExplanations = $stmt->fetchAll();

        $referralCount = 0;
        if (FeatureService::affiliateEnabled()) {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT e.user_id)
                FROM earnings e
                INNER JOIN users u ON u.id = e.user_id
                WHERE u.referrer_id = ?
                  AND COALESCE(u.referrer_source, '') = 'referral'
                  AND e.type IN ('download_reward', 'pps_reward')
                  AND e.status IN ('cleared', 'paid')
            ");
            $stmt->execute([$userId]);
            $referralCount = (int)$stmt->fetchColumn();
        }

        View::render('home/rewards.php', [
            'totalEarned' => $totalEarned,
            'totalPaid' => $totalPaid,
            'availableBalance' => $availableBalance,
            'pendingRewards' => $pendingRewards,
            'countedDownloads' => $countedDownloads,
            'rejectedDownloads' => $rejectedDownloads,
            'amountsByStatus' => $amountsByStatus,
            'recentEarnings' => $recentEarnings,
            'analytics' => $analytics,
            'earningsByDay' => $earningsByDay,
            'countryTierRows' => $countryTierRows,
            'downloadExplanations' => $downloadExplanations,
            'userModel' => $userModel,
            'referralCount' => $referralCount,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary((int)$userId, \App\Model\Package::getUserPackage((int)$userId) ?: []),
            'storageQuota' => $this->storageQuotaInfo((int)$userId),
            'defaultWithdrawalMethod' => $defaultWithdrawalMethod,
            'defaultWithdrawalDetails' => $defaultWithdrawalDetails,
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
        $amount = (float) ($_POST['amount'] ?? 0);
        $method = $_POST['method'] ?? '';
        $details = trim((string) ($_POST['details'] ?? ''));

        if ($amount <= 0 || $details === '') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payout request.']);
            exit;
        }

        $supportedMethods = array_filter(array_map('trim', explode(',', Setting::get('supported_withdrawal_methods', 'paypal,bitcoin', 'rewards'))));
        if (!in_array($method, $supportedMethods, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Selected withdrawal method is not currently available.']);
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $rewardService = new \App\Service\RewardService();
            $rewardService->rollupUserHistory($userId, \App\Service\RewardService::retentionDays());

            $stmt = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'cleared' FOR UPDATE");
            $stmt->execute([$userId]);
            $cleared = (float) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved', 'paid') FOR UPDATE");
            $stmt->execute([$userId]);
            $withdrawn = (float) $stmt->fetchColumn();

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

            $user = User::find((int)$userId);
            if ($user && !empty($user['email'])) {
                \App\Service\MailService::sendTemplate($user['email'], 'withdrawal_request_submitted', [
                    '{username}' => $user['username'] ?? 'User',
                    '{amount}' => '$' . number_format($amount, 2),
                    '{method}' => strtoupper($method),
                ], 'low');
            }

            $adminEmail = Setting::get('admin_notification_email', '');
            if ($adminEmail !== '') {
                \App\Service\MailService::sendTemplate($adminEmail, 'admin_notification', [
                    '{event_type}' => 'New Withdrawal Request',
                    '{details}' => "User ID: {$userId}\nAmount: $" . number_format($amount, 2) . "\nMethod: " . strtoupper($method),
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
