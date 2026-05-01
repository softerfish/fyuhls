<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';
renderAdminPageHeader('Abuse Reports');
?>

<?php renderAdminCardStart('Recent Abuse Reports'); ?>
        <?php if (empty($reports)): ?>
            <p class="abuse-reports-empty">No abuse reports found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>File</th>
                        <th>Reason</th>
                        <th>Reporter IP</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($r['created_at'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($r['filename']) ?></strong><br>
                                <small>Hash: <?= $r['short_id'] ?></small>
                            </td>
                            <td><span class="abuse-reason-badge badge"><?= strtoupper($r['reason']) ?></span></td>
                            <td><?= $r['reporter_ip'] ?></td>
                            <td><?= strtoupper($r['status']) ?></td>
                            <td>
                                <div class="abuse-report-actions">
                                    <form method="POST" action="/admin/abuse-reports/action" data-confirm-message="Permanently delete this file?">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="action" value="delete_file">
                                        <button type="submit" class="abuse-report-delete btn btn-sm">Delete File</button>
                                    </form>
                                    <form method="POST" action="/admin/abuse-reports/action">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="action" value="ignore">
                                        <button type="submit" class="btn btn-sm">Ignore</button>
                                    </form>
                                    <button class="btn btn-sm" type="button" data-alert-message="Details: <?= addslashes(htmlspecialchars($r['details'])) ?>">View Details</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<style>
.abuse-reports-empty{text-align:center;color:#64748b;padding:2rem}
.abuse-reason-badge{background:#fee2e2;color:#991b1b}
.abuse-report-actions{display:flex;gap:.5rem}
.abuse-report-delete{color:var(--error-color)}
</style>

<?php include 'footer.php'; ?>
