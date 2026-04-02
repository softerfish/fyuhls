<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>Stored Files</h1>
</div>


<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span>all files <?php if ($total > 0): ?><span style="font-weight: 400; color: var(--text-muted); font-size: 0.875rem;">(<?= number_format($total) ?> total)</span><?php endif; ?></span>
        <form method="GET" style="display: flex; gap: 0.5rem;">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="partial filename..." style="width: 240px;">
            <button type="submit" class="btn btn-primary">search</button>
        </form>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($files)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 2rem;">no files found.</p>
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
                            <td style="max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <a href="/file/<?= htmlspecialchars($file['short_id']) ?>" target="_blank" style="color: var(--primary-color); text-decoration: none;">
                                    <?= htmlspecialchars($file['filename'] ?? $file['original_name'] ?? '-') ?>
                                </a>
                            </td>
                            <td style="font-size: 0.875rem;"><?= htmlspecialchars($file['username'] ?? 'guest') ?></td>
                            <td style="font-size: 0.875rem; color: var(--text-muted);"><?= htmlspecialchars($file['server_name']) ?></td>
                            <td><?= number_format($file['downloads'] ?? 0) ?></td>
                            <td>
                                <?php $s = $file['status'] ?? 'active'; ?>
                                <span style="color: <?= $s === 'active' ? '#10b981' : ($s === 'deleted' ? '#ef4444' : '#f59e0b') ?>;"><?= htmlspecialchars($s) ?></span>
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--text-muted);"><?= date('M j, Y', strtotime($file['created_at'])) ?></td>
                            <td>
                                <form method="POST" action="/admin/files/delete" style="display: inline;" onsubmit="return confirm('permanently delete this file?')">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="file_id" value="<?= $file['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.6rem; font-size: 0.8rem; background: #fee2e2; color: #b91c1c;">delete</button>
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
