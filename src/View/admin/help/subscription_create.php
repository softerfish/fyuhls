<div class="small">
    <p class="mb-4">Create Manual Subscription is the admin-side repair and exception flow for premium access. Use it when you need to grant or correct a paid-plan window without starting a Stripe or PayPal checkout.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="mb-4">
        <li><strong>Use it when:</strong> You need a one-time manual premium record for support recovery, offline payment handling, migration cleanup, or account repair.</li>
        <li><strong>Use Subscriptions instead when:</strong> You are reviewing existing billing history or renewal state rather than creating a new manual record.</li>
        <li><strong>Use Users as well when:</strong> You need to confirm the account you are about to affect is really the right user.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What This Action Does</h6>
    <ul class="mb-4">
        <li><strong>Creates a one-time record:</strong> It adds an admin-created premium subscription story inside fyuhls itself.</li>
        <li><strong>Updates account access:</strong> An active saved record immediately changes the user's package and premium expiry.</li>
        <li><strong>Requires operator context:</strong> The admin note is part of the audit trail and should explain why normal checkout was not used.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What This Page Does Not Do</h6>
    <ul class="mb-4">
        <li><strong>No gateway billing:</strong> It does not create a Stripe or PayPal charge.</li>
        <li><strong>No auto-renew:</strong> It never creates a recurring billing agreement.</li>
        <li><strong>No coupon logic:</strong> It is not the right place to test or simulate coupon-aware checkout behavior.</li>
        <li><strong>No stacking on live premium:</strong> Fyuhls blocks unsafe overlap with an already-live subscription story.</li>
    </ul>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/subscriptions" class="guide-action-link">Subscriptions</a> for existing billing records, <a href="/admin/users" class="guide-action-link">Users</a> for account verification, and <a href="/admin/coupons" class="guide-action-link">Coupons</a> if the real goal is discounted checkout rather than manual access repair.
    </div>
</div>
