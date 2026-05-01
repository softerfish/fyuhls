<?php
$title = "Reset Password - " . ($siteName ?? 'Fyuhls');
$metaDescription = 'Choose a new password and restore access to your account.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
?>

<div class="public-form-shell">
    <div class="public-form-card auth-container">
        <h2>Reset Password</h2>
        <p class="public-form-intro">
            Please choose a new password for your account.
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" name="password" id="password" required minlength="8" autofocus>
            </div>
            <div class="form-group">
                <label for="password_confirm">Confirm New Password</label>
                <input type="password" name="password_confirm" id="password_confirm" required minlength="8">
            </div>
            <button type="submit" class="btn">Reset Password</button>
        </form>

        <div class="auth-footer">
            Return to <a href="/login">Login</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
