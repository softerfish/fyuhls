<?php include 'header.php'; ?>

<div class="page-header">
    <h1>DMCA Reports</h1>
</div>

<div class="card">
    <div class="card-header">DMCA Takedown Reports</div>
    <div class="card-body">
        <?php if (empty($reports)): ?>
            <p class="dmca-empty-state">No DMCA reports yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reporter</th>
                        <th>Infringing URL</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($report['created_at'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($report['reporter_name']) ?></strong><br>
                                <small><?= htmlspecialchars($report['reporter_email']) ?></small>
                            </td>
                            <td><a href="<?= htmlspecialchars($report['infringing_url']) ?>" target="_blank" class="dmca-link"><?= htmlspecialchars($report['infringing_url']) ?></a></td>
                            <td><span class="dmca-status-badge badge"><?= $report['status'] ?></span></td>
                            <td>
                                <button class="btn btn-sm" type="button" data-alert-message="Description: <?= htmlspecialchars($report['description'], ENT_QUOTES, 'UTF-8') ?>&#10;&#10;Signature: <?= htmlspecialchars($report['signature'], ENT_QUOTES, 'UTF-8') ?>">View Details</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.dmca-empty-state{text-align:center;color:#64748b;padding:2rem}
.dmca-link{color:var(--primary-color);text-decoration:none}
.dmca-status-badge{background:#fee2e2;color:#b91c1c;padding:.25rem .5rem;border-radius:4px;font-size:.75rem}
</style>

<?php include 'footer.php'; ?>
