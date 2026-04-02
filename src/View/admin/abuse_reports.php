<?php include 'header.php'; ?>

<div class="page-header">
    <h1>Abuse Reports</h1>
</div>

<div class="card">
    <div class="card-header">Recent Abuse Reports</div>
    <div class="card-body">
        <?php if (empty($reports)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">No abuse reports found.</p>
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
                            <td><span class="badge" style="background: #fee2e2; color: #991b1b;"><?= strtoupper($r['reason']) ?></span></td>
                            <td><?= $r['reporter_ip'] ?></td>
                            <td><?= strtoupper($r['status']) ?></td>
                            <td>
                                <div style="display: flex; gap: 0.5rem;">
                                    <form method="POST" action="/admin/abuse-reports/action" onsubmit="return confirm('Permanently delete this file?')">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="action" value="delete_file">
                                        <button type="submit" class="btn btn-sm" style="color: var(--error-color);">Delete File</button>
                                    </form>
                                    <form method="POST" action="/admin/abuse-reports/action">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <input type="hidden" name="action" value="ignore">
                                        <button type="submit" class="btn btn-sm">Ignore</button>
                                    </form>
                                    <button class="btn btn-sm" onclick="alert('Details: <?= addslashes(htmlspecialchars($r['details'])) ?>')">View Details</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
