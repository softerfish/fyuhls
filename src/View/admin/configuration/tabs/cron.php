<?php
$demoMode = \App\Model\Setting::get('demo_mode', '0') === '1';
$lastTimestamp = (int)\App\Model\Setting::get('last_cron_run_timestamp', 0);
$diff = time() - $lastTimestamp;
$isHealthy = ($lastTimestamp > 0 && $diff < 1860); // 31 minutes
?>
<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Cron Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#cron-status"><i class="bi bi-heart-pulse me-2"></i> Status</a>
            <a class="nav-link text-start" href="#cron-scheduled"><i class="bi bi-list-task me-2"></i> Scheduled Tasks</a>
            <a class="nav-link text-start" href="#cron-setup"><i class="bi bi-terminal me-2"></i> Server Setup</a>
            <a class="nav-link text-start" href="#cron-reference"><i class="bi bi-info-square me-2"></i> Reference Guide</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">Cron Jobs</h5>
                <p class="config-section-intro__text">Monitor the background engine, adjust task frequencies, and verify the server crontab that keeps cleanup, rewards, email, and storage maintenance moving.</p>
            </div>
            <ul class="config-summary-chips">
                <li class="config-summary-chip <?= $isHealthy ? 'config-summary-chip--success' : 'config-summary-chip--danger' ?>">Heartbeat: <?= $isHealthy ? 'Healthy' : 'Offline' ?></li>
                <li class="config-summary-chip config-summary-chip--info">Tasks: <?= count($tasks ?? []) ?> registered</li>
                <li class="config-summary-chip config-summary-chip--info">Runner: every minute</li>
            </ul>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>Fyuhls expects the cron runner to execute every minute. The heartbeat card below reflects when that engine last checked in, while the task table controls how often individual jobs actually run.</p>
            </div>
        </details>

<div id="cron-status"></div>
<div class="config-status-grid">
    <div>
        <?php renderAdminCardStart(null, ['bodyClass' => 'd-flex flex-column align-items-center justify-content-center text-center py-3', 'cardClass' => 'shadow-sm h-100 border-0 bg-light config-status-card']); ?>
                <div class="mb-2">
                    <?php if ($isHealthy): ?>
                        <div class="cron-health-icon rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto shadow-sm">
                            <i class="cron-health-icon-symbol bi bi-heart-pulse-fill"></i>
                        </div>
                        <h5 class="mt-3 text-success fw-bold">System Healthy</h5>
                        <p class="extra-small text-muted">Heartbeat detected <?= round($diff / 60) ?> mins ago.</p>
                    <?php else: ?>
                        <div class="cron-health-icon rounded-circle bg-danger text-white d-flex align-items-center justify-content-center mx-auto shadow-sm pulse-red">
                            <i class="cron-health-icon-symbol bi bi-exclamation-octagon-fill"></i>
                        </div>
                        <h5 class="mt-3 text-danger fw-bold">Cron Jobs Offline</h5>
                        <p class="extra-small text-muted">No heartbeat detected in over 31 mins. Check crontab.</p>
                    <?php endif; ?>
                </div>
        <?php renderAdminCardEnd(); ?>
    </div>

    <div>
        <div class="config-utility-zone h-100">
            <div class="config-utility-zone__title"><i class="bi bi-lightning-charge-fill me-2"></i>Utility Action</div>
            <p class="config-utility-zone__text">Immediately execute all registered tasks regardless of schedule. Use this to clear space, sync security, or sweep stale payment history now.</p>
            <form method="POST" action="/admin/cron/trigger" class="config-utility-zone__actions m-0">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-primary btn-sm px-4 shadow-sm fw-bold">
                    <i class="bi bi-play-circle me-1"></i> Trigger All Tasks Now
                </button>
            </form>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div id="cron-scheduled"></div>
        <?php renderAdminCardStart(null, ['headerHtml' => '<h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i> Scheduled Cron Jobs</h6>', 'bodyClass' => 'p-0', 'cardClass' => 'border-0 shadow-sm config-section-card']); ?>
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
                                'payment_cleanup'   => 'Marks package-purchase attempts that have been stuck in pending status for more than 24 hours as failed so account billing history stays clean.',
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
                                    <td class="cron-task-description extra-small text-muted">
                                        <?= htmlspecialchars($descriptions[$task['task_key']] ?? 'System background task for internal maintenance and synchronization.') ?>
                                    </td>
                                    <td>
                                        <div class="cron-interval-group input-group input-group-sm">
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
        <?php renderAdminCardEnd(); ?>
    </div>

    <div class="col-lg-4">
        <!-- Crontab Setup -->
        <div id="cron-setup"></div>
        <?php renderAdminCardStart(null, ['bodyClass' => 'p-3', 'cardClass' => 'shadow-sm border-0 bg-dark text-white mb-4 config-section-card']); ?>
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
        <?php renderAdminCardEnd(); ?>

        <!-- Task Reference -->
        <div id="cron-reference"></div>
        <?php renderAdminCardStart(null, ['headerHtml' => '<h6 class="mb-0 fw-bold small"><i class="bi bi-info-square me-2 text-primary"></i> Task Reference Guide</h6>', 'bodyClass' => 'p-0', 'cardClass' => 'shadow-sm border-0 config-section-card']); ?>
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
                                <span class="cron-task-key badge bg-light text-muted border extra-small"><?= $task['task_key'] ?></span>
                            </div>
                            <p class="extra-small text-muted mb-0"><?= $desc ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>
</div>
</div>

<style>
.pulse-red { animation: pulse-red 2s infinite; }
.cron-health-icon { width: 50px; height: 60px; }
.cron-health-icon-symbol { font-size: 1.5rem; }
.cron-task-description { min-width: 260px; }
.cron-interval-group { width: 110px; }
.cron-task-key { font-size: 0.6rem; }
.cron-quick-card { display:flex; flex-direction:column; justify-content:center; gap:1rem; min-height:100%; }
@keyframes pulse-red {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
}
.extra-small { font-size: 0.75rem; }
@media (min-width: 768px) {
    .cron-quick-card { flex-direction: row; align-items: center; justify-content: space-between; }
}
</style>
