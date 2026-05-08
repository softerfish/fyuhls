<style>
    .reward-settings-card {
        padding: 1rem 1.05rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
    }
    .reward-settings-label {
        font-weight: 700;
        display: block;
        margin-bottom: 0.5rem;
        font-size: 0.94rem;
        color: #0f172a;
    }
    .reward-settings-copy {
        font-size: 0.83rem;
        color: var(--text-muted);
        margin-bottom: 1rem;
        line-height: 1.55;
    }
    .reward-settings-group { margin-bottom: 0; }
    .reward-settings-select,
    .reward-settings-input {
        width: 100%;
        border: 1px solid var(--border-color);
        border-radius: 10px;
    }
    .reward-settings-select {
        padding: 0.75rem;
    }
    .reward-settings-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.35fr);
        gap: 1rem 1.25rem;
        margin-top: 1rem;
    }
    .reward-settings-heading {
        margin: 1.4rem 0 0.35rem;
        font-size: 0.94rem;
        font-weight: 800;
        color: #0f172a;
    }
    .reward-settings-heading-copy {
        margin: 0 0 1rem;
        color: var(--text-muted);
        font-size: 0.82rem;
        line-height: 1.55;
    }
    @media (max-width: 900px) {
        .reward-settings-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="reward-settings-card">
    <label class="reward-settings-label">Monetization Rewards Program</label>
    <p class="reward-settings-copy">
        Choose your primary way of earning. This affects how your downloads and referrals are calculated.
    </p>

    <div class="form-group reward-settings-group">
        <select name="monetization_model" <?= empty($enabledModels) ? 'disabled' : '' ?> class="form-control reward-settings-select">
            <?php if (in_array('ppd', $enabledModels, true)): ?>
                <option value="ppd" <?= ($user['monetization_model'] ?? 'ppd') === 'ppd' ? 'selected' : '' ?>>Pay-Per-Download (PPD) - Earn per 1,000 downloads</option>
            <?php endif; ?>
            <?php if (in_array('pps', $enabledModels, true)): ?>
                <option value="pps" <?= ($user['monetization_model'] ?? '') === 'pps' ? 'selected' : '' ?>>Pay-Per-Sale (PPS) - Earn percentage of sales</option>
            <?php endif; ?>
            <?php if (in_array('mixed', $enabledModels, true)): ?>
                <option value="mixed" <?= ($user['monetization_model'] ?? '') === 'mixed' ? 'selected' : '' ?>>Mixed / Hybrid Model - Earn from both PPD and PPS</option>
            <?php endif; ?>
        </select>
    </div>
</div>

<h4 class="reward-settings-heading">Withdrawal & Payment Settings</h4>
<p class="reward-settings-heading-copy">Set the payout method and account details staff should use when your earnings are ready to withdraw. Leave this current so payments are not delayed.</p>
<?php
$supportedMethods = array_filter(array_map('trim', explode(',', \App\Model\Setting::get('supported_withdrawal_methods', 'paypal,bitcoin', 'rewards'))));
$methodLabels = [
    'paypal' => 'PayPal (Email)',
    'stripe' => 'Stripe / Bank',
    'bitcoin' => 'Bitcoin (Wallet Address)',
    'wire' => 'Bank Wire Transfer',
];
?>
<div class="reward-settings-grid">
    <div class="form-group">
        <label>Default Payment Method</label>
        <select name="payment_method" class="form-control reward-settings-input">
            <?php foreach ($supportedMethods as $method): ?>
                <?php if (isset($methodLabels[$method])): ?>
                    <option value="<?= htmlspecialchars($method) ?>" <?= ($user['payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= htmlspecialchars($methodLabels[$method]) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Payment ID / Account Details</label>
        <input type="text" name="payment_details" maxlength="500" value="<?= htmlspecialchars($user['payment_details'] ?? '') ?>" placeholder="e.g. your@email.com or BTC address" class="form-control reward-settings-input">
    </div>
</div>
