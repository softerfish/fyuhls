<?php include 'header.php'; ?>

<div class="page-header mb-4">
    <h1>Storage Health Monitoring</h1>
    <a href="/admin" class="btn btn-outline-secondary btn-sm">&larr; Back to Dashboard</a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold">Connection History (Last 50)</h5>
        <div class="small text-muted">Auto-refreshes every 60 minutes via Cron.</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light small">
                    <tr>
                        <th class="ps-4">Time</th>
                        <th>Server Name</th>
                        <th>Status</th>
                        <th>Response Time</th>
                        <th class="pe-4">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-search d-block mb-2 fs-3"></i>
                                No monitoring data found yet. Ensure your Cron heartbeat is active.
                            </td>
                        </tr>
                    <?php else: foreach ($logs as $log): ?>
                        <tr>
                            <td class="ps-4 small text-muted"><?= date('M d, H:i:s', strtotime($log['checked_at'])) ?></td>
                            <td>
                                <span class="fw-bold"><?= htmlspecialchars($log['server_name']) ?></span>
                                <div class="extra-small text-muted">ID: #<?= $log['server_id'] ?></div>
                            </td>
                            <td>
                                <?php if ($log['status'] === 'online'): ?>
                                    <span class="badge bg-success-subtle text-success border-0">ONLINE</span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-white border-0">OFFLINE</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace small">
                                <?= $log['status'] === 'online' ? $log['response_time_ms'] . 'ms' : '--' ?>
                            </td>
                            <td class="pe-4">
                                <?php if ($log['status'] === 'offline' && !empty($log['error_message'])): ?>
                                    <div class="text-danger extra-small" title="<?= htmlspecialchars($log['error_message']) ?>">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <?= htmlspecialchars(substr($log['error_message'], 0, 60)) ?><?= strlen($log['error_message']) > 60 ? '...' : '' ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted extra-small">No issues detected.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card bg-light border-0 shadow-sm">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>How Monitoring Works</h6>
                <p class="extra-small text-muted mb-0">
                    The <strong>Automation Heartbeat</strong> runs every 60 minutes. It attempts to connect to each storage node (Local, S3, B2, etc.) using your configured API keys and paths. If a connection fails, a record is added here and a warning may appear on your dashboard.
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card bg-light border-0 shadow-sm">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-gear-fill me-2 text-secondary"></i>Log Configuration</h6>
                <form method="POST" action="/admin/configuration/save" class="row g-2">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="col-8">
                        <label class="extra-small text-muted d-block mb-1">Max Logs to Display</label>
                        <input type="number" name="monitoring_log_limit" class="form-control form-control-sm" value="<?= \App\Model\Setting::get('monitoring_log_limit', '50') ?>">
                    </div>
                    <div class="col-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-secondary btn-sm w-100">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
