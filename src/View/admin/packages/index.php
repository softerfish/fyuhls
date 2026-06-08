<?php
include dirname(__DIR__) . '/header.php';
include dirname(__DIR__) . '/partials/shell_helpers.php';

$userCounts = is_array($userCounts ?? null) ? $userCounts : [];

if (!function_exists('formatPackageBytes')) {
    function formatPackageBytes(int $bytes, string $unit = 'auto'): string
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

        $precision = $index >= 3 ? 1 : 0;
        return round($size, $precision) . ' ' . $units[$index];
    }
}

if (!function_exists('formatPackageTerm')) {
    function formatPackageTerm(int $days): string
    {
        return \App\Service\PaymentService::formatTermLabel($days);
    }
}

$totalPackages = count($packages);
$paidPackages = count(array_filter($packages, static fn ($pkg) => (string)($pkg['level_type'] ?? '') === 'paid'));
$totalAssignedUsers = array_sum($userCounts);
$plansWithAdsDisabled = count(array_filter($packages, static fn ($pkg) => empty($pkg['show_ads'])));
$viewerIsSuperAdmin = \App\Core\Auth::isSuperAdmin();

ob_start();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="text-muted small">Start from an existing plan, then clone and adjust it when you need a new tier.</div>
    <a href="/admin/package/create" class="btn btn-sm btn-primary shadow-sm">Create Package</a>
</div>
<?php
$pageActions = ob_get_clean();
renderAdminPageHeader('Packages', 'Control the user-facing plans that shape uploads, downloads, rewards, and checkout behavior.', $pageActions);
?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Managed Plans', (string)$totalPackages); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Paid Plans', (string)$paidPackages); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Assigned Users', (string)$totalAssignedUsers); ?>
    </div>
    <div class="col-md-3 col-sm-6">
        <?php renderAdminStatCard('Ad-Free Plans', (string)$plansWithAdsDisabled); ?>
    </div>
</div>

<?php renderAdminCardStart('Plan Comparison', ['cardClass' => 'card border-0 shadow-sm']); ?>
    <div class="table-responsive">
        <table class="table align-middle package-index-table mb-0">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Audience</th>
                    <th>Price</th>
                    <th>Storage</th>
                    <th>Upload Max</th>
                    <th>Daily Bandwidth</th>
                    <th>Delivery</th>
                    <th>Rewards</th>
                    <th>Experience</th>
                    <th>Users</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $pkg): ?>
                    <?php
                    $pkgId = (int)$pkg['id'];
                    $isPaid = (string)($pkg['level_type'] ?? '') === 'paid';
                    $waitSummary = !empty($pkg['wait_time_enabled']) ? ((int)($pkg['wait_time'] ?? 0) . 's wait') : 'Instant';
                    $rewardParts = [];
                    if (!empty($pkg['ppd_enabled'])) {
                        $rewardParts[] = 'PPD';
                    }
                    if (!empty($pkg['pps_enabled'])) {
                        $rewardParts[] = 'PPS';
                    }
                    $rewardSummary = $rewardParts ? implode(' + ', $rewardParts) : 'Off';
                    $experienceParts = [];
                    $experienceParts[] = !empty($pkg['show_ads']) ? 'Ads' : 'Ad-free';
                    $experienceParts[] = !empty($pkg['allow_remote_upload']) ? 'Remote upload' : 'Browser upload only';
                    $experienceParts[] = !empty($pkg['allow_direct_links']) ? 'Direct links' : 'Download page only';
                    $assignedUsers = (int)($userCounts[$pkgId] ?? 0);
                    $billingOptions = is_array($pkg['billing_options'] ?? null) ? $pkg['billing_options'] : [];
                    $activeBillingOptions = array_values(array_filter($billingOptions, static fn(array $option): bool => !empty($option['is_active'])));
                    $defaultBillingOption = $activeBillingOptions[0] ?? $billingOptions[0] ?? null;
                    $displayBillingOptions = $activeBillingOptions ?: $billingOptions;
                    $renewableOptionCount = count(array_filter($displayBillingOptions, static fn(array $option): bool => !empty($option['renewal_enabled'])));
                    $oneTimeOptionCount = count($displayBillingOptions) - $renewableOptionCount;
                    $billingSummary = 'Free';
                    if ($isPaid && $defaultBillingOption !== null) {
                        $billingSummary = '$' . number_format((float)($defaultBillingOption['price'] ?? 0), 2) . ' / ' . formatPackageTerm((int)($defaultBillingOption['term_days'] ?? 30));
                    }
                    $renewalSummary = $renewableOptionCount > 0 && $oneTimeOptionCount > 0
                        ? 'Mixed renewal options'
                        : (($defaultBillingOption !== null && !empty($defaultBillingOption['renewal_enabled'])) ? 'Auto-renew available' : 'One-time only');
                    $isSystemPackage = in_array((string)($pkg['level_type'] ?? ''), ['guest', 'admin'], true);
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars((string)$pkg['name']) ?></div>
                            <div class="small text-muted">ID #<?= $pkgId ?></div>
                        </td>
                        <td>
                            <span class="badge text-bg-light border text-uppercase"><?= htmlspecialchars((string)$pkg['level_type']) ?></span>
                        </td>
                        <td>
                            <?= htmlspecialchars($billingSummary) ?>
                            <?php if ($isPaid): ?>
                                <div class="small text-muted">
                                    <?= count($displayBillingOptions) ?> billing option<?= count($displayBillingOptions) === 1 ? '' : 's' ?>
                                    | <?= htmlspecialchars($renewalSummary) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(formatPackageBytes((int)($pkg['max_storage_bytes'] ?? 0), 'gb')) ?></td>
                        <td><?= htmlspecialchars(formatPackageBytes((int)($pkg['max_upload_size'] ?? 0), 'mb')) ?></td>
                        <td><?= htmlspecialchars(formatPackageBytes((int)($pkg['max_daily_downloads'] ?? 0))) ?></td>
                        <td>
                            <div><?= htmlspecialchars($waitSummary) ?></div>
                            <div class="small text-muted"><?= ((int)($pkg['concurrent_downloads'] ?? 1)) > 0 ? (int)$pkg['concurrent_downloads'] . ' concurrent' : 'Unlimited concurrent' ?></div>
                        </td>
                        <td><?= htmlspecialchars($rewardSummary) ?></td>
                        <td>
                            <div class="small"><?= htmlspecialchars(implode(' | ', $experienceParts)) ?></div>
                        </td>
                        <td>
                            <span class="fw-semibold"><?= $assignedUsers ?></span>
                        </td>
                        <td class="text-end">
                            <div class="d-flex flex-wrap justify-content-end gap-2">
                                <?php if (!$isSystemPackage || $viewerIsSuperAdmin): ?>
                                    <a href="/admin/package/edit/<?= $pkgId ?>" class="btn btn-sm btn-primary">Edit</a>
                                <?php else: ?>
                                    <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Protected</span>
                                <?php endif; ?>
                                <?php if (!$isSystemPackage): ?>
                                    <form method="POST" action="/admin/package/clone/<?= $pkgId ?>" class="d-inline" data-confirm-message="Clone this package into a new plan?">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Clone</button>
                                    </form>
                                    <form method="POST" action="/admin/package/delete/<?= $pkgId ?>" class="d-inline" data-confirm-message="Delete this package? This only works when nothing still depends on it.">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php renderAdminCardEnd(); ?>

<style>
.package-index-table td,.package-index-table th{vertical-align:middle}
</style>

<?php include dirname(__DIR__) . '/footer.php'; ?>
