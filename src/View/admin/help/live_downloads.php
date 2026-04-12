<div class="small">
    <p class="mb-4">Live Downloads shows currently tracked download sessions in near real time. Use it for concurrency enforcement, fraud review, and support debugging while a transfer is actually happening.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Use The Page</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Confirm tracking is enabled:</strong> If the page is empty when it should not be, verify tracking is turned on in <a href="/admin/configuration?tab=downloads" class="guide-action-link">Downloads</a>.</li>
        <li><strong>Match the session to a user or guest:</strong> This helps you tell whether one person, one IP, or many visitors are responsible for a spike.</li>
        <li><strong>Compare with package rules:</strong> When users complain about concurrent-download limits, check what is active here against the package they actually have.</li>
        <li><strong>Use it during incidents:</strong> This page is most useful while the problem is live, not hours later after cleanup has removed the stale rows.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What The Columns Mean</h6>
    <ul class="mb-4">
        <li><strong>File ID / Filename:</strong> The file currently being transferred.</li>
        <li><strong>Downloader IP:</strong> The client IP recorded for that transfer.</li>
        <li><strong>User ID:</strong> The logged-in user if the downloader is authenticated, otherwise the session is shown as a guest.</li>
        <li><strong>Started At / Time Elapsed:</strong> How long the transfer has been active.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Requirements And Caveats</h6>
    <ul class="mb-4">
        <li><strong>Tracking must be enabled:</strong> Turn on <em>Track Active Download Connections</em> in Config Hub.</li>
        <li><strong>Cron helps cleanup:</strong> Stale sessions are purged when they age out, so very old phantom rows usually mean cron is not healthy.</li>
        <li><strong>Privacy tradeoff:</strong> Tracking helps support and fraud review, but it adds database write activity and connection metadata retention.</li>
    </ul>

    <div class="alert alert-warning border-0">
        <strong>Privacy Note:</strong> Keep active-download tracking enabled only when you need concurrency enforcement, anti-fraud checks, or support diagnostics.
    </div>
</div>
