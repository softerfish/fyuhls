<?php include 'header.php'; ?>
<?php
$stats = $bundle['stats'] ?? [];
$history = $bundle['history'] ?? [];
$widgets = $bundle['widgets'] ?? [];
$isLive = $stats['is_live'] ?? false;

$money = static fn($v): string => '$' . number_format((float)$v, 2);
$count = static fn($v): string => number_format((int)$v);
$size = static fn($v): string => \App\Service\FileProcessor::formatSize((int)$v);
$timeText = static function (?string $value): string {
    if (!$value) return 'Never';
    $ts = strtotime($value);
    return $ts !== false ? date('M j, H:i', $ts) : 'Never';
};

$chartLabels = [];
$chartUploads = [];
$chartDownloads = [];
foreach ($history as $day) {
    $chartLabels[] = date('M d', strtotime($day['date']));
    $chartUploads[] = (int)($day['uploads_count'] ?? 0);
    $chartDownloads[] = (int)($day['downloads_count'] ?? 0);
}
$latestHistoryDay = !empty($history) ? end($history) : null;
if ($latestHistoryDay !== null) {
    reset($history);
}

$attentionItems = [];
if (($widgets['support_diagnostics']['recent_errors'] ?? 0) > 0) {
    $attentionItems[] = ['warning', 'Recent Errors', $count($widgets['support_diagnostics']['recent_errors'] ?? 0) . ' recent errors logged', '/admin/status#recent-system-errors'];
}
if (($widgets['automation']['overdue_tasks'] ?? 0) > 0) {
    $attentionItems[] = ['danger', 'Overdue Tasks', $count($widgets['automation']['overdue_tasks'] ?? 0) . ' automation tasks overdue', '/admin/configuration?tab=cron'];
}
if (($widgets['moderation_queue']['abuse_pending'] ?? 0) > 0 || ($widgets['moderation_queue']['dmca_pending'] ?? 0) > 0) {
    $attentionItems[] = ['warning', 'Moderation Queue', $count(($widgets['moderation_queue']['abuse_pending'] ?? 0) + ($widgets['moderation_queue']['dmca_pending'] ?? 0)) . ' reports waiting', '/admin/requests'];
}
if (($widgets['storage_capacity']['nodes_over_80'] ?? 0) > 0 || (($widgets['storage_capacity']['disk']['percent'] ?? 0) >= 85)) {
    $attentionItems[] = ['danger', 'Storage Pressure', 'Capacity is getting tight', '/admin/file-servers'];
}
if (empty($widgets['support_diagnostics']['smtp_configured'])) {
    $attentionItems[] = ['info', 'SMTP Missing', 'Email delivery is not configured', '/admin/configuration?tab=email'];
}

$todaySummary = [];
if (($widgets['user_growth']['new_today'] ?? 0) > 0) {
    $todaySummary[] = '+' . $count($widgets['user_growth']['new_today']) . ' users';
}
if (($latestHistoryDay['uploads_count'] ?? 0) > 0) {
    $todaySummary[] = '+' . $count($latestHistoryDay['uploads_count'] ?? 0) . ' uploads';
}
if (($widgets['automation']['overdue_tasks'] ?? 0) > 0) {
    $todaySummary[] = $count($widgets['automation']['overdue_tasks']) . ' overdue tasks';
}
if (($widgets['storage_capacity']['nodes_over_80'] ?? 0) > 0) {
    $todaySummary[] = $count($widgets['storage_capacity']['nodes_over_80']) . ' storage nodes over 80%';
}

function dashboardWidgetStart(string $id, string $title, string $subtitle, string $span = 'span-4'): void { ?>
    <section class="dashboard-widget <?= $span ?>" data-widget-id="<?= htmlspecialchars($id) ?>" draggable="true">
        <div class="dashboard-widget-card card border-0 shadow-sm h-100">
            <div class="dashboard-widget-header card-header bg-white border-0">
                <div>
                    <div class="dashboard-widget-title"><?= htmlspecialchars($title) ?></div>
                    <div class="dashboard-widget-subtitle"><?= htmlspecialchars($subtitle) ?></div>
                </div>
                <button type="button" class="dashboard-widget-toggle" aria-label="Collapse <?= htmlspecialchars($title) ?>">
                    <i class="bi bi-chevron-up"></i>
                </button>
            </div>
            <div class="dashboard-widget-body card-body">
<?php }

function dashboardWidgetEnd(): void { ?>
            </div>
        </div>
    </section>
<?php }

function dashboardMetricGrid(array $items): void { ?>
    <div class="metric-grid metric-grid-2">
        <?php foreach ($items as $item):
            $label = $item[0] ?? '';
            $value = $item[1] ?? '';
            $href = $item[2] ?? '';
            $stateClass = trim((string)($item[3] ?? ''));
            $classes = trim('metric-chip' . ($stateClass !== '' ? ' ' . $stateClass : ''));
        ?>
            <?php if ($href): ?>
                <a class="<?= htmlspecialchars($classes) ?>" href="<?= htmlspecialchars((string)$href) ?>">
                    <span><?= htmlspecialchars((string)$label) ?></span>
                    <strong><?= htmlspecialchars((string)$value) ?></strong>
                </a>
            <?php else: ?>
                <div class="<?= htmlspecialchars($classes) ?>">
                    <span><?= htmlspecialchars((string)$label) ?></span>
                    <strong><?= htmlspecialchars((string)$value) ?></strong>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php }

function dashboardMiniList(array $rows, string $empty = 'Nothing to show yet.'): void { ?>
    <div class="mini-list">
        <?php if (empty($rows)): ?>
            <div class="small text-muted"><?= htmlspecialchars($empty) ?></div>
        <?php else: ?>
            <?php foreach ($rows as [$left, $right, $class]): ?>
                <div class="mini-list-row">
                    <span><?= htmlspecialchars((string)$left) ?></span>
                    <strong class="<?= htmlspecialchars($class) ?>"><?= htmlspecialchars((string)$right) ?></strong>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php }
?>

<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <p class="text-muted mb-0">Drag widgets into any order and collapse the ones you do not need to see right now.</p>
    </div>
    <div class="dashboard-header-actions">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="dashboardResetLayoutBtn">Reset layout</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm p-3 h-100"><div class="dashboard-summary-label small text-muted mb-1 text-uppercase fw-bold">Total Users</div><div class="h4 mb-0 fw-bold"><?= $count($stats['total_users'] ?? 0) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm p-3 h-100"><div class="dashboard-summary-label small text-muted mb-1 text-uppercase fw-bold">Total Files</div><div class="h4 mb-0 fw-bold"><?= $count($stats['total_files'] ?? 0) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm p-3 h-100"><div class="dashboard-summary-label small text-muted mb-1 text-uppercase fw-bold">Storage Used</div><div class="h4 mb-0 fw-bold"><?= $size($stats['total_storage_bytes'] ?? 0) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm p-3 h-100"><div class="dashboard-summary-label small text-muted mb-1 text-uppercase fw-bold">Cache Status</div><div class="h4 mb-0 fw-bold"><span class="dashboard-summary-badge badge <?= $isLive ? 'bg-warning text-dark' : 'bg-success' ?> rounded-pill"><?= $isLive ? 'LIVE (SLOW)' : 'OPTIMIZED' ?></span></div></div></div>
</div>

<?php if (!empty($attentionItems)): ?>
<div class="dashboard-attention-strip card border-0 shadow-sm mb-4">
    <div class="dashboard-attention-header">
        <div>
            <div class="dashboard-attention-title">Attention Needed</div>
            <div class="dashboard-attention-subtitle">Things worth checking right now before they turn into support pain.</div>
        </div>
    </div>
    <div class="dashboard-attention-grid">
        <?php foreach ($attentionItems as [$severity, $title, $copy, $href]): ?>
            <a href="<?= htmlspecialchars($href) ?>" class="dashboard-attention-item dashboard-attention-item--<?= htmlspecialchars($severity) ?>">
                <span class="dashboard-attention-kicker"><?= htmlspecialchars($title) ?></span>
                <strong><?= htmlspecialchars($copy) ?></strong>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($todaySummary)): ?>
<div class="dashboard-today-strip card border-0 shadow-sm mb-4">
    <div class="dashboard-today-title">What changed today</div>
    <div class="dashboard-today-items">
        <?php foreach ($todaySummary as $item): ?>
            <span class="dashboard-today-chip"><?= htmlspecialchars($item) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-light border shadow-sm mb-4"><strong>Dashboard Layout:</strong> drag any widget to reorder it. Use the arrow button to collapse it down to the title bar. Your layout is saved in this browser.</div>

<div id="dashboardWidgetGrid" class="dashboard-widget-grid">
    <?php dashboardWidgetStart('support_diagnostics', 'Support and Diagnostics', 'Logs, SMTP, and plugin surface'); ?>
        <?php dashboardMetricGrid([
            ['Recent Errors', $count($widgets['support_diagnostics']['recent_errors'] ?? 0), '/admin/status#recent-system-errors', ($widgets['support_diagnostics']['recent_errors'] ?? 0) > 0 ? 'metric-chip--warning' : ''],
            ['SMTP', !empty($widgets['support_diagnostics']['smtp_configured']) ? 'Configured' : 'Missing', '/admin/configuration?tab=email', !empty($widgets['support_diagnostics']['smtp_configured']) ? 'metric-chip--ok' : 'metric-chip--warning'],
            ['Active Plugins', $count($widgets['support_diagnostics']['active_plugins'] ?? 0), '/admin/plugins'],
            ['Support Email', $widgets['support_diagnostics']['support_email'] ?? 'N/A', '/admin/support'],
        ]); ?>
        <div class="dashboard-widget-links mt-3"><a href="/admin/support">Open support center</a><a href="/admin/status">View status and logs</a><a href="/admin/docs">Open admin docs</a></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('revenue', 'Revenue Snapshot', 'Rewards, payouts, and subscription momentum'); ?>
        <?php dashboardMetricGrid([
            ['Today', $money($widgets['revenue']['today_earnings'] ?? 0)],
            ['Last 7 Days', $money($widgets['revenue']['week_earnings'] ?? 0)],
            ['Last 30 Days', $money($widgets['revenue']['month_earnings'] ?? 0)],
            ['Effective RPM', $money($widgets['revenue']['effective_rpm'] ?? 0)],
            ['Pending Withdrawals', $count($widgets['revenue']['pending_withdrawals'] ?? 0)],
            ['Pending Amount', $money($widgets['revenue']['pending_withdrawal_amount'] ?? 0)],
        ]); ?>
        <div class="small text-muted mt-3">Active subscriptions: <strong><?= $count($widgets['revenue']['active_subscriptions'] ?? 0) ?></strong><br>Completed transactions (30d): <strong><?= $count($widgets['revenue']['completed_transactions'] ?? 0) ?></strong></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('upload_pipeline', 'Upload Pipeline Health', 'Sessions, reservations, and stalled work'); ?>
        <?php dashboardMetricGrid([
            ['Active Sessions', $count($widgets['upload_pipeline']['active_sessions'] ?? 0)],
            ['Failed Sessions', $count($widgets['upload_pipeline']['failed_sessions'] ?? 0)],
            ['Stale Sessions', $count($widgets['upload_pipeline']['stale_sessions'] ?? 0)],
            ['Stuck Completing', $count($widgets['upload_pipeline']['stuck_completing'] ?? 0)],
            ['Active Reservations', $count($widgets['upload_pipeline']['active_reservations'] ?? 0)],
            ['Reserved Capacity', $size($widgets['upload_pipeline']['reserved_bytes'] ?? 0)],
        ]); ?>
        <div class="small text-muted mt-3">Checksum backlog: <strong><?= $count($widgets['upload_pipeline']['checksum_backlog'] ?? 0) ?></strong><br>Pending remote URL downloads: <strong><?= $count($widgets['upload_pipeline']['pending_remote_uploads'] ?? 0) ?></strong></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('storage_capacity', 'Storage Capacity', 'Disk pressure and storage node usage'); ?>
        <?php dashboardMetricGrid([
            ['Host Disk', ($widgets['storage_capacity']['disk']['percent'] ?? 0) . '%'],
            ['Active Servers', $count($widgets['storage_capacity']['active_servers'] ?? 0)],
            ['Read-Only Nodes', $count($widgets['storage_capacity']['read_only_servers'] ?? 0)],
            ['Nodes Over 80%', $count($widgets['storage_capacity']['nodes_over_80'] ?? 0)],
        ]); ?>
        <div class="small text-muted mt-3">Host usage: <strong><?= htmlspecialchars((string)($widgets['storage_capacity']['disk']['readable_used'] ?? '0 B')) ?></strong> of <strong><?= htmlspecialchars((string)($widgets['storage_capacity']['disk']['readable_total'] ?? '0 B')) ?></strong><br><?php if (!empty($widgets['storage_capacity']['hottest_node'])): ?>Hottest node: <strong><?= htmlspecialchars($widgets['storage_capacity']['hottest_node']['name']) ?></strong> at <strong><?= htmlspecialchars((string)$widgets['storage_capacity']['hottest_node']['percent']) ?>%</strong><?php else: ?>Hottest node: <strong>No node metrics yet</strong><?php endif; ?></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('moderation_queue', 'Abuse and Moderation Queue', 'Items waiting for review'); ?>
        <?php dashboardMetricGrid([
            ['Abuse Reports', $count($widgets['moderation_queue']['abuse_pending'] ?? 0), '/admin/abuse-reports', ($widgets['moderation_queue']['abuse_pending'] ?? 0) > 0 ? 'metric-chip--warning' : ''],
            ['DMCA Reports', $count($widgets['moderation_queue']['dmca_pending'] ?? 0), '/admin/dmca', ($widgets['moderation_queue']['dmca_pending'] ?? 0) > 0 ? 'metric-chip--warning' : ''],
            ['New Contacts', $count($widgets['moderation_queue']['new_contacts'] ?? 0), '/admin/contacts'],
            ['DMCA Investigating', $count($widgets['moderation_queue']['investigating_dmca'] ?? 0), '/admin/dmca'],
        ]); ?>
        <div class="dashboard-widget-links mt-3"><a href="/admin/abuse-reports">Open abuse reports</a><a href="/admin/dmca">Open DMCA queue</a><a href="/admin/contacts">Open contact messages</a></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('user_growth', 'User Growth', 'New accounts and verification backlog'); ?>
        <?php dashboardMetricGrid([
            ['Today', $count($widgets['user_growth']['new_today'] ?? 0)],
            ['Last 7 Days', $count($widgets['user_growth']['new_7d'] ?? 0)],
            ['Last 30 Days', $count($widgets['user_growth']['new_30d'] ?? 0)],
            ['Need Verification', $count($widgets['user_growth']['pending_verification'] ?? 0)],
        ]); ?>
        <div class="small text-muted mt-3 mb-3">Active premium accounts: <strong><?= $count($widgets['user_growth']['active_premium'] ?? 0) ?></strong></div>
        <?php
        $signupRows = [];
        foreach (($widgets['user_growth']['recent_signups'] ?? []) as $signup) $signupRows[] = [$signup['username'] ?: ($signup['public_id'] ?? 'user'), $timeText($signup['created_at'] ?? null), 'text-muted'];
        dashboardMiniList($signupRows, 'No signups recorded yet.');
        ?>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('email_queue', 'Email Queue Health', 'Pending, failed, and most recent delivery'); ?>
        <?php dashboardMetricGrid([
            ['Pending', $count($widgets['email_queue']['pending'] ?? 0)],
            ['Failed', $count($widgets['email_queue']['failed'] ?? 0)],
            ['Sent (24h)', $count($widgets['email_queue']['sent_24h'] ?? 0)],
            ['Oldest Pending', $timeText($widgets['email_queue']['oldest_pending_at'] ?? null)],
        ]); ?>
        <div class="small text-muted mt-3">Last sent message: <strong><?= htmlspecialchars($timeText($widgets['email_queue']['last_sent_at'] ?? null)) ?></strong></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('security_watch', 'Security Watch', 'Rate limits, VPN hits, and 2FA admin actions'); ?>
        <?php dashboardMetricGrid([
            ['Failed Logins (24h)', $count($widgets['security_watch']['failed_logins_24h'] ?? 0)],
            ['Restricted IPs', $count($widgets['security_watch']['restricted_ips_24h'] ?? 0)],
            ['VPN Hits', $count($widgets['security_watch']['vpn_hits_24h'] ?? 0)],
            ['2FA Admin Actions', $count($widgets['security_watch']['recent_2fa_actions'] ?? 0)],
        ]); ?>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('automation', 'System Automation', 'Cron heartbeat and overdue tasks'); ?>
        <?php dashboardMetricGrid([
            ['Heartbeat', !empty($widgets['automation']['healthy']) ? 'Healthy' : 'Warning', '/admin/configuration?tab=cron', !empty($widgets['automation']['healthy']) ? 'metric-chip--ok' : 'metric-chip--warning'],
            ['Overdue Tasks', $count($widgets['automation']['overdue_tasks'] ?? 0), '/admin/configuration?tab=cron', ($widgets['automation']['overdue_tasks'] ?? 0) > 0 ? 'metric-chip--danger' : ''],
            ['Failed Tasks', $count($widgets['automation']['failed_tasks'] ?? 0), '/admin/configuration?tab=cron', ($widgets['automation']['failed_tasks'] ?? 0) > 0 ? 'metric-chip--warning' : ''],
            ['Last Run', $timeText($widgets['automation']['last_cron_run'] ?? null), '/admin/configuration?tab=cron'],
        ]); ?>
        <?php
        $taskRows = [];
        foreach (($widgets['automation']['tasks'] ?? []) as $task) $taskRows[] = [$task['task_name'] ?? $task['task_key'], !empty($task['is_overdue']) ? 'Overdue' : ($task['last_status'] ?? 'unknown'), !empty($task['is_overdue']) ? 'text-danger' : 'text-muted'];
        echo '<div class="dashboard-section-gap">';
        dashboardMiniList($taskRows, 'No cron task data yet.');
        echo '</div>';
        ?>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('download_mix', 'Download Delivery Mix', 'Where traffic is being served from'); ?>
        <?php dashboardMetricGrid([
            ['CDN Eligible', $count($widgets['download_mix']['cdn_eligible_files'] ?? 0)],
            ['Signed Origin', $count($widgets['download_mix']['signed_origin_files'] ?? 0)],
            ['App Controlled', $count($widgets['download_mix']['app_controlled_files'] ?? 0)],
            ['Active Downloads', $count($widgets['download_mix']['active_downloads'] ?? 0)],
        ]); ?>
        <div class="small text-muted mt-3">Public object files: <strong><?= $count($widgets['download_mix']['public_object_files'] ?? 0) ?></strong><br>Private object files: <strong><?= $count($widgets['download_mix']['private_object_files'] ?? 0) ?></strong><br>Local files: <strong><?= $count($widgets['download_mix']['local_files'] ?? 0) ?></strong></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('file_lifecycle', 'File Lifecycle', 'Cleanup backlog and object integrity'); ?>
        <?php dashboardMetricGrid([
            ['Pending Purge', $count($widgets['file_lifecycle']['pending_purge'] ?? 0)],
            ['Deleted', $count($widgets['file_lifecycle']['deleted'] ?? 0)],
            ['Quarantined', $count($widgets['file_lifecycle']['quarantined'] ?? 0)],
            ['Failed / Abandoned', $count($widgets['file_lifecycle']['failed'] ?? 0)],
            ['Duplicate Objects', $count($widgets['file_lifecycle']['duplicated_objects'] ?? 0)],
            ['Orphaned Objects', $count($widgets['file_lifecycle']['orphaned_objects'] ?? 0)],
        ]); ?>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('top_content', 'Top Content', 'Downloads, storage-heavy users, and earners', 'span-8'); ?>
        <div class="triple-list-grid">
            <div>
                <h6 class="small text-uppercase text-muted fw-bold mb-3">Most Downloaded Files</h6>
                <?php $rows = []; foreach (($widgets['top_content']['top_files'] ?? []) as $file) $rows[] = [$file['filename'] ?: ($file['short_id'] ?? 'file'), $count($file['downloads'] ?? 0), '']; dashboardMiniList($rows, 'No file activity yet.'); ?>
            </div>
            <div>
                <h6 class="small text-uppercase text-muted fw-bold mb-3">Largest Storage Users</h6>
                <?php $rows = []; foreach (($widgets['top_content']['top_storage_users'] ?? []) as $user) $rows[] = [$user['username'] ?: ($user['public_id'] ?? 'user'), $size($user['storage_used'] ?? 0), '']; dashboardMiniList($rows, 'No storage usage yet.'); ?>
            </div>
            <div>
                <h6 class="small text-uppercase text-muted fw-bold mb-3">Top Earners (30d)</h6>
                <?php $rows = []; foreach (($widgets['top_content']['top_earners'] ?? []) as $user) $rows[] = [$user['username'] ?: ($user['public_id'] ?? 'user'), $money($user['earnings_30d'] ?? 0), '']; dashboardMiniList($rows, 'No earnings data yet.'); ?>
            </div>
        </div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('growth_chart', 'Platform Growth', 'Uploads and downloads over the last 30 days', 'span-8'); ?>
        <canvas id="growthChart" class="dashboard-growth-chart"></canvas>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('host_health', 'Host System Health', 'Disk, CPU, RAM, and runtime details'); ?>
        <?php dashboardMetricGrid([
            ['Disk Usage', ($widgets['host']['disk']['percent'] ?? 0) . '%'],
            ['CPU Load', $widgets['host']['cpu'] ?? 'N/A'],
            ['RAM Usage', isset($widgets['host']['ram']['percent']) ? $widgets['host']['ram']['percent'] . '%' : 'N/A'],
            ['PHP', $widgets['host']['php_version'] ?? PHP_VERSION],
        ]); ?>
        <div class="small text-muted mt-3">Server: <strong><?= htmlspecialchars((string)($widgets['host']['server_software'] ?? 'N/A')) ?></strong><br>OS: <strong><?= htmlspecialchars((string)($widgets['host']['os'] ?? PHP_OS)) ?></strong></div>
    <?php dashboardWidgetEnd(); ?>

    <?php dashboardWidgetStart('recent_activity', 'Recent User Activity', 'Latest actions across the site', 'span-12'); ?>
        <div class="dashboard-activity-table table-responsive">
            <table class="dashboard-activity-text table table-hover align-middle mb-0">
                <thead class="bg-light sticky-top"><tr><th class="ps-4 py-3 border-0">Time</th><th class="border-0">User</th><th class="border-0">Action</th><th class="pe-4 border-0">Details</th></tr></thead>
                <tbody>
                    <?php foreach (($widgets['recent_activity'] ?? []) as $log): ?>
                        <tr>
                            <td class="ps-4 text-muted small"><?= htmlspecialchars($timeText($log['created_at'] ?? null)) ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($log['display_name'] ?? 'guest') ?></td>
                            <td><span class="dashboard-activity-badge badge bg-light text-dark border fw-normal"><?= htmlspecialchars(strtoupper((string)($log['activity_type'] ?? 'unknown'))) ?></span></td>
                            <td class="pe-4 text-muted small"><?= htmlspecialchars((string)($log['description'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($widgets['recent_activity'])): ?><tr><td colspan="4" class="ps-4 py-4 text-muted">No recent user activity recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php dashboardWidgetEnd(); ?>
</div>

<style>
.page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem}
.dashboard-header-actions{display:flex;gap:.75rem;align-items:center}
.dashboard-attention-strip,.dashboard-today-strip{padding:1rem 1.1rem}
.dashboard-attention-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:.9rem}
.dashboard-attention-title,.dashboard-today-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#0f172a}
.dashboard-attention-subtitle{font-size:.78rem;color:#64748b;line-height:1.45}
.dashboard-attention-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.85rem}
.dashboard-attention-item{text-decoration:none;border:1px solid #e2e8f0;border-radius:12px;padding:.85rem .9rem;background:#fff;display:flex;flex-direction:column;gap:.35rem;transition:.18s ease;color:#0f172a}
.dashboard-attention-item:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(15,23,42,.06)}
.dashboard-attention-kicker{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.dashboard-attention-item--danger{border-color:#fecaca;background:#fff7f7}
.dashboard-attention-item--danger .dashboard-attention-kicker{color:#b91c1c}
.dashboard-attention-item--warning{border-color:#fde68a;background:#fffdf3}
.dashboard-attention-item--warning .dashboard-attention-kicker{color:#b45309}
.dashboard-attention-item--info{border-color:#bfdbfe;background:#f8fbff}
.dashboard-attention-item--info .dashboard-attention-kicker{color:#1d4ed8}
.dashboard-today-items{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.7rem}
.dashboard-today-chip{display:inline-flex;align-items:center;padding:.45rem .7rem;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-size:.78rem;font-weight:600;color:#334155}
.dashboard-widget-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;align-items:start;grid-auto-flow:dense;grid-auto-rows:10px}
.dashboard-widget{min-width:0}
.dashboard-widget.span-4{grid-column:span 1}
.dashboard-widget.span-8{grid-column:span 2}
.dashboard-widget.span-12{grid-column:1 / -1}
.dashboard-widget-card{margin-bottom:0}
.dashboard-widget.dragging{opacity:.55}
.dashboard-widget-header{display:flex;justify-content:space-between;align-items:start;gap:.75rem;cursor:grab;padding:1rem 1.1rem .8rem}
.dashboard-widget-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em}
.dashboard-widget-subtitle{font-size:.72rem;color:#64748b;margin-top:.15rem;line-height:1.45}
.dashboard-widget-body{padding:1rem 1.1rem 1.1rem}
.dashboard-widget-toggle{border:0;background:transparent;color:#475569;width:1.9rem;height:1.9rem;border-radius:999px;display:inline-flex;align-items:center;justify-content:center}
.dashboard-widget-toggle:hover{background:#f1f5f9}
.dashboard-widget.is-collapsed .dashboard-widget-body{display:none}
.dashboard-widget.is-collapsed .dashboard-widget-toggle i{transform:rotate(180deg)}
.metric-grid{display:grid;gap:.7rem}
.metric-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
.metric-chip{border:1px solid #e2e8f0;border-radius:10px;padding:.7rem .8rem;background:#f8fafc;display:block;text-decoration:none;color:inherit;transition:.16s ease}
.metric-chip:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(15,23,42,.05);border-color:#cbd5e1}
.metric-chip span{display:block;font-size:.66rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.28rem}
.metric-chip strong{font-size:.92rem;font-weight:700;color:#0f172a}
.metric-chip--ok{background:#f0fdf4;border-color:#bbf7d0}
.metric-chip--warning{background:#fffbeb;border-color:#fde68a}
.metric-chip--danger{background:#fef2f2;border-color:#fecaca}
.dashboard-widget-links{display:flex;flex-wrap:wrap;gap:.65rem}
.dashboard-widget-links a{font-size:.78rem;font-weight:600;color:var(--bs-primary);text-decoration:none}
.dashboard-widget-links a:hover{text-decoration:underline}
.dashboard-summary-label{font-size:.65rem}
.dashboard-summary-badge{font-size:.7rem}
.dashboard-growth-chart{max-height:320px}
.dashboard-activity-table{max-height:420px}
.dashboard-activity-text{font-size:.85rem}
.dashboard-activity-badge{font-size:.65rem}
.dashboard-section-gap{margin-top:1rem}
.mini-list{display:grid;gap:.55rem}
.mini-list-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.85rem;align-items:flex-start;font-size:.79rem;border-bottom:1px solid #eef2f7;padding-bottom:.55rem}
.mini-list-row:last-child{border-bottom:0;padding-bottom:0}
.mini-list-row span{color:#334155;min-width:0;overflow-wrap:anywhere;word-break:break-word;white-space:normal;line-height:1.4}
.mini-list-row strong{justify-self:end;text-align:right;color:#0f172a;font-size:.74rem;line-height:1.35;min-width:3.5rem}
.triple-list-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.25rem}
.triple-list-grid>div{min-width:0}
.triple-list-grid h6{line-height:1.35;min-height:2.8em;margin-bottom:.9rem!important}
@media (max-width:1199px){.dashboard-widget-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.dashboard-widget.span-4,.dashboard-widget.span-12{grid-column:span 1}.triple-list-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:900px){.triple-list-grid{grid-template-columns:1fr}}
@media (max-width:767px){.page-header{flex-direction:column;align-items:stretch}.dashboard-widget-grid{grid-template-columns:1fr}.metric-grid-2{grid-template-columns:1fr}}
</style>

<script src="/assets/js/vendor/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const orderKey = 'fyuhls-admin-dashboard-order-v1';
    const collapseKey = 'fyuhls-admin-dashboard-collapsed-v1';
    const grid = document.getElementById('dashboardWidgetGrid');
    const widgets = Array.from(grid.querySelectorAll('.dashboard-widget'));
    let dragged = null;
    let autoScrollTick = null;
    let pointerY = null;
    try {
        const order = JSON.parse(localStorage.getItem(orderKey) || '[]');
        if (Array.isArray(order)) {
            const map = new Map(widgets.map((widget) => [widget.dataset.widgetId, widget]));
            order.forEach((id) => { const widget = map.get(id); if (widget) { grid.appendChild(widget); map.delete(id); } });
            map.forEach((widget) => grid.appendChild(widget));
        }
    } catch (error) {}
    try {
        const collapsed = JSON.parse(localStorage.getItem(collapseKey) || '[]');
        if (Array.isArray(collapsed)) collapsed.forEach((id) => { const widget = grid.querySelector('[data-widget-id="' + id + '"]'); if (widget) widget.classList.add('is-collapsed'); });
    } catch (error) {}
    const saveOrder = () => localStorage.setItem(orderKey, JSON.stringify(Array.from(grid.querySelectorAll('.dashboard-widget')).map((widget) => widget.dataset.widgetId)));
    const saveCollapsed = () => localStorage.setItem(collapseKey, JSON.stringify(Array.from(grid.querySelectorAll('.dashboard-widget.is-collapsed')).map((widget) => widget.dataset.widgetId)));
    const resetLayoutBtn = document.getElementById('dashboardResetLayoutBtn');
    const layoutWidgets = () => {
        const computed = getComputedStyle(grid);
        const rowHeight = parseFloat(computed.getPropertyValue('grid-auto-rows')) || 10;
        const rowGap = parseFloat(computed.getPropertyValue('row-gap')) || parseFloat(computed.getPropertyValue('gap')) || 16;
        Array.from(grid.querySelectorAll('.dashboard-widget')).forEach((widget) => {
            const card = widget.querySelector('.dashboard-widget-card');
            if (!card) return;
            widget.style.gridRowEnd = '';
            const span = Math.max(1, Math.ceil((card.getBoundingClientRect().height + rowGap) / (rowHeight + rowGap)));
            widget.style.gridRowEnd = 'span ' + span;
        });
    };
    const stopAutoScroll = () => {
        if (autoScrollTick) {
            cancelAnimationFrame(autoScrollTick);
            autoScrollTick = null;
        }
    };
    const runAutoScroll = () => {
        if (dragged === null || pointerY === null) {
            stopAutoScroll();
            return;
        }
        const edge = 100;
        const speed = 18;
        if (pointerY < edge) {
            window.scrollBy(0, -speed);
        } else if (pointerY > window.innerHeight - edge) {
            window.scrollBy(0, speed);
        }
        autoScrollTick = requestAnimationFrame(runAutoScroll);
    };
    document.addEventListener('dragover', (event) => {
        pointerY = event.clientY;
        if (dragged && !autoScrollTick) {
            autoScrollTick = requestAnimationFrame(runAutoScroll);
        }
    });
    widgets.forEach((widget) => {
        widget.addEventListener('dragstart', () => { dragged = widget; widget.classList.add('dragging'); if (!autoScrollTick) autoScrollTick = requestAnimationFrame(runAutoScroll); });
        widget.addEventListener('dragend', () => { widget.classList.remove('dragging'); dragged = null; pointerY = null; stopAutoScroll(); saveOrder(); layoutWidgets(); });
        widget.addEventListener('dragover', (event) => { event.preventDefault(); if (!dragged || dragged === widget) return; const box = widget.getBoundingClientRect(); grid.insertBefore(dragged, event.clientY < (box.top + box.height / 2) ? widget : widget.nextSibling); layoutWidgets(); });
        widget.querySelector('.dashboard-widget-toggle').addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); widget.classList.toggle('is-collapsed'); saveCollapsed(); layoutWidgets(); });
    });
    resetLayoutBtn?.addEventListener('click', function () {
        localStorage.removeItem(orderKey);
        localStorage.removeItem(collapseKey);
        window.location.reload();
    });
    layoutWidgets();
    window.addEventListener('resize', layoutWidgets);
    const chartCanvas = document.getElementById('growthChart');
    if (chartCanvas) {
        const styles = getComputedStyle(document.documentElement);
        new Chart(chartCanvas.getContext('2d'), {
            type: 'line',
            data: { labels: <?= json_encode($chartLabels) ?>, datasets: [
                { label: 'Uploads', data: <?= json_encode($chartUploads) ?>, borderColor: styles.getPropertyValue('--bs-primary').trim() || '#2563eb', backgroundColor: 'rgba(37, 99, 235, 0.10)', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 0 },
                { label: 'Downloads', data: <?= json_encode($chartDownloads) ?>, borderColor: styles.getPropertyValue('--bs-success').trim() || '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.10)', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 0 }
            ]},
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false }, ticks: { font: { size: 10 } } }, x: { grid: { display: false }, ticks: { font: { size: 10 } } } } }
        });
        setTimeout(layoutWidgets, 0);
    }
});
</script>

<?php include 'footer.php'; ?>
