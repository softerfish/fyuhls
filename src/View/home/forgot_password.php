<?php
$title = "Forgot Password - " . ($siteName ?? 'Fyuhls');
$metaDescription = 'Request a password reset link to regain access to your file hosting account.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
?>

<div class="public-form-shell">
    <div class="public-form-card auth-container">
        <h2>Forgot Password</h2>
        <p class="public-form-intro">
            Enter your email address and we'll send you a link to reset your password.
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (($_GET['error'] ?? '') === 'invalid_token'): ?>
            <div class="alert alert-error">The reset link is invalid or has expired.</div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
            </div>
            <button type="submit" class="btn">Send Reset Link</button>
        </form>

        <div class="auth-footer">
            Remembered your password? <a href="/login">Login</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
