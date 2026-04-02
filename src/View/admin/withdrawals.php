<?php include 'header.php'; ?>

<div class="page-header">
    <h1>Withdrawal Requests</h1>
</div>

<div class="card">
    <div class="card-header">Pending & Recent Withdrawals</div>
    <div class="card-body">
        <?php if (empty($withdrawals)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">No withdrawal requests found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td><?= date('Y-m-d', strtotime($w['created_at'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($w['username']) ?></strong><br>
                                <small><?= htmlspecialchars($w['user_email']) ?></small>
                            </td>
                            <td><strong>$<?= number_format($w['amount'], 2) ?></strong></td>
                            <td><?= strtoupper($w['method']) ?></td>
                            <td>
                                <span class="badge" style="background: <?= $w['status'] === 'pending' ? '#fef3c7; color: #92400e;' : ($w['status'] === 'paid' ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;') ?> padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                    <?= strtoupper($w['status']) ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm" onclick="showWithdrawalDetails(<?= $w['id'] ?>, '<?= addslashes(htmlspecialchars($w['details'])) ?>', '<?= $w['status'] ?>')">Manage</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Simple Management Modal (Simulated with JS for prototype) -->
<div id="wModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center;">
    <div style="background: white; padding: 2rem; border-radius: 12px; width: 400px; max-width: 90%; shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <h3>Manage Withdrawal</h3>
        <form method="POST" action="/admin/withdrawal/update">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="id" id="modalWId">
            <div class="form-group">
                <label>Payment Details</label>
                <pre id="modalWDetails" style="background: #f8fafc; color: #1e293b;"></pre>
            </div>
            <div class="form-group">
                <label>Update Status</label>
                <select name="status" id="modalWStatus">
                    <option value="pending">Pending</option>
                    <option value="approved">Approved (Wait for payment)</option>
                    <option value="paid">Mark as PAID</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <div class="form-group">
                <label>Admin Note (Visible to User)</label>
                <textarea name="admin_note" rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" class="btn" onclick="document.getElementById('wModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function showWithdrawalDetails(id, details, status) {
    document.getElementById('modalWId').value = id;
    document.getElementById('modalWDetails').innerText = details;
    document.getElementById('modalWStatus').value = status;
    document.getElementById('wModal').style.display = 'flex';
}
</script>

<?php include 'footer.php'; ?>
