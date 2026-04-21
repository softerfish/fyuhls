<div class="small">
    <p class="mb-4">Use Support Center for sanitized diagnostics, safer support handoff, and updater checks. This is the safest place to gather technical context without exposing raw secrets.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Reproduce the issue first:</strong> Generate a bundle only after you confirm the problem still exists so the logs and diagnostics are current.</li>
        <li><strong>Check System Status next:</strong> Review logs, writable paths, FFmpeg, GD, multipart health, and recent errors before exporting anything.</li>
        <li><strong>Export the sanitized bundle:</strong> Use the JSON download when you need a clean support snapshot for debugging or escalation.</li>
        <li><strong>Email only after review:</strong> If SMTP works and you are comfortable with the sanitized payload, send the same bundle directly from this page.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Sanitized Support Bundles</h6>
    <ul class="mb-4">
        <li><strong>Download Sanitized JSON:</strong> Exports a plain <code>.json</code> support bundle, not a zip archive.</li>
        <li><strong>Email Support Bundle:</strong> Sends that same JSON payload directly when SMTP is configured.</li>
        <li><strong>What is included:</strong> Version info, environment checks, plugin summary, sanitized config snapshot, and recent sanitized logs.</li>
        <li><strong>What is redacted:</strong> Secrets, tokens, encryption material, IP addresses, email addresses, and sensitive absolute paths.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Updater Notes</h6>
    <ul class="mb-4">
        <li><strong>Preview first:</strong> Use the update preview before applying when you can.</li>
        <li><strong>Backups first:</strong> Keep database and file backups before any update path.</li>
        <li><strong>Core-file ownership rules:</strong> Fyuhls is designed to avoid blindly overwriting or deleting local state during update apply.</li>
        <li><strong>Quarantine behavior:</strong> Old unchanged core files can be moved into update quarantine instead of being hard-deleted.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Tip:</strong> The fastest support workflow is usually: reproduce the issue, inspect <a href="/admin/status" class="guide-action-link">System Status</a>, then generate a sanitized support bundle from this page.
    </div>
</div>
