<div class="small">
    <p class="mb-4">Use Support Center for sanitized diagnostics, safer escalation, and updater context. It is the safest place to gather technical evidence without exposing raw secrets.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="mb-4">
        <li><strong>Use it when:</strong> You need a clean support bundle, want to review updater context, or need to hand an issue off without leaking secrets.</li>
        <li><strong>Use another page when:</strong> You already know the problem is queue, ticket, or package specific. Work the operational page first, then come here for escalation support.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Reproduce the issue first:</strong> Generate evidence only after you confirm the problem still exists.</li>
        <li><strong>Check System Status next:</strong> Look at logs, writable paths, queue health, FFmpeg, GD, and extension warnings before exporting anything.</li>
        <li><strong>Export the sanitized bundle:</strong> Use the JSON download when you need a clean snapshot for escalation.</li>
        <li><strong>Email only after review:</strong> Send the same sanitized payload only when SMTP is already healthy and you are comfortable with the redaction level.</li>
    </ol>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/status" class="guide-action-link">System Status</a> for raw health checks, <a href="/admin/configuration?tab=email" class="guide-action-link">Config Hub &gt; Email</a> for SMTP fixes, and <a href="/admin/docs" class="guide-action-link">Documentation</a> when you need the page-by-page context before escalating.
    </div>
</div>
