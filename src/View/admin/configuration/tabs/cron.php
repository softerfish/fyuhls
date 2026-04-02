<?php $demoMode = \App\Model\Setting::get('demo_mode', '0') === '1'; ?>
<div class="row g-4 mb-4">
    <!-- Health Monitor -->
    <div class="col-md-4">
        <div class="card shadow-sm h-100 border-0 bg-light">
            <div class="card-body d-flex flex-column align-items-center justify-content-center text-center py-4">
                <?php 
                $lastTimestamp = (int)\App\Model\Setting::get('last_cron_run_timestamp', 0);
                $diff = time() - $lastTimestamp;
                $isHealthy = ($lastTimestamp > 0 && $diff < 1860); // 31 minutes
                ?>

                <div class="mb-2">
                    <?php if ($isHealthy): ?>
                        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto shadow-sm" style="width: 50px; height: 60px;">
                            <i class="bi bi-heart-pulse-fill" style="font-size: 1.5rem;"></i>
                        </div>
                        <h5 class="mt-3 text-success fw-bold">System Healthy</h5>
                        <p class="extra-small text-muted">Heartbeat detected <?= round($diff / 60) ?> mins ago.</p>
                    <?php else: ?>
                        <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center mx-auto shadow-sm pulse-red" style="width: 50px; height: 60px;">
                            <i class="bi bi-exclamation-octagon-fill" style="font-size: 1.5rem;"></i>
                        </div>
                        <h5 class="mt-3 text-danger fw-bold">Cron Jobs Offline</h5>
                        <p class="extra-small text-muted">No heartbeat detected in over 31 mins. Check crontab.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Action Card -->
    <div class="col-md-8">
        <div class="card shadow-sm h-100 border-0 bg-primary bg-opacity-10">
            <div class="card-body d-flex flex-column justify-content-center">
                <h6 class="text-primary fw-bold mb-1"><i class="bi bi-lightning-charge-fill me-2"></i>Force Run All Tasks</h6>
                <p class="extra-small text-muted mb-3">Immediately executes all registered tasks regardless of their schedule. Use this to clear space or sync security now.</p>
                <form method="POST" action="/admin/cron/trigger" class="m-0">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm fw-bold">
                        <i class="bi bi-play-circle me-1"></i> Trigger All Tasks Now
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-bottom-0">
                <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i> Scheduled Cron Jobs</h6>
            </div>
            <div class="table-responsive">
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="cron">
                    
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light extra-small text-uppercase fw-bold text-muted">
                            <tr>
                                <th class="ps-4">Managed Task</th>
                                <th>Description</th>
                                <th>Frequency</th>
                                <th>Last Execution</th>
                                <th class="text-end pe-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $descriptions = [
                                'cleanup'           => 'Deletes expired files, clears old upload chunks, and removes stale temporary data.',
                                'cf_sync'           => 'Refreshes trusted Cloudflare IP ranges for proxy-aware security decisions.',
                                'rl_purge'          => 'Purges old rate-limit records so the security tables do not grow forever.',
                                'account_downgrade' => 'Downgrades expired premium accounts to the correct package.',
                                'account_expiry'    => 'Sends reminder emails before premium accounts expire.',
                                'server_monitoring' => 'Checks active storage nodes for uptime, latency, and connectivity failures.',
                                'mail_queue'        => 'Processes queued outbound email in background batches.',
                                'reward_flush'      => 'Flushes reward queue events into permanent reward records.',
                                'reward_rollup'     => 'Builds reward and affiliate history summaries for reporting.',
                                'db_health'         => 'Checks the database schema for missing tables, columns, or drift issues.',
                                'log_purge'         => 'Rotates or removes older application log data.',
                                'file_purge'        => 'Permanently removes files that were already marked for background deletion.',
                                'storage_audit'     => 'Recalculates user storage totals against actual stored files.',
                                'security_purge'    => 'Purges stale security caches and related temporary security data.',
                                'refresh_stats'     => 'Refreshes dashboard/system statistics and trims old history.',
                                'remote_uploads'    => 'Processes queued remote URL imports in background batches so browser requests do not have to wait for large external downloads.',
                                'nginx_download_logs' => 'Reads the dedicated Nginx completion log so accelerated standard-file downloads can be reconciled for cleanup and threshold-based PPD credit.',
                                'upload_sessions'   => 'Expires abandoned multipart upload sessions and releases reserved quota.',
                                'upload_reconcile'  => 'Repairs multipart upload sessions that stalled during state changes.',
                                'checksum_jobs'     => 'Marks completed uploads as checksum-verified after reconciliation work.'
                            ];
                            ?>
                            <?php foreach ($tasks as $task): ?>
                                <?php if (str_contains($task['task_key'], 'reward') && !\App\Service\FeatureService::rewardsEnabled()) continue; ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold small"><?= htmlspecialchars($task['task_name']) ?></div>
                                        <code class="extra-small text-muted"><?= $task['task_key'] ?></code>
                                    </td>
                                    <td class="extra-small text-muted" style="min-width: 260px;">
                                        <?= htmlspecialchars($descriptions[$task['task_key']] ?? 'System background task for internal maintenance and synchronization.') ?>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm" style="width: 110px;">
                                            <input type="number" class="form-control" name="intervals[<?= $task['task_key'] ?>]" value="<?= $task['interval_mins'] ?>">
                                            <span class="input-group-text extra-small">min</span>
                                        </div>
                                    </td>
                                    <td class="extra-small text-muted">
                                        <?= $task['last_run_at'] ? date('M j, H:i', strtotime($task['last_run_at'])) : 'Never' ?>
                                        <div class="opacity-50"><?= number_format($task['execution_time'], 3) ?>s</div>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if ($task['last_status'] === 'success'): ?>
                                            <i class="bi bi-check-circle-fill text-success" title="Success"></i>
                                        <?php elseif ($task['last_status'] === 'failed'): ?>
                                            <i class="bi bi-x-circle-fill text-danger" title="<?= htmlspecialchars($task['last_error'] ?? '') ?>"></i>
                                        <?php else: ?>
                                            <i class="bi bi-clock text-muted"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="card-footer bg-white py-3 border-top">
                        <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm fw-bold">
                            <i class="bi bi-save me-2"></i> Save Frequencies
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Crontab Setup -->
        <div class="card shadow-sm border-0 bg-dark text-white mb-4">
            <div class="card-body p-3">
                <h6 class="fw-bold small mb-2"><i class="bi bi-terminal me-2"></i>Server Crontab Setup</h6>
                <p class="extra-small text-white-50 mb-3">Add this entry to your server to enable the engine. Set to <code>Every Minute</code>.</p>
                <div class="bg-black bg-opacity-50 p-2 rounded extra-small font-monospace mb-2 text-break">
                    <?php if ($demoMode): ?>
                        * * * * * php /path/to/fyuhls/src/Cron/Run.php
                    <?php else: ?>
                        * * * * * php <?= BASE_PATH . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Cron' . DIRECTORY_SEPARATOR . 'Run.php' ?>
                    <?php endif; ?>
                </div>
                <div class="extra-small text-info"><i class="bi bi-info-circle me-1"></i> <?= $demoMode ? 'Demo mode hides the real server path. Replace the example path with your actual Fyuhls install path.' : 'Paste this into your cPanel "Cron Jobs" section.' ?></div>
            </div>
        </div>

        <!-- Task Reference -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold small"><i class="bi bi-info-square me-2 text-primary"></i> Task Reference Guide</h6>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($tasks as $task): ?>
                        <?php 
                        // Skip Reward tasks if Rewards is disabled
                        if (str_contains($task['task_key'], 'reward') && !\App\Service\FeatureService::rewardsEnabled()) continue;
                        
                        $desc = $descriptions[$task['task_key']] ?? 'System background task for internal maintenance and synchronization.';
                        ?>
                        <div class="list-group-item border-0 py-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="fw-bold extra-small text-primary"><?= htmlspecialchars($task['task_name']) ?></div>
                                <span class="badge bg-light text-muted border extra-small" style="font-size: 0.6rem;"><?= $task['task_key'] ?></span>
                            </div>
                            <p class="extra-small text-muted mb-0"><?= $desc ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.pulse-red { animation: pulse-red 2s infinite; }
@keyframes pulse-red {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
}
.extra-small { font-size: 0.75rem; }
</style>
