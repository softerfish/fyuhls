<?php
include dirname(__DIR__) . '/header.php';
include dirname(__DIR__) . '/partials/shell_helpers.php';

$allPackages = is_array($allPackages ?? null) ? $allPackages : [];
$userCounts = is_array($userCounts ?? null) ? $userCounts : [];

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
    <form method="POST" action="/admin/package/clone/<?= (int)$package['id'] ?>" class="d-inline" data-confirm-message="Clone this package into a new plan?">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn-sm btn-outline-dark shadow-sm">Clone Package</button>
    </form>
</div>
<?php
$actions = ob_get_clean();
renderAdminPageHeader('Edit Package: ' . (string)$package['name'], 'Tune user-facing plan behavior without digging through a single long form. Limits, rewards, and experience settings are grouped below.', $actions);
?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Plan Type', strtoupper((string)$package['level_type'])); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Price', ((float)($package['price'] ?? 0)) > 0 ? '$' . number_format((float)$package['price'], 2) : 'Free'); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Assigned Users', (string)$assignedUsers); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Rewards Mode', htmlspecialchars($rewardSummaryText)); ?>
    </div>
</div>

<?php if ($assignedUsers > 0): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4">
        <strong>Live impact:</strong> <?= $assignedUsers ?> user<?= $assignedUsers === 1 ? '' : 's' ?> currently rely on this package. Changes to limits, rewards, or wait rules affect their experience immediately.
    </div>
<?php endif; ?>

<form method="POST" id="packageEditForm">
    <?= \App\Core\Csrf::field() ?>
    <div class="row g-4">
        <div class="col-xl-3">
            <?php renderAdminCardStart('Package Sections', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div class="list-group list-group-flush package-edit-nav">
                    <a href="#package-overview" class="list-group-item list-group-item-action">Overview</a>
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
                                <div class="small text-muted"><?= strtoupper((string)$comparison['level_type']) ?> | <?= ((float)($comparison['price'] ?? 0)) > 0 ? '$' . number_format((float)$comparison['price'], 2) : 'Free' ?></div>
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
                        <label class="form-label fw-semibold">Package Price (USD)</label>
                        <input type="number" class="form-control" step="0.01" min="0" name="price" value="<?= htmlspecialchars((string)($package['price'] ?? '0.00')) ?>">
                        <div class="form-text">Set to <code>0</code> for plans that should not charge users directly.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Plan Audience</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(strtoupper((string)$package['level_type'])) ?>" disabled>
                        <div class="form-text">This built-in package type is fixed so registrations, upgrades, and role flows remain predictable.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Accepted File Types</label>
                        <input type="text" class="form-control" name="accepted_file_types" value="<?= htmlspecialchars((string)($package['accepted_file_types'] ?? '')) ?>" placeholder="Leave blank to use the global defaults">
                        <div class="form-text">Optional override. Use a comma-separated list if this plan needs stricter file-type rules than the global uploader settings.</div>
                    </div>
                </div>
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
                    Editing a live plan updates user entitlements right away. Clone a plan first if you want to test a new tier safely before switching users or checkout flows over to it.
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="/admin/packages" class="btn btn-outline-secondary">Back to Packages</a>
                    <button type="submit" class="btn btn-primary">Save Package Changes</button>
                </div>
            </div>
        </div>

        <div class="col-xl-3">
            <?php renderAdminCardStart('Customer Preview', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div id="package-preview"></div>
                <div class="package-preview-card border rounded p-3">
                    <div class="small text-uppercase text-muted mb-2"><?= htmlspecialchars(strtoupper((string)$package['level_type'])) ?></div>
                    <h5 class="mb-2"><?= htmlspecialchars((string)$package['name']) ?></h5>
                    <div class="fs-4 fw-bold mb-3"><?= ((float)($package['price'] ?? 0)) > 0 ? '$' . number_format((float)$package['price'], 2) : 'Free' ?></div>
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
                    <li>Clone a plan when you need a new tier instead of repurposing a live package that already has users on it.</li>
                    <li>Concurrent download limits above zero automatically turn on active-download tracking in Config Hub.</li>
                </ul>
            <?php renderAdminCardEnd(); ?>
        </div>
    </div>
</form>

<style>
.package-edit-nav .list-group-item{padding:.72rem .82rem}
.package-savebar{z-index:10;bottom:1rem}
.package-edit-actions .btn{white-space:nowrap}
@media (max-width: 991.98px){
    .package-savebar{position:static!important}
    .package-savebar .btn{flex:1 1 100%}
}
</style>

<?php include dirname(__DIR__) . '/footer.php'; ?>
