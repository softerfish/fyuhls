<?php
use App\Service\SiteContentService;

$pageLocale = SiteContentService::requestLocale();
$siteContent = SiteContentService::page('dmca', $pageLocale);
$siteContentTokens = SiteContentService::tokenContext();
$dmcaPage = $siteContent['page'] ?? [];
$dmcaFields = $siteContent['fields'] ?? [];
$extraHead = ($extraHead ?? '') . SiteContentService::previewHeadHtml('dmca', $pageLocale);

$title = strip_tags(SiteContentService::renderInlineMarkdown((string)($dmcaPage['title'] ?? 'DMCA Takedown Notice'), $siteContentTokens));
$metaDescription = 'Submit a DMCA takedown notice for copyrighted material hosted on this site.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
?>

    <style>
        .dmca-intro {
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 2rem;
        }
        .dmca-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        .dmca-textarea {
            width: 100%;
            padding: 0.625rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.875rem;
        }
        .dmca-help {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }
        .dmca-confirm-box {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        .dmca-confirm-title { margin-top: 0; }
        .dmca-confirm-label {
            display: block;
            cursor: pointer;
            font-weight: 400;
            line-height: 1.5;
        }
        .auth-form .dmca-confirm-checkbox {
            width: auto;
            margin-top: 0.25rem;
            flex-shrink: 0;
            cursor: pointer;
        }
    </style>

    <div class="public-form-shell">
    <div class="auth-container public-form-card public-form-card--wide public-form-card--plain">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($dmcaPage['title'] ?? 'DMCA Takedown Notice'), $siteContentTokens) ?></h2>
        <div class="dmca-intro"><?= SiteContentService::renderMarkdown((string)($dmcaPage['intro'] ?? ''), $siteContentTokens) ?></div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="dmca-grid">
                <div class="form-group">
                    <label for="name"><?= htmlspecialchars((string)($dmcaFields['name_label'] ?? 'Full Name')) ?></label>
                    <input type="text" name="name" id="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="email"><?= htmlspecialchars((string)($dmcaFields['email_label'] ?? 'Email Address')) ?></label>
                    <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label for="url"><?= htmlspecialchars((string)($dmcaFields['url_label'] ?? 'Infringing URL(s)')) ?></label>
                <textarea name="url" id="url" rows="7" class="dmca-textarea" placeholder="https://yourdomain.com/file/123&#10;https://yourdomain.com/file/456&#10;https://yourdomain.com/file/789" required><?= htmlspecialchars($_POST['url'] ?? '') ?></textarea>
                <div class="dmca-help"><?= SiteContentService::renderMarkdown((string)($dmcaFields['url_help'] ?? ''), $siteContentTokens) ?></div>
            </div>
            <div class="form-group">
                <label for="description"><?= htmlspecialchars((string)($dmcaFields['description_label'] ?? 'Detailed Description')) ?></label>
                <textarea name="description" id="description" rows="6" class="dmca-textarea" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="signature"><?= htmlspecialchars((string)($dmcaFields['signature_label'] ?? 'Electronic Signature')) ?></label>
                <div class="dmca-help"><?= SiteContentService::renderMarkdown((string)($dmcaFields['signature_help'] ?? ''), $siteContentTokens) ?></div>
                <input type="text" name="signature" id="signature" value="<?= htmlspecialchars($_POST['signature'] ?? '') ?>" required>
            </div>
            <div class="dmca-confirm-box">
                <label class="dmca-confirm-label">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                        <strong class="dmca-confirm-title" style="margin: 0;"><?= htmlspecialchars((string)($dmcaFields['confirmation_title'] ?? 'Confirmation:')) ?></strong>
                        <input type="checkbox" required class="dmca-confirm-checkbox" style="margin: 0;">
                    </div>
                    <div style="display:block;"><?= SiteContentService::renderMarkdown((string)($dmcaFields['confirmation_body'] ?? ''), $siteContentTokens) ?></div>
                </label>
            </div>
            <?php include __DIR__ . '/../partials/captcha.php'; ?>
            <button type="submit" class="btn"><?= htmlspecialchars((string)($dmcaPage['submit_label'] ?? 'Submit Takedown Notice')) ?></button>
        </form>
    </div>
    </div>

<?php include __DIR__ . '/footer.php'; ?>

