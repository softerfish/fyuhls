<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Account Settings - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .settings-shell { margin-top: 1rem; }
    .settings-plan-card { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
    .settings-plan-current { margin-bottom: 0.25rem; font-size: 0.875rem; color: var(--text-color); font-weight: 600; }
    .settings-plan-name { color: var(--primary-color); }
    .settings-plan-expiry { font-size: 0.75rem; color: var(--text-muted); }
    .settings-plan-limit { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem; }
    .settings-plan-expiry--tight { margin-bottom: 0.5rem; }
    .settings-plan-expiry--wide { margin-bottom: 1.25rem; }
    .settings-plan-button { width: auto; padding: 0.5rem 1.5rem; }
    .settings-account-title { margin-top: 0; }
    .settings-nav { list-style: none; padding: 0.5rem 0; margin: 0; }
    .settings-trash-item { padding: 0; display: flex; justify-content: space-between; align-items: center; min-height: 40px; }
    .settings-trash-link { flex: 1; padding: 0.6rem 0.75rem; display: block; }
    .settings-toolbar-note { font-size: 0.8125rem; color: var(--text-muted); }
    .settings-status-card { background: #f8fafc; padding: 2.5rem; border-radius: 12px; margin-bottom: 3rem; border: 1px solid var(--border-color); text-align: center; }
    .settings-status-stack { display: flex; flex-direction: column; gap: 1rem; align-items: center; }
    .settings-status-title { margin: 0; font-size: 1.125rem; }
    .settings-status-name { color: var(--primary-color); }
    .settings-status-copy { margin: 0; font-size: 0.875rem; color: var(--text-muted); }
    .settings-form { max-width: 800px; }
    .settings-grid-two { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    .settings-input-disabled { background: #f1f5f9; }
    .settings-select, .settings-password-input, .settings-token-input { width: 100%; border: 1px solid var(--border-color); }
    .settings-select { padding: 0.625rem; border-radius: 6px; }
    .settings-password-input, .settings-token-input { padding: 0.75rem; border-radius: 8px; }
    .settings-save-btn { margin-top: 1.5rem; width: auto; padding-left: 2.5rem; padding-right: 2.5rem; }
    .settings-section { margin-top: 4rem; padding-top: 3rem; border-top: 1px solid var(--border-color); max-width: 800px; }
    .settings-section-title { margin-top: 0; margin-bottom: 1.5rem; }
    .settings-field-spacer { margin-bottom: 1.5rem; }
    .settings-password-actions { margin-top: 2rem; }
    .settings-password-btn, .settings-setup-2fa-btn, .settings-token-btn, .settings-token-revoke { width: auto; }
    .settings-password-btn { padding-left: 2rem; padding-right: 2rem; background: #f1f5f9; border: 1px solid var(--border-color); }
    .settings-2fa-card { margin-top: 3rem; padding: 2rem; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; gap: 2rem; }
    .settings-2fa-card--enabled { background: #f0fdf4; border: 1px solid #dcfce7; }
    .settings-2fa-card--disabled { background: #fdf2f2; border: 1px solid #fee2e2; }
    .settings-2fa-title { margin-top: 0; margin-bottom: 0.5rem; }
    .settings-2fa-title--enabled, .settings-2fa-copy--enabled { color: #166534; }
    .settings-2fa-title--disabled, .settings-2fa-copy--disabled { color: #991b1b; }
    .settings-2fa-copy { margin: 0; font-size: 0.8125rem; opacity: 0.8; }
    .settings-setup-2fa-btn { padding: 0.5rem 1.5rem; }
    .settings-section-copy { font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem; }
    .settings-token-success { margin-bottom: 1.5rem; }
    .settings-token-value { margin-top: 0.75rem; font-family: monospace; word-break: break-all; background: #0f172a; color: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px; }
    .settings-token-form { margin-bottom: 2rem; }
    .settings-token-grid { display: grid; grid-template-columns: 1.4fr 0.8fr; gap: 1rem; }
    .settings-scopes { margin-top: 1rem; }
    .settings-scopes-list { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem; }
    .settings-scope-option { display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; }
    .auth-form .settings-scope-option input[type="checkbox"] { width: auto; margin: 0; flex-shrink: 0; cursor: pointer; }
    .settings-token-btn { margin-top: 1.5rem; padding-left: 2rem; padding-right: 2rem; }
    .settings-token-list { border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; }
    .settings-token-empty { padding: 1rem 1.25rem; color: var(--text-muted); }
    .settings-token-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.25rem; border-top: 1px solid var(--border-color); }
    .settings-token-name { font-weight: 700; }
    .settings-token-meta, .settings-token-scopes { color: var(--text-muted); margin-top: 0.25rem; }
    .settings-token-meta { font-size: 0.8rem; }
    .settings-token-scopes { font-size: 0.75rem; }
    .settings-token-revoke { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .settings-token-revoked { font-size: 0.8rem; color: #b91c1c; font-weight: 700; }
    .settings-history-list { display: grid; gap: 1rem; }
    .settings-history-item { border: 1px solid var(--border-color); border-radius: 12px; padding: 1rem 1.1rem; background: #fff; }
    .settings-history-title { font-weight: 700; margin-bottom: 0.35rem; }
    .settings-history-meta { color: var(--text-muted); font-size: 0.8125rem; margin-bottom: 0.5rem; }
    .settings-history-reason { font-size: 0.875rem; color: var(--text-color); }
    .settings-history-empty { color: var(--text-muted); font-size: 0.875rem; }
</style>';
include __DIR__ . '/header.php';
?>

<div class="fm-container settings-shell">
    <div class="fm-sidebar">
        <div class="sidebar-section">
            <div class="settings-plan-card">
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
                <div class="settings-plan-current">
                    Current Plan: <span class="settings-plan-name"><?= htmlspecialchars($pkgNameStr) ?></span>
                </div>
                <div class="settings-plan-expiry <?= $isPaidPlan ? 'settings-plan-expiry--tight' : 'settings-plan-expiry--wide' ?>">
                    <?= htmlspecialchars($expiryStr) ?>
                </div>
                <?php if (!empty($dailyDownloadLimitSummary['label']) && array_key_exists('value', $dailyDownloadLimitSummary)): ?>
                    <div class="settings-plan-limit">
                        <?= htmlspecialchars($dailyDownloadLimitSummary['label']) ?>: <?= htmlspecialchars($dailyDownloadLimitSummary['value']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isPaidPlan): ?>
                    <button class="btn btn-warning settings-plan-button" data-nav-url="/#pricing">View Plans</button>
                <?php endif; ?>
            </div>
            <h3 class="settings-account-title">Account</h3>
            <ul class="settings-nav">
                <li data-nav-url="/">All Files</li>
                <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                    <li data-nav-url="/rewards">My Rewards</li>
                    <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                        <li data-nav-url="/affiliate">Affiliate</li>
                    <?php endif; ?>
                <?php endif; ?>
                <li data-nav-url="/settings" class="active">Settings</li>
                <li data-nav-url="/recent">Recent</li>
                <li data-nav-url="/shared">Shared</li>
                <li class="sidebar-trash-item settings-trash-item">
                    <span data-nav-url="/trash" class="settings-trash-link">Trash</span>
                </li>
            </ul>
        </div>
    </div>

    <div class="fm-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Account Settings</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Settings</span>
                </div>
            </div>

            <div class="toolbar-right">
                <div class="toolbar-controls">
                    <span class="settings-toolbar-note">Update your account preferences, security, and payout details here.</span>
                </div>
            </div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

        <!-- Account Status Section -->
        <section id="statusSection" class="settings-status-card">
            <div class="settings-status-stack">
                <h3 class="settings-status-title">You are on the <span class="settings-status-name"><?= htmlspecialchars($user['package_name'] ?? 'Free Plan') ?></span></h3>
                <?php if (!empty($user['premium_expiry'])): ?>
                    <p class="settings-status-copy">Your premium features expire on: <?= date('M d, Y', strtotime($user['premium_expiry'])) ?></p>
                <?php else: ?>
                    <p class="settings-status-copy">This is a lifetime free account.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($fileDeletionHistory)): ?>
        <section class="settings-section">
            <h3 class="settings-section-title">Deleted File History</h3>
            <p class="settings-section-copy">This shows files removed from your account, including admin removals and the recorded reason when one was supplied.</p>
            <div class="settings-history-list">
                <?php foreach ($fileDeletionHistory as $entry): ?>
                    <?php
                    $actorLabel = trim((string)($entry['deleted_by_label'] ?? ''));
                    if ($actorLabel === '') {
                        $actorLabel = (($entry['deleted_by_role'] ?? '') === 'admin') ? 'Administrator' : 'You';
                    }
                    $reason = trim((string)($entry['delete_reason'] ?? ''));
                    ?>
                    <article class="settings-history-item">
                        <div class="settings-history-title"><?= htmlspecialchars((string)($entry['original_filename'] ?? 'Deleted file')) ?></div>
                        <div class="settings-history-meta">
                            Removed <?= htmlspecialchars(date('M d, Y H:i', strtotime((string)$entry['deleted_at']))) ?> by <?= htmlspecialchars($actorLabel) ?>
                        </div>
                        <div class="settings-history-reason">
                            <?= $reason !== '' ? htmlspecialchars($reason) : 'No reason was recorded for this deletion.' ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <form method="POST" class="auth-form settings-form">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="profile">
            
            <div class="settings-grid-two">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled class="settings-input-disabled">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="text" value="<?= htmlspecialchars($user['email']) ?>" disabled class="settings-input-disabled">
                </div>
            </div>

            <div class="settings-grid-two">
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="timezone" class="form-control settings-select">
                        <option value="UTC" <?= $user['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC / GMT</option>
                        <option value="America/New_York" <?= $user['timezone'] === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (US)</option>
                        <option value="Europe/London" <?= $user['timezone'] === 'Europe/London' ? 'selected' : '' ?>>London / Western Europe</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Default File Privacy</label>
                    <select name="default_privacy" class="form-control settings-select">
                        <option value="public" <?= $user['default_privacy'] === 'public' ? 'selected' : '' ?>>Public (Accessible via link)</option>
                        <option value="private" <?= $user['default_privacy'] === 'private' ? 'selected' : '' ?>>Private (Only you can access)</option>
                    </select>
                </div>
            </div>

            <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                <?php include __DIR__ . '/partials/reward_settings_fields.php'; ?>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary settings-save-btn">Save General Settings</button>
        </form>

        <section id="securitySection" class="settings-section">
            <h3 class="settings-section-title">Security & Password</h3>
            
            <form method="POST" class="auth-form">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="password">
                
                <div class="form-group settings-field-spacer">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required autocomplete="current-password" class="form-control settings-password-input">
                </div>
                <div class="settings-grid-two">
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required autocomplete="new-password" class="form-control settings-password-input">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required autocomplete="new-password" class="form-control settings-password-input">
                    </div>
                </div>
                <div class="settings-password-actions">
                    <button type="submit" class="btn settings-password-btn">Update Password</button>
                </div>
            </form>

            <?php if (\App\Service\FeatureService::twoFactorEnabled()): ?>
                <?php
                $db = \App\Core\Database::getInstance()->getConnection();
                if ($db) {
                    $stmt = $db->prepare("SELECT is_enabled FROM user_two_factor WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                    $is2faEnabled = (bool)$stmt->fetchColumn();
                } else { $is2faEnabled = false; }
                ?>
                <div class="settings-2fa-card <?= $is2faEnabled ? 'settings-2fa-card--enabled' : 'settings-2fa-card--disabled' ?>">
                    <div>
                        <h4 class="settings-2fa-title <?= $is2faEnabled ? 'settings-2fa-title--enabled' : 'settings-2fa-title--disabled' ?>">
                            <i class="bi bi-shield-check me-2"></i>Two-Factor Authentication
                        </h4>
                        <p class="settings-2fa-copy <?= $is2faEnabled ? 'settings-2fa-copy--enabled' : 'settings-2fa-copy--disabled' ?>">
                            <?= $is2faEnabled ? 'Your account is currently protected by an extra layer of security.' : 'Add an extra layer of security to your account using an authenticator app (TOTP).' ?>
                        </p>
                    </div>
                    <div>
                        <?php if ($is2faEnabled): ?>
                            <span class="badge bg-success py-2 px-3">Enabled</span>
                        <?php else: ?>
                            <a href="/2fa/setup" class="btn btn-primary settings-setup-2fa-btn">Setup 2FA</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section id="apiSection" class="settings-section">
            <h3 class="settings-section-title">API Tokens</h3>
            <p class="settings-section-copy">Use personal API tokens for desktop tools and external integrations. Tokens are tied to your account, your quota, and your package limits.</p>

            <?php if (!empty($newApiToken)): ?>
                <div class="alert alert-success settings-token-success">
                    <strong>Copy this token now.</strong>
                    <div class="settings-token-value"><?= htmlspecialchars($newApiToken) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form settings-token-form">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="api_token_create">

                <div class="settings-token-grid">
                    <div class="form-group">
                        <label>Token Name</label>
                        <input type="text" name="token_name" value="Desktop API Token" maxlength="100" required class="form-control settings-token-input">
                    </div>
                    <div class="form-group">
                        <label>Expires In</label>
                        <select name="token_expiry_days" class="form-control settings-token-input">
                            <option value="0">Never</option>
                            <option value="30">30 days</option>
                            <option value="90" selected>90 days</option>
                            <option value="365">365 days</option>
                        </select>
                    </div>
                </div>

                <div class="form-group settings-scopes">
                    <label>Scopes</label>
                    <div class="settings-scopes-list">
                        <label class="settings-scope-option"><input type="checkbox" name="token_scopes[]" value="files.upload" checked> Upload files</label>
                        <label class="settings-scope-option"><input type="checkbox" name="token_scopes[]" value="files.read" checked> Read files and create download links</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary settings-token-btn">Create API Token</button>
            </form>

            <div class="settings-token-list">
                <?php if (empty($apiTokens)): ?>
                    <div class="settings-token-empty">No API tokens created yet.</div>
                <?php else: ?>
                    <?php foreach ($apiTokens as $token): ?>
                        <div class="settings-token-row">
                            <div>
                                <div class="settings-token-name"><?= htmlspecialchars($token['name']) ?></div>
                                <div class="settings-token-meta">
                                    <?= htmlspecialchars($token['token_prefix'] . '...' . $token['token_last_four']) ?>
                                    <?php if (!empty($token['expires_at'])): ?>
                                        &middot; Expires <?= htmlspecialchars(date('M d, Y', strtotime($token['expires_at']))) ?>
                                    <?php else: ?>
                                        &middot; No expiry
                                    <?php endif; ?>
                                    <?php if (!empty($token['last_used_at'])): ?>
                                        &middot; Last used <?= htmlspecialchars(date('M d, Y H:i', strtotime($token['last_used_at']))) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="settings-token-scopes">
                                    Scopes: <?= htmlspecialchars(implode(', ', $token['scopes'] ?? [])) ?>
                                </div>
                            </div>
                            <?php if (($token['status'] ?? 'active') === 'active'): ?>
                                <form method="POST" class="m-0">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="action" value="api_token_revoke">
                                    <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                                    <button type="submit" class="btn settings-token-revoke">Revoke</button>
                                </form>
                            <?php else: ?>
                                <span class="settings-token-revoked">Revoked</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
