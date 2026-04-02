<?php include 'header.php'; ?>

<div class="page-header">
    <h1>Support Center</h1>
</div>

<div class="card mb-4">
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Report a Problem</div>
    <div class="card-body" style="padding: 1.5rem;">
        <?php if (!empty($demoAdmin)): ?>
            <div class="alert alert-warning mb-3">Support bundle generation is hidden for the demo admin account.</div>
        <?php endif; ?>
        <p style="margin-top: 0; color: var(--text-color);">Use this page to generate a sanitized support bundle for bug reports. Sensitive values are redacted before export, and the download is a plain <code>.json</code> file, not a zip archive.</p>

        <form method="POST" action="/admin/support/download" style="margin-bottom: 1rem;">
            <?= \App\Core\Csrf::field() ?>
            <div style="margin-bottom: 1rem;">
                <label for="issue_description" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">What went wrong?</label>
                <textarea id="issue_description" name="issue_description" rows="4" class="form-control" placeholder="Example: remote upload fails after 30 seconds and status page shows SMTP and curl warnings." <?= !empty($demoAdmin) ? 'disabled' : '' ?>><?= htmlspecialchars($issueDescription ?? '') ?></textarea>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
                <button type="submit" class="btn btn-primary" <?= !empty($demoAdmin) ? 'disabled' : '' ?>>Download Sanitized JSON</button>
                <?php if ($smtpConfigured && empty($demoAdmin)): ?>
                    <button type="submit" formaction="/admin/support/email" class="btn btn-dark" onclick="return confirm('Send the sanitized support bundle to <?= htmlspecialchars($supportEmail) ?>?');">Email to <?= htmlspecialchars($supportEmail) ?></button>
                <?php else: ?>
                    <button type="button" class="btn btn-dark" disabled>Email Unavailable</button>
                <?php endif; ?>
            </div>

            <div style="margin-top: 1rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <input type="checkbox" name="approve_data_share" value="1" <?= !empty($demoAdmin) ? 'disabled' : '' ?>>
                    I reviewed this sanitized bundle and approve sending it to <?= htmlspecialchars($supportEmail) ?>.
                </label>
            </div>
        </form>

        <div style="font-size: 0.875rem; color: var(--text-muted);">
            SMTP status: <?= $smtpConfigured ? 'configured for direct email' : 'not configured, use JSON download and send manually' ?>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(280px, 0.7fr); gap: 1.5rem; margin-bottom: 1.5rem;">
    <div class="card">
        <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Bundle Preview</div>
        <div class="card-body" style="padding: 1.5rem;">
            <pre style="background: var(--bg-color); padding: 1rem; border-radius: 8px; font-size: 0.8125rem; color: var(--text-color); border: 1px solid var(--border-color); white-space: pre-wrap; word-wrap: break-word; max-height: 520px; overflow-y: auto;"><?= htmlspecialchars($supportJsonPreview ?? '{}') ?></pre>
        </div>
    </div>

    <div style="display: grid; gap: 1.5rem;">
        <div class="card">
            <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">What Gets Included</div>
            <div class="card-body" style="padding: 1.5rem;">
                <ul style="margin: 0; padding-left: 1.2rem; color: var(--text-color);">
                    <li>App version and support token</li>
                    <li>PHP, MySQL, web server, and host metrics</li>
                    <li>System checks like storage, GD, FFmpeg, and SMTP</li>
                    <li>Active plugin summary</li>
                    <li>Sanitized config snapshot</li>
                    <li>Recent sanitized logs</li>
                    <li>Downloaded as a readable JSON file for support tools and agents</li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">What Gets Redacted</div>
            <div class="card-body" style="padding: 1.5rem;">
                <ul style="margin: 0; padding-left: 1.2rem; color: var(--text-color);">
                    <li>Passwords, API keys, tokens, and encryption material</li>
                    <li>Email addresses and IP addresses</li>
                    <li>Absolute server paths</li>
                    <li>Encrypted values stored in logs</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="font-weight: 600; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-color);">Other Ways to Reach Support</div>
    <div class="card-body" style="padding: 1.5rem;">
        <p class="text-muted" style="margin-top: 0;">The support bundle lives here because this is the best place for bug-report workflow. Quick links also exist in <a href="/admin/status">System Status</a> and on the <a href="/admin">Admin Dashboard</a>.</p>
        <p class="text-muted" style="margin-bottom: 0;">If email is not configured, download the JSON file and send it manually to <strong><?= htmlspecialchars($supportEmail) ?></strong>.</p>
    </div>
</div>

<?php include 'footer.php'; ?>
