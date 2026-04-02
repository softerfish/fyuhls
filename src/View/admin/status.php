<?php include 'header.php'; ?>

<div class="page-header">
    <h1>System Status</h1>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <div class="card" style="padding: 1.5rem;">
        <div style="color: var(--text-muted); font-size: 0.875rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Uploads Path</div>
        <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-color);"><?= $writable === 'ok' ? 'Writable' : 'Not Writable' ?></div>
    </div>
    <div class="card" style="padding: 1.5rem;">
        <div style="color: var(--text-muted); font-size: 0.875rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Image Thumbnails</div>
        <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-color);"><?= $gdOk ? 'GD Installed' : 'GD Missing' ?></div>
    </div>
    <div class="card" style="padding: 1.5rem;">
        <div style="color: var(--text-muted); font-size: 0.875rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Video Thumbnails</div>
        <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-color);"><?= $ffmpegOk ? 'FFmpeg Ready' : 'Not Configured' ?></div>
    </div>
    <div class="card" style="padding: 1.5rem;">
        <div style="color: var(--text-muted); font-size: 0.875rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Rate Limit Blocks</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-color);"><?= (int)$blocked ?></div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span>Application Updates</span>
        <a href="/admin/status?refresh_update=1" class="btn btn-sm" style="font-size: 0.75rem; padding: 0.35rem 0.85rem;">Refresh</a>
    </div>
    <div class="card-body" style="padding: 1.5rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Installed</div>
                <div style="font-size: 1rem; font-weight: 700;"><?= htmlspecialchars($updateStatus['current_version'] ?? 'unknown') ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Latest Release</div>
                <div style="font-size: 1rem; font-weight: 700;"><?= htmlspecialchars($updateStatus['latest_version'] ?? 'Unavailable') ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Status</div>
                <div style="font-size: 1rem; font-weight: 700; color: <?= !empty($updateStatus['update_available']) ? '#b45309' : '#166534' ?>;">
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
            <div style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1rem;">
                Source repo: <code><?= htmlspecialchars($updateStatus['repo']) ?></code>
            </div>
        <?php endif; ?>

        <?php if (!empty($updateStatus['update_available']) && empty($updateStatus['error'])): ?>
            <div class="alert alert-info mb-3" role="alert">
                The updater preserves local config files, <code>storage/</code>, <code>themes/custom/</code>, and <code>src/Plugin/</code> while applying the latest release package.
            </div>
            <form method="POST" action="/admin/update/apply" onsubmit="return confirm('Download and apply the latest GitHub release now?');">
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
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Multipart Upload Health</div>
    <div class="card-body" style="padding: 1.5rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Active Sessions</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= (int)($uploadStats['active_sessions'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Stale Sessions</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: <?= !empty($uploadStats['stale_sessions']) ? '#b91c1c' : 'var(--text-color)' ?>;"><?= (int)($uploadStats['stale_sessions'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Failed Sessions</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: <?= !empty($uploadStats['failed_sessions']) ? '#b91c1c' : 'var(--text-color)' ?>;"><?= (int)($uploadStats['failed_sessions'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Active Reservations</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= (int)($uploadStats['active_reservations'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Reserved Capacity</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= \App\Service\FileProcessor::formatSize((int)($uploadStats['reserved_bytes'] ?? 0)) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Stuck Completing</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: <?= !empty($uploadStats['stuck_completing']) ? '#b91c1c' : 'var(--text-color)' ?>;"><?= (int)($uploadStats['stuck_completing'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Checksum Backlog</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: <?= !empty($uploadStats['checksum_backlog']) ? '#92400e' : 'var(--text-color)' ?>;"><?= (int)($uploadStats['checksum_backlog'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Expired Reservations</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: <?= !empty($uploadStats['expired_reservations']) ? '#b91c1c' : 'var(--text-color)' ?>;"><?= (int)($uploadStats['expired_reservations'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Download Delivery Diagnostics</div>
    <div class="card-body" style="padding: 1.5rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">CDN Eligible Public Files</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= (int)($deliveryStats['cdn_eligible_files'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Signed Origin Files</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= (int)($deliveryStats['signed_origin_files'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">App-Controlled Files</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= (int)($deliveryStats['app_controlled_files'] ?? 0) ?></div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 0.35rem;">Private Object Files</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= (int)($deliveryStats['private_object_files'] ?? 0) ?></div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem;">
            <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem;">
                <div style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; margin-bottom: 0.5rem;">Current Policy</div>
                <div style="font-size: 0.95rem; line-height: 1.65;">
                    CDN redirects: <strong><?= !empty($deliveryStats['cdn_enabled']) ? 'Enabled' : 'Disabled' ?></strong><br>
                    CDN base URL: <strong><?= !empty($deliveryStats['cdn_base_configured']) ? 'Configured' : 'Missing' ?></strong><br>
                    PPD progress threshold: <strong><?= (int)($deliveryStats['ppd_progress_tracking'] ?? 0) ?>%</strong>
                </div>
            </div>
            <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem;">
                <div style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700; margin-bottom: 0.5rem;">Interpretation</div>
                <div style="font-size: 0.95rem; line-height: 1.65; color: var(--text-color);">
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
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Recent Multipart Sessions</div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($recentUploadSessions)): ?>
            <div style="padding: 1.5rem; color: var(--text-muted);">No multipart upload sessions recorded yet.</div>
        <?php else: ?>
            <div style="overflow: auto; max-height: 420px;">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="padding: 0.9rem 1rem;">Session</th>
                            <th style="padding: 0.9rem 1rem;">User</th>
                            <th style="padding: 0.9rem 1rem;">File</th>
                            <th style="padding: 0.9rem 1rem;">Progress</th>
                            <th style="padding: 0.9rem 1rem;">Status</th>
                            <th style="padding: 0.9rem 1rem;">Updated</th>
                            <th style="padding: 0.9rem 1rem;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUploadSessions as $session): ?>
                            <?php
                            $status = $session['status'] ?? 'unknown';
                            $statusColor = in_array($status, ['failed', 'expired'], true) ? '#b91c1c' : (in_array($status, ['completed'], true) ? '#166534' : '#92400e');
                            $isAbortable = in_array($status, ['pending', 'uploading', 'completing', 'processing'], true);
                            ?>
                            <tr>
                                <td style="padding: 0.9rem 1rem;"><code><?= htmlspecialchars($session['public_id']) ?></code></td>
                                <td style="padding: 0.9rem 1rem;"><?= htmlspecialchars($session['username'] ?: ('User #' . (int)$session['user_id'])) ?></td>
                                <td style="padding: 0.9rem 1rem; max-width: 260px;">
                                    <div style="font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($session['original_filename'] ?? 'Unknown') ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($session['storage_provider'] ?? 'unknown') ?> &middot; <?= \App\Service\FileProcessor::formatSize((int)($session['expected_size'] ?? 0)) ?></div>
                                </td>
                                <td style="padding: 0.9rem 1rem;">
                                    <div style="font-weight: 600;"><?= \App\Service\FileProcessor::formatSize((int)($session['uploaded_bytes'] ?? 0)) ?> / <?= \App\Service\FileProcessor::formatSize((int)($session['expected_size'] ?? 0)) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= (int)($session['completed_parts'] ?? 0) ?> parts reported</div>
                                </td>
                                <td style="padding: 0.9rem 1rem;">
                                    <div style="font-weight: 700; color: <?= $statusColor ?>;"><?= htmlspecialchars($status) ?></div>
                                    <?php if (!empty($session['error_message'])): ?>
                                        <div style="font-size: 0.75rem; color: #b91c1c; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($session['error_message']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.9rem 1rem; font-size: 0.875rem; color: var(--text-muted);">
                                    <?= !empty($session['updated_at']) ? date('M j, H:i', strtotime($session['updated_at'])) : 'Never' ?>
                                    <?php if (!empty($session['expires_at'])): ?>
                                        <div style="font-size: 0.75rem;">Expires <?= date('M j, H:i', strtotime($session['expires_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.9rem 1rem;">
                                    <?php if ($isAbortable): ?>
                                        <form method="POST" action="/admin/uploads/session/abort" onsubmit="return confirm('Abort upload session <?= htmlspecialchars($session['public_id']) ?>?');">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="session_id" value="<?= htmlspecialchars($session['public_id']) ?>">
                                            <button type="submit" class="btn btn-sm" style="background: #fee2e2; color: #b91c1c;">Abort</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">No action</span>
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
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Recent Quota Reservations</div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($recentReservations)): ?>
            <div style="padding: 1.5rem; color: var(--text-muted);">No quota reservations recorded yet.</div>
        <?php else: ?>
            <div style="overflow: auto; max-height: 420px;">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="padding: 0.9rem 1rem;">Reservation</th>
                            <th style="padding: 0.9rem 1rem;">User</th>
                            <th style="padding: 0.9rem 1rem;">Upload Session</th>
                            <th style="padding: 0.9rem 1rem;">Reserved</th>
                            <th style="padding: 0.9rem 1rem;">Status</th>
                            <th style="padding: 0.9rem 1rem;">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReservations as $reservation): ?>
                            <tr>
                                <td style="padding: 0.9rem 1rem;"><code><?= htmlspecialchars($reservation['public_id']) ?></code></td>
                                <td style="padding: 0.9rem 1rem;"><?= htmlspecialchars($reservation['username'] ?: ('User #' . (int)$reservation['user_id'])) ?></td>
                                <td style="padding: 0.9rem 1rem;"><?= !empty($reservation['upload_public_id']) ? '<code>' . htmlspecialchars($reservation['upload_public_id']) . '</code>' : '<span style="color: var(--text-muted);">Detached</span>' ?></td>
                                <td style="padding: 0.9rem 1rem; font-weight: 600;"><?= \App\Service\FileProcessor::formatSize((int)($reservation['reserved_bytes'] ?? 0)) ?></td>
                                <td style="padding: 0.9rem 1rem;"><?= htmlspecialchars($reservation['status'] ?? 'unknown') ?></td>
                                <td style="padding: 0.9rem 1rem; color: var(--text-muted);">
                                    <?= !empty($reservation['created_at']) ? date('M j, H:i', strtotime($reservation['created_at'])) : 'Unknown' ?>
                                    <?php if (!empty($reservation['expires_at'])): ?>
                                        <div style="font-size: 0.75rem;">Expires <?= date('M j, H:i', strtotime($reservation['expires_at'])) ?></div>
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
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Host Environment</div>
    <div class="card-body" style="padding: 1.5rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 2rem;">
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 1rem;">System Specs</div>
                <div style="font-size: 0.875rem; line-height: 2;">
                    <strong>OS:</strong> <?= $metrics['os'] ?><br>
                    <strong>Web Server:</strong> <?= $metrics['server_software'] ?><br>
                    <strong>PHP Version:</strong> <?= $metrics['php_version'] ?>
                </div>
            </div>
            <div>
                <div style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; margin-bottom: 1rem;">Resource Usage</div>

                <div style="margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.25rem;">
                        <span>Disk Usage</span>
                        <span><?= $metrics['disk']['percent'] ?>%</span>
                    </div>
                    <div style="height: 8px; background: #f3f4f6; border-radius: 4px; overflow: hidden;">
                        <div style="height: 100%; width: <?= $metrics['disk']['percent'] ?>%; background: <?= $metrics['disk']['percent'] > 90 ? '#ef4444' : '#3b82f6' ?>;"></div>
                    </div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.25rem;">
                        <?= $metrics['disk']['readable_used'] ?> used of <?= $metrics['disk']['readable_total'] ?>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; text-align: center; background: #f9fafb; padding: 1rem; border-radius: 8px;">
                    <div>
                        <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">CPU Load</div>
                        <div style="font-size: 1rem; font-weight: 700;"><?= $metrics['cpu'] ?? 'N/A' ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">RAM Usage</div>
                        <div style="font-size: 1rem; font-weight: 700;"><?= $metrics['ram']['percent'] ?? 'N/A' ?><?= isset($metrics['ram']['percent']) ? '%' : '' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span>Support Bundle</span>
        <?php if (empty($demoAdmin)): ?>
            <a href="/admin/support" class="btn btn-sm" style="font-size: 0.75rem; padding: 0.35rem 0.85rem;">Open Support Center</a>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding: 1.5rem;">
        <p style="margin: 0 0 0.75rem 0; color: var(--text-color);">
            <?= !empty($demoAdmin)
                ? 'Support bundle tools are hidden for the demo admin account.'
                : 'Generate a sanitized diagnostics bundle for bug reports. It strips secrets and masks sensitive values before export, and the download is a plain <code>.json</code> file.' ?>
        </p>
        <div style="font-size: 0.875rem; color: var(--text-muted);">
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

<div class="card" style="margin-bottom: 2rem;">
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Recent System Errors</div>
    <div class="card-body" style="padding: 1.5rem;">
        <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1rem;">
            Current log size: <strong><?= htmlspecialchars((string)($logSizeReadable ?? '0 B')) ?></strong> / <?= htmlspecialchars((string)($logMaxReadable ?? '25 MB')) ?> cap.
        </div>
        <pre style="background: var(--bg-color); padding: 1rem; border-radius: 8px; font-size: 0.875rem; color: var(--text-color); border: 1px solid var(--border-color); white-space: pre-wrap; word-wrap: break-word; max-height: 320px; overflow: auto;"><?php if (empty($errors)): ?>(no recent errors)<?php else: foreach ($errors as $line): ?><?= htmlspecialchars($line) ?><?php endforeach; endif; ?></pre>
    </div>
</div>

<div class="card">
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span>Application Logs</span>
        <?php if (empty($demoAdmin)): ?>
            <form method="POST" action="/admin/logs/clear" onsubmit="return confirm('Permanently clear all application logs?')">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="redirect" value="/admin/status">
                <button type="submit" class="btn btn-sm" style="background: #fee2e2; color: #b91c1c; font-size: 0.75rem; padding: 0.25rem 0.75rem;">Clear Logs</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding: 1.5rem;">
        <div style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 1rem;">
            <?= !empty($demoAdmin)
                ? 'Recent entries are shown in a redacted format for the demo admin account.'
                : 'Recent entries are shown in a readable format below. Raw JSON is still included for anything Fyuhls cannot parse cleanly.' ?>
        </div>
        <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1rem;">
            Current log size: <strong><?= htmlspecialchars((string)($logSizeReadable ?? '0 B')) ?></strong> / <?= htmlspecialchars((string)($logMaxReadable ?? '25 MB')) ?> cap.
        </div>
        <div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 520px; overflow-y: auto;">
            <?php foreach (($formattedLogs ?? []) as $entry): ?>
                <?php
                $level = strtolower((string)($entry['level'] ?? 'info'));
                $accent = $level === 'error' ? '#b91c1c' : ($level === 'warning' ? '#b45309' : '#2563eb');
                $timestamp = !empty($entry['timestamp']) ? date('M j, Y H:i:s', strtotime($entry['timestamp'])) : 'Unknown time';
                $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
                ?>
                <div style="background: var(--bg-color); border: 1px solid var(--border-color); border-left: 4px solid <?= $accent ?>; border-radius: 8px; padding: 0.9rem 1rem;">
                    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 0.45rem;">
                        <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: <?= $accent ?>;"><?= htmlspecialchars($level) ?></span>
                        <span style="font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($timestamp) ?></span>
                    </div>
                    <div style="font-size: 0.95rem; font-weight: 600; color: var(--text-color); margin-bottom: <?= !empty($context) ? '0.55rem' : '0' ?>;">
                        <?= htmlspecialchars((string)($entry['message'] ?? 'Log entry')) ?>
                    </div>
                    <?php if (!empty($context)): ?>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            <?php foreach ($context as $key => $value): ?>
                                <span style="display: inline-flex; align-items: center; gap: 0.35rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 999px; padding: 0.3rem 0.55rem; font-size: 0.78rem; color: var(--text-color);">
                                    <strong style="font-weight: 700;"><?= htmlspecialchars((string)$key) ?>:</strong>
                                    <span><?= htmlspecialchars(is_scalar($value) || $value === null ? (string)$value : json_encode($value)) ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($formattedLogs)): ?>
                <div style="color: var(--text-muted);">No application log entries recorded yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
