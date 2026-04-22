<?php

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Model\Setting;
use App\Model\User;
use App\Service\FeatureService;
use App\Service\PackageAllowanceService;

class RewardsController
{
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
            'mixedPpdPercent' => Setting::get('mixed_ppd_percent', '30', 'rewards'),
            'user' => $user,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary(Auth::id() ? (int)Auth::id() : null, Auth::id() ? (\App\Model\Package::getUserPackage((int)Auth::id()) ?: []) : []),
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

        $stmt = $db->prepare("
            SELECT MAX(e.created_at) as last_activity, f.filename, SUM(e.amount) as total_amount, COUNT(e.id) as total_downloads
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

        $referralCount = 0;
        if (FeatureService::affiliateEnabled()) {
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT t.user_id)
                FROM transactions t
                INNER JOIN users u ON u.id = t.user_id
                WHERE u.referrer_id = ? AND t.status = 'completed'
            ");
            $stmt->execute([$userId]);
            $referralCount = (int)$stmt->fetchColumn();
        }

        View::render('home/rewards.php', [
            'totalEarned' => $totalEarned,
            'totalPaid' => $totalPaid,
            'availableBalance' => $availableBalance,
            'pendingRewards' => $pendingRewards,
            'recentEarnings' => $recentEarnings,
            'analytics' => $analytics,
            'userModel' => $userModel,
            'referralCount' => $referralCount,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary((int)$userId, \App\Model\Package::getUserPackage((int)$userId) ?: []),
        ]);
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
