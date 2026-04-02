<?php
$title = "Register - " . ($siteName ?? 'Fyuhls');
$metaDescription = 'Create an account to upload, manage, and share files using this self-hosted file platform.';
include __DIR__ . '/header.php';
?>

<div style="flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem;">
    <div class="auth-container" style="margin: 0;">
        <h2>Register</h2>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($error) || $error !== 'Registrations are currently closed.'): ?>
        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username" autofocus>
            </div>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" name="password_confirm" id="password_confirm" required autocomplete="new-password">
            </div>
            <?php
            $captchaEnabled = $captchaRegister ?? false;
            include dirname(__DIR__) . '/partials/captcha.php';
            ?>
            <button type="submit" class="btn">Create Account</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer">
            Already have an account? <a href="/login">Login</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
