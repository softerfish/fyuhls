<?php include __DIR__ . '/../header.php'; ?>
<?php
$buttonFormStyle = 'display:inline-flex; margin:0;';
$buttonStyle = 'padding: 0.35rem 0.75rem; font-size: 0.8125rem;';
?>

<div class="page-header">
    <h1>Plugin Manager</h1>
</div>


<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span>plugins</span>
        <form method="POST" action="/admin/plugins/upload" enctype="multipart/form-data" style="display: flex; gap: 0.5rem; align-items: center;">
            <?= \App\Core\Csrf::field() ?>
            <input type="file" name="plugin_zip" accept=".zip" required style="font-size: 0.875rem;">
            <button type="submit" class="btn btn-primary" style="white-space: nowrap;">upload plugin</button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($plugins)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 2rem 0;">
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
                            <td style="color: var(--text-muted); font-size: 0.875rem;"><?= htmlspecialchars($meta['description'] ?? '') ?></td>
                            <td>
                                <?php if (!$record): ?>
                                    <span style="color: var(--text-muted);">not installed</span>
                                <?php elseif ($record['is_active']): ?>
                                    <span style="color: #10b981; font-weight: 600;">active</span>
                                <?php else: ?>
                                    <span style="color: #f59e0b;">inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <?php if (!$record): ?>
                                    <form method="POST" action="/admin/plugins/install/<?= urlencode($dir) ?>" style="<?= $buttonFormStyle ?>">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-primary" style="<?= $buttonStyle ?>">install</button>
                                    </form>
                                <?php elseif ($record['is_active']): ?>
                                    <form method="POST" action="/admin/plugins/deactivate/<?= urlencode($dir) ?>" style="<?= $buttonFormStyle ?>">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn" style="<?= $buttonStyle ?>">deactivate</button>
                                    </form>
                                    <a href="/admin/plugins/settings/<?= urlencode($dir) ?>" class="btn" style="padding: 0.35rem 0.75rem; font-size: 0.8125rem;">settings</a>
                                    <!-- Active plugins cannot be uninstalled. They must be deactivated first. -->
                                    <button disabled class="btn" style="padding: 0.35rem 0.75rem; font-size: 0.8125rem; color: #9ca3af; border-color: #e5e7eb; cursor: not-allowed;" title="Deactivate plugin first to uninstall">uninstall</button>
                                <?php else: ?>
                                    <form method="POST" action="/admin/plugins/activate/<?= urlencode($dir) ?>" style="<?= $buttonFormStyle ?>">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn btn-primary" style="<?= $buttonStyle ?>">activate</button>
                                    </form>
                                    <button disabled class="btn" style="padding: 0.35rem 0.75rem; font-size: 0.8125rem; color: #9ca3af; border-color: #e5e7eb; cursor: not-allowed;" title="Activate plugin first to access settings">settings</button>
                                    <form method="POST" action="/admin/plugins/uninstall/<?= urlencode($dir) ?>" style="<?= $buttonFormStyle ?>" onsubmit="let conf = prompt('WARNING: Are you sure you want to completely delete the <?= htmlspecialchars($meta['name'] ?? $dir) ?> plugin suite and all of its files from the server? This action cannot be undone.\n\nType \'uninstall\' to confirm:'); return conf === 'uninstall';">
                                        <?= \App\Core\Csrf::field() ?>
                                        <button type="submit" class="btn" style="<?= $buttonStyle ?> color: #ef4444; border-color: #ef4444;">uninstall</button>
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
