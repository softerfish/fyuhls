<?php
$title = 'Contact Support';
$metaDescription = 'Contact support for questions, account issues, abuse follow-up, or general help with this file hosting site.';
include __DIR__ . '/header.php';
?>

    <div class="auth-container" style="max-width: 600px; border: none; box-shadow: none; background: transparent;">
        <h2>Contact Us</h2>
        <p style="text-align: center; color: var(--text-muted); margin-bottom: 2rem;">Have a question or feedback? We'd love to hear from you.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="name">Your Name</label>
                <input type="text" name="name" id="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="subject">Subject</label>
                <input type="text" name="subject" id="subject" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="message">Message</label>
                <textarea name="message" id="message" rows="6" style="width: 100%; padding: 0.625rem; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.875rem;" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
            </div>
            <?php include __DIR__ . '/../partials/captcha.php'; ?>
            <button type="submit" class="btn">Send Message</button>
        </form>
    </div>

<?php include __DIR__ . '/footer.php'; ?>

