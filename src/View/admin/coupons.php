<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';

$coupons = is_array($coupons ?? null) ? $coupons : [];
$stats = is_array($stats ?? null) ? $stats : ['active' => 0, 'scheduled' => 0, 'expired' => 0, 'redeemed' => 0];

$actions = '<a href="/admin/coupon/create" class="btn btn-primary">Create Coupon</a>';
renderAdminPageHeader(
    'Premium Coupons',
    'Create discount codes for premium checkout, control who they apply to, and see whether they are being used the way the campaign intended.',
    $actions
);

if (!function_exists('couponAdminStatus')) {
    function couponAdminStatus(array $coupon): array
    {
        $now = time();
        $isActive = (int)($coupon['is_active'] ?? 0) === 1;
        $startsAt = !empty($coupon['starts_at']) ? strtotime((string)$coupon['starts_at']) : null;
        $expiresAt = !empty($coupon['expires_at']) ? strtotime((string)$coupon['expires_at']) : null;

        if (!$isActive) {
            return ['Draft', 'bg-secondary-subtle text-secondary-emphasis'];
        }
        if ($startsAt && $startsAt > $now) {
            return ['Scheduled', 'bg-info-subtle text-info-emphasis'];
        }
        if ($expiresAt && $expiresAt < $now) {
            return ['Expired', 'bg-warning-subtle text-warning-emphasis'];
        }
        return ['Live', 'bg-success-subtle text-success-emphasis'];
    }
}
?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6"><?php renderAdminStatCard('Live Coupons', (string)(int)$stats['active']); ?></div>
    <div class="col-md-3 col-sm-6"><?php renderAdminStatCard('Scheduled', (string)(int)$stats['scheduled']); ?></div>
    <div class="col-md-3 col-sm-6"><?php renderAdminStatCard('Expired', (string)(int)$stats['expired']); ?></div>
    <div class="col-md-3 col-sm-6"><?php renderAdminStatCard('Redeemed Uses', (string)(int)$stats['redeemed']); ?></div>
</div>

<?php renderAdminCardStart('Coupon Rules At A Glance'); ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="coupon-note-box h-100">
                <h6>What a coupon changes</h6>
                <p>Coupons adjust premium checkout pricing only. They do not edit the package itself, user storage limits, or rewards settings.</p>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="coupon-note-box h-100">
                <h6>Where limits are enforced</h6>
                <p>Usage limits are reserved as checkout starts, so limited-run coupons cannot be overspent by two buyers racing the same code.</p>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="coupon-note-box h-100">
                <h6>How renewals work</h6>
                <p>Renewal-only coupons are checked again each time a buyer starts premium checkout. Multi-cycle offers claim their renewal window on first use, so buyers cannot restart subscriptions to stretch the same promotion farther.</p>
            </div>
        </div>
    </div>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('All Coupons'); ?>
    <?php if ($coupons === []): ?>
        <p class="text-muted mb-0">No coupons exist yet. Create one when you want to run a launch, retention, or win-back offer.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Coupon</th>
                        <th>Discount</th>
                        <th>Eligibility</th>
                        <th>Usage</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($coupons as $coupon): ?>
                        <?php [$statusLabel, $statusClass] = couponAdminStatus($coupon); ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string)$coupon['code']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars((string)$coupon['internal_label']) ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold">
                                    <?php if (($coupon['discount_type'] ?? '') === 'percent'): ?>
                                        <?= number_format((float)$coupon['discount_value'], 0) ?>% off
                                    <?php else: ?>
                                        $<?= number_format((float)$coupon['discount_value'], 2) ?> off
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted">
                                    <?php if (($coupon['discount_type'] ?? '') === 'percent' && ($coupon['percent_cap_amount'] ?? null) !== null): ?>
                                        capped at $<?= number_format((float)$coupon['percent_cap_amount'], 2) ?>
                                    <?php else: ?>
                                        no extra cap
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="small"><?= htmlspecialchars(str_replace('_', ' ', (string)$coupon['purchase_scope'])) ?></div>
                                <div class="small text-muted">
                                    <?= (int)($coupon['applies_to_all_paid'] ?? 0) === 1 ? 'All paid packages' : (count($coupon['eligible_package_ids'] ?? []) . ' selected packages') ?>
                                    <?php if (!empty($coupon['eligible_billing_option_ids'] ?? [])): ?>
                                        &middot; <?= count($coupon['eligible_billing_option_ids']) ?> billing option<?= count($coupon['eligible_billing_option_ids']) === 1 ? '' : 's' ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= (int)($coupon['redeemed_count'] ?? 0) ?> redeemed</div>
                                <div class="small text-muted"><?= (int)($coupon['reserved_count'] ?? 0) ?> waiting on payment</div>
                            </td>
                            <td><span class="badge rounded-pill <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                            <td class="text-end">
                                <a href="/admin/coupon/edit/<?= (int)$coupon['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<style>
.coupon-note-box{border:1px solid #dbe4f0;border-radius:14px;padding:1rem;background:#f8fbff}
.coupon-note-box h6{margin-bottom:.5rem}
.coupon-note-box p{margin-bottom:0;color:#64748b}
</style>

<?php include 'footer.php'; ?>
