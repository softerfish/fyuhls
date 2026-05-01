<?php
$title = 'Link Checker';
$metaDescription = 'Check batches of local file and folder links and see whether they are available, not available, or invalid.';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/public_form_shell_styles.php';
$canUseCopyToAccount = !empty($allowCopyToAccount) && \App\Core\Auth::check();
$hasAvailableResults = !empty(array_filter($results ?? [], static fn(array $row): bool => ($row['status'] ?? '') === 'Available'));
$hasUnavailableResults = !empty(array_filter($results ?? [], static fn(array $row): bool => ($row['status'] ?? '') === 'Unavailable'));
$hasInvalidResults = !empty(array_filter($results ?? [], static fn(array $row): bool => ($row['status'] ?? '') === 'Invalid'));
?>

<style>
    .link-checker-card { max-width: none; }
    .link-checker-intro {
        text-align: center;
        color: var(--text-muted);
        margin-bottom: 2rem;
    }
    .link-checker-textarea {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.875rem;
        min-height: 200px;
    }
    .link-checker-help {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.5rem;
    }
    .link-checker-results {
        margin-top: 2rem;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        overflow: hidden;
        background: white;
    }
    .link-checker-table {
        width: 100%;
        border-collapse: collapse;
    }
    .link-checker-table th,
    .link-checker-table td {
        padding: 0.9rem 1rem;
        border-top: 1px solid var(--border-color);
        text-align: left;
        vertical-align: top;
        font-size: 0.875rem;
    }
    .link-checker-table thead th {
        border-top: none;
        background: #f8fafc;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--text-muted);
    }
    .link-checker-status {
        display: inline-flex;
        align-items: center;
        padding: 0.3rem 0.65rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .link-checker-status--active { background: #dcfce7; color: #166534; }
    .link-checker-status--private { background: #e0e7ff; color: #3730a3; }
    .link-checker-status--pending { background: #fef3c7; color: #92400e; }
    .link-checker-status--deleted { background: #fee2e2; color: #991b1b; }
    .link-checker-status--invalid { background: #f3f4f6; color: #4b5563; }
    .link-checker-url {
        color: var(--text-color);
        text-decoration: none;
        word-break: break-all;
    }
    .link-checker-url:hover { text-decoration: underline; }
    .link-checker-filename {
        font-weight: 600;
        color: var(--text-color);
    }
    .link-checker-details {
        display: block;
        margin-top: 0.2rem;
        color: var(--text-muted);
        font-size: 0.75rem;
        line-height: 1.55;
    }
    .link-checker-summary {
        margin-top: 1.25rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .link-checker-summary-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        font-size: 0.78rem;
        color: var(--text-muted);
    }
    .link-checker-actions {
        margin-top: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
    }
    .link-checker-actions .btn { width: auto; }
    .link-checker-utility-tools,
    .link-checker-copy-tools {
        margin-top: 1rem;
        padding: 1rem;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: #f8fafc;
    }
    .link-checker-utility-tools {
        background: white;
    }
    .link-checker-utility-tools-title,
    .link-checker-copy-tools-title {
        margin: 0 0 0.75rem;
        font-size: 0.95rem;
        font-weight: 700;
    }
    .link-checker-utility-tools-copy,
    .link-checker-copy-tools-copy {
        font-size: 0.78rem;
        color: var(--text-muted);
        margin: 0 0 0.85rem;
    }
    .link-checker-checkbox {
        width: 16px;
        height: 16px;
        cursor: pointer;
    }
    .link-checker-mute {
        color: var(--text-muted);
        font-size: 0.78rem;
    }
    .link-checker-row-kind {
        display: inline-block;
        margin-bottom: 0.3rem;
        padding: 0.15rem 0.45rem;
        border-radius: 999px;
        background: #f1f5f9;
        color: #475569;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
</style>

<div class="public-form-shell">
    <div class="public-form-card public-form-card--wide public-form-card--plain link-checker-card auth-container">
        <h2>Link Checker</h2>
        <p class="link-checker-intro">Paste one or more local file or folder links and fyuhls will tell you whether each one is available, not available, or invalid. You can check up to <strong><?= (int)$maxLinks ?></strong> links at a time.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="link_checker_action" value="check">
            <div class="form-group">
                <label for="links">File or Folder URLs</label>
                <textarea name="links" id="links" rows="10" class="link-checker-textarea" placeholder="https://yourdomain.com/file/abc12345&#10;https://yourdomain.com/folder/def67890" required><?= htmlspecialchars($submittedLinks ?? '') ?></textarea>
                <p class="link-checker-help">Paste one URL per line. The checker supports local <code>/file/{id}</code> links and signed-in folder links in the format <code>/folder/{id}</code>.</p>
            </div>
            <?php include __DIR__ . '/../partials/captcha.php'; ?>
            <button type="submit" class="btn">Check Links</button>
        </form>

        <?php if (!empty($results)): ?>
            <div class="link-checker-summary">
                <span class="link-checker-summary-chip"><?= (int)($summary['submitted'] ?? 0) ?> submitted</span>
                <span class="link-checker-summary-chip"><?= (int)($summary['unique'] ?? 0) ?> unique</span>
                <?php if (($summary['duplicates_removed'] ?? 0) > 0): ?>
                    <span class="link-checker-summary-chip"><?= (int)$summary['duplicates_removed'] ?> duplicates removed</span>
                <?php endif; ?>
                <?php if (($summary['invalid_submitted'] ?? 0) > 0): ?>
                    <span class="link-checker-summary-chip"><?= (int)$summary['invalid_submitted'] ?> malformed skipped</span>
                <?php endif; ?>
                <span class="link-checker-summary-chip"><?= (int)($summary['available'] ?? 0) ?> available</span>
                <span class="link-checker-summary-chip"><?= (int)($summary['unavailable'] ?? 0) ?> not available</span>
                <span class="link-checker-summary-chip"><?= (int)($summary['invalid'] ?? 0) ?> invalid</span>
            </div>

            <div class="link-checker-utility-tools">
                <p class="link-checker-utility-tools-title">Bulk Clipboard and Export Tools</p>
                <p class="link-checker-utility-tools-copy">Copy or export the exact subsets you need without manually cleaning the list first.</p>
                <div class="link-checker-actions">
                    <button type="button" class="btn btn-secondary" data-link-checker-copy-status="Available" <?= $hasAvailableResults ? '' : 'disabled' ?>>Copy Available Links</button>
                    <button type="button" class="btn btn-secondary" data-link-checker-copy-status="Unavailable" <?= $hasUnavailableResults ? '' : 'disabled' ?>>Copy Not Available Links</button>
                    <button type="button" class="btn btn-secondary" data-link-checker-copy-status="Invalid" <?= $hasInvalidResults ? '' : 'disabled' ?>>Copy Invalid Links</button>
                </div>
                <div class="link-checker-actions">
                    <button type="button" class="btn btn-white" data-link-checker-export-status="Available" data-link-checker-export-format="txt" <?= $hasAvailableResults ? '' : 'disabled' ?>>Export Available TXT</button>
                    <button type="button" class="btn btn-white" data-link-checker-export-status="Unavailable" data-link-checker-export-format="txt" <?= $hasUnavailableResults ? '' : 'disabled' ?>>Export Not Available TXT</button>
                    <button type="button" class="btn btn-white" data-link-checker-export-status="Invalid" data-link-checker-export-format="txt" <?= $hasInvalidResults ? '' : 'disabled' ?>>Export Invalid TXT</button>
                    <button type="button" class="btn btn-white" data-link-checker-export-status="Available" data-link-checker-export-format="csv" <?= $hasAvailableResults ? '' : 'disabled' ?>>Export Available CSV</button>
                    <button type="button" class="btn btn-white" data-link-checker-export-status="Unavailable" data-link-checker-export-format="csv" <?= $hasUnavailableResults ? '' : 'disabled' ?>>Export Not Available CSV</button>
                    <button type="button" class="btn btn-white" data-link-checker-export-status="Invalid" data-link-checker-export-format="csv" <?= $hasInvalidResults ? '' : 'disabled' ?>>Export Invalid CSV</button>
                </div>
                <div class="link-checker-actions">
                    <span class="link-checker-mute" id="linkCheckerClipboardStatus"></span>
                </div>
            </div>

            <form method="POST" class="link-checker-results">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="link_checker_action" value="copy">
                <input type="hidden" name="links" value="<?= htmlspecialchars($submittedLinks ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($canUseCopyToAccount): ?>
                    <div class="link-checker-copy-tools">
                        <p class="link-checker-copy-tools-title">Copy To Account</p>
                        <p class="link-checker-copy-tools-copy">Select individual available file links or copy every available file result in one click. Existing safe copy rules, duplicate checks, and storage limits still apply.</p>
                        <div class="link-checker-actions">
                            <button type="submit" class="btn btn-primary" name="copy_mode" value="selected">Copy Selected To Account</button>
                            <button type="submit" class="btn btn-secondary" name="copy_mode" value="all">Copy All Available To Account</button>
                            <span class="link-checker-mute">Only public available files can be copied into an account.</span>
                        </div>
                    </div>
                <?php elseif (!empty($allowCopyToAccount)): ?>
                    <div class="link-checker-copy-tools">
                        <p class="link-checker-copy-tools-title">Copy To Account</p>
                        <p class="link-checker-copy-tools-copy">Sign in to select available file links and save them into your account.</p>
                    </div>
                <?php endif; ?>
                <table class="link-checker-table">
                    <thead>
                        <tr>
                            <?php if ($canUseCopyToAccount): ?><th><input class="link-checker-checkbox" type="checkbox" id="linkCheckerSelectAll" title="Select all eligible active file links"></th><?php endif; ?>
                            <th>Status</th>
                            <th>Link</th>
                            <th>Item</th>
                            <th>Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr
                                data-link-checker-status="<?= htmlspecialchars((string)$row['status']) ?>"
                                data-link-checker-kind="<?= htmlspecialchars((string)($row['kind'] ?? 'file')) ?>"
                                data-link-checker-url="<?= htmlspecialchars((string)$row['url'], ENT_QUOTES, 'UTF-8') ?>"
                                data-link-checker-name="<?= htmlspecialchars((string)($row['filename'] ?: $row['label']), ENT_QUOTES, 'UTF-8') ?>"
                                data-link-checker-size="<?= htmlspecialchars((string)($row['size'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <?php if ($canUseCopyToAccount): ?>
                                    <td>
                                        <?php if (!empty($row['copy_eligible']) && !empty($row['short_id'])): ?>
                                            <input class="link-checker-checkbox link-checker-copy-item" type="checkbox" name="copy_short_ids[]" value="<?= htmlspecialchars((string)$row['short_id']) ?>">
                                        <?php else: ?>
                                            <span class="link-checker-mute">-</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <span class="link-checker-status link-checker-status--<?= htmlspecialchars($row['status_class']) ?>">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?= htmlspecialchars($row['url']) ?>" class="link-checker-url" target="_blank" rel="noopener noreferrer">
                                        <?= htmlspecialchars($row['url']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="link-checker-row-kind"><?= htmlspecialchars((string)($row['kind'] ?? 'file')) ?></span>
                                    <span class="link-checker-filename"><?= htmlspecialchars($row['filename'] ?: $row['label']) ?></span>
                                    <span class="link-checker-details"><?= htmlspecialchars($row['details']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($row['size'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    const selectAll = document.getElementById('linkCheckerSelectAll');
    const items = Array.from(document.querySelectorAll('.link-checker-copy-item'));
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            items.forEach((item) => {
                item.checked = selectAll.checked;
            });
        });
    }

    const statusEl = document.getElementById('linkCheckerClipboardStatus');
    const rows = Array.from(document.querySelectorAll('[data-link-checker-status]'));
    const getUrlsByStatus = (status) => rows
        .filter((row) => row.dataset.linkCheckerStatus === status)
        .map((row) => row.dataset.linkCheckerUrl || '')
        .filter((url) => url !== '');

    const fallbackCopy = (text) => {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.setAttribute('readonly', 'readonly');
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.select();
        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }
        document.body.removeChild(textArea);
        return copied;
    };

    const setStatus = (message) => {
        if (statusEl) {
            statusEl.textContent = message;
        }
    };

    document.querySelectorAll('[data-link-checker-copy-status]').forEach((button) => {
        button.addEventListener('click', async () => {
            const status = button.dataset.linkCheckerCopyStatus || '';
            const urls = getUrlsByStatus(status);
            if (!urls.length) {
                setStatus(`No ${status.toLowerCase()} links to copy.`);
                return;
            }
            const payload = urls.join('\n');
            let copied = false;
            if (navigator.clipboard && window.isSecureContext) {
                try {
                    await navigator.clipboard.writeText(payload);
                    copied = true;
                } catch (error) {
                    copied = false;
                }
            }
            if (!copied) {
                copied = fallbackCopy(payload);
            }
            setStatus(copied ? `Copied ${urls.length} ${status.toLowerCase()} link(s).` : 'Clipboard copy was blocked. Please try again or export the list instead.');
        });
    });

    const escapeCsv = (value) => `"${String(value).replace(/"/g, '""')}"`;
    document.querySelectorAll('[data-link-checker-export-status]').forEach((button) => {
        button.addEventListener('click', () => {
            const status = button.dataset.linkCheckerExportStatus || '';
            const format = button.dataset.linkCheckerExportFormat || 'txt';
            const filteredRows = rows.filter((row) => row.dataset.linkCheckerStatus === status);
            if (!filteredRows.length) {
                setStatus(`No ${status.toLowerCase()} links to export.`);
                return;
            }

            let text = '';
            let mime = 'text/plain;charset=utf-8';
            let extension = 'txt';
            if (format === 'csv') {
                mime = 'text/csv;charset=utf-8';
                extension = 'csv';
                const csvRows = [['status', 'type', 'url', 'name', 'size']];
                filteredRows.forEach((row) => {
                    csvRows.push([
                        row.dataset.linkCheckerStatus || '',
                        row.dataset.linkCheckerKind || '',
                        row.dataset.linkCheckerUrl || '',
                        row.dataset.linkCheckerName || '',
                        row.dataset.linkCheckerSize || ''
                    ]);
                });
                text = csvRows.map((line) => line.map(escapeCsv).join(',')).join('\n');
            } else {
                text = filteredRows.map((row) => row.dataset.linkCheckerUrl || '').filter((url) => url !== '').join('\n');
            }

            const blob = new Blob([text], { type: mime });
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `link-checker-${status.toLowerCase()}-${new Date().toISOString().slice(0, 10)}.${extension}`;
            document.body.appendChild(anchor);
            anchor.click();
            document.body.removeChild(anchor);
            URL.revokeObjectURL(url);
            setStatus(`Exported ${filteredRows.length} ${status.toLowerCase()} link(s).`);
        });
    });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
