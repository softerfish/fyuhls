<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';

$coupon = is_array($coupon ?? null) ? $coupon : [];
$isNewCoupon = !empty($isNewCoupon);
$paidPackages = is_array($paidPackages ?? null) ? $paidPackages : [];
$recentRedemptions = is_array($recentRedemptions ?? null) ? $recentRedemptions : [];

$couponCode = (string)($coupon['code'] ?? '');
$pageTitle = $isNewCoupon ? 'Create Coupon' : ('Edit Coupon: ' . $couponCode);
$pageSummary = $isNewCoupon
    ? 'Build a premium discount code with clear rules around who can use it, how much it saves, and how long it should keep working.'
    : 'Tune the live behavior of this premium coupon without guessing what each field affects. The sections below stay close to how checkout will actually use the code.';
$actions = '<a href="/admin/coupons" class="btn btn-outline-secondary btn-sm">&larr; Back to Coupons</a>';
renderAdminPageHeader($pageTitle, $pageSummary, $actions);

$selectedPackageIds = array_map('intval', $coupon['eligible_package_ids'] ?? []);
$selectedBillingOptionIds = array_map('intval', $coupon['eligible_billing_option_ids'] ?? []);

if (!function_exists('couponFieldDateTime')) {
    function couponFieldDateTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d\TH:i', $timestamp) : '';
    }
}
?>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <?php renderAdminCardStart('How To Use This Page', ['cardClass' => 'card border-0 shadow-sm h-100']); ?>
            <ol class="small text-muted mb-0 ps-3">
                <li class="mb-2"><strong class="text-dark">Start with the offer itself:</strong> name the coupon, decide whether it is live, and set the dates you want staff to honor it.</li>
                <li class="mb-2"><strong class="text-dark">Define the discount carefully:</strong> flat-dollar and percent discounts behave differently, especially when you want a cap.</li>
                <li class="mb-2"><strong class="text-dark">Be explicit about eligibility:</strong> choose whether the code is for new buyers, renewals, or both instead of relying on staff memory.</li>
                <li><strong class="text-dark">Use limits when the campaign matters:</strong> checkout reserves usage when payment starts, so caps are meaningful even under load.</li>
            </ol>
        <?php renderAdminCardEnd(); ?>
    </div>
    <div class="col-lg-8">
        <?php renderAdminCardStart('What Changes When You Save', ['cardClass' => 'card border-0 shadow-sm h-100']); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="coupon-note-box h-100">
                        <h6>Checkout behavior</h6>
                        <p>New rules apply the next time a buyer starts premium checkout. Existing completed subscriptions stay untouched, and checkout-rule changes now invalidate older pending reserved checkouts for this coupon.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="coupon-note-box h-100">
                        <h6>Usage limits</h6>
                        <p>Changing limits affects future uses and reserved checkouts. It does not rewrite already-redeemed history.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="coupon-note-box h-100">
                        <h6>Package targeting</h6>
                        <p>If you narrow package scope, the code stops working on packages outside the new list immediately.</p>
                    </div>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>

<form method="POST" class="row g-4">
    <?= \App\Core\Csrf::field() ?>
    <div class="col-xl-8">
        <?php renderAdminCardStart('Offer Basics', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Coupon code</label>
                    <input type="text" name="code" class="form-control" maxlength="64" value="<?= htmlspecialchars($couponCode) ?>" required>
                    <div class="form-text">Keep it short and memorable. Fyuhls matches coupon codes case-insensitively.</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Internal label</label>
                    <input type="text" name="internal_label" class="form-control" maxlength="150" value="<?= htmlspecialchars((string)($coupon['internal_label'] ?? '')) ?>" required>
                    <div class="form-text">Use something staff will instantly recognize, like “Summer win-back” or “Affiliate launch.”</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold d-block">Availability</label>
                    <label class="form-check-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input mt-0" <?= !empty($coupon['is_active']) ? 'checked' : '' ?>>
                        Coupon is live
                    </label>
                    <div class="form-text">Turn this off to pause the code without deleting its history.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Start date and time</label>
                    <input type="datetime-local" name="starts_at" class="form-control" value="<?= htmlspecialchars(couponFieldDateTime($coupon['starts_at'] ?? null)) ?>">
                    <div class="form-text">Leave blank to allow the coupon immediately.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">End date and time</label>
                    <input type="datetime-local" name="expires_at" class="form-control" value="<?= htmlspecialchars(couponFieldDateTime($coupon['expires_at'] ?? null)) ?>">
                    <div class="form-text">Leave blank for a coupon that only ends when staff disable it.</div>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Discount Rules', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Discount type</label>
                    <select name="discount_type" class="form-select">
                        <option value="amount" <?= (($coupon['discount_type'] ?? 'amount') === 'amount') ? 'selected' : '' ?>>Flat dollar amount off</option>
                        <option value="percent" <?= (($coupon['discount_type'] ?? '') === 'percent') ? 'selected' : '' ?>>Percent off</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Discount value</label>
                    <input type="number" name="discount_value" class="form-control" min="0.01" step="0.01" value="<?= htmlspecialchars((string)($coupon['discount_value'] ?? '0.00')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">% discount cap (optional)</label>
                    <input type="number" name="percent_cap_amount" class="form-control" min="0" step="0.01" value="<?= htmlspecialchars((string)($coupon['percent_cap_amount'] ?? '')) ?>">
                    <div class="form-text">Use this when a high percent off should never exceed a certain dollar amount.</div>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Where The Coupon Can Be Used', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-check-label d-flex align-items-center gap-2">
                        <input type="checkbox" name="applies_to_all_paid" value="1" class="form-check-input mt-0" <?= !empty($coupon['applies_to_all_paid']) ? 'checked' : '' ?>>
                        Apply to every paid package
                    </label>
                    <div class="form-text">When this is off, only the specific premium plans checked below will accept the coupon.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Specific paid packages</label>
                    <div class="coupon-package-grid">
                        <?php foreach ($paidPackages as $package): ?>
                            <label class="coupon-package-option">
                                <input type="checkbox" name="eligible_package_ids[]" value="<?= (int)$package['id'] ?>" <?= in_array((int)$package['id'], $selectedPackageIds, true) ? 'checked' : '' ?>>
                                <span class="fw-semibold"><?= htmlspecialchars((string)$package['name']) ?></span>
                                <small><?= ((float)($package['price'] ?? 0)) > 0 ? '$' . number_format((float)$package['price'], 2) : 'Free' ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Specific billing options</label>
                    <div class="coupon-package-grid">
                        <?php foreach ($paidPackages as $package): ?>
                            <?php foreach (($package['billing_options'] ?? []) as $billingOption): ?>
                                <?php if (empty($billingOption['id'])) { continue; } ?>
                                <label class="coupon-package-option">
                                    <input type="checkbox" name="eligible_billing_option_ids[]" value="<?= (int)$billingOption['id'] ?>" <?= in_array((int)$billingOption['id'], $selectedBillingOptionIds, true) ? 'checked' : '' ?>>
                                    <span class="fw-semibold"><?= htmlspecialchars((string)$package['name']) ?>: <?= htmlspecialchars((string)($billingOption['option_label'] ?? 'Billing option')) ?></span>
                                    <small>$<?= number_format((float)($billingOption['price'] ?? 0), 2) ?> for <?= htmlspecialchars(\App\Service\PaymentService::formatTermLabel((int)($billingOption['term_days'] ?? 30))) ?></small>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Leave every billing option unchecked if the coupon should work across all allowed options inside the selected packages.</div>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Eligibility', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Who can use it</label>
                    <select name="purchase_scope" class="form-select">
                        <option value="both" <?= (($coupon['purchase_scope'] ?? 'both') === 'both') ? 'selected' : '' ?>>New and renewal checkouts</option>
                        <option value="new_only" <?= (($coupon['purchase_scope'] ?? '') === 'new_only') ? 'selected' : '' ?>>New premium accounts only</option>
                        <option value="renewal_only" <?= (($coupon['purchase_scope'] ?? '') === 'renewal_only') ? 'selected' : '' ?>>Renewals only</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">New-account rule</label>
                    <select name="new_account_rule" class="form-select">
                        <option value="first_subscription" <?= (($coupon['new_account_rule'] ?? '') === 'first_subscription') ? 'selected' : '' ?>>First subscription ever</option>
                        <option value="first_paid_subscription" <?= (($coupon['new_account_rule'] ?? 'first_paid_subscription') === 'first_paid_subscription') ? 'selected' : '' ?>>First paid subscription ever</option>
                    </select>
                    <div class="form-text">Used when the coupon is allowed on new-account checkouts.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Renewal rule</label>
                    <select name="renewal_rule" class="form-select">
                        <option value="active_only" <?= (($coupon['renewal_rule'] ?? '') === 'active_only') ? 'selected' : '' ?>>Active renewals only</option>
                        <option value="active_or_returning" <?= (($coupon['renewal_rule'] ?? 'active_or_returning') === 'active_or_returning') ? 'selected' : '' ?>>Active and returning expired premium users</option>
                    </select>
                    <div class="form-text">Used when the coupon can be used on renewals.</div>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Usage Limits', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Discount duration</label>
                    <select name="duration_type" class="form-select">
                        <option value="once" <?= (($coupon['duration_type'] ?? 'once') === 'once') ? 'selected' : '' ?>>One billing cycle only</option>
                        <option value="cycles" <?= (($coupon['duration_type'] ?? '') === 'cycles') ? 'selected' : '' ?>>First X billing cycles</option>
                        <option value="forever" <?= (($coupon['duration_type'] ?? '') === 'forever') ? 'selected' : '' ?>>Every qualifying renewal</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Cycle count</label>
                    <input type="number" name="duration_cycles" class="form-control" min="1" step="1" value="<?= htmlspecialchars((string)($coupon['duration_cycles'] ?? '')) ?>">
                    <div class="form-text">Only used for “First X billing cycles.” Fyuhls claims that multi-cycle offer on the buyer's first qualifying subscription start, so they cannot restart checkout later to reuse the same cycle window.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Total redemption limit</label>
                    <input type="number" name="total_redemption_limit" class="form-control" min="1" step="1" value="<?= htmlspecialchars((string)($coupon['total_redemption_limit'] ?? '')) ?>">
                    <div class="form-text">Leave blank for no overall cap.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Per-user redemption limit</label>
                    <input type="number" name="per_user_redemption_limit" class="form-control" min="1" step="1" value="<?= htmlspecialchars((string)($coupon['per_user_redemption_limit'] ?? '')) ?>">
                    <div class="form-text">Leave blank if the duration rule should be the only per-user limit.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Internal note</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional campaign note for the admin team."><?= htmlspecialchars((string)($coupon['notes'] ?? '')) ?></textarea>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $isNewCoupon ? 'Create Coupon' : 'Save Coupon Changes' ?></button>
            <a href="/admin/coupons" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>

    <div class="col-xl-4">
        <?php renderAdminCardStart('Quick Policy Snapshot', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <ul class="list-unstyled small text-muted mb-0 d-grid gap-2">
                <li><strong class="text-dark">Coupon code:</strong> Buyers enter this during premium checkout.</li>
                <li><strong class="text-dark">Discount type:</strong> Dollar-off and percent-off both stop at $0.00. Fyuhls never lets a coupon create a negative charge.</li>
                <li><strong class="text-dark">Limits:</strong> Reserved checkouts temporarily count toward usage so limited campaigns are not overspent.</li>
                <li><strong class="text-dark">Renewal logic:</strong> Renewal-only coupons can be strict to active subscribers or include returning expired premium users. Multi-cycle discounts are claimed once per buyer, then continue on that subscription for the configured number of discounted cycles.</li>
                <li><strong class="text-dark">Pending checkout note:</strong> A buyer who already started checkout keeps that reserved coupon snapshot unless the payment is cancelled, times out, or a material coupon rule change invalidates the pending checkout.</li>
            </ul>
        <?php renderAdminCardEnd(); ?>

        <?php if (!$isNewCoupon): ?>
            <?php renderAdminCardStart('Recent Coupon Usage', ['cardClass' => 'card border-0 shadow-sm']); ?>
                <?php if ($recentRedemptions === []): ?>
                    <p class="text-muted small mb-0">Nobody has started checkout with this coupon yet.</p>
                <?php else: ?>
                    <div class="d-grid gap-2 coupon-redemption-list">
                        <?php foreach ($recentRedemptions as $row): ?>
                            <div class="border rounded p-2">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?= htmlspecialchars((string)($row['username'] ?: ('User #' . (int)$row['user_id']))) ?></strong>
                                    <span class="small text-muted"><?= htmlspecialchars((string)$row['status']) ?></span>
                                </div>
                                <div class="small text-muted"><?= htmlspecialchars((string)($row['package_name'] ?? 'Unknown package')) ?> • <?= htmlspecialchars((string)$row['purchase_kind']) ?></div>
                                <div class="small text-muted">$<?= number_format((float)($row['discount_amount'] ?? 0), 2) ?> off • <?= htmlspecialchars((string)$row['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php renderAdminCardEnd(); ?>
        <?php endif; ?>
    </div>
</form>

<style>
.coupon-note-box{border:1px solid #dbe4f0;border-radius:14px;padding:1rem;background:#f8fbff}
.coupon-note-box h6{margin-bottom:.5rem}
.coupon-note-box p{margin-bottom:0;color:#64748b}
.coupon-package-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem}
.coupon-package-option{display:flex;flex-direction:column;gap:.15rem;border:1px solid #dbe4f0;border-radius:14px;padding:.85rem 1rem;background:#fff}
.coupon-package-option input{margin-bottom:.35rem}
.coupon-package-option small{color:#64748b}
.coupon-redemption-list{max-height:520px;overflow:auto}
</style>

<?php include 'footer.php'; ?>
