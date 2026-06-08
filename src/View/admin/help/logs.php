<div class="small">
    <p class="mb-4">Application Logs is the rawer operator-facing log view. Use it when you need the newest redacted log lines quickly, but do not need the broader triage framing from System Status or the sanitized export flow from Diagnostics.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="mb-4">
        <li><strong>Use it when:</strong> You already know the issue is log-worthy and want the newest lines fast without navigating the larger System Status layout.</li>
        <li><strong>Use System Status instead when:</strong> You still need to decide whether the issue is uploads, delivery, cron, queue health, or environment drift.</li>
        <li><strong>Use Diagnostics instead when:</strong> You need a sanitized escalation bundle to hand to another person or system.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What The Page Is Showing</h6>
    <ul class="mb-4">
        <li><strong>Newest lines first:</strong> The page is for recent troubleshooting, not long-term retention or analytics.</li>
        <li><strong>Redacted output:</strong> Fyuhls removes obvious secrets and sensitive values before showing the lines here.</li>
        <li><strong>Current size signal:</strong> The size indicator helps you spot when the log is growing too quickly or approaching the configured cap.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Good Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Reproduce first:</strong> Trigger or confirm the issue so the newest lines are the ones you actually care about.</li>
        <li><strong>Read the surrounding context:</strong> Do not stop at the first error string if nearby lines explain the real cause.</li>
        <li><strong>Clear only on purpose:</strong> Clearing logs is for creating a fresh troubleshooting window, not routine cleanup every time something looks noisy.</li>
    </ol>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/status" class="guide-action-link">System Status</a> for triage and health checks, <a href="/admin/support" class="guide-action-link">Diagnostics</a> for sanitized escalation bundles, and <a href="/admin/configuration?tab=cron" class="guide-action-link">Config Hub &gt; Cron Jobs</a> when the same errors keep returning after background tasks run.
    </div>
</div>
