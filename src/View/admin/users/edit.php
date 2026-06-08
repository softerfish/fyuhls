<?php
include __DIR__ . '/../header.php';
include __DIR__ . '/../partials/shell_helpers.php';
?>

<?php renderAdminPageHeader('Edit User: ' . (string)$user['username']); ?>

<?php if (!empty($error)): ?>
    <div class="user-edit-feedback alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="user-edit-feedback alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php
ob_start();
?>
    <div class="d-flex justify-content-between align-items-center">
        <span>Account Details</span>
        <a href="/admin/users" class="btn btn-sm btn-secondary">Back to Users</a>
    </div>
<?php
$userEditHeader = ob_get_clean();
renderAdminCardStart(null, ['headerHtml' => $userEditHeader]);
?>
        <form method="POST">
            <?= \App\Core\Csrf::field() ?>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small">Username</label>
                    <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Role</label>
                    <select class="form-select" name="role">
                        <?php foreach (($roleOptions ?? []) as $roleKey => $roleLabel): ?>
                            <?php if (!$canManageStaffPermissions && !( !empty($user['is_super_admin']) && !empty($canEditProtectedSuperAdmin)) && $roleKey !== 'user') continue; ?>
                            <option value="<?= htmlspecialchars($roleKey) ?>" <?= $user['role'] === $roleKey ? 'selected' : '' ?>><?= htmlspecialchars($roleLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ((int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                        <small class="text-muted">
                            <?= !empty($demoMode)
                                ? 'This account is currently marked as the demo admin.'
                                : 'This account is designated as the demo admin and will become the read-only demo account when demo mode is enabled.' ?>
                        </small>
                    <?php endif; ?>
                    <?php if (!empty($user['is_super_admin'])): ?>
                        <small class="text-warning d-block mt-2">This is the protected super admin account created during setup. Only staff with explicit super-admin edit access can change it from User Management.</small>
                    <?php endif; ?>
                    <?php if (!$canManageStaffPermissions && !( !empty($user['is_super_admin']) && !empty($canEditProtectedSuperAdmin))): ?>
                        <small class="text-muted d-block mt-2">Only staff with permission management access can assign or remove staff roles.</small>
                    <?php else: ?>
                        <small class="text-muted d-block mt-2">Changing the role resets this account to that role's default permissions. Save first, then fine-tune the permissions below if needed.</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="banned" <?= $user['status'] === 'banned' ? 'selected' : '' ?>>Banned</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold small">Package</label>
                    <?php if (!empty($canManagePackages) && empty($currentPackageIsPaid)): ?>
                        <select class="form-select" name="package_id">
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?= $pkg['id'] ?>" <?= $user['package_id'] == $pkg['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pkg['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php
                        $currentPackageName = 'Current package';
                        foreach ($packages as $pkg) {
                            if ((int)$user['package_id'] === (int)$pkg['id']) {
                                $currentPackageName = (string)$pkg['name'];
                                break;
                            }
                        }
                        ?>
                        <input type="hidden" name="package_id" value="<?= (int)$user['package_id'] ?>">
                        <input type="text" class="form-control" value="<?= htmlspecialchars($currentPackageName) ?>" disabled>
                        <small class="text-muted d-block mt-2">
                            <?= !empty($currentPackageIsPaid)
                                ? 'This account is currently on a paid package. Package changes must go through Subscriptions so billing history stays intact.'
                                : 'Package changes require subscription-management access.' ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>

            <hr class="my-4">

            <div class="mb-3">
                <label class="form-label fw-bold small">Reset Password (Optional)</label>
                <input type="password" class="form-control" name="new_password" placeholder="Enter new password to reset..." autocomplete="new-password">
                <small class="text-muted">Leave blank to keep the current password.</small>
            </div>

            <hr class="my-4">

            <?php if (($canManageStaffPermissions || (!empty($user['is_super_admin']) && !empty($canEditProtectedSuperAdmin))) && in_array((string)$user['role'], ['admin', 'moderator'], true)): ?>
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Staff permissions</h5>
                            <div class="text-muted small">Turn tools on or off for this <?= htmlspecialchars($user['role']) ?> account. Admins start with everything. Moderators start with just moderation access.</div>
                            <?php if (!empty($user['is_super_admin'])): ?>
                                <div class="small text-warning mt-2">Protected super admin access is a separate default-off permission. Other admins will not be able to edit this account unless you explicitly grant it.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row g-4">
                        <?php foreach (($staffCapabilities ?? []) as $groupLabel => $capabilities): ?>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100 bg-white shadow-sm">
                                    <div class="fw-semibold mb-3"><?= htmlspecialchars($groupLabel) ?></div>
                                    <?php foreach ($capabilities as $capabilityKey => $capability): ?>
                                        <?php $isProtectedSuperAdminEdit = $capabilityKey === 'staff.edit_super_admin'; ?>
                                        <?php if ($isProtectedSuperAdminEdit): ?>
                                            <div class="mb-2 user-edit-danger-capability border border-danger-subtle rounded-3 p-3 bg-danger-subtle">
                                                <div class="d-flex align-items-start gap-3">
                                                    <div class="form-check m-0 pt-1">
                                                        <input
                                                            class="form-check-input"
                                                            type="checkbox"
                                                            name="staff_capabilities[<?= htmlspecialchars($capabilityKey) ?>]"
                                                            id="cap-<?= htmlspecialchars(str_replace('.', '-', $capabilityKey)) ?>"
                                                            <?= !empty($currentCapabilityMap[$capabilityKey]) ? 'checked' : '' ?>
                                                        >
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <label class="form-check-label fw-semibold d-block mb-2" for="cap-<?= htmlspecialchars(str_replace('.', '-', $capabilityKey)) ?>">
                                                            <?= htmlspecialchars((string)($capability['label'] ?? $capabilityKey)) ?>
                                                        </label>
                                                        <div class="small text-danger fw-semibold">Danger zone: this allows the staff account to edit the protected super admin from User Management.</div>
                                                        <div class="small text-muted mt-1">Only grant this if you explicitly trust the admin to change the platform owner's account, role, status, package, password, or 2FA state.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="form-check mb-2">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="staff_capabilities[<?= htmlspecialchars($capabilityKey) ?>]"
                                                    id="cap-<?= htmlspecialchars(str_replace('.', '-', $capabilityKey)) ?>"
                                                    <?= !empty($currentCapabilityMap[$capabilityKey]) ? 'checked' : '' ?>
                                                >
                                                <label class="form-check-label" for="cap-<?= htmlspecialchars(str_replace('.', '-', $capabilityKey)) ?>">
                                                    <?= htmlspecialchars((string)($capability['label'] ?? $capabilityKey)) ?>
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr class="my-4">
            <?php endif; ?>

            <!-- Wallet & Earnings Section -->
            <?php if (\App\Service\FeatureService::rewardsEnabled() && (!empty($canManageUserFinancials) || !empty($canIssueManualCredit))): ?>
                <div class="row g-4 bg-light p-4 rounded-3 border mx-0">
                    <?php if (!empty($canManageUserFinancials)): ?>
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-3"><i class="bi bi-wallet2 me-2"></i>Wallet & Earnings</h5>
                            <?php
                            $uId = (int)$user['id'];
                            $stmtBalance = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'cleared'");
                            $stmtBalance->execute([$uId]);
                            $clearedBalance = (float)($stmtBalance->fetchColumn() ?: 0);

                            $stmtReserved = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved', 'paid')");
                            $stmtReserved->execute([$uId]);
                            $reserved = (float)($stmtReserved->fetchColumn() ?: 0);

                            $stmtPaid = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status = 'paid'");
                            $stmtPaid->execute([$uId]);
                            $paid = (float)($stmtPaid->fetchColumn() ?: 0);

                            $availableBalance = max(0, $clearedBalance - $reserved);
                            $openWithdrawalAmount = max(0, $reserved - $paid);
                            ?>
                            <div class="p-3 bg-white border rounded shadow-sm">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Available Balance:</span>
                                    <span class="fw-bold text-success">$<?= number_format($availableBalance, 2) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Open Withdrawal Holds:</span>
                                    <span class="fw-bold text-warning">$<?= number_format($openWithdrawalAmount, 2) ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Lifetime Paid:</span>
                                    <span class="fw-bold text-primary">$<?= number_format($paid, 2) ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($canIssueManualCredit)): ?>
                        <div class="col-md-6<?= !empty($canManageUserFinancials) ? ' border-start-md' : '' ?>">
                            <h5 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Give Manual Credit</h5>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <input type="number" name="credit_amount" step="0.01" class="form-control" placeholder="0.00">
                                </div>
                                <div class="col-md-8">
                                    <input type="text" name="credit_reason" class="form-control" placeholder="Reason note (required when crediting)">
                                </div>
                            </div>
                            <small class="text-muted">Amount will be added to Available Balance immediately. Include a clear reason note so the audit trail explains why it was granted.</small>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mt-5 text-end">
                <button type="submit" class="btn btn-primary btn-lg px-5">Save All Changes</button>
            </div>
        </form>

        <?php if (!empty($canManageStaffPermissions) && ((string)$user['role'] === 'admin' || (int)($demoAdminUserId ?? 0) === (int)$user['id'])): ?>
            <div class="mt-5 p-4 border rounded-3 bg-white shadow-sm">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h5 class="fw-bold mb-1">Demo Admin Designation</h5>
                        <?php if ((int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                            <p class="small text-muted mb-0">
                                <?= !empty($demoMode)
                                    ? 'This account is the current demo admin. Sensitive data stays redacted here while demo mode is enabled.'
                                    : 'This account is already designated as the demo admin. Turn demo mode on later and this account will become the read-only demo admin without needing to sign into it first.' ?>
                            </p>
                        <?php else: ?>
                            <p class="small text-muted mb-0">You can predesignate this active admin account now so demo mode uses it later. That lets a super admin prepare the demo account without signing into the target user first.</p>
                        <?php endif; ?>
                    </div>
                    <?php if ((int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                        <form method="POST" action="/admin/users/action" data-confirm-message="Clear this demo admin designation?">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                            <button type="submit" name="action" value="clear_demo_admin" class="btn btn-outline-secondary">clear demo admin</button>
                        </form>
                    <?php elseif ((string)$user['role'] === 'admin' && (string)$user['status'] === 'active'): ?>
                        <form method="POST" action="/admin/users/action" data-confirm-message="Designate this admin as the demo admin?">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                            <button type="submit" name="action" value="set_demo_admin" class="btn btn-outline-primary"><?= !empty($demoMode) ? 'set demo admin' : 'designate demo admin' ?></button>
                        </form>
                    <?php else: ?>
                        <div class="small text-muted">Only active admin accounts can be designated as the demo admin.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (\App\Service\FeatureService::twoFactorEnabled() && !empty($canDisableUser2FA)): ?>
            <?php
            $stmt2fa = $db->prepare("SELECT is_enabled FROM user_two_factor WHERE user_id = ?");
            $stmt2fa->execute([$user['id']]);
            $has2fa = (bool)$stmt2fa->fetchColumn();
            ?>
            <?php if ($has2fa): ?>
                <div class="mt-5 p-4 border border-danger rounded-3 bg-white shadow-sm">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="text-danger fw-bold mb-1"><i class="bi bi-shield-slash me-2"></i>Emergency 2FA Override</h5>
                            <p class="small text-muted mb-0">This user has Two-Factor Authentication enabled. If they are locked out, you can manually disable it here.</p>
                            <p class="extra-small text-danger fw-bold mt-2">
                                <i class="bi bi-exclamation-triangle me-1"></i> WARNING: Ensure you have verified this user's identity through official support channels before proceeding.
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <form method="POST" action="/admin/users/disable-2fa" data-confirm-message="CRITICAL SECURITY ACTION: Are you ABSOLUTELY sure you want to disable 2FA for this user?">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn btn-danger">Disable 2FA Now</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<style>
.user-edit-feedback{margin-bottom:1.5rem}
</style>

<?php include __DIR__ . '/../footer.php'; ?>
