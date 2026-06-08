<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';
renderAdminPageHeader('Search Results: "' . (string)$query . '"', '', '<a href="/admin" class="btn btn-sm btn-outline-secondary">Clear Search</a>');
?>

<?php if (empty($users) && empty($files)): ?>
    <?php renderAdminCardStart(null, ['cardClass' => 'card shadow-sm border-0 py-5 text-center']); ?>
            <i class="search-results-empty-icon bi bi-search text-muted"></i>
            <h5 class="mt-3">No Results Found</h5>
            <p class="text-muted small">We couldn't find any matches for that term.<br>
            Search supports exact IDs and short IDs, plus partial username, email, and filename matching.</p>
    <?php renderAdminCardEnd(); ?>
<?php endif; ?>

<?php if (!empty($users)): ?>
    <?php renderAdminCardStart('Matching Users', ['cardClass' => 'card shadow-sm border-0 mb-4', 'headerClass' => 'card-header bg-white py-3 border-0', 'bodyClass' => 'card-body p-0']); ?>
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
                        <?php
                        $matchedUserId = (int)($u['id'] ?? 0);
                        $matchedUserLink = \App\Service\AdminUserNavigationService::destinationForUserEdit($matchedUserId);
                        $matchedUserLabel = \App\Service\AdminUserNavigationService::isCurrentUser($matchedUserId) ? 'my settings' : 'edit user';
                        ?>
                        <tr>
                            <td class="ps-4 small text-muted">#<?= $u['id'] ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td class="pe-4 text-end">
                                <a href="<?= htmlspecialchars($matchedUserLink) ?>" class="btn btn-sm btn-primary px-3"><?= htmlspecialchars($matchedUserLabel) ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    <?php renderAdminCardEnd(); ?>
<?php endif; ?>

<?php if (!empty($files)): ?>
    <?php renderAdminCardStart('Matching Files', ['cardClass' => 'card shadow-sm border-0', 'headerClass' => 'card-header bg-white py-3 border-0', 'bodyClass' => 'card-body p-0']); ?>
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
    <?php renderAdminCardEnd(); ?>
<?php endif; ?>

<style>
.search-results-empty-icon{font-size:3rem}
.search-results-heading{font-size:.75rem}
</style>

<?php include 'footer.php'; ?>
