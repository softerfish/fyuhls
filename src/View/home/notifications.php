<?php 
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Notifications - {$siteName}";
$extraHead = '<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">';
include __DIR__ . '/header.php'; 
?>

<div class="fm-container" style="margin-top: 1rem;">
    <div class="fm-sidebar">
        <div class="sidebar-section">
            <div style="text-align: center; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color);">
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
                <div style="margin-bottom: 0.25rem; font-size: 0.875rem; color: var(--text-color); font-weight: 600;">
                    Current Plan: <span style="color: var(--primary-color);"><?= htmlspecialchars($pkgNameStr) ?></span>
                </div>
                <div style="margin-bottom: <?= $isPaidPlan ? '0.5rem' : '1.25rem' ?>; font-size: 0.75rem; color: var(--text-muted);">
                    <?= htmlspecialchars($expiryStr) ?>
                </div>
                <?php if (!$isPaidPlan): ?>
                    <button class="btn btn-warning" onclick="location.href='/#pricing'" style="width: auto; padding: 0.5rem 1.5rem;">View Plans</button>
                <?php endif; ?>
            </div>
            <h3 style="margin-top: 0;">Account</h3>
            <ul style="list-style: none; padding: 0.5rem 0; margin: 0;">
                <li onclick="location.href='/'">All Files</li>
                <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                    <li onclick="location.href='/rewards'">My Rewards</li>
                    <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                        <li onclick="location.href='/affiliate'">Affiliate</li>
                    <?php endif; ?>
                <?php endif; ?>
                <li onclick="location.href='/settings'">Settings</li>
                <li onclick="location.href='/recent'">Recent</li>
                <li onclick="location.href='/shared'">Shared</li>
                <li class="sidebar-trash-item" style="padding: 0; display: flex; justify-content: space-between; align-items: center; min-height: 40px;">
                    <span onclick="location.href='/trash'" style="flex: 1; padding: 0.6rem 0.75rem; display: block;">Trash</span>
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
                <div class="toolbar-controls" style="display: flex !important; align-items: center !important; gap: 12px !important; flex-wrap: nowrap !important; width: auto !important; min-width: 280px !important; justify-content: flex-end !important; position: relative !important; z-index: 10 !important;">
                    <span style="font-size: 0.8125rem; color: var(--text-muted);">Account activity, payout updates, and system notices.</span>
                    <button class="btn btn-primary" id="markReadBtn" style="white-space: nowrap;">Mark all as read</button>
                </div>
            </div>
        </div>

        <div class="notification-list" style="background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: hidden;">
            <?php if (empty($notifications)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 5rem 2rem;">
                    <div style="font-size: 3.5rem; margin-bottom: 1.5rem;">Inbox</div>
                    <h3 style="margin: 0; color: var(--text-color);">No notifications yet</h3>
                    <p style="margin-top: 0.5rem; font-size: 0.875rem;">We'll let you know when something important happens.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                    <div class="notification-item" style="padding: 1.5rem; border-bottom: 1px solid var(--border-color); transition: background 0.2s; <?= !$n['is_read'] ? 'background: #f0f7ff; border-left: 4px solid var(--primary-color);' : '' ?>">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                            <div>
                                <h4 style="margin: 0; font-size: 1rem; color: var(--secondary-color); font-weight: 600;"><?= htmlspecialchars($n['title']) ?></h4>
                                <p style="margin: 0.5rem 0 0; color: var(--text-muted); font-size: 0.875rem; line-height: 1.5;"><?= htmlspecialchars($n['message']) ?></p>
                            </div>
                            <small style="color: var(--text-muted); white-space: nowrap; font-size: 0.75rem; font-weight: 500; background: #f1f5f9; padding: 0.25rem 0.5rem; border-radius: 4px;">
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
