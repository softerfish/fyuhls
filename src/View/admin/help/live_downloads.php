<div class="small">
    <p class="mb-4">This page shows currently tracked download sessions in near real time. It is mainly useful for fraud review, support debugging, and connection-limit enforcement.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What The Columns Mean</h6>
    <ul class="mb-4">
        <li><strong>File ID / Filename:</strong> The file currently being transferred.</li>
        <li><strong>Downloader IP:</strong> The client IP recorded for that transfer.</li>
        <li><strong>User ID:</strong> The logged-in user if the downloader is authenticated, otherwise the session is shown as a guest.</li>
        <li><strong>Started At / Time Elapsed:</strong> How long the transfer has been active.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Requirements</h6>
    <ul class="mb-4">
        <li><strong>Tracking must be enabled:</strong> Turn on <em>Track Active Download Connections</em> in <a href="/admin/configuration?tab=downloads" class="guide-action-link">Config Hub</a>.</li>
        <li><strong>Cron helps cleanup:</strong> Stale sessions are automatically purged when they age out, so very old phantom rows usually point to cron not running.</li>
    </ul>

    <div class="alert alert-warning border-0">
        <strong>Privacy Note:</strong> Only keep active-download tracking enabled if you need concurrency enforcement, anti-fraud checks, or support diagnostics, because it increases database write activity.
    </div>
</div>
