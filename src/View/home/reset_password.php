<?php
$title = "Reset Password - " . ($siteName ?? 'Fyuhls');
$metaDescription = 'Choose a new password and restore access to your account.';
include __DIR__ . '/header.php';
?>

<div class="reset-password-shell">
    <div class="reset-password-card auth-container">
        <h2>Reset Password</h2>
        <p class="reset-password-copy">
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

<style>
.reset-password-shell{flex:1;display:flex;align-items:center;justify-content:center;padding:2rem}
.reset-password-card{margin:0}
.reset-password-copy{color:var(--text-muted);font-size:.875rem;margin-bottom:1.5rem;text-align:center}
</style>

<?php include __DIR__ . '/footer.php'; ?>
