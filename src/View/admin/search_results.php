<?php include 'header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h4 fw-bold">Search Results: "<?= htmlspecialchars($query) ?>"</h2>
    <a href="/admin" class="btn btn-sm btn-outline-secondary">Clear Search</a>
</div>

<?php if (empty($users) && empty($files)): ?>
    <div class="card shadow-sm border-0 py-5 text-center">
        <div class="card-body">
            <i class="search-results-empty-icon bi bi-search text-muted"></i>
            <h5 class="mt-3">No Results Found</h5>
            <p class="text-muted small">We couldn't find any matches for that term.<br>
            Search supports exact IDs and short IDs, plus partial username, email, and filename matching.</p>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($users)): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-0">
            <h6 class="search-results-heading mb-0 fw-bold text-uppercase">Matching Users</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light small">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th class="pe-4 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="ps-4 small text-muted">#<?= $u['id'] ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td class="pe-4 text-end">
                                <a href="/admin/users/edit/<?= $u['id'] ?>" class="btn btn-sm btn-primary px-3">Edit User</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($files)): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-0">
            <h6 class="search-results-heading mb-0 fw-bold text-uppercase">Matching Files</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light small">
                        <tr>
                            <th class="ps-4">Short ID</th>
                            <th>Filename</th>
                            <th>Admin Filter</th>
                            <th class="pe-4 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $f): ?>
                        <tr>
                            <td class="ps-4 small text-muted"><?= $f['short_id'] ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($f['filename']) ?></td>
                            <td>
                                <a href="/admin/files?q=<?= urlencode($f['filename']) ?>" class="btn btn-sm btn-outline-secondary px-3">Filter List</a>
                            </td>
                            <td class="pe-4 text-end">
                                <a href="/file/<?= urlencode($f['short_id']) ?>" class="btn btn-sm btn-primary px-3" target="_blank">Open File</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
.search-results-empty-icon{font-size:3rem}
.search-results-heading{font-size:.75rem}
</style>

<?php include 'footer.php'; ?>
