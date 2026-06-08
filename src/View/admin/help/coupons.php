<div class="small">
    <p class="mb-4">Coupons lets you create premium discount codes without relying on staff memory or checkout-side guesswork. Each coupon controls who can use it, which premium packages accept it, how much it discounts, and how long it stays valid.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Each Section Does</h6>
    <ul class="mb-4">
        <li><strong>Offer Basics:</strong> Sets the customer-facing code, your internal campaign label, whether the coupon is live, and the start/end window.</li>
        <li><strong>Discount Rules:</strong> Chooses flat-dollar or percent-off pricing. Percent coupons can also use a dollar cap.</li>
        <li><strong>Where The Coupon Can Be Used:</strong> Decides whether every paid package accepts the coupon or only specific premium plans.</li>
        <li><strong>Eligibility:</strong> Controls whether the code is for new accounts, renewals, or both, and how strict each rule should be.</li>
        <li><strong>Usage Limits:</strong> Controls how many times the coupon can be redeemed overall and per user. Multi-cycle recurring offers are claimed once, then continue on that subscription for the configured discounted cycle window.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Changing A Coupon Does</h6>
    <ul class="mb-4">
        <li><strong>Availability changes:</strong> Toggling the coupon off stops brand-new checkouts from starting with it immediately, but it does not alter completed subscriptions.</li>
        <li><strong>Discount changes:</strong> Future checkout starts use the new discount value. Existing redeemed rows remain as historical records.</li>
        <li><strong>Package targeting changes:</strong> The next checkout start enforces the new package and billing-option scope right away.</li>
        <li><strong>Eligibility changes:</strong> The next checkout start evaluates the new rules when deciding whether the buyer still qualifies.</li>
        <li><strong>Limit changes:</strong> Reserved and redeemed uses count toward limits, so capped campaigns stay honest under load.</li>
        <li><strong>Pending checkout reservations:</strong> Once staff materially change eligibility, limits, activity, timing, or discount behavior, Fyuhls invalidates older pending reserved checkouts still waiting on that coupon.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Operational Notes</h6>
    <ul class="mb-4">
        <li><strong>Case-insensitive codes:</strong> Buyers can enter upper or lower case. Fyuhls normalizes the code before matching it.</li>
        <li><strong>Safe checkout math:</strong> Coupons cannot push a purchase below $0.00.</li>
        <li><strong>Renewal campaigns:</strong> Renewal-only coupons can be strict to active subscribers or include users returning after premium expired.</li>
        <li><strong>Audit trail:</strong> Open a coupon to see recent reserved and redeemed uses, including who used it and whether it was a new or renewal checkout.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Good habit:</strong> Give each coupon a clear internal label so staff can tell the difference between public promos, retention offers, affiliate campaigns, and one-off support gestures.
    </div>
</div>
