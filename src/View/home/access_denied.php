<?php
$title = ($accessDeniedTitle ?? 'Access Denied') . " - " . ($siteName ?? 'Fyuhls');
$metaDescription = 'Permission required to access this area.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
?>

<div class="public-form-shell">
    <div class="public-form-card auth-container">
        <h2><?= htmlspecialchars((string)($accessDeniedTitle ?? 'Access Denied')) ?></h2>
        <p class="public-form-intro access-denied-intro">
            <?= htmlspecialchars((string)($accessDeniedMessage ?? 'You do not have permission to open this area.')) ?>
        </p>

        <div class="login-soft-note access-denied-note">
            If you expected to have access here, check your staff role and capability assignments or sign in with the correct account.
        </div>

        <div class="access-denied-actions">
            <?php if (!empty($isStaffUser)): ?>
                <a href="/admin" class="btn">Return to Admin Dashboard</a>
            <?php elseif (!empty($isLoggedIn)): ?>
                <a href="/" class="btn">Return Home</a>
            <?php else: ?>
                <a href="/login" class="btn">Go to Login</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .access-denied-intro{max-width:56ch;margin-left:auto;margin-right:auto}
    .access-denied-note{margin:0 0 1.25rem;padding:.9rem 1rem;border:1px solid rgba(37,99,235,.12);border-radius:14px;background:rgba(37,99,235,.05);color:var(--text-muted);font-size:.92rem;line-height:1.5;text-align:center}
    .access-denied-actions{display:flex;justify-content:center}
</style>

<?php include __DIR__ . '/footer.php'; ?>
