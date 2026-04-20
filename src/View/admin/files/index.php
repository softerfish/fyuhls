<?php include __DIR__ . '/../header.php'; ?>

<style>
    .files-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .files-total {
        font-weight: 400;
        color: var(--text-muted);
        font-size: 0.875rem;
    }
    .files-search-form {
        display: flex;
        gap: 0.5rem;
    }
    .files-search-input { width: 240px; }
    .files-card-body-flat { padding: 0; }
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
    .files-delete-form { display: inline; }
    .files-delete-btn {
        padding: 0.25rem 0.6rem;
        font-size: 0.8rem;
        background: #fee2e2;
        color: #b91c1c;
    }
</style>

<div class="page-header">
    <h1>Stored Files</h1>
</div>


<div class="card">
    <div class="card-header files-header">
        <span>all files <?php if ($total > 0): ?><span class="files-total">(<?= number_format($total) ?> total)</span><?php endif; ?></span>
        <form method="GET" class="files-search-form">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="partial filename..." class="files-search-input">
            <button type="submit" class="btn btn-primary">search</button>
        </form>
    </div>
    <?php if (!empty($dedupeSummary)): ?>
        <div class="files-summary">
            <span class="files-summary-pill">Logical files: <?= number_format((int)($dedupeSummary['logical_files'] ?? 0)) ?></span>
            <span class="files-summary-pill">Unique stored files: <?= number_format((int)($dedupeSummary['unique_stored_files'] ?? 0)) ?></span>
            <span class="files-summary-pill">Duplicate file entries: <?= number_format((int)($dedupeSummary['duplicate_file_entries'] ?? 0)) ?></span>
        </div>
    <?php endif; ?>
    <div class="card-body files-card-body-flat">
        <?php if (empty($files)): ?>
            <p class="files-empty">no files found.</p>
        <?php else: ?>
            <table class="table">
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
                                <form method="POST" action="/admin/files/delete" class="files-delete-form" data-confirm-message="permanently delete this file?">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="file_id" value="<?= $file['id'] ?>">
                                    <button type="submit" class="btn files-delete-btn">delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

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
    </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
