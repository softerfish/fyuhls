<?php
$title = "Register - " . ($siteName ?? 'Fyuhls');
$metaDescription = 'Create an account to upload, manage, and share files using this self-hosted file platform.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
?>

<div class="public-form-shell">
    <div class="public-form-card auth-container">
        <h2>Create account</h2>
        <p class="public-form-intro register-intro">
            Set up your account to upload files, manage links, and keep everything in one place.
        </p>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($requireVerification) && (empty($error) || $error !== 'Registrations are currently closed.')): ?>
            <div class="register-soft-note">
                New accounts require email confirmation.
            </div>
        <?php endif; ?>

        <?php if (empty($error) || $error !== 'Registrations are currently closed.'): ?>
        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username" autofocus placeholder="Choose a public username">
            </div>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email" placeholder="name@example.com">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="register-field-note">Use at least 10 characters.</div>
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
        <?php else: ?>
        <div class="register-closed-note">
            <strong>Need access right away?</strong>
            <span>Sign in if you already have an account, or contact support if you need help getting access.</span>
        </div>
        <?php endif; ?>

        <div class="auth-footer">
            <div class="register-footer-line">Already have an account? <a href="/login">Sign in</a></div>
        </div>
    </div>
</div>

<style>
    .register-intro{max-width:44ch;margin-left:auto;margin-right:auto}
    .register-soft-note{margin:0 0 1.25rem;padding:.85rem 1rem;border:1px solid rgba(37,99,235,.12);border-radius:14px;background:rgba(37,99,235,.05);color:var(--text-muted);font-size:.88rem;line-height:1.4;text-align:center}
    .register-field-note{margin:-.15rem 0 .45rem;color:var(--text-muted);font-size:.83rem}
    .register-closed-note{margin-top:.25rem;padding:1rem 1.1rem;border:1px solid var(--border-color);border-radius:14px;background:#fafcff;color:var(--text-muted);line-height:1.6}
    .register-closed-note strong{display:block;color:var(--text-main);margin-bottom:.25rem}
    .register-footer-line{color:var(--text-muted)}
</style>

<?php include __DIR__ . '/footer.php'; ?>
