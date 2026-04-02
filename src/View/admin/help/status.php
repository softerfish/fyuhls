<div class="small">
    <p class="mb-4">System Status is your technical health page. Use it when installs fail, thumbnails stop generating, logs need review, or you need a sanitized bundle for support.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What To Check First</h6>
    <ul class="mb-4">
        <li><strong>Uploads Path:</strong> Confirms the main <code>storage/uploads/</code> area is writable for local-file operations.</li>
        <li><strong>Image Thumbnails:</strong> Confirms the PHP GD extension is available.</li>
        <li><strong>Video Thumbnails:</strong> Confirms FFmpeg is enabled and the configured binary path exists.</li>
        <li><strong>Rate Limit Blocks:</strong> Shows how many download rate-limit entries crossed the block threshold in the current window.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Logs And Support</h6>
    <ul class="mb-4">
        <li><strong>Recent System Errors:</strong> Shows the last error-level entries pulled from the application log.</li>
        <li><strong>Application Logs:</strong> Shows recent log entries in a readable format with timestamp, level, message, and key details, plus a clear button for maintenance.</li>
        <li><strong>Support Bundle:</strong> Opens the support center so you can download a sanitized diagnostics JSON file or email that same JSON bundle.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Upload And Delivery Diagnostics</h6>
    <ul class="mb-4">
        <li><strong>Multipart Upload Health:</strong> Watch stale sessions, stuck completions, checksum backlog, and expired reservations before they turn into quota or metadata drift.</li>
        <li><strong>Download Delivery Diagnostics:</strong> Shows whether the site is currently favoring CDN redirects, signed-origin object storage, or app-controlled delivery.</li>
        <li><strong>Recent Multipart Sessions:</strong> Use this table to inspect failures and manually abort sessions that are clearly stuck.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Tip:</strong> If this page shows healthy basics but users still report a bug, generate a support bundle from the Support Center instead of sharing raw logs.
    </div>
</div>
