<?php
$title = "Login - " . ($siteName ?? 'Fyuhls');
$metaDescription = 'Login to access your file manager, packages, rewards, and account settings.';
include __DIR__ . '/header.php';
?>

<div class="login-shell">
    <div class="login-card auth-container">
        <h2>Login</h2>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'invalid_token'): ?>
            <div class="alert alert-error">The verification link is invalid or has expired.</div>
        <?php endif; ?>
        <?php if (($_GET['registered'] ?? '') === 'pending'): ?>
            <div class="alert alert-info">Registration successful! Please check your email to verify your account.</div>
        <?php endif; ?>
        <?php if (($_GET['verified'] ?? '') === '1'): ?>
            <div class="alert alert-success">Email verified successfully! You can now login.</div>
        <?php endif; ?>
        <?php if (($_GET['reset'] ?? '') === '1'): ?>
            <div class="alert alert-success">Password reset successfully! You can now login.</div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="username">Username or Email</label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username" autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required autocomplete="current-password">
            </div>
            <?php
// show captcha if the shared login form is protected by either login captcha setting
            $showCaptcha = ($captchaUserLogin ?? false) || ($captchaAdminLogin ?? false);
            $captchaEnabled = $showCaptcha;
            include dirname(__DIR__) . '/partials/captcha.php';
            ?>
            <button type="submit" class="btn">Login</button>
        </form>

        <div class="auth-footer">
            <a href="/forgot-password" class="login-forgot-link">Forgot Password?</a><br><br>
            Don't have an account? <a href="/register">Register</a>
        </div>
    </div>
</div>

<style>
.login-shell{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem}
.login-card{margin:0}
.login-forgot-link{font-size:.875rem}
</style>

<?php include __DIR__ . '/footer.php'; ?>
