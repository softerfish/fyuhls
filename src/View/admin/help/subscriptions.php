<div class="small">
    <p class="mb-4">Subscriptions is the billing-history view for premium upgrades. Use it to confirm who paid, how they paid, what package they received, and when premium access should expire.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Review A Subscription</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Find the customer record:</strong> Start with the newest payment or search for the specific user reporting a billing issue.</li>
        <li><strong>Check the package and expiry:</strong> Make sure the plan and expiry date match what the buyer actually purchased.</li>
        <li><strong>Verify the payment method:</strong> The source helps you know whether to investigate PayPal, Stripe, rewards balance, or another checkout path.</li>
        <li><strong>Cross-check the user account if needed:</strong> Open <a href="/admin/users" class="guide-action-link">Users</a> if the billing row looks correct but the actual account access is wrong.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Expiry And Renewal Notes</h6>
    <ul class="mb-4">
        <li><strong>Reminder emails:</strong> Expiry-warning mail depends on the email system and cron being healthy.</li>
        <li><strong>Downgrades:</strong> Once premium expires, the user falls back through the normal package path instead of staying permanently upgraded.</li>
        <li><strong>Manual adjustments:</strong> If you extend or repair an account manually, keep the user record and the billing story aligned.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Troubleshooting</h6>
    <ul class="mb-4">
        <li><strong>Payment not showing?</strong> Check provider webhooks first. Missing subscription rows are usually callback failures, bad secrets, or temporary provider issues.</li>
        <li><strong>User paid but still looks free?</strong> Confirm the payment row exists here, then correct the account package on <a href="/admin/users" class="guide-action-link">Users</a> if needed.</li>
        <li><strong>Refunds or chargebacks?</strong> Handle the money movement in the payment provider first, then bring the app-side account state back in line.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Billing Receipts:</strong> Successful checkout receipts are email-driven. If customers are paying correctly but not receiving receipts, inspect email configuration before editing subscription records.
    </div>
</div>
