<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Model\User;
use App\Service\MailService;

class TwoFactorController
{
    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    public function disableUser2FA()
    {
        Auth::requireAdmin();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF mismatch');
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->abortText(422, 'Invalid user');
        }

        $db = Database::getInstance()->getConnection();
        $db->prepare("DELETE FROM user_two_factor WHERE user_id = ?")->execute([$userId]);
        $db->prepare("DELETE FROM user_two_factor_devices WHERE user_id = ?")->execute([$userId]);

        $user = User::find($userId);
        if ($user && !empty($user['email'])) {
            MailService::sendTemplate((string)$user['email'], 'two_factor_disabled', [
                '{username}' => (string)($user['username'] ?? 'User'),
            ], 'high');
        }

        $_SESSION['success'] = '2FA has been disabled for this user.';
        header("Location: /admin/users/edit/$userId");
        exit;
    }
}
