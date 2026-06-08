<?php
use App\Service\PaymentService;

include dirname(__DIR__) . '/header.php';
include dirname(__DIR__) . '/partials/shell_helpers.php';

$allPackages = is_array($allPackages ?? null) ? $allPackages : [];
$userCounts = is_array($userCounts ?? null) ? $userCounts : [];
$isNewPackage = !empty($isNewPackage);
$canEditPackage = array_key_exists('canEditPackage', get_defined_vars()) ? !empty($canEditPackage) : true;
$packageEditBlockedMessage = (string)($packageEditBlockedMessage ?? '');
$error = (string)($error ?? '');
$success = (string)($success ?? '');

if (!function_exists('packageFormatBytes')) {
    function packageFormatBytes(int $bytes, string $unit = 'auto'): string
    {
        if ($bytes <= 0) {
            return 'Unlimited';
        }
        if ($unit === 'mb') {
            return round($bytes / 1024 / 1024, 0) . ' MB';
        }
        if ($unit === 'gb') {
            return round($bytes / 1024 / 1024 / 1024, 1) . ' GB';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float)$bytes;
        $index = 0;
        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }
        return round($size, $index >= 3 ? 1 : 0) . ' ' . $units[$index];
    }
}

if (!function_exists('packageFriendlyTermLabel')) {
    function packageFriendlyTermLabel(int $days): string
    {
        return PaymentService::formatTermLabel($days);
    }
}

$isPaidPackage = (string)($package['level_type'] ?? '') === 'paid';
$packageBillingOptions = is_array($package['billing_options'] ?? null) ? $package['billing_options'] : [];
if ($isPaidPackage && $packageBillingOptions === []) {
    $packageBillingOptions = [[
        'id' => 0,
        'option_label' => PaymentService::formatTermLabel((int)($package['subscription_term_days'] ?? 30)),
        'price' => (float)($package['price'] ?? 9.99),
        'term_days' => (int)($package['subscription_term_days'] ?? 30),
        'renewal_enabled' => !empty($package['renewal_enabled']) ? 1 : 0,
        'is_active' => 1,
    ]];
}
$billingEditorRows = $packageBillingOptions;
if ($billingEditorRows === []) {
    $billingEditorRows = [[
        'id' => 0,
        'option_label' => PaymentService::formatTermLabel((int)($package['subscription_term_days'] ?? 30)),
        'price' => 9.99,
        'term_days' => (int)($package['subscription_term_days'] ?? 30),
        'renewal_enabled' => !empty($package['renewal_enabled']) ? 1 : 0,
        'is_active' => 1,
    ]];
}

$assignedUsers = (int)($userCounts[(int)$package['id']] ?? 0);
$rewardSummary = [];
if (!empty($package['ppd_enabled'])) {
    $rewardSummary[] = 'PPD';
}
if (!empty($package['pps_enabled'])) {
    $rewardSummary[] = 'PPS';
}
$rewardSummaryText = $rewardSummary ? implode(' + ', $rewardSummary) : 'Off';
$comparisonPackages = array_values(array_filter($allPackages, static fn ($row) => (int)$row['id'] !== (int)$package['id']));

ob_start();
?>
<div class="d-flex flex-wrap gap-2 align-items-center package-edit-actions">
    <a href="/admin/packages" class="btn btn-sm btn-outline-secondary shadow-sm">&larr; Back to Packages</a>
    <?php if (!$isNewPackage): ?>
        <?php if (!in_array((string)($package['level_type'] ?? ''), ['guest', 'admin'], true)): ?>
            <form method="POST" action="/admin/package/clone/<?= (int)$package['id'] ?>" class="d-inline" data-confirm-message="Clone this package into a new plan?">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-sm btn-outline-dark shadow-sm">Clone Package</button>
            </form>
            <form method="POST" action="/admin/package/delete/<?= (int)$package['id'] ?>" class="d-inline" data-confirm-message="Delete this package? This only works when nothing in the system still depends on it.">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-sm btn-outline-danger shadow-sm">Delete Package</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$actions = ob_get_clean();
renderAdminPageHeader($isNewPackage ? 'Create Package' : ('Edit Package: ' . (string)$package['name']), $isNewPackage ? 'Create a new user-facing plan from scratch, then fine-tune it before assigning users or exposing it in checkout.' : 'Tune user-facing plan behavior without digging through a single long form. Limits, rewards, and experience settings are grouped below.', $actions);
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm mb-4"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Plan Type', strtoupper((string)$package['level_type'])); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php
        $activeBillingOptions = array_values(array_filter($packageBillingOptions, static fn(array $option): bool => !empty($option['is_active'])));
        $defaultBillingOption = $activeBillingOptions[0] ?? $packageBillingOptions[0] ?? null;
        $packagePriceLabel = ($isPaidPackage && $defaultBillingOption !== null && (float)($defaultBillingOption['price'] ?? 0) > 0)
            ? ('$' . number_format((float)$defaultBillingOption['price'], 2) . ' / ' . packageFriendlyTermLabel((int)($defaultBillingOption['term_days'] ?? 30)))
            : 'Free';
        renderAdminStatCard('Price', $packagePriceLabel);
        ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Assigned Users', (string)$assignedUsers); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Rewards Mode', htmlspecialchars($rewardSummaryText)); ?>
    </div>
</div>

<?php if (!$isNewPackage && $assignedUsers > 0): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4">
        <strong>Live impact:</strong> <?= $assignedUsers ?> user<?= $assignedUsers === 1 ? '' : 's' ?> currently rely on this package. Changes to limits, rewards, or wait rules affect their experience immediately.
    </div>
<?php endif; ?>

<?php if (!$canEditPackage): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4">
        <strong>Protected system plan:</strong> <?= htmlspecialchars($packageEditBlockedMessage) ?>
    </div>
<?php endif; ?>

<form method="POST" id="packageEditForm">
    <?= \App\Core\Csrf::field() ?>
    <fieldset <?= !$canEditPackage ? 'disabled' : '' ?>>
    <div class="row g-4">
        <div class="col-xl-3">
            <?php renderAdminCardStart('Package Sections', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div class="list-group list-group-flush package-edit-nav">
                    <a href="#package-overview" class="list-group-item list-group-item-action">Overview</a>
                    <a href="#package-billing" class="list-group-item list-group-item-action">Billing Options</a>
                    <a href="#package-storage" class="list-group-item list-group-item-action">Storage & Uploads</a>
                    <a href="#package-downloads" class="list-group-item list-group-item-action">Downloads & Delivery</a>
                    <a href="#package-rewards" class="list-group-item list-group-item-action">Rewards & Payout</a>
                    <a href="#package-experience" class="list-group-item list-group-item-action">Ads & Restrictions</a>
                    <a href="#package-preview" class="list-group-item list-group-item-action">Customer Preview</a>
                </div>
            <?php renderAdminCardEnd(); ?>

            <?php renderAdminCardStart('Quick Comparison', ['cardClass' => 'card border-0 shadow-sm']); ?>
                <?php if (empty($comparisonPackages)): ?>
                    <p class="text-muted small mb-0">No other packages are available to compare yet.</p>
                <?php else: ?>
                    <div class="d-grid gap-2">
                        <?php foreach ($comparisonPackages as $comparison): ?>
                            <div class="border rounded p-2">
                                <div class="fw-semibold"><?= htmlspecialchars((string)$comparison['name']) ?></div>
                                <?php
                                $comparisonOptions = is_array($comparison['billing_options'] ?? null) ? $comparison['billing_options'] : [];
                                $comparisonActive = array_values(array_filter($comparisonOptions, static fn(array $option): bool => !empty($option['is_active'])));
                                $comparisonDefault = $comparisonActive[0] ?? $comparisonOptions[0] ?? null;
                                ?>
                                <div class="small text-muted">
                                    <?= strtoupper((string)$comparison['level_type']) ?> |
                                    <?= ($comparisonDefault !== null && (float)($comparisonDefault['price'] ?? 0) > 0)
                                        ? ('$' . number_format((float)$comparisonDefault['price'], 2) . ' / ' . packageFriendlyTermLabel((int)($comparisonDefault['term_days'] ?? 30)))
                                        : 'Free' ?>
                                </div>
                                <div class="small text-muted"><?= htmlspecialchars(packageFormatBytes((int)($comparison['max_storage_bytes'] ?? 0), 'gb')) ?> storage | <?= htmlspecialchars(packageFormatBytes((int)($comparison['max_upload_size'] ?? 0), 'mb')) ?> upload</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php renderAdminCardEnd(); ?>
        </div>

        <div class="col-xl-6">
            <?php renderAdminCardStart('Overview', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-overview"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Package Name</label>
                        <input type="text" class="form-control" name="name" value="<?= htmlspecialchars((string)$package['name']) ?>" maxlength="100" required>
                        <div class="form-text">This is the customer-facing name used across checkout, account, and package messaging.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Billing setup</label>
                        <div class="form-control bg-light d-flex align-items-center">Managed in the Billing Options section below</div>
                        <div class="form-text">One paid package can now offer multiple checkout choices like 1 month, 6 months, or 1 year.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Plan Audience</label>
                        <?php if ($isNewPackage): ?>
                            <select class="form-select" id="planAudienceSelect" name="level_type">
                                <option value="free" <?= (string)$package['level_type'] === 'free' ? 'selected' : '' ?>>FREE</option>
                                <option value="paid" <?= (string)$package['level_type'] === 'paid' ? 'selected' : '' ?>>PAID</option>
                            </select>
                            <div class="form-text">New packages can start as free or paid plans. System-only guest and admin plans stay protected.</div>
                        <?php else: ?>
                            <input type="text" class="form-control" value="<?= htmlspecialchars(strtoupper((string)$package['level_type'])) ?>" disabled>
                            <div class="form-text">This built-in package type is fixed so registrations, upgrades, and role flows remain predictable.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Accepted File Types</label>
                        <input type="text" class="form-control" name="accepted_file_types" value="<?= htmlspecialchars((string)($package['accepted_file_types'] ?? '')) ?>" placeholder="Leave blank to use the global defaults">
                        <div class="form-text">Optional override. Use a comma-separated list if this plan needs stricter file-type rules than the global uploader settings.</div>
                    </div>
                </div>
            <?php renderAdminCardEnd(); ?>
            <?php renderAdminCardStart('Billing Options', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-billing"></div>
                <div id="packageFreeBillingNotice" class="<?= $isPaidPackage ? 'd-none' : '' ?>">
                    <div class="alert alert-light border small mb-0">
                        <strong>Not used for free plans:</strong> Billing options only apply to paid packages. If you later convert this package into a paid plan, checkout pricing will be managed here.
                    </div>
                </div>
                <fieldset id="packagePaidBillingFieldset" <?= $isPaidPackage ? '' : 'disabled' ?> class="<?= $isPaidPackage ? '' : 'd-none' ?>">
                    <div class="form-text mb-3">These are the real checkout choices for this plan. Admins can use any day count they want. If auto-renew is enabled on a row, the term also needs to fit the recurring gateway limits. The first active row becomes the default option shown across the site.</div>
                    <div class="d-grid gap-3" id="billingOptionsList">
                        <?php foreach ($billingEditorRows as $index => $option): ?>
                            <div class="border rounded p-3 billing-option-row">
                                <input type="hidden" name="billing_option_id[]" value="<?= (int)($option['id'] ?? 0) ?>">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Label</label>
                                        <input type="text" class="form-control" name="billing_option_label[]" maxlength="100" value="<?= htmlspecialchars((string)($option['option_label'] ?? '')) ?>" placeholder="3 months, Annual, 45 days">
                                        <div class="form-text">Optional. Leave blank to auto-name it from the day count.</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Price (USD)</label>
                                        <input type="number" class="form-control" name="billing_option_price[]" min="0" step="0.01" value="<?= htmlspecialchars(number_format((float)($option['price'] ?? 0), 2, '.', '')) ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Term (days)</label>
                                        <input type="number" class="form-control billing-option-term-days" name="billing_option_term_days[]" min="1" value="<?= (int)($option['term_days'] ?? 30) ?>">
                                        <div class="form-text billing-option-term-preview">Shows as <?= htmlspecialchars(packageFriendlyTermLabel((int)($option['term_days'] ?? 30))) ?></div>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="button" class="btn btn-outline-danger w-100 billing-option-remove">Remove</button>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-check-label d-flex align-items-center gap-2">
                                            <input type="checkbox" class="form-check-input mt-0" name="billing_option_renewal_enabled[<?= $index ?>]" value="1" <?= !empty($option['renewal_enabled']) ? 'checked' : '' ?>>
                                            Allow auto-renew for this billing choice
                                        </label>
                                        <div class="form-text">Auto-renew rows must use gateway-friendly recurring lengths, like 45 days, 3 months, 6 months, or 1 year.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-check-label d-flex align-items-center gap-2">
                                            <input type="checkbox" class="form-check-input mt-0" name="billing_option_is_active[<?= $index ?>]" value="1" <?= !empty($option['is_active']) ? 'checked' : '' ?>>
                                            Show this billing choice in checkout
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3" id="packagePaidBillingActions">
                        <button type="button" class="btn btn-outline-secondary" id="addBillingOptionBtn">Add Billing Option</button>
                    </div>
                </fieldset>
            <?php renderAdminCardEnd(); ?>

            <?php renderAdminCardStart('Storage & Uploads', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-storage"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Max Storage (Bytes)</label>
                        <input type="number" class="form-control" min="0" name="max_storage_bytes" value="<?= (int)$package['max_storage_bytes'] ?>">
                        <div class="form-text">0 = unlimited. Current summary: <?= htmlspecialchars(packageFormatBytes((int)$package['max_storage_bytes'], 'gb')) ?>.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Max Upload Size (Bytes)</label>
                        <input type="number" class="form-control" min="0" name="max_upload_size" value="<?= (int)$package['max_upload_size'] ?>">
                        <div class="form-text">0 = unlimited. Current summary: <?= htmlspecialchars(packageFormatBytes((int)$package['max_upload_size'], 'mb')) ?>.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Concurrent Uploads</label>
                        <input type="number" class="form-control" min="1" name="concurrent_uploads" value="<?= (int)$package['concurrent_uploads'] ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold d-block">Remote Upload</label>
                        <label class="form-check-label d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input mt-0" name="allow_remote_upload" value="1" <?= ($package['allow_remote_upload'] ?? 0) ? 'checked' : '' ?>>
                            Allow remote URL upload
                        </label>
                        <div class="form-text">Useful for premium or admin-facing packages that should be able to ingest files from remote URLs.</div>
                    </div>
                </div>
            <?php renderAdminCardEnd(); ?>

            <?php renderAdminCardStart('Downloads & Delivery', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-downloads"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Daily Bandwidth Limit (Bytes)</label>
                        <input type="number" class="form-control" min="0" name="max_daily_downloads" value="<?= (int)$package['max_daily_downloads'] ?>">
                        <div class="form-text">0 = unlimited. Controls total data this user can download across a rolling day.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Download Speed (Bytes/sec)</label>
                        <input type="number" class="form-control" min="0" name="download_speed" value="<?= (int)$package['download_speed'] ?>">
                        <div class="form-text">0 = unlimited. Use this when a plan should throttle downloads.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold d-block">Countdown Timer</label>
                        <label class="form-check-label d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input mt-0" name="wait_time_enabled" value="1" <?= ($package['wait_time_enabled'] ?? 0) ? 'checked' : '' ?>>
                            Enable countdown before download
                        </label>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Countdown Duration (Seconds)</label>
                        <input type="number" class="form-control" min="0" name="wait_time" value="<?= (int)$package['wait_time'] ?>">
                        <div class="form-text">0 = instant access even when countdowns are enabled globally.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Concurrent Downloads</label>
                        <input type="number" class="form-control" min="0" name="concurrent_downloads" value="<?= htmlspecialchars((string)($package['concurrent_downloads'] ?? 1)) ?>">
                        <div class="form-text">Set above 0 to limit active tracked downloads. Saving a positive value also enables active-download tracking globally.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">File Expiration (Days Since Last Download)</label>
                        <input type="number" class="form-control" min="0" name="file_expiry_days" value="<?= (int)($package['file_expiry_days'] ?? 0) ?>">
                        <div class="form-text">0 = never expires. This is a retention policy, not a package subscription expiry.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-check-label d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input mt-0" name="allow_direct_links" value="1" <?= !empty($package['allow_direct_links']) ? 'checked' : '' ?>>
                            Allow direct hotlinking for this package
                        </label>
                        <div class="form-text">When off, files still work through the normal Fyuhls download page flow.</div>
                    </div>
                </div>
            <?php renderAdminCardEnd(); ?>

            <?php renderAdminCardStart('Rewards & Payout', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-rewards"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="alert alert-light border small mb-0">
                            <strong>How this works:</strong> These package switches only decide whether this plan can participate in rewards. Global reward rates and payout strategy are still controlled in Config Hub and the broader rewards settings.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-check-label d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input mt-0" name="ppd_enabled" value="1" <?= !empty($package['ppd_enabled']) ? 'checked' : '' ?>>
                            Enable Pay-Per-Download rewards
                        </label>
                        <div class="form-text">This only applies if rewards are enabled globally. The actual PPD payout rates are managed in the rewards configuration, not here.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-check-label d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input mt-0" name="pps_enabled" value="1" <?= !empty($package['pps_enabled']) ? 'checked' : '' ?>>
                            Enable Pay-Per-Sale rewards
                        </label>
                        <div class="form-text">Use this when the package should participate in affiliate-style sale sharing. Commission percentages are controlled in the broader rewards settings.</div>
                    </div>
                </div>
            <?php renderAdminCardEnd(); ?>

            <?php renderAdminCardStart('Ads & Restrictions', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-experience"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-check-label d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input mt-0" name="show_ads" value="1" <?= !empty($package['show_ads']) ? 'checked' : '' ?>>
                            Show advertising on download pages
                        </label>
                        <div class="form-text">Turn this off for premium-style experiences.</div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-light border small mb-0 h-100">
                            <strong>Managed elsewhere:</strong> AdBlock enforcement and VPN/proxy blocking are controlled through the broader monetization and security settings so package editing stays focused on plan behavior.
                        </div>
                    </div>
                </div>
                <?php \App\Core\PluginManager::doAction('admin_package_edit_options', $package); ?>
                <?php \App\Core\PluginManager::doAction('admin_package_edit_form_extra', $package); ?>
            <?php renderAdminCardEnd(); ?>

            <?php renderAdminCardStart('Extension Hooks & Extra Limits', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <p class="text-muted small">Plugin-provided package controls render here so advanced extensions stay separate from the core plan workflow.</p>
                <?php \App\Core\PluginManager::doAction('admin_package_edit_limits', $package); ?>
            <?php renderAdminCardEnd(); ?>

            <div class="position-sticky bottom-0 bg-white border rounded shadow-sm p-3 d-flex flex-wrap align-items-center justify-content-between gap-3 package-savebar">
                <div class="small text-muted">
                    <?= $isNewPackage
                        ? 'Create the package first, then review it on the edit screen before assigning users or exposing it in checkout.'
                        : 'Editing a live plan updates user entitlements right away. Clone a plan first if you want to test a new tier safely before switching users or checkout flows over to it.' ?>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="/admin/packages" class="btn btn-outline-secondary">Back to Packages</a>
                    <button type="submit" class="btn btn-primary"><?= $isNewPackage ? 'Create Package' : 'Save Package Changes' ?></button>
                </div>
            </div>
        </div>

        <div class="col-xl-3">
            <?php renderAdminCardStart('Customer Preview', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-preview"></div>
                <div class="package-preview-card border rounded p-3">
                    <div class="small text-uppercase text-muted mb-2"><?= htmlspecialchars(strtoupper((string)$package['level_type'])) ?></div>
                    <h5 class="mb-2"><?= htmlspecialchars((string)$package['name']) ?></h5>
                    <div class="fs-4 fw-bold mb-3"><?= ($isPaidPackage && $defaultBillingOption !== null && (float)($defaultBillingOption['price'] ?? 0) > 0) ? '$' . number_format((float)$defaultBillingOption['price'], 2) : 'Free' ?></div>
                    <?php if ($isPaidPackage): ?>
                        <div class="small text-muted mb-3">
                            <?= count($packageBillingOptions) > 1 ? 'Starts at' : 'Default term:' ?>
                            <?= htmlspecialchars(packageFriendlyTermLabel((int)($defaultBillingOption['term_days'] ?? 30))) ?>
                            <?php if (!empty($defaultBillingOption['renewal_enabled'])): ?>
                                | Auto-renew available
                            <?php else: ?>
                                | One-time only
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <ul class="small text-muted ps-3 mb-0">
                        <li><?= htmlspecialchars(packageFormatBytes((int)$package['max_storage_bytes'], 'gb')) ?> storage</li>
                        <li><?= htmlspecialchars(packageFormatBytes((int)$package['max_upload_size'], 'mb')) ?> max upload</li>
                        <li><?= htmlspecialchars(packageFormatBytes((int)$package['max_daily_downloads'])) ?> daily bandwidth</li>
                        <li><?= !empty($package['allow_remote_upload']) ? 'Remote upload enabled' : 'Browser uploads only' ?></li>
                        <li><?= !empty($package['allow_direct_links']) ? 'Direct links enabled' : 'Download page protection stays on' ?></li>
                        <li><?= !empty($package['show_ads']) ? 'Ads can appear on download pages' : 'Ad-free download pages' ?></li>
                        <li><?= htmlspecialchars($rewardSummaryText) ?> rewards</li>
                    </ul>
                </div>
            <?php renderAdminCardEnd(); ?>

            <?php renderAdminCardStart('Admin Notes', ['cardClass' => 'card border-0 shadow-sm']); ?>
                <ul class="small text-muted ps-3 mb-0">
                    <li>Use built-in package types as anchors for registration, upgrade, and role-driven behavior.</li>
                    <li>You can add as many billing options as you want for one paid plan. The day count is freeform.</li>
                    <li>Clone a plan when you need a new tier instead of repurposing a live package that already has users on it.</li>
                    <li>Concurrent download limits above zero automatically turn on active-download tracking in Config Hub.</li>
                </ul>
            <?php renderAdminCardEnd(); ?>
        </div>
    </div>
    </fieldset>
</form>

<style>
.package-edit-nav .list-group-item{padding:.72rem .82rem}
.package-savebar{z-index:10;bottom:1rem}
.package-edit-actions .btn{white-space:nowrap}
.billing-option-row{background:#fff}
.billing-option-term-preview{min-height:1.25rem}
@media (max-width: 991.98px){
    .package-savebar{position:static!important}
    .package-savebar .btn{flex:1 1 100%}
}
</style>

<script>
(() => {
    const list = document.getElementById('billingOptionsList');
    const addBtn = document.getElementById('addBillingOptionBtn');
    if (!list || !addBtn) {
        return;
    }
    const planAudienceSelect = document.getElementById('planAudienceSelect');
    const paidFieldset = document.getElementById('packagePaidBillingFieldset');
    const freeNotice = document.getElementById('packageFreeBillingNotice');
    const paidActions = document.getElementById('packagePaidBillingActions');

    const termLabel = (days) => {
        const value = Math.max(1, parseInt(days || '1', 10) || 1);
        if (value % 365 === 0) {
            const years = value / 365;
            return years === 1 ? '1 year' : `${years} years`;
        }
        if (value % 30 === 0) {
            const months = value / 30;
            return months === 1 ? '1 month' : `${months} months`;
        }
        if (value % 7 === 0) {
            const weeks = value / 7;
            return weeks === 1 ? '1 week' : `${weeks} weeks`;
        }
        return value === 1 ? '1 day' : `${value} days`;
    };

    const refreshRows = () => {
        const rows = Array.from(list.querySelectorAll('.billing-option-row'));
        rows.forEach((row, index) => {
            const renewalCheckbox = row.querySelector('[data-billing-renewal]');
            const activeCheckbox = row.querySelector('[data-billing-active]');
            const termInput = row.querySelector('.billing-option-term-days');
            const termPreview = row.querySelector('.billing-option-term-preview');
            const removeBtn = row.querySelector('.billing-option-remove');
            if (renewalCheckbox) {
                renewalCheckbox.name = `billing_option_renewal_enabled[${index}]`;
            }
            if (activeCheckbox) {
                activeCheckbox.name = `billing_option_is_active[${index}]`;
            }
            if (termInput && termPreview) {
                termPreview.textContent = `Shows as ${termLabel(termInput.value)}`;
            }
            if (removeBtn) {
                removeBtn.disabled = rows.length === 1;
            }
        });
    };

    const syncBillingSectionVisibility = () => {
        if (!(planAudienceSelect instanceof HTMLSelectElement) || !(paidFieldset instanceof HTMLFieldSetElement) || !(freeNotice instanceof HTMLElement)) {
            return;
        }

        const isPaid = planAudienceSelect.value === 'paid';
        paidFieldset.disabled = !isPaid;
        paidFieldset.classList.toggle('d-none', !isPaid);
        freeNotice.classList.toggle('d-none', isPaid);
        if (paidActions instanceof HTMLElement) {
            paidActions.classList.toggle('d-none', !isPaid);
        }
    };

    const buildRow = () => {
        const row = document.createElement('div');
        row.className = 'border rounded p-3 billing-option-row';
        row.innerHTML = `
            <input type="hidden" name="billing_option_id[]" value="0">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Label</label>
                    <input type="text" class="form-control" name="billing_option_label[]" maxlength="100" placeholder="3 months, Annual, 45 days">
                    <div class="form-text">Optional. Leave blank to auto-name it from the day count.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Price (USD)</label>
                    <input type="number" class="form-control" name="billing_option_price[]" min="0" step="0.01" value="0.00">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Term (days)</label>
                    <input type="number" class="form-control billing-option-term-days" name="billing_option_term_days[]" min="1" value="30">
                    <div class="form-text billing-option-term-preview">Shows as 1 month</div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-danger w-100 billing-option-remove">Remove</button>
                </div>
                <div class="col-md-6">
                    <label class="form-check-label d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input mt-0" data-billing-renewal value="1" checked>
                        Allow auto-renew for this billing choice
                    </label>
                </div>
                <div class="col-md-6">
                    <label class="form-check-label d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input mt-0" data-billing-active value="1" checked>
                        Show this billing choice in checkout
                    </label>
                </div>
            </div>
        `;
        return row;
    };

    list.querySelectorAll('input[name^="billing_option_renewal_enabled"]').forEach((el) => {
        el.setAttribute('data-billing-renewal', '1');
    });
    list.querySelectorAll('input[name^="billing_option_is_active"]').forEach((el) => {
        el.setAttribute('data-billing-active', '1');
    });

    addBtn.addEventListener('click', () => {
        list.appendChild(buildRow());
        refreshRows();
    });

    list.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement) || !target.classList.contains('billing-option-remove')) {
            return;
        }
        const rows = list.querySelectorAll('.billing-option-row');
        if (rows.length <= 1) {
            return;
        }
        target.closest('.billing-option-row')?.remove();
        refreshRows();
    });

    list.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement) || !target.classList.contains('billing-option-term-days')) {
            return;
        }
        refreshRows();
    });

    refreshRows();
    syncBillingSectionVisibility();
    if (planAudienceSelect instanceof HTMLSelectElement) {
        planAudienceSelect.addEventListener('change', syncBillingSectionVisibility);
    }
})();
</script>

<?php include dirname(__DIR__) . '/footer.php'; ?>
