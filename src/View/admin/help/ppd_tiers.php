<?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
<div class="small">
    <p class="mb-4">Set exactly how much users earn for every 1,000 unique downloads based on the visitor's country.</p>
    
    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">1. Country Groups (Tiers)</h6>
    <p class="mb-3">Visitors from higher-value regions (like the US or UK) typically have higher payout rates. You can create groups and assign countries to them in the Rewards configuration area.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">2. Payout Rates</h6>
    <ul class="mb-4">
        <li><strong>Fixed Rate:</strong> Enter the dollar amount (e.g. 5.00 for $5.00) you want to pay per 1,000 downloads for each tier.</li>
        <li><strong>Unique Downloads:</strong> Only one download per IP address per 24 hours is counted towards the user's earnings.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Referral Bonus:</strong> In addition to PPD, users also earn a percentage of any premium subscriptions bought by their referrals. This is managed in the PPS settings.
    </div>
</div>
<?php else: ?>
<div class="alert alert-light border m-4">
    The <strong>Rewards</strong> feature must be enabled to configure PPD tiers. Please enable it in <a href="/admin/configuration?tab=monetization">Configuration</a>.
</div>
<?php endif; ?>
