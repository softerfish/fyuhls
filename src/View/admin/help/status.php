<div class="small">
    <p class="mb-4">System Status is the admin ops center. Use it to triage active problems first, then move into upload, delivery, logging, update, and support diagnostics once you know which domain needs attention.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="mb-4">
        <li><strong>Use Status first</strong> when something feels broken but you do not yet know whether the issue is environment, uploads, delivery, updates, or logging.</li>
        <li><strong>Use Diagnostics next</strong> when the status sections still do not explain the behavior and you need a sanitized bundle for escalation.</li>
        <li><strong>Do not use Status as your only config page</strong> for Email, Cron, Downloads, or Storage changes. Treat it as triage plus guided next steps.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How The Page Is Organized</h6>
    <ul class="mb-4">
        <li><strong>Triage First:</strong> The page starts with critical issues, warnings, and healthy signals so you can decide what matters right now.</li>
        <li><strong>Action Center:</strong> Quick links take you straight to Cron, Email, Download, Storage, Monitoring, or Support without hunting through the sidebar.</li>
        <li><strong>Domain Sections:</strong> App Health, Updates, Upload Pipeline, Storage &amp; Reservations, Download &amp; Delivery, Support Diagnostics, and Logs each answer a different operational question.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What To Check First</h6>
    <ul class="mb-4">
        <li><strong>Recent Errors:</strong> Start here when reports are vague and you need to know whether error-level events are piling up.</li>
        <li><strong>Upload Backlog:</strong> A fast signal for stale sessions, failed uploads, stuck completions, checksum lag, or expired reservations.</li>
        <li><strong>Update Status:</strong> Shows whether the install is current and whether updater checks are healthy.</li>
        <li><strong>Support Readiness:</strong> Tells you whether support bundles can be emailed directly or are download-only until SMTP is fixed.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Operational Sections</h6>
    <ul class="mb-4">
        <li><strong>App Health:</strong> Verifies the basics like writable uploads path, GD, FFmpeg, rate-limit pressure, and runtime security notices.</li>
        <li><strong>Updates:</strong> Helps you decide whether to refresh release checks, review a release page, or apply an update during a maintenance window.</li>
        <li><strong>Upload Pipeline:</strong> Focuses on active, stale, failed, and stuck upload sessions plus checksum backlog.</li>
        <li><strong>Storage &amp; Reservations:</strong> Focuses on quota reservations and capacity that may still be held by uploads or cleanup work.</li>
        <li><strong>Download &amp; Delivery:</strong> Shows whether downloads are running through CDN redirects, signed-origin rules, or the app-controlled path.</li>
        <li><strong>Support Diagnostics:</strong> Confirms whether support bundles and support email are ready before you escalate.</li>
        <li><strong>Logs:</strong> Gives you level filters, readable messages, optional context, and a clear-log action for maintenance.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Common Next Steps</h6>
    <ul class="mb-4">
        <li><strong>Cron problems:</strong> Open <code>Config Hub &gt; Cron</code> if upload backlog or stale reservations keep returning.</li>
        <li><strong>Delivery problems:</strong> Open <code>Config Hub &gt; Downloads</code> or <code>Live Downloads</code> when routing or payout verification looks wrong.</li>
        <li><strong>Storage problems:</strong> Open <code>Config Hub &gt; Storage</code> or <code>Server Monitoring</code> for quota, provider, or node-level drift.</li>
        <li><strong>Support problems:</strong> Open <code>Config Hub &gt; Email</code> or <code>Diagnostics</code> if support mail or escalation flow is not ready.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Tip:</strong> This page is meant to reduce operator hesitation. Read the short operator notes first, then expand the lower-level tables or log context only when you need the extra detail.
    </div>
</div>
