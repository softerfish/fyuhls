<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = \App\Model\Setting::get('seo_home_title', "{$siteName} - File Hosting");
$metaDescription = \App\Model\Setting::get('seo_home_description', 'Self-hosted PHP file hosting with package controls, external storage backends, admin tools, and optional rewards.');
$seoHomeH1 = \App\Model\Setting::get('seo_home_h1', '');
$seoHomeIntro = \App\Model\Setting::get('seo_home_intro', '');
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
?>

<div class="hero">
    <div class="hero-copy">
        <div class="hero-kicker">Hosted by <?= htmlspecialchars($siteName) ?></div>
        <h1><?= htmlspecialchars($seoHomeH1 !== '' ? $seoHomeH1 : 'Share files with rules that match your site.') ?></h1>
        <p>
            <?= htmlspecialchars($seoHomeIntro !== '' ? $seoHomeIntro : 'Upload, organize, and deliver files from a responsive web interface with package-based limits, configurable download rules, and optional creator rewards.') ?>
        </p>
        <div class="cta-group">
            <?php if ($allowRegistrations): ?>
                <a href="/register" class="btn btn-lg">Create Account</a>
            <?php else: ?>
                <a href="/login" class="btn btn-lg">Login</a>
            <?php endif; ?>
            <?php if ($guestUploadsAllowed): ?>
                <a href="/upload" class="btn btn-lg btn-outline">Guest Upload</a>
            <?php endif; ?>
            <a href="#features" class="btn btn-lg btn-outline">See Features</a>
        </div>
        <div class="hero-meta">
            <span><?= $allowRegistrations ? 'Registrations are open' : 'Registrations are currently closed' ?></span>
            <span><?= $requireAccountToDownload ? 'Downloads require an account' : 'Guest downloads are allowed' ?></span>
            <span><?= $requireVerification ? 'Email verification is enabled' : 'Email verification is optional' ?></span>
            <span><?= $guestUploadsAllowed ? 'Guest uploads are enabled' : 'Uploads require login' ?></span>
        </div>
    </div>
    <div class="hero-panel">
        <h3>Why Use <?= htmlspecialchars($siteName) ?></h3>
        <div class="hero-panel-grid">
            <div class="hero-stat">
                <span class="hero-stat-label">Account Levels</span>
                <strong><?= (int) $packageCount ?> plan<?= $packageCount === 1 ? '' : 's' ?></strong>
                <small><?= $hasPaidPlan ? 'Starts simple and scales into premium access' : 'Built around the current access levels on this site' ?></small>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-label">Access Style</span>
                <strong><?= $requireAccountToDownload ? 'Member downloads' : 'Guest downloads' ?></strong>
                <small><?= $requireVerification ? 'Email confirmation helps keep accounts cleaner' : 'Account signup stays lightweight for new users' ?></small>
            </div>
        </div>
        <ul>
            <li>
                <?= $freePackage
                    ? 'Get started with uploads up to ' . htmlspecialchars($formatBytes((int) $freePackage['max_upload_size'])) . ' and ' . htmlspecialchars($formatBytes((int) $freePackage['max_storage_bytes'])) . ' of storage on the current ' . htmlspecialchars($freePackage['name']) . ' plan.'
                    : 'Upload limits and storage space are shaped around the account level this site offers.' ?>
            </li>
            <li>
                <?= $supportsRemoteUpload
                    ? 'Import files from a remote URL as well as through standard browser uploads on supported plans.'
                    : 'Upload directly from your browser with the same package-based controls used across the site.' ?>
            </li>
            <li>
                <?= $guestUploadsAllowed
                    ? 'Guests can open the dedicated upload page without creating an account first.'
                    : 'Uploads currently require a signed-in account before files can be added.' ?>
            </li>
            <li><?= $creatorSummary ?></li>
            <li><?= $tierSummary ?></li>
        </ul>
    </div>
</div>

<div class="section" id="features">
    <div class="section-title">
        <h2>Built around the rules your admin sets</h2>
        <p>These highlights reflect live configuration and package settings instead of fixed marketing promises.</p>
    </div>
    <div class="features">
        <div class="feature-card">
            <span class="feature-icon">Upload</span>
            <h3>Package-Based Limits</h3>
            <p>
                <?= $freePackage
                    ? htmlspecialchars($freePackage['name']) . ' currently allows up to ' . $formatBytes((int) $freePackage['max_upload_size']) . ' per file and ' . $formatBytes((int) $freePackage['max_storage_bytes']) . ' total storage.'
                    : 'Upload limits are controlled per package so different account levels can use different quotas.' ?>
            </p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">Access</span>
            <h3>Download Controls</h3>
            <p>
                <?= $requireAccountToDownload
                    ? 'This site currently requires a registered account before downloads can begin.'
                    : 'This site currently allows guest access to downloads, subject to package and security rules.' ?>
            </p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">Security</span>
            <h3>Onboarding Security</h3>
            <p>
                <?= $requireVerification
                    ? 'New accounts must confirm their email address before they can start using the platform.'
                    : 'Account creation is streamlined right now because email verification is optional.' ?>
            </p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">Rewards</span>
            <h3>Creator Monetization</h3>
            <p>
                <?= $rewardsEnabled
                    ? ($affiliateEnabled
                        ? 'Eligible users can access rewards and affiliate tools from their account area.'
                        : 'Eligible users can access rewards and payout tools from their account area.')
                    : 'Creator rewards are disabled on this install, so accounts only use the storage and sharing features.' ?>
            </p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">API</span>
            <h3>Public API and Tokens</h3>
            <p>Personal API tokens, managed upload flows, multipart session control, file metadata access, and application-signed download links are built into the platform.</p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">Multipart</span>
            <h3>Large-File Upload Path</h3>
            <p>Fyuhls supports resumable multipart upload sessions for object storage, so larger installs can move file bytes directly to storage instead of routing everything through PHP.</p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">Fraud</span>
            <h3>Rewards Fraud Review</h3>
            <p>When rewards are enabled, admins can hold earnings, inspect suspicious traffic, and review uploader or network risk signals from a dedicated fraud console.</p>
        </div>
        <div class="feature-card">
            <span class="feature-icon">Ops</span>
            <h3>Live Operations</h3>
            <p>Admins can monitor current downloads, review system status, export sanitized support bundles, and manage storage or delivery behavior without leaving the control surface.</p>
        </div>
    </div>
</div>

<div class="section" id="homepage-faq">
    <div class="section-title">
        <h2>Quick answers before someone signs up</h2>
        <p>A compact overview of how this install is currently set up.</p>
    </div>
    <div class="faq-grid">
        <div class="faq-card">
            <h3>Do downloads require an account?</h3>
            <p><?= $requireAccountToDownload
                ? 'Yes. This install currently requires users to register and log in before downloading files.'
                : 'No. Guest downloads are currently allowed, although package and security rules can still apply.' ?></p>
        </div>
        <div class="faq-card">
            <h3>Can users register right now?</h3>
            <p><?= $allowRegistrations
                ? 'Yes. New account registration is currently open.'
                : 'Not right now. Registration is currently closed by the administrator.' ?></p>
        </div>
        <div class="faq-card">
            <h3>Are creator rewards enabled?</h3>
            <p><?= $rewardsEnabled
                ? ($affiliateEnabled
                    ? 'Yes. Rewards and affiliate referrals are both enabled for eligible users.'
                    : 'Yes. Rewards are enabled for eligible users, while affiliate referrals are currently off.')
                : 'Not at the moment. This install is currently focused on file hosting and sharing without reward payouts.' ?></p>
        </div>
        <div class="faq-card">
            <h3>What kind of account levels are available?</h3>
            <p><?= $paidPackage
                ? 'This install offers multiple package levels, including ' . htmlspecialchars($paidPackage['name']) . ' as the current upgrade path.'
                : 'This install currently uses its configured guest and free account levels without a paid upgrade path.' ?></p>
        </div>
    </div>
</div>

<div class="section section-soft" id="pricing">
    <div class="section-title">
        <h2>Account Levels</h2>
        <p>The plan cards below are generated from the current package configuration.</p>
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
                <a href="<?= htmlspecialchars($buttonHref) ?>" class="btn <?= $levelType === 'paid' ? 'btn-primary' : 'btn-outline' ?>">
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

