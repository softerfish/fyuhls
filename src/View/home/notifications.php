<?php 
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Notifications - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .notifications-shell { margin-top: 1rem; }
    .notifications-plan-card { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
    .notifications-plan-current { margin-bottom: 0.25rem; font-size: 0.875rem; color: var(--text-color); font-weight: 600; }
    .notifications-plan-name { color: var(--primary-color); }
    .notifications-plan-expiry { font-size: 0.75rem; color: var(--text-muted); }
    .notifications-plan-limit { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem; }
    .notifications-plan-expiry--tight { margin-bottom: 0.5rem; }
    .notifications-plan-expiry--wide { margin-bottom: 1.25rem; }
    .notifications-plan-button { width: auto; padding: 0.5rem 1.5rem; }
    .notifications-account-title { margin-top: 0; }
    .notifications-nav { list-style: none; padding: 0.5rem 0; margin: 0; }
    .notifications-trash-item { padding: 0; display: flex; justify-content: space-between; align-items: center; min-height: 40px; }
    .notifications-trash-link { flex: 1; padding: 0.6rem 0.75rem; display: block; }
    .notifications-toolbar-controls { display: flex !important; align-items: center !important; gap: 12px !important; flex-wrap: nowrap !important; width: auto !important; min-width: 280px !important; justify-content: flex-end !important; position: relative !important; z-index: 10 !important; }
    .notifications-toolbar-note { font-size: 0.8125rem; color: var(--text-muted); }
    .notifications-mark-read { white-space: nowrap; }
    .notifications-list { background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: hidden; }
    .notifications-empty { text-align: center; color: var(--text-muted); padding: 5rem 2rem; }
    .notifications-empty-icon { font-size: 3.5rem; margin-bottom: 1.5rem; }
    .notifications-empty-title { margin: 0; color: var(--text-color); }
    .notifications-empty-copy { margin-top: 0.5rem; font-size: 0.875rem; }
    .notifications-item { padding: 1.5rem; border-bottom: 1px solid var(--border-color); transition: background 0.2s; }
    .notifications-item--unread { background: #f0f7ff; border-left: 4px solid var(--primary-color); }
    .notifications-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
    .notifications-title { margin: 0; font-size: 1rem; color: var(--secondary-color); font-weight: 600; }
    .notifications-message { margin: 0.5rem 0 0; color: var(--text-muted); font-size: 0.875rem; line-height: 1.5; }
    .notifications-time { color: var(--text-muted); white-space: nowrap; font-size: 0.75rem; font-weight: 500; background: #f1f5f9; padding: 0.25rem 0.5rem; border-radius: 4px; }
</style>';
include __DIR__ . '/header.php'; 
?>

<div class="fm-container notifications-shell">
    <div class="fm-sidebar">
        <div class="sidebar-section">
            <div class="notifications-plan-card">
                <?php 
                $userId = \App\Core\Auth::id();
                $pkgNameStr = 'Free Plan';
                $expiryStr = 'Lifetime Free Account';
                $userPkg = null;

                if ($userId) {
                    if (\App\Core\Auth::isAdmin()) {
                        $pkgNameStr = 'Admin';
                    } else {
                        $userPkg = \App\Model\Package::getUserPackage($userId);
                        if ($userPkg) {
                            $pkgNameStr = $userPkg['name'] ?? 'Free Plan';
                            if (!empty($userPkg['premium_expiry'])) {
                                $expiryStr = 'Renews on ' . date('M d, Y', strtotime($userPkg['premium_expiry']));
                            }
                        }
                    }
                }
                $isPaidPlan = (\App\Core\Auth::isAdmin() || strtolower((string)($userPkg['level_type'] ?? 'free')) === 'paid');
                ?>
                <div class="notifications-plan-current">
                    Current Plan: <span class="notifications-plan-name"><?= htmlspecialchars($pkgNameStr) ?></span>
                </div>
                <div class="notifications-plan-expiry <?= $isPaidPlan ? 'notifications-plan-expiry--tight' : 'notifications-plan-expiry--wide' ?>">
                    <?= htmlspecialchars($expiryStr) ?>
                </div>
                <?php if (!empty($dailyDownloadLimitSummary['label']) && array_key_exists('value', $dailyDownloadLimitSummary)): ?>
                    <div class="notifications-plan-limit">
                        <?= htmlspecialchars($dailyDownloadLimitSummary['label']) ?>: <?= htmlspecialchars($dailyDownloadLimitSummary['value']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isPaidPlan): ?>
                    <button class="btn btn-warning notifications-plan-button" data-nav-url="/#pricing">View Plans</button>
                <?php endif; ?>
            </div>
            <h3 class="notifications-account-title">Account</h3>
            <ul class="notifications-nav">
                <li data-nav-url="/">All Files</li>
                <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                    <li data-nav-url="/rewards">My Rewards</li>
                    <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                        <li data-nav-url="/affiliate">Affiliate</li>
                    <?php endif; ?>
                <?php endif; ?>
                <li data-nav-url="/settings">Settings</li>
                <li data-nav-url="/recent">Recent</li>
                <li data-nav-url="/shared">Shared</li>
                <li class="sidebar-trash-item notifications-trash-item">
                    <span data-nav-url="/trash" class="notifications-trash-link">Trash</span>
                </li>
            </ul>
        </div>
    </div>
    <div class="fm-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Your Notifications</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Notifications</span>
                </div>
            </div>

            <div class="toolbar-right">
                <div class="toolbar-controls notifications-toolbar-controls">
                    <span class="notifications-toolbar-note">Account activity, payout updates, and system notices.</span>
                    <button class="btn btn-primary notifications-mark-read" id="markReadBtn">Mark all as read</button>
                </div>
            </div>
        </div>

        <div class="notification-list notifications-list">
            <?php if (empty($notifications)): ?>
                <div class="notifications-empty">
                    <div class="notifications-empty-icon">Inbox</div>
                    <h3 class="notifications-empty-title">No notifications yet</h3>
                    <p class="notifications-empty-copy">We'll let you know when something important happens.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                    <div class="notification-item notifications-item <?= !$n['is_read'] ? 'notifications-item--unread' : '' ?>">
                        <div class="notifications-row">
                            <div>
                                <h4 class="notifications-title"><?= htmlspecialchars($n['title']) ?></h4>
                                <p class="notifications-message"><?= htmlspecialchars($n['message']) ?></p>
                            </div>
                            <small class="notifications-time">
                                <?= date('M d, H:i', strtotime($n['created_at'])) ?>
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('markReadBtn')?.addEventListener('click', () => {
    const btn = document.getElementById('markReadBtn');
    btn.disabled = true;
    btn.innerText = "Processing...";

    fetch('/notifications/read', {
        method: 'POST',
        headers: { 'X-CSRF-Token': '<?= \App\Core\Csrf::generate() ?>' }
    })
    .then(() => location.reload())
    .catch(() => {
        btn.disabled = false;
        btn.innerText = "Mark all as read";
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
