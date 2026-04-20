<?php include __DIR__ . '/../header.php'; ?>
<?php
$buttonFormStyle = 'display:inline-flex; margin:0;';
$buttonStyle = 'padding: 0.35rem 0.75rem; font-size: 0.8125rem;';
?>

<style>
    .plugins-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .plugins-upload-form {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }
    .plugins-file-input { font-size: 0.875rem; }
    .plugins-upload-btn { white-space: nowrap; }
    .plugins-empty {
        color: var(--text-muted);
        text-align: center;
        padding: 2rem 0;
    }
    .plugins-description {
        color: var(--text-muted);
        font-size: 0.875rem;
    }
    .plugins-status-muted { color: var(--text-muted); }
    .plugins-status-active { color: #10b981; font-weight: 600; }
    .plugins-status-inactive { color: #f59e0b; }
    .plugins-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .plugins-btn-form { display: inline-flex; margin: 0; }
    .plugins-btn {
        padding: 0.35rem 0.75rem;
        font-size: 0.8125rem;
    }
    .plugins-btn-disabled {
        padding: 0.35rem 0.75rem;
        font-size: 0.8125rem;
        color: #9ca3af;
        border-color: #e5e7eb;
        cursor: not-allowed;
    }
    .plugins-btn-danger {
        color: #ef4444;
        border-color: #ef4444;
    }
</style>

<div class="page-header">
    <h1>Plugin Manager</h1>
</div>


<div class="card">
    <div class="card-header plugins-card-header">
        <span>plugins</span>
        <form method="POST" action="/admin/plugins/upload" enctype="multipart/form-data" class="plugins-upload-form">
            <?= \App\Core\Csrf::field() ?>
            <input type="file" name="plugin_zip" accept=".zip" required class="plugins-file-input">
            <button type="submit" class="btn btn-primary plugins-upload-btn">upload plugin</button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($plugins)): ?>
            <p class="plugins-empty">
                No plugins found. Add your own optional plugin ZIP here or place a custom plugin folder in <code>src/Plugin/</code>. Core features such as Rewards and 2FA are already built into the script.
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>name</th>
                        <th>version</th>
                        <th>description</th>
                        <th>status</th>
                        <th>actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $dir => $plugin): ?>
                        <?php $meta = $plugin['meta']; $record = $plugin['db_record']; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($meta['name'] ?? $dir) ?></strong></td>
                            <td><?= htmlspecialchars($meta['version'] ?? '-') ?></td>
                            <td class="plugins-description"><?= htmlspecialchars($meta['description'] ?? '') ?></td>
                            <td>
                                <?php if (!$record): ?>
                                    <span class="plugins-status-muted">not installed</span>
                                <?php elseif ($record['is_active']): ?>
                                    <span class="plugins-status-active">active</span>
                                <?php else: ?>
                                    <span class="plugins-status-inactive">inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="plugins-actions">
                                <?php if (!$record): ?>
                                    <form method="POST" action="/admin/plugins/install/<?= urlencode($dir) ?>" class="plugins-btn-form">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-primary plugins-btn">install</button>
                                    </form>
                                <?php elseif ($record['is_active']): ?>
                                    <form method="POST" action="/admin/plugins/deactivate/<?= urlencode($dir) ?>" class="plugins-btn-form">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn plugins-btn">deactivate</button>
                                    </form>
                                    <a href="/admin/plugins/settings/<?= urlencode($dir) ?>" class="btn plugins-btn">settings</a>
                                    <!-- Active plugins cannot be uninstalled. They must be deactivated first. -->
                                    <button disabled class="btn plugins-btn-disabled" title="Deactivate plugin first to uninstall">uninstall</button>
                                <?php else: ?>
                                    <form method="POST" action="/admin/plugins/activate/<?= urlencode($dir) ?>" class="plugins-btn-form">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-primary plugins-btn">activate</button>
                                    </form>
                                    <button disabled class="btn plugins-btn-disabled" title="Activate plugin first to access settings">settings</button>
                                    <form method="POST" action="/admin/plugins/uninstall/<?= urlencode($dir) ?>" class="plugins-btn-form" data-confirm-prompt="WARNING: Are you sure you want to completely delete the <?= htmlspecialchars($meta['name'] ?? $dir) ?> plugin suite and all of its files from the server? This action cannot be undone.&#10;&#10;Type 'uninstall' to confirm:" data-confirm-expected="uninstall">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn plugins-btn plugins-btn-danger">uninstall</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
