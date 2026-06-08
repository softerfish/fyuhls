<?php
$requestUri = $requestUri ?? ($_SERVER['REQUEST_URI'] ?? '/');
$currentUserId = \App\Core\Auth::id() ?? 0;
$storageQuota = is_array($storageQuota ?? null) ? $storageQuota : ['used' => 0, 'limit' => 0];
$dailyDownloadLimitSummary = is_array($dailyDownloadLimitSummary ?? null) ? $dailyDownloadLimitSummary : [];
$isSettingsArea = str_contains($requestUri, '/settings') || str_contains($requestUri, '/2fa/');
$isRewardsArea = str_contains($requestUri, '/rewards');
$isAffiliateArea = str_contains($requestUri, '/affiliate');
$isPlansArea = str_contains($requestUri, '/plans');
$isPaymentsArea = str_contains($requestUri, '/payments');
$isPromotionsArea = str_contains($requestUri, '/promotions');
$isRecentArea = str_contains($requestUri, '/recent');
$isSharedArea = str_contains($requestUri, '/shared');
$isNotificationsArea = str_contains($requestUri, '/notifications');
$isTicketsArea = str_contains($requestUri, '/tickets');
$isAllFilesArea = (!isset($isTrash) || !$isTrash)
    && !$isSettingsArea
    && !$isRewardsArea
    && !$isAffiliateArea
    && !$isPlansArea
    && !$isPaymentsArea
    && !$isPromotionsArea
    && !$isRecentArea
    && !$isSharedArea
    && !$isNotificationsArea
    && !$isTicketsArea;

$pkgNameStr = 'Free Plan';
$expiryStr = 'Lifetime Free Account';
$userPkg = null;
$planLink = null;
$isPaidPlan = false;

if ($currentUserId) {
    if (\App\Core\Auth::isAdmin()) {
        $pkgNameStr = 'Admin';
        $expiryStr = 'Administrator account';
        $isPaidPlan = true;
    } else {
        $userPkg = \App\Model\Package::getUserPackage((int)$currentUserId);
        if ($userPkg) {
            $pkgNameStr = $userPkg['name'] ?? 'Free Plan';
            $isPaidPlan = strtolower((string)($userPkg['level_type'] ?? 'free')) === 'paid';
            if (!empty($userPkg['premium_expiry'])) {
                $expiryPrefix = 'Expires on ';
                try {
                    $db = \App\Core\Database::getInstance()->getConnection();
                    $stmt = $db->prepare("SELECT auto_renew FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > NOW() ORDER BY expires_at DESC, id DESC LIMIT 1");
                    $stmt->execute([(int)$currentUserId]);
                    $expiryPrefix = ((int)($stmt->fetchColumn() ?: 0) === 1) ? 'Renews on ' : 'Expires on ';
                } catch (\Throwable $e) {
                    $expiryPrefix = 'Expires on ';
                }
                $expiryStr = $expiryPrefix . date('M d, Y', strtotime($userPkg['premium_expiry']));
            } elseif ($isPaidPlan) {
                $expiryStr = 'Paid account active';
            }
        }
    }
}

$allPackages = \App\Model\Package::getAll();
foreach ($allPackages as $sidebarPkg) {
    if (($sidebarPkg['level_type'] ?? '') === 'paid') {
        $planLink = '/plans';
        break;
    }
}

$unreadNotificationCount = 0;
$openTicketCount = 0;
$trashItemCount = 0;
if ($currentUserId > 0) {
    try {
        $unreadNotificationCount = count(\App\Service\NotificationService::getUnread($currentUserId));
    } catch (\Throwable $e) {
        $unreadNotificationCount = 0;
    }

    try {
        $userTickets = \App\Service\TicketService::getUserTickets($currentUserId);
        foreach ($userTickets as $ticket) {
            if (($ticket['status'] ?? '') !== 'closed') {
                $openTicketCount++;
            }
        }
    } catch (\Throwable $e) {
        $openTicketCount = 0;
    }

    try {
        $trashItemCount = count(\App\Model\File::getDeletedByUser($currentUserId))
            + count(\App\Model\Folder::getDeletedByUser($currentUserId));
    } catch (\Throwable $e) {
        $trashItemCount = 0;
    }
}

$showPromotionsLink = $currentUserId > 0
    && \App\Service\FeatureService::rewardsEnabled()
    && \App\Service\BonusOfferService::hasVisiblePromotions($currentUserId);

?>
<div class="fm-sidebar">
    <div class="sidebar-section">
        <div class="dashboard-plan-card">
            <div class="dashboard-plan-current">
                Current Plan: <span class="dashboard-plan-name"><?= htmlspecialchars($pkgNameStr) ?></span>
            </div>
            <div class="dashboard-plan-expiry <?= $isPaidPlan ? 'dashboard-plan-expiry--tight' : 'dashboard-plan-expiry--wide' ?>">
                <?= htmlspecialchars($expiryStr) ?>
            </div>

            <?php if (!$isPaidPlan && $planLink !== null): ?>
                <a class="btn btn-warning dashboard-plan-button" href="<?= htmlspecialchars($planLink) ?>">View Plans</a>
            <?php endif; ?>
        </div>

        <?php
        $sqUsed = (int)($storageQuota['used'] ?? 0);
        $sqLimit = (int)($storageQuota['limit'] ?? 0);
        if ($sqLimit > 0):
            $sqPct = min(100, round(($sqUsed / $sqLimit) * 100));
            $sqClass = $sqPct >= 90 ? 'storage-bar--danger' : ($sqPct >= 70 ? 'storage-bar--warn' : '');
        ?>
        <div class="storage-bar-wrap">
            <div class="storage-bar-label">
                <span>Storage</span>
                <span><?= htmlspecialchars(\App\Service\FileProcessor::formatSize($sqUsed, 1)) ?> / <?= htmlspecialchars(\App\Service\FileProcessor::formatSize($sqLimit, 1)) ?></span>
            </div>
            <div class="storage-bar <?= $sqClass ?>">
                <div class="storage-bar-fill" style="width:<?= $sqPct ?>%"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $bwUsed = (int)($dailyDownloadLimitSummary['used_bytes'] ?? 0);
        $bwLimit = (int)($dailyDownloadLimitSummary['limit_bytes'] ?? 0);
        $bwHasLimit = !empty($dailyDownloadLimitSummary['has_limit']);
        if ($bwHasLimit && $bwLimit > 0):
            $bwPct = min(100, round(($bwUsed / $bwLimit) * 100));
            $bwClass = $bwPct >= 90 ? 'storage-bar--danger' : ($bwPct >= 70 ? 'storage-bar--warn' : '');
        ?>
        <div class="storage-bar-wrap" style="margin-top: 15px;">
            <div class="storage-bar-label">
                <span>Bandwidth (Daily)</span>
                <span><?= htmlspecialchars(\App\Service\FileProcessor::formatSize($bwUsed, 1)) ?> / <?= htmlspecialchars(\App\Service\FileProcessor::formatSize($bwLimit, 1)) ?></span>
            </div>
            <div class="storage-bar <?= $bwClass ?>">
                <div class="storage-bar-fill" style="width:<?= $bwPct ?>%"></div>
            </div>
        </div>
        <?php elseif (!$bwHasLimit): ?>
        <div class="storage-bar-wrap" style="margin-top: 15px;">
            <div class="storage-bar-label">
                <span>Bandwidth (Daily)</span>
                <span>Unlimited</span>
            </div>
            <div class="storage-bar">
                <div class="storage-bar-fill" style="width:0%"></div>
            </div>
        </div>
        <?php endif; ?>

        <h3 class="dashboard-account-title">Files</h3>
        <ul class="dashboard-nav">
            <li data-nav-url="/" class="<?= $isAllFilesArea ? 'active' : '' ?>">All Files</li>
            <li data-nav-url="/recent" class="<?= $isRecentArea ? 'active' : '' ?>">Recent</li>
            <li data-nav-url="/shared" class="<?= $isSharedArea ? 'active' : '' ?>">Shared</li>
            <li class="<?= (isset($isTrash) && $isTrash) ? 'active' : '' ?> sidebar-trash-item dashboard-trash-item">
                <span data-nav-url="/trash" class="dashboard-trash-link">Trash<?php if ($trashItemCount > 0): ?><span class="dashboard-nav-count"><?= number_format($trashItemCount) ?></span><?php endif; ?></span>
            </li>
        </ul>

        <h3 class="dashboard-account-title">Account</h3>
        <ul class="dashboard-nav">
            <li data-nav-url="/notifications" class="<?= $isNotificationsArea ? 'active' : '' ?>">Notifications<?php if ($unreadNotificationCount > 0): ?><span class="dashboard-nav-count"><?= number_format($unreadNotificationCount) ?></span><?php endif; ?></li>
            <li data-nav-url="/tickets" class="<?= $isTicketsArea ? 'active' : '' ?>">Tickets<?php if ($openTicketCount > 0): ?><span class="dashboard-nav-count"><?= number_format($openTicketCount) ?></span><?php endif; ?></li>
            <li data-nav-url="/settings" class="<?= $isSettingsArea ? 'active' : '' ?>">Settings</li>
            <li data-nav-url="/payments" class="<?= $isPaymentsArea ? 'active' : '' ?>">Payments</li>
        </ul>

        <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
        <h3 class="dashboard-account-title">Earnings</h3>
        <ul class="dashboard-nav">
            <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                <li data-nav-url="/rewards" class="<?= $isRewardsArea ? 'active' : '' ?>">My Rewards</li>
                <li data-nav-url="/affiliate" class="<?= $isAffiliateArea ? 'active' : '' ?>">Affiliate</li>
                <?php if ($showPromotionsLink): ?>
                    <li data-nav-url="/promotions" class="<?= $isPromotionsArea ? 'active' : '' ?>">Promotions</li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
