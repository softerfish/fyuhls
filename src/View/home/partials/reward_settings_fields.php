<div style="margin-top: 2rem; padding: 1.5rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px;">
    <label style="font-weight: 600; display: block; margin-bottom: 0.5rem; font-size: 1rem;">Monetization Rewards Program</label>
    <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 1.25rem; line-height: 1.5;">
        Choose your primary way of earning. This affects how your downloads and referrals are calculated.
    </p>

    <div class="form-group" style="margin-bottom: 0;">
        <select name="monetization_model" <?= empty($enabledModels) ? 'disabled' : '' ?> class="form-control" style="width: 100%; padding:0.75rem; border: 1px solid var(--primary-color); border-radius:6px;">
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

<h3 style="margin-top: 2.5rem; border-top: 1px solid var(--border-color); padding-top: 2rem;">Withdrawal & Payment Settings</h3>
<?php
$supportedMethods = array_filter(array_map('trim', explode(',', \App\Model\Setting::get('supported_withdrawal_methods', 'paypal,bitcoin', 'rewards'))));
$methodLabels = [
    'paypal' => 'PayPal (Email)',
    'stripe' => 'Stripe / Bank',
    'bitcoin' => 'Bitcoin (Wallet Address)',
    'wire' => 'Bank Wire Transfer',
];
?>
<div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 1.5rem;">
    <div class="form-group">
        <label>Default Payment Method</label>
        <select name="payment_method" class="form-control" style="width: 100%; padding:0.625rem; border: 1px solid var(--border-color); border-radius:6px;">
            <?php foreach ($supportedMethods as $method): ?>
                <?php if (isset($methodLabels[$method])): ?>
                    <option value="<?= htmlspecialchars($method) ?>" <?= ($user['payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= htmlspecialchars($methodLabels[$method]) ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Payment ID / Account Details</label>
        <input type="text" name="payment_details" maxlength="500" value="<?= htmlspecialchars($user['payment_details'] ?? '') ?>" placeholder="e.g. your@email.com or BTC address" class="form-control" style="width: 100%; padding:0.625rem; border: 1px solid var(--border-color); border-radius:6px;">
    </div>
</div>
