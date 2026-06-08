<?php
use App\Service\SiteContentService;

$pageLocale = SiteContentService::requestLocale();
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = "Creator Rewards - {$siteName}";
$ppsCommission = (string)($ppsCommission ?? \App\Model\Setting::get('pps_commission_percent', '50', 'rewards'));
$mixedPpdPercent = (string)($mixedPpdPercent ?? \App\Model\Setting::get('mixed_ppd_percent', '30', 'rewards'));
$mixedPpsPercent = (string)($mixedPpsPercent ?? \App\Model\Setting::get('mixed_pps_percent', '30', 'rewards'));
$referralCommission = (string)($referralCommission ?? \App\Model\Setting::get('referral_commission_percent', '50', 'rewards'));
$affiliateEnabled = (bool)($affiliateEnabled ?? \App\Service\FeatureService::affiliateEnabled());
$siteContent = SiteContentService::page('affiliate', $pageLocale);
$siteContentTokens = SiteContentService::tokenContext([
    'mixed_ppd_percent' => $mixedPpdPercent,
    'mixed_pps_percent' => $mixedPpsPercent,
    'pps_commission_percent' => $ppsCommission,
    'referral_commission_percent' => $referralCommission,
]);
$extraHead = ($extraHead ?? '') . SiteContentService::previewHeadHtml('affiliate', $pageLocale);
$affiliateHeader = $siteContent['header'] ?? [];
$affiliateModelsSection = $siteContent['models_section'] ?? [];
$affiliateMixed = $siteContent['mixed_program'] ?? [];
$affiliatePpd = $siteContent['ppd_program'] ?? [];
$affiliatePps = $siteContent['pps_program'] ?? [];
$affiliateTierSection = $siteContent['tier_section'] ?? [];
$affiliateGuidanceCards = $siteContent['guidance_cards'] ?? [];
$affiliateGuestCta = $siteContent['guest_cta'] ?? [];
$affiliateMemberCta = $siteContent['member_cta'] ?? [];
$extraHead .= '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/account_sidebar_styles.php';
$showAccountShell = \App\Core\Auth::check();
$modelLabelMap = [
    'ppd' => 'Pay Per Download',
    'pps' => 'Pay Per Sale',
    'mixed' => 'Hybrid',
];
$currentModelLabel = $user && !empty($user['monetization_model']) ? ($modelLabelMap[(string)$user['monetization_model']] ?? ucfirst((string)$user['monetization_model'])) : null;
$paymentMethodRaw = strtolower(trim((string)($user['payment_method'] ?? '')));
$paymentMethodLabel = $paymentMethodRaw !== '' ? \App\Service\PayoutProcessorService::label($paymentMethodRaw) : 'Not set';
?>

<style>
    .affiliate-shell { margin-top: 1rem; }
    .affiliate-main { min-width: 0; }
    .affiliate-toolbar-note { font-size: 0.8125rem; color: var(--text-muted); line-height: 1.55; }
    .affiliate-hero-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .affiliate-hero-grid { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.9fr); gap: 1.5rem; align-items: stretch; }
    .affiliate-hero-copy { display: flex; flex-direction: column; justify-content: center; min-width: 0; }
    .affiliate-hero-card h1 { font-size: 2.25rem; font-weight: 800; margin: 0 0 1rem; color: var(--text-color); }
    .affiliate-hero-card p { font-size: 1rem; color: var(--text-muted); max-width: 760px; margin: 0; line-height: 1.7; }
    .affiliate-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem; }
    .affiliate-summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
    .affiliate-summary-card { background: #f8fbff; border: 1px solid #dbe6f3; border-radius: 16px; padding: 1rem; min-width: 0; }
    .affiliate-summary-label { font-size: 0.75rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; margin-bottom: 0.55rem; }
    .affiliate-summary-value { font-size: 1.35rem; font-weight: 800; line-height: 1.15; color: var(--text-color); }
    .affiliate-summary-copy { margin-top: 0.35rem; font-size: 0.9rem; color: var(--text-muted); line-height: 1.45; }
    .affiliate-section { padding-bottom: 4rem; max-width: 1200px; margin: 0 auto; }
    .affiliate-section--dashboard { max-width: none; margin: 0; width: 100%; }
    .affiliate-section-block { margin-top: 2.25rem; }
    .affiliate-section-heading { margin-bottom: 1rem; }
    .affiliate-section-heading h2 { font-size: 1.75rem; margin: 0; color: var(--text-color); }
    .affiliate-section-copy { margin-top: 0.5rem; color: var(--text-muted); line-height: 1.65; max-width: 860px; }
    .affiliate-program-card--active { border: 2px solid var(--primary-color); background: #f0f9ff; }
    .affiliate-program-badge--default { background: #e0e7ff; color: #3730a3; }
    .program-card .badge.affiliate-program-badge--active {
        background: var(--primary-color);
        color: white;
        font-size: 0.95rem;
        line-height: 1;
        padding: 0.6rem 1.15rem;
    }
    .affiliate-program-list { padding-left: 1.1rem; color: #4b5563; line-height: 1.75; flex-grow: 1; margin: 0; }
    .affiliate-tier-section { margin-top: 3.75rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }
    .affiliate-tier-title { font-size: 1.65rem; text-align: center; margin-bottom: 1.5rem; }
    .affiliate-tier-empty { text-align: center; }
    .affiliate-tier-copy { font-size: 0.875rem; color: #6b7280; margin-top: 1rem; text-align: center; }
    .affiliate-cta-copy { font-size: 1.125rem; opacity: 0.9; }
    .affiliate-cta-row { display: flex; gap: 0.5rem; max-width: 600px; margin: 0 auto; }
    .affiliate-cta-input { flex: 1; padding: 1rem; border: none; border-radius: 8px; color: #111827; font-weight: 500; }
    .affiliate-cta-copy-btn { background: #111827; color: white; border: none; padding: 0 1.5rem; }
    .program-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
        gap: 1rem;
        align-items: stretch;
        margin-bottom: 1rem;
    }
    .program-card { background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 1.35rem; transition: transform 0.2s; display: grid; grid-template-rows: auto auto auto 1fr auto; min-width: 0; align-content: start; height: 100%; }
    .program-card:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
    .program-card h2 { font-size: 1.35rem; line-height: 1.2; margin-bottom: 0.8rem; color: #111827; }
    .program-card .badge { display: block; width: fit-content; margin: 0 0 0.9rem; background: #eff6ff; color: #2563eb; font-weight: 700; padding: 0.4rem 0.8rem; border-radius: 9999px; font-size: 0.72rem; text-align: center; }
    .program-card .btn { align-self: flex-start; width: auto !important; margin-top: 1rem !important; }
    .affiliate-card-copy { color: #4b5563; line-height: 1.65; font-size: 0.95rem; }
    .affiliate-card-copy > *:first-child { margin-top: 0; }
    .affiliate-card-copy p { margin: 0 0 0.85rem; }
    .affiliate-card-copy ul { margin: 0.85rem 0 0; padding-left: 1.15rem; }
    .affiliate-card-copy li { margin-bottom: 0.45rem; }
    .affiliate-guidance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); gap: 1rem; }
    .affiliate-guidance-card { background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 1.25rem; min-width: 0; }
    .affiliate-guidance-card h3 { margin: 0 0 0.75rem; font-size: 1.15rem; color: var(--text-color); }
    .affiliate-guidance-section {
        margin-top: 3.25rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    .affiliate-guidance-heading {
        margin: 0 0 1.1rem;
    }
    .affiliate-guidance-heading h2 {
        margin: 0;
        font-size: 1.45rem;
        color: var(--text-color);
    }
    .affiliate-guidance-heading p {
        margin: 0.45rem 0 0;
        color: var(--text-muted);
        line-height: 1.6;
        max-width: 720px;
    }
    .tier-table-wrap { background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 1rem; }
    .tier-table { width: 100%; border-collapse: collapse; margin-top: 1rem; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
    .tier-table th { background: #f9fafb; padding: 1rem; text-align: left; font-weight: 600; color: #374151; }
    .tier-table td { padding: 1rem; border-top: 1px solid #e5e7eb; color: #4b5563; }
    .cta-box { background: linear-gradient(135deg, rgba(37, 99, 235, 0.07), rgba(99, 102, 241, 0.1)); border: 1px solid rgba(37, 99, 235, 0.15); border-radius: 24px; padding: 3rem; text-align: center; color: var(--text-color); margin-top: 1rem; display: flex; flex-direction: column; align-items: center; gap: 1.25rem; }
    .cta-box h2 { font-size: 2.15rem; margin: 0; }
    .cta-box p { color: var(--text-muted); margin: 0; max-width: 760px; }
    .cta-box input[type="text"] { background: white; }
    .cta-box .btn { display: inline-block; width: auto; font-size: 1.125rem; padding: 0.875rem 2.5rem; }
    .cta-box .btn:hover { background: var(--primary-hover); }
    @media (min-width: 1180px) {
        .affiliate-section--dashboard .program-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .affiliate-section--marketing .program-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 1280px) {
        .program-card { padding: 1.35rem; }
        .affiliate-hero-card h1 { font-size: 1.9rem; }
        .affiliate-cta-row { flex-direction: column; max-width: 100%; }
        .affiliate-cta-copy-btn { padding: 0.9rem 1.25rem; }
        .affiliate-hero-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 900px) {
        .cta-box { padding: 2rem 1.25rem; }
        .program-card h2 { font-size: 1.3rem; }
        .affiliate-summary-grid { grid-template-columns: 1fr; }
    }
</style>

<?php if ($showAccountShell): ?>
<div class="fm-container affiliate-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>
    <div class="fm-main affiliate-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Creator Rewards</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Creator Rewards</span>
                </div>
            </div>
            <div class="toolbar-right">
                <div class="toolbar-controls">
                    <span class="affiliate-toolbar-note"><?= SiteContentService::renderInlineMarkdown((string)($affiliateEnabled ? ($affiliateHeader['toolbar_enabled'] ?? 'Choose your earning model, review current PPD rates, and manage the referral link tied to your account.') : ($affiliateHeader['toolbar_disabled'] ?? 'Choose your earning model, review current PPD rates, and track the rewards tools available on your account.')), $siteContentTokens) ?></span>
                </div>
            </div>
        </div>

        <div class="affiliate-hero-card">
            <div class="affiliate-hero-grid">
                <div class="affiliate-hero-copy">
                    <h1><?= SiteContentService::renderInlineMarkdown((string)($affiliateHeader['hero_title'] ?? 'Earn with {site_name}'), $siteContentTokens) ?></h1>
                    <p><?= SiteContentService::renderInlineMarkdown((string)($affiliateEnabled ? ($affiliateHeader['hero_intro_enabled'] ?? 'Choose the reward model that fits your traffic, track earnings clearly, and share your referral link when referrals are available.') : ($affiliateHeader['hero_intro_disabled'] ?? 'Choose the reward model that fits your traffic, review current payout rates, and track the earning tools available on your account.')), $siteContentTokens) ?></p>
                    <?php if ($user): ?>
                        <div class="affiliate-actions">
                            <a href="/rewards" class="btn">Open Rewards Dashboard</a>
                            <a href="/settings" class="btn btn-secondary">Update Payout Settings</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="affiliate-summary-grid">
                    <?php if ($user): ?>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">Current model</div>
                            <div class="affiliate-summary-value"><?= htmlspecialchars($currentModelLabel ?? 'Not selected') ?></div>
                            <div class="affiliate-summary-copy">Switch between models whenever your traffic mix changes.</div>
                        </div>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">Referral program</div>
                            <div class="affiliate-summary-value"><?= $affiliateEnabled ? 'Active' : 'Off' ?></div>
                            <div class="affiliate-summary-copy"><?= $affiliateEnabled ? 'Referral commissions can stack on top of your reward model.' : 'You can still earn through the reward models below.' ?></div>
                        </div>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">Payout method</div>
                            <div class="affiliate-summary-value"><?= htmlspecialchars($paymentMethodLabel) ?></div>
                            <div class="affiliate-summary-copy"><?= $paymentMethodRaw !== '' ? 'Keep your payout details current in account settings.' : 'Add your payout details in settings before requesting a payout.' ?></div>
                        </div>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">PPD rate tiers</div>
                            <div class="affiliate-summary-value"><?= number_format(count($tiers)) ?></div>
                            <div class="affiliate-summary-copy"><?= !empty($tiers) ? 'Current country groups are listed below.' : 'Rates will appear here when PPD tiers are available.' ?></div>
                        </div>
                    <?php else: ?>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">Earning models</div>
                            <div class="affiliate-summary-value"><?= number_format(count($enabledModels)) ?></div>
                            <div class="affiliate-summary-copy">Choose the model that best fits your traffic and audience.</div>
                        </div>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">Referral program</div>
                            <div class="affiliate-summary-value"><?= $affiliateEnabled ? 'Available' : 'Not active' ?></div>
                            <div class="affiliate-summary-copy"><?= $affiliateEnabled ? 'Referral commissions can be earned alongside creator rewards.' : 'Creator rewards are still available even without referrals.' ?></div>
                        </div>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">PPD rate tiers</div>
                            <div class="affiliate-summary-value"><?= number_format(count($tiers)) ?></div>
                            <div class="affiliate-summary-copy"><?= !empty($tiers) ? 'Current country groups are listed below.' : 'Rates will appear here when PPD tiers are available.' ?></div>
                        </div>
                        <div class="affiliate-summary-card">
                            <div class="affiliate-summary-label">Payout requests</div>
                            <div class="affiliate-summary-value">Available</div>
                            <div class="affiliate-summary-copy">Track cleared earnings and request payouts from your account dashboard.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="affiliate-section affiliate-section--dashboard">
<?php else: ?>
<div class="affiliate-section affiliate-section--marketing" style="padding-top: 4rem;">
    <div class="affiliate-hero-card" style="margin-bottom: 3rem;">
        <div class="affiliate-hero-grid">
            <div class="affiliate-hero-copy">
                <h1><?= SiteContentService::renderInlineMarkdown((string)($affiliateHeader['hero_title'] ?? 'Earn with {site_name}'), $siteContentTokens) ?></h1>
                <p><?= SiteContentService::renderInlineMarkdown((string)($affiliateEnabled ? ($affiliateHeader['hero_intro_enabled'] ?? 'Choose the reward model that fits your traffic, track earnings clearly, and share your referral link when referrals are available.') : ($affiliateHeader['hero_intro_disabled'] ?? 'Choose the reward model that fits your traffic, review current payout rates, and track the earning tools available on your account.')), $siteContentTokens) ?></p>
            </div>
            <div class="affiliate-summary-grid">
                <div class="affiliate-summary-card">
                    <div class="affiliate-summary-label">Earning models</div>
                    <div class="affiliate-summary-value"><?= number_format(count($enabledModels)) ?></div>
                    <div class="affiliate-summary-copy">Choose the model that best fits your traffic and audience.</div>
                </div>
                <div class="affiliate-summary-card">
                    <div class="affiliate-summary-label">Referral program</div>
                    <div class="affiliate-summary-value"><?= $affiliateEnabled ? 'Available' : 'Not active' ?></div>
                    <div class="affiliate-summary-copy"><?= $affiliateEnabled ? 'Referral commissions can be earned alongside creator rewards.' : 'Creator rewards are still available even without referrals.' ?></div>
                </div>
                <div class="affiliate-summary-card">
                    <div class="affiliate-summary-label">PPD rate tiers</div>
                    <div class="affiliate-summary-value"><?= number_format(count($tiers)) ?></div>
                    <div class="affiliate-summary-copy"><?= !empty($tiers) ? 'Current country groups are listed below.' : 'Rates will appear here when PPD tiers are available.' ?></div>
                </div>
                <div class="affiliate-summary-card">
                    <div class="affiliate-summary-label">Payout requests</div>
                    <div class="affiliate-summary-value">Available</div>
                    <div class="affiliate-summary-copy">Track cleared earnings and request payouts from your account dashboard.</div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
            <section class="affiliate-section-block">
                <div class="affiliate-section-heading">
                    <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliateModelsSection['title'] ?? 'Choose how you want to earn'), $siteContentTokens) ?></h2>
                    <div class="affiliate-section-copy"><?= SiteContentService::renderMarkdown((string)($affiliateModelsSection['intro'] ?? 'Pick the reward model that best matches your traffic. You can switch later as your audience changes.'), $siteContentTokens) ?></div>
                </div>
            </section>
            <div class="program-grid">
                <?php if (in_array('mixed', $enabledModels, true)): ?>
                <div class="program-card <?= ($userModel === 'mixed') ? 'affiliate-program-card--active' : '' ?>">
                    <span class="badge <?= ($userModel === 'mixed') ? 'affiliate-program-badge--active' : 'affiliate-program-badge--default' ?>">
                        <?= htmlspecialchars((string)(($userModel === 'mixed') ? ($affiliateMixed['badge_current'] ?? 'Your Current Model') : ($affiliateMixed['badge_default'] ?? 'Hybrid Model'))) ?>
                    </span>
                    <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliateMixed['title'] ?? 'PPD + PPS Hybrid'), $siteContentTokens) ?></h2>
                    <div class="affiliate-card-copy"><?= SiteContentService::renderMarkdown((string)($affiliateMixed['body'] ?? ''), $siteContentTokens) ?></div>
                    <?php if ($user && $userModel !== 'mixed'): ?>
                        <form method="POST" action="/settings/update-monetization">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="model" value="mixed">
                            <button type="submit" class="btn btn-outline-primary w-100 mt-3">Switch to Hybrid</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (in_array('ppd', $enabledModels, true)): ?>
                <div class="program-card <?= ($userModel === 'ppd') ? 'affiliate-program-card--active' : '' ?>">
                    <span class="badge <?= ($userModel === 'ppd') ? 'affiliate-program-badge--active' : '' ?>">
                        <?= htmlspecialchars((string)(($userModel === 'ppd') ? ($affiliatePpd['badge_current'] ?? 'Your Current Model') : ($affiliatePpd['badge_default'] ?? 'PPD Program'))) ?>
                    </span>
                    <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliatePpd['title'] ?? 'Pay Per Download'), $siteContentTokens) ?></h2>
                    <div class="affiliate-card-copy"><?= SiteContentService::renderMarkdown((string)($affiliatePpd['body'] ?? ''), $siteContentTokens) ?></div>
                    <?php if ($user && $userModel !== 'ppd'): ?>
                        <form method="POST" action="/settings/update-monetization">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="model" value="ppd">
                            <button type="submit" class="btn btn-outline-primary w-100 mt-3">Switch to PPD</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (in_array('pps', $enabledModels, true)): ?>
                <div class="program-card <?= ($userModel === 'pps') ? 'affiliate-program-card--active' : '' ?>">
                    <span class="badge <?= ($userModel === 'pps') ? 'affiliate-program-badge--active' : '' ?>">
                        <?= htmlspecialchars((string)(($userModel === 'pps') ? ($affiliatePps['badge_current'] ?? 'Your Current Model') : ($affiliatePps['badge_default'] ?? 'PPS Program'))) ?>
                    </span>
                    <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliatePps['title'] ?? 'Pay Per Sale'), $siteContentTokens) ?></h2>
                    <div class="affiliate-card-copy"><?= SiteContentService::renderMarkdown((string)($affiliatePps['body'] ?? ''), $siteContentTokens) ?></div>
                    <?php if ($user && $userModel !== 'pps'): ?>
                        <form method="POST" action="/settings/update-monetization">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="model" value="pps">
                            <button type="submit" class="btn btn-outline-primary w-100 mt-3">Switch to PPS</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <section class="affiliate-section-block affiliate-guidance-section">
                <div class="affiliate-guidance-heading">
                    <h2>How rewards work</h2>
                    <p>Use these notes to see what qualifies, how referrals work, and when earnings are ready.</p>
                </div>
                <div class="affiliate-guidance-grid">
                    <?php foreach ($affiliateGuidanceCards as $card): ?>
                        <div class="affiliate-guidance-card">
                            <h3><?= SiteContentService::renderInlineMarkdown((string)($card['title'] ?? ''), $siteContentTokens) ?></h3>
                            <div class="affiliate-card-copy"><?= SiteContentService::renderMarkdown((string)($card['body'] ?? ''), $siteContentTokens) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="affiliate-tier-section">
                <div class="affiliate-section-heading">
                    <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliateTierSection['title'] ?? 'Current PPD Tier Rates'), $siteContentTokens) ?></h2>
                    <div class="affiliate-section-copy"><?= SiteContentService::renderMarkdown((string)($affiliateTierSection['intro'] ?? 'Rates vary by visitor country group and apply to qualifying downloads.'), $siteContentTokens) ?></div>
                </div>
                <div class="tier-table-wrap">
                    <table class="tier-table">
                        <thead>
                            <tr>
                                <th>Tier</th>
                                <th>Countries</th>
                                <th>Rate per 1000 Downloads</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($tiers)): foreach ($tiers as $tier): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($tier['name']) ?></strong></td>
                                    <td><?= $tier['countries'] ? htmlspecialchars(str_replace(',', ', ', $tier['countries'])) : 'Fallback / all other countries' ?></td>
                                    <td><strong>$<?= number_format($tier['rate_per_1000'], 2) ?></strong></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr>
                                    <td colspan="3" class="affiliate-tier-empty"><?= htmlspecialchars((string)($affiliateTierSection['empty_state'] ?? 'No PPD tiers are available right now.')) ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="affiliate-section-block">
            <div class="cta-box">
                <?php if ($user): ?>
                    <?php if ($affiliateEnabled): ?>
                        <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliateMemberCta['referral_title'] ?? 'Your referral link'), $siteContentTokens) ?></h2>
                        <p class="affiliate-cta-copy"><?= SiteContentService::renderInlineMarkdown((string)($affiliateMemberCta['referral_body'] ?? 'Share this link when you want signups credited to your account. When referred users earn through rewards, your referral commission follows the current site rules.'), $siteContentTokens) ?></p>
                        <div class="affiliate-cta-row">
                            <?php $refCode = trim((string)($user['public_id'] ?? '')); ?>
                            <?php $refLink = $refCode !== '' ? (\App\Service\SeoService::trustedBaseUrl() . '/?ref=' . rawurlencode($refCode)) : ''; ?>
                            <input type="text" value="<?= htmlspecialchars($refLink !== '' ? $refLink : 'Referral link unavailable. Please contact support if this persists.') ?>" readonly class="affiliate-cta-input">
                            <button class="btn affiliate-cta-copy-btn" type="button" data-copy-previous data-copy-success="Copied!" <?= $refLink === '' ? 'disabled' : '' ?>><?= htmlspecialchars((string)($affiliateMemberCta['copy_button_label'] ?? 'Copy')) ?></button>
                        </div>
                    <?php else: ?>
                        <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliateMemberCta['referrals_off_title'] ?? 'Referral commissions are currently off'), $siteContentTokens) ?></h2>
                        <p class="affiliate-cta-copy"><?= SiteContentService::renderInlineMarkdown((string)($affiliateMemberCta['referrals_off_body'] ?? 'Rewards are still available, but referral signups and referral commissions are currently disabled.'), $siteContentTokens) ?></p>
                        <a href="/rewards" class="btn"><?= htmlspecialchars((string)($affiliateMemberCta['referrals_off_button'] ?? 'Open Rewards Dashboard')) ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <h2><?= SiteContentService::renderInlineMarkdown((string)($affiliateGuestCta['title'] ?? 'Create an account to start earning'), $siteContentTokens) ?></h2>
                    <p class="affiliate-cta-copy"><?= SiteContentService::renderInlineMarkdown((string)($affiliateEnabled ? ($affiliateGuestCta['body_enabled'] ?? 'Create an account to unlock rewards, referral tools, payout requests, and your earnings dashboard.') : ($affiliateGuestCta['body_disabled'] ?? 'Create an account to unlock rewards, payout requests, and your earnings dashboard.')), $siteContentTokens) ?></p>
                    <a href="/register" class="btn"><?= htmlspecialchars((string)($affiliateGuestCta['button_label'] ?? 'Create My Account')) ?></a>
                <?php endif; ?>
            </div>
            </section>
        </div>
<?php if ($showAccountShell): ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
