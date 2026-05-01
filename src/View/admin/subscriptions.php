<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';
renderAdminPageHeader('Premium Subscriptions', '', '<button type="button" class="btn btn-primary" data-alert-message="Feature coming soon: Manual subscription creation">Add Subscription</button>');
?>

<?php renderAdminCardStart('Managed Subscriptions (Payments & Status)'); ?>
        <?php if (empty($subscriptions)): ?>
            <p class="subscriptions-empty-state">No subscriptions found in the system yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Package</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Gateway</th>
                        <th>Expires At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subscriptions as $sub): ?>
                        <tr>
                            <td><?= date('Y-m-d', strtotime($sub['created_at'])) ?></td>
                            <td><strong><?= htmlspecialchars($sub['username']) ?></strong></td>
                            <td><?= htmlspecialchars($sub['package_name']) ?></td>
                            <td><?= $sub['amount'] ?> <?= $sub['currency'] ?></td>
                            <?php $statusClass = $sub['status'] === 'active' ? 'subscriptions-status-active' : 'subscriptions-status-inactive'; ?>
                            <td><span class="subscriptions-status-badge badge <?= $statusClass ?>"><?= strtoupper($sub['status']) ?></span></td>
                            <td><?= strtoupper($sub['gateway']) ?></td>
                            <td><?= date('Y-m-d', strtotime($sub['expires_at'])) ?></td>
                            <td>
                                <button class="btn btn-sm">Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<style>
.subscriptions-empty-state{text-align:center;color:#64748b;padding:2rem}
.subscriptions-status-badge{padding:.25rem .5rem;border-radius:4px;font-size:.75rem}
.subscriptions-status-active{background:#dcfce7;color:#166534}
.subscriptions-status-inactive{background:#fee2e2;color:#991b1b}
</style>

<?php include 'footer.php'; ?>
