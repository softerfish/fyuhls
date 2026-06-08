<?php
include __DIR__ . '/../header.php';
include __DIR__ . '/../partials/shell_helpers.php';
$currentAdminId = (int)(\App\Core\Auth::id() ?? 0);
?>

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
    .users-role-moderator {
        color: #7c3aed;
        font-weight: 600;
    }
    .users-role-user { color: var(--text-muted); }
    .users-role-super-admin {
        color: #b45309;
        font-weight: 700;
    }
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

<?php renderAdminPageHeader('User Management'); ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger users-alert"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success users-alert"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center">
        <div class="fw-semibold">Create Account</div>
        <span class="users-card-header-note">Create standard users, moderators, or admins without leaving the Users page.</span>
    </div>
<?php $usersCreateHeader = ob_get_clean(); ?>
<?php renderAdminCardStart(null, ['cardClass' => 'card mb-4', 'headerHtml' => $usersCreateHeader]); ?>
        <div class="alert alert-info users-demo-note">
            <?php if (!empty($demoMode)): ?>
                <?php if (!empty($canManageStaffPermissions)): ?>
                    Demo mode is active. You can move the demo-admin designation between active admin accounts from User Management. That account keeps sensitive items hidden, while other admins can still reveal protected fields when needed.
                <?php else: ?>
                    Demo mode is active. The designated demo admin account keeps sensitive items hidden, and staff with permission-management access can move that designation between active admin accounts from User Management.
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($canManageStaffPermissions)): ?>
                    You can predesignate one active admin account as the demo admin before turning demo mode on. After you create the admin, use the Users list to mark it here so you do not need to sign into that account first.
                <?php else: ?>
                    Staff with permission-management access can predesignate one active admin account as the demo admin before turning demo mode on, so the target account does not need to sign in first.
                <?php endif; ?>
            <?php endif; ?>
        </div>
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
                    <input type="password" class="form-control" name="password" required minlength="10" autocomplete="new-password">
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Package</label>
                    <?php if (!empty($canManagePackages)): ?>
                        <select class="form-select" name="package_id">
                            <?php foreach ($packages as $package): ?>
                                <option value="<?= (int)$package['id'] ?>" <?= (int)($createForm['package_id'] ?? $defaultAssignablePackageId ?? 0) === (int)$package['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($package['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php
                        $defaultPackageName = 'Default package';
                        foreach ($packages as $package) {
                            if ((int)$package['id'] === (int)($defaultAssignablePackageId ?? 0)) {
                                $defaultPackageName = (string)$package['name'];
                                break;
                            }
                        }
                        ?>
                        <input type="hidden" name="package_id" value="<?= (int)($defaultAssignablePackageId ?? 0) ?>">
                        <input type="text" class="form-control" value="<?= htmlspecialchars($defaultPackageName) ?>" disabled>
                        <small class="text-muted d-block mt-2">Package assignment requires subscription-management access. New accounts you create will use the default assignable package.</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Role</label>
                    <select class="form-select" name="role">
                        <?php foreach (($roleOptions ?? []) as $roleKey => $roleLabel): ?>
                            <?php if (!$canManageStaffPermissions && $roleKey !== 'user') continue; ?>
                            <option value="<?= htmlspecialchars($roleKey) ?>" <?= ($createForm['role'] ?? 'user') === $roleKey ? 'selected' : '' ?>><?= htmlspecialchars($roleLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$canManageStaffPermissions): ?>
                        <small class="text-muted d-block mt-2">Your staff profile can create standard users. Staff role assignment stays with accounts that can manage permissions.</small>
                    <?php elseif (empty($canEditProtectedSuperAdmin)): ?>
                        <small class="text-muted d-block mt-2">New admin accounts do not get access to the protected super admin account unless you explicitly turn that permission on later.</small>
                    <?php endif; ?>
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
                <small class="text-muted">Use Edit after creation for password resets, credits, non-paid package changes, or 2FA overrides. Use Subscriptions for paid-package changes.</small>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
<?php renderAdminCardEnd(); ?>

<?php ob_start(); ?>
    <div class="users-list-header">
        <span>users</span>
        <form method="GET" class="users-search-form">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="partial username, email, or ID..." class="users-search-input">
            <button type="submit" class="btn btn-primary">search</button>
        </form>
    </div>
<?php $usersListHeader = ob_get_clean(); ?>
<?php renderAdminCardStart(null, ['headerHtml' => $usersListHeader, 'bodyClass' => 'card-body users-card-body-flat']); ?>
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
                                    <?php if (!empty($user['is_super_admin'])): ?>
                                        <span class="badge bg-warning text-dark ms-2">super admin</span>
                                    <?php endif; ?>
                                    <?php if ((int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                                        <span class="badge bg-warning text-dark ms-2"><?= !empty($demoMode) ? 'demo admin' : 'designated demo admin' ?></span>
                                    <?php endif; ?>
                                <?php elseif ($user['role'] === 'moderator'): ?>
                                    <span class="users-role-moderator">moderator</span>
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
                                    <?php if (!empty($user['is_super_admin']) && empty($canEditProtectedSuperAdmin) && (int)$user['id'] !== $currentAdminId): ?>
                                        <span class="btn btn-sm btn-secondary disabled" aria-disabled="true">protected</span>
                                    <?php else: ?>
                                        <?php $userActionLink = \App\Service\AdminUserNavigationService::destinationForUserEdit((int)$user['id'], $currentAdminId); ?>
                                        <a href="<?= htmlspecialchars($userActionLink) ?>" class="btn btn-sm btn-secondary"><?= (int)$user['id'] === $currentAdminId ? 'my settings' : 'edit' ?></a>
                                    <?php endif; ?>
                                    <form method="POST" action="/admin/users/action" class="users-actions-form" data-confirm-message="are you sure?">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <?php if ($user['status'] !== 'banned' && (int)$user['id'] !== $currentAdminId && (empty($user['is_super_admin']) || !empty($canEditProtectedSuperAdmin))): ?>
                                            <button type="submit" name="action" value="ban" class="btn btn-sm btn-warning-light">ban</button>
                                        <?php elseif ($user['status'] === 'banned' && (empty($user['is_super_admin']) || !empty($canEditProtectedSuperAdmin))): ?>
                                            <button type="submit" name="action" value="unban" class="btn btn-sm btn-outline-primary">unban</button>
                                        <?php endif; ?>
                                        <?php if ($canManageStaffPermissions && (empty($user['is_super_admin']) || !empty($canEditProtectedSuperAdmin))): ?>
                                            <?php if ($user['role'] !== 'admin'): ?>
                                                <button type="submit" name="action" value="make_admin" class="btn btn-sm btn-outline-primary">make admin</button>
                                            <?php elseif ((int)$user['id'] !== $currentAdminId): ?>
                                                <button type="submit" name="action" value="remove_admin" class="btn btn-sm btn-secondary">remove admin</button>
                                            <?php endif; ?>
                                            <?php if ($user['role'] !== 'moderator' && (int)$user['id'] !== $currentAdminId): ?>
                                                <button type="submit" name="action" value="make_moderator" class="btn btn-sm btn-outline-primary">make moderator</button>
                                            <?php elseif ((int)$user['id'] !== $currentAdminId): ?>
                                                <button type="submit" name="action" value="remove_moderator" class="btn btn-sm btn-secondary">remove moderator</button>
                                            <?php endif; ?>
                                            <?php if ((int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                                                <button type="submit" name="action" value="clear_demo_admin" class="btn btn-sm btn-outline-secondary">clear demo admin</button>
                                            <?php elseif ($user['role'] === 'admin' && $user['status'] === 'active'): ?>
                                                <button type="submit" name="action" value="set_demo_admin" class="btn btn-sm btn-outline-primary"><?= !empty($demoMode) ? 'set demo admin' : 'designate demo admin' ?></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (($canManageStaffPermissions || !in_array((string)$user['role'], ['admin', 'moderator'], true)) && (int)$user['id'] !== $currentAdminId && (empty($user['is_super_admin']) || !empty($canEditProtectedSuperAdmin))): ?>
                                            <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger-light">delete</button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                                <?php if (!empty($user['is_super_admin']) && empty($canEditProtectedSuperAdmin) && (int)$user['id'] !== $currentAdminId): ?>
                                    <div class="users-joined mt-2">Protected super admin account. Default admin access cannot edit this user.</div>
                                <?php endif; ?>
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
<?php renderAdminCardEnd(); ?>

<?php include __DIR__ . '/../footer.php'; ?>
