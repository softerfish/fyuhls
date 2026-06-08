<?php
$title = 'Current Live Downloads';
$canAccessLiveDownloadIdentities = !empty($canAccessLiveDownloadIdentities);
$canAccessLiveDownloadFileDetails = !empty($canAccessLiveDownloadFileDetails);
include __DIR__ . '/header.php';
include __DIR__ . '/partials/shell_helpers.php';
ob_start();
?>
    <div>
        <span class="badge bg-primary" id="activeCount">0 Active</span>
        <button class="btn btn-sm btn-outline-secondary ms-2" type="button" id="refreshBtn">
            <i class="bi bi-arrow-clockwise"></i> Refresh Now
        </button>
    </div>
<?php
$currentDownloadsActions = ob_get_clean();
renderAdminPageHeader('Current Live Downloads', '', $currentDownloadsActions);
?>

<?php if (\App\Model\Setting::get('track_current_downloads', '0') !== '1'): ?>
<div class="alert alert-warning">
    Tracking current downloads is currently <strong>disabled</strong> in Site Settings. No connections will appear here until it is enabled.
</div>
<?php endif; ?>

<?php if (!$canAccessLiveDownloadIdentities): ?>
<div class="alert alert-info">
    Raw downloader identities are hidden unless your account also has investigations, abuse-review, support, or configuration diagnostics access.
</div>
<?php endif; ?>

<?php if (!$canAccessLiveDownloadFileDetails): ?>
<div class="alert alert-info">
    File names and direct file links are hidden unless your account also has moderation, investigations, support, or configuration diagnostics access.
</div>
<?php endif; ?>

<?php renderAdminCardStart(null, ['bodyClass' => 'card-body p-0']); ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="downloadsTable">
                <thead class="table-light">
                    <tr>
                        <th><?= $canAccessLiveDownloadFileDetails ? 'File ID' : 'File Scope' ?></th>
                        <th><?= $canAccessLiveDownloadFileDetails ? 'Filename' : 'Filename (Restricted)' ?></th>
                        <th><?= (!empty($demoAdmin) || !$canAccessLiveDownloadIdentities) ? 'Downloader IP (Masked)' : 'Downloader IP' ?></th>
                        <th><?= $canAccessLiveDownloadIdentities ? 'User ID' : 'Viewer Scope' ?></th>
                        <th>Started At</th>
                        <th>Time Elapsed</th>
                    </tr>
                </thead>
                <tbody id="downloadsBody">
                    <tr><td colspan="6" class="text-center text-muted py-4">Loading active connections...</td></tr>
                </tbody>
            </table>
        </div>
<?php renderAdminCardEnd(); ?>

<script>
let refreshTimer;

function escapeHtml(unsafe) {
    if (unsafe == null) return '';
    return unsafe.toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

function timeSince(dateString) {
    // Basic UTC to local diff
    const tParts = dateString.split(/[- :]/);
    const date = new Date(Date.UTC(tParts[0], tParts[1]-1, tParts[2], tParts[3], tParts[4], tParts[5]));
    const seconds = Math.floor((new Date() - date) / 1000);

    let interval = seconds / 3600;
    if (interval > 1) return Math.floor(interval) + " hours";
    interval = seconds / 60;
    if (interval > 1) return Math.floor(interval) + " mins";
    return Math.floor(seconds) + " secs";
}

function refreshDownloads() {
    clearTimeout(refreshTimer);
    const btn = document.getElementById('refreshBtn');
    if (btn) btn.disabled = true;

    fetch('/admin/downloads/current/json')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('downloadsBody');
            document.getElementById('activeCount').innerText = data.length + ' Active';

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No active file downloads currently.</td></tr>';
            } else {
                tbody.innerHTML = data.map(row => `
                    <tr>
                        <td>${row.file_id ? ('#' + escapeHtml(row.file_id)) : '<span class="badge bg-secondary">Restricted</span>'}</td>
                        <td>
                            ${row.short_id ? '<a href="/file/' + escapeHtml(row.short_id || row.file_id) + '" target="_blank" class="text-decoration-none">' + escapeHtml(row.filename || 'Unknown File') + '</a>' : '<span class="text-muted">' + escapeHtml(row.filename || 'Restricted file') + '</span>'}
                        </td>
                        <td><code>${escapeHtml(row.ip_address)}</code></td>
                        <td>${row.user_id ? '<span class="badge bg-info text-dark">User #' + escapeHtml(row.user_id) + '</span>' : '<span class="badge bg-secondary">' + (<?= $canAccessLiveDownloadIdentities ? 'false' : 'true' ?> ? 'Masked viewer' : 'Guest') + '</span>'}</td>
                        <td>${escapeHtml(row.started_at)}</td>
                        <td><span class="text-success fw-medium">${timeSince(row.started_at)}</span></td>
                    </tr>
                `).join('');
            }
        })
        .catch(err => {
            console.error('Failed to load downloads:', err);
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            // Auto refresh every 5 seconds
            refreshTimer = setTimeout(refreshDownloads, 5000);
        });
}

// Initial load
document.addEventListener('DOMContentLoaded', function() {
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', refreshDownloads);
    }

    refreshDownloads();
});

</script>

<?php include __DIR__ . '/footer.php'; ?>
