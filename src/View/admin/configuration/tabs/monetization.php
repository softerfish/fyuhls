<?php
// Initialize fallback values to prevent warnings if not passed from controller
$adTop = $adTop ?? '';
$adLeft = $adLeft ?? '';
$adRight = $adRight ?? '';
$adBottom = $adBottom ?? '';
$adOverlay = $adOverlay ?? '';
$tiers = $tiers ?? [];
$exampleTiers = $exampleTiers ?? [];
$bonusOfferDefinitions = $bonusOfferDefinitions ?? \App\Service\BonusOfferService::definitions();
$bonusOffers = $bonusOffers ?? [];
$bonusPendingAwards = $bonusPendingAwards ?? [];
$bonusRecentAwards = $bonusRecentAwards ?? [];
$withdrawalProcessors = $withdrawalProcessors ?? \App\Service\PayoutProcessorService::definitions(false);
$bonusEditingOffer = $bonusEditingOffer ?? null;
$bonusReviewOnly = !empty($bonusReviewOnly);
$canReviewBonusAwards = \App\Core\Auth::hasCapability('bonus_awards.review');
$bonusMonetizationPane = trim((string)($bonusMonetizationPane ?? ''));
$activeMonetizationPane = in_array($bonusMonetizationPane, ['bonus-offers', 'ads', 'tiers'], true) ? $bonusMonetizationPane : 'rewards';
if ($bonusReviewOnly) {
    $activeMonetizationPane = 'bonus-offers';
}
$bonusAllowedMetricsByOfferKind = $bonusAllowedMetricsByOfferKind ?? \App\Service\BonusOfferService::allowedMetricsByOfferKind();
$bonusMetricDescriptions = $bonusMetricDescriptions ?? \App\Service\BonusOfferService::metricDescriptions();
$bonusAllowedRewardTypesByMetric = [
    'approved_payouts' => ['fixed'],
    'uploaded_files' => ['fixed'],
    'rewarded_downloads' => ['fixed', 'multiplier', 'percent'],
    'cleared_earnings_amount' => ['fixed', 'multiplier', 'percent'],
    'verified_referrals' => ['fixed'],
    'premium_referrals' => ['fixed'],
    'no_fraud_reversal_days' => ['fixed'],
];
$bonusMetricsUsingClearedOnly = ['rewarded_downloads', 'cleared_earnings_amount'];
$bonusStatusOptions = [
    'draft' => 'Draft',
    'scheduled' => 'Waiting for start date',
    'active' => 'Live now',
    'paused' => 'Paused',
    'ended' => 'Finished',
    'archived' => 'Archived',
];
$bonusAwardStatusLabels = [
    'pending_review' => 'Waiting for approval',
    'credited' => 'Credited to balance',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'reversed' => 'Reversed',
    'expired' => 'Expired',
];
$editingAudienceIds = '';
$editingWeekdays = [];
if (is_array($bonusEditingOffer)) {
    $decodedAudience = json_decode((string)($bonusEditingOffer['audience_json'] ?? ''), true);
    if (is_array($decodedAudience)) {
        $editingAudienceIds = implode(', ', array_map('strval', $decodedAudience));
    }
    $decodedWeekdays = json_decode((string)($bonusEditingOffer['weekday_json'] ?? ''), true);
    if (is_array($decodedWeekdays)) {
        $editingWeekdays = array_map('strval', $decodedWeekdays);
    }
}

$rewardsActive = true;
?>

<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Revenue Sections</div>
        <div class="nav flex-column nav-pills" id="monetizationTabs" role="tablist" aria-orientation="vertical">
            <button class="nav-link text-start <?= $activeMonetizationPane === 'bonus-offers' ? 'active' : '' ?>" id="bonus-offers-tab" data-bs-toggle="pill" data-bs-target="#bonus-offers-content" type="button">
                <i class="bi bi-stars me-2"></i> Bonus Offers
            </button>
            <?php if (!$bonusReviewOnly): ?>
                <button class="nav-link text-start <?= $activeMonetizationPane === 'rewards' ? 'active' : '' ?>" id="rewards-tab" data-bs-toggle="pill" data-bs-target="#rewards-content" type="button">
                    <i class="bi bi-cash-coin me-2"></i> Rewards
                </button>
                <button class="nav-link text-start <?= $activeMonetizationPane === 'ads' ? 'active' : '' ?>" id="ads-tab" data-bs-toggle="pill" data-bs-target="#ads-content" type="button">
                    <i class="bi bi-megaphone me-2"></i> Ad Placements
                </button>
                <button class="nav-link text-start <?= $activeMonetizationPane === 'tiers' ? 'active' : '' ?>" id="tiers-tab" data-bs-toggle="pill" data-bs-target="#tiers-content" type="button">
                    <i class="bi bi-globe me-2"></i> PPD Geographic Tiers
                </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">Monetization</h5>
                <p class="config-section-intro__text"><?= $bonusReviewOnly ? 'Review pending bonus awards from the Monetization area.' : 'Configure uploader rewards, withdrawal rules, payment gateways, ad placements, and the geographic tier logic that drives PPD payout strategy.' ?></p>
            </div>
            <?php if (!$bonusReviewOnly): ?>
                <ul class="config-summary-chips">
                    <li class="config-summary-chip <?= !empty($rewardsEnabled) ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Rewards: <?= !empty($rewardsEnabled) ? 'Enabled' : 'Off' ?></li>
                    <li class="config-summary-chip <?= !empty($affiliateEnabled) ? 'config-summary-chip--info' : 'config-summary-chip--warning' ?>">Affiliate: <?= !empty($affiliateEnabled) ? 'Enabled' : 'Off' ?></li>
                    <li class="config-summary-chip <?= (!empty($stripeEnabled) || !empty($paypalEnabled)) ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Checkout: <?= (!empty($stripeEnabled) || !empty($paypalEnabled)) ? 'Configured' : 'No gateway' ?></li>
                </ul>
            <?php endif; ?>
        </div>
        <?php if (!$bonusReviewOnly): ?>
            <details class="config-help-panel">
                <summary>How this works</summary>
                <div class="config-help-panel__body">
                    <p>Rewards and affiliate settings shape what users can earn, while the gateway settings shape how packages are sold. Ad placements and PPD tiers are separate surfaces because they influence monetization in very different ways.</p>
                </div>
            </details>
        <?php endif; ?>

<div class="tab-content" id="monetizationContent">
    <?php if (!$bonusReviewOnly): ?>
    <div class="tab-pane fade <?= $activeMonetizationPane === 'rewards' ? 'show active' : '' ?>" id="rewards-content">
        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="monetization">
            <input type="hidden" name="monetization_action" value="rewards_settings">

            <div class="card border-0 shadow-sm mb-4 config-section-card">
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="rewards_enabled" id="rewardsEnabled" value="1" <?= !empty($rewardsEnabled) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="rewardsEnabled">Enable Rewards</label>
                        <div class="config-form-note mt-1">Turns on the built-in monetization system for pay-per-download, payouts, withdrawal requests, uploader earnings tracking, and the rewards fraud tools.</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="affiliate_enabled" id="affiliateEnabled" value="1" <?= !empty($affiliateEnabled) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="affiliateEnabled">Enable Affiliate Program</label>
                        <div class="config-form-note mt-1">Enables referral tracking so users can earn commission when visitors they refer buy packages or generate qualifying sales activity. Affiliate requires Rewards and will automatically turn off if Rewards is disabled.</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4 config-section-card">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Available Monetization Models</h6>
                    <?php foreach (['ppd' => 'Pay-Per-Download', 'pps' => 'Pay-Per-Sale', 'mixed' => 'Mixed Model'] as $modelKey => $modelLabel): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="enabled_models[]" value="<?= $modelKey ?>" id="model_<?= $modelKey ?>" <?= in_array($modelKey, $enabledModels ?? [], true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="model_<?= $modelKey ?>"><?= $modelLabel ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">PPS Commission (%)</label>
                    <input type="number" class="form-control" name="pps_commission_percent" value="<?= htmlspecialchars($ppsCommission ?? '50') ?>" min="0" max="100">
                    <div class="config-form-note mt-1">Direct pay-per-sale commission paid to the uploader when a premium purchase is attributed through their download flow.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Referral % Rate</label>
                    <input type="number" class="form-control" name="referral_commission_percent" value="<?= htmlspecialchars($referralCommissionPercent ?? '50') ?>" min="0" max="100">
                    <div class="config-form-note mt-1">Affiliate referral commission paid to the referring user when someone signs up under their referral link and generates eligible earnings.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Affiliate Hold Days</label>
                    <input type="number" class="form-control" name="affiliate_hold_days" value="<?= htmlspecialchars(\App\Model\Setting::get('affiliate_hold_days', '5', 'rewards')) ?>" min="0" max="90">
                    <div class="config-form-note mt-1">How long affiliate commission stays held before it clears automatically. Use this to buffer refunds and chargebacks. Default: 5 days.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Mixed PPD Percentage (%)</label>
                    <input type="number" class="form-control" name="mixed_ppd_percent" value="<?= htmlspecialchars($mixedPpdPercent ?? '30') ?>">
                    <div class="config-form-note mt-1">How much of the standard PPD rate a Hybrid user receives for download earnings.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Mixed PPS Percentage (%)</label>
                    <input type="number" class="form-control" name="mixed_pps_percent" value="<?= htmlspecialchars($mixedPpsPercent ?? '30') ?>" min="0" max="100">
                    <div class="config-form-note mt-1">How much of the standard PPS commission a Hybrid user receives for premium sales attributed through their files.</div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">IP Reward Limit / 24h</label>
                    <input type="number" class="form-control" name="ppd_ip_reward_limit" value="<?= htmlspecialchars(\App\Model\Setting::get('ppd_ip_reward_limit', '1', 'rewards')) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Min File Size (MB)</label>
                    <input type="number" step="0.1" class="form-control" name="ppd_min_file_size" value="<?= htmlspecialchars(round((float)\App\Model\Setting::get('ppd_min_file_size', '0', 'rewards') / 1024 / 1024, 2)) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Max File Size (MB)</label>
                    <input type="number" step="0.1" class="form-control" name="ppd_max_file_size" value="<?= htmlspecialchars(round((float)\App\Model\Setting::get('ppd_max_file_size', '0', 'rewards') / 1024 / 1024, 2)) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Min Download Progress (%)</label>
                    <input type="number" class="form-control" name="ppd_min_download_percent" value="<?= htmlspecialchars(\App\Model\Setting::get('ppd_min_download_percent', '0', 'rewards')) ?>" min="0" max="100">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Max Earnings by IP ($)</label>
                    <input type="number" step="0.01" class="form-control" name="ppd_max_earn_ip" value="<?= htmlspecialchars(\App\Model\Setting::get('ppd_max_earn_ip', '0', 'rewards')) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Max Earnings by File ($)</label>
                    <input type="number" step="0.01" class="form-control" name="ppd_max_earn_file" value="<?= htmlspecialchars(\App\Model\Setting::get('ppd_max_earn_file', '0', 'rewards')) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Max Earnings by User ($)</label>
                    <input type="number" step="0.01" class="form-control" name="ppd_max_earn_user" value="<?= htmlspecialchars(\App\Model\Setting::get('ppd_max_earn_user', '0', 'rewards')) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Min Video Watch Percent (%)</label>
                    <input type="number" class="form-control" name="rewards_min_video_watch_percent" value="<?= htmlspecialchars($minVideoWatchPercent ?? '80') ?>" min="0" max="100">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Min Video Watch Seconds</label>
                    <input type="number" class="form-control" name="rewards_min_video_watch_seconds" value="<?= htmlspecialchars($minVideoWatchSeconds ?? '30') ?>" min="0">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Reward Guests Only</label>
                    <select class="form-select" name="ppd_only_guests_count">
                        <?php $guestOnly = \App\Model\Setting::get('ppd_only_guests_count', '0', 'rewards'); ?>
                        <option value="0" <?= $guestOnly === '0' ? 'selected' : '' ?>>No</option>
                        <option value="1" <?= $guestOnly === '1' ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Count VPN / Proxy Traffic</label>
                    <?php $rewardVpn = \App\Model\Setting::get('ppd_reward_vpn', '0', 'rewards'); ?>
                    <select class="form-select" name="ppd_reward_vpn">
                        <option value="0" <?= $rewardVpn === '0' ? 'selected' : '' ?>>No</option>
                        <option value="1" <?= $rewardVpn === '1' ? 'selected' : '' ?>>Yes</option>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Payout Processors</label>
                <div class="config-form-note mb-3">Set the payout processors users can choose from, and make each one explicit about what destination staff should pay. This replaces vague labels like “Crypto” with the exact destination type you want users to provide.</div>
                <div class="border rounded-3 p-3 bg-light-subtle" id="withdrawalProcessorManager">
                    <div class="d-grid gap-3" id="withdrawalProcessorRows">
                        <?php foreach (($withdrawalProcessors ?? []) as $index => $processor): ?>
                            <div class="border rounded-3 bg-white p-3 withdrawal-processor-row" data-processor-row>
                                <input type="hidden" name="withdrawal_processor_existing_key[]" value="<?= htmlspecialchars((string)($processor['key'] ?? '')) ?>">
                                <input type="hidden" name="withdrawal_processor_row_id[]" value="processor_<?= htmlspecialchars((string)($processor['key'] ?? $index)) ?>">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                    <div>
                                        <div class="fw-bold">Processor <?= $index + 1 ?></div>
                                        <div class="config-form-note">Users will see this label in Settings and on the rewards dashboard.</div>
                                    </div>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <label class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" name="withdrawal_processor_enabled[]" value="processor_<?= htmlspecialchars((string)($processor['key'] ?? $index)) ?>" <?= !empty($processor['enabled']) ? 'checked' : '' ?>>
                                            <span class="form-check-label">Enabled</span>
                                        </label>
                                        <button class="btn btn-outline-secondary btn-sm" type="button" data-remove-processor-row>Remove</button>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Processor name</label>
                                        <input type="text" class="form-control" name="withdrawal_processor_label[]" maxlength="80" value="<?= htmlspecialchars((string)($processor['label'] ?? '')) ?>" placeholder="e.g. PayPal, USDT (TRC20), Wise">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Destination field label</label>
                                        <input type="text" class="form-control" name="withdrawal_processor_destination_label[]" maxlength="160" value="<?= htmlspecialchars((string)($processor['destination_label'] ?? '')) ?>" placeholder="e.g. PayPal account email">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Destination placeholder</label>
                                        <input type="text" class="form-control" name="withdrawal_processor_placeholder[]" maxlength="160" value="<?= htmlspecialchars((string)($processor['placeholder'] ?? '')) ?>" placeholder="e.g. creator@example.com">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Helper text shown to users</label>
                                        <input type="text" class="form-control" name="withdrawal_processor_help_text[]" maxlength="260" value="<?= htmlspecialchars((string)($processor['help_text'] ?? '')) ?>" placeholder="Explain exactly what staff needs in order to send this payout">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-primary btn-sm" type="button" id="addWithdrawalProcessorBtn">Add payout processor</button>
                        <span class="config-form-note align-self-center">Tip: use precise names like “USDT (TRC20)” or “PayPal account email” so nobody has to guess.</span>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Rewards Retention (Days)</label>
                <input type="number" class="monetization-retention-input form-control" name="rewards_retention_days" value="<?= htmlspecialchars($retentionDays ?? '7') ?>" min="1" max="365">
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Minimum Withdrawal Amount</label>
                <input type="number" class="form-control" name="minimum_withdrawal_amount" value="<?= htmlspecialchars($minimumWithdrawalAmount ?? '1.00') ?>" min="0" step="0.01">
                <div class="config-form-note mt-1">The smallest payout request users are allowed to submit from their rewards dashboard. Use <code>0</code> only if you intentionally want to allow tiny payout requests.</div>
            </div>

            <div class="config-soft-callout config-section-card">
                PPD rates are controlled from the <strong>PPD Geographic Tiers</strong> tab. Add country-based tiers there instead of using one flat global rate. If you want a rest-of-world fallback, create a tier with no countries assigned.
            </div>

            <div class="config-soft-callout config-section-card">
                        PPD can count on accelerated delivery methods, but for ordinary file downloads the strongest threshold-based proof is App-Controlled PHP. Nginx can also honor <code>ppd_min_download_percent</code> through its completion log pipeline. Direct URLs, Apache X-SendFile, and LiteSpeed standard-file handoff remain start-based unless Fyuhls falls back to PHP.
            </div>

            <div class="config-soft-callout config-section-card">
                If you enable streaming support in the Downloads tab, video rewards can use watch-based validation. That streaming proof is separate from ordinary file-download payout verification. Use the video watch percent and seconds settings here to define the minimum playback needed before credit is considered.
            </div>

            <div class="card border-0 shadow-sm mb-4 config-section-card">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Payment Gateways</h6>
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="config-soft-callout h-100">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="payment_stripe_enabled" id="paymentStripeEnabled" value="1" <?= !empty($stripeEnabled) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="paymentStripeEnabled">Enable Stripe Checkout</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Secret Key</label>
                                    <input type="password" class="form-control" name="payment_stripe_secret_key" placeholder="<?= !empty($stripeSecretKey) ? 'Saved. Leave blank to keep current.' : 'sk_live_... or sk_test_...' ?>">
                                </div>
                                <div class="mb-0">
                                    <label class="form-label fw-bold">Webhook Secret</label>
                                    <input type="password" class="form-control" name="payment_stripe_webhook_secret" placeholder="<?= !empty($stripeWebhookSecret) ? 'Saved. Leave blank to keep current.' : 'whsec_...' ?>">
                                    <div class="config-form-note mt-1">Used for Stripe webhook verification on <code>/payment/callback/stripe</code>. Stripe success redirects also work through the direct session confirmation route.</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="config-soft-callout h-100">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="payment_paypal_enabled" id="paymentPaypalEnabled" value="1" <?= !empty($paypalEnabled) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="paymentPaypalEnabled">Enable PayPal Checkout</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Client ID</label>
                                    <input type="text" class="form-control" name="payment_paypal_client_id" value="<?= htmlspecialchars($paypalClientId ?? '') ?>" placeholder="PayPal client ID">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Client Secret</label>
                                    <input type="password" class="form-control" name="payment_paypal_client_secret" placeholder="<?= !empty($paypalClientSecret) ? 'Saved. Leave blank to keep current.' : 'PayPal client secret' ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Webhook ID</label>
                                    <input type="text" class="form-control" name="payment_paypal_webhook_id" value="<?= htmlspecialchars($paypalWebhookId ?? '') ?>" placeholder="PayPal webhook ID">
                                </div>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="payment_paypal_sandbox" id="paymentPaypalSandbox" value="1" <?= !empty($paypalSandbox) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="paymentPaypalSandbox">Use PayPal Sandbox</label>
                                </div>
                                <div class="config-form-note mt-2">PayPal uses server-side order creation and capture with a return URL back into the app. Switch Sandbox off only after your live credentials are ready.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="config-sticky-save">
                <p class="config-sticky-save__text">Rewards settings shape uploader earnings, payout rules, and package checkout readiness all at once, so changes here are worth reviewing carefully before saving.</p>
                <button type="submit" class="btn btn-primary px-5">Save Rewards Settings</button>
            </div>
        </form>

        <template id="withdrawalProcessorTemplate">
            <div class="border rounded-3 bg-white p-3 withdrawal-processor-row" data-processor-row>
                <input type="hidden" name="withdrawal_processor_existing_key[]" value="">
                <input type="hidden" name="withdrawal_processor_row_id[]" value="__ROW_ID__">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <div class="fw-bold">New processor</div>
                        <div class="config-form-note">Users will see this label in Settings and on the rewards dashboard.</div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <label class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="withdrawal_processor_enabled[]" value="__ROW_ID__" checked>
                            <span class="form-check-label">Enabled</span>
                        </label>
                        <button class="btn btn-outline-secondary btn-sm" type="button" data-remove-processor-row>Remove</button>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Processor name</label>
                        <input type="text" class="form-control" name="withdrawal_processor_label[]" maxlength="80" value="" placeholder="e.g. PayPal, USDT (TRC20), Wise">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Destination field label</label>
                        <input type="text" class="form-control" name="withdrawal_processor_destination_label[]" maxlength="160" value="" placeholder="e.g. PayPal account email">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Destination placeholder</label>
                        <input type="text" class="form-control" name="withdrawal_processor_placeholder[]" maxlength="160" value="" placeholder="e.g. creator@example.com">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Helper text shown to users</label>
                        <input type="text" class="form-control" name="withdrawal_processor_help_text[]" maxlength="260" value="" placeholder="Explain exactly what staff needs in order to send this payout">
                    </div>
                </div>
            </div>
        </template>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.getElementById('withdrawalProcessorRows');
            const addBtn = document.getElementById('addWithdrawalProcessorBtn');
            const template = document.getElementById('withdrawalProcessorTemplate');
            if (!rows || !addBtn || !template) {
                return;
            }

            let nextIndex = rows.querySelectorAll('[data-processor-row]').length;
            rows.addEventListener('click', function(event) {
                const removeButton = event.target.closest('[data-remove-processor-row]');
                if (!removeButton) {
                    return;
                }
                const row = removeButton.closest('[data-processor-row]');
                if (row) {
                    row.remove();
                }
            });
            addBtn.addEventListener('click', function() {
                const rowId = 'processor_new_' + String(nextIndex);
                const html = template.innerHTML
                    .replace(/__INDEX__/g, String(nextIndex))
                    .replace(/__ROW_ID__/g, rowId);
                rows.insertAdjacentHTML('beforeend', html);
                nextIndex += 1;
            });
        });
        </script>
    </div>
    <?php endif; ?>

    <div class="tab-pane fade <?= $activeMonetizationPane === 'bonus-offers' ? 'show active' : '' ?>" id="bonus-offers-content">
        <?php if ($bonusReviewOnly): ?>
        <div class="config-soft-callout config-section-card mb-4">
            Review pending bonus awards here. Use the main Bonus Offers workspace when you need to create or edit campaigns.
        </div>
        <?php else: ?>
        <div class="config-soft-callout config-section-card mb-4">
            Create bonus campaigns without changing your normal reward rates. Set what users must do, who can earn it, when it runs, and whether the bonus is approved manually or credited automatically. Approved bonuses are added to the user&apos;s main rewards balance and appear in <code>/rewards</code>.
        </div>

        <form method="POST" action="/admin/configuration/save" class="mb-4">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="monetization">
            <input type="hidden" name="monetization_action" value="save_bonus_offer">
            <input type="hidden" name="monetization_return" value="bonus-offers">
            <?php if (!empty($bonusEditingOffer['id'])): ?>
                <input type="hidden" name="offer_id" value="<?= (int)$bonusEditingOffer['id'] ?>">
                <input type="hidden" name="offer_edit_fingerprint" value="<?= htmlspecialchars(\App\Service\BonusOfferService::editFingerprint($bonusEditingOffer)) ?>">
            <?php endif; ?>

            <div class="card border-0 shadow-sm config-section-card">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h6 class="fw-bold mb-1"><?= !empty($bonusEditingOffer['id']) ? 'Edit Bonus Offer' : 'Create Bonus Offer' ?></h6>
                            <div class="config-form-note">Think of each offer as four choices: what users must do, what they get, who can earn it, and when it runs.</div>
                        </div>
                        <?php if (!empty($bonusEditingOffer['id'])): ?>
                            <a href="/admin/configuration?tab=monetization&monetization_pane=bonus-offers" class="btn btn-outline-secondary btn-sm">Start New Offer</a>
                        <?php endif; ?>
                    </div>

                    <div class="config-soft-callout mb-4">
                        <div class="fw-bold mb-2">Quick examples</div>
                        <div class="small text-muted">
                            Use <strong>Get rewarded downloads + Goal Amount 1000 + Goal Unit downloads</strong> for a download milestone,
                            <strong>Upload files + Goal Amount 10 + Goal Unit files</strong> for an upload bonus,
                            or <strong>Get premium referrals + Goal Amount 3</strong> for an affiliate push.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <h6 class="fw-bold mb-1">1. Name the offer</h6>
                            <div class="config-form-note">Choose what staff will call it internally and what users will see publicly.</div>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label fw-bold">Admin Label</label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars((string)($bonusEditingOffer['name'] ?? '')) ?>" required>
                            <div class="config-form-note mt-1">Internal only. This helps staff recognize the offer in admin.</div>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label fw-bold">User-Facing Title</label>
                            <input type="text" class="form-control" name="public_title" value="<?= htmlspecialchars((string)($bonusEditingOffer['public_title'] ?? '')) ?>" required>
                            <div class="config-form-note mt-1">Shown to users on Promotions and Rewards pages.</div>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($bonusStatusOptions as $statusKey => $statusLabel): ?>
                                    <?php if (in_array($statusKey, ['ended', 'archived'], true) && (($bonusEditingOffer['status'] ?? 'draft') !== $statusKey)): ?>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <option value="<?= htmlspecialchars($statusKey) ?>" <?= (($bonusEditingOffer['status'] ?? 'draft') === $statusKey) ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1">
                                Use <strong>Draft</strong> while building it, <strong>Live now</strong> to run it immediately, or <strong>Paused</strong> to stop it without deleting it.
                                <strong>Live now</strong> should not be paired with a future start date.
                                <strong>Waiting for start date</strong> and <strong>Finished</strong> are usually set automatically from the start and end dates. <strong>Archived</strong> is used when an offer is removed but its bonus history is kept for audit records.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">What Users Will Read</label>
                            <textarea class="form-control" name="public_description" rows="3" placeholder="Tell users what they need to do and what they will earn."><?= htmlspecialchars((string)($bonusEditingOffer['public_description'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-12 pt-2">
                            <h6 class="fw-bold mb-1">2. Decide what users must do</h6>
                            <div class="config-form-note">This defines the action, the target number, and whether the bonus is earned once or repeatedly.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label fw-bold">Bonus Style</label>
                            <select class="form-select" name="offer_kind" id="bonusOfferKindSelect">
                                <?php foreach (($bonusOfferDefinitions['offerKinds'] ?? []) as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= (($bonusEditingOffer['offer_kind'] ?? 'milestone') === $key) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1">Use this to tell staff whether the offer is a milestone, a limited-time push, or a referral campaign.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label fw-bold">What Users Must Do</label>
                            <select class="form-select" name="metric_key" id="bonusMetricKeySelect">
                                <?php foreach (($bonusOfferDefinitions['metrics'] ?? []) as $key => $label): ?>
                                    <?php
                                    $allowedKinds = [];
                                    foreach ($bonusAllowedMetricsByOfferKind as $kindKey => $metricKeys) {
                                        if (in_array($key, $metricKeys, true)) {
                                            $allowedKinds[] = $kindKey;
                                        }
                                    }
                                    ?>
                                    <option
                                        value="<?= htmlspecialchars($key) ?>"
                                        data-allowed-kinds="<?= htmlspecialchars(implode(',', $allowedKinds)) ?>"
                                        data-description="<?= htmlspecialchars((string)($bonusMetricDescriptions[$key] ?? '')) ?>"
                                        <?= (($bonusEditingOffer['metric_key'] ?? 'rewarded_downloads') === $key) ? 'selected' : '' ?>
                                    ><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1" id="bonusMetricHelp">Pick the activity you want to reward, like uploads, rewarded downloads, payouts, or referrals.</div>
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label fw-bold">Goal Amount</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="threshold_value" value="<?= htmlspecialchars((string)($bonusEditingOffer['threshold_value'] ?? '1')) ?>" required>
                            <div class="config-form-note mt-1">Examples: <code>10</code> files, <code>1000</code> downloads, <code>3</code> payouts, <code>30</code> days.</div>
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label fw-bold">Goal Unit</label>
                            <input type="text" class="form-control" name="threshold_unit" value="<?= htmlspecialchars((string)($bonusEditingOffer['threshold_unit'] ?? 'count')) ?>" placeholder="count, days, USD">
                            <div class="config-form-note mt-1">Short label for the target, like <code>files</code>, <code>downloads</code>, <code>days</code>, or <code>USD</code>.</div>
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label fw-bold">When To Award It</label>
                            <select class="form-select" name="trigger_style">
                                <?php foreach (($bonusOfferDefinitions['triggerStyles'] ?? []) as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= (($bonusEditingOffer['trigger_style'] ?? 'once') === $key) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1">Choose whether users can earn it only once or every time they hit another multiple of the goal.</div>
                        </div>
                        <div class="col-12 pt-2">
                            <h6 class="fw-bold mb-1">3. Decide what they get</h6>
                            <div class="config-form-note">Set the payout style and whether it credits automatically or waits for staff review.</div>
                        </div>
                        <div class="col-lg-3" id="bonusRewardTypeField">
                            <label class="form-label fw-bold">Bonus Format</label>
                            <select class="form-select" name="reward_type" id="bonusRewardTypeSelect">
                                <?php foreach (($bonusOfferDefinitions['rewardTypes'] ?? []) as $key => $label): ?>
                                    <?php
                                    $allowedMetrics = [];
                                    foreach ($bonusAllowedRewardTypesByMetric as $metricKey => $rewardTypes) {
                                        if (in_array($key, $rewardTypes, true)) {
                                            $allowedMetrics[] = $metricKey;
                                        }
                                    }
                                    ?>
                                    <option
                                        value="<?= htmlspecialchars($key) ?>"
                                        data-allowed-metrics="<?= htmlspecialchars(implode(',', $allowedMetrics)) ?>"
                                        <?= (($bonusEditingOffer['reward_type'] ?? 'fixed') === $key) ? 'selected' : '' ?>
                                    ><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1" id="bonusRewardTypeHelp">Fixed is the easiest to understand. Multiplier and percent are best for earnings-based promos.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label fw-bold">Bonus Amount</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="reward_value" value="<?= htmlspecialchars((string)($bonusEditingOffer['reward_value'] ?? '1')) ?>" required>
                            <div class="config-form-note mt-1">Examples: <code>5</code> for a $5 bonus, <code>2</code> for 2x, or <code>25</code> for a 25% bonus.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label fw-bold">Approval Flow</label>
                            <select class="form-select" name="award_mode">
                                <?php foreach (($bonusOfferDefinitions['awardModes'] ?? []) as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= (($bonusEditingOffer['award_mode'] ?? 'pending_review') === $key) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1">Use approval for higher-risk offers. Use automatic credit for simpler, lower-risk promos.</div>
                        </div>
                        <div class="col-12 pt-2">
                            <h6 class="fw-bold mb-1">4. Choose who can earn it and when it runs</h6>
                            <div class="config-form-note">This controls the audience, time window, timezone, and optional active days.</div>
                        </div>
                        <div class="col-lg-3" id="bonusAudienceTypeField">
                            <label class="form-label fw-bold">Who Can Earn This</label>
                            <select class="form-select" name="audience_type" id="bonusAudienceTypeSelect">
                                <?php foreach (($bonusOfferDefinitions['audienceTypes'] ?? []) as $key => $label): ?>
                                    <?php if ($key === 'all_affiliates' && (($bonusEditingOffer['audience_type'] ?? 'all_rewards') !== 'all_affiliates')): ?>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <?php
                                    $displayLabel = $label;
                                    if ($key === 'all_affiliates' && (($bonusEditingOffer['audience_type'] ?? 'all_rewards') === 'all_affiliates')) {
                                        $displayLabel = 'Legacy: rewards users when affiliate mode is on';
                                    }
                                    ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= (($bonusEditingOffer['audience_type'] ?? 'all_rewards') === $key) ? 'selected' : '' ?>><?= htmlspecialchars($displayLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1" id="bonusAudienceHelp">Pick everyone using rewards, only premium users, or a specific package or user list.</div>
                            <?php if (($bonusEditingOffer['audience_type'] ?? 'all_rewards') === 'all_affiliates'): ?>
                                <div class="config-form-note mt-1 text-warning">This offer uses a legacy audience mode that behaves like rewards users while affiliate mode is enabled. Move it to a supported audience when you can.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-3" id="bonusScheduleModeField">
                            <label class="form-label fw-bold">When This Offer Runs</label>
                            <select class="form-select" name="schedule_mode" id="bonusScheduleModeSelect">
                                <?php foreach (($bonusOfferDefinitions['scheduleModes'] ?? []) as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= (($bonusEditingOffer['schedule_mode'] ?? 'always') === $key) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="config-form-note mt-1" id="bonusScheduleHelp">Use a date range for one-time campaigns, or selected days inside a date range for recurring promos like weekends.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label fw-bold">Timezone For Dates</label>
                            <select class="form-select" name="timezone">
                                <?php $selectedTimezone = (string)($bonusEditingOffer['timezone'] ?? 'UTC'); ?>
                                <?php foreach (timezone_identifiers_list() as $timezoneId): ?>
                                    <option value="<?= htmlspecialchars($timezoneId) ?>" <?= $selectedTimezone === $timezoneId ? 'selected' : '' ?>><?= htmlspecialchars($timezoneId) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label fw-bold">Starts At</label>
                            <?php $startLocal = !empty($bonusEditingOffer['start_at']) ? (new DateTimeImmutable((string)$bonusEditingOffer['start_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($selectedTimezone))->format('Y-m-d\TH:i') : ''; ?>
                            <input type="datetime-local" class="form-control" name="start_at" value="<?= htmlspecialchars($startLocal) ?>">
                            <div class="config-form-note mt-1">Required for offers that run in a date window. Optional only for always-on offers.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label fw-bold">Ends At</label>
                            <?php $endLocal = !empty($bonusEditingOffer['end_at']) ? (new DateTimeImmutable((string)$bonusEditingOffer['end_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($selectedTimezone))->format('Y-m-d\TH:i') : ''; ?>
                            <input type="datetime-local" class="form-control" name="end_at" value="<?= htmlspecialchars($endLocal) ?>">
                            <div class="config-form-note mt-1">Required for offers that run in a date window. Optional only for always-on offers you will end manually later.</div>
                        </div>
                        <div class="col-12" id="bonusActiveDaysField">
                            <label class="form-label fw-bold">Active Days</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach (($bonusOfferDefinitions['weekdays'] ?? []) as $weekdayValue => $weekdayLabel): ?>
                                    <label class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="checkbox" name="active_weekdays[]" value="<?= htmlspecialchars($weekdayValue) ?>" <?= in_array((string)$weekdayValue, $editingWeekdays, true) ? 'checked' : '' ?>>
                                        <span class="form-check-label"><?= htmlspecialchars($weekdayLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="config-form-note mt-2">Only used for recurring date-based promos. Leave all days unchecked if the offer should run every day in its date window.</div>
                        </div>
                        <div class="col-12" id="bonusAudienceIdsField">
                            <label class="form-label fw-bold">Specific User Or Package IDs</label>
                            <input type="text" class="form-control" name="audience_ids" value="<?= htmlspecialchars($editingAudienceIds) ?>" placeholder="Example: 5, 12, 19">
                            <div class="config-form-note mt-2">Only used when the audience is <strong>Selected users</strong> or <strong>Selected packages</strong>.</div>
                        </div>
                        <div class="col-12 pt-2">
                            <h6 class="fw-bold mb-1">5. Safety and visibility</h6>
                            <div class="config-form-note">These settings control where the offer is shown and how strict the qualifying checks should be.</div>
                        </div>
                        <div class="col-12" id="bonusClearedOnlyField">
                            <div class="row g-3">
                                <div class="col-lg-3">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="public_visibility" value="1" <?= !isset($bonusEditingOffer['public_visibility']) || (int)$bonusEditingOffer['public_visibility'] === 1 ? 'checked' : '' ?>>
                                        <span class="form-check-label fw-bold">Show On Promotions Page And Nav</span>
                                    </label>
                                </div>
                                <div class="col-lg-3">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="fraud_hold" value="1" <?= !isset($bonusEditingOffer['fraud_hold']) || (int)$bonusEditingOffer['fraud_hold'] === 1 ? 'checked' : '' ?>>
                                        <span class="form-check-label fw-bold">Hold If Related Activity Is Under Fraud Review</span>
                                    </label>
                                </div>
                                <div class="col-lg-3">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="count_cleared_only" value="1" <?= !isset($bonusEditingOffer['count_cleared_only']) || (int)$bonusEditingOffer['count_cleared_only'] === 1 ? 'checked' : '' ?>>
                                        <span class="form-check-label fw-bold">Only Count Cleared Rewards And Referrals</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <h6 class="fw-bold mt-2 mb-2">6. User alerts</h6>
                            <div class="row g-3">
                                <div class="col-lg-3">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="notify_on_start" value="1" <?= !isset($bonusEditingOffer['notify_on_start']) || (int)$bonusEditingOffer['notify_on_start'] === 1 ? 'checked' : '' ?>>
                                        <span class="form-check-label">Send in-app alert when the offer starts</span>
                                    </label>
                                </div>
                                <div class="col-lg-3">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="email_on_start" value="1" <?= !isset($bonusEditingOffer['email_on_start']) || (int)$bonusEditingOffer['email_on_start'] === 1 ? 'checked' : '' ?>>
                                        <span class="form-check-label">Send email when the offer starts</span>
                                    </label>
                                </div>
                                <div class="col-lg-3">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="notify_on_earned" value="1" <?= !isset($bonusEditingOffer['notify_on_earned']) || (int)$bonusEditingOffer['notify_on_earned'] === 1 ? 'checked' : '' ?>>
                                        <span class="form-check-label">Send in-app alert when a user earns it</span>
                                    </label>
                                </div>
                                <div class="col-lg-3">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="email_on_earned" value="1" <?= !isset($bonusEditingOffer['email_on_earned']) || (int)$bonusEditingOffer['email_on_earned'] === 1 ? 'checked' : '' ?>>
                                        <span class="form-check-label">Send email when a user earns it</span>
                                    </label>
                                </div>
                            </div>
                            <div class="config-form-note mt-2">Email subjects and message bodies are managed in the Email Templates area with the bonus offer templates.</div>
                        </div>
                    </div>

                    <div class="config-sticky-save mt-4">
                        <p class="config-sticky-save__text">By default, earned bonuses wait for admin approval before they reach the user&apos;s withdrawable balance. Switch to auto-credit only for lower-risk offers.</p>
                        <button type="submit" class="btn btn-primary px-5"><?= !empty($bonusEditingOffer['id']) ? 'Save Bonus Offer' : 'Create Bonus Offer' ?></button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm mb-4 config-section-card">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Current Bonus Offers</h6>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Offer</th>
                                <th>Goal</th>
                                <th>Reward</th>
                                <th>Eligible Users</th>
                                <th>Status</th>
                                <th>Runs</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bonusOffers)): ?>
                                <tr><td colspan="7" class="text-muted py-4 text-center">No bonus offers yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($bonusOffers as $offer): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars((string)$offer['public_title']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars((string)$offer['name']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars((string)($bonusOfferDefinitions['metrics'][$offer['metric_key']] ?? $offer['metric_key'])) ?><div class="small text-muted"><?= htmlspecialchars((string)$offer['threshold_value']) ?> <?= htmlspecialchars((string)$offer['threshold_unit']) ?></div></td>
                                        <td><?= htmlspecialchars(\App\Service\BonusOfferService::formatRewardPreview($offer, [])) ?></td>
                                        <td><?= htmlspecialchars((string)($bonusOfferDefinitions['audienceTypes'][$offer['audience_type']] ?? $offer['audience_type'])) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string)($bonusStatusOptions[(string)$offer['status']] ?? ucwords(str_replace('_', ' ', (string)$offer['status'])))) ?></span></td>
                                        <td class="small text-muted"><?= htmlspecialchars(\App\Service\BonusOfferService::formatOfferSchedule($offer)) ?></td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="/admin/configuration?tab=monetization&monetization_pane=bonus-offers&edit_bonus_offer=<?= (int)$offer['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <form method="POST" action="/admin/configuration/save" data-confirm-message="Archive this offer if it has bonus history, or delete it permanently if it has never been used?" data-confirm-label="Remove Offer">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <input type="hidden" name="section" value="monetization">
                                                    <input type="hidden" name="monetization_action" value="delete_bonus_offer">
                                                    <input type="hidden" name="monetization_return" value="bonus-offers">
                                                    <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove Offer</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-7">
                <div class="card border-0 shadow-sm config-section-card h-100">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Bonuses Waiting For Approval</h6>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Offer</th>
                                        <th>Amount</th>
                                        <th>Progress</th>
                                        <th>Earned</th>
                                        <th>Decision</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($bonusPendingAwards)): ?>
                                        <tr><td colspan="6" class="text-muted py-4 text-center">No bonus awards are waiting for review.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($bonusPendingAwards as $award): ?>
                                            <tr>
                                                <td><?= htmlspecialchars(!empty($award['username']) ? \App\Service\EncryptionService::decrypt((string)$award['username']) : ('Deleted user #' . (int)$award['user_id'])) ?></td>
                                                <td><?= htmlspecialchars((string)$award['public_title']) ?></td>
                                                <td>$<?= number_format((float)$award['amount'], 2) ?></td>
                                                <td class="small text-muted"><?= number_format((float)$award['progress_value'], 2) ?> / <?= number_format((float)$award['threshold_value'], 2) ?></td>
                                                <td><?= htmlspecialchars(date('M d, Y H:i', strtotime((string)$award['earned_at']))) ?></td>
                                                <td>
                                                    <?php if ($canReviewBonusAwards): ?>
                                                        <form method="POST" action="/admin/configuration/save" class="d-grid gap-2">
                                                            <?= \App\Core\Csrf::field() ?>
                                                            <input type="hidden" name="section" value="monetization">
                                                            <input type="hidden" name="monetization_return" value="bonus-offers">
                                                            <input type="hidden" name="award_id" value="<?= (int)$award['id'] ?>">
                                                            <textarea name="review_note" rows="2" class="form-control form-control-sm" placeholder="Optional note"></textarea>
                                                            <div class="d-flex gap-2">
                                                                <button type="submit" name="monetization_action" value="approve_bonus_award" class="btn btn-sm btn-primary">Approve Bonus</button>
                                                                <button type="submit" name="monetization_action" value="reject_bonus_award" class="btn btn-sm btn-outline-danger">Reject Bonus</button>
                                                            </div>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Requires bonus-award review permission.</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card border-0 shadow-sm config-section-card h-100">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Recently Approved Or Rejected Bonuses</h6>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Offer</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($bonusRecentAwards)): ?>
                                        <tr><td colspan="4" class="text-muted py-4 text-center">No recent bonus decisions yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($bonusRecentAwards as $award): ?>
                                            <tr>
                                        <td><?= htmlspecialchars(!empty($award['username']) ? \App\Service\EncryptionService::decrypt((string)$award['username']) : ('Deleted user #' . (int)$award['user_id'])) ?></td>
                                                <td><?= htmlspecialchars((string)$award['public_title']) ?></td>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string)($bonusAwardStatusLabels[(string)$award['status']] ?? ucwords(str_replace('_', ' ', (string)$award['status'])))) ?></span></td>
                                                <td>$<?= number_format((float)$award['amount'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$bonusReviewOnly): ?>
    <!-- Ad Placements -->
    <div class="tab-pane fade <?= $activeMonetizationPane === 'ads' ? 'show active' : '' ?>" id="ads-content">
        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="monetization">
            <input type="hidden" name="monetization_action" value="ads">

            <div class="config-soft-callout config-section-card">
                These ad fields intentionally accept raw HTML and JavaScript ad tags. Only paste code you trust. The script now redacts these blocks from the admin activity log, and oversized ad blocks are rejected.
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Download Page: Top Banner</label>
                <textarea class="form-control font-monospace" name="ads[download_top]" rows="4"><?= htmlspecialchars($adTop ?? '') ?></textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Sidebar: Left</label>
                    <textarea class="form-control font-monospace" name="ads[download_left]" rows="4"><?= htmlspecialchars($adLeft ?? '') ?></textarea>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Sidebar: Right</label>
                    <textarea class="form-control font-monospace" name="ads[download_right]" rows="4"><?= htmlspecialchars($adRight ?? '') ?></textarea>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Download Page: Bottom</label>
                <textarea class="form-control font-monospace" name="ads[download_bottom]" rows="4"><?= htmlspecialchars($adBottom ?? '') ?></textarea>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Interstitial / Overlay Ad</label>
                <div class="config-form-note extra-small mb-2">Typically used for full-page pop-unders or modal dialogs that appear before the download begins.</div>
                <textarea class="form-control font-monospace" name="ads[download_overlay]" rows="4"><?= htmlspecialchars($adOverlay ?? '') ?></textarea>
            </div>

            <div class="config-sticky-save">
                <p class="config-sticky-save__text">Ad placement code runs on the public download surface, so keep it trusted, intentional, and as small as possible.</p>
                <button type="submit" class="btn btn-primary px-5">Save Ad Placements</button>
            </div>
        </form>
    </div>

    <!-- PPD Tiers -->
    <div class="tab-pane fade <?= $activeMonetizationPane === 'tiers' ? 'show active' : '' ?>" id="tiers-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h6 class="fw-bold mb-0">Geographic Reward Rates</h6>
            <div class="d-flex gap-2">
                <?php if (empty($tiers)): ?>
                    <form method="POST" action="/admin/configuration/save" class="m-0">
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="section" value="monetization">
                        <input type="hidden" name="monetization_action" value="load_example_tiers">
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-magic me-1"></i> Load Starter Tiers
                        </button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addTierModal">
                    <i class="bi bi-plus-circle me-1"></i> Add New Tier
                </button>
            </div>
        </div>

        <div class="config-soft-callout mb-4 config-section-card">
            Set your PPD payout rates here by country group. Higher-value countries can sit in a higher tier, lower-value countries in a lower tier, and an empty-country tier can be used as your catch-all fallback.
        </div>

        <?php if (!empty($exampleTiers)): ?>
            <div class="card border-0 shadow-sm mb-4 config-section-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h6 class="fw-bold mb-1">Starter Tier Examples</h6>
                            <div class="small text-muted">Use these as a starting structure, then replace them with your own countries and payout rates.</div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($exampleTiers as $tierExample): ?>
                            <div class="col-md-4">
                                <div class="ppd-example-tier">
                                    <div class="ppd-example-tier__name"><?= htmlspecialchars($tierExample['name']) ?></div>
                                    <div class="ppd-example-tier__countries"><?= htmlspecialchars($tierExample['countries']) ?></div>
                                    <div class="ppd-example-tier__rate">$<?= htmlspecialchars($tierExample['rate_per_1000']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="monetization">
            <input type="hidden" name="monetization_action" value="update_tiers">

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="bg-light extra-small fw-bold text-uppercase">
                        <tr>
                            <th>Tier Name</th>
                            <th>Countries (ISO)</th>
                            <th>Rate / 1000</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tiers as $tier): ?>
                            <tr>
                                <td>
                                    <input type="text" class="form-control form-control-sm fw-bold" name="tiers[<?= $tier['id'] ?>][name]" value="<?= htmlspecialchars($tier['name']) ?>" required>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" name="tiers[<?= $tier['id'] ?>][countries]" value="<?= htmlspecialchars($tier['countries'] ?? '') ?>" placeholder="US, GB, CA">
                                </td>
                                <td>
                                    <div class="monetization-tier-rate input-group input-group-sm">
                                        <span class="input-group-text">$</span>
                                        <input type="number" step="0.01" class="form-control" name="tiers[<?= $tier['id'] ?>][rate]" value="<?= $tier['rate_per_1000'] ?>" required>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-delete-tier-id="<?= (int)$tier['id'] ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
                    <div class="config-sticky-save">
                        <p class="config-sticky-save__text">Tier changes immediately affect the live PPD rate table used by uploader-facing rewards surfaces.</p>
                        <button type="submit" class="btn btn-primary px-5">Save Tier Changes</button>
                    </div>
        </form>
    </div>
    <?php endif; ?>
</div>
</div>
</div>

<?php if (!$bonusReviewOnly): ?>
<!-- Add Tier Modal -->
<div class="modal fade" id="addTierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="/admin/configuration/save">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="section" value="monetization">
                <input type="hidden" name="monetization_action" value="add_tier">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold">Add PPD Tier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tier Name</label>
                        <input type="text" name="new_name" class="form-control" required placeholder="e.g. Tier 1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Rate / 1000 ($)</label>
                        <input type="number" step="0.01" name="new_rate" class="form-control" required value="1.00">
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold">Country ISO Codes</label>
                        <input type="text" name="new_countries" class="form-control" placeholder="US, GB, DE">
                        <small class="text-muted">Comma separated. Empty = catch-all.</small>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Create Tier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteTierForm" method="POST" action="/admin/configuration/save" class="monetization-delete-tier-form">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="section" value="monetization">
    <input type="hidden" name="monetization_action" value="delete_tier">
    <input type="hidden" name="tier_id" id="deleteTierId">
</form>
<?php endif; ?>

<script>
async function deleteTier(id) {
    if (await window.adminConfirm('Delete this tier and all associated country mappings?', {
        title: 'Delete PPD Tier',
        confirmLabel: 'Delete Tier'
    })) {
        document.getElementById('deleteTierId').value = id;
        document.getElementById('deleteTierForm').submit();
    }
}

function syncBonusMetricOptions() {
    const offerKindSelect = document.getElementById('bonusOfferKindSelect');
    const metricSelect = document.getElementById('bonusMetricKeySelect');
    const metricHelp = document.getElementById('bonusMetricHelp');
    const rewardTypeSelect = document.getElementById('bonusRewardTypeSelect');
    const rewardTypeHelp = document.getElementById('bonusRewardTypeHelp');
    const audienceTypeSelect = document.getElementById('bonusAudienceTypeSelect');
    const audienceHelp = document.getElementById('bonusAudienceHelp');
    const audienceIdsField = document.getElementById('bonusAudienceIdsField');
    const scheduleModeSelect = document.getElementById('bonusScheduleModeSelect');
    const scheduleHelp = document.getElementById('bonusScheduleHelp');
    const activeDaysField = document.getElementById('bonusActiveDaysField');
    const clearedOnlyField = document.getElementById('bonusClearedOnlyField');

    if (!offerKindSelect || !metricSelect || !metricHelp) {
        return;
    }

    const selectedKind = offerKindSelect.value;
    const options = Array.from(metricSelect.options);
    let currentStillValid = false;
    let firstAllowedValue = '';

    options.forEach((option) => {
        const allowedKinds = (option.dataset.allowedKinds || '').split(',').filter(Boolean);
        const isAllowed = allowedKinds.includes(selectedKind);
        option.hidden = !isAllowed;
        option.disabled = !isAllowed;

        if (isAllowed && firstAllowedValue === '') {
            firstAllowedValue = option.value;
        }

        if (isAllowed && option.value === metricSelect.value) {
            currentStillValid = true;
        }
    });

    if (!currentStillValid && firstAllowedValue !== '') {
        metricSelect.value = firstAllowedValue;
    }

    const selectedOption = metricSelect.selectedOptions[0];
    let helpText = selectedOption ? (selectedOption.dataset.description || '') : '';

    if (selectedKind === 'referral') {
        helpText += (helpText ? ' ' : '') + 'Use verified referrals for referred users who confirmed their email. Use premium referrals for referred users who are currently on a paid package.';
    }

    metricHelp.textContent = helpText || 'Pick the activity you want to reward, like uploads, rewarded downloads, payouts, or referrals.';

    if (rewardTypeSelect && rewardTypeHelp) {
        const rewardOptions = Array.from(rewardTypeSelect.options);
        let currentRewardStillValid = false;
        let firstAllowedReward = '';

        rewardOptions.forEach((option) => {
            const allowedMetrics = (option.dataset.allowedMetrics || '').split(',').filter(Boolean);
            const isAllowed = allowedMetrics.includes(metricSelect.value);
            option.hidden = !isAllowed;
            option.disabled = !isAllowed;

            if (isAllowed && firstAllowedReward === '') {
                firstAllowedReward = option.value;
            }

            if (isAllowed && option.value === rewardTypeSelect.value) {
                currentRewardStillValid = true;
            }
        });

        if (!currentRewardStillValid && firstAllowedReward !== '') {
            rewardTypeSelect.value = firstAllowedReward;
        }

        rewardTypeHelp.textContent = ['rewarded_downloads', 'cleared_earnings_amount'].includes(metricSelect.value)
            ? 'Fixed works everywhere. Multiplier and percent also work here because this goal is based on earnings.'
            : 'This goal supports a fixed cash bonus only.';
    }

    if (audienceTypeSelect && audienceHelp && audienceIdsField) {
        const needsIds = ['selected_packages', 'selected_users'].includes(audienceTypeSelect.value);
        audienceIdsField.style.display = needsIds ? '' : 'none';

        if (selectedKind === 'referral') {
            audienceHelp.textContent = 'Referral bonuses usually make the most sense for everyone using rewards, or for a selected package or user group.';
        } else if (audienceTypeSelect.value === 'all_affiliates') {
            audienceHelp.textContent = 'This is a legacy audience option. It behaves like rewards users while affiliate mode is enabled, not as a separate member list.';
        } else {
            audienceHelp.textContent = 'Pick everyone using rewards, only premium users, or a specific package or user list.';
        }
    }

    if (scheduleModeSelect && scheduleHelp && activeDaysField) {
        const usesActiveDays = scheduleModeSelect.value === 'date_range_weekdays';
        activeDaysField.style.display = usesActiveDays ? '' : 'none';

        scheduleHelp.textContent = usesActiveDays
            ? 'This offer runs inside the selected date range, but only on the days you check below.'
            : 'Use a date range for one-time campaigns, or leave it continuous for always-on offers.';
    }

    if (clearedOnlyField) {
        const usesClearedOnly = ['rewarded_downloads', 'cleared_earnings_amount'].includes(metricSelect.value);
        clearedOnlyField.style.display = usesClearedOnly ? '' : 'none';
    }
}

document.addEventListener('click', function(event) {
    const deleteButton = event.target.closest('[data-delete-tier-id]');
    if (!deleteButton) {
        return;
    }

    const tierId = deleteButton.getAttribute('data-delete-tier-id');
    if (tierId) {
        deleteTier(tierId);
    }
});

document.addEventListener('change', function(event) {
    if (event.target && (
        event.target.id === 'bonusOfferKindSelect' ||
        event.target.id === 'bonusMetricKeySelect' ||
        event.target.id === 'bonusAudienceTypeSelect' ||
        event.target.id === 'bonusScheduleModeSelect'
    )) {
        syncBonusMetricOptions();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    syncBonusMetricOptions();
});
</script>

<style>
.ppd-example-tier {
    background: linear-gradient(180deg, #102334 0%, #101a2d 100%);
    border: 1px solid rgba(37, 99, 235, 0.18);
    border-radius: 18px;
    padding: 1.2rem 1.1rem;
    min-height: 100%;
    color: #e2e8f0;
}
.ppd-example-tier__name {
    color: #0ea5e9;
    font-weight: 800;
    font-size: 1.2rem;
    margin-bottom: 0.45rem;
}
.ppd-example-tier__countries {
    color: rgba(226, 232, 240, 0.8);
    font-size: 0.92rem;
    line-height: 1.5;
    margin-bottom: 1.25rem;
}
.ppd-example-tier__rate {
    color: #f8fafc;
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}
.monetization-retention-input { max-width: 220px; }
.monetization-tier-rate { width: 120px; }
.monetization-delete-tier-form { display: none; }
.nav#monetizationTabs .nav-link {
    width: 100%;
}
</style>
