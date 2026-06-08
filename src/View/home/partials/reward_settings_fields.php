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
    .reward-settings-helper {
        margin-top: 0.45rem;
        color: var(--text-muted);
        font-size: 0.8rem;
        line-height: 1.5;
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
<p class="reward-settings-heading-copy">Choose the payout processor your admin supports, then enter the exact destination staff should pay when your earnings are ready.</p>
<?php
$processors = \App\Service\PayoutProcessorService::definitions(true);
$selectedProcessor = trim((string)($user['payment_method'] ?? ''));
$currentProcessor = \App\Service\PayoutProcessorService::find($selectedProcessor);
if ($currentProcessor === null && !empty($processors)) {
    $currentProcessor = $processors[0];
}
$currentDestinationLabel = $currentProcessor['destination_label'] ?? 'Payout destination';
$currentPlaceholder = $currentProcessor['placeholder'] ?? 'Enter the exact payout destination staff should use';
$currentHelpText = $currentProcessor['help_text'] ?? 'Enter the exact payout destination staff should use when sending your rewards payment.';
?>
<div class="reward-settings-grid">
    <div class="form-group">
        <label>Default Payout Processor</label>
        <select name="payment_method" class="form-control reward-settings-input" id="rewardPaymentMethodSelect">
            <?php foreach ($processors as $processor): ?>
                <option
                    value="<?= htmlspecialchars((string)$processor['key']) ?>"
                    data-destination-label="<?= htmlspecialchars((string)$processor['destination_label'], ENT_QUOTES, 'UTF-8') ?>"
                    data-placeholder="<?= htmlspecialchars((string)$processor['placeholder'], ENT_QUOTES, 'UTF-8') ?>"
                    data-help-text="<?= htmlspecialchars((string)$processor['help_text'], ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($user['payment_method'] ?? '') === $processor['key'] ? 'selected' : '' ?>
                ><?= htmlspecialchars((string)$processor['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="reward-settings-helper">Only payout processors enabled by the admin appear here.</div>
    </div>
    <div class="form-group">
        <label id="rewardPaymentDetailsLabel"><?= htmlspecialchars((string)$currentDestinationLabel) ?></label>
        <input
            type="text"
            name="payment_details"
            maxlength="500"
            value="<?= htmlspecialchars($user['payment_details'] ?? '') ?>"
            placeholder="<?= htmlspecialchars((string)$currentPlaceholder) ?>"
            class="form-control reward-settings-input"
            id="rewardPaymentDetailsInput"
        >
        <div class="reward-settings-helper" id="rewardPaymentDetailsHelp"><?= htmlspecialchars((string)$currentHelpText) ?></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const methodSelect = document.getElementById('rewardPaymentMethodSelect');
    const detailsLabel = document.getElementById('rewardPaymentDetailsLabel');
    const detailsInput = document.getElementById('rewardPaymentDetailsInput');
    const detailsHelp = document.getElementById('rewardPaymentDetailsHelp');
    if (!methodSelect || !detailsLabel || !detailsInput || !detailsHelp) {
        return;
    }

    const syncRewardPaymentFields = function() {
        const option = methodSelect.options[methodSelect.selectedIndex];
        if (!option) {
            return;
        }
        detailsLabel.textContent = option.getAttribute('data-destination-label') || 'Payout destination';
        detailsInput.setAttribute('placeholder', option.getAttribute('data-placeholder') || 'Enter the exact payout destination staff should use');
        detailsHelp.textContent = option.getAttribute('data-help-text') || 'Enter the exact payout destination staff should use when sending your rewards payment.';
    };

    methodSelect.addEventListener('change', syncRewardPaymentFields);
    syncRewardPaymentFields();
});
</script>
