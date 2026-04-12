<?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
<div class="p-1">
    <p class="guide-purpose mb-4">Withdrawals is the admin payout queue for uploader earnings. Use it to review requests, confirm payment details, and keep reward balances synchronized with real-world payouts.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Process A Withdrawal</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Open Manage:</strong> Review amount, payment method, and the payout details the user submitted.</li>
        <li><strong>Verify the account context:</strong> Check the uploader account and, if needed, review <a href="/admin/rewards-fraud" class="guide-action-link">Rewards Fraud</a> before approving anything suspicious.</li>
        <li><strong>Send the payment outside the app:</strong> Fyuhls tracks status and notes, but the actual transfer still happens in your payment processor or wallet.</li>
        <li><strong>Update the status carefully:</strong> Use <em>Approved</em> when you intend to pay, <em>Paid</em> after the transfer is complete, or <em>Rejected</em> if the request is invalid.</li>
        <li><strong>Add a clear note:</strong> The user can see the note, so write it like a status explanation rather than a private admin memo.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Review Flow</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Check balance logic first:</strong> Make sure the request lines up with the cleared balance you expect.</li>
        <li class="mb-2"><strong>Review method-specific details:</strong> PayPal addresses, crypto wallets, and bank details should be complete before you mark anything approved.</li>
        <li class="mb-2"><strong>Keep proof outside the app:</strong> Save transaction IDs or provider screenshots in your own payout records if you need accounting history.</li>
        <li><strong>Use rejections sparingly:</strong> If the problem is just missing information, it is often better to ask for corrected details than to reject a legitimate payout permanently.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Safeguard:</strong> Once a request is marked <em>paid</em> or <em>rejected</em>, it becomes locked. Double-check the payout before finalizing the decision.
    </div>
</div>
<?php else: ?>
<div class="p-5 text-center text-muted">
    <p>The <strong>Rewards</strong> feature is disabled. Enable it in <a href="/admin/configuration?tab=monetization">Config Hub</a> to use withdrawals.</p>
</div>
<?php endif; ?>
