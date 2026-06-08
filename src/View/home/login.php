<?php
$title = "Login - " . ($siteName ?? 'Fyuhls');
$loginRewardsEnabled = \App\Service\FeatureService::rewardsEnabled();
$metaDescription = $loginRewardsEnabled
    ? 'Login to access your file manager, packages, rewards, and account settings.'
    : 'Login to access your file manager, packages, and account settings.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
?>

<div class="public-form-shell">
    <div class="public-form-card auth-container">
        <h2>Sign in</h2>
        <p class="public-form-intro login-intro">
            <?= $loginRewardsEnabled
                ? 'Access your files, uploads, rewards, and account settings.'
                : 'Access your files, uploads, and account settings.' ?>
        </p>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'invalid_token'): ?>
            <div class="alert alert-error">The verification link is invalid or has expired.</div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (($_GET['reset'] ?? '') === '1'): ?>
            <div class="alert alert-success">Password reset successfully. You can now sign in.</div>
        <?php endif; ?>

        <?php if (!empty($requireVerification)): ?>
            <div class="login-soft-note">
                Email confirmation is required before you can sign in.
            </div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="username">Email address or username</label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username" autofocus placeholder="name@example.com or username">
            </div>
            <div class="form-group">
                <div class="login-password-row">
                    <label for="password">Password</label>
                    <a href="/forgot-password" class="login-forgot-link">Forgot password?</a>
                </div>
                <input type="password" name="password" id="password" required autocomplete="current-password">
            </div>
            <?php if (!empty($rememberMeEnabled)): ?>
                <div class="form-group form-group-inline">
                    <label class="login-remember-toggle">
                        <input type="checkbox" name="remember_me" value="1" <?= !empty($_POST['remember_me']) ? 'checked' : '' ?>>
                        <span>Remember me for 30 days</span>
                    </label>
                </div>
            <?php endif; ?>
            <?php
// show captcha if the shared login form is protected by either login captcha setting
            $showCaptcha = ($captchaUserLogin ?? false) || ($captchaAdminLogin ?? false);
            $captchaEnabled = $showCaptcha;
            include dirname(__DIR__) . '/partials/captcha.php';
            ?>
            <button type="submit" class="btn">Sign In</button>
        </form>

        <div class="auth-footer">
            <?php if (!empty($allowRegistrations)): ?>
                <div class="login-footer-line">Don't have an account? <a href="/register">Create one</a></div>
            <?php else: ?>
                <div class="login-footer-line">Need access? Contact support if registrations are currently closed.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .login-intro{max-width:52ch;margin-left:auto;margin-right:auto}
    .login-password-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.35rem}
    .login-password-row label{margin-bottom:0}
    .form-group-inline{margin-top:-.2rem}
    .login-remember-toggle{display:inline-flex;align-items:center;gap:.6rem;font-size:.95rem;color:var(--text-muted);cursor:pointer}
    .login-remember-toggle input{width:1rem;height:1rem}
    .login-forgot-link{font-size:.875rem;white-space:nowrap}
    .login-soft-note{margin:0 0 1.25rem;padding:.9rem 1rem;border:1px solid rgba(37,99,235,.12);border-radius:14px;background:rgba(37,99,235,.05);color:var(--text-muted);font-size:.92rem;line-height:1.45;white-space:nowrap;text-align:center}
    .login-footer-line{color:var(--text-muted)}
    @media (max-width: 640px){
        .login-password-row{flex-direction:column;align-items:flex-start}
        .login-soft-note{white-space:normal}
    }
</style>

<?php include __DIR__ . '/footer.php'; ?>
