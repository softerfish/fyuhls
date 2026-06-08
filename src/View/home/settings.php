<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Account Settings - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .settings-shell { margin-top: 1rem; }
    .settings-toolbar-note { font-size: 0.84rem; color: var(--text-muted); max-width: 620px; line-height: 1.55; }
    .settings-page { display: grid; gap: 1.25rem; }
    .settings-hero,
    .settings-card,
    .settings-token-reveal {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: 0 6px 20px rgba(15, 23, 42, 0.04);
    }
    .settings-hero { padding: 1.2rem 1.25rem; }
    .settings-hero-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(260px, 420px); gap: 1.25rem; align-items: start; }
    .settings-hero-title { margin: 0; font-size: 1.35rem; font-weight: 800; color: #0f172a; }
    .settings-hero-copy { margin: 0.45rem 0 0; font-size: 0.94rem; line-height: 1.65; color: var(--text-muted); max-width: 760px; }
    .settings-anchor-nav { display: flex; flex-wrap: wrap; gap: 0.65rem; margin-top: 1rem; }
    .settings-anchor-link {
        display: inline-flex;
        align-items: center;
        min-height: 38px;
        padding: 0.48rem 0.82rem;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-size: 0.8rem;
        font-weight: 700;
        text-decoration: none;
    }
    .settings-anchor-link:hover { color: var(--primary-color); border-color: #c7d2fe; background: #eef2ff; }
    .settings-summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
    .settings-summary-card {
        padding: 0.85rem 0.95rem;
        border-radius: 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }
    .settings-summary-label { display: block; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.35rem; }
    .settings-summary-value {
        display: block;
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.3;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .settings-summary-meta { display: block; margin-top: 0.28rem; color: var(--text-muted); font-size: 0.77rem; line-height: 1.45; }
    .settings-section-stack { display: grid; gap: 1.25rem; }
    .settings-card__head { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
    .settings-card__body { padding: 1.25rem; }
    .settings-card__title { margin: 0; font-size: 1rem; font-weight: 800; color: #0f172a; }
    .settings-card__copy { margin: 0.25rem 0 0; font-size: 0.84rem; line-height: 1.6; color: var(--text-muted); max-width: 760px; }
    .settings-card__meta { font-size: 0.78rem; color: var(--text-muted); font-weight: 600; }
    .settings-form { display: grid; gap: 1.1rem; max-width: none; }
    .settings-subsection + .settings-subsection { margin-top: 1.4rem; padding-top: 1.4rem; border-top: 1px solid #e2e8f0; }
    .settings-subsection--separated { margin-top: 1.6rem; padding-top: 1.35rem; border-top: 1px solid #e2e8f0; }
    .settings-subsection-title { margin: 0 0 0.35rem; font-size: 0.9rem; font-weight: 800; color: #0f172a; }
    .settings-subsection-copy { margin: 0 0 1rem; font-size: 0.82rem; line-height: 1.55; color: var(--text-muted); }
    .settings-grid-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem 1.25rem; }
    .settings-grid-single { display: grid; gap: 1rem; }
    .settings-input-disabled { background: #f1f5f9; }
    .settings-select, .settings-password-input, .settings-token-input { width: 100%; border: 1px solid var(--border-color); }
    .settings-select { padding: 0.7rem; border-radius: 10px; }
    .settings-password-input, .settings-token-input { padding: 0.75rem; border-radius: 8px; }
    .settings-save-btn { margin-top: 0.5rem; width: auto; padding-left: 2.5rem; padding-right: 2.5rem; }
    .settings-password-actions { margin-top: 1rem; }
    .settings-security-form { margin-bottom: 1.5rem; }
    .settings-password-btn, .settings-setup-2fa-btn, .settings-token-btn, .settings-token-revoke { width: auto; }
    .settings-password-btn { padding-left: 2rem; padding-right: 2rem; background: #f1f5f9; border: 1px solid var(--border-color); }
    .settings-2fa-card { padding: 1.15rem 1.1rem; border-radius: 14px; display: flex; align-items: center; justify-content: space-between; gap: 1.25rem; }
    .settings-2fa-card--enabled { background: #f0fdf4; border: 1px solid #dcfce7; }
    .settings-2fa-card--disabled { background: #fdf2f2; border: 1px solid #fee2e2; }
    .settings-2fa-title { margin-top: 0; margin-bottom: 0.5rem; }
    .settings-2fa-title--enabled, .settings-2fa-copy--enabled { color: #166534; }
    .settings-2fa-title--disabled, .settings-2fa-copy--disabled { color: #991b1b; }
    .settings-2fa-copy { margin: 0; font-size: 0.8125rem; opacity: 0.8; }
    .settings-setup-2fa-btn { padding: 0.5rem 1.5rem; }
    .settings-token-success { margin-bottom: 1.5rem; }
    .settings-token-reveal { margin-bottom: 0; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 16px; padding: 1rem 1.25rem; }
    .settings-token-reveal-title { margin: 0 0 0.35rem; font-weight: 700; color: #166534; }
    .settings-token-reveal-copy { margin: 0; color: #166534; font-size: 0.875rem; }
    .settings-token-reveal-actions { margin-top: 0.9rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
    .settings-token-value { margin-top: 0.75rem; font-family: monospace; word-break: break-all; background: #0f172a; color: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px; }
    .settings-token-copy-btn { width: auto; }
    .settings-token-copy-status { margin: 0; font-size: 0.8125rem; color: var(--text-muted); }
    .settings-token-reveal-hint { margin: 0; color: var(--text-muted); font-size: 0.8125rem; }
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
    .settings-inline-status {
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        padding: 0.34rem 0.65rem;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 0.76rem;
        font-weight: 700;
    }
    .settings-inline-status--safe { background: #ecfdf5; color: #047857; }
    .settings-inline-status--warn { background: #fff7ed; color: #c2410c; }
    .settings-danger-note {
        padding: 0.9rem 1rem;
        border-radius: 12px;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #9a3412;
        font-size: 0.82rem;
        line-height: 1.55;
    }
    .settings-info-note {
        padding: 0.85rem 0.95rem;
        border-radius: 12px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #1d4ed8;
        font-size: 0.82rem;
        line-height: 1.55;
    }
    @media (max-width: 1120px) {
        .settings-hero-grid { grid-template-columns: 1fr; }
        .settings-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 900px) {
        .settings-grid-two,
        .settings-token-grid,
        .settings-summary-grid { grid-template-columns: 1fr; }
        .settings-2fa-card,
        .settings-card__head { flex-direction: column; align-items: stretch; }
    }
</style>';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/account_sidebar_styles.php';

$settingsPlanType = \App\Service\AccountPlanStatusService::levelType($user, null);
$settingsIsPaidPlan = \App\Service\AccountPlanStatusService::isPaidAccessLevel($user, null) && $settingsPlanType !== 'admin';
$settingsPlanStatus = \App\Service\AccountPlanStatusService::statusLabel($user, null);
$apiTokenCount = is_array($apiTokens ?? null) ? count($apiTokens) : 0;
$paymentDetailsConfigured = !empty(trim((string)($user['payment_details'] ?? '')));
$settingsRewardsEnabled = \App\Service\FeatureService::rewardsEnabled();
$settingsToolbarNote = $settingsRewardsEnabled
    ? 'Update your account preferences, security, and payout details here.'
    : 'Update your account preferences, security, and API access here.';
$settingsProfileCopy = $settingsRewardsEnabled
    ? 'Adjust the everyday settings that shape how your account behaves, including your timezone, default file privacy, and any payout preferences tied to rewards.'
    : 'Adjust the everyday settings that shape how your account behaves, including your timezone and default file privacy.';
$settingsTwoFactorCopy = $settingsRewardsEnabled
    ? 'An authenticator app adds an extra code step when you sign in, which is one of the best ways to protect payout access and file ownership.'
    : 'An authenticator app adds an extra code step when you sign in, which is one of the best ways to protect your files and account access.';
$settingsTimezoneChoices = timezone_identifiers_list();
$settingsCurrentTimezone = (string)($user['timezone'] ?? 'UTC');
if (!in_array($settingsCurrentTimezone, $settingsTimezoneChoices, true)) {
    $settingsCurrentTimezone = 'UTC';
}
$pendingEmail = trim((string)($user['pending_email'] ?? ''));
$is2faEnabled = false;
if (\App\Service\FeatureService::twoFactorEnabled()) {
    $db = \App\Core\Database::getInstance()->getConnection();
    if ($db) {
        $stmt = $db->prepare("SELECT is_enabled FROM user_two_factor WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $is2faEnabled = (bool)$stmt->fetchColumn();
    }
}
?>

<div class="fm-container settings-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>

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
                    <span class="settings-toolbar-note"><?= htmlspecialchars($settingsToolbarNote) ?></span>
                </div>
            </div>
        </div>

        <div class="settings-page">
            <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
            <?php if ($success && empty($newApiToken)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
            <?php if (!empty($newApiToken)): ?>
                <div class="settings-token-reveal" id="newApiTokenReveal">
                    <p class="settings-token-reveal-title">API token created. Copy it now.</p>
                    <p class="settings-token-reveal-copy">This full token is only shown once. The saved token list below only shows a shortened preview.</p>
                    <div class="settings-token-value" id="newApiTokenValue"><?= htmlspecialchars($newApiToken) ?></div>
                    <div class="settings-token-reveal-actions">
                        <button type="button" class="btn btn-primary settings-token-copy-btn" id="copyNewApiTokenButton">Copy Token</button>
                        <span class="settings-token-copy-status" id="copyNewApiTokenStatus" aria-live="polite"></span>
                        <a href="#apiSection" class="settings-token-reveal-hint">Jump to the API Tokens section</a>
                    </div>
                </div>
            <?php endif; ?>

            <section class="settings-hero">
                <div class="settings-hero-grid">
                    <div>
                        <h3 class="settings-hero-title">Manage your account, security, and connected tools</h3>
                        <p class="settings-hero-copy">Use this area to update your file defaults, keep your account secure, set payout details when rewards are enabled, and control any API tokens tied to desktop tools or outside apps.</p>
                        <div class="settings-anchor-nav">
                            <a href="#profileSection" class="settings-anchor-link">Profile & Preferences</a>
                            <a href="#securitySection" class="settings-anchor-link">Security</a>
                            <?php if ($settingsRewardsEnabled): ?>
                                <a href="#rewardsSection" class="settings-anchor-link">Rewards & Payout</a>
                            <?php endif; ?>
                            <a href="#apiSection" class="settings-anchor-link">API Tokens</a>
                        </div>
                    </div>
                    <div class="settings-summary-grid">
                        <div class="settings-summary-card">
                            <span class="settings-summary-label">Email</span>
                            <span class="settings-summary-value"><?= htmlspecialchars($user['email']) ?></span>
                            <span class="settings-summary-meta">
                                <?php if ($pendingEmail !== ''): ?>
                                    Pending change to <?= htmlspecialchars($pendingEmail) ?> after confirmation.
                                <?php else: ?>
                                    Your login and account alerts go here.
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="settings-summary-card">
                            <span class="settings-summary-label">Plan</span>
                            <span class="settings-summary-value"><?= htmlspecialchars($user['package_name'] ?? 'Free Plan') ?></span>
                            <span class="settings-summary-meta"><?= htmlspecialchars($settingsPlanStatus) ?></span>
                        </div>
                        <div class="settings-summary-card">
                            <span class="settings-summary-label">Two-Factor</span>
                            <span class="settings-summary-value"><?= $is2faEnabled ? 'Enabled' : 'Not Enabled' ?></span>
                            <span class="settings-summary-meta"><?= $is2faEnabled ? 'Extra sign-in protection is active.' : 'Add an authenticator app for stronger account security.' ?></span>
                        </div>
                        <div class="settings-summary-card">
                            <span class="settings-summary-label">API Tokens</span>
                            <span class="settings-summary-value"><?= (int)$apiTokenCount ?></span>
                            <span class="settings-summary-meta"><?= $apiTokenCount > 0 ? 'Review access for desktop tools and integrations.' : 'Create tokens only for tools you trust.' ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <div class="settings-section-stack">
                <section id="profileSection" class="settings-card">
                    <div class="settings-card__head">
                        <div>
                            <h3 class="settings-card__title">Profile & Preferences</h3>
                            <p class="settings-card__copy"><?= htmlspecialchars($settingsProfileCopy) ?></p>
                        </div>
                        <div class="settings-card__meta">Most users only need this section for routine updates.</div>
                    </div>
                    <div class="settings-card__body">
                        <form method="POST" class="auth-form settings-form settings-security-form">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="profile">

                            <div class="settings-subsection">
                                <h4 class="settings-subsection-title">Account Identity</h4>
                                <p class="settings-subsection-copy">Your username and email are shown here for reference. Contact support if you need help changing locked identity details.</p>
                                <div class="settings-grid-two">
                                    <div class="form-group">
                                        <label>Username</label>
                                        <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled class="settings-input-disabled">
                                    </div>
                                    <div class="form-group">
                                        <label>Email Address</label>
                                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="form-control settings-select" required>
                                    </div>
                                </div>
                                <div class="settings-info-note">
                                    Changing your email sends a confirmation link to the new address. Your current login email stays active until that link is opened.
                                    <?php if ($pendingEmail !== ''): ?>
                                        <br><strong>Pending confirmation:</strong> <?= htmlspecialchars($pendingEmail) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label>Current Password</label>
                                    <input type="password" name="profile_current_password" autocomplete="current-password" class="form-control settings-password-input">
                                    <small class="text-muted">Required if you change your email address.</small>
                                </div>
                            </div>

                            <div class="settings-subsection">
                                <h4 class="settings-subsection-title">Workspace Defaults</h4>
                                <p class="settings-subsection-copy">These defaults help new uploads behave the way you expect without changing them file by file.</p>
                                <div class="settings-grid-two">
                                    <div class="form-group">
                                        <label>Timezone</label>
                                        <select name="timezone" class="form-control settings-select">
                                            <?php foreach ($settingsTimezoneChoices as $timezoneId): ?>
                                                <option value="<?= htmlspecialchars($timezoneId) ?>" <?= $settingsCurrentTimezone === $timezoneId ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($timezoneId) ?>
                                                </option>
                                            <?php endforeach; ?>
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
                            </div>

                            <?php if ($settingsRewardsEnabled): ?>
                                <div id="rewardsSection" class="settings-subsection">
                                    <h4 class="settings-subsection-title">Rewards & Payout</h4>
                                    <p class="settings-subsection-copy">Choose how you want to earn, then make sure your payout details are correct before you request withdrawals.</p>
                                    <?php include __DIR__ . '/partials/reward_settings_fields.php'; ?>
                                    <div class="form-group" style="margin-top: 1rem;">
                                        <label>Current Password</label>
                                        <input type="password" name="payout_current_password" autocomplete="current-password" class="form-control settings-password-input">
                                        <small class="text-muted">Required only when you change payout processor or payout destination.</small>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary settings-save-btn">Save Profile Settings</button>
                        </form>
                    </div>
                </section>

                <section id="securitySection" class="settings-card">
                    <div class="settings-card__head">
                        <div>
                            <h3 class="settings-card__title">Security</h3>
                            <p class="settings-card__copy">Use this section for sensitive account protections like your password and two-factor authentication. These controls affect how you sign in and how hard it is for someone else to get in.</p>
                        </div>
                        <div class="settings-inline-status <?= $is2faEnabled ? 'settings-inline-status--safe' : 'settings-inline-status--warn' ?>">
                            <?= $is2faEnabled ? '2FA Enabled' : '2FA Recommended' ?>
                        </div>
                    </div>
                    <div class="settings-card__body">
                        <form method="POST" class="auth-form settings-form">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="password">

                            <div class="settings-subsection">
                                <h4 class="settings-subsection-title">Password</h4>
                                <p class="settings-subsection-copy">Change your password when you want to rotate access, respond to a suspected compromise, or tighten your account security.</p>
                                <div class="settings-grid-single">
                                    <div class="form-group">
                                        <label>Current Password</label>
                                        <input type="password" name="current_password" required autocomplete="current-password" class="form-control settings-password-input">
                                    </div>
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
                            </div>
                        </form>

                        <?php if (\App\Service\FeatureService::twoFactorEnabled()): ?>
                            <div class="settings-subsection settings-subsection--separated">
                                <h4 class="settings-subsection-title">Two-Factor Authentication</h4>
                                <p class="settings-subsection-copy"><?= htmlspecialchars($settingsTwoFactorCopy) ?></p>
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
                                <div class="settings-danger-note" style="margin-top: 1rem;">If you reuse your password anywhere else, change it there too. Two-factor helps a lot, but it works best when your password is unique.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section id="apiSection" class="settings-card">
                    <div class="settings-card__head">
                        <div>
                            <h3 class="settings-card__title">API Tokens</h3>
                            <p class="settings-card__copy">Create personal tokens for desktop tools and external integrations. Only enable the scopes a tool actually needs, and revoke anything you no longer trust or use.</p>
                        </div>
                        <div class="settings-inline-status"><?= (int)$apiTokenCount ?> Active / Saved</div>
                    </div>
                    <div class="settings-card__body">
                        <?php if (!empty($newApiToken)): ?>
                            <div class="alert alert-success settings-token-success">
                                <strong>The list below only shows shortened token previews.</strong>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="auth-form settings-token-form">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="action" value="api_token_create">

                            <div class="settings-subsection" style="padding-top:0; border-top:0; margin-top:0;">
                                <h4 class="settings-subsection-title">Create a New Token</h4>
                                <p class="settings-subsection-copy">Good for uploaders, desktop sync tools, and tightly scoped integrations that should not use your password.</p>

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
                                        <label class="settings-scope-option"><input type="checkbox" name="token_scopes[]" value="files.read" checked> Read files, folders, and create download links</label>
                                        <label class="settings-scope-option"><input type="checkbox" name="token_scopes[]" value="files.write"> Create folders and manage file or folder changes</label>
                                        <?php if ($settingsRewardsEnabled): ?>
                                            <label class="settings-scope-option"><input type="checkbox" name="token_scopes[]" value="stats.read"> Read earnings and payout stats</label>
                                        <?php endif; ?>
                                        <label class="settings-scope-option"><input type="checkbox" name="token_scopes[]" value="remote.upload"> Create and manage remote URL uploads</label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Current Password</label>
                                    <input type="password" name="token_current_password" autocomplete="current-password" class="form-control settings-token-input" required>
                                    <small class="text-muted">Required before creating a long-lived API token.</small>
                                </div>

                                <button type="submit" class="btn btn-primary settings-token-btn">Create API Token</button>
                            </div>
                        </form>

                        <div class="settings-subsection">
                            <h4 class="settings-subsection-title">Saved Tokens</h4>
                            <p class="settings-subsection-copy">Review token age, expiry, and last use. Revoke anything you do not recognize or no longer need.</p>
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
                                                <form method="POST" class="m-0 settings-token-revoke-form">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <input type="hidden" name="action" value="api_token_revoke">
                                                    <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                                                    <button
                                                        type="button"
                                                        class="btn settings-token-revoke settings-token-revoke-btn"
                                                        data-token-name="<?= htmlspecialchars($token['name']) ?>"
                                                    >Revoke</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="settings-token-revoked">Revoked</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const copyButton = document.getElementById('copyNewApiTokenButton');
    const tokenValue = document.getElementById('newApiTokenValue');
    const status = document.getElementById('copyNewApiTokenStatus');
    if (!copyButton || !tokenValue) {
        return;
    }

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const fallbackCopy = (text) => {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.setAttribute('readonly', '');
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        document.body.removeChild(textArea);
        return copied;
    };

    copyButton.addEventListener('click', async () => {
        const text = tokenValue.textContent.trim();
        let copied = false;
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                copied = true;
            } catch (error) {
                copied = false;
            }
        }
        if (!copied) {
            copied = fallbackCopy(text);
        }

        if (copied) {
            setStatus('Copied to clipboard.');
            copyButton.textContent = 'Copied';
        } else {
            setStatus('Copy failed. Select the token and copy it manually.');
            copyButton.textContent = 'Copy Failed';
        }
    });

    document.querySelectorAll('.settings-token-revoke-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const tokenName = button.getAttribute('data-token-name') || 'this API token';
            const confirmed = window.confirm(`Revoke "${tokenName}"? Any app or uploader using it will stop working immediately.`);
            if (!confirmed) {
                return;
            }
            const form = button.closest('.settings-token-revoke-form');
            if (form) {
                form.submit();
            }
        });
    });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
