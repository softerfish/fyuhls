<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Core\Csrf;
use App\Model\ApiToken;
use App\Model\Package;
use App\Model\User;
use App\Service\EncryptedSearchService;
use App\Service\MailService;
use App\Service\MonetizationModelService;
use App\Service\DemoModeService;
use App\Service\PackageTargetLockService;
use App\Service\RememberMeService;
use App\Service\StaffActivityService;
use App\Service\StaffPermissionService;

class UserController
{
    private function isDemoModeEnabled(): bool
    {
        return \App\Model\Setting::get('demo_mode', '0') === '1';
    }

    private function countActiveAdmins(\PDO $db): int
    {
        return (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
    }

    private function assertNotRemovingLastActiveAdmin(\PDO $db, int $userId, string $message): void
    {
        $stmt = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE");
        $activeAdminIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if (in_array($userId, $activeAdminIds, true) && count($activeAdminIds) <= 1) {
            throw new \RuntimeException($message);
        }
    }

    private function getDemoAdminUserId(): int
    {
        return (int)\App\Model\Setting::get('demo_admin_user_id', '0');
    }

    private function isDemoAdmin(int $userId): bool
    {
        return $userId > 0 && $this->getDemoAdminUserId() === $userId;
    }

    private function setDemoAdminUserId(int $userId): void
    {
        \App\Model\Setting::set('demo_admin_user_id', (string)$userId, 'general');
    }

    private function clearDemoAdminIfMatches(int $userId): void
    {
        if ($this->isDemoAdmin($userId)) {
            $this->setDemoAdminUserId(0);
        }
    }

    private function checkAuth()
    {
        Auth::requireCapability('users.manage');
    }

    private function canManageStaffPermissions(): bool
    {
        return Auth::hasCapability('staff.manage_permissions');
    }

    private function canManageUserFinancials(): bool
    {
        return Auth::hasCapability('withdrawals.manage');
    }

    private function canManagePackages(): bool
    {
        return Auth::hasCapability('subscriptions.manage');
    }

    private function canIssueManualCredit(): bool
    {
        return Auth::hasCapability('earnings.credit_manual');
    }

    private function manualCreditCap(): float
    {
        return max(0, round((float)\App\Model\Setting::get('max_manual_credit_amount', '500.00', 'rewards'), 2));
    }

    private function manualCreditAvailabilityError(float $credit): ?string
    {
        if ($credit <= 0) {
            return null;
        }

        if (!$this->canIssueManualCredit()) {
            return "You do not have permission to issue manual account credit.";
        }

        if (!\App\Service\FeatureService::rewardsEnabled()) {
            return "Manual account credit is unavailable while rewards are disabled.";
        }

        $cap = $this->manualCreditCap();
        if ($cap <= 0) {
            return "Manual account credit is unavailable until a positive per-credit cap is configured.";
        }

        if (round($credit, 2) > $cap) {
            return 'Manual account credit cannot exceed $' . number_format($cap, 2, '.', '') . ' in a single adjustment.';
        }

        return null;
    }

    private function canDisableUser2FA(): bool
    {
        return Auth::hasCapability('users.2fa_reset');
    }

    private function canEditProtectedSuperAdmin(): bool
    {
        return Auth::isSuperAdmin() || Auth::hasCapability('staff.edit_super_admin');
    }

    private function ensureDemoAdminReadOnly(string $redirect = '/admin/users'): void
    {
        try {
            DemoModeService::assertCurrentViewerCanMutate();
        } catch (\RuntimeException $e) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: ' . $redirect);
            exit;
        }
    }

    private function assertCanManageStaffRole(string $role, ?array $targetUser = null): void
    {
        if (
            $targetUser !== null
            && $this->isProtectedSuperAdmin($targetUser)
            && $this->canEditProtectedSuperAdmin()
        ) {
            return;
        }

        if (StaffPermissionService::roleSupportsCapabilities($role) && !$this->canManageStaffPermissions()) {
            Auth::denyAccess('Staff account management requires additional permission.');
        }
    }

    private function isProtectedSuperAdmin(array $user): bool
    {
        return !empty($user['is_super_admin']);
    }

    private function assertCanEditProtectedSuperAdmin(array $user): void
    {
        if ($this->isProtectedSuperAdmin($user) && !$this->canEditProtectedSuperAdmin()) {
            Auth::denyAccess('This protected super admin account can only be edited by staff with explicit super admin access.');
        }
    }

    private function assertLockedTargetStillManageable(array $user): void
    {
        if ($this->isProtectedSuperAdmin($user) && !$this->canEditProtectedSuperAdmin()) {
            throw new \RuntimeException('This protected super admin account can only be edited by staff with explicit super admin access.');
        }

        if (
            StaffPermissionService::roleSupportsCapabilities((string)($user['role'] ?? ''))
            && !$this->canManageStaffPermissions()
        ) {
            throw new \RuntimeException('Staff account management requires additional permission.');
        }
    }

    private function getAssignablePackages(\PDO $db, int $includePackageId = 0, bool $allowIncludedPaidPackage = false): array
    {
        $stmt = $db->query("SELECT id, name, level_type FROM packages ORDER BY id ASC");
        $packages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_filter($packages, static function (array $package) use ($includePackageId, $allowIncludedPaidPackage): bool {
            $packageId = (int)($package['id'] ?? 0);
            $levelType = strtolower((string)($package['level_type'] ?? ''));
            if ($packageId > 0 && $packageId === $includePackageId) {
                return $allowIncludedPaidPackage || !in_array($levelType, ['guest', 'admin', 'paid'], true);
            }

            return !in_array($levelType, ['guest', 'admin', 'paid'], true);
        }));
    }

    private function defaultAssignablePackageId(\PDO $db): int
    {
        $packages = $this->getAssignablePackages($db);
        foreach ($packages as $package) {
            if (strtolower((string)($package['level_type'] ?? 'free')) === 'free') {
                return (int)($package['id'] ?? 0);
            }
        }

        return (int)($packages[0]['id'] ?? 0);
    }

    private function isPaidPackage(?array $package): bool
    {
        return is_array($package) && strtolower((string)($package['level_type'] ?? 'free')) === 'paid';
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function lockSelectedPackageForEdit(\PDO $db, int $packageId): array
    {
        $lockKeys = PackageTargetLockService::lockPackageIds($db, [$packageId]);

        $packageStmt = $db->prepare("SELECT id, name, level_type FROM packages WHERE id = ? LIMIT 1 FOR UPDATE");
        $packageStmt->execute([$packageId]);
        $selectedPackage = $packageStmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($selectedPackage)) {
            PackageTargetLockService::releaseLocks($db, $lockKeys);
            throw new \RuntimeException('Selected package was not found.');
        }

        return [$selectedPackage, $lockKeys];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function lockAssignablePackageForCreate(\PDO $db, int $packageId): array
    {
        [$selectedPackage, $lockKeys] = $this->lockSelectedPackageForEdit($db, $packageId);

        $levelType = strtolower((string)($selectedPackage['level_type'] ?? ''));
        if (in_array($levelType, ['guest', 'admin'], true)) {
            PackageTargetLockService::releaseLocks($db, $lockKeys);
            throw new \RuntimeException('Guest and Admin packages are system plans and cannot be assigned from User Management.');
        }

        if ($this->isPaidPackage($selectedPackage)) {
            PackageTargetLockService::releaseLocks($db, $lockKeys);
            throw new \RuntimeException('Create the account on a non-paid package first, then use Subscriptions to grant paid access so billing history stays intact.');
        }

        return [$selectedPackage, $lockKeys];
    }

    public function index()
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();
        StaffPermissionService::ensureSchema();
        User::ensureRuntimeColumns($db);

        // Pagination Logic
        $page = (int)($_GET['page'] ?? 1);
        $page = $page < 1 ? 1 : $page;
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Search Logic
        $search = $_GET['q'] ?? '';
        if (!empty($search)) {
            $matchedUsers = EncryptedSearchService::searchUsers($search);
            $totalUsers = count($matchedUsers);
            $totalPages = (int) max(1, ceil($totalUsers / $limit));
            $users = array_slice($matchedUsers, $offset, $limit);
        } else {
            $countSql = "SELECT COUNT(*) FROM users";
            $totalUsers = (int)$db->query($countSql)->fetchColumn();
            $totalPages = (int) max(1, ceil($totalUsers / $limit));

            $sql = "SELECT * FROM users ORDER BY created_at DESC LIMIT $offset, $limit";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($users as &$user) {
                $user['username'] = \App\Service\EncryptionService::decrypt($user['username']);
                $user['email'] = \App\Service\EncryptionService::decrypt($user['email']);
            }
        }

        $packages = $this->getAssignablePackages($db);
        $error = $_SESSION['error'] ?? '';
        $success = $_SESSION['success'] ?? '';
        $createForm = $_SESSION['admin_create_user_form'] ?? [];
        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['admin_create_user_form']);

        View::render('admin/users/index.php', [
            'users' => $users,
            'search' => $search,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'totalUsers' => $totalUsers,
            'packages' => $packages,
            'error' => $error,
            'success' => $success,
            'createForm' => $createForm,
            'demoMode' => $this->isDemoModeEnabled(),
            'demoAdminUserId' => $this->getDemoAdminUserId(),
            'roleOptions' => StaffPermissionService::roleOptions(),
            'canManageStaffPermissions' => $this->canManageStaffPermissions(),
            'canManagePackages' => $this->canManagePackages(),
            'defaultAssignablePackageId' => $this->defaultAssignablePackageId($db),
            'canEditProtectedSuperAdmin' => $this->canEditProtectedSuperAdmin(),
        ]);
    }

    public function create()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: /admin/users");
            exit;
        }

        $this->ensureDemoAdminReadOnly('/admin/users');

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = "CSRF Token Mismatch";
            header("Location: /admin/users");
            exit;
        }

        $db = Database::getInstance()->getConnection();
        User::ensureRuntimeColumns($db);
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $packageId = $this->canManagePackages()
            ? (int)($_POST['package_id'] ?? 0)
            : $this->defaultAssignablePackageId($db);
        $rawSubmittedCapabilities = (array)($_POST['staff_capabilities'] ?? []);

        $_SESSION['admin_create_user_form'] = [
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'package_id' => $packageId,
        ];

        $reservedUsernamesRaw = \App\Model\Setting::get('reserved_usernames', 'administrator,admin,support');
        $reservedUsernames = array_map('trim', explode(',', strtolower($reservedUsernamesRaw)));
        $role = array_key_exists($role, StaffPermissionService::roleOptions()) ? $role : 'user';
        $this->assertCanManageStaffRole($role);
        $status = in_array($status, ['active', 'banned'], true) ? $status : 'active';

        if (strlen($username) < 3) {
            $_SESSION['error'] = "Username is too short.";
            header("Location: /admin/users");
            exit;
        }

        if (in_array($username, $reservedUsernames, true)) {
            $_SESSION['error'] = "This username is reserved and cannot be used.";
            header("Location: /admin/users");
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid email address.";
            header("Location: /admin/users");
            exit;
        }

        if (strlen($password) < 10) {
            $_SESSION['error'] = "Password must be at least 10 characters.";
            header("Location: /admin/users");
            exit;
        }

        if ($packageId <= 0) {
            $_SESSION['error'] = "No assignable package is currently available for new accounts.";
            header("Location: /admin/users");
            exit;
        }

        $packageStmt = $db->prepare("SELECT id, name, level_type FROM packages WHERE id = ? LIMIT 1");
        $packageStmt->execute([$packageId]);
        $package = $packageStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$package) {
            $_SESSION['error'] = "Selected package was not found.";
            header("Location: /admin/users");
            exit;
        }

        if (in_array(strtolower((string)($package['level_type'] ?? '')), ['guest', 'admin'], true)) {
            $_SESSION['error'] = "Guest and Admin packages are system plans and cannot be assigned from User Management.";
            header("Location: /admin/users");
            exit;
        }

        if ($this->isPaidPackage($package)) {
            $_SESSION['error'] = "Create the account on a non-paid package first, then use Subscriptions to grant paid access so billing history stays intact.";
            header("Location: /admin/users");
            exit;
        }

        $existingCredential = User::findByCredentials($username);
        $existingEmail = User::findByEmailOrPendingEmail($email);
        if ($existingCredential || $existingEmail) {
            $_SESSION['error'] = "Username or email is already in use.";
            header("Location: /admin/users");
            exit;
        }

        $credentialLockKeys = [];
        $packageLockKeys = [];
        try {
            $db->beginTransaction();
            $credentialLockKeys = User::lockCredentialValues($db, [$username, $email]);
            User::assertCredentialsAvailable($db, $username, $email);
            [$package, $packageLockKeys] = $this->lockAssignablePackageForCreate($db, $packageId);

            $hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
            $userId = User::create([
                'username' => $username,
                'email' => $email,
                'password' => $hash,
                'role' => $role,
                'package_id' => $packageId,
            ]);

            $newPackageIsPaid = false;

            if ($status === 'active') {
                $db->prepare("UPDATE users SET status = 'active', email_verified = 1, verification_token = NULL, premium_started_at = ? WHERE id = ?")
                   ->execute([$newPackageIsPaid ? date('Y-m-d H:i:s') : null, $userId]);
            } else {
                $db->prepare("UPDATE users SET status = ?, premium_started_at = NULL WHERE id = ?")->execute([$status, $userId]);
            }

            if (StaffPermissionService::roleSupportsCapabilities($role)) {
                $capabilityMapToSave = StaffPermissionService::constrainGrantedCapabilitiesForActor(
                    $role,
                    $rawSubmittedCapabilities,
                    Auth::id(),
                    Auth::role(),
                    Auth::isSuperAdmin(),
                    true
                );
                StaffPermissionService::syncOverridesForUser($userId, $role, $capabilityMapToSave, (int)(Auth::id() ?? 0));
            }

            StaffActivityService::log(
                'user_created',
                'user',
                $userId,
                'Created ' . $role . ' account ' . $username . '.',
                [
                    'role' => $role,
                    'status' => $status,
                    'package_id' => $packageId,
                ],
                $userId
            );

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e instanceof \RuntimeException
                ? $e->getMessage()
                : "We couldn't finish creating that account. Nothing was applied.";
            header("Location: /admin/users");
            exit;
        } finally {
            User::releaseCredentialLocks($db, $credentialLockKeys);
            PackageTargetLockService::releaseLocks($db, $packageLockKeys);
        }

        unset($_SESSION['admin_create_user_form']);
        \App\Service\SystemStatsService::refreshCounter('total_users');
        \App\Service\BonusOfferService::touchUserFailSoft((int)$userId, true, [
            'workflow' => 'admin_user_create',
            'user_id' => (int)$userId,
        ]);
        $_SESSION['success'] = "User created successfully: {$username}";
        header("Location: /admin/users");
        exit;
    }

    public function action()
    {
        $this->checkAuth();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly('/admin/users');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }

            $db = Database::getInstance()->getConnection();
            User::ensureRuntimeColumns($db);
            $userId = (int)$_POST['user_id'];
            $action = $_POST['action'];

            $stmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                $_SESSION['error'] = "User not found.";
                header("Location: /admin/users"); exit;
            }

            $this->assertCanEditProtectedSuperAdmin($targetUser);

            if (in_array($action, ['make_admin', 'remove_admin', 'make_moderator', 'remove_moderator', 'set_demo_admin', 'clear_demo_admin'], true)) {
                $this->assertCanManageStaffRole('admin');
            }

            if (in_array($action, ['ban', 'unban', 'delete'], true)) {
                $this->assertCanManageStaffRole((string)($targetUser['role'] ?? 'user'), $targetUser);
            }

            // Prevent self-action (redundant but safe)
            if ($userId === Auth::id() && in_array($action, ['delete', 'make_admin', 'remove_admin', 'ban', 'make_moderator', 'remove_moderator'], true)) {
                $_SESSION['error'] = "You cannot perform this action on your own account from User Management.";
                header("Location: /admin/users"); exit;
            }

            if ($action === 'ban') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    if (($lockedUser['role'] ?? '') === 'admin') {
                        $this->assertNotRemovingLastActiveAdmin($db, $userId, "Action denied: You cannot delete, ban, or demote the last active administrator.");
                    }
                    $this->clearDemoAdminIfMatches($userId);
                    $stmt = $db->prepare("UPDATE users SET status = 'banned' WHERE id = ?");
                    $stmt->execute([$userId]);
                    ApiToken::revokeAllForUserWithConnection($db, $userId);
                    RememberMeService::revokeAllForUserWithConnection($db, $userId);
                    StaffActivityService::logWithConnection($db, 'user_banned', 'user', $userId, 'Banned a user account.', [], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't ban that account safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
            }
            elseif ($action === 'unban') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    $stmt = $db->prepare("UPDATE users SET status = 'active', email_verified = 1, verification_token = NULL WHERE id = ?");
                    $stmt->execute([$userId]);
                    StaffActivityService::logWithConnection($db, 'user_unbanned', 'user', $userId, 'Restored a banned user account.', [], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't restore that account safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
            }
            elseif ($action === 'make_admin') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    $stmt = $db->prepare("UPDATE users SET role = 'admin', is_super_admin = 0 WHERE id = ?");
                    $stmt->execute([$userId]);
                    $capabilityMap = StaffPermissionService::constrainGrantedCapabilitiesForActor(
                        'admin',
                        [],
                        Auth::id(),
                        Auth::role(),
                        Auth::isSuperAdmin(),
                        true
                    );
                    StaffPermissionService::syncOverridesForUser($userId, 'admin', $capabilityMap, (int)(Auth::id() ?? 0));
                    StaffActivityService::logWithConnection($db, 'user_role_changed', 'user', $userId, 'Promoted user to admin.', ['role' => 'admin'], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't promote that account safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
            }
            elseif ($action === 'remove_admin') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    if (($lockedUser['role'] ?? '') === 'admin') {
                        $this->assertNotRemovingLastActiveAdmin($db, $userId, "Action denied: You cannot delete, ban, or demote the last active administrator.");
                    }
                    $this->clearDemoAdminIfMatches($userId);
                    $stmt = $db->prepare("UPDATE users SET role = 'user', is_super_admin = 0 WHERE id = ?");
                    $stmt->execute([$userId]);
                    StaffPermissionService::syncOverridesForUser($userId, 'user', [], (int)(Auth::id() ?? 0));
                    StaffActivityService::logWithConnection($db, 'user_role_changed', 'user', $userId, 'Removed admin access from user.', ['role' => 'user'], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't remove admin access safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
            }
            elseif ($action === 'make_moderator') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    if (($lockedUser['role'] ?? '') === 'admin') {
                        $this->assertNotRemovingLastActiveAdmin($db, $userId, "Action denied: You cannot delete, ban, or demote the last active administrator.");
                    }
                    $stmt = $db->prepare("UPDATE users SET role = 'moderator', is_super_admin = 0 WHERE id = ?");
                    $stmt->execute([$userId]);
                    $capabilityMap = StaffPermissionService::constrainGrantedCapabilitiesForActor(
                        'moderator',
                        [],
                        Auth::id(),
                        Auth::role(),
                        Auth::isSuperAdmin(),
                        true
                    );
                    StaffPermissionService::syncOverridesForUser($userId, 'moderator', $capabilityMap, (int)(Auth::id() ?? 0));
                    StaffActivityService::logWithConnection($db, 'user_role_changed', 'user', $userId, 'Assigned moderator role.', ['role' => 'moderator'], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't change that role safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
            }
            elseif ($action === 'remove_moderator') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    if (($lockedUser['role'] ?? '') !== 'moderator') {
                        throw new \RuntimeException('Only moderator accounts can use the remove moderator action.');
                    }
                    $stmt = $db->prepare("UPDATE users SET role = 'user', is_super_admin = 0 WHERE id = ?");
                    $stmt->execute([$userId]);
                    StaffPermissionService::syncOverridesForUser($userId, 'user', [], (int)(Auth::id() ?? 0));
                    StaffActivityService::logWithConnection($db, 'user_role_changed', 'user', $userId, 'Removed moderator role.', ['role' => 'user'], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't remove moderator access safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
            }
            elseif ($action === 'set_demo_admin') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    if (($lockedUser['role'] ?? '') !== 'admin' || ($lockedUser['status'] ?? '') !== 'active') {
                        throw new \RuntimeException('Only active administrator accounts can be marked as the demo admin.');
                    }

                    $this->setDemoAdminUserId($userId);
                    StaffActivityService::logWithConnection($db, 'demo_admin_assigned', 'user', $userId, 'Marked this admin account as the demo admin.', [], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't update the demo admin safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
                $_SESSION['success'] = "Demo admin account updated.";
                header("Location: /admin/users"); exit;
            }
            elseif ($action === 'clear_demo_admin') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$lockedUser) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    $this->clearDemoAdminIfMatches($userId);
                    StaffActivityService::logWithConnection($db, 'demo_admin_cleared', 'user', $userId, 'Cleared demo admin designation from this account.', [], $userId);
                    $db->commit();
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : "We couldn't clear the demo admin safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
                $_SESSION['success'] = "Demo admin account cleared.";
                header("Location: /admin/users"); exit;
            }
            elseif ($action === 'delete') {
                try {
                    $db->beginTransaction();
                    $lockStmt = $db->prepare("SELECT id, role, status, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    $lockStmt->execute([$userId]);
                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                    if ((int)($lockedUser['id'] ?? 0) !== $userId) {
                        throw new \RuntimeException('User not found.');
                    }
                    $this->assertLockedTargetStillManageable($lockedUser);
                    if (($lockedUser['role'] ?? '') === 'admin') {
                        $this->assertNotRemovingLastActiveAdmin($db, $userId, "Action denied: You cannot delete, ban, or demote the last active administrator.");
                    }

                    $historyBlockers = [
                        [
                            'sql' => "SELECT COUNT(*) FROM withdrawals WHERE user_id = ?",
                            'message' => "Cannot delete user while they still have withdrawal history. Ban the account instead so payout records stay intact.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM earnings WHERE user_id = ?",
                            'message' => "Cannot delete user while they still have earnings history. Ban the account instead so reward records stay intact.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM reward_receipts WHERE user_id = ?",
                            'message' => "Cannot delete user while they still have reward receipt history. Ban the account instead so fraud-review evidence stays intact.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM subscriptions WHERE user_id = ?",
                            'message' => "Cannot delete user while they still have subscription history. Ban the account instead so billing records stay intact.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM transactions WHERE user_id = ?",
                            'message' => "Cannot delete user while they still have payment transaction history. Ban the account instead so billing records stay intact.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM support_tickets WHERE user_id = ?",
                            'message' => "Cannot delete user while they still have support ticket history. Ban the account instead so support records stay intact.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM files WHERE user_id = ?",
                            'message' => "Cannot delete user while they still own files. Ban the account instead so file records and storage cleanup stay consistent.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM upload_sessions WHERE user_id = ? AND status IN ('pending', 'uploading', 'processing', 'completing')",
                            'message' => "Cannot delete user while they still have active multipart uploads. Abort or finish those uploads first so staged bytes and quota reservations are cleaned up safely.",
                        ],
                        [
                            'sql' => "SELECT COUNT(*) FROM quota_reservations WHERE user_id = ? AND status = 'active'",
                            'message' => "Cannot delete user while they still have active upload quota reservations. Resolve the in-flight upload work first so quota state stays consistent.",
                        ],
                    ];

                    foreach ($historyBlockers as $blocker) {
                        $stmt = $db->prepare($blocker['sql']);
                        $stmt->execute([$userId]);
                        if ((int)$stmt->fetchColumn() > 0) {
                            throw new \RuntimeException($blocker['message']);
                        }
                    }

                    $stmt = $db->prepare("SELECT COUNT(*) FROM bonus_offer_awards WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new \RuntimeException("Cannot delete user while they still have bonus-offer history. Ban the account instead so promotion review and audit records stay intact.");
                    }

                    $this->clearDemoAdminIfMatches($userId);
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    if ($stmt->execute([$userId])) {
                        StaffActivityService::logWithConnection($db, 'user_deleted', 'user', $userId, 'Deleted a user account.', [], $userId);
                    }
                    $db->commit();
                    \App\Service\SystemStatsService::refreshCounter('total_users');
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $_SESSION['error'] = $e instanceof \RuntimeException
                        ? $e->getMessage()
                        : "We couldn't delete that user safely. Nothing was applied.";
                    header("Location: /admin/users"); exit;
                }
            }

            header("Location: /admin/users");
            exit;
        }
    }

    public function edit(string $id)
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();
        User::ensureRuntimeColumns($db);
        $userId = (int)$id;
        StaffPermissionService::ensureSchema();

        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['error'] = 'That user account no longer exists.';
            header('Location: /admin/users');
            exit;
        }

        $this->assertCanEditProtectedSuperAdmin($user);
        $this->assertCanManageStaffRole((string)($user['role'] ?? 'user'), $user);

        // don't let an admin edit themselves through this page - use /settings instead
        if ($userId === Auth::id()) {
            header("Location: /settings");
            exit;
        }

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly('/admin/users/edit/' . rawurlencode((string)$userId));
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "CSRF Token Mismatch";
            } else {
                $originalRole = (string)($user['role'] ?? 'user');
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $role = $_POST['role'] ?? 'user';
                $status = $_POST['status'] ?? 'active';
                $packageId = (int)($_POST['package_id'] ?? 1);
                $newPassword = (string)($_POST['new_password'] ?? '');
                $rawSubmittedCapabilities = (array)($_POST['staff_capabilities'] ?? []);
                $oldPackageId = (int)($user['package_id'] ?? 0);
                $credit = round((float)($_POST['credit_amount'] ?? 0), 2);
                $creditReason = trim((string)($_POST['credit_reason'] ?? ''));

                $reservedUsernamesRaw = \App\Model\Setting::get('reserved_usernames', 'administrator,admin,support');
                $reservedUsernames = array_map('trim', explode(',', strtolower($reservedUsernamesRaw)));

                if (strlen($username) < 3) {
                    $error = "Username is too short.";
                } elseif (in_array(strtolower($username), $reservedUsernames) && \App\Service\EncryptionService::decrypt($user['username']) !== $username) {
                    $error = "This username is reserved and cannot be used.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email address.";
                } else {
                    $existingCredential = User::findByCredentials($username, $userId);
                    $existingEmail = User::findByEmailOrPendingEmail($email, $userId);
                    if ($existingCredential || $existingEmail) {
                        $error = "Username or email already taken by another user.";
                    } else {
                        $this->assertCanManageStaffRole($role, $user);

                        if (!$this->canManagePackages()) {
                            $packageId = $oldPackageId;
                        }

                        $packageStmt = $db->prepare("SELECT id, name, level_type FROM packages WHERE id = ? LIMIT 1");
                        $packageStmt->execute([$packageId]);
                        $selectedPackage = $packageStmt->fetch(\PDO::FETCH_ASSOC);
                        if (!$selectedPackage) {
                            $error = "Selected package was not found.";
                        } elseif (
                            $packageId !== $oldPackageId
                            && in_array(strtolower((string)($selectedPackage['level_type'] ?? '')), ['guest', 'admin'], true)
                        ) {
                            $error = "Guest and Admin packages are system plans and cannot be assigned from User Management.";
                        }

                        // Safety Check: Last Admin Protection
                        if (empty($error)) {
                            if (!empty($newPassword) && strlen($newPassword) < 10) {
                                $error = "New password must be at least 10 characters.";
                            }
                        }

                        if (empty($error)) {
                            $error = $this->manualCreditAvailabilityError($credit);
                        }

                        if (empty($error) && $credit > 0 && $creditReason === '') {
                            $error = "A reason note is required when issuing manual credit.";
                        }

                        if (empty($error) && $credit > 0) {
                            try {
                                \App\Service\ReviewIntegrityService::assertNotSelfManualCredit((int)(Auth::id() ?? 0), $userId);
                            } catch (\RuntimeException $e) {
                                $error = $e->getMessage();
                            }
                        }

                        if (empty($error)) {
                            $shouldRevokeApiTokens = ($status !== 'active') || !empty($newPassword);
                            $encUsername = \App\Service\EncryptionService::encrypt($username);
                            $encEmail = \App\Service\EncryptionService::encrypt($email);
                            $usernameLookup = User::credentialLookupHash($username);
                            $emailLookup = User::credentialLookupHash($email);
                            $packageChanged = $oldPackageId !== $packageId;
                            $nextIsSuperAdmin = ($role === 'admin') ? (int)($user['is_super_admin'] ?? 0) : 0;
                            $oldPackageStmt = $db->prepare("SELECT level_type FROM packages WHERE id = ? LIMIT 1");
                            $oldPackageStmt->execute([$oldPackageId]);
                            $oldPackageLevelType = (string)($oldPackageStmt->fetchColumn() ?: 'free');
                            $oldPackageIsPaid = strtolower($oldPackageLevelType) === 'paid';
                            $newPackageIsPaid = strtolower((string)($selectedPackage['level_type'] ?? 'free')) === 'paid';

                            if ($packageChanged && ($oldPackageIsPaid || $newPackageIsPaid)) {
                                $error = "Paid package changes must go through Subscriptions so premium access keeps a proper billing record.";
                            }

                            if (!empty($error)) {
                                $selectedPackage = null;
                            }

                            $nextPremiumStartedAt = null;
                            $normalizedMonetizationModel = MonetizationModelService::normalizeRequestedModel(
                                (string)($user['monetization_model'] ?? 'ppd'),
                                $selectedPackage ?: ['level_type' => $oldPackageLevelType],
                                (string)($user['monetization_model'] ?? 'ppd')
                            );
                            $balanceBeforeCredit = null;
                            $balanceAfterCredit = null;
                            if (empty($error) && $status === 'active' && $newPackageIsPaid) {
                                $nextPremiumStartedAt = ($oldPackageIsPaid && !empty($user['premium_started_at']))
                                    ? (string)$user['premium_started_at']
                                    : date('Y-m-d H:i:s');
                            }

                            if (empty($error)) {
                                $credentialLockKeys = [];
                                $packageLockKeys = [];
                                try {
                                    $db->beginTransaction();
                                    $credentialLockKeys = User::lockCredentialValues($db, [$username, $email]);
                                    User::assertCredentialsAvailable($db, $username, $email, $userId);
                                    $lockStmt = $db->prepare("SELECT role, status, package_id, premium_started_at FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                                    $lockStmt->execute([$userId]);
                                    $lockedUser = $lockStmt->fetch(\PDO::FETCH_ASSOC);
                                    if (!$lockedUser) {
                                        throw new \RuntimeException('User not found.');
                                    }
                                    $this->assertLockedTargetStillManageable($lockedUser);
                                    if (($lockedUser['role'] ?? '') === 'admin' && ($role !== 'admin' || $status !== 'active')) {
                                        $this->assertNotRemovingLastActiveAdmin($db, $userId, "Action denied: You cannot demote or deactivate the last active administrator.");
                                    }

                                    $currentLockedPackageId = (int)($lockedUser['package_id'] ?? 0);
                                    if (!$this->canManagePackages()) {
                                        $packageId = $currentLockedPackageId;
                                    }

                                    [$selectedPackage, $packageLockKeys] = $this->lockSelectedPackageForEdit($db, $packageId);
                                    $newPackageIsPaid = strtolower((string)($selectedPackage['level_type'] ?? 'free')) === 'paid';
                                    if ($packageId !== $oldPackageId && in_array(strtolower((string)($selectedPackage['level_type'] ?? '')), ['guest', 'admin'], true)) {
                                        throw new \RuntimeException('Guest and Admin packages are system plans and cannot be assigned from User Management.');
                                    }
                                    $currentPackageStmt = $db->prepare("SELECT level_type FROM packages WHERE id = ? LIMIT 1");
                                    $currentPackageStmt->execute([$currentLockedPackageId]);
                                    $currentPackageLevelType = (string)($currentPackageStmt->fetchColumn() ?: 'free');
                                    $currentPackageIsPaid = strtolower($currentPackageLevelType) === 'paid';
                                    if ($packageId !== $currentLockedPackageId && ($currentPackageIsPaid || $newPackageIsPaid)) {
                                        throw new \RuntimeException('Paid package changes must go through Subscriptions so premium access keeps a proper billing record.');
                                    }
                                    $normalizedMonetizationModel = MonetizationModelService::normalizeRequestedModel(
                                        (string)($user['monetization_model'] ?? 'ppd'),
                                        $selectedPackage,
                                        (string)($user['monetization_model'] ?? 'ppd')
                                    );
                                    $nextPremiumStartedAt = null;
                                    if ($status === 'active' && $newPackageIsPaid) {
                                        $nextPremiumStartedAt = ($currentPackageIsPaid && !empty($lockedUser['premium_started_at']))
                                            ? (string)$lockedUser['premium_started_at']
                                            : date('Y-m-d H:i:s');
                                    }

                                if ($status === 'active') {
                                    $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, username_lookup = ?, email_lookup = ?, role = ?, is_super_admin = ?, status = ?, package_id = ?, monetization_model = ?, premium_started_at = ?, email_verified = 1, verification_token = NULL WHERE id = ?");
                                    $stmt->execute([$encUsername, $encEmail, $usernameLookup ?: null, $emailLookup ?: null, $role, $nextIsSuperAdmin, $status, $packageId, $normalizedMonetizationModel, $nextPremiumStartedAt, $userId]);
                                } else {
                                    $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, username_lookup = ?, email_lookup = ?, role = ?, is_super_admin = ?, status = ?, package_id = ?, monetization_model = ?, premium_started_at = NULL WHERE id = ?");
                                    $stmt->execute([$encUsername, $encEmail, $usernameLookup ?: null, $emailLookup ?: null, $role, $nextIsSuperAdmin, $status, $packageId, $normalizedMonetizationModel, $userId]);
                                }

                                if ($role !== 'admin' || $status !== 'active') {
                                    $this->clearDemoAdminIfMatches($userId);
                                }

                                $capabilityMapToSave = StaffPermissionService::constrainGrantedCapabilitiesForActor(
                                    $role,
                                    $rawSubmittedCapabilities,
                                    Auth::id(),
                                    Auth::role(),
                                    Auth::isSuperAdmin(),
                                    $role !== $originalRole
                                );

                                StaffPermissionService::syncOverridesForUser($userId, $role, $capabilityMapToSave, (int)(Auth::id() ?? 0));

                                if (!empty($newPassword)) {
                                    $hash = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => 12]);
                                    $stmt = $db->prepare("UPDATE users SET password = ?, session_version = session_version + 1 WHERE id = ?");
                                    $stmt->execute([$hash, $userId]);
                                    $db->prepare("DELETE FROM user_two_factor_devices WHERE user_id = ?")->execute([$userId]);
                                    ApiToken::revokeAllForUserWithConnection($db, $userId);
                                    RememberMeService::revokeAllForUserWithConnection($db, $userId);
                                }

                                if ($role !== 'user' || $status !== 'active') {
                                    ApiToken::revokeAllForUserWithConnection($db, $userId);
                                    RememberMeService::revokeAllForUserWithConnection($db, $userId);
                                }

                                if ($credit > 0) {
                                    $balanceStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM earnings WHERE user_id = ? AND status = 'cleared'");
                                    $balanceStmt->execute([$userId]);
                                    $balanceBeforeCredit = (float)$balanceStmt->fetchColumn();
                                    $reason = $creditReason;
                                    $db->prepare("INSERT INTO earnings (user_id, amount, type, status, description) VALUES (?, ?, 'bonus', 'cleared', ?)")
                                       ->execute([$userId, $credit, $reason]);
                                    $balanceAfterCredit = $balanceBeforeCredit + $credit;
                                }

                                if ($credit > 0) {
                                    StaffActivityService::logWithConnection(
                                        $db,
                                        'manual_credit',
                                        'user',
                                        $userId,
                                        'Issued manual credit to ' . $username . '.',
                                        [
                                            'amount' => number_format($credit, 2, '.', ''),
                                            'reason' => $creditReason,
                                            'before' => [
                                                'cleared_balance' => number_format((float)$balanceBeforeCredit, 2, '.', ''),
                                            ],
                                            'after' => [
                                                'cleared_balance' => number_format((float)$balanceAfterCredit, 2, '.', ''),
                                            ],
                                        ],
                                        $userId
                                    );
                                }

                                StaffActivityService::logWithConnection(
                                    $db,
                                    'user_updated',
                                    'user',
                                    $userId,
                                    'Updated account settings for ' . $username . '.',
                                    [
                                        'role' => $role,
                                        'status' => $status,
                                        'package_id' => $packageId,
                                    ],
                                    $userId
                                );

                                    $db->commit();
                                } catch (\Throwable $e) {
                                    if ($db->inTransaction()) {
                                        $db->rollBack();
                                    }
                                    $error = $e instanceof \RuntimeException
                                        ? $e->getMessage()
                                        : "We couldn't save every change for this account. Nothing was applied.";
                                } finally {
                                    User::releaseCredentialLocks($db, $credentialLockKeys);
                                    PackageTargetLockService::releaseLocks($db, $packageLockKeys);
                                }
                            }

                            if (empty($error)) {
                                if (!empty($newPassword) && (int)$userId === (int)(Auth::id() ?? 0)) {
                                    $_SESSION['session_version'] = (int)($_SESSION['session_version'] ?? 1) + 1;
                                }
                                if ($packageChanged) {
                                    try {
                                        $oldPackage = Package::find($oldPackageId);
                                        $newPackage = Package::find($packageId);
                                        MailService::sendTemplate($email, 'package_changed', [
                                            '{username}' => $username,
                                            '{old_package}' => (string)($oldPackage['name'] ?? ('Package #' . $oldPackageId)),
                                            '{new_package}' => (string)($newPackage['name'] ?? ('Package #' . $packageId)),
                                        ], 'high');
                                    } catch (\Throwable $e) {
                                    }
                                }

                                \App\Service\BonusOfferService::touchUserFailSoft((int)$userId, true, [
                                    'workflow' => 'admin_user_update',
                                    'user_id' => (int)$userId,
                                ]);
                                $success = "User updated successfully.";
                                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                                $stmt->execute([$userId]);
                                $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                            }
                        }
                    }
                }
            }
        }

        $user['username'] = \App\Service\EncryptionService::decrypt($user['username']);
        $user['email'] = \App\Service\EncryptionService::decrypt($user['email']);

        // Fetch all packages for the dropdown
        $packages = $this->getAssignablePackages($db, (int)($user['package_id'] ?? 0), true);
        $currentPackageIsPaid = $this->isPaidPackage(Package::find((int)($user['package_id'] ?? 0)));
        $staffCapabilities = StaffPermissionService::groupedDefinitions();
        $currentCapabilityMap = StaffPermissionService::getCapabilityMapForUser($userId, (string)($user['role'] ?? 'user'));

        View::render('admin/users/edit.php', [
            'user' => $user,
            'packages' => $packages,
            'error' => $error,
            'success' => $success,
            'demoMode' => $this->isDemoModeEnabled(),
            'demoAdminUserId' => $this->getDemoAdminUserId(),
            'roleOptions' => StaffPermissionService::roleOptions(),
            'staffCapabilities' => $staffCapabilities,
            'currentCapabilityMap' => $currentCapabilityMap,
            'canManageStaffPermissions' => $this->canManageStaffPermissions(),
            'canManagePackages' => $this->canManagePackages(),
            'canEditProtectedSuperAdmin' => $this->canEditProtectedSuperAdmin(),
            'canManageUserFinancials' => $this->canManageUserFinancials(),
            'canIssueManualCredit' => $this->canIssueManualCredit(),
            'canDisableUser2FA' => $this->canDisableUser2FA(),
            'currentPackageIsPaid' => $currentPackageIsPaid,
        ]);
    }
}
