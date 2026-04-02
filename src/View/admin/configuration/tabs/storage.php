<?php
$servers = $servers ?? [];
$demoAdmin = !empty($demoAdmin);
$totalServers = isset($totalServers) ? (int)$totalServers : count($servers);
$activeServers = isset($activeServers) ? (int)$activeServers : 0;
$totalUsed = isset($totalUsed) ? (float)$totalUsed : 0;
$totalLimit = isset($totalLimit) ? (float)$totalLimit : 0;
$usagePercent = isset($usagePercent) ? (float)$usagePercent : ($totalLimit > 0 ? round(($totalUsed / $totalLimit) * 100, 1) : 0);

foreach ($servers as &$server) {
    if (!empty($server['config']) && is_string($server['config'])) {
        $decrypted = \App\Service\EncryptionService::decrypt($server['config']);
        if ($decrypted) {
            $server['config'] = json_decode($decrypted, true);
        }
    }

    if (!empty($server['storage_path']) && str_starts_with((string)$server['storage_path'], 'ENC:')) {
        $server['storage_path'] = \App\Service\EncryptionService::decrypt((string)$server['storage_path']);
    }
}
unset($server);
?>

<div class="row g-4 mb-4">
    <!-- Infrastructure Overview -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-uppercase small fw-bold text-muted">Nodes Online</h6>
                <h3 class="fw-bold mb-0"><?= $activeServers ?> <small class="text-muted fw-normal">/ <?= $totalServers ?></small></h3>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-uppercase small fw-bold text-muted">Network Capacity</h6>
                <div class="d-flex align-items-center gap-3">
                    <div class="progress flex-grow-1" style="height: 10px;">
                        <div class="progress-bar <?= $usagePercent > 90 ? 'bg-danger' : 'bg-success' ?>" role="progressbar" style="width: <?= $usagePercent ?>%"></div>
                    </div>
                    <span class="fw-bold"><?= $usagePercent ?>%</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm small mb-4">
    <strong>Multipart Upload Requirement:</strong> Large uploads to B2, Wasabi, R2, and other S3-compatible storage are sent directly from your user's browser to the storage bucket, not through Fyuhls. That bucket must be configured to trust your website domain, allow browser upload requests (<code>PUT</code>) and follow-up checks (<code>GET</code> and <code>HEAD</code>), and let the browser read back the <code>ETag</code> value that confirms each uploaded part succeeded. If the bucket does not allow that, large browser uploads will fail even though the server itself is set up correctly.
</div>

<?php if ($demoAdmin): ?>
    <div class="alert alert-warning border-0 shadow-sm small mb-4">
        <strong>Demo admin mode:</strong> Storage configuration is read-only for this account. Add, migrate, edit, and connection actions are disabled.
    </div>
<?php endif; ?>

<!-- Storage Action Toolbar -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Storage Nodes</h5>
    <div class="btn-group">
        <a href="<?= $demoAdmin ? '#' : '/admin/file-server/add' ?>" class="btn btn-primary btn-sm px-3 <?= $demoAdmin ? 'disabled' : '' ?>" <?= $demoAdmin ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
            <i class="bi bi-plus-circle me-1"></i> Add Node
        </a>
        <a href="<?= $demoAdmin ? '#' : '/admin/file-server/migrate' ?>" class="btn btn-outline-dark btn-sm px-3 <?= $demoAdmin ? 'disabled' : '' ?>" <?= $demoAdmin ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
            <i class="bi bi-arrow-left-right me-1"></i> Migrate Files
        </a>
    </div>
</div>

<!-- Server Health Matrix -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">Server Name</th>
                    <th>Type</th>
                    <th>Storage Identifier</th>
                    <th>Storage Usage</th>
                    <th>Status</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($servers as $server): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold"><?= htmlspecialchars($server['name']) ?></div>
                            <small class="text-muted">ID: #<?= $server['id'] ?> <?= ($server['is_default'] ?? 0) == 1 ? '<span class="badge bg-info-subtle text-info px-2 py-0 ms-1">Default</span>' : '' ?></small>
                        </td>
                        <td><span class="badge bg-secondary-subtle text-secondary border"><?= strtoupper($server['server_type']) ?></span></td>
                        <td>
                            <?php
                            $type = $server['server_type'];
                            $cfg = $server['config'] ?? [];
                            if (in_array($type, ['s3', 'r2', 'wasabi', 'b2'])) {
                                $ep = $cfg['s3_endpoint'] ?? '';
                                if ($type === 'r2') {
                                    // Strip R2 suffix for a shorter 'Account ID' looking display
                                    $ep = str_replace('.r2.cloudflarestorage.com', '', $ep);
                                }
                                echo '<code class="small">' . htmlspecialchars($ep ?: 'not set') . '</code>';
                            } elseif ($type === 'local') {
                                echo '<code class="small">' . htmlspecialchars($server['storage_path'] ?? 'Local Disk') . '</code>';
                            } else {
                                echo '<code class="small">' . htmlspecialchars($server['public_url'] ?? 'N/A') . '</code>';
                            }
                            ?>
                        </td>
                        <td style="min-width: 150px;">
                            <?php 
                            $usageBytes = (float)($server['current_usage_bytes'] ?? 0);
                            $maxBytes = (float)($server['max_capacity_bytes'] ?? 0);
                            $sUsage = $maxBytes > 0 ? round(($usageBytes / $maxBytes) * 100, 1) : 0;
                            ?>
                            <div class="progress mb-1" style="height: 6px;">
                                <div class="progress-bar <?= $sUsage > 85 ? 'bg-danger' : 'bg-primary' ?>" style="width: <?= $sUsage ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between extra-small text-muted">
                                <span><?= $sUsage ?>%</span>
                                <span><?= round($usageBytes / 1024 / 1024 / 1024, 2) ?> GB / <?= round($maxBytes / 1024 / 1024 / 1024, 2) ?> GB</span>
                            </div>
                        </td>
                        <td>
                            <?php if ($server['status'] === 'active'): ?>
                                <span class="badge bg-success rounded-pill"><i class="bi bi-check-circle me-1"></i> Active</span>
                            <?php elseif ($server['status'] === 'read-only'): ?>
                                <span class="badge bg-info rounded-pill"><i class="bi bi-lock me-1"></i> Read-Only</span>
                            <?php else: ?>
                                <span class="badge bg-warning rounded-pill text-dark"><i class="bi bi-pause-circle me-1"></i> Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="table-actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-primary action-icon-btn" onclick="testServer(this, <?= $server['id'] ?>)" title="Ping Node" aria-label="Ping Node" <?= $demoAdmin ? 'disabled' : '' ?>>
                                    <i class="bi bi-plug"></i>
                                </button>
                                <a href="<?= $demoAdmin ? '#' : '/admin/file-server/edit/' . $server['id'] ?>" class="btn btn-sm btn-outline-dark action-icon-btn <?= $demoAdmin ? 'disabled' : '' ?>" title="Edit Config" aria-label="Edit Config" <?= $demoAdmin ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function testServer(btn, id) {
    if (btn.disabled) {
        return;
    }
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('server_id', id);
    formData.append('csrf_token', '<?= \App\Core\Csrf::generate() ?>');

    fetch('/admin/file-server/test', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message || 'Ping successful!');
    })
    .catch(e => alert('Connection failed: ' + e.message))
    .finally(() => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    });
}
</script>
