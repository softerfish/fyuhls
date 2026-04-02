<div class="p-1">
    <p class="guide-purpose mb-4">Use the dashboard as your daily triage page. It summarizes user growth, file growth, storage usage, recent admin tasks, and quick health warnings.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Each Area Means</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Total Users / Total Files / Storage Used:</strong> Fast headline counts pulled from the platform stats service.</li>
        <li class="mb-2"><strong>Cache Status:</strong> <em>Optimized</em> means cached counters are being used. <em>Live (Slow)</em> means the dashboard is falling back to direct database calculations.</li>
        <li class="mb-2"><strong>Platform Growth:</strong> Compares uploads and active download traffic over the last 30 days so you can spot drops or spikes quickly.</li>
        <li class="mb-2"><strong>Recent User Activity:</strong> Shows the latest recorded account events such as logins, uploads, settings changes, and system actions.</li>
        <li class="mb-2"><strong>Smart To-Do List:</strong> Flags items that need admin attention first, including pending abuse reports, pending withdrawals, cron failures, and pending encryption work.</li>
        <li><strong>Bug Reports:</strong> Opens the Support Center where you can generate a sanitized support bundle for troubleshooting.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Best Use</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Check the To-Do List first:</strong> It surfaces the items most likely to break trust, payouts, or compliance.</li>
        <li><strong>Watch storage growth:</strong> If storage rises quickly, review package limits and add capacity in the Storage tab before uploads start failing.</li>
        <li><strong>Use activity logs for context:</strong> When a user reports a problem, the recent activity block often shows what happened just before it.</li>
    </ol>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Tip:</strong> Use this page for overview, then move into <a href="/admin/status" class="guide-action-link">System Status</a> for logs and server health, or <a href="/admin/support" class="guide-action-link">Support Center</a> for exportable diagnostics.
    </div>
</div>
