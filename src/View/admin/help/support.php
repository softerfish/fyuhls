<div class="small">
    <p class="mb-4">Use Support Center for sanitized diagnostics, support handoff, and update checks. This is the safest place to gather technical context without exposing raw secrets.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Use Support Center</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Reproduce the issue first:</strong> Generate a bundle only after you confirm the problem still exists so the logs and diagnostics are current.</li>
        <li><strong>Check System Status next:</strong> Review writable paths, FFmpeg, GD, logs, and multipart health before exporting anything.</li>
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

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Update Engine</h6>
    <ul class="mb-4">
        <li><strong>Release source:</strong> Fyuhls checks GitHub releases for the configured repository and compares the newest tag against your installed version.</li>
        <li><strong>One-click updater:</strong> Downloads the release zip and updates the app while preserving local state.</li>
        <li><strong>Manual updates still need care:</strong> Preserve <code>config/app.php</code>, <code>config/database.php</code>, <code>storage/</code>, <code>themes/custom/</code>, and <code>src/Plugin/</code> during a manual replacement.</li>
        <li><strong>Backups first:</strong> Always keep a database backup plus file backups before running any update path.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Troubleshooting</h6>
    <ul class="mb-4">
        <li><strong>Cannot connect to GitHub releases?</strong> Verify outbound HTTPS access and firewall rules on the server.</li>
        <li><strong>Update failed?</strong> Review <a href="/admin/status" class="guide-action-link">System Status</a> and application logs first, then restore from backup if the update stopped halfway through.</li>
        <li><strong>Need a bug report?</strong> Use the sanitized JSON export from this page instead of sharing raw logs.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Tip:</strong> The fastest support workflow is usually: reproduce the issue, inspect System Status, then generate a sanitized support bundle from this page.
    </div>
</div>
