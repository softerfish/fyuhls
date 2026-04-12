<?php include 'header.php'; ?>

<style>
    .status-overview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    .status-overview-card { padding: 1.5rem; }
    .status-eyebrow {
        color: var(--text-muted);
        font-size: 0.875rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
    }
    .status-value {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--text-color);
    }
    .status-value-lg {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-color);
    }
    .status-card-header {
        font-weight: 600;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border-color);
    }
    .status-card-header-between {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .status-header-btn {
        font-size: 0.75rem;
        padding: 0.35rem 0.85rem;
    }
    .status-card-body { padding: 1.5rem; }
    .status-grid-md {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .status-grid-sm {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
    }
    .status-label {
        color: var(--text-muted);
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 0.35rem;
    }
    .status-metric {
        font-size: 1rem;
        font-weight: 700;
    }
    .status-metric-lg {
        font-size: 1.25rem;
        font-weight: 700;
    }
    .status-warning { color: #b45309; }
    .status-success { color: #166534; }
    .status-danger { color: #b91c1c; }
    .status-muted-copy {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 1rem;
    }
    .status-policy-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1rem;
    }
    .status-policy-card {
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 1rem;
    }
    .status-policy-title {
        font-size: 0.8rem;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    .status-policy-copy {
        font-size: 0.95rem;
        line-height: 1.65;
    }
    .status-table-wrap {
        overflow: auto;
        max-height: 420px;
    }
    .status-empty {
        padding: 1.5rem;
        color: var(--text-muted);
    }
    .status-cell { padding: 0.9rem 1rem; }
    .status-file-cell { max-width: 260px; }
    .status-file-name {
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .status-file-meta,
    .status-subcopy,
    .status-small-copy {
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    .status-small-copy { font-size: 0.875rem; }
    .status-action-btn {
        background: #fee2e2;
        color: #b91c1c;
    }
    .status-progress-wrap { margin-bottom: 1rem; }
    .status-progress-head {
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        margin-bottom: 0.25rem;
    }
    .status-progress-track {
        height: 8px;
        background: #f3f4f6;
        border-radius: 4px;
        overflow: hidden;
    }
    .status-progress-bar { height: 100%; }
    .status-progress-bar--danger { background: #ef4444; }
    .status-progress-bar--normal { background: #3b82f6; }
    .status-progress-note {
        font-size: 0.7rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }
    .status-mini-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        text-align: center;
        background: #f9fafb;
        padding: 1rem;
        border-radius: 8px;
    }
    .status-mini-label {
        font-size: 0.65rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
    }
    .status-log-card { margin-bottom: 2rem; }
    .status-log-size {
        font-size: 0.82rem;
        color: var(--text-muted);
        margin-bottom: 1rem;
    }
    .status-log-pre {
        background: var(--bg-color);
        padding: 1rem;
        border-radius: 8px;
        font-size: 0.875rem;
        color: var(--text-color);
        border: 1px solid var(--border-color);
        white-space: pre-wrap;
        word-wrap: break-word;
        max-height: 320px;
        overflow: auto;
    }
    .status-log-feed {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        max-height: 520px;
        overflow-y: auto;
    }
    .status-log-entry {
        background: var(--bg-color);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 0.9rem 1rem;
    }
    .status-log-head {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
        margin-bottom: 0.45rem;
    }
    .status-log-level {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .status-log-time {
        font-size: 0.78rem;
        color: var(--text-muted);
    }
    .status-log-message {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-color);
    }
    .status-log-context {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .status-log-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 999px;
        padding: 0.3rem 0.55rem;
        font-size: 0.78rem;
        color: var(--text-color);
    }
    .status-log-key { font-weight: 700; }
    .status-host-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 2rem;
    }
    .status-host-copy { line-height: 2; }
    .status-support-copy {
        margin: 0 0 0.75rem 0;
        color: var(--text-color);
        font-size: 1rem;
        font-weight: 400;
    }
    .status-log-card { margin-bottom: 2rem; }
</style>

<div class="page-header">
    <h1>System Status</h1>
</div>

<div class="status-overview-grid">
    <div class="card status-overview-card">
        <div class="status-eyebrow">Uploads Path</div>
        <div class="status-value"><?= $writable === 'ok' ? 'Writable' : 'Not Writable' ?></div>
    </div>
    <div class="card status-overview-card">
        <div class="status-eyebrow">Image Thumbnails</div>
        <div class="status-value"><?= $gdOk ? 'GD Installed' : 'GD Missing' ?></div>
    </div>
    <div class="card status-overview-card">
        <div class="status-eyebrow">Video Thumbnails</div>
        <div class="status-value"><?= $ffmpegOk ? 'FFmpeg Ready' : 'Not Configured' ?></div>
    </div>
    <div class="card status-overview-card">
        <div class="status-eyebrow">Rate Limit Blocks</div>
        <div class="status-value-lg"><?= (int)$blocked ?></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header status-card-header status-card-header-between">
        <span>Application Updates</span>
        <a href="/admin/status?refresh_update=1" class="btn btn-sm status-header-btn">Refresh</a>
    </div>
    <div class="card-body status-card-body">
        <div class="status-grid-md">
            <div>
                <div class="status-label">Installed</div>
                <div class="status-metric"><?= htmlspecialchars($updateStatus['current_version'] ?? 'unknown') ?></div>
            </div>
            <div>
                <div class="status-label">Latest Release</div>
                <div class="status-metric"><?= htmlspecialchars($updateStatus['latest_version'] ?? 'Unavailable') ?></div>
            </div>
            <div>
                <div class="status-label">Status</div>
                <div class="status-metric <?= !empty($updateStatus['update_available']) ? 'status-warning' : 'status-success' ?>">
                    <?= !empty($updateStatus['update_available']) ? 'Update Available' : 'Up To Date' ?>
                </div>
            </div>
        </div>

        <?php if (!empty($updateStatus['error'])): ?>
            <div class="alert alert-warning mb-3" role="alert">
                <?= htmlspecialchars($updateStatus['error']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($updateStatus['repo'])): ?>
            <div class="status-muted-copy">
                Source repo: <code><?= htmlspecialchars($updateStatus['repo']) ?></code>
            </div>
        <?php endif; ?>

        <?php if (!empty($updateStatus['update_available']) && empty($updateStatus['error'])): ?>
            <div class="alert alert-info mb-3" role="alert">
                The updater preserves local config files, <code>storage/</code>, <code>themes/custom/</code>, and <code>src/Plugin/</code> while applying the latest release package.
            </div>
            <form method="POST" action="/admin/update/apply" data-confirm-message="Download and apply the latest GitHub release now?">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-primary">Install Update</button>
                <?php if (!empty($updateStatus['release_url'])): ?>
                    <a href="<?= htmlspecialchars($updateStatus['release_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary ms-2">View Release</a>
                <?php endif; ?>
            </form>
        <?php elseif (empty($updateStatus['repo_configured'])): ?>
            <div class="alert alert-secondary mb-0" role="alert">
                Set <code>update.github_repo</code> in <code>config/version.php</code> to enable one-click release checks.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header status-card-header">Multipart Upload Health</div>
    <div class="card-body status-card-body">
        <div class="status-grid-sm">
            <div>
                <div class="status-label">Active Sessions</div>
                <div class="status-metric-lg"><?= (int)($uploadStats['active_sessions'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Stale Sessions</div>
                <div class="status-metric-lg <?= !empty($uploadStats['stale_sessions']) ? 'status-danger' : '' ?>"><?= (int)($uploadStats['stale_sessions'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Failed Sessions</div>
                <div class="status-metric-lg <?= !empty($uploadStats['failed_sessions']) ? 'status-danger' : '' ?>"><?= (int)($uploadStats['failed_sessions'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Active Reservations</div>
                <div class="status-metric-lg"><?= (int)($uploadStats['active_reservations'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Reserved Capacity</div>
                <div class="status-metric-lg"><?= \App\Service\FileProcessor::formatSize((int)($uploadStats['reserved_bytes'] ?? 0)) ?></div>
            </div>
            <div>
                <div class="status-label">Stuck Completing</div>
                <div class="status-metric-lg <?= !empty($uploadStats['stuck_completing']) ? 'status-danger' : '' ?>"><?= (int)($uploadStats['stuck_completing'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Checksum Backlog</div>
                <div class="status-metric-lg <?= !empty($uploadStats['checksum_backlog']) ? 'status-warning' : '' ?>"><?= (int)($uploadStats['checksum_backlog'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Expired Reservations</div>
                <div class="status-metric-lg <?= !empty($uploadStats['expired_reservations']) ? 'status-danger' : '' ?>"><?= (int)($uploadStats['expired_reservations'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header status-card-header">Download Delivery Diagnostics</div>
    <div class="card-body status-card-body">
        <div class="status-grid-sm mb-3">
            <div>
                <div class="status-label">CDN Eligible Public Files</div>
                <div class="status-metric-lg"><?= (int)($deliveryStats['cdn_eligible_files'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Signed Origin Files</div>
                <div class="status-metric-lg"><?= (int)($deliveryStats['signed_origin_files'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">App-Controlled Files</div>
                <div class="status-metric-lg"><?= (int)($deliveryStats['app_controlled_files'] ?? 0) ?></div>
            </div>
            <div>
                <div class="status-label">Private Object Files</div>
                <div class="status-metric-lg"><?= (int)($deliveryStats['private_object_files'] ?? 0) ?></div>
            </div>
        </div>

        <div class="status-policy-grid">
            <div class="status-policy-card">
                <div class="status-policy-title">Current Policy</div>
                <div class="status-policy-copy">
                    CDN redirects: <strong><?= !empty($deliveryStats['cdn_enabled']) ? 'Enabled' : 'Disabled' ?></strong><br>
                    CDN base URL: <strong><?= !empty($deliveryStats['cdn_base_configured']) ? 'Configured' : 'Missing' ?></strong><br>
                    PPD progress threshold: <strong><?= (int)($deliveryStats['ppd_progress_tracking'] ?? 0) ?>%</strong>
                </div>
            </div>
            <div class="status-policy-card">
                <div class="status-policy-title">Interpretation</div>
                <div class="status-policy-copy">
                    <?php if (($deliveryStats['ppd_progress_tracking'] ?? 0) > 0): ?>
                        Verified completion tracking is enabled, so downloads stay on the app-controlled delivery path instead of CDN or signed-origin redirects.
                    <?php elseif (!empty($deliveryStats['cdn_enabled']) && !empty($deliveryStats['cdn_base_configured'])): ?>
                        Public object-storage files can use CDN redirects. Private object-storage files stay on signed origin links, and local files stay on app-controlled delivery.
                    <?php else: ?>
                        Object-storage files use signed origin redirects when possible. Local files and fallback paths remain app-controlled.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header status-card-header">Recent Multipart Sessions</div>
    <div class="card-body p-0">
        <?php if (empty($recentUploadSessions)): ?>
            <div class="status-empty">No multipart upload sessions recorded yet.</div>
        <?php else: ?>
            <div class="status-table-wrap">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="status-cell">Session</th>
                            <th class="status-cell">User</th>
                            <th class="status-cell">File</th>
                            <th class="status-cell">Progress</th>
                            <th class="status-cell">Status</th>
                            <th class="status-cell">Updated</th>
                            <th class="status-cell">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUploadSessions as $session): ?>
                            <?php
                            $status = $session['status'] ?? 'unknown';
                            $statusClass = in_array($status, ['failed', 'expired'], true) ? 'status-metric--danger' : (in_array($status, ['completed'], true) ? 'status-metric--success' : 'status-metric--warning');
                            $isAbortable = in_array($status, ['pending', 'uploading', 'completing', 'processing'], true);
                            ?>
                            <tr>
                                <td class="status-cell"><code><?= htmlspecialchars($session['public_id']) ?></code></td>
                                <td class="status-cell"><?= htmlspecialchars($session['username'] ?: ('User #' . (int)$session['user_id'])) ?></td>
                                <td class="status-cell status-file-cell">
                                    <div class="status-file-name"><?= htmlspecialchars($session['original_filename'] ?? 'Unknown') ?></div>
                                    <div class="status-file-meta"><?= htmlspecialchars($session['storage_provider'] ?? 'unknown') ?> &middot; <?= \App\Service\FileProcessor::formatSize((int)($session['expected_size'] ?? 0)) ?></div>
                                </td>
                                <td class="status-cell">
                                    <div class="status-file-name"><?= \App\Service\FileProcessor::formatSize((int)($session['uploaded_bytes'] ?? 0)) ?> / <?= \App\Service\FileProcessor::formatSize((int)($session['expected_size'] ?? 0)) ?></div>
                                    <div class="status-file-meta"><?= (int)($session['completed_parts'] ?? 0) ?> parts reported</div>
                                </td>
                                <td class="status-cell">
                                    <div class="status-metric <?= $statusClass ?>"><?= htmlspecialchars($status) ?></div>
                                    <?php if (!empty($session['error_message'])): ?>
                                        <div class="status-file-meta status-danger"><?= htmlspecialchars($session['error_message']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="status-cell status-small-copy">
                                    <?= !empty($session['updated_at']) ? date('M j, H:i', strtotime($session['updated_at'])) : 'Never' ?>
                                    <?php if (!empty($session['expires_at'])): ?>
                                        <div class="status-subcopy">Expires <?= date('M j, H:i', strtotime($session['expires_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="status-cell">
                                    <?php if ($isAbortable): ?>
                                        <form method="POST" action="/admin/uploads/session/abort" data-confirm-message="Abort upload session <?= htmlspecialchars($session['public_id']) ?>?">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="session_id" value="<?= htmlspecialchars($session['public_id']) ?>">
                                            <button type="submit" class="btn btn-sm status-action-btn">Abort</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="status-subcopy">No action</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header status-card-header">Recent Quota Reservations</div>
    <div class="card-body p-0">
        <?php if (empty($recentReservations)): ?>
            <div class="status-empty">No quota reservations recorded yet.</div>
        <?php else: ?>
            <div class="status-table-wrap">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="status-cell">Reservation</th>
                            <th class="status-cell">User</th>
                            <th class="status-cell">Upload Session</th>
                            <th class="status-cell">Reserved</th>
                            <th class="status-cell">Status</th>
                            <th class="status-cell">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReservations as $reservation): ?>
                            <tr>
                                <td class="status-cell"><code><?= htmlspecialchars($reservation['public_id']) ?></code></td>
                                <td class="status-cell"><?= htmlspecialchars($reservation['username'] ?: ('User #' . (int)$reservation['user_id'])) ?></td>
                                <td class="status-cell"><?= !empty($reservation['upload_public_id']) ? '<code>' . htmlspecialchars($reservation['upload_public_id']) . '</code>' : '<span class="status-muted-copy">Detached</span>' ?></td>
                                <td class="status-cell status-file-name"><?= \App\Service\FileProcessor::formatSize((int)($reservation['reserved_bytes'] ?? 0)) ?></td>
                                <td class="status-cell"><?= htmlspecialchars($reservation['status'] ?? 'unknown') ?></td>
                                <td class="status-cell status-small-copy">
                                    <?= !empty($reservation['created_at']) ? date('M j, H:i', strtotime($reservation['created_at'])) : 'Unknown' ?>
                                    <?php if (!empty($reservation['expires_at'])): ?>
                                        <div class="status-subcopy">Expires <?= date('M j, H:i', strtotime($reservation['expires_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header status-card-header">Host Environment</div>
    <div class="card-body status-card-body">
        <div class="status-host-grid">
            <div>
                <div class="status-label mb-3">System Specs</div>
                <div class="status-small-copy status-host-copy">
                    <strong>OS:</strong> <?= $metrics['os'] ?><br>
                    <strong>Web Server:</strong> <?= $metrics['server_software'] ?><br>
                    <strong>PHP Version:</strong> <?= $metrics['php_version'] ?>
                </div>
            </div>
            <div>
                <div class="status-label mb-3">Resource Usage</div>

                <div class="status-progress-wrap">
                    <div class="status-progress-head">
                        <span>Disk Usage</span>
                        <span><?= $metrics['disk']['percent'] ?>%</span>
                    </div>
                    <div class="status-progress-track">
                        <div class="status-progress-bar js-status-progress <?= $metrics['disk']['percent'] > 90 ? 'status-progress-bar--danger' : 'status-progress-bar--normal' ?>" data-progress="<?= htmlspecialchars((string)$metrics['disk']['percent']) ?>"></div>
                    </div>
                    <div class="status-progress-note">
                        <?= $metrics['disk']['readable_used'] ?> used of <?= $metrics['disk']['readable_total'] ?>
                    </div>
                </div>

                <div class="status-mini-grid">
                    <div>
                        <div class="status-mini-label">CPU Load</div>
                        <div class="status-metric"><?= $metrics['cpu'] ?? 'N/A' ?></div>
                    </div>
                    <div>
                        <div class="status-mini-label">RAM Usage</div>
                        <div class="status-metric"><?= $metrics['ram']['percent'] ?? 'N/A' ?><?= isset($metrics['ram']['percent']) ? '%' : '' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header status-card-header status-card-header-between">
        <span>Support Bundle</span>
        <?php if (empty($demoAdmin)): ?>
            <a href="/admin/support" class="btn btn-sm status-header-btn">Open Support Center</a>
        <?php endif; ?>
    </div>
    <div class="card-body status-card-body">
        <p class="status-support-copy">
            <?= !empty($demoAdmin)
                ? 'Support bundle tools are hidden for the demo admin account.'
                : 'Generate a sanitized diagnostics bundle for bug reports. It strips secrets and masks sensitive values before export, and the download is a plain <code>.json</code> file.' ?>
        </p>
        <div class="status-muted-copy mb-0">
            Email target: <strong><?= htmlspecialchars($supportEmail) ?></strong><br>
            SMTP status: <?= $smtpConfigured ? 'Configured for direct send' : 'Not configured, download-only mode' ?>
        </div>
    </div>
</div>

<?php if (!$gdOk || !$ffmpegOk): ?>
<div class="alert alert-warning mb-4">
    <h5 class="alert-heading">System Configuration Warnings</h5>
    <ul class="mb-0">
        <?php if (!$gdOk): ?>
            <li>Install the PHP GD extension for image thumbnails.</li>
        <?php endif; ?>
        <?php if (!$ffmpegOk): ?>
            <li>Set <code>video.ffmpeg_path</code> to your server's FFmpeg binary.</li>
        <?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card status-log-card">
    <div class="card-header status-card-header">Recent System Errors</div>
    <div class="card-body status-card-body">
        <div class="status-log-size">
            Current log size: <strong><?= htmlspecialchars((string)($logSizeReadable ?? '0 B')) ?></strong> / <?= htmlspecialchars((string)($logMaxReadable ?? '25 MB')) ?> cap.
        </div>
        <pre class="status-log-pre"><?php if (empty($errors)): ?>(no recent errors)<?php else: foreach ($errors as $line): ?><?= htmlspecialchars($line) ?><?php endforeach; endif; ?></pre>
    </div>
</div>

<div class="card">
    <div class="card-header status-card-header status-card-header-between">
        <span>Application Logs</span>
        <?php if (empty($demoAdmin)): ?>
            <form method="POST" action="/admin/logs/clear" data-confirm-message="Permanently clear all application logs?">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="redirect" value="/admin/status">
                <button type="submit" class="btn btn-sm status-action-btn">Clear Logs</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body status-card-body">
        <div class="status-muted-copy">
            <?= !empty($demoAdmin)
                ? 'Recent entries are shown in a redacted format for the demo admin account.'
                : 'Recent entries are shown in a readable format below. Raw JSON is still included for anything Fyuhls cannot parse cleanly.' ?>
        </div>
        <div class="status-log-size">
            Current log size: <strong><?= htmlspecialchars((string)($logSizeReadable ?? '0 B')) ?></strong> / <?= htmlspecialchars((string)($logMaxReadable ?? '25 MB')) ?> cap.
        </div>
        <div class="status-log-feed">
            <?php foreach (($formattedLogs ?? []) as $entry): ?>
                <?php
                $level = strtolower((string)($entry['level'] ?? 'info'));
                $accentClass = $level === 'error' ? 'status-log-entry--error' : ($level === 'warning' ? 'status-log-entry--warning' : 'status-log-entry--info');
                $timestamp = !empty($entry['timestamp']) ? date('M j, Y H:i:s', strtotime($entry['timestamp'])) : 'Unknown time';
                $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
                ?>
                <div class="status-log-entry <?= $accentClass ?>">
                    <div class="status-log-head">
                        <span class="status-log-level"><?= htmlspecialchars($level) ?></span>
                        <span class="status-log-time"><?= htmlspecialchars($timestamp) ?></span>
                    </div>
                    <div class="status-log-message <?= !empty($context) ? 'status-log-message--with-context' : '' ?>">
                        <?= htmlspecialchars((string)($entry['message'] ?? 'Log entry')) ?>
                    </div>
                    <?php if (!empty($context)): ?>
                        <div class="status-log-context">
                            <?php foreach ($context as $key => $value): ?>
                                <span class="status-log-pill">
                                    <strong class="status-log-key"><?= htmlspecialchars((string)$key) ?>:</strong>
                                    <span><?= htmlspecialchars(is_scalar($value) || $value === null ? (string)$value : json_encode($value)) ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($formattedLogs)): ?>
                <div class="status-muted-copy mb-0">No application log entries recorded yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-status-progress').forEach(function(bar) {
        const progress = parseFloat(bar.getAttribute('data-progress') || '0');
        const safeProgress = Number.isFinite(progress) ? Math.min(100, Math.max(0, progress)) : 0;
        bar.style.width = safeProgress + '%';
    });
});
</script>

<?php include 'footer.php'; ?>
