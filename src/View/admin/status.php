<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';

$runtimeSecurityNotices = is_array($runtimeSecurityNotices ?? null) ? $runtimeSecurityNotices : [];
$errors = is_array($errors ?? null) ? $errors : [];
$formattedLogs = is_array($formattedLogs ?? null) ? $formattedLogs : [];
$uploadStats = is_array($uploadStats ?? null) ? $uploadStats : [];
$deliveryStats = is_array($deliveryStats ?? null) ? $deliveryStats : [];
$recentUploadSessions = is_array($recentUploadSessions ?? null) ? $recentUploadSessions : [];
$recentReservations = is_array($recentReservations ?? null) ? $recentReservations : [];
$metrics = is_array($metrics ?? null) ? $metrics : [];
$updateStatus = is_array($updateStatus ?? null) ? $updateStatus : [];
$demoAdmin = !empty($demoAdmin);

$logSizeBytes = (int)($logSizeBytes ?? 0);
$logMaxBytes = (int)($logMaxBytes ?? 26214400);
$recentErrorCount = count($errors);
$uploadRiskCount = (int)($uploadStats['stale_sessions'] ?? 0)
    + (int)($uploadStats['failed_sessions'] ?? 0)
    + (int)($uploadStats['stuck_completing'] ?? 0)
    + (int)($uploadStats['expired_reservations'] ?? 0);
$uploadBacklogCount = $uploadRiskCount + (int)($uploadStats['checksum_backlog'] ?? 0);
$environmentWarnings = 0;
if (($writable ?? '') !== 'ok') {
    $environmentWarnings++;
}
if (empty($gdOk)) {
    $environmentWarnings++;
}
if (empty($ffmpegOk)) {
    $environmentWarnings++;
}
if (!empty($runtimeSecurityNotices)) {
    $environmentWarnings += count($runtimeSecurityNotices);
}
$logNearCap = $logMaxBytes > 0 && $logSizeBytes >= (int)($logMaxBytes * 0.8);

$updateStateLabel = 'Up to date';
$updateStateClass = 'text-success';
if (!empty($updateStatus['error'])) {
    $updateStateLabel = 'Update check failed';
    $updateStateClass = 'text-danger';
} elseif (!empty($updateStatus['update_available'])) {
    $updateStateLabel = 'Update available';
    $updateStateClass = 'text-warning';
} elseif (empty($updateStatus['repo_configured'])) {
    $updateStateLabel = 'Repo not configured';
    $updateStateClass = 'text-muted';
}

$supportStateLabel = $demoAdmin ? 'Hidden in demo' : ($smtpConfigured ? 'Ready to email' : 'Download only');
$supportStateClass = $demoAdmin ? 'text-muted' : ($smtpConfigured ? 'text-success' : 'text-warning');

$deliveryStateLabel = 'App-controlled fallback';
$deliveryStateClass = 'text-primary';
if (($deliveryStats['ppd_progress_tracking'] ?? 0) > 0) {
    $deliveryStateLabel = 'Verification forced';
    $deliveryStateClass = 'text-warning';
} elseif (!empty($deliveryStats['cdn_enabled']) && !empty($deliveryStats['cdn_base_configured'])) {
    $deliveryStateLabel = 'CDN ready';
    $deliveryStateClass = 'text-success';
} elseif (!empty($deliveryStats['cdn_enabled']) && empty($deliveryStats['cdn_base_configured'])) {
    $deliveryStateLabel = 'CDN incomplete';
    $deliveryStateClass = 'text-danger';
} elseif (($deliveryStats['private_object_files'] ?? 0) > 0 || ($deliveryStats['signed_origin_files'] ?? 0) > 0) {
    $deliveryStateLabel = 'Signed origin mix';
    $deliveryStateClass = 'text-primary';
}

$triageCritical = [];
$triageWarnings = [];
$triageHealthy = [];

if (!empty($runtimeSecurityNotices)) {
    foreach ($runtimeSecurityNotices as $notice) {
        $triageCritical[] = [
            'title' => (string)($notice['title'] ?? 'Security notice'),
            'message' => (string)($notice['message'] ?? ''),
            'next' => 'Review Security settings and key handling before making other changes.',
        ];
    }
}

if (($writable ?? '') !== 'ok') {
    $triageCritical[] = [
        'title' => 'Uploads path is not writable',
        'message' => 'Local-file operations can fail if storage/uploads cannot be written.',
        'next' => 'Check filesystem permissions before debugging uploads any further.',
    ];
}
if ($uploadRiskCount > 0) {
    $triageCritical[] = [
        'title' => 'Upload pipeline needs attention',
        'message' => $uploadRiskCount . ' stale, failed, stuck, or expired upload records are waiting for review.',
        'next' => 'Open Upload Pipeline below, inspect recent sessions, and confirm cron health.',
    ];
}
if (!empty($updateStatus['error'])) {
    $triageWarnings[] = [
        'title' => 'Update checks are failing',
        'message' => (string)$updateStatus['error'],
        'next' => 'Confirm GitHub connectivity and repo configuration before trusting updater status.',
    ];
}
if (!empty($updateStatus['update_available'])) {
    $triageWarnings[] = [
        'title' => 'A newer release is available',
        'message' => 'The installed version is behind the latest release.',
        'next' => 'Review the update panel, take backups, and apply on your maintenance window.',
    ];
}
if (!$smtpConfigured && !$demoAdmin) {
    $triageWarnings[] = [
        'title' => 'SMTP is not configured',
        'message' => 'Account, ticket, and support emails cannot be sent directly right now.',
        'next' => 'Open Config Hub > Email and verify SMTP before testing mail-dependent workflows.',
    ];
}
if ($logNearCap) {
    $triageWarnings[] = [
        'title' => 'Application log is nearing its size cap',
        'message' => 'The log file is large enough that review and retention will get harder.',
        'next' => 'Review noisy errors first, then clear logs only after you capture what you need.',
    ];
}
if (empty($gdOk)) {
    $triageWarnings[] = [
        'title' => 'GD is missing',
        'message' => 'Image thumbnails and some image tooling will not work.',
        'next' => 'Install the PHP GD extension and reload PHP.',
    ];
}
if (empty($ffmpegOk)) {
    $triageWarnings[] = [
        'title' => 'FFmpeg is not ready',
        'message' => 'Video thumbnails and video processing will stay unavailable.',
        'next' => 'Set the FFmpeg binary path in General settings and confirm the file exists.',
    ];
}

if (empty($triageCritical) && empty($triageWarnings)) {
    $triageHealthy[] = [
        'title' => 'No immediate blockers detected',
        'message' => 'The status page is not showing a major operational issue right now.',
        'next' => 'Use the deeper sections below for targeted review or support prep.',
    ];
}
if (($writable ?? '') === 'ok' && !empty($gdOk) && !empty($ffmpegOk)) {
    $triageHealthy[] = [
        'title' => 'Core media basics look healthy',
        'message' => 'Uploads path, GD, and FFmpeg are all available.',
        'next' => 'If users still report media issues, move on to delivery or storage diagnostics.',
    ];
}
if ($smtpConfigured && !$demoAdmin) {
    $triageHealthy[] = [
        'title' => 'Support email path is available',
        'message' => 'SMTP is configured, so direct support and ticket mail can be tested here.',
        'next' => 'Use the Email tab for send tests and template review if needed.',
    ];
}

ob_start();
?>
<div class="d-flex flex-wrap gap-2">
    <a href="/admin/support" class="btn btn-sm btn-outline-secondary">Open Support Center</a>
    <a href="/admin/configuration?tab=email" class="btn btn-sm btn-outline-secondary">Email Settings</a>
    <a href="/admin/configuration?tab=downloads" class="btn btn-sm btn-outline-secondary">Download Settings</a>
    <a href="/admin/configuration?tab=cron" class="btn btn-sm btn-outline-secondary">Cron Jobs</a>
    <a href="/admin/server-monitoring" class="btn btn-sm btn-outline-secondary">Server Monitoring</a>
</div>
<?php
$statusActions = ob_get_clean();
renderAdminPageHeader(
    'System Status',
    'Triage active issues first, then inspect the deeper upload, delivery, logging, and support diagnostics below.',
    $statusActions
);
?>

<style>
    .status-summary-grid,
    .status-domain-grid,
    .status-action-grid,
    .status-health-grid,
    .status-metric-grid,
    .status-log-filter-row {
        display:grid;
        gap:1rem;
    }
    .status-summary-grid { grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); margin-bottom: 1.5rem; }
    .status-domain-grid { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .status-action-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .status-health-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .status-metric-grid { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
    .status-log-filter-row { grid-template-columns: repeat(auto-fit, minmax(110px, max-content)); align-items:center; }
    .status-ops-card,
    .status-summary-card,
    .status-action-card,
    .status-note-card {
        background:#fff;
        border:1px solid rgba(15,23,42,.08);
        border-radius:14px;
        box-shadow:0 10px 24px rgba(15,23,42,.05);
    }
    .status-summary-card { padding:1rem 1.1rem; }
    .status-summary-label {
        font-size:.75rem;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:var(--text-muted);
        font-weight:700;
        margin-bottom:.45rem;
    }
    .status-summary-value { font-size:1.2rem; font-weight:800; line-height:1.2; }
    .status-summary-meta { font-size:.82rem; color:var(--text-muted); margin-top:.35rem; }
    .status-triage-column { display:flex; flex-direction:column; gap:1rem; }
    .status-triage-heading {
        display:flex;
        align-items:center;
        font-size:.95rem;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.04em;
        margin-bottom:.25rem;
    }
    .status-ops-card { padding:1rem 1.1rem; }
    .status-ops-title { font-weight:800; margin-bottom:.35rem; }
    .status-ops-copy { color:var(--text-color); line-height:1.65; margin-bottom:.5rem; }
    .status-ops-next { font-size:.85rem; color:var(--text-muted); }
    .status-triage-critical .status-triage-heading { color:#b91c1c; }
    .status-triage-warning .status-triage-heading { color:#b45309; }
    .status-triage-healthy .status-triage-heading { color:#166534; }
    .status-ops-card--critical { border-color:rgba(220,38,38,.18); background:#fff7f7; }
    .status-ops-card--warning { border-color:rgba(245,158,11,.22); background:#fffaf0; }
    .status-ops-card--healthy { border-color:rgba(34,197,94,.20); background:#f7fff9; }
    .status-section-intro { color:var(--text-muted); margin-bottom:1rem; line-height:1.65; }
    .status-domain-band { margin-bottom:1.5rem; }
    .status-domain-title { font-weight:800; margin-bottom:.35rem; }
    .status-action-card {
        padding:1rem 1.1rem;
        display:flex;
        flex-direction:column;
        gap:.5rem;
        text-decoration:none;
        color:inherit;
    }
    .status-action-card:hover { transform:translateY(-1px); box-shadow:0 14px 28px rgba(15,23,42,.07); }
    .status-action-label { font-size:.85rem; text-transform:uppercase; color:var(--text-muted); font-weight:700; }
    .status-note-card { padding:1rem 1.1rem; }
    .status-note-label { font-size:.78rem; text-transform:uppercase; color:var(--text-muted); font-weight:800; margin-bottom:.45rem; }
    .status-note-copy { color:var(--text-color); line-height:1.65; margin-bottom:0; }
    .status-note-copy + .status-note-copy { margin-top:.65rem; }
    .status-note-card--operator {
        border-color:rgba(37,99,235,.14);
        background:#f8fbff;
    }
    .status-note-card--technical {
        border-color:rgba(15,23,42,.10);
        background:#fbfcfd;
    }
    .status-check-card {
        background:#f8fafc;
        border:1px solid rgba(15,23,42,.08);
        border-radius:12px;
        padding:1rem;
    }
    .status-check-label { font-size:.75rem; text-transform:uppercase; color:var(--text-muted); font-weight:700; margin-bottom:.35rem; }
    .status-check-value { font-size:1rem; font-weight:800; }
    .status-check-copy { color:var(--text-muted); font-size:.84rem; margin-top:.35rem; line-height:1.55; }
    .status-good { color:#166534; }
    .status-warn { color:#b45309; }
    .status-bad { color:#b91c1c; }
    .status-muted-copy { font-size:0.9rem; color: var(--text-muted); }
    .status-policy-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; }
    .status-policy-card {
        background:#f8fafc;
        border:1px solid var(--border-color);
        border-radius:12px;
        padding:1rem;
    }
    .status-policy-title { font-size:.78rem; text-transform:uppercase; color:var(--text-muted); font-weight:800; margin-bottom:.45rem; }
    .status-policy-copy { line-height:1.65; }
    .status-host-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem; }
    .status-mini-grid {
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:1rem;
        text-align:center;
        background:#f9fafb;
        padding:1rem;
        border-radius:10px;
    }
    .status-mini-label { font-size:.68rem; color:var(--text-muted); font-weight:800; text-transform:uppercase; }
    .status-metric { font-size:1rem; font-weight:800; }
    .status-metric-lg { font-size:1.3rem; font-weight:800; }
    .status-section-tools { display:flex; flex-wrap:wrap; gap:.6rem; }
    .status-section-tools .btn { font-size:.78rem; }
    .status-progress-wrap { margin-bottom:1rem; }
    .status-progress-head { display:flex; justify-content:space-between; font-size:.78rem; margin-bottom:.25rem; }
    .status-progress-track { height:8px; background:#f3f4f6; border-radius:4px; overflow:hidden; }
    .status-progress-bar { height:100%; }
    .status-progress-bar--danger { background:#ef4444; }
    .status-progress-bar--normal { background:#3b82f6; }
    .status-progress-note { font-size:.75rem; color:var(--text-muted); margin-top:.25rem; }
    .status-table-wrap { overflow:auto; max-height:420px; }
    .status-empty { padding:1.5rem; color:var(--text-muted); }
    .status-cell { padding:.9rem 1rem; }
    .status-file-cell { max-width:260px; }
    .status-file-name { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .status-file-meta,
    .status-subcopy,
    .status-small-copy { font-size:.78rem; color:var(--text-muted); }
    .status-action-btn { background:#fee2e2; color:#b91c1c; }
    .status-log-size { font-size:.82rem; color:var(--text-muted); margin-bottom:1rem; }
    .status-log-pre {
        background:var(--bg-color);
        padding:1rem;
        border-radius:10px;
        font-size:.875rem;
        color:var(--text-color);
        border:1px solid var(--border-color);
        white-space:pre-wrap;
        word-wrap:break-word;
        max-height:320px;
        overflow:auto;
    }
    .status-log-feed { display:flex; flex-direction:column; gap:.75rem; max-height:520px; overflow-y:auto; }
    .status-log-entry {
        background:var(--bg-color);
        border:1px solid var(--border-color);
        border-radius:10px;
        padding:.9rem 1rem;
    }
    .status-log-entry--error { border-color:rgba(220,38,38,.2); }
    .status-log-entry--warning { border-color:rgba(245,158,11,.25); }
    .status-log-head { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; margin-bottom:.45rem; }
    .status-log-level { font-size:.72rem; font-weight:800; text-transform:uppercase; }
    .status-log-time { font-size:.78rem; color:var(--text-muted); }
    .status-log-message { font-size:.95rem; font-weight:700; color:var(--text-color); margin-bottom:.45rem; }
    .status-log-context { display:flex; flex-wrap:wrap; gap:.5rem; }
    .status-log-context[hidden] { display:none !important; }
    .status-log-pill {
        display:inline-flex;
        align-items:center;
        gap:.35rem;
        background:#f8fafc;
        border:1px solid var(--border-color);
        border-radius:999px;
        padding:.3rem .55rem;
        font-size:.78rem;
        color:var(--text-color);
    }
    .status-log-key { font-weight:700; }
    .status-log-tools { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:1rem; }
    .status-log-filter {
        border:1px solid rgba(15,23,42,.08);
        background:#fff;
        color:var(--text-color);
        border-radius:999px;
        padding:.35rem .75rem;
        font-size:.78rem;
        font-weight:700;
    }
    .status-log-filter.is-active { background:#2563eb; color:#fff; border-color:#2563eb; }
    .status-collapsible {
        border:1px solid rgba(15,23,42,.08);
        border-radius:14px;
        background:#fff;
        overflow:hidden;
    }
    .status-collapsible + .status-collapsible { margin-top:1rem; }
    .status-collapsible summary {
        list-style:none;
        cursor:pointer;
        padding:1rem 1.1rem;
        font-weight:700;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:1rem;
    }
    .status-collapsible summary::-webkit-details-marker { display:none; }
    .status-collapsible-body { padding:0 1.1rem 1.1rem; }
    @media (max-width: 991.98px) {
        .status-mini-grid { grid-template-columns:1fr; }
    }
</style>

<div class="status-summary-grid">
    <div class="status-summary-card">
        <div class="status-summary-label">Recent Errors</div>
        <div class="status-summary-value <?= $recentErrorCount > 0 ? 'status-bad' : 'status-good' ?>"><?= number_format($recentErrorCount) ?></div>
        <div class="status-summary-meta">Error-level log entries currently surfaced at the top of this page.</div>
    </div>
    <div class="status-summary-card">
        <div class="status-summary-label">Upload Backlog</div>
        <div class="status-summary-value <?= $uploadBacklogCount > 0 ? 'status-warn' : 'status-good' ?>"><?= number_format($uploadBacklogCount) ?></div>
        <div class="status-summary-meta">Stale, failed, stuck, expired, or checksum-backlogged upload records.</div>
    </div>
    <div class="status-summary-card">
        <div class="status-summary-label">Delivery Mode</div>
        <div class="status-summary-value <?= htmlspecialchars($deliveryStateClass) ?>"><?= htmlspecialchars($deliveryStateLabel) ?></div>
        <div class="status-summary-meta">How download routing currently behaves for public and object-storage files.</div>
    </div>
    <div class="status-summary-card">
        <div class="status-summary-label">Update Status</div>
        <div class="status-summary-value <?= htmlspecialchars($updateStateClass) ?>"><?= htmlspecialchars($updateStateLabel) ?></div>
        <div class="status-summary-meta">Release check state and updater readiness.</div>
    </div>
    <div class="status-summary-card">
        <div class="status-summary-label">Support Readiness</div>
        <div class="status-summary-value <?= htmlspecialchars($supportStateClass) ?>"><?= htmlspecialchars($supportStateLabel) ?></div>
        <div class="status-summary-meta">Whether support bundles can be emailed directly from the admin area.</div>
    </div>
    <div class="status-summary-card">
        <div class="status-summary-label">Environment Warnings</div>
        <div class="status-summary-value <?= $environmentWarnings > 0 ? 'status-warn' : 'status-good' ?>"><?= number_format($environmentWarnings) ?></div>
        <div class="status-summary-meta">Writable-path, media-tooling, and runtime security warnings.</div>
    </div>
</div>

<?php renderAdminCardStart('Triage First', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <p class="status-section-intro">This top section is for deciding what needs attention right now. If nothing looks urgent, the deeper sections below are still available for inspection and support prep.</p>
    <div class="status-domain-grid">
        <div class="status-triage-column status-triage-critical">
            <div class="status-triage-heading"><span>Critical Issues</span></div>
            <?php if (empty($triageCritical)): ?>
                <div class="status-ops-card status-ops-card--healthy">
                    <div class="status-ops-title">No critical issues detected</div>
                    <div class="status-ops-copy">There is no immediately blocking issue surfaced by the current status data.</div>
                </div>
            <?php else: ?>
                <?php foreach ($triageCritical as $issue): ?>
                    <div class="status-ops-card status-ops-card--critical">
                        <div class="status-ops-title"><?= htmlspecialchars($issue['title']) ?></div>
                        <div class="status-ops-copy"><?= htmlspecialchars($issue['message']) ?></div>
                        <div class="status-ops-next"><?= htmlspecialchars($issue['next']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="status-triage-column status-triage-warning">
            <div class="status-triage-heading"><span>Warnings</span></div>
            <?php if (empty($triageWarnings)): ?>
                <div class="status-ops-card status-ops-card--healthy">
                    <div class="status-ops-title">No active warnings</div>
                    <div class="status-ops-copy">The current signals do not show a clear warning state that needs quick follow-up.</div>
                </div>
            <?php else: ?>
                <?php foreach ($triageWarnings as $issue): ?>
                    <div class="status-ops-card status-ops-card--warning">
                        <div class="status-ops-title"><?= htmlspecialchars($issue['title']) ?></div>
                        <div class="status-ops-copy"><?= htmlspecialchars($issue['message']) ?></div>
                        <div class="status-ops-next"><?= htmlspecialchars($issue['next']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="status-triage-column status-triage-healthy">
            <div class="status-triage-heading"><span>Healthy Signals</span></div>
            <?php foreach ($triageHealthy as $issue): ?>
                <div class="status-ops-card status-ops-card--healthy">
                    <div class="status-ops-title"><?= htmlspecialchars($issue['title']) ?></div>
                    <div class="status-ops-copy"><?= htmlspecialchars($issue['message']) ?></div>
                    <div class="status-ops-next"><?= htmlspecialchars($issue['next']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Action Center', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <p class="status-section-intro">Use these quick links when you already know the next safe move and do not want to hunt through the sidebar.</p>
    <div class="status-action-grid">
        <a href="/admin/configuration?tab=cron" class="status-action-card">
            <div class="status-action-label">Automation</div>
            <div class="fw-semibold">Check Cron Jobs</div>
            <div class="small text-muted">Review stale schedules, heartbeat timing, and task frequencies.</div>
        </a>
        <a href="/admin/configuration?tab=email" class="status-action-card">
            <div class="status-action-label">Mail</div>
            <div class="fw-semibold">Open Email Settings</div>
            <div class="small text-muted">Fix SMTP, queue delivery, or template issues before testing support mail.</div>
        </a>
        <a href="/admin/configuration?tab=downloads" class="status-action-card">
            <div class="status-action-label">Delivery</div>
            <div class="fw-semibold">Open Download Settings</div>
            <div class="small text-muted">Check CDN redirects, streaming, and Nginx completion-log behavior.</div>
        </a>
        <a href="/admin/configuration?tab=storage" class="status-action-card">
            <div class="status-action-label">Storage</div>
            <div class="fw-semibold">Open Storage Settings</div>
            <div class="small text-muted">Review storage-node inventory, migration paths, and provider-side behavior.</div>
        </a>
        <a href="/admin/server-monitoring" class="status-action-card">
            <div class="status-action-label">Infrastructure</div>
            <div class="fw-semibold">Server Monitoring</div>
            <div class="small text-muted">Cross-check storage-node availability and longer-term host trends.</div>
        </a>
        <a href="/admin/support" class="status-action-card">
            <div class="status-action-label">Escalation</div>
            <div class="fw-semibold">Open Support Center</div>
            <div class="small text-muted">Prepare a sanitized bundle when the deeper diagnostics still are not enough.</div>
        </a>
    </div>
<?php renderAdminCardEnd(); ?>

<?php
ob_start();
?>
<div class="status-card-header-between">
    <span>Updates</span>
    <div class="status-section-tools">
        <a href="/admin/status?refresh_update=1" class="btn btn-sm btn-outline-secondary">Refresh Release Check</a>
        <?php if (!empty($updateStatus['release_url'])): ?>
            <a href="<?= htmlspecialchars((string)$updateStatus['release_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">View Release</a>
        <?php endif; ?>
    </div>
</div>
<?php
$updatesHeader = ob_get_clean();
renderAdminCardStart(null, ['headerHtml' => $updatesHeader, 'bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']);
?>
    <p class="status-section-intro">This section is for release posture and updater safety. It should help you decide whether the install is current and whether the built-in updater looks trustworthy enough for a maintenance window.</p>
    <div class="status-metric-grid mb-4">
        <div class="status-check-card">
            <div class="status-check-label">Installed</div>
            <div class="status-check-value"><?= htmlspecialchars((string)($updateStatus['current_version'] ?? 'unknown')) ?></div>
        </div>
        <div class="status-check-card">
            <div class="status-check-label">Latest Release</div>
            <div class="status-check-value"><?= htmlspecialchars((string)($updateStatus['latest_version'] ?? 'Unavailable')) ?></div>
        </div>
        <div class="status-check-card">
            <div class="status-check-label">Updater State</div>
            <div class="status-check-value <?= $updateStateClass === 'text-danger' ? 'status-bad' : ($updateStateClass === 'text-warning' ? 'status-warn' : 'status-good') ?>"><?= htmlspecialchars($updateStateLabel) ?></div>
        </div>
    </div>

    <?php if (!empty($updateStatus['error'])): ?>
        <div class="alert alert-warning mb-3"><?= htmlspecialchars((string)$updateStatus['error']) ?></div>
    <?php endif; ?>

    <?php if (!empty($updateStatus['repo'])): ?>
        <div class="status-muted-copy mb-3">Source repo: <code><?= htmlspecialchars((string)$updateStatus['repo']) ?></code></div>
    <?php endif; ?>

    <?php if (!empty($updateStatus['update_available']) && empty($updateStatus['error'])): ?>
        <div class="status-note-card mb-3">
            <div class="status-note-label">What this means</div>
            <p class="status-note-copy">The updater preserves local config files, <code>storage/</code>, <code>themes/custom/</code>, and <code>src/Plugin/</code>. It tracks core-owned files, backs up overwritten core files, and quarantines stale unchanged core files instead of hard-deleting them.</p>
        </div>
        <form method="POST" action="/admin/update/apply" data-confirm-message="Download and apply the latest GitHub release now?">
            <?= \App\Core\Csrf::field() ?>
            <button type="submit" class="btn btn-primary">Install Update</button>
        </form>
    <?php elseif (empty($updateStatus['repo_configured'])): ?>
        <div class="alert alert-secondary mb-0">Set <code>update.github_repo</code> in <code>config/version.php</code> to enable one-click release checks.</div>
    <?php endif; ?>

    <?php if (!empty($updateStatus['last_report']) && is_array($updateStatus['last_report'])): ?>
        <?php $lastReport = $updateStatus['last_report']; ?>
        <div class="status-policy-grid mt-4">
            <div class="status-policy-card">
                <div class="status-policy-title">Last Update Report</div>
                <div class="status-policy-copy">
                    Mode: <strong><?= htmlspecialchars((string)($lastReport['mode'] ?? 'unknown')) ?></strong><br>
                    Generated: <strong><?= !empty($lastReport['generated_at']) ? htmlspecialchars(date('M j, Y H:i', strtotime((string)$lastReport['generated_at']))) : 'Unknown' ?></strong><br>
                    Target: <strong><?= htmlspecialchars((string)($lastReport['from_version'] ?? '?')) ?> -> <?= htmlspecialchars((string)($lastReport['to_version'] ?? '?')) ?></strong>
                </div>
            </div>
            <div class="status-policy-card">
                <div class="status-policy-title">Safety Summary</div>
                <div class="status-policy-copy">
                    Copy candidates: <strong><?= (int)($lastReport['copy_candidates'] ?? 0) ?></strong><br>
                    Backed up: <strong><?= (int)($lastReport['files_backed_up'] ?? 0) ?></strong><br>
                    Quarantined stale core files: <strong><?= (int)($lastReport['stale_quarantined'] ?? 0) ?></strong><br>
                    Skipped modified stale files: <strong><?= (int)($lastReport['stale_modified_skipped'] ?? 0) ?></strong>
                </div>
            </div>
            <div class="status-policy-card">
                <div class="status-policy-title">Paths</div>
                <div class="status-policy-copy">
                    Backup root: <code><?= htmlspecialchars((string)($lastReport['backup_root'] ?? 'Not created yet')) ?></code><br>
                    Quarantine root: <code><?= htmlspecialchars((string)($lastReport['quarantine_root'] ?? 'Not created yet')) ?></code><br>
                    Report file: <code><?= htmlspecialchars((string)($lastReport['report_path'] ?? 'storage/cache/update_report.json')) ?></code>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('App Health', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <div class="status-domain-band">
        <div class="status-domain-title"><span>Core environment checks</span></div>
        <p class="status-section-intro">These checks answer whether the app can perform basic local work before you chase narrower workflow bugs.</p>
        <div class="status-note-card status-note-card--operator mb-4">
            <div class="status-note-label">Operator Note</div>
            <p class="status-note-copy">Treat this section like the "is the ground solid?" check. If uploads path, GD, or FFmpeg are unhealthy, fix those basics before debugging ticket reports, delivery complaints, or thumbnail issues one by one.</p>
        </div>
        <div class="status-health-grid">
            <div class="status-check-card">
                <div class="status-check-label">Uploads Path</div>
                <div class="status-check-value <?= ($writable ?? '') === 'ok' ? 'status-good' : 'status-bad' ?>"><?= ($writable ?? '') === 'ok' ? 'Writable' : 'Not writable' ?></div>
                <div class="status-check-copy">If this is not writable, local upload and file-manipulation paths will fail early.</div>
            </div>
            <div class="status-check-card">
                <div class="status-check-label">Image Thumbnails</div>
                <div class="status-check-value <?= !empty($gdOk) ? 'status-good' : 'status-warn' ?>"><?= !empty($gdOk) ? 'GD installed' : 'GD missing' ?></div>
                <div class="status-check-copy">Missing GD means image thumbnails and some image tooling will stop working.</div>
            </div>
            <div class="status-check-card">
                <div class="status-check-label">Video Thumbnails</div>
                <div class="status-check-value <?= !empty($ffmpegOk) ? 'status-good' : 'status-warn' ?>"><?= !empty($ffmpegOk) ? 'FFmpeg ready' : 'Not configured' ?></div>
                <div class="status-check-copy">If FFmpeg is not ready, video previews and processing stay unavailable.</div>
            </div>
            <div class="status-check-card">
                <div class="status-check-label">Rate-Limit Blocks</div>
                <div class="status-check-value <?= $blocked > 0 ? 'status-warn' : 'status-good' ?>"><?= number_format((int)$blocked) ?></div>
                <div class="status-check-copy">This shows how many download limit rows crossed the current block threshold.</div>
            </div>
        </div>
    </div>

    <?php if (!empty($runtimeSecurityNotices)): ?>
        <div class="status-domain-band">
            <div class="status-domain-title"><span>Runtime security notices</span></div>
            <p class="status-section-intro">These are not ordinary warnings. They usually mean the install is carrying forward an unsafe or incomplete security state.</p>
            <div class="status-domain-grid">
                <?php foreach ($runtimeSecurityNotices as $notice): ?>
                    <div class="status-note-card border border-warning-subtle">
                        <div class="status-note-label text-warning-emphasis"><?= htmlspecialchars((string)($notice['title'] ?? 'Security notice')) ?></div>
                        <p class="status-note-copy mb-2"><?= htmlspecialchars((string)($notice['message'] ?? '')) ?></p>
                        <?php if (!empty($notice['config_path'])): ?>
                            <div class="status-small-copy mb-2"><strong>Hidden config path:</strong> <code><?= htmlspecialchars((string)$notice['config_path']) ?></code></div>
                        <?php endif; ?>
                        <?php if (!empty($notice['suggested_value'])): ?>
                            <div class="status-small-copy"><strong>Suggested replacement app_key:</strong> <code><?= htmlspecialchars((string)$notice['suggested_value']) ?></code></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="status-domain-band">
        <div class="status-domain-title"><span>Host environment</span></div>
        <p class="status-section-intro">This is the passive host picture: useful for capacity and compatibility questions, but usually not the first place to debug a workflow bug.</p>
        <div class="status-host-grid">
            <div class="status-check-card">
                <div class="status-check-label">System Specs</div>
                <div class="status-small-copy" style="line-height:1.9;">
                    <strong>OS:</strong> <?= htmlspecialchars((string)($metrics['os'] ?? 'Unknown')) ?><br>
                    <strong>Web Server:</strong> <?= htmlspecialchars((string)($metrics['server_software'] ?? 'Unknown')) ?><br>
                    <strong>PHP Version:</strong> <?= htmlspecialchars((string)($metrics['php_version'] ?? 'Unknown')) ?>
                </div>
            </div>
            <div class="status-check-card">
                <div class="status-check-label">Disk Usage</div>
                <div class="status-progress-wrap mb-0">
                    <div class="status-progress-head">
                        <span>Usage</span>
                        <span><?= htmlspecialchars((string)($metrics['disk']['percent'] ?? '0')) ?>%</span>
                    </div>
                    <div class="status-progress-track">
                        <div class="status-progress-bar js-status-progress <?= (($metrics['disk']['percent'] ?? 0) > 90) ? 'status-progress-bar--danger' : 'status-progress-bar--normal' ?>" data-progress="<?= htmlspecialchars((string)($metrics['disk']['percent'] ?? '0')) ?>"></div>
                    </div>
                    <div class="status-progress-note"><?= htmlspecialchars((string)($metrics['disk']['readable_used'] ?? '0 B')) ?> used of <?= htmlspecialchars((string)($metrics['disk']['readable_total'] ?? '0 B')) ?></div>
                </div>
            </div>
            <div class="status-check-card">
                <div class="status-check-label">CPU and Memory</div>
                <div class="status-mini-grid">
                    <div>
                        <div class="status-mini-label">CPU Load</div>
                        <div class="status-metric"><?= htmlspecialchars((string)($metrics['cpu'] ?? 'N/A')) ?></div>
                    </div>
                    <div>
                        <div class="status-mini-label">RAM Usage</div>
                        <div class="status-metric"><?= htmlspecialchars((string)($metrics['ram']['percent'] ?? 'N/A')) ?><?= isset($metrics['ram']['percent']) ? '%' : '' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php renderAdminCardEnd(); ?>



<?php renderAdminCardStart('Upload Pipeline', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <p class="status-section-intro">This section is about whether uploads are moving cleanly from browser to stored object. Problems here usually become quota drift, stuck sessions, or support tickets later.</p>
    <div class="status-metric-grid mb-4">
        <div class="status-check-card"><div class="status-check-label">Active Sessions</div><div class="status-check-value"><?= (int)($uploadStats['active_sessions'] ?? 0) ?></div><div class="status-check-copy">Live multipart work still in motion.</div></div>
        <div class="status-check-card"><div class="status-check-label">Stale Sessions</div><div class="status-check-value <?= !empty($uploadStats['stale_sessions']) ? 'status-bad' : '' ?>"><?= (int)($uploadStats['stale_sessions'] ?? 0) ?></div><div class="status-check-copy">Usually means interrupted uploads or a worker path that never cleaned up.</div></div>
        <div class="status-check-card"><div class="status-check-label">Failed Sessions</div><div class="status-check-value <?= !empty($uploadStats['failed_sessions']) ? 'status-bad' : '' ?>"><?= (int)($uploadStats['failed_sessions'] ?? 0) ?></div><div class="status-check-copy">Failures worth checking before users keep retrying blindly.</div></div>
        <div class="status-check-card"><div class="status-check-label">Stuck Completing</div><div class="status-check-value <?= !empty($uploadStats['stuck_completing']) ? 'status-bad' : '' ?>"><?= (int)($uploadStats['stuck_completing'] ?? 0) ?></div><div class="status-check-copy">Usually means finalization did not finish cleanly.</div></div>
        <div class="status-check-card"><div class="status-check-label">Checksum Backlog</div><div class="status-check-value <?= !empty($uploadStats['checksum_backlog']) ? 'status-warn' : '' ?>"><?= (int)($uploadStats['checksum_backlog'] ?? 0) ?></div><div class="status-check-copy">Stored objects still waiting for checksum verification.</div></div>
        <div class="status-check-card"><div class="status-check-label">Expired Reservations</div><div class="status-check-value <?= !empty($uploadStats['expired_reservations']) ? 'status-bad' : '' ?>"><?= (int)($uploadStats['expired_reservations'] ?? 0) ?></div><div class="status-check-copy">Reserved quota that should have been released by cleanup work.</div></div>
    </div>

    <div class="status-note-card status-note-card--operator mb-4">
        <div class="status-note-label">Operator Note</div>
        <p class="status-note-copy">Stale sessions, stuck completions, and expired reservations usually point to interrupted uploads or cron cleanup not keeping up. Check the recent session table first, then verify cron health if the same problems keep returning.</p>
    </div>

    <div class="status-note-card status-note-card--technical mb-4">
        <div class="status-note-label">Technical Detail</div>
        <p class="status-note-copy">"Checksum backlog" means stored objects exist but their integrity bookkeeping has not fully caught up yet. "Stuck completing" usually means the upload made it through part delivery but stalled during finalization or metadata write-back.</p>
    </div>

    <div class="status-section-tools mb-4">
        <a href="/admin/configuration?tab=cron" class="btn btn-sm btn-outline-secondary">Check Cron Jobs</a>
        <a href="/admin/configuration?tab=uploads" class="btn btn-sm btn-outline-secondary">Open Upload Settings</a>
        <a href="/admin/configuration?tab=storage" class="btn btn-sm btn-outline-secondary">Open Storage Settings</a>
    </div>

    <details class="status-collapsible" open>
        <summary>
            <span>Recent Multipart Sessions</span>
            <span class="status-small-copy"><?= count($recentUploadSessions) ?> rows</span>
        </summary>
        <div class="status-collapsible-body">
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
                                $status = (string)($session['status'] ?? 'unknown');
                                $statusClass = in_array($status, ['failed', 'expired'], true) ? 'status-bad' : (in_array($status, ['completed'], true) ? 'status-good' : 'status-warn');
                                $isAbortable = in_array($status, ['pending', 'uploading', 'completing', 'processing'], true);
                                ?>
                                <tr>
                                    <td class="status-cell"><code><?= htmlspecialchars((string)$session['public_id']) ?></code></td>
                                    <td class="status-cell"><?= htmlspecialchars((string)($session['username'] ?: ('User #' . (int)$session['user_id']))) ?></td>
                                    <td class="status-cell status-file-cell">
                                        <div class="status-file-name"><?= htmlspecialchars((string)($session['original_filename'] ?? 'Unknown')) ?></div>
                                        <div class="status-file-meta"><?= htmlspecialchars((string)($session['storage_provider'] ?? 'unknown')) ?> &middot; <?= \App\Service\FileProcessor::formatSize((int)($session['expected_size'] ?? 0)) ?></div>
                                    </td>
                                    <td class="status-cell">
                                        <div class="status-file-name"><?= \App\Service\FileProcessor::formatSize((int)($session['uploaded_bytes'] ?? 0)) ?> / <?= \App\Service\FileProcessor::formatSize((int)($session['expected_size'] ?? 0)) ?></div>
                                        <div class="status-file-meta"><?= (int)($session['completed_parts'] ?? 0) ?> parts reported</div>
                                    </td>
                                    <td class="status-cell">
                                        <div class="status-metric <?= $statusClass ?>"><?= htmlspecialchars($status) ?></div>
                                        <?php if (!empty($session['error_message'])): ?>
                                            <div class="status-file-meta status-bad"><?= htmlspecialchars((string)$session['error_message']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="status-cell status-small-copy">
                                        <?= !empty($session['updated_at']) ? htmlspecialchars(date('M j, H:i', strtotime((string)$session['updated_at']))) : 'Never' ?>
                                        <?php if (!empty($session['expires_at'])): ?>
                                            <div class="status-subcopy">Expires <?= htmlspecialchars(date('M j, H:i', strtotime((string)$session['expires_at']))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="status-cell">
                                        <?php if ($isAbortable): ?>
                                            <form method="POST" action="/admin/uploads/session/abort" data-confirm-message="Abort upload session <?= htmlspecialchars((string)$session['public_id']) ?>?">
                                                <?= \App\Core\Csrf::field() ?>
                                                <input type="hidden" name="session_id" value="<?= htmlspecialchars((string)$session['public_id']) ?>">
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
    </details>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Storage & Reservations', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <p class="status-section-intro">This section is for quota bookkeeping and reservation cleanup. It is the place to look when storage limits feel wrong, space stays "reserved" too long, or uploads appear detached from quota state.</p>

    <div class="status-metric-grid mb-4">
        <div class="status-check-card"><div class="status-check-label">Active Reservations</div><div class="status-check-value <?= !empty($uploadStats['active_reservations']) ? 'status-warn' : 'status-good' ?>"><?= (int)($uploadStats['active_reservations'] ?? 0) ?></div><div class="status-check-copy">Quota currently held open for upload work that may still complete.</div></div>
        <div class="status-check-card"><div class="status-check-label">Reserved Bytes</div><div class="status-check-value"><?= \App\Service\FileProcessor::formatSize((int)($uploadStats['reserved_bytes'] ?? 0)) ?></div><div class="status-check-copy">How much capacity is still blocked by active reservations.</div></div>
        <div class="status-check-card"><div class="status-check-label">Expired Reservations</div><div class="status-check-value <?= !empty($uploadStats['expired_reservations']) ? 'status-bad' : 'status-good' ?>"><?= (int)($uploadStats['expired_reservations'] ?? 0) ?></div><div class="status-check-copy">Reservations that should have been released by cleanup or finalization.</div></div>
    </div>

    <div class="status-note-card status-note-card--operator mb-4">
        <div class="status-note-label">Operator Note</div>
        <p class="status-note-copy">If users report "quota full" or space not returning after failed uploads, start here. Reservations tell you whether the problem is active work still in progress or cleanup that never finished.</p>
    </div>

    <div class="status-section-tools mb-4">
        <a href="/admin/configuration?tab=storage" class="btn btn-sm btn-outline-secondary">Open Storage Settings</a>
        <a href="/admin/configuration?tab=cron" class="btn btn-sm btn-outline-secondary">Check Cron Jobs</a>
        <a href="/admin/server-monitoring" class="btn btn-sm btn-outline-secondary">Server Monitoring</a>
    </div>

    <details class="status-collapsible">
        <summary>
            <span>Recent Quota Reservations</span>
            <span class="status-small-copy"><?= count($recentReservations) ?> rows</span>
        </summary>
        <div class="status-collapsible-body">
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
                                    <td class="status-cell"><code><?= htmlspecialchars((string)$reservation['public_id']) ?></code></td>
                                    <td class="status-cell"><?= htmlspecialchars((string)($reservation['username'] ?: ('User #' . (int)$reservation['user_id']))) ?></td>
                                    <td class="status-cell">
                                        <?php if (!empty($reservation['upload_public_id'])): ?>
                                            <code><?= htmlspecialchars((string)$reservation['upload_public_id']) ?></code>
                                        <?php else: ?>
                                            <span class="status-small-copy">Detached</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="status-cell status-file-name"><?= \App\Service\FileProcessor::formatSize((int)($reservation['reserved_bytes'] ?? 0)) ?></td>
                                    <td class="status-cell"><?= htmlspecialchars((string)($reservation['status'] ?? 'unknown')) ?></td>
                                    <td class="status-cell status-small-copy">
                                        <?= !empty($reservation['created_at']) ? htmlspecialchars(date('M j, H:i', strtotime((string)$reservation['created_at']))) : 'Unknown' ?>
                                        <?php if (!empty($reservation['expires_at'])): ?>
                                            <div class="status-subcopy">Expires <?= htmlspecialchars(date('M j, H:i', strtotime((string)$reservation['expires_at']))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </details>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Download & Delivery', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <p class="status-section-intro">This section answers how downloads are likely being routed right now and what delivery rules are currently shaping the experience.</p>
    <div class="status-metric-grid mb-4">
        <div class="status-check-card"><div class="status-check-label">CDN Eligible Public Files</div><div class="status-check-value"><?= (int)($deliveryStats['cdn_eligible_files'] ?? 0) ?></div><div class="status-check-copy">Public object-storage files that can use CDN redirects.</div></div>
        <div class="status-check-card"><div class="status-check-label">Signed Origin Files</div><div class="status-check-value"><?= (int)($deliveryStats['signed_origin_files'] ?? 0) ?></div><div class="status-check-copy">Files still relying on signed origin links instead of CDN redirects.</div></div>
        <div class="status-check-card"><div class="status-check-label">App-Controlled Files</div><div class="status-check-value"><?= (int)($deliveryStats['app_controlled_files'] ?? 0) ?></div><div class="status-check-copy">Files staying on the application-controlled path.</div></div>
        <div class="status-check-card"><div class="status-check-label">Private Object Files</div><div class="status-check-value"><?= (int)($deliveryStats['private_object_files'] ?? 0) ?></div><div class="status-check-copy">Files that cannot be treated as public CDN candidates.</div></div>
    </div>

    <div class="status-policy-grid mb-4">
        <div class="status-policy-card">
            <div class="status-policy-title">Current Policy</div>
            <div class="status-policy-copy">
                CDN redirects: <strong><?= !empty($deliveryStats['cdn_enabled']) ? 'Enabled' : 'Disabled' ?></strong><br>
                CDN base URL: <strong><?= !empty($deliveryStats['cdn_base_configured']) ? 'Configured' : 'Missing' ?></strong><br>
                PPD progress threshold: <strong><?= (int)($deliveryStats['ppd_progress_tracking'] ?? 0) ?>%</strong>
            </div>
        </div>
        <div class="status-policy-card">
            <div class="status-policy-title">What this means</div>
            <div class="status-policy-copy">
                <?php if (($deliveryStats['ppd_progress_tracking'] ?? 0) > 0): ?>
                    Percent-based payout verification is forcing downloads back onto the app-controlled path so completion can be trusted.
                <?php elseif (!empty($deliveryStats['cdn_enabled']) && !empty($deliveryStats['cdn_base_configured'])): ?>
                    Public object-storage files can use CDN redirects. Private object-storage files still stay on signed-origin links, and local files remain app-controlled.
                <?php else: ?>
                    CDN is either off or incomplete, so object-storage files rely on signed-origin behavior and local files stay app-controlled.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="status-note-card status-note-card--operator mb-4">
        <div class="status-note-label">Operator Note</div>
        <p class="status-note-copy">Use this section when users say downloads feel inconsistent or payouts do not line up with expected delivery behavior. It tells you whether traffic is taking the CDN path, signed-origin path, or fully app-controlled path.</p>
    </div>

    <div class="status-section-tools">
        <a href="/admin/configuration?tab=downloads" class="btn btn-sm btn-outline-secondary">Open Download Settings</a>
        <a href="/admin/configuration?tab=storage" class="btn btn-sm btn-outline-secondary">Open Storage Settings</a>
        <a href="/admin/downloads/current" class="btn btn-sm btn-outline-secondary">Open Live Downloads</a>
    </div>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Support Diagnostics', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <p class="status-section-intro">This is the escalation side of the ops page. If the lower-level checks look healthy but reports keep coming in, this is where you confirm whether support tooling is ready.</p>
    <div class="status-policy-grid">
        <div class="status-policy-card">
            <div class="status-policy-title">Support Bundle</div>
            <div class="status-policy-copy">
                <?= $demoAdmin
                    ? 'Support bundle tools are hidden for the demo admin account.'
                    : 'Generate a sanitized diagnostics bundle for bug reports. It strips secrets and masks sensitive values before export, and the download is a plain .json file.' ?>
            </div>
        </div>
        <div class="status-policy-card">
            <div class="status-policy-title">Support Mail Path</div>
            <div class="status-policy-copy">
                Email target: <strong><?= htmlspecialchars((string)$supportEmail) ?></strong><br>
                SMTP status: <strong><?= $smtpConfigured ? 'Configured for direct send' : 'Not configured, download-only mode' ?></strong>
            </div>
        </div>
        <div class="status-policy-card">
            <div class="status-policy-title">Next Safe Step</div>
            <div class="status-policy-copy">
                Check this section after Status still leaves the issue unclear. Use Support Center for sanitized export instead of sharing raw logs or secrets directly.
            </div>
        </div>
    </div>
    <div class="status-note-card status-note-card--operator mt-4">
        <div class="status-note-label">Operator Note</div>
        <p class="status-note-copy">Reach for Support Center after you have done the quick triage and still need another pair of eyes. The safe move is to send a sanitized bundle instead of raw logs, secrets, or screenshots with sensitive values visible.</p>
    </div>
    <?php if (!$demoAdmin): ?>
        <div class="status-section-tools mt-4">
            <a href="/admin/support" class="btn btn-sm btn-outline-secondary">Open Support Center</a>
            <a href="/admin/configuration?tab=email" class="btn btn-sm btn-outline-secondary">Open Email Settings</a>
        </div>
    <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Logs', ['bodyClass' => 'status-card-body', 'cardClass' => 'mb-4']); ?>
    <p class="status-section-intro">Read the message first, then the metadata. Use the level filters to cut noise before you clear anything.</p>
    <div class="status-note-card status-note-card--operator mb-4">
        <div class="status-note-label">Operator Note</div>
        <p class="status-note-copy">Logs are for narrowing the question, not just collecting more text. Start with error-level entries, then open context only when the message itself is not enough to choose a next step.</p>
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

    <details class="status-collapsible mb-3" open>
        <summary>
            <span>Recent System Errors</span>
            <span class="status-small-copy"><?= $recentErrorCount ?> entries</span>
        </summary>
        <div class="status-collapsible-body">
            <div class="status-log-size">Current log size: <strong><?= htmlspecialchars((string)($logSizeReadable ?? '0 B')) ?></strong> / <?= htmlspecialchars((string)($logMaxReadable ?? '25 MB')) ?> cap.</div>
            <pre class="status-log-pre"><?php if (empty($errors)): ?>(no recent errors)<?php else: foreach ($errors as $line): ?><?= htmlspecialchars((string)$line) ?><?php endforeach; endif; ?></pre>
        </div>
    </details>

    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3">
        <div class="status-log-size mb-0">Current log size: <strong><?= htmlspecialchars((string)($logSizeReadable ?? '0 B')) ?></strong> / <?= htmlspecialchars((string)($logMaxReadable ?? '25 MB')) ?> cap.</div>
        <?php if (empty($demoAdmin)): ?>
            <form method="POST" action="/admin/logs/clear" data-confirm-message="Permanently clear all application logs?">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="redirect" value="/admin/status">
                <button type="submit" class="btn btn-sm status-action-btn">Clear Logs</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="status-log-tools">
        <button type="button" class="status-log-filter is-active" data-log-filter="all">All</button>
        <button type="button" class="status-log-filter" data-log-filter="error">Errors</button>
        <button type="button" class="status-log-filter" data-log-filter="warning">Warnings</button>
        <button type="button" class="status-log-filter" data-log-filter="info">Info</button>
    </div>

    <div class="status-log-feed" id="statusLogFeed">
        <?php foreach ($formattedLogs as $index => $entry): ?>
            <?php
            $level = strtolower((string)($entry['level'] ?? 'info'));
            $accentClass = $level === 'error' ? 'status-log-entry--error' : ($level === 'warning' ? 'status-log-entry--warning' : 'status-log-entry--info');
            $timestamp = !empty($entry['timestamp']) ? date('M j, Y H:i:s', strtotime((string)$entry['timestamp'])) : 'Unknown time';
            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            $contextId = 'status-log-context-' . $index;
            ?>
            <div class="status-log-entry <?= $accentClass ?>" data-log-level="<?= htmlspecialchars($level) ?>">
                <div class="status-log-head">
                    <span class="status-log-level"><?= htmlspecialchars($level) ?></span>
                    <span class="status-log-time"><?= htmlspecialchars($timestamp) ?></span>
                    <?php if (!empty($context)): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 js-status-log-context-toggle" data-target="<?= htmlspecialchars($contextId) ?>">Context</button>
                    <?php endif; ?>
                </div>
                <div class="status-log-message"><?= htmlspecialchars((string)($entry['message'] ?? 'Log entry')) ?></div>
                <?php if (!empty($context)): ?>
                    <div class="status-log-context" id="<?= htmlspecialchars($contextId) ?>" hidden>
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
<?php renderAdminCardEnd(); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-status-progress').forEach(function(bar) {
        const progress = parseFloat(bar.getAttribute('data-progress') || '0');
        const safeProgress = Number.isFinite(progress) ? Math.min(100, Math.max(0, progress)) : 0;
        bar.style.width = safeProgress + '%';
    });

    const filters = Array.from(document.querySelectorAll('[data-log-filter]'));
    const logEntries = Array.from(document.querySelectorAll('[data-log-level]'));

    filters.forEach(function(filterButton) {
        filterButton.addEventListener('click', function() {
            const filter = filterButton.getAttribute('data-log-filter') || 'all';
            filters.forEach(function(button) {
                button.classList.toggle('is-active', button === filterButton);
            });
            logEntries.forEach(function(entry) {
                const level = entry.getAttribute('data-log-level') || 'info';
                entry.hidden = filter !== 'all' && level !== filter;
            });
        });
    });

    document.querySelectorAll('.js-status-log-context-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            const targetId = button.getAttribute('data-target');
            if (!targetId) {
                return;
            }
            const target = document.getElementById(targetId);
            if (!target) {
                return;
            }
            target.hidden = !target.hidden;
        });
    });
});
</script>

<?php include 'footer.php'; ?>

