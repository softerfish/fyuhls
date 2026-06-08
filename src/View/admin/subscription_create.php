<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';

$subscription = is_array($subscription ?? null) ? $subscription : [];
$paidPackages = is_array($paidPackages ?? null) ? $paidPackages : [];

renderAdminPageHeader(
    'Create Manual Subscription',
    'Add a non-recurring premium subscription directly from the admin area when you need to grant or repair access without starting a payment-provider checkout.',
    '<a href="/admin/subscriptions" class="btn btn-outline-secondary btn-sm">&larr; Back to Subscriptions</a>'
);
?>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <?php renderAdminCardStart('How This Works', ['cardClass' => 'card border-0 shadow-sm h-100']); ?>
            <ol class="small text-muted mb-0 ps-3">
                <li class="mb-2"><strong class="text-dark">Target the right user:</strong> use the internal user ID, public ID, exact username, or exact email.</li>
                <li class="mb-2"><strong class="text-dark">Pick the premium package:</strong> the package controls the user-facing plan they will receive.</li>
                <li class="mb-2"><strong class="text-dark">Set the term and expiry clearly:</strong> this page creates a manual record, so the expiry you enter is what the account will use.</li>
                <li><strong class="text-dark">Leave auto-renew out of it:</strong> manual subscriptions created here are always one-time and never create Stripe or PayPal billing agreements.</li>
            </ol>
        <?php renderAdminCardEnd(); ?>
    </div>
    <div class="col-lg-7">
        <?php renderAdminCardStart('Before You Save', ['cardClass' => 'card border-0 shadow-sm h-100']); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="subscription-note-box h-100">
                        <h6>Live users</h6>
                        <p>Active manual subscriptions immediately place the user on the selected paid package and set their premium expiry.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="subscription-note-box h-100">
                        <h6>Safety rail</h6>
                        <p>Fyuhls blocks this action if the user already has a live subscription, so you do not accidentally stack billing stories.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="subscription-note-box h-100">
                        <h6>Audit trail</h6>
                        <p>Your admin note is required and goes into staff activity so later troubleshooting has context.</p>
                    </div>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>

<form method="POST" class="row g-4">
    <?= \App\Core\Csrf::field() ?>
    <div class="col-xl-8">
        <?php renderAdminCardStart('Manual Subscription Details', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">User lookup</label>
                    <input type="text" name="user_lookup" class="form-control" value="<?= htmlspecialchars((string)($subscription['user_lookup'] ?? '')) ?>" required>
                    <div class="form-text">Use a user ID, public ID like <code>u_xxxxx</code>, exact username, or exact email.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Paid package</label>
                    <select name="package_id" class="form-select" required>
                        <?php foreach ($paidPackages as $package): ?>
                            <option value="<?= (int)$package['id'] ?>" <?= (int)($subscription['package_id'] ?? 0) === (int)$package['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$package['name']) ?> - $<?= number_format((float)($package['price'] ?? 0), 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only paid packages can be created here.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Amount</label>
                    <input type="number" name="amount" class="form-control" min="0" step="0.01" value="<?= htmlspecialchars((string)($subscription['amount'] ?? '0.00')) ?>" required>
                    <div class="form-text">Recorded for audit/history. This does not create a payment-provider charge.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Currency</label>
                    <input type="text" name="currency" class="form-control" maxlength="3" value="<?= htmlspecialchars((string)($subscription['currency'] ?? 'USD')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Term (days)</label>
                    <input type="number" name="term_days" class="form-control" min="1" value="<?= (int)($subscription['term_days'] ?? 30) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['active' => 'Active now', 'pending' => 'Pending / not yet applied', 'cancelled' => 'Cancelled record', 'expired' => 'Expired record'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= (($subscription['status'] ?? 'active') === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Expires at</label>
                    <input type="datetime-local" name="expires_at" class="form-control" value="<?= htmlspecialchars((string)($subscription['expires_at'] ?? '')) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Admin note</label>
                    <textarea name="admin_note" class="form-control" rows="4" required><?= htmlspecialchars((string)($subscription['admin_note'] ?? '')) ?></textarea>
                    <div class="form-text">Required. Explain why this manual subscription is being created.</div>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>

    <div class="col-xl-4">
        <?php renderAdminCardStart('What This Does Not Do', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <ul class="small text-muted mb-0 ps-3">
                <li class="mb-2">It does not start Stripe or PayPal billing.</li>
                <li class="mb-2">It does not create auto-renew.</li>
                <li class="mb-2">It does not apply coupons or recurring discount logic.</li>
                <li>It does not stack on top of an already-live subscription.</li>
            </ul>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Save Action', ['cardClass' => 'card border-0 shadow-sm']); ?>
            <p class="small text-muted">When you save an active manual subscription, the user package and premium expiry update immediately.</p>
            <button type="submit" class="btn btn-primary w-100">Create Manual Subscription</button>
        <?php renderAdminCardEnd(); ?>
    </div>
</form>

<style>
.subscription-note-box{border:1px solid #e2e8f0;border-radius:10px;padding:1rem;background:#f8fafc;height:100%}
.subscription-note-box h6{margin-bottom:.5rem}
.subscription-note-box p{font-size:.875rem;color:#64748b;margin:0}
</style>

<?php include 'footer.php'; ?>
