<?php include 'header.php'; ?>

<div class="page-header">
    <h1>DMCA Reports</h1>
</div>

<div class="card">
    <div class="card-header">DMCA Takedown Reports</div>
    <div class="card-body">
        <?php if (empty($reports)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">No DMCA reports yet.</p>
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
                            <td><a href="<?= htmlspecialchars($report['infringing_url']) ?>" target="_blank" style="color: var(--primary-color); text-decoration: none;"><?= htmlspecialchars($report['infringing_url']) ?></a></td>
                            <td><span class="badge" style="background: #fee2e2; color: #b91c1c; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;"><?= $report['status'] ?></span></td>
                            <td>
                                <button class="btn btn-sm" onclick="alert('Description: <?= addslashes(htmlspecialchars($report['description'])) ?>

Signature: <?= addslashes(htmlspecialchars($report['signature'])) ?>')">View Details</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
