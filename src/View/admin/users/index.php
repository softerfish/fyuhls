<?php include __DIR__ . '/../header.php'; ?>

<style>
    .users-alert { margin-bottom: 1.5rem; }
    .users-card-header-note {
        color: var(--text-muted);
        font-size: 0.875rem;
    }
    .users-demo-note { margin-bottom: 1rem; }
    .users-list-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .users-search-form {
        display: flex;
        gap: 0.5rem;
    }
    .users-search-input { width: 240px; }
    .users-card-body-flat { padding: 0; }
    .users-empty {
        color: var(--text-muted);
        text-align: center;
        padding: 2rem;
    }
    .users-id,
    .users-joined {
        color: var(--text-muted);
        font-size: 0.8125rem;
    }
    .users-email { font-size: 0.875rem; }
    .users-role-admin {
        color: #2563eb;
        font-weight: 600;
    }
    .users-role-user { color: var(--text-muted); }
    .users-status-active { color: #10b981; }
    .users-status-banned { color: #ef4444; }
    .users-status-other { color: #f59e0b; }
    .users-actions {
        display: flex;
        gap: 0.25rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .users-actions-form {
        display: flex;
        gap: 0.25rem;
        flex-wrap: wrap;
    }
</style>

<div class="page-header">
    <h1>User Management</h1>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger users-alert"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success users-alert"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Create Account</span>
        <span class="users-card-header-note">Create a standard or admin account without leaving the Users page.</span>
    </div>
    <div class="card-body">
        <?php if (!empty($demoMode)): ?>
            <div class="alert alert-info users-demo-note">Demo mode is active. You can mark one active admin account as the demo admin. That account keeps sensitive items hidden, while other admins can still reveal protected fields when needed.</div>
        <?php endif; ?>
        <form method="POST" action="/admin/users/create">
            <?= \App\Core\Csrf::field() ?>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Username</label>
                    <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($createForm['username'] ?? '') ?>" required minlength="3" autocomplete="off">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($createForm['email'] ?? '') ?>" required autocomplete="off">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Temporary Password</label>
                    <input type="text" class="form-control" name="password" required minlength="6" autocomplete="new-password">
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Package</label>
                    <select class="form-select" name="package_id">
                        <?php foreach ($packages as $package): ?>
                            <option value="<?= (int)$package['id'] ?>" <?= (int)($createForm['package_id'] ?? 1) === (int)$package['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($package['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Role</label>
                    <select class="form-select" name="role">
                        <option value="user" <?= ($createForm['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= ($createForm['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?= ($createForm['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="banned" <?= ($createForm['status'] ?? '') === 'banned' ? 'selected' : '' ?>>Banned</option>
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                <small class="text-muted">Use Edit after creation for password resets, credits, package changes, or 2FA overrides.</small>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header users-list-header">
        <span>users</span>
        <form method="GET" class="users-search-form">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="partial username, email, or ID..." class="users-search-input">
            <button type="submit" class="btn btn-primary">search</button>
        </form>
    </div>
    <div class="card-body users-card-body-flat">
        <?php if (empty($users)): ?>
            <p class="users-empty">no users found.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>id</th>
                        <th>username</th>
                        <th>email</th>
                        <th>role</th>
                        <th>status</th>
                        <th>joined</th>
                        <th>actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td class="users-id">#<?= $user['id'] ?></td>
                            <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                            <td class="users-email"><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="users-role-admin">admin</span>
                                    <?php if (!empty($demoMode) && (int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                                        <span class="badge bg-warning text-dark ms-2">demo admin</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="users-role-user">user</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['status'] === 'active'): ?>
                                    <span class="users-status-active">active</span>
                                <?php elseif ($user['status'] === 'banned'): ?>
                                    <span class="users-status-banned">banned</span>
                                <?php else: ?>
                                    <span class="users-status-other"><?= htmlspecialchars($user['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="users-joined"><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                            <td>
                                <div class="users-actions">
                                    <a href="/admin/users/edit/<?= $user['id'] ?>" class="btn btn-sm btn-secondary">edit</a>
                                    <form method="POST" action="/admin/users/action" class="users-actions-form" data-confirm-message="are you sure?">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <?php if ($user['status'] !== 'banned'): ?>
                                            <button type="submit" name="action" value="ban" class="btn btn-sm btn-warning-light">ban</button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="unban" class="btn btn-sm btn-outline-primary">unban</button>
                                        <?php endif; ?>
                                        <?php if ($user['role'] !== 'admin'): ?>
                                            <button type="submit" name="action" value="make_admin" class="btn btn-sm btn-outline-primary">make admin</button>
                                        <?php elseif ($user['role'] === 'admin'): ?>
                                            <button type="submit" name="action" value="remove_admin" class="btn btn-sm btn-secondary">remove admin</button>
                                        <?php endif; ?>
                                        <?php if (!empty($demoMode) && $user['role'] === 'admin' && $user['status'] === 'active'): ?>
                                            <?php if ((int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                                                <button type="submit" name="action" value="clear_demo_admin" class="btn btn-sm btn-outline-secondary">clear demo admin</button>
                                            <?php else: ?>
                                                <button type="submit" name="action" value="set_demo_admin" class="btn btn-sm btn-outline-primary">set demo admin</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger-light">delete</button>
                                    </form>
                                </div>
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
                            Showing <?= count($users) ?> of <?= number_format($totalUsers) ?> users
                        </div>
                        <nav aria-label="User pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $currentPage - 1 ?>&q=<?= urlencode($search) ?>">Previous</a>
                                </li>
                                <?php 
                                $start = max(1, $currentPage - 2);
                                $end = min($totalPages, $currentPage + 2);
                                for ($i = $start; $i <= $end; $i++): 
                                ?>
                                    <li class="page-item <?= $i == $currentPage ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $currentPage + 1 ?>&q=<?= urlencode($search) ?>">Next</a>
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
