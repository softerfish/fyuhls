<div class="small">
    <p class="mb-4">Use Support Center for sanitized diagnostics, support contact workflow, and one-click update checks.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">1. Sanitized Support Bundles</h6>
    <ul class="mb-4">
        <li><strong>Download Sanitized JSON:</strong> Exports a plain <code>.json</code> support bundle, not a zip archive.</li>
        <li><strong>Email Support Bundle:</strong> Sends the same sanitized JSON bundle directly when SMTP is configured and you approve the share.</li>
        <li><strong>What is included:</strong> Version info, environment checks, plugin summary, sanitized config snapshot, and recent sanitized logs.</li>
        <li><strong>What is redacted:</strong> Secrets, tokens, encryption material, IP addresses, email addresses, and sensitive absolute paths.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">2. Update Engine</h6>
    <ul class="mb-4">
        <li><strong>Release source:</strong> Fyuhls checks GitHub releases for the pinned repository and compares the latest release tag against your installed version.</li>
        <li><strong>One-click updater:</strong> Downloads the latest release zip and updates the codebase while preserving your configured local state.</li>
        <li><strong>What to back up first:</strong> Keep current backups of <code>config/</code>, your database, and any customized themes or local storage paths before updating.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">3. Troubleshooting</h6>
    <ul class="mb-4">
        <li><strong>Cannot connect to GitHub releases?</strong> Make sure the server can reach GitHub over outbound HTTPS and that your firewall is not blocking the request.</li>
        <li><strong>Update failed?</strong> Review System Status and logs first, then restore from backup if the update did not finish cleanly.</li>
        <li><strong>Need a bug report?</strong> Use the sanitized JSON export from this page instead of raw logs so support tools and agents can read it directly.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Tip:</strong> The quickest support path is usually: reproduce the issue, open System Status, then generate a sanitized JSON bundle from this page.
    </div>
</div>
