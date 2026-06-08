<?php
include __DIR__ . '/../header.php';
include __DIR__ . '/../partials/shell_helpers.php';
?>

<style>
    .files-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .files-total {
        font-weight: 400;
        color: var(--text-muted);
        font-size: 0.875rem;
    }
    .files-search-form {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .files-search-input { width: 240px; }
    .files-card-body-flat { padding: 0; }
    .files-table-wrap { overflow-x: auto; padding-top: 0.35rem; }
    .files-table { width: 100%; min-width: 980px; }
    .files-table th { white-space: nowrap; }
    .files-summary {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        padding: 1rem 1.25rem 0;
    }
    .files-summary-pill {
        padding: 0.45rem 0.75rem;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        font-size: 0.8125rem;
        color: var(--text-muted);
    }
    .files-empty {
        color: var(--text-muted);
        text-align: center;
        padding: 2rem;
    }
    .files-name-cell {
        max-width: 260px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .files-link {
        color: var(--primary-color);
        text-decoration: none;
    }
    .files-owner,
    .files-server {
        font-size: 0.875rem;
    }
    .files-server,
    .files-date {
        color: var(--text-muted);
    }
    .files-status-active { color: #10b981; }
    .files-status-deleted { color: #ef4444; }
    .files-status-other { color: #f59e0b; }
    .files-dedupe-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.18rem 0.45rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        margin-left: 0.45rem;
        vertical-align: middle;
    }
    .files-dedupe-badge--unique {
        background: #dcfce7;
        color: #166534;
    }
    .files-dedupe-badge--duplicate {
        background: #fef3c7;
        color: #92400e;
    }
    .files-date {
        font-size: 0.8125rem;
    }
    .files-actions {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        align-items: flex-start;
        min-width: 260px;
    }
    .files-investigate-links {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }
    .files-delete-form {
        display: grid;
        gap: 0.55rem;
        width: 100%;
        max-width: 320px;
    }
    .files-delete-reason {
        width: 100%;
        min-height: 62px;
        padding: 0.35rem 0.5rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 0.78rem;
        resize: vertical;
    }
    .files-delete-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.65rem;
    }
    .files-delete-btn {
        padding: 0.25rem 0.6rem;
        font-size: 0.8rem;
        background: #fee2e2;
        color: #b91c1c;
        white-space: nowrap;
    }
    .files-delete-toggle {
        display: flex;
        align-items: flex-start;
        gap: 0.35rem;
        font-size: 0.78rem;
        color: #475569;
        line-height: 1.35;
        flex: 1 1 auto;
    }
    .files-delete-toggle input {
        margin: 0.15rem 0 0;
        flex: 0 0 auto;
    }
    @media (max-width: 768px) {
        .files-search-input {
            width: 100%;
        }
        .files-delete-row {
            flex-direction: column;
            align-items: stretch;
        }
        .files-delete-btn {
            width: 100%;
        }
    }
</style>

<?php
$canViewInvestigations = \App\Core\Auth::hasCapability('investigations.view');
renderAdminPageHeader('Stored Files');
?>


<?php ob_start(); ?>
    <div class="files-header">
        <span>all files <?php if ($total > 0): ?><span class="files-total">(<?= number_format($total) ?> total)</span><?php endif; ?></span>
        <form method="GET" class="files-search-form">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="filename, short ID, or owner..." class="files-search-input">
            <button type="submit" class="btn btn-primary">search</button>
        </form>
    </div>
<?php $filesHeaderHtml = ob_get_clean(); ?>
<?php renderAdminCardStart(null, ['headerHtml' => $filesHeaderHtml, 'bodyClass' => 'card-body files-card-body-flat']); ?>
    <?php if (!empty($dedupeSummary)): ?>
        <div class="files-summary">
            <span class="files-summary-pill">Logical files: <?= number_format((int)($dedupeSummary['logical_files'] ?? 0)) ?></span>
            <span class="files-summary-pill">Unique stored files: <?= number_format((int)($dedupeSummary['unique_stored_files'] ?? 0)) ?></span>
            <span class="files-summary-pill">Duplicate file entries: <?= number_format((int)($dedupeSummary['duplicate_file_entries'] ?? 0)) ?></span>
        </div>
    <?php endif; ?>
        <?php if (empty($files)): ?>
            <p class="files-empty">no files found.</p>
        <?php else: ?>
            <div class="files-table-wrap">
                <table class="table files-table">
                    <thead>
                        <tr>
                            <th>filename</th>
                            <th>owner</th>
                            <th>server</th>
                            <th>downloads</th>
                            <th>status</th>
                            <th>uploaded</th>
                            <th>actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                            <tr>
                                <td class="files-name-cell">
                                    <a href="/file/<?= htmlspecialchars($file['short_id']) ?>" target="_blank" class="files-link">
                                        <?= htmlspecialchars($file['filename'] ?? $file['original_name'] ?? '-') ?>
                                    </a>
                                    <?php if (!empty($file['is_duplicate_entry'])): ?>
                                        <span class="files-dedupe-badge files-dedupe-badge--duplicate">Dup x<?= number_format((int)$file['ref_count']) ?></span>
                                    <?php else: ?>
                                        <span class="files-dedupe-badge files-dedupe-badge--unique">Unique</span>
                                    <?php endif; ?>
                                </td>
                                <td class="files-owner"><?= htmlspecialchars($file['username'] ?? 'guest') ?></td>
                                <td class="files-server"><?= htmlspecialchars($file['server_name']) ?></td>
                                <td><?= number_format($file['downloads'] ?? 0) ?></td>
                                <td>
                                    <?php $s = $file['status'] ?? 'active'; ?>
                                    <span class="<?= $s === 'active' ? 'files-status-active' : ($s === 'deleted' ? 'files-status-deleted' : 'files-status-other') ?>"><?= htmlspecialchars($s) ?></span>
                                </td>
                                <td class="files-date"><?= date('M j, Y', strtotime($file['created_at'])) ?></td>
                                <td>
                                    <div class="files-actions">
                                        <?php if ($canViewInvestigations): ?>
                                            <div class="files-investigate-links">
                                                <a href="/admin/investigations/file/<?= (int)$file['id'] ?>" class="btn btn-sm btn-outline-secondary">File Investigation</a>
                                                <?php if (!empty($file['user_id'])): ?>
                                                    <a href="/admin/investigations/uploader/<?= (int)$file['user_id'] ?>" class="btn btn-sm btn-outline-secondary">Uploader Investigation</a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <form method="POST" action="/admin/files/delete" class="files-delete-form" data-confirm-message="permanently delete this file?">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="file_id" value="<?= $file['id'] ?>">
                                            <textarea
                                                name="delete_reason"
                                                class="files-delete-reason"
                                                placeholder="Deletion reason"
                                                aria-label="Deletion reason for <?= htmlspecialchars($file['filename'] ?? $file['original_name'] ?? 'file') ?>"
                                                required
                                            ></textarea>
                                            <div class="files-delete-row">
                                                <label class="files-delete-toggle">
                                                    <input type="checkbox" name="delete_file_earnings" value="1">
                                                    <span>Remove rewards earned from this file too</span>
                                                </label>
                                                <button type="submit" class="btn files-delete-btn">delete</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white border-top py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="small text-muted">
                            Showing <?= count($files) ?> of <?= number_format($total) ?> files
                        </div>
                        <nav aria-label="File pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>">Previous</a>
                                </li>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);

                                if ($start > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?page=1&q=<?= urlencode($search) ?>">1</a></li>
                                    <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($end < $totalPages): ?>
                                    <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>&q=<?= urlencode($search) ?>"><?= $totalPages ?></a></li>
                                <?php endif; ?>

                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<?php include __DIR__ . '/../footer.php'; ?>
