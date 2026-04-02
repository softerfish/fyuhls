<div class="p-1">
    <p class="guide-purpose mb-4">Cron Jobs is the background worker system for Fyuhls. It handles cleanup, mail delivery, storage audits, reward processing, multipart upload recovery, remote URL imports, and other scheduled jobs.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Setup</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Copy the command shown on the page:</strong> It points to <code>src/Cron/Run.php</code>.</li>
        <li><strong>Run it every minute:</strong> That is the expected schedule for the built-in heartbeat design.</li>
        <li><strong>Use Trigger All Tasks Now only for testing:</strong> It forces every registered job to run immediately, regardless of its normal schedule.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Reading The Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>System Healthy / Cron Jobs Offline:</strong> The heartbeat card checks whether cron has reported in within the last 31 minutes.</li>
        <li class="mb-2"><strong>Scheduled Cron Jobs:</strong> Shows every managed task, its interval in minutes, last execution time, and last status.</li>
        <li class="mb-2"><strong>Save Frequencies:</strong> Stores the new per-task interval values shown in the table. It does not change your server crontab itself.</li>
        <li><strong>Server Crontab Setup:</strong> Shows the one server command you should schedule every minute. That command wakes the internal task runner.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Common Tasks</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>cleanup:</strong> Deletes expired files, clears old upload chunks, and removes stale temporary data.</li>
        <li class="mb-2"><strong>upload_sessions:</strong> Expires abandoned multipart upload sessions and releases reserved quota.</li>
        <li class="mb-2"><strong>upload_reconcile:</strong> Repairs multipart upload sessions that stalled during state changes.</li>
        <li class="mb-2"><strong>checksum_jobs:</strong> Finishes checksum verification work for completed object-storage uploads.</li>
        <li class="mb-2"><strong>remote_uploads:</strong> Processes queued remote URL imports in the background so users do not have to keep the browser open.</li>
        <li class="mb-2"><strong>file_purge:</strong> Physically deletes files that were already marked for background purge.</li>
        <li class="mb-2"><strong>mail_queue:</strong> Sends outbound emails at the configured rate.</li>
        <li class="mb-2"><strong>storage_audit:</strong> Recalculates storage usage for integrity.</li>
        <li class="mb-2"><strong>server_monitoring:</strong> Checks file-server health and connectivity.</li>
        <li><strong>reward_flush / reward_rollup:</strong> Matter only when Rewards is enabled.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Important:</strong> If cron is not running, deleted files can remain on disk, queued email can stall, multipart reservations can get stuck, remote URL imports will not progress, and object-storage upload state can drift away from the database.
    </div>
</div>
