<div class="small">

    <p class="mb-4">Review and manage premium subscriptions and billing history for your users.</p>

    

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">1. Subscription Management</h6>

    <p class="mb-3">Track who is currently Premium, when their status expires, and how they paid (Stripe, PayPal, Rewards balance, etc.).</p>



    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">2. Expiry Reminders</h6>

    <p class="mb-3">The system automatically sends emails 7 days and 1 day before an account expires. You can view these queued notifications in the <strong>Email</strong> worker logs.</p>



    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">3. Troubleshooting</h6>

    <ul class="mb-4">

        <li><strong>Payment not showing?</strong> If a user paid but their status hasn't updated, check your payment provider's webhook logs for any "403 Forbidden" or "500 Error" responses.</li>

        <li><strong>Manual upgrades?</strong> You can always manually upgrade a user's package by editing their profile in the <strong>Users</strong> section.</li>

        <li><strong>Refunds?</strong> We recommend processing refunds in your payment provider first, then manually downgrading the user here to keep records clean.</li>

    </ul>



    <div class="alert alert-info border-0">

        <strong>Billing Receipts:</strong> Users receive an automated email receipt immediately after a successful checkout. You can customize this in the <strong>Email Templates</strong> section.

    </div>

</div>

