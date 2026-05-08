<?php
use App\Service\SiteContentService;

$pageLocale = SiteContentService::requestLocale();
$siteContent = SiteContentService::page('contact', $pageLocale);
$siteContentTokens = SiteContentService::tokenContext();
$contactPage = $siteContent['page'] ?? [];
$contactFields = $siteContent['fields'] ?? [];
$extraHead = ($extraHead ?? '') . SiteContentService::previewHeadHtml('contact', $pageLocale);

$title = strip_tags(SiteContentService::renderInlineMarkdown((string)($contactPage['title'] ?? 'Contact Support'), $siteContentTokens));
$metaDescription = 'Contact support for questions, account issues, abuse follow-up, or general help with this file hosting site.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
?>
    <div class="public-form-shell">
    <div class="public-form-card public-form-card--wide public-form-card--plain contact-support-card auth-container">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($contactPage['title'] ?? 'Contact Us'), $siteContentTokens) ?></h2>
        <div class="public-form-intro"><?= SiteContentService::renderMarkdown((string)($contactPage['intro'] ?? ''), $siteContentTokens) ?></div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group">
                <label for="name"><?= htmlspecialchars((string)($contactFields['name_label'] ?? 'Your Name')) ?></label>
                <input type="text" name="name" id="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="email"><?= htmlspecialchars((string)($contactFields['email_label'] ?? 'Email Address')) ?></label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="subject"><?= htmlspecialchars((string)($contactFields['subject_label'] ?? 'Subject')) ?></label>
                <input type="text" name="subject" id="subject" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label for="message"><?= htmlspecialchars((string)($contactFields['message_label'] ?? 'Message')) ?></label>
                <textarea name="message" id="message" rows="6" class="contact-support-message" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
            </div>
            <?php include __DIR__ . '/../partials/captcha.php'; ?>
            <button type="submit" class="btn"><?= htmlspecialchars((string)($contactPage['submit_label'] ?? 'Send Message')) ?></button>
        </form>
    </div>
    </div>

<style>
.contact-support-card{max-width:none}
.contact-support-message{width:100%;padding:.625rem;border:1px solid var(--border-color);border-radius:8px;font-size:.875rem}
.public-form-intro p:first-child{margin-top:0}
.public-form-intro p:last-child{margin-bottom:1.5rem}
</style>

<?php include __DIR__ . '/footer.php'; ?>

