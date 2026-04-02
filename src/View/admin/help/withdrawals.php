<?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
<div class="p-1">
    <p class="guide-purpose mb-4">Withdrawals are the admin approval queue for creator payout requests. This page is only available while Rewards is enabled.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Process A Withdrawal</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Open Manage:</strong> Review the payout method and payment details submitted by the user.</li>
        <li><strong>Send the payment outside the app:</strong> The script tracks status and notes, but it does not move real money for you.</li>
        <li><strong>Update the status:</strong> Use <em>Approved</em> when you intend to pay soon, <em>Paid</em> after payment is complete, or <em>Rejected</em> if the request is invalid.</li>
        <li><strong>Add an admin note:</strong> The note is visible to the user, so write it as a status message rather than an internal-only comment.</li>
    </ol>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Safeguard:</strong> Once a request is marked <em>paid</em> or <em>rejected</em>, it becomes locked and cannot be changed again from this screen.
    </div>
</div>
<?php else: ?>
<div class="p-5 text-center text-muted">
    <p>The <strong>Rewards</strong> feature is disabled. Enable it in <a href="/admin/configuration?tab=monetization">Config Hub</a> to use withdrawals.</p>
</div>
<?php endif; ?>
