<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\View;
use App\Core\Csrf;
use App\Model\Package;
use App\Model\User;
use App\Service\EncryptedSearchService;
use App\Service\MailService;

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
        Auth::requireAdmin();
    }

    public function index()
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();

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

        $packages = $db->query("SELECT id, name FROM packages ORDER BY id ASC")->fetchAll(\PDO::FETCH_ASSOC);
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
        ]);
    }

    public function create()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: /admin/users");
            exit;
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = "CSRF Token Mismatch";
            header("Location: /admin/users");
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $status = $_POST['status'] ?? 'active';
        $packageId = (int)($_POST['package_id'] ?? 1);

        $_SESSION['admin_create_user_form'] = [
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'package_id' => $packageId,
        ];

        $reservedUsernamesRaw = \App\Model\Setting::get('reserved_usernames', 'administrator,admin,support');
        $reservedUsernames = array_map('trim', explode(',', strtolower($reservedUsernamesRaw)));
        $role = in_array($role, ['user', 'admin'], true) ? $role : 'user';
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

        if (strlen($password) < 6) {
            $_SESSION['error'] = "Password must be at least 6 characters.";
            header("Location: /admin/users");
            exit;
        }

        $packageStmt = $db->prepare("SELECT id, name FROM packages WHERE id = ? LIMIT 1");
        $packageStmt->execute([$packageId]);
        $package = $packageStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$package) {
            $_SESSION['error'] = "Selected package was not found.";
            header("Location: /admin/users");
            exit;
        }

        $encUsername = \App\Service\EncryptionService::encrypt($username);
        $encEmail = \App\Service\EncryptionService::encrypt($email);
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$encUsername, $encEmail]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = "Username or email is already in use.";
            header("Location: /admin/users");
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
        $userId = User::create([
            'username' => $username,
            'email' => $email,
            'password' => $hash,
            'role' => $role,
            'package_id' => $packageId,
        ]);

        if ($status !== 'active') {
            $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, $userId]);
        }

        unset($_SESSION['admin_create_user_form']);
        $_SESSION['success'] = "User created successfully: {$username}";
        header("Location: /admin/users");
        exit;
    }

    public function action()
    {
        $this->checkAuth();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }

            $db = Database::getInstance()->getConnection();
            $userId = (int)$_POST['user_id'];
            $action = $_POST['action'];

            // 1. Safety Check: Last Admin Protection
            if ($action === 'delete' || $action === 'remove_admin' || $action === 'ban') {
                $stmt = $db->prepare("SELECT role, status FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $targetUser = $stmt->fetch();

                if ($targetUser && $targetUser['role'] === 'admin') {
                    $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
                    if ($adminCount <= 1) {
                        $_SESSION['error'] = "Action denied: You cannot delete, ban, or demote the last active administrator.";
                        header("Location: /admin/users"); exit;
                    }
                }
            }

            // Prevent self-action (redundant but safe)
            if ($userId === Auth::id() && ($action === 'delete' || $action === 'remove_admin' || $action === 'ban')) {
                $_SESSION['error'] = "You cannot perform this action on your own account.";
                header("Location: /admin/users"); exit;
            }

            if ($action === 'ban') {
                $this->clearDemoAdminIfMatches($userId);
                $stmt = $db->prepare("UPDATE users SET status = 'banned' WHERE id = ?");
                $stmt->execute([$userId]);
            }
            elseif ($action === 'unban') {
                $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                $stmt->execute([$userId]);
            }
            elseif ($action === 'make_admin') {
                $stmt = $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
                $stmt->execute([$userId]);
            }
            elseif ($action === 'remove_admin') {
                $this->clearDemoAdminIfMatches($userId);
                $stmt = $db->prepare("UPDATE users SET role = 'user' WHERE id = ?");
                $stmt->execute([$userId]);
            }
            elseif ($action === 'set_demo_admin') {
                $stmt = $db->prepare("SELECT role, status FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $targetUser = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$targetUser || $targetUser['role'] !== 'admin' || $targetUser['status'] !== 'active') {
                    $_SESSION['error'] = "Only active administrator accounts can be marked as the demo admin.";
                    header("Location: /admin/users"); exit;
                }

                $this->setDemoAdminUserId($userId);
                $_SESSION['success'] = "Demo admin account updated.";
                header("Location: /admin/users"); exit;
            }
            elseif ($action === 'clear_demo_admin') {
                $this->clearDemoAdminIfMatches($userId);
                $_SESSION['success'] = "Demo admin account cleared.";
                header("Location: /admin/users"); exit;
            }
            elseif ($action === 'delete') {
                // 1. Check for active withdrawals (Enterprise Safety)
                $stmt = $db->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'approved')");
                $stmt->execute([$userId]);
                if ($stmt->fetchColumn() > 0) {
                    $_SESSION['error'] = "Cannot delete user while they have pending or approved withdrawals. Process those first.";
                    header("Location: /admin/users"); exit;
                }

                $this->clearDemoAdminIfMatches($userId);
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                if ($stmt->execute([$userId])) {
                    \App\Service\SystemStatsService::decrement('total_users');
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
        $userId = (int)$id;

        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            die("User not found");
        }

        // don't let an admin edit themselves through this page - use /settings instead
        if ($userId === Auth::id()) {
            header("Location: /settings");
            exit;
        }

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "CSRF Token Mismatch";
            } else {
                $username = strtolower(trim($_POST['username'] ?? ''));
                $email = strtolower(trim($_POST['email'] ?? ''));
                $role = $_POST['role'] ?? 'user';
                $status = $_POST['status'] ?? 'active';
                $packageId = (int)($_POST['package_id'] ?? 1);
                $newPassword = $_POST['new_password'] ?? '';
                $oldPackageId = (int)($user['package_id'] ?? 0);

                $reservedUsernamesRaw = \App\Model\Setting::get('reserved_usernames', 'administrator,admin,support');
                $reservedUsernames = array_map('trim', explode(',', strtolower($reservedUsernamesRaw)));

                if (strlen($username) < 3) {
                    $error = "Username is too short.";
                } elseif (in_array(strtolower($username), $reservedUsernames) && \App\Service\EncryptionService::decrypt($user['username']) !== $username) {
                    $error = "This username is reserved and cannot be used.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email address.";
                } else {
                    $encUsername = \App\Service\EncryptionService::encrypt($username);
                    $encEmail = \App\Service\EncryptionService::encrypt($email);

                    // Check if new username or email is already taken by another user
                    $stmt = $db->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1");
                    $stmt->execute([$encUsername, $encEmail, $userId]);
                    if ($stmt->fetch()) {
                        $error = "Username or email already taken by another user.";
                    } else {
                        // Safety Check: Last Admin Protection
                        if ($user['role'] === 'admin' && ($role !== 'admin' || $status !== 'active')) {
                            $adminCount = $this->countActiveAdmins($db);
                            if ($adminCount <= 1) {
                                $error = "Action denied: You cannot demote or deactivate the last active administrator.";
                            }
                        }

                        if (empty($error)) {
                            // Update basic attributes
                            $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, role = ?, status = ?, package_id = ? WHERE id = ?");
                            $stmt->execute([$encUsername, $encEmail, $role, $status, $packageId, $userId]);

                            if ($role !== 'admin' || $status !== 'active') {
                                $this->clearDemoAdminIfMatches($userId);
                            }

                            if ($oldPackageId !== $packageId) {
                                $oldPackage = Package::find($oldPackageId);
                                $newPackage = Package::find($packageId);
                                MailService::sendTemplate($email, 'package_changed', [
                                    '{username}' => $username,
                                    '{old_package}' => (string)($oldPackage['name'] ?? ('Package #' . $oldPackageId)),
                                    '{new_package}' => (string)($newPackage['name'] ?? ('Package #' . $packageId)),
                                ], 'high');
                            }

                            // Update password if provided
                            if (!empty($newPassword)) {
                                if (strlen($newPassword) < 6) {
                                    $error = "New password must be at least 6 characters.";
                                } else {
                                    $hash = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => 12]);
                                    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                                    $stmt->execute([$hash, $userId]);
                                }
                            }

                            if (empty($error)) {
                                // Handle Manual Credit if provided
                                $credit = (float)($_POST['credit_amount'] ?? 0);
                                if ($credit > 0) {
                                    $reason = $_POST['credit_reason'] ?: 'Admin Manual Credit';
                                    $db->prepare("INSERT INTO earnings (user_id, amount, type, status, description) VALUES (?, ?, 'bonus', 'cleared', ?)")
                                       ->execute([$userId, $credit, $reason]);
                                }

                                $success = "User updated successfully.";
                                // refresh user data for the view
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
        $stmt = $db->query("SELECT id, name FROM packages ORDER BY id ASC");
        $packages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        View::render('admin/users/edit.php', [
            'user' => $user,
            'packages' => $packages,
            'error' => $error,
            'success' => $success,
            'demoMode' => $this->isDemoModeEnabled(),
            'demoAdminUserId' => $this->getDemoAdminUserId(),
        ]);
    }
}
