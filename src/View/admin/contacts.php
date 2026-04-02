<?php include 'header.php'; ?>

<div class="card">
    <div class="card-header">Contact Messages</div>
    <div class="card-body">
        <?php if (empty($messages)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">No contact messages yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Name/Email</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($msg['created_at'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($msg['name']) ?></strong><br>
                                <small><?= htmlspecialchars($msg['email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($msg['subject']) ?></td>
                            <td><span class="badge" style="background: #e2e8f0; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;"><?= $msg['status'] ?></span></td>
                            <td>
                                <button class="btn btn-sm" onclick="alert('<?= addslashes(htmlspecialchars($msg['message'])) ?>')">View Message</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
