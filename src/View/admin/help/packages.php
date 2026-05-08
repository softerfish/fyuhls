<div class="p-1">
    <p class="guide-purpose mb-4">Packages define what a user can do, how downloads feel, what limits apply, and whether a plan participates in rewards or checkout. Think of this page as the per-plan rules layer, not the master switch for every related system.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Use it when:</strong> You need to change limits, waits, pricing, ad behavior, remote-upload access, or reward participation for a specific plan.</li>
        <li class="mb-2"><strong>Use Config Hub instead when:</strong> You need to change site-wide rewards rules, affiliate settings, VPN policy, ticket settings, or global upload and download defaults.</li>
        <li><strong>What it does not do:</strong> It does not replace SMTP, monetization master switches, or global security controls.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Is On The Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Package index:</strong> Compare plans side by side, including price, storage, upload size, bandwidth, rewards mode, ads, and assigned-user counts.</li>
        <li class="mb-2"><strong>Clone:</strong> Duplicate a normal package to create a new tier faster. Singleton system plans such as <code>guest</code> and <code>admin</code> cannot be cloned.</li>
        <li class="mb-2"><strong>Edit page summary:</strong> The top chips surface the most important plan details before you scroll into the full form.</li>
        <li><strong>Customer preview card:</strong> Shows the package the way a buyer or upgrader is more likely to think about it.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Edit Screen Sections</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Overview:</strong> Name, level, price, and high-level plan identity.</li>
        <li class="mb-2"><strong>Storage &amp; Uploads:</strong> Single-file upload size, total storage, and upload-related limits.</li>
        <li class="mb-2"><strong>Downloads &amp; Delivery:</strong> Wait time, bandwidth, speed, concurrency, and direct-link behavior.</li>
        <li class="mb-2"><strong>Rewards &amp; Payout:</strong> Whether the plan participates in PPD or PPS when those systems are enabled globally.</li>
        <li><strong>Ads &amp; Restrictions:</strong> Plan-level experience flags such as ad behavior and related capability switches.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Read the summary chips first:</strong> Confirm you are editing the right package before changing limits.</li>
        <li><strong>Change one family of settings at a time:</strong> Limits, rewards participation, and pricing have different downstream effects.</li>
        <li><strong>Keep plan math coherent:</strong> Very large single-upload sizes should still make sense against storage and daily bandwidth.</li>
        <li><strong>Use clones for new tiers:</strong> It is usually safer than rebuilding a plan from scratch.</li>
        <li><strong>Test with a real account after major changes:</strong> Especially when you change waits, concurrency, rewards participation, or checkout-facing pricing.</li>
    </ol>

    <div class="alert alert-info border-0 shadow-sm small mb-3">
        <strong>Rewards note:</strong> The package page controls whether a plan participates in rewards. Global reward rates, payout rules, and affiliate-system switches still live in <strong>Config Hub</strong>.
    </div>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/subscriptions" class="guide-action-link">Subscriptions</a> for active paid-plan state, <a href="/admin/configuration?tab=monetization" class="guide-action-link">Config Hub &gt; Monetization</a> for global rewards and affiliate settings, and <a href="/admin/users" class="guide-action-link">Users</a> when you need to confirm who is already on a plan before changing it.
    </div>
</div>
