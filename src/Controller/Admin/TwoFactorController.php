<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Model\User;
use App\Service\AdminUserNavigationService;
use App\Service\MailService;
use App\Service\RememberMeService;
use App\Service\StaffActivityService;
use App\Service\StaffPermissionService;

class TwoFactorController
{
    private function canManageStaffPermissions(): bool
    {
        return Auth::hasCapability('staff.manage_permissions');
    }

    private function canEditProtectedSuperAdmin(): bool
    {
        return Auth::isSuperAdmin() || Auth::hasCapability('staff.edit_super_admin');
    }

    private function assertCanManageStaffRole(string $role, ?array $targetUser = null): void
    {
        if (
            $targetUser !== null
            && !empty($targetUser['is_super_admin'])
            && $this->canEditProtectedSuperAdmin()
        ) {
            return;
        }

        if (StaffPermissionService::roleSupportsCapabilities($role) && !$this->canManageStaffPermissions()) {
            Auth::denyAccess('Staff account management requires additional permission.');
        }
    }

    private function assertCanEditProtectedSuperAdmin(array $user): void
    {
        if (!empty($user['is_super_admin']) && !$this->canEditProtectedSuperAdmin()) {
            Auth::denyAccess('This protected super admin account can only be edited by staff with explicit super admin access.');
        }
    }

    private function assertLockedTargetStillManageable(array $user): void
    {
        if (!empty($user['is_super_admin']) && !$this->canEditProtectedSuperAdmin()) {
            throw new \RuntimeException('This protected super admin account can only be edited by staff with explicit super admin access.');
        }

        if (
            StaffPermissionService::roleSupportsCapabilities((string)($user['role'] ?? ''))
            && !$this->canManageStaffPermissions()
        ) {
            throw new \RuntimeException('Staff account management requires additional permission.');
        }
    }

    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    public function disableUser2FA()
    {
        Auth::requireCapability('users.2fa_reset');
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF mismatch');
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->abortText(422, 'Invalid user');
        }

        if ($userId === (int)(Auth::id() ?? 0)) {
            $this->abortText(403, 'Use your own account settings to manage your two-factor configuration.');
        }

        $db = Database::getInstance()->getConnection();
        User::ensureRuntimeColumns($db);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT role, is_super_admin FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$targetUser) {
                throw new \UnexpectedValueException('User not found');
            }

            $this->assertLockedTargetStillManageable($targetUser);

            $db->prepare("DELETE FROM user_two_factor WHERE user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM user_two_factor_devices WHERE user_id = ?")->execute([$userId]);
            RememberMeService::revokeAllForUserWithConnection($db, $userId);
            StaffActivityService::logWithConnection($db, 'user_2fa_disabled', 'user', $userId, 'Disabled two-factor authentication for user.', [], $userId);
            $db->commit();
        } catch (\UnexpectedValueException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->abortText(404, $e->getMessage());
        } catch (\RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->abortText(403, $e->getMessage());
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->abortText(500, 'Could not disable two-factor authentication right now.');
        }

        $user = User::find($userId);
        if ($user && !empty($user['email'])) {
            MailService::sendTemplate((string)$user['email'], 'two_factor_disabled', [
                '{username}' => (string)($user['username'] ?? 'User'),
            ], 'high');
        }

        $_SESSION['success'] = '2FA has been disabled for this user.';
        header('Location: ' . AdminUserNavigationService::destinationForUserEdit((int)$userId));
        exit;
    }
}
