<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Download Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#download-access"><i class="bi bi-person-lock me-2"></i> Access Rules</a>
            <a class="nav-link text-start" href="#download-tracking"><i class="bi bi-activity me-2"></i> Delivery Tracking</a>
            <a class="nav-link text-start" href="#download-cdn"><i class="bi bi-cloud-arrow-down me-2"></i> CDN Redirects</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">Downloads</h5>
                <p class="config-section-intro__text">Control who can download, how delivery is tracked, and when object-storage files should stay on the normal Fyuhls path versus being redirected to an external CDN hostname.</p>
            </div>
            <ul class="config-summary-chips">
                <li class="config-summary-chip <?= ($requireAccountToDownload === '1') ? 'config-summary-chip--warning' : 'config-summary-chip--info' ?>">Access: <?= ($requireAccountToDownload === '1') ? 'Account required' : 'Guest allowed' ?></li>
                <li class="config-summary-chip <?= ($trackCurrentDownloads === '1') ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Tracking: <?= ($trackCurrentDownloads === '1') ? 'Enabled' : 'Disabled' ?></li>
                <li class="config-summary-chip <?= ($cdnDownloadRedirectsEnabled === '1') ? 'config-summary-chip--info' : 'config-summary-chip--warning' ?>">CDN: <?= ($cdnDownloadRedirectsEnabled === '1') ? 'Redirects on' : 'Redirects off' ?></li>
            </ul>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>High-traffic sites should consider disabling <strong>Track Active Download Connections</strong> to reduce database load if per-user limits are not required.</p>
                <p>CDN redirects are an <strong>optional advanced setup</strong>. Most Fyuhls sites should leave this off unless they have already created a public Cloudflare or custom-domain hostname that points at the same object-storage bucket path.</p>
            </div>
        </details>

        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="downloads">

    <div id="download-access"></div>
    <?php renderAdminCardStart('Access Rules', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="require_account_to_download" id="reqAcc" value="1" <?= ($requireAccountToDownload === '1') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="reqAcc">Require User Account to Download</label>
        </div>
        <small class="config-form-note">Force users to register/login before they can access download links.</small>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">Block Downloads by Country (ISO Codes)</label>
        <input type="text" class="form-control" name="blocked_download_countries" value="<?= htmlspecialchars($blockedDownloadCountries) ?>" placeholder="US,CA,GB">
        <small class="config-form-note">Comma-separated list of country codes to restrict. Example: <code>US,CN,RU</code></small>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div id="download-tracking"></div>
    <?php renderAdminCardStart('Delivery Tracking', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="track_current_downloads" id="trackDownloads" value="1" <?= ($trackCurrentDownloads === '1') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="trackDownloads">Track Active Download Connections</label>
        </div>
        <small class="config-form-note">Enables real-time monitoring of app-controlled downloads. Required if you want to enforce package-based concurrent connection limits per user. You can manage those limits under <a href="/admin/packages">Packages</a>. Saving a package with a concurrent download limit above 0 will automatically turn this on.</small>
    </div>

    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="remote_url_background" id="remoteBg" value="1" <?= ($remoteUrlBackground === '1') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="remoteBg">Process Remote URL Downloads in Background</label>
        </div>
        <small class="config-form-note">Moves remote URL imports onto the cron queue so users do not have to wait in the browser. This requires the cron heartbeat to be enabled and running every minute.</small>
    </div>

    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="streaming_support_enabled" id="streamingSupport" value="1" <?= ($streamingSupportEnabled === '1') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="streamingSupport">Enable Streaming Support</label>
        </div>
        <small class="config-form-note">Enables stream-session tracking for supported video delivery flows. This is required if you want to credit rewards based on video watch progress instead of file-download completion.</small>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">Nginx Completion Log Path</label>
        <input type="text" class="form-control" name="nginx_completion_log_path" value="<?= htmlspecialchars($nginxCompletionLogPath ?? '') ?>" placeholder="/home/yourusername/filehosting/storage/logs/nginx-download-completion.log">
        <small class="config-form-note mb-2">Use this when you select <strong>Nginx Handoff</strong> and want Fyuhls to process final download-completion events from a dedicated Nginx access log instead of the older callback approach.</small>
        <small class="config-form-note">Fyuhls reads this log file during cron runs. Keep cron running every minute, and make sure the path matches the same file configured in your Nginx <code>access_log</code> directive for protected downloads.</small>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">Nginx Completion Event Retention (Days)</label>
        <input type="number" class="form-control" name="nginx_completion_retention_days" value="<?= htmlspecialchars($nginxCompletionRetentionDays ?? '7') ?>" min="1" max="3650">
        <small class="config-form-note">Default is <strong>7</strong> days. Fyuhls keeps processed Nginx completion events for this long before purging them automatically during cron runs.</small>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">Nginx Completion Log Lines Per Cron Run</label>
        <input type="number" class="form-control" name="nginx_completion_max_lines_per_run" value="<?= htmlspecialchars($nginxCompletionMaxLinesPerRun ?? '5000') ?>" min="100" max="1000000" step="100">
        <small class="config-form-note">Default is <strong>5000</strong>. Increase this on busier Nginx servers if the completion log backlog grows faster than cron can ingest it.</small>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div id="download-cdn"></div>
    <?php renderAdminCardStart('CDN Redirects', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="cdn_download_redirects_enabled" id="cdnRedirects" value="1" <?= ($cdnDownloadRedirectsEnabled === '1') ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="cdnRedirects">Enable CDN Redirects for Public Object-Storage Files</label>
        </div>
        <small class="config-form-note">Optional. Use this only if you have already created a public Cloudflare or custom-domain hostname that fronts the same object-storage bucket/key path. Public eligible files can be redirected there, while private files, local-storage files, and stricter reward-proof flows stay on the normal Fyuhls-controlled path. Lighter rewarded downloads may still use redirect-style delivery when your reward settings allow it.</small>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">CDN Base URL</label>
        <input type="url" class="form-control" name="cdn_download_base_url" value="<?= htmlspecialchars($cdnDownloadBaseUrl ?? '') ?>" placeholder="https://cdn.example.com">
        <small class="config-form-note mb-2">Leave this blank unless you have already created a public hostname for object-storage delivery, usually through Cloudflare on your own domain. Enter the exact hostname or fixed path, without a trailing slash. Fyuhls will append the stored object key automatically.</small>
        <small class="config-form-note">
            <strong>What this usually is:</strong> a hostname like <code>https://files.yourdomain.com</code> that <em>you</em> created and pointed at your public bucket or CDN path. Cloudflare does not create this for you automatically.
        </small>
        <small class="config-form-note mt-2">
            <strong>Examples:</strong>
            <ul class="mb-0 ps-3">
                <li><code>https://cdn.example.com</code> becomes <code>https://cdn.example.com/uploads/2026/03/report.pdf</code></li>
                <li><code>https://files.example.com</code> becomes <code>https://files.example.com/uploads/2026/03/report.pdf</code></li>
                <li><code>https://files.example.com/file/my-bucket</code> becomes <code>https://files.example.com/file/my-bucket/uploads/2026/03/report.pdf</code></li>
                <li><code>https://pub-123abc456.r2.dev</code> becomes <code>https://pub-123abc456.r2.dev/uploads/2026/03/report.pdf</code></li>
            </ul>
        </small>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div class="config-sticky-save">
        <p class="config-sticky-save__text">Download changes affect the public file page, direct handoff modes, and reward-aware delivery flows.</p>
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-save me-2"></i> Save Download Settings
        </button>
    </div>
</form>
    </div>
</div>
