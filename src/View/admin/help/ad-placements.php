<div class="p-1">
    <p class="mb-3 small">This guide is used for the Monetization tab in Config Hub. That tab now covers both Rewards and ad placement settings.</p>

    <h6 class="fw-bold text-dark small text-uppercase mb-2">Rewards And Affiliate</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Enable Rewards:</strong> Turns on earnings, withdrawals, uploader monetization, and the rewards fraud workflow.</li>
        <li class="mb-2"><strong>Enable Affiliate Program:</strong> Only works when Rewards is enabled and controls whether referral sales can earn commission.</li>
        <li class="mb-2"><strong>Models:</strong> Choose which monetization models users are allowed to select: PPD, PPS, or Mixed.</li>
        <li class="mb-2"><strong>PPS Commission / Mixed Percentages:</strong> Controls how package-sale revenue is split for PPS and Mixed publishers.</li>
        <li class="mb-2"><strong>Affiliate Hold Days:</strong> Keeps affiliate commission in a held state for a configurable number of days before it clears, which gives you a refund and chargeback buffer.</li>
        <li class="mb-2"><strong>PPD Rules:</strong> IP limits, file-size limits, download-progress threshold, video-watch thresholds, guest-only mode, and VPN counting behavior all live here.</li>
        <li class="mb-2"><strong>Withdrawal Methods:</strong> Controls which payout methods users are allowed to submit.</li>
        <li class="mb-2"><strong>Rewards Retention:</strong> Defines how long reward events stay in the detailed rollup window before older data is collapsed or trimmed.</li>
        <li><strong>Payment Gateways:</strong> Stripe and PayPal credentials, verification secrets, and sandbox or live behavior are managed here.</li>
    </ul>

    <h6 class="fw-bold text-dark small text-uppercase mb-2">Ad Placements</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Top / Bottom / Left / Right:</strong> HTML or ad code injected into the download page layout.</li>
        <li><strong>Overlay:</strong> Full-page or interstitial code that runs before or during the download flow.</li>
    </ul>

    <h6 class="fw-bold text-dark small text-uppercase mb-2">PPD Tier Table</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Countries:</strong> Two-letter ISO codes separated by commas.</li>
        <li><strong>Rate / 1000:</strong> Reward amount used when traffic matches that tier.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Fraud Note:</strong> On ordinary file downloads, direct presigned URLs, Apache X-SendFile, and LiteSpeed internal redirect are still start-based. App-Controlled PHP is the strongest proof path, and Nginx is the only accelerated standard-file option that can still honor <code>ppd_min_download_percent</code> through the completion log pipeline. Streaming or watch-based media proof is separate.
    </div>
</div>
