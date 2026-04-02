<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Account Settings - {$siteName}";
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
                <li onclick="location.href='/settings'" class="active">Settings</li>
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
                <h2 class="folder-title">Account Settings</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Settings</span>
                </div>
            </div>

            <div class="toolbar-right">
                <div class="toolbar-controls" style="display: flex !important; align-items: center !important; gap: 12px !important; flex-wrap: nowrap !important; width: auto !important; min-width: 280px !important; justify-content: flex-end !important; position: relative !important; z-index: 10 !important;">
                    <span style="font-size: 0.8125rem; color: var(--text-muted);">Update your account preferences, security, and payout details here.</span>
                </div>
            </div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

        <!-- Account Status Section -->
        <section id="statusSection" style="background: #f8fafc; padding: 2.5rem; border-radius: 12px; margin-bottom: 3rem; border: 1px solid var(--border-color); text-align: center;">
            <div style="display: flex; flex-direction: column; gap: 1rem; align-items: center;">
                <h3 style="margin: 0; font-size: 1.125rem;">You are on the <span style="color: var(--primary-color);"><?= htmlspecialchars($user['package_name'] ?? 'Free Plan') ?></span></h3>
                <?php if (!empty($user['premium_expiry'])): ?>
                    <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">Your premium features expire on: <?= date('M d, Y', strtotime($user['premium_expiry'])) ?></p>
                <?php else: ?>
                    <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">This is a lifetime free account.</p>
                <?php endif; ?>
            </div>
        </section>

        <form method="POST" class="auth-form" style="max-width: 800px;">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="action" value="profile">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled style="background:#f1f5f9">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="text" value="<?= htmlspecialchars($user['email']) ?>" disabled style="background:#f1f5f9">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="timezone" class="form-control" style="width: 100%; padding:0.625rem; border: 1px solid var(--border-color); border-radius:6px;">
                        <option value="UTC" <?= $user['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC / GMT</option>
                        <option value="America/New_York" <?= $user['timezone'] === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (US)</option>
                        <option value="Europe/London" <?= $user['timezone'] === 'Europe/London' ? 'selected' : '' ?>>London / Western Europe</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Default File Privacy</label>
                    <select name="default_privacy" class="form-control" style="width: 100%; padding:0.625rem; border: 1px solid var(--border-color); border-radius:6px;">
                        <option value="public" <?= $user['default_privacy'] === 'public' ? 'selected' : '' ?>>Public (Accessible via link)</option>
                        <option value="private" <?= $user['default_privacy'] === 'private' ? 'selected' : '' ?>>Private (Only you can access)</option>
                    </select>
                </div>
            </div>

            <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                <?php include __DIR__ . '/partials/reward_settings_fields.php'; ?>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary" style="margin-top: 1.5rem; width: auto; padding-left: 2.5rem; padding-right: 2.5rem;">Save General Settings</button>
        </form>

        <section id="securitySection" style="margin-top: 4rem; padding-top: 3rem; border-top: 1px solid var(--border-color); max-width: 800px;">
            <h3 style="margin-top: 0; margin-bottom: 1.5rem;">Security & Password</h3>
            
            <form method="POST" class="auth-form">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="password">
                
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label>Current Password</label>
                    <input type="password" name="current_password" required autocomplete="current-password" class="form-control" style="width: 100%; padding:0.75rem; border: 1px solid var(--border-color); border-radius:8px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required autocomplete="new-password" class="form-control" style="width: 100%; padding:0.75rem; border: 1px solid var(--border-color); border-radius:8px;">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required autocomplete="new-password" class="form-control" style="width: 100%; padding:0.75rem; border: 1px solid var(--border-color); border-radius:8px;">
                    </div>
                </div>
                <div style="margin-top: 2rem;">
                    <button type="submit" class="btn" style="width: auto; padding-left: 2rem; padding-right: 2rem; background: #f1f5f9; border: 1px solid var(--border-color);">Update Password</button>
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
                <div style="margin-top: 3rem; background: <?= $is2faEnabled ? '#f0fdf4' : '#fdf2f2' ?>; border: 1px solid <?= $is2faEnabled ? '#dcfce7' : '#fee2e2' ?>; padding: 2rem; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; gap: 2rem;">
                    <div>
                        <h4 style="margin-top: 0; margin-bottom: 0.5rem; color: <?= $is2faEnabled ? '#166534' : '#991b1b' ?>;">
                            <i class="bi bi-shield-check me-2"></i>Two-Factor Authentication
                        </h4>
                        <p style="margin: 0; font-size: 0.8125rem; color: <?= $is2faEnabled ? '#166534' : '#991b1b' ?>; opacity: 0.8;">
                            <?= $is2faEnabled ? 'Your account is currently protected by an extra layer of security.' : 'Add an extra layer of security to your account using an authenticator app (TOTP).' ?>
                        </p>
                    </div>
                    <div>
                        <?php if ($is2faEnabled): ?>
                            <span class="badge bg-success py-2 px-3">Enabled</span>
                        <?php else: ?>
                            <a href="/2fa/setup" class="btn btn-primary" style="width: auto; padding: 0.5rem 1.5rem;">Setup 2FA</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section id="apiSection" style="margin-top: 4rem; padding-top: 3rem; border-top: 1px solid var(--border-color); max-width: 800px;">
            <h3 style="margin-top: 0; margin-bottom: 1rem;">API Tokens</h3>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1.5rem;">Use personal API tokens for desktop tools and external integrations. Tokens are tied to your account, your quota, and your package limits.</p>

            <?php if (!empty($newApiToken)): ?>
                <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                    <strong>Copy this token now.</strong>
                    <div style="margin-top: 0.75rem; font-family: monospace; word-break: break-all; background: #0f172a; color: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px;"><?= htmlspecialchars($newApiToken) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form" style="margin-bottom: 2rem;">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="api_token_create">

                <div style="display: grid; grid-template-columns: 1.4fr 0.8fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Token Name</label>
                        <input type="text" name="token_name" value="Desktop API Token" maxlength="100" required class="form-control" style="width: 100%; padding:0.75rem; border: 1px solid var(--border-color); border-radius:8px;">
                    </div>
                    <div class="form-group">
                        <label>Expires In</label>
                        <select name="token_expiry_days" class="form-control" style="width: 100%; padding:0.75rem; border: 1px solid var(--border-color); border-radius:8px;">
                            <option value="0">Never</option>
                            <option value="30">30 days</option>
                            <option value="90" selected>90 days</option>
                            <option value="365">365 days</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 1rem;">
                    <label>Scopes</label>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.5rem;"><input type="checkbox" name="token_scopes[]" value="files.upload" checked> Upload files</label>
                        <label style="display: inline-flex; align-items: center; gap: 0.5rem;"><input type="checkbox" name="token_scopes[]" value="files.read" checked> Read files and create download links</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top: 1.5rem; width: auto; padding-left: 2rem; padding-right: 2rem;">Create API Token</button>
            </form>

            <div style="border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden;">
                <?php if (empty($apiTokens)): ?>
                    <div style="padding: 1rem 1.25rem; color: var(--text-muted);">No API tokens created yet.</div>
                <?php else: ?>
                    <?php foreach ($apiTokens as $token): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.25rem; border-top: 1px solid var(--border-color);">
                            <div>
                                <div style="font-weight: 700;"><?= htmlspecialchars($token['name']) ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">
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
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Scopes: <?= htmlspecialchars(implode(', ', $token['scopes'] ?? [])) ?>
                                </div>
                            </div>
                            <?php if (($token['status'] ?? 'active') === 'active'): ?>
                                <form method="POST" style="margin: 0;">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="action" value="api_token_revoke">
                                    <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                                    <button type="submit" class="btn" style="width: auto; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;">Revoke</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size: 0.8rem; color: #b91c1c; font-weight: 700;">Revoked</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
