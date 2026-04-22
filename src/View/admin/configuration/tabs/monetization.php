<?php
// Initialize fallback values to prevent warnings if not passed from controller
$adTop = $adTop ?? '';
$adLeft = $adLeft ?? '';
$adRight = $adRight ?? '';
$adBottom = $adBottom ?? '';
$adOverlay = $adOverlay ?? '';
$tiers = $tiers ?? [];
$exampleTiers = $exampleTiers ?? [];

$rewardsActive = true;
?>

<ul class="nav nav-pills mb-4 bg-light p-2 rounded" id="monetizationTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active fw-bold small" id="rewards-tab" data-bs-toggle="pill" data-bs-target="#rewards-content" type="button">
            <i class="bi bi-cash-coin me-2"></i> Rewards
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link fw-bold small" id="ads-tab" data-bs-toggle="pill" data-bs-target="#ads-content" type="button">
            <i class="bi bi-megaphone me-2"></i> Ad Placements
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link fw-bold small" id="tiers-tab" data-bs-toggle="pill" data-bs-target="#tiers-content" type="button">
            <i class="bi bi-globe me-2"></i> PPD Geographic Tiers
        </button>
    </li>
</ul>

<div class="tab-content" id="monetizationContent">
    <div class="tab-pane fade show active" id="rewards-content">
        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="monetization">
            <input type="hidden" name="monetization_action" value="rewards_settings">

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="rewards_enabled" id="rewardsEnabled" value="1" <?= !empty($rewardsEnabled) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="rewardsEnabled">Enable Rewards</label>
                        <div class="small text-muted mt-1">Turns on the built-in monetization system for pay-per-download, payouts, withdrawal requests, uploader earnings tracking, and the rewards fraud tools.</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="affiliate_enabled" id="affiliateEnabled" value="1" <?= !empty($affiliateEnabled) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="affiliateEnabled">Enable Affiliate Program</label>
                        <div class="small text-muted mt-1">Enables referral tracking so users can earn commission when visitors they refer buy packages or generate qualifying sales activity. Affiliate requires Rewards and will automatically turn off if Rewards is disabled.</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
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
                    <div class="small text-muted mt-1">Direct pay-per-sale commission paid to the uploader when a premium purchase is attributed through their download flow.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Referral % Rate</label>
                    <input type="number" class="form-control" name="referral_commission_percent" value="<?= htmlspecialchars($referralCommissionPercent ?? '50') ?>" min="0" max="100">
                    <div class="small text-muted mt-1">Affiliate referral commission paid to the referring user when someone signs up under their referral link and generates eligible earnings.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Affiliate Hold Days</label>
                    <input type="number" class="form-control" name="affiliate_hold_days" value="<?= htmlspecialchars(\App\Model\Setting::get('affiliate_hold_days', '5', 'rewards')) ?>" min="0" max="90">
                    <div class="small text-muted mt-1">How long affiliate commission stays held before it clears automatically. Use this to buffer refunds and chargebacks. Default: 5 days.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Mixed PPD Percentage (%)</label>
                    <input type="number" class="form-control" name="mixed_ppd_percent" value="<?= htmlspecialchars($mixedPpdPercent ?? '30') ?>">
                    <div class="small text-muted mt-1">How much of the standard PPD rate a Hybrid user receives for download earnings.</div>
                </div>
                <div class="col-md-6 mb-4">
                    <label class="form-label fw-bold">Mixed PPS Percentage (%)</label>
                    <input type="number" class="form-control" name="mixed_pps_percent" value="<?= htmlspecialchars($mixedPpsPercent ?? '30') ?>" min="0" max="100">
                    <div class="small text-muted mt-1">How much of the standard PPS commission a Hybrid user receives for premium sales attributed through their files.</div>
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
                <label class="form-label fw-bold">Supported Withdrawal Methods</label>
                <?php foreach (['paypal' => 'PayPal', 'stripe' => 'Stripe / Bank', 'bitcoin' => 'Bitcoin / Crypto', 'wire' => 'Bank Wire'] as $methodKey => $methodLabel): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="supported_withdrawal_methods[]" value="<?= $methodKey ?>" id="method_<?= $methodKey ?>" <?= in_array($methodKey, $supportedWithdrawalMethods ?? [], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="method_<?= $methodKey ?>"><?= $methodLabel ?></label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Rewards Retention (Days)</label>
                <input type="number" class="monetization-retention-input form-control" name="rewards_retention_days" value="<?= htmlspecialchars($retentionDays ?? '7') ?>" min="1" max="365">
            </div>

            <div class="alert alert-info">
                PPD rates are controlled from the <strong>PPD Geographic Tiers</strong> tab. Add country-based tiers there instead of using one flat global rate. If you want a rest-of-world fallback, create a tier with no countries assigned.
            </div>

            <div class="alert alert-warning">
                        PPD can count on accelerated delivery methods, but for ordinary file downloads the strongest threshold-based proof is App-Controlled PHP. Nginx can also honor <code>ppd_min_download_percent</code> through its completion log pipeline. Direct URLs, Apache X-SendFile, and LiteSpeed standard-file handoff remain start-based unless Fyuhls falls back to PHP.
            </div>

            <div class="alert alert-info">
                If you enable streaming support in the Downloads tab, video rewards can use watch-based validation. That streaming proof is separate from ordinary file-download payout verification. Use the video watch percent and seconds settings here to define the minimum playback needed before credit is considered.
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Payment Gateways</h6>
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="border rounded p-3 h-100">
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
                                    <div class="small text-muted mt-1">Used for Stripe webhook verification on <code>/payment/callback/stripe</code>. Stripe success redirects also work through the direct session confirmation route.</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="border rounded p-3 h-100">
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
                                <div class="small text-muted mt-2">PayPal uses server-side order creation and capture with a return URL back into the app. Switch Sandbox off only after your live credentials are ready.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary px-5">Save Rewards Settings</button>
        </form>
    </div>

    <!-- Ad Placements -->
    <div class="tab-pane fade" id="ads-content">
        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="monetization">
            <input type="hidden" name="monetization_action" value="ads">

            <div class="alert alert-warning">
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
                <div class="text-muted extra-small mb-2">Typically used for full-page pop-unders or modal dialogs that appear before the download begins.</div>
                <textarea class="form-control font-monospace" name="ads[download_overlay]" rows="4"><?= htmlspecialchars($adOverlay ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary px-5">Save Ad Placements</button>
        </form>
    </div>

    <!-- PPD Tiers -->
    <div class="tab-pane fade" id="tiers-content">
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

        <div class="alert alert-light border mb-4">
            Set your PPD payout rates here by country group. Higher-value countries can sit in a higher tier, lower-value countries in a lower tier, and an empty-country tier can be used as your catch-all fallback.
        </div>

        <?php if (!empty($exampleTiers)): ?>
            <div class="card border-0 shadow-sm mb-4">
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
            <button type="submit" class="btn btn-primary mt-4 px-5">Save Tier Changes</button>
        </form>
    </div>
</div>

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

<script>
function deleteTier(id) {
    if (confirm('Delete this tier and all associated country mappings?')) {
        document.getElementById('deleteTierId').value = id;
        document.getElementById('deleteTierForm').submit();
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
</style>
