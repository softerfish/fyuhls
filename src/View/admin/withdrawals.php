<?php include 'header.php'; ?>

<div class="page-header">
    <h1>Withdrawal Requests</h1>
</div>

<div class="card">
    <div class="card-header">Pending & Recent Withdrawals</div>
    <div class="card-body">
        <?php if (empty($withdrawals)): ?>
            <p class="withdrawals-empty-state">No withdrawal requests found.</p>
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
                                <?php $statusClass = $w['status'] === 'pending' ? 'withdrawal-status-pending' : ($w['status'] === 'paid' ? 'withdrawal-status-paid' : 'withdrawal-status-rejected'); ?>
                                <span class="badge withdrawal-status-badge <?= $statusClass ?>">
                                    <?= strtoupper($w['status']) ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm" type="button" data-withdrawal-id="<?= (int)$w['id'] ?>" data-withdrawal-details="<?= htmlspecialchars($w['details'], ENT_QUOTES, 'UTF-8') ?>" data-withdrawal-status="<?= htmlspecialchars($w['status'], ENT_QUOTES, 'UTF-8') ?>">Manage</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Simple Management Modal (Simulated with JS for prototype) -->
<div id="wModal" class="withdrawal-modal-shell">
    <div class="withdrawal-modal-card">
        <h3>Manage Withdrawal</h3>
        <form method="POST" action="/admin/withdrawal/update">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="id" id="modalWId">
            <div class="form-group">
                <label>Payment Details</label>
                <pre id="modalWDetails" class="withdrawal-modal-details"></pre>
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
            <div class="withdrawal-modal-actions">
                <button type="button" class="btn" id="closeWithdrawalModalBtn">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<style>
.withdrawals-empty-state{text-align:center;color:#64748b;padding:2rem}
.withdrawal-status-badge{padding:.25rem .5rem;border-radius:4px;font-size:.75rem}
.withdrawal-status-pending{background:#fef3c7;color:#92400e}
.withdrawal-status-paid{background:#dcfce7;color:#166534}
.withdrawal-status-rejected{background:#fee2e2;color:#991b1b}
.withdrawal-modal-shell{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;align-items:center;justify-content:center}
.withdrawal-modal-card{background:#fff;padding:2rem;border-radius:12px;width:400px;max-width:90%;box-shadow:0 20px 25px -5px rgba(0,0,0,.1)}
.withdrawal-modal-details{background:#f8fafc;color:#1e293b}
.withdrawal-modal-actions{display:flex;gap:1rem;justify-content:flex-end}
</style>

<script>
function showWithdrawalDetails(id, details, status) {
    document.getElementById('modalWId').value = id;
    document.getElementById('modalWDetails').innerText = details;
    document.getElementById('modalWStatus').value = status;
    document.getElementById('wModal').style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-withdrawal-id]').forEach(function(button) {
        button.addEventListener('click', function() {
            showWithdrawalDetails(
                button.getAttribute('data-withdrawal-id') || '',
                button.getAttribute('data-withdrawal-details') || '',
                button.getAttribute('data-withdrawal-status') || ''
            );
        });
    });

    const closeButton = document.getElementById('closeWithdrawalModalBtn');
    const modal = document.getElementById('wModal');
    if (closeButton && modal) {
        closeButton.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    }
});
</script>

<?php include 'footer.php'; ?>
