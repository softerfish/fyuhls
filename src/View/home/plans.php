<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Plans - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .plans-shell { margin-top: 1rem; }
    .plans-toolbar-note { font-size: 0.8125rem; color: var(--text-muted); line-height: 1.55; }
    .plans-hero-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    .plans-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
        gap: 1.5rem;
        align-items: stretch;
    }
    .plans-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.75rem;
        display: flex;
        flex-direction: column;
        min-height: 100%;
    }
    .plans-card--current {
        border-color: var(--primary-color);
        box-shadow: 0 20px 25px -18px rgba(37, 99, 235, 0.35);
    }
    .plans-badge {
        display: inline-flex;
        align-self: flex-start;
        padding: 0.35rem 0.8rem;
        border-radius: 999px;
        background: #eff6ff;
        color: var(--primary-color);
        font-size: 0.75rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }
    .plans-card-title {
        margin: 0 0 0.45rem;
        font-size: 1.5rem;
    }
    .plans-price {
        margin-bottom: 1rem;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-color);
    }
    .plans-copy {
        margin: 0 0 1.25rem;
        color: var(--text-muted);
        line-height: 1.65;
        font-size: 0.92rem;
    }
    .plans-billing-list {
        margin: -0.1rem 0 1.35rem;
        display: grid;
        gap: 0.65rem;
        justify-items: center;
    }
    .plans-billing-option {
        width: 100%;
        max-width: 240px;
        text-align: center;
        padding: 0.8rem 0.95rem;
        border-radius: 14px;
        border: 1px solid #dbe7ff;
        background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.85);
    }
    .plans-billing-option-label {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0;
        text-transform: uppercase;
        color: #315aa6;
        margin-bottom: 0.25rem;
    }
    .plans-billing-option-price {
        display: block;
        font-size: 1rem;
        font-weight: 800;
        color: var(--text-color);
        line-height: 1.2;
    }
    .plans-billing-option-renewal {
        display: block;
        margin-top: 0.25rem;
        font-size: 0.74rem;
        color: var(--text-muted);
    }
    .plans-features {
        list-style: none;
        padding: 0;
        margin: 0 0 1.5rem;
        display: grid;
        gap: 0.7rem;
    }
    .plans-features li {
        color: var(--text-color);
        line-height: 1.5;
        font-size: 0.9rem;
    }
    .plans-features li::before {
        content: "+";
        color: var(--success-color);
        font-weight: 700;
        margin-right: 0.55rem;
    }
    .plans-actions {
        margin-top: auto;
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .plans-action-btn {
        width: auto;
        padding-left: 1.4rem;
        padding-right: 1.4rem;
    }
    .plans-current-note {
        color: var(--text-muted);
        font-size: 0.82rem;
        font-weight: 600;
    }
    .plans-gateway-note {
        margin-top: 1.5rem;
        padding: 1rem 1.1rem;
        border-radius: 12px;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #9a3412;
        font-size: 0.88rem;
        line-height: 1.6;
    }
</style>';

include __DIR__ . '/header.php';
include __DIR__ . '/partials/account_sidebar_styles.php';

$formatBytes = static function (int $bytes): string {
    if ($bytes <= 0) {
        return 'Unlimited';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $pow);
    return round($value, $pow >= 2 ? 1 : 0) . ' ' . $units[$pow];
};

$formatTerm = static function (int $days): string {
    return \App\Service\PaymentService::formatTermLabel($days);
};

$paymentEnabled = !empty($stripeEnabled) || !empty($paypalEnabled);
?>

<div class="fm-container plans-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>

    <div class="fm-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Upgrade Plans</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Plans</span>
                </div>
            </div>

            <div class="toolbar-right">
                <div class="toolbar-controls">
                    <span class="plans-toolbar-note">Compare account levels, review what each plan includes, and continue to checkout when you are ready.</span>
                </div>
            </div>
        </div>

        <div class="plans-hero-card">
            <div class="plans-grid">
                <?php foreach ($paidPackages as $package): ?>
                    <?php
                    $isCurrent = (int)($package['id'] ?? 0) === $currentPackageId;
                    $downloadSpeed = (int)($package['download_speed'] ?? 0);
                    $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
                    $maxUpload = (int)($package['max_upload_size'] ?? 0);
                    $billingOptions = \App\Service\PaymentService::checkoutBillingOptions($package);
                    $primaryOption = $billingOptions[0];
                    $termDays = (int)$primaryOption['term_days'];
                    $renewableOptionCount = count(array_filter($billingOptions, static fn(array $option): bool => !empty($option['renewal_enabled'])));
                    $oneTimeOptionCount = count($billingOptions) - $renewableOptionCount;
                    $renewalSummary = $renewableOptionCount > 0 && $oneTimeOptionCount > 0
                        ? 'Renewal depends on the billing option you pick'
                        : ($renewableOptionCount > 0 ? 'Auto-renew available at checkout' : 'One-time premium term');
                    ?>
                    <article class="plans-card <?= $isCurrent ? 'plans-card--current' : '' ?>">
                        <span class="plans-badge"><?= $isCurrent ? 'Current Plan' : 'Paid Plan' ?></span>
                        <h3 class="plans-card-title"><?= htmlspecialchars((string)($package['name'] ?? 'Premium Plan')) ?></h3>
                        <div class="plans-price">From $<?= number_format((float)($primaryOption['price'] ?? 0), 2) ?> / <?= htmlspecialchars($formatTerm($termDays)) ?></div>
                        <p class="plans-copy">
                            Upgrade for more storage, faster delivery, and additional account features for paid members.
                        </p>
                        <ul class="plans-features">
                            <li>Storage: <?= htmlspecialchars($formatBytes($maxStorage)) ?></li>
                            <li>Max upload: <?= htmlspecialchars($formatBytes($maxUpload)) ?></li>
                            <li>Download speed: <?= htmlspecialchars($downloadSpeed > 0 ? $formatBytes($downloadSpeed) . '/s' : 'Unlimited') ?></li>
                            <li><?= htmlspecialchars($renewalSummary) ?></li>
                            <li>Download wait: <?= !empty($package['wait_time_enabled']) ? (int)($package['wait_time'] ?? 0) . ' seconds' : 'Instant' ?></li>
                            <li><?= !empty($package['allow_remote_upload']) ? 'Remote URL upload enabled' : 'Remote URL upload disabled' ?></li>
                            <li><?= !empty($package['show_ads']) ? 'Download pages may show ads' : 'No download-page ads' ?></li>
                        </ul>
                        <?php if (count($billingOptions) > 1): ?>
                            <div class="plans-billing-list" aria-label="Billing options">
                                <?php foreach ($billingOptions as $option): ?>
                                    <div class="plans-billing-option">
                                        <span class="plans-billing-option-label"><?= htmlspecialchars((string)$option['option_label']) ?></span>
                                        <span class="plans-billing-option-price">$<?= number_format((float)$option['price'], 2) ?></span>
                                        <span class="plans-billing-option-renewal"><?= !empty($option['renewal_enabled']) ? 'Auto-renew available' : 'One-time purchase' ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="plans-actions">
                            <?php if ($isCurrent): ?>
                                <span class="plans-current-note">You are already on this plan.</span>
                            <?php else: ?>
                                <?php foreach ($billingOptions as $option): ?>
                                    <a class="btn btn-primary plans-action-btn" href="/checkout/<?= (int)$package['id'] ?><?= !empty($option['id']) ? ('?option=' . (int)$option['id']) : '' ?>">
                                        <?= htmlspecialchars((string)$option['option_label']) ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if (!$paymentEnabled): ?>
                <div class="plans-gateway-note">
                    Premium upgrades are temporarily unavailable because checkout is not fully set up yet. Please check back again soon.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
