<?php include dirname(__DIR__) . '/header.php'; ?>

<style>
    .package-edit-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
    }
    .package-toggle-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
    }
    .package-toggle-label--spaced { margin-top: 0.25rem; }
    .package-toggle-input { width: auto; }
    .package-field-spacer { margin-top: 1rem; }
    .package-submit-row {
        border-top: 1px solid var(--border-color);
        padding-top: 1.5rem;
        margin-top: 2rem;
    }
</style>

<div class="page-header">
    <h1>Edit Package: <?= htmlspecialchars($package['name']) ?></h1>
    <a href="/admin/packages" class="btn">&larr; Back to Packages</a>
</div>

<div class="card">
    <div class="card-header">Package Configuration</div>
    <div class="card-body">
        <form method="POST">
            <?= \App\Core\Csrf::field() ?>
            
            <div class="package-edit-grid">
                <div class="form-group">
                    <label>Package Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($package['name']) ?>" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label>Package Price (USD)</label>
                    <input type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars((string)($package['price'] ?? '0.00')) ?>">
                    <small>Used for the checkout page and live payment gateways. Set to 0 for non-paid plans.</small>
                </div>
                <div class="form-group">
                    <label>Max Storage (Bytes)</label>
                    <input type="number" min="0" name="max_storage_bytes" value="<?= $package['max_storage_bytes'] ?>">
                    <small>0 = Unlimited. 1GB = 1073741824 bytes.</small>
                </div>
                <div class="form-group">
                    <label>Max Upload Size (Bytes)</label>
                    <input type="number" min="0" name="max_upload_size" value="<?= $package['max_upload_size'] ?>">
                    <small>0 = Unlimited. 100MB = 104857600 bytes.</small>
                </div>
                <div class="form-group">
                    <label>Daily Bandwidth Limit (Bytes)</label>
                    <input type="number" min="0" name="max_daily_downloads" value="<?= $package['max_daily_downloads'] ?>" placeholder="104857600">
                    <small>0 = Unlimited. Total data a user can download in a 24-hour period (e.g. 104857600 = 100MB).</small>
                </div>
                <div class="form-group">
                    <label>Download Speed (Bytes/sec)</label>
                    <input type="number" min="0" name="download_speed" value="<?= $package['download_speed'] ?>">
                    <small>0 = Unlimited. 1MB/s = 1048576.</small>
                </div>
                <div class="form-group">
                    <label>Countdown Timer</label>
                    <label class="package-toggle-label package-toggle-label--spaced">
                        <input type="checkbox" name="wait_time_enabled" value="1" <?= ($package['wait_time_enabled'] ?? 0) ? 'checked' : '' ?> class="package-toggle-input">
                        Enable countdown before download
                    </label>
                </div>
                <div class="form-group">
                    <label>Countdown Duration (Seconds)</label>
                    <input type="number" min="0" name="wait_time" value="<?= $package['wait_time'] ?>">
                    <small>How long the user must wait before the download button activates. 0 = instant.</small>
                </div>
                <div class="form-group">
                    <label>Concurrent Uploads</label>
                    <input type="number" min="1" name="concurrent_uploads" value="<?= $package['concurrent_uploads'] ?>">
                </div>
                <div class="form-group">
                    <label>Concurrent Downloads</label>
                    <input type="number" name="concurrent_downloads" value="<?= htmlspecialchars((string)($package['concurrent_downloads'] ?? 1)) ?>" min="0">
                    <small>
                        Maximum simultaneous app-tracked downloads allowed for users on this package. Set to 0 for unlimited.
                        Saving a value above 0 will automatically enable
                        <a href="/admin/configuration?tab=downloads">Track Active Download Connections</a>.
                    </small>
                </div>
                <div class="form-group">
                    <label>File Expiration (Days since Last Download)</label>
                    <input type="number" min="0" name="file_expiry_days" value="<?= $package['file_expiry_days'] ?? 0 ?>">
                    <small>0 = Never expires. Files will be deleted after X days of inactivity (no downloads).</small>
                </div>
                <?php \App\Core\PluginManager::doAction('admin_package_edit_limits', $package); ?>
            </div>

            <div class="form-group package-field-spacer">
                <label class="package-toggle-label">
                    <input type="checkbox" name="show_ads" value="1" <?= $package['show_ads'] ? 'checked' : '' ?> class="package-toggle-input">
                    Show Advertising on Download Pages
                </label>
            </div>

            <div class="form-group package-field-spacer">
                <label class="package-toggle-label">
                    <input type="checkbox" name="allow_remote_upload" value="1" <?= ($package['allow_remote_upload'] ?? 0) ? 'checked' : '' ?> class="package-toggle-input">
                    Allow Remote URL Upload
                </label>
            </div>

            <div class="form-group package-field-spacer">
                <label class="package-toggle-label">
                    <input type="checkbox" name="allow_direct_links" value="1" <?= $package['allow_direct_links'] ? 'checked' : '' ?> class="package-toggle-input">
                    Allow Direct Hotlinking (Premium Feature)
                </label>
            </div>

            <?php \App\Core\PluginManager::doAction('admin_package_edit_options', $package); ?>

            <?php \App\Core\PluginManager::doAction('admin_package_edit_form_extra', $package); ?>

            <div class="package-submit-row">
                <button type="submit" class="btn btn-primary">Save Package Changes</button>
            </div>
        </form>
    </div>
</div>
<?php include dirname(__DIR__) . '/footer.php'; ?>
