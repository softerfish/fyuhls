<?php
include __DIR__ . '/../header.php';
include __DIR__ . '/../partials/shell_helpers.php';
?>

<?php renderAdminPageHeader('File Investigation: ' . (string)($file['filename'] ?? 'File')); ?>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <?php renderAdminCardStart('File'); ?>
            <div class="small text-muted mb-2">File #<?= (int)($file['id'] ?? 0) ?></div>
            <div class="fw-semibold fs-5"><?= htmlspecialchars((string)($file['filename'] ?? '')) ?></div>
            <div class="text-muted mt-1">Owner: <a href="/admin/investigations/uploader/<?= (int)($file['uploader_id'] ?? 0) ?>"><?= htmlspecialchars((string)($file['username'] ?? 'Unknown')) ?></a></div>
            <div class="mt-3 small">
                <div><strong>Status:</strong> <?= htmlspecialchars((string)($file['status'] ?? 'active')) ?></div>
                <div><strong>Downloads:</strong> <?= number_format((int)($file['downloads'] ?? 0)) ?></div>
                <div><strong>Created:</strong> <?= htmlspecialchars(date('Y-m-d', strtotime((string)($file['created_at'] ?? 'now')))) ?></div>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>
    <div class="col-lg-8">
        <?php renderAdminCardStart('30-Day Reward Snapshot'); ?>
            <div class="row g-3">
                <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Reward total</div><div class="fw-semibold">$<?= number_format((float)($summary['reward_total_30d'] ?? 0), 4) ?></div></div></div>
                <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Reward rows</div><div class="fw-semibold"><?= number_format((int)($summary['reward_rows_30d'] ?? 0)) ?></div></div></div>
                <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Rows still in review</div><div class="fw-semibold"><?= number_format((int)($summary['review_rows_30d'] ?? 0)) ?></div></div></div>
                <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Cleared rows</div><div class="fw-semibold"><?= number_format((int)($summary['cleared_rows_30d'] ?? 0)) ?></div></div></div>
                <div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Paid rows</div><div class="fw-semibold"><?= number_format((int)($summary['paid_rows_30d'] ?? 0)) ?></div></div></div>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <?php renderAdminCardStart('Top Referring Pages (30d)'); ?>
            <?php if (empty($referrers)): ?>
                <p class="text-muted mb-0">No recent referring-page history yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Referrer</th><th>Sessions</th><th>Reward Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($referrers as $referrer): ?>
                                <tr>
                                    <td><a href="<?= htmlspecialchars((string)$referrer['download_page_referrer_url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$referrer['download_page_referrer_url']) ?></a></td>
                                    <td><?= number_format((int)($referrer['session_count'] ?? 0)) ?></td>
                                    <td>$<?= number_format((float)($referrer['reward_total'] ?? 0), 4) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php renderAdminCardEnd(); ?>
    </div>
    <div class="col-xl-6">
        <?php renderAdminCardStart('Top Countries (30d)'); ?>
            <?php if (empty($countries)): ?>
                <p class="text-muted mb-0">No recent country breakdown yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Country</th><th>Sessions</th></tr></thead>
                        <tbody>
                            <?php foreach ($countries as $country): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($country['country_code'] ?: 'Unknown')) ?></td>
                                    <td><?= number_format((int)($country['session_count'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php renderAdminCardEnd(); ?>
    </div>
    <div class="col-xl-7">
        <?php renderAdminCardStart('Recent Reward Rows'); ?>
            <?php if (empty($recentEarnings)): ?>
                <p class="text-muted mb-0">No recent reward rows for this file yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>When</th><th>Status</th><th>Risk</th><th>Amount</th><th>Review Note</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentEarnings as $earning): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)($earning['created_at'] ?? 'now')))) ?></td>
                                    <td><?= htmlspecialchars((string)($earning['status'] ?? 'pending')) ?></td>
                                    <td><?= number_format((float)($earning['risk_score'] ?? 0), 1) ?></td>
                                    <td>$<?= number_format((float)($earning['amount'] ?? 0), 4) ?></td>
                                    <td><?= htmlspecialchars((string)($earning['review_note'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php renderAdminCardEnd(); ?>
    </div>
    <div class="col-xl-5">
        <?php renderAdminCardStart('Recent Staff Actions'); ?>
            <?php if (empty($staffActivity)): ?>
                <p class="text-muted mb-0">No recent staff actions were tied directly to this file.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>When</th><th>Actor</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($staffActivity as $activity): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)($activity['created_at'] ?? 'now')))) ?></td>
                                    <td><?= htmlspecialchars((string)($activity['username'] ?? 'Staff')) ?></td>
                                    <td><?= htmlspecialchars((string)($activity['details'] ?? str_replace('_', ' ', (string)($activity['action'] ?? 'activity')))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
