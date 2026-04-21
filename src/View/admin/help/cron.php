<div class="p-1">
    <p class="guide-purpose mb-4">Cron Jobs is the heartbeat system for Fyuhls. One server cron entry wakes the internal scheduler every minute, and Fyuhls decides which jobs are due.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Setup</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Copy the command shown on the page:</strong> It points to <code>src/Cron/Run.php</code> inside your live install.</li>
        <li><strong>Run it every minute:</strong> That is the expected schedule. The one-minute crontab does not mean every internal task runs every minute.</li>
        <li><strong>Use Trigger All Tasks Now only for testing:</strong> It forces registered jobs to run immediately, but it does not replace a real cron entry.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Reading The Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>System Healthy / Cron Jobs Offline:</strong> This is the heartbeat health view. If it is stale, queued work across the app will drift.</li>
        <li class="mb-2"><strong>Scheduled Cron Jobs:</strong> Shows each managed task, its interval, last execution time, runtime, and last recorded status.</li>
        <li class="mb-2"><strong>Never:</strong> Means no successful run has been recorded yet. For expected core or enabled-feature tasks, that is a real troubleshooting signal.</li>
        <li><strong>Save Frequencies:</strong> Stores the new internal task intervals. It does not rewrite your server crontab.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Common Task Groups</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>cleanup / upload_sessions / upload_reconcile / checksum_jobs:</strong> Keep multipart uploads, temp state, and quota reservations healthy.</li>
        <li class="mb-2"><strong>remote_uploads / mail_queue:</strong> Background imports and outbound email.</li>
        <li class="mb-2"><strong>storage_audit / server_monitoring / refresh_stats:</strong> Capacity, node health, and dashboard/stat freshness.</li>
        <li><strong>reward_flush / reward_rollup / fraud_cleanup / fraud_scores / fraud_clearance:</strong> Rewards-only tasks. If they still show <code>Never</code> on a live rewards install, verify the cron path is pointing at the current install and runner.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Important:</strong> If cron is unhealthy, remote URL imports, mail delivery, multipart cleanup, rewards jobs, stats refresh, and other background features can all look unrelated but start failing together.
    </div>
</div>
