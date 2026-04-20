<div class="small">
    <p class="mb-4">Server Monitoring tracks host health and storage-node health over time so you can react before uploads fail, nodes fill up, or remote storage starts timing out.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Use This Page</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Check the main web server first:</strong> Watch CPU, RAM, and disk pressure on the host actually running Fyuhls.</li>
        <li><strong>Review each storage node:</strong> Confirm remote nodes still respond and have enough free space for expected upload growth.</li>
        <li><strong>Act before 100%:</strong> If a node is trending high, mark it read-only, add capacity, or migrate files before uploads start failing.</li>
        <li><strong>Cross-check Storage Nodes:</strong> Use <a href="/admin/configuration?tab=storage" class="guide-action-link">Storage Nodes</a> when a graph or alert points to a specific server that needs a status or capacity change.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What The Signals Mean</h6>
    <ul class="mb-4">
        <li><strong>CPU:</strong> Sustained high CPU can mean thumbnail generation, overloaded workers, busy downloads, or storage retry loops.</li>
        <li><strong>RAM:</strong> High memory usage can point to worker pressure, aggressive caching, or background jobs competing with traffic.</li>
        <li><strong>Disk usage:</strong> The main host still needs free space for logs, temp files, local uploads, and queue processing even when most files live off-box.</li>
        <li><strong>Node responsiveness:</strong> Slow or missing remote node checks often explain migration failures, upload errors, or delayed cleanup work.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Troubleshooting</h6>
    <ul class="mb-4">
        <li><strong>Monitoring is blank?</strong> Verify cron is healthy and that the app can reach the monitored nodes from the main server.</li>
        <li><strong>Wrong disk size?</strong> Compare with the host dashboard. Large logs, backups, temp files, or other apps can explain the difference.</li>
        <li><strong>Node says full but still receives uploads?</strong> Re-check the node status, default flag, and capacity fields on the Storage Nodes page.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Automated Health:</strong> Monitoring depends on the background worker cycle. If the charts look frozen, confirm <a href="/admin/configuration?tab=cron" class="guide-action-link">Cron Jobs</a> are healthy before assuming the servers stopped reporting.
    </div>
</div>
