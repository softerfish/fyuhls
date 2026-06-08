<?php
use App\Service\SiteContentService;

$pageLocale = SiteContentService::requestLocale();
$siteContent = SiteContentService::page('homepage', $pageLocale);
$siteContentTokens = SiteContentService::tokenContext();
$extraHead = ($extraHead ?? '') . SiteContentService::previewHeadHtml('homepage', $pageLocale);

$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = \App\Model\Setting::get('seo_home_title', "{$siteName} - File Hosting");
$metaDescription = \App\Model\Setting::get('seo_home_description', 'Self-hosted PHP file hosting with package controls, external storage backends, admin tools, and optional rewards.');
include __DIR__ . '/header.php';

$allowRegistrations = \App\Model\Setting::get('allow_registrations', '1') === '1';
$requireVerification = \App\Model\Setting::get('require_email_verification', '0') === '1';
$requireAccountToDownload = \App\Model\Setting::get('require_account_to_download', '0') === '1';
$guestUploadsAllowed = \App\Model\Setting::get('upload_login_required', '0') !== '1';
$rewardsEnabled = \App\Service\FeatureService::rewardsEnabled();
$affiliateEnabled = \App\Service\FeatureService::affiliateEnabled();
$supportsRemoteUpload = false;
$hasPaidPlan = false;
$packageCount = count($packages);

$formatBytes = static function (int $bytes): string {
    if ($bytes <= 0) {
        return 'Unlimited';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $pow);
    return round($value, $pow >= 2 ? 1 : 0) . ' ' . $units[$pow];
};

foreach ($packages as $pkg) {
    if (!empty($pkg['allow_remote_upload'])) {
        $supportsRemoteUpload = true;
    }
    if (($pkg['level_type'] ?? '') === 'paid') {
        $hasPaidPlan = true;
    }
}

$guestPackage = null;
$freePackage = null;
$paidPackage = null;
foreach ($packages as $pkg) {
    if (($pkg['level_type'] ?? '') === 'guest' && !$guestPackage) $guestPackage = $pkg;
    if (($pkg['level_type'] ?? '') === 'free' && !$freePackage) $freePackage = $pkg;
    if (($pkg['level_type'] ?? '') === 'paid' && !$paidPackage) $paidPackage = $pkg;
}

$creatorSummary = $rewardsEnabled
    ? ($affiliateEnabled
        ? 'Eligible users can unlock both creator rewards and affiliate referrals from the same account system.'
        : 'Eligible users can unlock creator rewards alongside the site\'s storage and sharing tools.')
    : 'Share files with package-based rules, access controls, and a cleaner workflow for everyday hosting.';

$tierSummary = $paidPackage
    ? (string)($paidPackage['name'] ?? 'The current paid plan') . ' is available for users who need more speed, larger uploads, or fewer restrictions.'
    : 'Everything here is currently focused on the site\'s active access tiers without a paid upgrade step.';

$heroContent = $siteContent['hero'] ?? [];
$panelContent = $siteContent['panel'] ?? [];
$trustSectionContent = $siteContent['trust_section'] ?? [];
$trustCardsContent = $siteContent['trust_cards'] ?? [];
$previewSectionContent = $siteContent['preview_section'] ?? [];
$accountSectionContent = $siteContent['account_section'] ?? [];
$accountCardsContent = $siteContent['account_cards'] ?? [];
$featuresSectionContent = $siteContent['features_section'] ?? [];
$featureCardsContent = $siteContent['feature_cards'] ?? [];
$quickFaqSectionContent = $siteContent['quick_faq_section'] ?? [];
$quickFaqCardsContent = $siteContent['quick_faq_cards'] ?? [];
$pricingSectionContent = $siteContent['pricing_section'] ?? [];
$conversionCtaContent = $siteContent['conversion_cta'] ?? [];

$entryPackage = $freePackage ?? $guestPackage ?? ($packages[0] ?? null);
$recommendedPaidPackageId = $paidPackage ? (int)($paidPackage['id'] ?? 0) : 0;
$recommendedFreePackageId = $freePackage ? (int)($freePackage['id'] ?? 0) : 0;

$planBadge = static function (array $pkg) use ($allowRegistrations, $recommendedFreePackageId, $recommendedPaidPackageId): string {
    $levelType = (string)($pkg['level_type'] ?? 'free');
    $id = (int)($pkg['id'] ?? 0);
    if ($levelType === 'guest') {
        return 'Guest Access';
    }
    if ($levelType === 'free') {
        return $allowRegistrations && $id === $recommendedFreePackageId ? 'Best first step' : 'Free Tier';
    }
    if ($levelType === 'paid') {
        return $id === $recommendedPaidPackageId ? 'Popular upgrade' : 'Paid Tier';
    }
    return strtoupper($levelType);
};

$planAudience = static function (array $pkg) use ($allowRegistrations): string {
    $levelType = (string)($pkg['level_type'] ?? 'free');
    $remote = !empty($pkg['allow_remote_upload']);
    $adsDisabled = empty($pkg['show_ads']);
    $speedUnlimited = (int)($pkg['download_speed'] ?? 0) <= 0;

    if ($levelType === 'guest') {
        return 'Best for quick access, one-off uploads, and lightweight public use.';
    }
    if ($levelType === 'free') {
        return $allowRegistrations
            ? 'Best for new members who want a saved dashboard and a real account right away.'
            : 'Ready for member access once registrations reopen on this site.';
    }

    $highlights = [];
    if ($remote) {
        $highlights[] = 'remote imports';
    }
    if ($adsDisabled) {
        $highlights[] = 'cleaner download pages';
    }
    if ($speedUnlimited) {
        $highlights[] = 'faster delivery';
    }

    if ($highlights === []) {
        return 'Best for users who need more room, bigger transfers, or fewer limits.';
    }

    return 'Best for users who need ' . implode(', ', $highlights) . ', and more room.';
};

$workspaceHighlights = [
    [
        'label' => 'Uploads and folders',
        'body' => $guestUploadsAllowed
            ? 'Guests can start on the public side, then move into a saved file dashboard with folders and reusable links.'
            : 'Uploads currently start from signed-in accounts, with folders and link management built into the account dashboard.',
    ],
    [
        'label' => 'Downloads and sharing',
        'body' => $requireAccountToDownload
            ? 'Downloads currently flow through member accounts under the site\'s access rules.'
            : 'Visitors can access download pages without a forced account wall when the current rules allow it.',
    ],
    [
        'label' => 'Remote imports',
        'body' => $supportsRemoteUpload
            ? 'Supported plans can pull a file straight from a remote URL into the account dashboard.'
            : 'Browser uploads are active now, with remote URL imports currently turned off in the plan mix.',
    ],
    [
        'label' => 'Extra tools',
        'body' => $rewardsEnabled
            ? 'Creator rewards, payout tracking, and account tools sit alongside the file dashboard when enabled.'
            : 'Account tools stay focused on file management, saved links, and the wider sharing workflow.',
    ],
];

$liveSetupCards = [
    [
        'label' => 'Entry plan',
        'value' => $entryPackage ? (string)($entryPackage['name'] ?? 'Account access') : 'Account access',
        'copy' => $entryPackage
            ? $formatBytes((int)($entryPackage['max_upload_size'] ?? 0)) . ' uploads / ' . $formatBytes((int)($entryPackage['max_storage_bytes'] ?? 0)) . ' storage'
            : 'Package-based limits are active on this site.',
    ],
    [
        'label' => 'Upgrade path',
        'value' => $paidPackage ? (string)($paidPackage['name'] ?? 'Paid plan') : 'No paid plan live',
        'copy' => $paidPackage
            ? '$' . number_format((float)($paidPackage['price'] ?? 0), 2) . ' for the current paid tier'
            : 'Visitors are currently choosing between the non-paid account levels.',
    ],
    [
        'label' => 'Download access',
        'value' => $requireAccountToDownload ? 'Member downloads' : 'Guest downloads',
        'copy' => $requireVerification
            ? 'New accounts confirm email before the full member flow begins.'
            : 'Email verification is optional in the current setup.',
    ],
    [
        'label' => 'Creator tools',
        'value' => $rewardsEnabled ? 'Rewards live' : 'Rewards off',
        'copy' => $supportsRemoteUpload
            ? 'Remote URL imports are available on supported plans.'
            : 'Browser uploads are the current default path.',
    ],
];
?>

<div class="hero">
    <div class="hero-copy">
        <div class="hero-kicker"><?= SiteContentService::renderInlineMarkdown((string)($heroContent['kicker'] ?? 'Hosted by {site_name}'), $siteContentTokens) ?></div>
        <h1><?= SiteContentService::renderInlineMarkdown((string)($heroContent['title'] ?? 'Upload large files, share download links, and keep everything in one place.'), $siteContentTokens) ?></h1>
        <div class="hero-rich-copy"><?= SiteContentService::renderMarkdown((string)($heroContent['intro'] ?? ''), $siteContentTokens) ?></div>
        <div class="cta-group">
            <?php if ($allowRegistrations): ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/register', $pageLocale)) ?>" class="btn btn-lg"><?= htmlspecialchars((string)($heroContent['primary_cta_label'] ?? 'Create Account')) ?></a>
            <?php else: ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/login', $pageLocale)) ?>" class="btn btn-lg"><?= htmlspecialchars((string)($heroContent['primary_cta_label'] ?? 'Login')) ?></a>
            <?php endif; ?>
            <a href="#pricing" class="btn btn-lg btn-outline"><?= htmlspecialchars((string)($heroContent['features_cta_label'] ?? 'View Plans')) ?></a>
            <?php if ($guestUploadsAllowed): ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/upload', $pageLocale)) ?>" class="btn btn-lg btn-outline"><?= htmlspecialchars((string)($heroContent['guest_cta_label'] ?? 'Guest Upload')) ?></a>
            <?php endif; ?>
        </div>
        <div class="hero-meta">
            <span><?= htmlspecialchars($allowRegistrations ? (string)($heroContent['meta_registration_open'] ?? 'Registrations are open') : (string)($heroContent['meta_registration_closed'] ?? 'Registrations are currently closed')) ?></span>
            <span><?= htmlspecialchars($requireAccountToDownload ? (string)($heroContent['meta_downloads_require_account'] ?? 'Downloads require an account') : (string)($heroContent['meta_downloads_guest_allowed'] ?? 'Guest downloads are allowed')) ?></span>
            <span><?= htmlspecialchars($requireVerification ? (string)($heroContent['meta_verification_enabled'] ?? 'Email verification is enabled') : (string)($heroContent['meta_verification_optional'] ?? 'Email verification is optional')) ?></span>
            <span><?= htmlspecialchars($guestUploadsAllowed ? (string)($heroContent['meta_guest_uploads_enabled'] ?? 'Guest uploads are enabled') : (string)($heroContent['meta_guest_uploads_disabled'] ?? 'Uploads require login')) ?></span>
        </div>
    </div>
    <div class="hero-panel">
        <h3><?= SiteContentService::renderInlineMarkdown((string)($panelContent['title'] ?? 'Why Use {site_name}'), $siteContentTokens) ?></h3>
        <div class="hero-panel-grid">
            <div class="hero-stat">
                <span class="hero-stat-label"><?= htmlspecialchars((string)($panelContent['account_levels_label'] ?? 'Account Levels')) ?></span>
                <strong><?= (int) $packageCount ?> plan<?= $packageCount === 1 ? '' : 's' ?></strong>
                <small><?= strip_tags(SiteContentService::renderMarkdown($hasPaidPlan ? (string)($panelContent['account_levels_summary_paid'] ?? 'Starts simple and scales into premium access') : (string)($panelContent['account_levels_summary_free'] ?? 'Built around the current access levels on this site'), $siteContentTokens)) ?></small>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-label"><?= htmlspecialchars((string)($panelContent['access_style_label'] ?? 'Access Style')) ?></span>
                <strong><?= $requireAccountToDownload ? 'Member downloads' : 'Guest downloads' ?></strong>
                <small><?= strip_tags(SiteContentService::renderMarkdown($requireAccountToDownload ? (string)($panelContent['access_style_summary_member'] ?? 'Email confirmation helps keep accounts cleaner') : (string)($panelContent['access_style_summary_guest'] ?? 'Account signup stays lightweight for new users'), $siteContentTokens)) ?></small>
            </div>
        </div>
        <ul>
            <li><?= SiteContentService::renderInlineMarkdown((string)($panelContent['bullet_free_package'] ?? ''), $siteContentTokens) ?></li>
            <li><?= SiteContentService::renderInlineMarkdown($supportsRemoteUpload ? (string)($panelContent['bullet_remote_upload_enabled'] ?? '') : (string)($panelContent['bullet_remote_upload_disabled'] ?? ''), $siteContentTokens) ?></li>
            <li><?= SiteContentService::renderInlineMarkdown($guestUploadsAllowed ? (string)($panelContent['bullet_guest_upload_enabled'] ?? '') : (string)($panelContent['bullet_guest_upload_disabled'] ?? ''), $siteContentTokens) ?></li>
            <li><?= SiteContentService::renderInlineMarkdown((string)($panelContent['bullet_creator_summary'] ?? $creatorSummary), $siteContentTokens) ?></li>
            <li><?= SiteContentService::renderInlineMarkdown((string)($panelContent['bullet_tier_summary'] ?? $tierSummary), $siteContentTokens) ?></li>
        </ul>
    </div>
</div>

<div class="section section-compact" id="trust-strip">
    <div class="section-title section-title-left">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($trustSectionContent['title'] ?? 'What visitors can expect right now'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($trustSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="trust-grid">
        <?php foreach ($trustCardsContent as $card): ?>
            <div class="trust-card">
                <span class="card-eyebrow"><?= SiteContentService::renderInlineMarkdown((string)($card['label'] ?? ''), $siteContentTokens) ?></span>
                <h3><?= SiteContentService::renderInlineMarkdown((string)($card['title'] ?? ''), $siteContentTokens) ?></h3>
                <div class="trust-card-copy"><?= SiteContentService::renderMarkdown((string)($card['body'] ?? ''), $siteContentTokens) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section section-soft section-preview" id="product-preview">
    <div class="section-title section-title-left">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($previewSectionContent['title'] ?? 'See how the product feels before you sign up'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($previewSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="preview-grid">
        <div class="preview-shell">
            <div class="preview-window-bar">
                <span class="preview-dot"></span>
                <span class="preview-dot"></span>
                <span class="preview-dot"></span>
                <strong>Member file dashboard</strong>
            </div>
            <div class="preview-window-body">
                <div class="preview-topline">
                    <div>
                        <span class="preview-label">Dashboard</span>
                        <h3>Uploads, folders, and account tools</h3>
                    </div>
                    <span class="preview-chip"><?= (int)$packageCount ?> live plan<?= $packageCount === 1 ? '' : 's' ?></span>
                </div>
                <div class="preview-feature-grid">
                    <?php foreach ($workspaceHighlights as $item): ?>
                        <div class="preview-feature-card">
                            <span class="preview-card-label"><?= htmlspecialchars((string)$item['label']) ?></span>
                            <p><?= htmlspecialchars((string)$item['body']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="preview-live-shell">
            <div class="preview-live-header">
                <span class="preview-label">Live setup snapshot</span>
                <h3>Current rules and upgrade path</h3>
            </div>
            <div class="preview-live-list">
                <?php foreach ($liveSetupCards as $item): ?>
                    <div class="preview-live-card">
                        <span class="preview-card-label"><?= htmlspecialchars((string)$item['label']) ?></span>
                        <strong><?= htmlspecialchars((string)$item['value']) ?></strong>
                        <p><?= htmlspecialchars((string)$item['copy']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="section" id="why-account">
    <div class="section-title section-title-left">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($accountSectionContent['title'] ?? 'Why create an account'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($accountSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="benefit-grid">
        <?php foreach ($accountCardsContent as $card): ?>
            <div class="benefit-card">
                <span class="card-eyebrow"><?= SiteContentService::renderInlineMarkdown((string)($card['label'] ?? ''), $siteContentTokens) ?></span>
                <h3><?= SiteContentService::renderInlineMarkdown((string)($card['title'] ?? ''), $siteContentTokens) ?></h3>
                <div class="benefit-card-copy"><?= SiteContentService::renderMarkdown((string)($card['body'] ?? ''), $siteContentTokens) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section" id="features">
    <div class="section-title section-title-left">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($featuresSectionContent['title'] ?? 'More than a basic upload form'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($featuresSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="features">
        <?php foreach ($featureCardsContent as $card): ?>
            <div class="feature-card">
                <span class="card-eyebrow"><?= SiteContentService::renderInlineMarkdown((string)($card['label'] ?? $card['icon'] ?? 'Feature'), $siteContentTokens) ?></span>
                <h3><?= SiteContentService::renderInlineMarkdown((string)($card['title'] ?? ''), $siteContentTokens) ?></h3>
                <div class="feature-rich-copy"><?= SiteContentService::renderMarkdown((string)($card['body'] ?? ''), $siteContentTokens) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section section-soft" id="pricing">
    <div class="section-title section-title-left">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($pricingSectionContent['title'] ?? 'Account Levels'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($pricingSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="pricing">
        <?php foreach ($packages as $pkg): ?>
            <?php
            $levelType = $pkg['level_type'] ?? 'free';
            $isRecommended = ($levelType === 'free' && (int)($pkg['id'] ?? 0) === $recommendedFreePackageId && $allowRegistrations)
                || ($levelType === 'paid' && (int)($pkg['id'] ?? 0) === $recommendedPaidPackageId && !$allowRegistrations);
            $buttonLabel = $levelType === 'guest'
                ? ($guestUploadsAllowed ? 'Start Guest Upload' : ($allowRegistrations ? 'Create Account' : 'Login'))
                : ($levelType === 'paid' ? 'Upgrade Account' : ($allowRegistrations ? 'Get Started' : 'Login'));
            $buttonHref = $levelType === 'guest'
                ? ($guestUploadsAllowed ? '/upload' : ($allowRegistrations ? '/register' : '/login'))
                : ($levelType === 'paid' ? '/checkout/' . $pkg['id'] : ($allowRegistrations ? '/register' : '/login'));
            ?>
            <div class="price-card <?= $isRecommended ? 'featured' : '' ?> <?= $levelType === 'paid' ? 'price-card-paid' : '' ?>">
                <span class="price-card-badge"><?= htmlspecialchars($planBadge($pkg)) ?></span>
                <div class="plan-label"><?= htmlspecialchars($pkg['name']) ?></div>
                <div class="price-tag">
                    <?= strtoupper($levelType) ?>
                    <?php if ($levelType === 'paid'): ?>
                        &middot; $<?= number_format((float)($pkg['price'] ?? 0), 2) ?>
                    <?php endif; ?>
                </div>
                <p class="price-copy"><?= htmlspecialchars($planAudience($pkg)) ?></p>
                <ul class="price-features">
                    <li>Storage: <?= $formatBytes((int) $pkg['max_storage_bytes']) ?></li>
                    <li>Max upload: <?= $formatBytes((int) $pkg['max_upload_size']) ?></li>
                    <li>Speed: <?= (int) $pkg['download_speed'] > 0 ? $formatBytes((int) $pkg['download_speed']) . '/s' : 'Unlimited' ?></li>
                    <li>Download wait: <?= !empty($pkg['wait_time_enabled']) ? ((int) $pkg['wait_time']) . ' seconds' : 'Instant' ?></li>
                    <li><?= !empty($pkg['allow_remote_upload']) ? 'Remote URL upload enabled' : 'Remote URL upload disabled' ?></li>
                    <li><?= !empty($pkg['show_ads']) ? 'Download pages may show ads' : 'No download-page ads' ?></li>
                </ul>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl($buttonHref, $pageLocale)) ?>" class="btn <?= $levelType === 'paid' ? 'btn-primary' : 'btn-outline' ?>">
                    <?= htmlspecialchars($buttonLabel) ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section" id="homepage-faq">
    <div class="section-title section-title-left">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($quickFaqSectionContent['title'] ?? 'Quick answers before someone signs up'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($quickFaqSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="faq-grid">
        <?php foreach ($quickFaqCardsContent as $card): ?>
            <div class="faq-card">
                <h3><?= SiteContentService::renderInlineMarkdown((string)($card['question'] ?? ''), $siteContentTokens) ?></h3>
                <div class="faq-card-copy"><?= SiteContentService::renderMarkdown((string)($card['answer'] ?? ''), $siteContentTokens) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section section-final-cta" id="final-signup-cta">
    <div class="final-cta-card">
        <div class="section-title">
            <h2><?= SiteContentService::renderInlineMarkdown((string)($conversionCtaContent['title'] ?? 'Ready to start?'), $siteContentTokens) ?></h2>
            <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($conversionCtaContent['intro'] ?? ''), $siteContentTokens) ?></div>
        </div>
        <div class="cta-group cta-group-center">
            <?php if ($allowRegistrations): ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/register', $pageLocale)) ?>" class="btn btn-lg"><?= htmlspecialchars((string)($conversionCtaContent['primary_open_label'] ?? 'Create Account')) ?></a>
            <?php else: ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/login', $pageLocale)) ?>" class="btn btn-lg"><?= htmlspecialchars((string)($conversionCtaContent['primary_closed_label'] ?? 'Login')) ?></a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/faq', $pageLocale)) ?>" class="btn btn-lg btn-outline"><?= htmlspecialchars((string)($conversionCtaContent['secondary_label'] ?? 'Read FAQ')) ?></a>
        </div>
    </div>
</div>

<style>
    .hero {
        padding: 5rem 2rem;
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.9fr);
        gap: 2rem;
        align-items: stretch;
        max-width: 1200px;
        margin: 0 auto;
    }
    .hero-copy {
        background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 3rem;
    }
    .hero-kicker {
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.12em;
        color: var(--primary-color);
        font-weight: 700;
        margin-bottom: 1rem;
    }
    .hero h1 {
        font-size: 3.4rem;
        font-weight: 800;
        color: #1e3a8a;
        margin-bottom: 1.25rem;
        line-height: 1.05;
    }
    .hero p {
        font-size: 1.125rem;
        color: var(--text-muted);
        max-width: 700px;
        margin: 0 0 2rem;
    }
    .hero-rich-copy p:first-child,
    .section-rich-copy p:first-child,
    .feature-rich-copy p:first-child,
    .faq-card-copy p:first-child {
        margin-top: 0;
    }
    .hero-rich-copy p:last-child,
    .section-rich-copy p:last-child,
    .feature-rich-copy p:last-child,
    .faq-card-copy p:last-child {
        margin-bottom: 0;
    }
    .hero-panel {
        background: #0f172a;
        color: white;
        border-radius: 24px;
        padding: 2rem;
    }
    .hero-panel h3 {
        margin-top: 0;
        margin-bottom: 1rem;
        font-size: 1.125rem;
    }
    .hero-panel ul {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 0.9rem;
    }
    .hero-panel-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
        margin-bottom: 0.9rem;
    }
    .hero-stat {
        padding: 1rem;
        background: rgba(255,255,255,0.08);
        border-radius: 14px;
    }
    .hero-stat-label {
        display: block;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: rgba(255,255,255,0.7);
        margin-bottom: 0.45rem;
        font-weight: 700;
    }
    .hero-stat strong {
        display: block;
        font-size: 1.15rem;
        line-height: 1.2;
        margin-bottom: 0.35rem;
    }
    .hero-stat small {
        display: block;
        color: rgba(255,255,255,0.78);
        line-height: 1.45;
        font-size: 0.84rem;
    }
    .hero-panel li {
        padding: 0.9rem 1rem;
        background: rgba(255,255,255,0.08);
        border-radius: 12px;
        font-size: 0.95rem;
        line-height: 1.5;
    }
    .hero-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1.5rem;
    }
    .hero-meta span {
        font-size: 0.8125rem;
        color: #334155;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 999px;
        padding: 0.45rem 0.85rem;
    }
    .cta-group {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 0.75rem;
    }
    .btn-lg {
        padding: 0.875rem 2rem;
        font-size: 1.05rem;
        width: auto;
    }
    .btn-outline {
        background: transparent;
        color: var(--primary-color);
        border: 2px solid var(--primary-color);
    }
    .btn-outline:hover {
        background: var(--primary-light);
    }
    .section {
        padding: 4rem 2rem;
        max-width: 1200px;
        margin: 0 auto;
    }
    .section-compact {
        padding-top: 0;
    }
    .section-soft {
        background: #f8fafc;
        border-top: 1px solid var(--border-color);
        border-bottom: 1px solid var(--border-color);
        max-width: none;
    }
    .section-soft .section-title,
    .section-soft .pricing {
        max-width: 1320px;
        margin-left: auto;
        margin-right: auto;
    }
    .section-title {
        text-align: center;
        margin-bottom: 3rem;
    }
    .section-title-left {
        text-align: left;
    }
    .section-title h2 {
        font-size: 2.25rem;
        margin-bottom: 1rem;
    }
    .trust-grid,
    .benefit-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1.25rem;
    }
    .trust-card,
    .benefit-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.35rem;
    }
    .card-eyebrow {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.32rem 0.72rem;
        border-radius: 999px;
        background: #eff6ff;
        color: var(--primary-color);
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-bottom: 0.9rem;
    }
    .trust-card h3,
    .benefit-card h3 {
        margin: 0 0 0.65rem;
        font-size: 1.08rem;
    }
    .trust-card-copy p,
    .benefit-card-copy p {
        margin: 0;
        color: var(--text-muted);
        font-size: 0.95rem;
        line-height: 1.65;
    }
    .section-preview {
        padding-top: 4.5rem;
        padding-bottom: 4.5rem;
    }
    .preview-grid {
        max-width: 1320px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(300px, 0.9fr);
        gap: 1.5rem;
        align-items: stretch;
    }
    .preview-shell,
    .preview-live-shell {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 20px;
        overflow: hidden;
    }
    .preview-window-bar {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.85rem 1.1rem;
        background: #e2e8f0;
        color: #0f172a;
        font-size: 0.92rem;
    }
    .preview-dot {
        width: 0.7rem;
        height: 0.7rem;
        border-radius: 999px;
        background: #94a3b8;
    }
    .preview-window-body,
    .preview-live-shell {
        padding: 1.4rem;
    }
    .preview-topline,
    .preview-live-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .preview-topline h3,
    .preview-live-header h3 {
        margin: 0.25rem 0 0;
        font-size: 1.25rem;
    }
    .preview-label,
    .preview-card-label {
        display: block;
        text-transform: uppercase;
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        font-weight: 700;
        color: #64748b;
    }
    .preview-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
        padding: 0.4rem 0.85rem;
        border-radius: 999px;
        background: #eff6ff;
        color: var(--primary-color);
        font-size: 0.82rem;
        font-weight: 700;
    }
    .preview-feature-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }
    .preview-feature-card,
    .preview-live-card {
        padding: 1rem;
        border: 1px solid #dbe4f0;
        border-radius: 14px;
        background: #f8fafc;
    }
    .preview-feature-card p,
    .preview-live-card p {
        margin: 0.45rem 0 0;
        color: var(--text-muted);
        font-size: 0.92rem;
        line-height: 1.6;
    }
    .preview-live-list {
        display: grid;
        gap: 0.9rem;
    }
    .preview-live-card strong {
        display: block;
        margin-top: 0.35rem;
        font-size: 1.05rem;
        color: #0f172a;
        line-height: 1.35;
    }
    .features {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.5rem;
    }
    .feature-card {
        padding: 2rem;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
    }
    .feature-card h3 {
        margin: 0 0 0.65rem;
        font-size: 1.15rem;
    }
    .pricing {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 390px), 390px));
        justify-content: center;
        gap: 1.5rem;
    }
    .faq-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.25rem;
    }
    .faq-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.4rem 1.35rem;
    }
    .faq-card h3 {
        margin: 0 0 0.7rem;
        font-size: 1.05rem;
    }
    .faq-card p {
        margin: 0;
        font-size: 0.96rem;
        color: var(--text-muted);
        line-height: 1.6;
        max-width: none;
    }
    .feature-rich-copy,
    .faq-card-copy,
    .section-rich-copy {
        color: inherit;
    }
    .price-card {
        padding: 1.75rem;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        text-align: left;
    }
    .price-card-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.32rem 0.72rem;
        border-radius: 999px;
        background: #eff6ff;
        color: var(--primary-color);
        font-size: 0.74rem;
        font-weight: 700;
        margin-bottom: 0.85rem;
    }
    .price-card.featured {
        border-color: var(--primary-color);
        box-shadow: 0 20px 25px -5px rgba(37, 99, 235, 0.1);
    }
    .price-card-paid:not(.featured) {
        background: linear-gradient(180deg, rgba(37,99,235,0.02), rgba(255,255,255,1));
    }
    .plan-label {
        font-weight: 700;
        font-size: 1.25rem;
        margin-bottom: 0.45rem;
    }
    .price-tag {
        font-size: 0.85rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 0.95rem;
        font-weight: 700;
    }
    .price-copy {
        margin: 0 0 1.1rem;
        color: var(--text-muted);
        line-height: 1.6;
        font-size: 0.92rem;
    }
    .price-features {
        list-style: none;
        padding: 0;
        margin: 0 0 1.5rem;
        display: grid;
        gap: 0.6rem;
        font-size: 0.95rem;
    }
    .section-final-cta {
        padding-top: 2rem;
    }
    .final-cta-card {
        background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2.5rem 2.2rem;
    }
    .cta-group-center {
        justify-content: center;
    }
    .price-features li::before {
        content: "+";
        color: var(--success-color);
        font-weight: 700;
        margin-right: 0.5rem;
    }
    @media (max-width: 1279px) {
        .pricing {
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 340px));
        }
        .trust-grid,
        .benefit-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 900px) {
        .hero,
        .preview-grid,
        .faq-grid,
        .features,
        .pricing,
        .trust-grid,
        .benefit-grid {
            grid-template-columns: 1fr;
        }
        .hero-panel-grid {
            grid-template-columns: 1fr;
        }
        .preview-feature-grid {
            grid-template-columns: 1fr;
        }
        .preview-topline,
        .preview-live-header {
            flex-direction: column;
        }
        .hero h1 {
            font-size: 2.6rem;
        }
    }
</style>

<?php include __DIR__ . '/footer.php'; ?>
