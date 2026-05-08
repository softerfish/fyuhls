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
    ? htmlspecialchars($paidPackage['name']) . ' is available for users who need more speed, storage, or fewer restrictions.'
    : 'Everything here is currently focused on the site\'s active access tiers without a paid upgrade step.';

$heroContent = $siteContent['hero'] ?? [];
$panelContent = $siteContent['panel'] ?? [];
$featuresSectionContent = $siteContent['features_section'] ?? [];
$featureCardsContent = $siteContent['feature_cards'] ?? [];
$quickFaqSectionContent = $siteContent['quick_faq_section'] ?? [];
$quickFaqCardsContent = $siteContent['quick_faq_cards'] ?? [];
$pricingSectionContent = $siteContent['pricing_section'] ?? [];
?>

<div class="hero">
    <div class="hero-copy">
        <div class="hero-kicker"><?= SiteContentService::renderInlineMarkdown((string)($heroContent['kicker'] ?? 'Hosted by {site_name}'), $siteContentTokens) ?></div>
        <h1><?= SiteContentService::renderInlineMarkdown((string)($heroContent['title'] ?? 'Share files with rules that match your site.'), $siteContentTokens) ?></h1>
        <div class="hero-rich-copy"><?= SiteContentService::renderMarkdown((string)($heroContent['intro'] ?? ''), $siteContentTokens) ?></div>
        <div class="cta-group">
            <?php if ($allowRegistrations): ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/register', $pageLocale)) ?>" class="btn btn-lg"><?= htmlspecialchars((string)($heroContent['primary_cta_label'] ?? 'Create Account')) ?></a>
            <?php else: ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/login', $pageLocale)) ?>" class="btn btn-lg"><?= htmlspecialchars((string)($heroContent['primary_cta_label'] ?? 'Login')) ?></a>
            <?php endif; ?>
            <?php if ($guestUploadsAllowed): ?>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/upload', $pageLocale)) ?>" class="btn btn-lg btn-outline"><?= htmlspecialchars((string)($heroContent['guest_cta_label'] ?? 'Guest Upload')) ?></a>
            <?php endif; ?>
            <a href="#features" class="btn btn-lg btn-outline"><?= htmlspecialchars((string)($heroContent['features_cta_label'] ?? 'See Features')) ?></a>
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

<div class="section" id="features">
    <div class="section-title">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($featuresSectionContent['title'] ?? 'Built around the rules your admin sets'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($featuresSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="features">
        <?php foreach ($featureCardsContent as $card): ?>
            <div class="feature-card">
                <span class="feature-icon"><?= htmlspecialchars((string)($card['icon'] ?? $card['label'] ?? 'Feature')) ?></span>
                <h3><?= SiteContentService::renderInlineMarkdown((string)($card['title'] ?? ''), $siteContentTokens) ?></h3>
                <div class="feature-rich-copy"><?= SiteContentService::renderMarkdown((string)($card['body'] ?? ''), $siteContentTokens) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="section" id="homepage-faq">
    <div class="section-title">
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

<div class="section section-soft" id="pricing">
    <div class="section-title">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($pricingSectionContent['title'] ?? 'Account Levels'), $siteContentTokens) ?></h2>
        <div class="section-rich-copy"><?= SiteContentService::renderMarkdown((string)($pricingSectionContent['intro'] ?? ''), $siteContentTokens) ?></div>
    </div>
    <div class="pricing">
        <?php foreach ($packages as $pkg): ?>
            <?php
            $levelType = $pkg['level_type'] ?? 'free';
            $buttonLabel = $levelType === 'paid' ? 'Upgrade Account' : ($allowRegistrations ? 'Get Started' : 'Login');
            $buttonHref = $levelType === 'paid' ? '/checkout/' . $pkg['id'] : ($allowRegistrations ? '/register' : '/login');
            ?>
            <div class="price-card <?= $levelType === 'paid' ? 'featured' : '' ?>">
                <div class="plan-label"><?= htmlspecialchars($pkg['name']) ?></div>
                <div class="price-tag">
                    <?= strtoupper($levelType) ?>
                    <?php if ($levelType === 'paid'): ?>
                        &middot; $<?= number_format((float)($pkg['price'] ?? 0), 2) ?>
                    <?php endif; ?>
                </div>
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
    .section-soft {
        background: #f8fafc;
        border-top: 1px solid var(--border-color);
        border-bottom: 1px solid var(--border-color);
        max-width: none;
    }
    .section-soft .section-title,
    .section-soft .pricing {
        max-width: 1200px;
        margin-left: auto;
        margin-right: auto;
    }
    .section-title {
        text-align: center;
        margin-bottom: 3rem;
    }
    .section-title h2 {
        font-size: 2.25rem;
        margin-bottom: 1rem;
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
    .feature-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 56px;
        padding: 0.55rem 0.9rem;
        border-radius: 999px;
        background: var(--primary-light);
        color: var(--primary-color);
        font-size: 0.875rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }
    .pricing {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
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
        padding: 2rem;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        text-align: left;
    }
    .price-card.featured {
        border-color: var(--primary-color);
        box-shadow: 0 20px 25px -5px rgba(37, 99, 235, 0.1);
    }
    .plan-label {
        font-weight: 700;
        font-size: 1.25rem;
        margin-bottom: 0.75rem;
    }
    .price-tag {
        font-size: 0.85rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 1.5rem;
        font-weight: 700;
    }
    .price-features {
        list-style: none;
        padding: 0;
        margin: 0 0 1.5rem;
        display: grid;
        gap: 0.6rem;
    }
    .price-features li::before {
        content: "+";
        color: var(--success-color);
        font-weight: 700;
        margin-right: 0.5rem;
    }
    @media (max-width: 900px) {
        .hero,
        .faq-grid,
        .features,
        .pricing {
            grid-template-columns: 1fr;
        }
        .hero-panel-grid {
            grid-template-columns: 1fr;
        }
        .hero h1 {
            font-size: 2.6rem;
        }
    }
</style>

<?php include __DIR__ . '/footer.php'; ?>

