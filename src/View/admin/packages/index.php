<?php
include dirname(__DIR__) . '/header.php';
include dirname(__DIR__) . '/partials/shell_helpers.php';
renderAdminPageHeader('User Packages', 'Edit the built-in package limits and feature flags used across registrations, downloads, and uploads.', '<span class="text-muted small">Custom package creation is not available yet.</span>');
?>

<?php renderAdminCardStart('Managed Packages (Limits & Features)'); ?>
        <table>
            <thead>
                <tr>
                    <th>Package Name</th>
                    <th>Price</th>
                    <th>Type</th>
                    <th>Max Upload</th>
                    <th>Max Storage</th>
                    <th>Concurrent Downloads</th>
                    <th>Wait Time</th>
                    <th>Expiry</th>
                    <th>Ads</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $pkg): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($pkg['name']) ?></strong></td>
                        <td>$<?= number_format((float)($pkg['price'] ?? 0), 2) ?></td>
                        <td><?= strtoupper($pkg['level_type']) ?></td>
                        <td><?= $pkg['max_upload_size'] > 0 ? round($pkg['max_upload_size'] / 1024 / 1024, 0) . ' MB' : 'Unlimited' ?></td>
                        <td><?= $pkg['max_storage_bytes'] > 0 ? round($pkg['max_storage_bytes'] / 1024 / 1024 / 1024, 1) . ' GB' : 'Unlimited' ?></td>
                        <td><?= ((int)($pkg['concurrent_downloads'] ?? 1)) > 0 ? (int)$pkg['concurrent_downloads'] : 'Unlimited' ?></td>
                        <td><?= (int)$pkg['wait_time'] ?>s</td>
                        <td><?= $pkg['file_expiry_days'] > 0 ? (int)$pkg['file_expiry_days'] . ' Days' : 'Never' ?></td>
                        <td><?= !empty($pkg['show_ads']) ? 'Yes' : 'No' ?></td>
                        <td>
                            <a href="/admin/package/edit/<?= $pkg['id'] ?>" class="btn">Edit Limits</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
<?php renderAdminCardEnd(); ?>

<?php include dirname(__DIR__) . '/footer.php'; ?>
