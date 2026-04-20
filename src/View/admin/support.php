<?php include 'header.php'; ?>

<style>
    .support-card-header {
        font-weight: 600;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border-color);
    }
    .support-card-body { padding: 1.5rem; }
    .support-copy { margin-top: 0; color: var(--text-color); }
    .support-form { margin-bottom: 1rem; }
    .support-field { margin-bottom: 1rem; }
    .support-label {
        display: block;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }
    .support-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
    }
    .support-approval { margin-top: 1rem; }
    .support-approval-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-muted);
        font-size: 0.9rem;
    }
    .support-smtp-note {
        font-size: 0.875rem;
        color: var(--text-muted);
    }
    .support-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.3fr) minmax(280px, 0.7fr);
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .support-side-grid { display: grid; gap: 1.5rem; }
    .support-preview {
        background: var(--bg-color);
        padding: 1rem;
        border-radius: 8px;
        font-size: 0.8125rem;
        color: var(--text-color);
        border: 1px solid var(--border-color);
        white-space: pre-wrap;
        word-wrap: break-word;
        max-height: 520px;
        overflow-y: auto;
    }
    .support-list {
        margin: 0;
        padding-left: 1.2rem;
        color: var(--text-color);
    }
    .support-muted-top { margin-top: 0; }
    .support-muted-bottom { margin-bottom: 0; }
</style>

<div class="page-header">
    <h1>Support Center</h1>
</div>

<div class="card mb-4">
    <div class="card-header support-card-header">Report a Problem</div>
    <div class="card-body support-card-body">
        <?php if (!empty($demoAdmin)): ?>
            <div class="alert alert-warning mb-3">Support bundle generation is hidden for the demo admin account.</div>
        <?php endif; ?>
        <p class="support-copy">Use this page to generate a sanitized support bundle for bug reports. Sensitive values are redacted before export, and the download is a plain <code>.json</code> file, not a zip archive.</p>

        <form method="POST" action="/admin/support/download" class="support-form">
            <?= \App\Core\Csrf::field() ?>
            <div class="support-field">
                <label for="issue_description" class="support-label">What went wrong?</label>
                <textarea id="issue_description" name="issue_description" rows="4" class="form-control" placeholder="Example: remote upload fails after 30 seconds and status page shows SMTP and curl warnings." <?= !empty($demoAdmin) ? 'disabled' : '' ?>><?= htmlspecialchars($issueDescription ?? '') ?></textarea>
            </div>

            <div class="support-actions">
                <button type="submit" class="btn btn-primary" <?= !empty($demoAdmin) ? 'disabled' : '' ?>>Download Sanitized JSON</button>
                <?php if ($smtpConfigured && empty($demoAdmin)): ?>
                    <button type="submit" formaction="/admin/support/email" class="btn btn-dark" data-confirm-message="Send the sanitized support bundle to <?= htmlspecialchars($supportEmail) ?>?">Email to <?= htmlspecialchars($supportEmail) ?></button>
                <?php else: ?>
                    <button type="button" class="btn btn-dark" disabled>Email Unavailable</button>
                <?php endif; ?>
            </div>

            <div class="support-approval">
                <label class="support-approval-label">
                    <input type="checkbox" name="approve_data_share" value="1" <?= !empty($demoAdmin) ? 'disabled' : '' ?>>
                    I reviewed this sanitized bundle and approve sending it to <?= htmlspecialchars($supportEmail) ?>.
                </label>
            </div>
        </form>

        <div class="support-smtp-note">
            SMTP status: <?= $smtpConfigured ? 'configured for direct email' : 'not configured, use JSON download and send manually' ?>
        </div>
    </div>
</div>

<div class="support-grid">
    <div class="card">
        <div class="card-header support-card-header">Bundle Preview</div>
        <div class="card-body support-card-body">
            <pre class="support-preview"><?= htmlspecialchars($supportJsonPreview ?? '{}') ?></pre>
        </div>
    </div>

    <div class="support-side-grid">
        <div class="card">
            <div class="card-header support-card-header">What Gets Included</div>
            <div class="card-body support-card-body">
                <ul class="support-list">
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
            <div class="card-header support-card-header">What Gets Redacted</div>
            <div class="card-body support-card-body">
                <ul class="support-list">
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
    <div class="card-header support-card-header">Other Ways to Reach Support</div>
    <div class="card-body support-card-body">
        <p class="text-muted support-muted-top">The support bundle lives here because this is the best place for bug-report workflow. Quick links also exist in <a href="/admin/status">System Status</a> and on the <a href="/admin">Admin Dashboard</a>.</p>
        <p class="text-muted support-muted-bottom">If email is not configured, download the JSON file and send it manually to <strong><?= htmlspecialchars($supportEmail) ?></strong>.</p>
    </div>
</div>

<?php include 'footer.php'; ?>
