<div class="p-1">
    <p class="guide-purpose mb-4">Use the dashboard as your daily triage page. Start here to see what needs attention, what changed today, and which operational area you should open next.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Read It In This Order</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Attention Needed first:</strong> This is the fastest way to catch recent errors, overdue automation, SMTP gaps, moderation backlog, and storage pressure before they turn into support pain.</li>
        <li><strong>What changed today second:</strong> Use it for quick daily orientation so you can tell whether uploads, users, or activity feel normal.</li>
        <li><strong>Headline cards third:</strong> Total Users, Total Files, Storage Used, and Cache Status are fast context cards, not the full investigation surface.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What The Main Signals Mean</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Cache Status:</strong> <em>Optimized</em> means the dashboard is using cached summary stats. <em>Live (Slow)</em> means it is falling back to live database calculations because the cached summary is missing or stale.</li>
        <li class="mb-2"><strong>Recent Errors:</strong> Means recent error-level log entries were recorded. It is not a special review queue. Use it to jump to the recent error section on <a href="/admin/status#recent-errors" class="guide-action-link">System Status</a>.</li>
        <li class="mb-2"><strong>Overdue Tasks:</strong> Means one or more cron-managed jobs are behind schedule. Review <a href="/admin/configuration?tab=cron" class="guide-action-link">Cron Jobs</a> and heartbeat health.</li>
        <li class="mb-2"><strong>SMTP Missing:</strong> Means email delivery is not configured. Review <a href="/admin/configuration?tab=email" class="guide-action-link">Email</a> before expecting verification, reset, or support mail flows to work.</li>
        <li><strong>Clickable metric chips:</strong> Many cards are meant to be launch points into the real investigation page, not dead-end counters.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Widget Layout</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Saved browser layout:</strong> Widget order and collapse state can be stored in the browser, so two admins may not see the same arrangement.</li>
        <li class="mb-2"><strong>Reset layout:</strong> Use the button on the page when you want the default widget order and collapse state back.</li>
        <li><strong>Dashboard role:</strong> Use this page for fast triage, then move into the specific admin screen that can actually fix the issue.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Tip:</strong> The best daily pattern is dashboard first, then <a href="/admin/status" class="guide-action-link">System Status</a>, <a href="/admin/support" class="guide-action-link">Support Center</a>, or <a href="/admin/configuration?tab=cron" class="guide-action-link">Cron Jobs</a> depending on what the attention strip is telling you.
    </div>
</div>
