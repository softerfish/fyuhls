<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>Edit User: <?= htmlspecialchars($user['username']) ?></h1>
</div>

<?php if (!empty($error)): ?>
    <div class="user-edit-feedback alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="user-edit-feedback alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Account Details</span>
        <a href="/admin/users" class="btn btn-sm btn-secondary">Back to Users</a>
    </div>
    <div class="card-body">
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
                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                    <?php if (!empty($demoMode) && (int)($demoAdminUserId ?? 0) === (int)$user['id']): ?>
                        <small class="text-muted">This account is currently marked as the demo admin.</small>
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
                    <select class="form-select" name="package_id">
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?= $pkg['id'] ?>" <?= $user['package_id'] == $pkg['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pkg['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr class="my-4">

            <div class="mb-3">
                <label class="form-label fw-bold small">Reset Password (Optional)</label>
                <input type="text" class="form-control" name="new_password" placeholder="Enter new password to reset...">
                <small class="text-muted">Leave blank to keep the current password.</small>
            </div>

            <hr class="my-4">

            <!-- Wallet & Earnings Section -->
            <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                <div class="row g-4 bg-light p-4 rounded-3 border mx-0">
                    <div class="col-md-6">
                        <h5 class="fw-bold mb-3"><i class="bi bi-wallet2 me-2"></i>Wallet & Earnings</h5>
                        <?php
                        $uId = (int)$user['id'];
                        $stmtBalance = $db->prepare("SELECT SUM(amount) FROM earnings WHERE user_id = ? AND status = 'cleared'");
                        $stmtBalance->execute([$uId]);
                        $balance = (float)($stmtBalance->fetchColumn() ?: 0);

                        $stmtPaid = $db->prepare("SELECT SUM(amount) FROM withdrawals WHERE user_id = ? AND status = 'paid'");
                        $stmtPaid->execute([$uId]);
                        $paid = (float)($stmtPaid->fetchColumn() ?: 0);
                        ?>
                        <div class="p-3 bg-white border rounded shadow-sm">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Available Balance:</span>
                                <span class="fw-bold text-success">$<?= number_format($balance - $paid, 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Lifetime Paid:</span>
                                <span class="fw-bold text-primary">$<?= number_format($paid, 2) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 border-start-md">
                        <h5 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Give Manual Credit</h5>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <input type="number" name="credit_amount" step="0.01" class="form-control" placeholder="0.00">
                            </div>
                            <div class="col-md-8">
                                <input type="text" name="credit_reason" class="form-control" placeholder="Reason (e.g. Bonus)">
                            </div>
                        </div>
                        <small class="text-muted">Amount will be added to Available Balance immediately.</small>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mt-5 text-end">
                <button type="submit" class="btn btn-primary btn-lg px-5">Save All Changes</button>
            </div>
        </form>

        <?php if (\App\Service\FeatureService::twoFactorEnabled()): ?>
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
    </div>
</div>

<style>
.user-edit-feedback{margin-bottom:1.5rem}
</style>

<?php include __DIR__ . '/../footer.php'; ?>
