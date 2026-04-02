<?php

namespace App\Controller;

use App\Model\File;
use App\Model\Package;
use App\Model\Setting;
use App\Model\User;
use App\Core\Auth;
use App\Core\View;
use App\Core\Database;
use App\Core\Csrf;

class HomeController {
    private function isHttpsRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function issueReferralCookie(int $referrerId): void
    {
        if ($referrerId <= 0) {
            return;
        }

        $secret = (string)\App\Core\Config::get('app_key', '');
        if ($secret === '') {
            return;
        }

        $payload = (string)$referrerId;
        $signature = hash_hmac('sha256', $payload, $secret);
        setcookie('ref', $payload . '.' . $signature, [
            'expires' => time() + (86400 * 30),
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function resolveReferralUserId(string $ref): ?int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        if (preg_match('/^u_[a-f0-9]{12}$/i', $ref)) {
            $user = User::findByPublicId($ref);
            return $user ? (int)($user['id'] ?? 0) : null;
        }

        if (ctype_digit($ref) && (int)$ref > 0) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$ref]);
            $refId = $stmt->fetchColumn();
            return $refId ? (int)$refId : null;
        }

        return null;
    }

    private function verifyTurnstile(string $token): bool
    {
        $secret = Setting::getEncrypted('captcha_secret_key', \App\Core\Config::get('turnstile.secret_key'));
        if (!$secret || !$token) {
            return false;
        }

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => \App\Service\SecurityService::getClientIp(),
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        if (!$resp) {
            return false;
        }

        $decoded = json_decode($resp, true);
        return !empty($decoded['success']);
    }

    private function decryptFileRows(array $files): array
    {
        foreach ($files as &$file) {
            foreach (['filename', 'mime_type', 'storage_path'] as $field) {
                if (!isset($file[$field]) || !is_string($file[$field])) {
                    continue;
                }
                $file[$field] = \App\Service\EncryptionService::decrypt($file[$field]);
            }
        }

        return $files;
    }

    private function siteName(): string
    {
        return Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
    }

    public function index(?string $id = null) {
        if (\App\Service\FeatureService::affiliateEnabled() && isset($_GET['ref'])) {
            $ref = trim((string) $_GET['ref']);
            $refId = $this->resolveReferralUserId($ref);
            if ($refId) {
                $this->issueReferralCookie($refId);
            }
        }

        if (!Auth::check()) {
            $packages = array_filter(Package::getAll(), function($pkg) {
                return $pkg['level_type'] !== 'admin';
            });
            View::render('home/landing.php', ['packages' => $packages]);
            return;
        }

        $userId = Auth::id() ?? 0;
        $folderId = $id ?: null;
        
        $currentFolder = null;
        if ($folderId) {
            $currentFolder = \App\Model\Folder::find($folderId);
            if (
                !$currentFolder ||
                ($currentFolder['status'] ?? 'active') !== 'active' ||
                ($currentFolder['user_id'] != $userId && !Auth::isAdmin())
            ) {
                header('Location: /'); exit;
            }
        }

        $idToFetch = $currentFolder ? $currentFolder['id'] : null;
        $folders = \App\Model\Folder::getByUser($userId, $idToFetch);
        $files = File::getByUser($userId, $idToFetch);

        $breadcrumbPath = [];
        if ($currentFolder) {
            $temp = $currentFolder;
            while ($temp && $temp['parent_id']) {
                $parent = \App\Model\Folder::find($temp['parent_id']);
                if ($parent) {
                    array_unshift($breadcrumbPath, [
                        'name' => $parent['name'],
                        'url' => '/folder/' . $parent['short_id']
                    ]);
                    $temp = $parent;
                } else {
                    break;
                }
            }
        }

        View::render('home/index.php', [
            'files' => $files, 
            'folders' => $folders,
            'currentFolder' => $currentFolder,
            'breadcrumbPath' => $breadcrumbPath,
            'pageHeading' => $currentFolder ? $currentFolder['name'] : 'All Files',
            'pageTitle' => $currentFolder ? ($currentFolder['name'] . " - " . $this->siteName()) : "Dashboard - " . $this->siteName()
        ]);
    }

    public function guestUpload() {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        if (Setting::get('upload_login_required', '0') === '1') {
            header('Location: /login');
            exit;
        }

        $guestPackage = Package::getGuestPackage();
        if (!$guestPackage) {
            header('Location: /');
            exit;
        }

        View::render('home/index.php', [
            'files' => [],
            'folders' => [],
            'currentFolder' => null,
            'breadcrumbPath' => [],
            'guestMode' => true,
            'pageHeading' => 'Guest Upload',
            'pageTitle' => 'Guest Upload - ' . $this->siteName(),
        ]);
    }

    public function trash() {
        if (!Auth::check()) {
            header('Location: /login'); exit;
        }

        $userId = Auth::id() ?? 0;
        $files = File::getDeletedByUser($userId);
        $folders = []; // Folders aren't soft deleted currently, they hard delete or cascade

        View::render('home/index.php', [
            'files' => $files, 
            'folders' => $folders,
            'currentFolder' => null,
            'isTrash' => true,
            'pageHeading' => 'Trash',
            'pageTitle' => "Trash - " . $this->siteName()
        ]);
    }

    public function recent() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        $userId = Auth::id() ?? 0;
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT f.*, sf.file_size, sf.mime_type, sf.storage_path, sf.storage_provider, sf.file_hash,
                   sf.file_server_id, sf.provider_etag
            FROM files f
            JOIN stored_files sf ON f.stored_file_id = sf.id
            WHERE f.user_id = ? AND f.status = 'active'
            ORDER BY f.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $files = $this->decryptFileRows($stmt->fetchAll());

        View::render('home/index.php', [
            'files' => $files,
            'folders' => [],
            'currentFolder' => null,
            'pageHeading' => 'Recent Files',
            'pageTitle' => "Recent Files - " . $this->siteName()
        ]);
    }

    public function shared() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        $userId = Auth::id() ?? 0;
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT f.*, sf.file_size, sf.mime_type, sf.storage_path, sf.storage_provider, sf.file_hash,
                   sf.file_server_id, sf.provider_etag
            FROM files f 
            JOIN stored_files sf ON f.stored_file_id = sf.id 
            WHERE f.user_id = ? AND f.status = 'active' AND f.is_public = 1 
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$userId]);
        $files = $this->decryptFileRows($stmt->fetchAll());

        View::render('home/index.php', [
            'files' => $files,
            'folders' => [],
            'currentFolder' => null,
            'pageHeading' => 'Shared Files',
            'pageTitle' => "Shared Files - " . $this->siteName(),
            'isShared' => true
        ]);
    }

    public function faq() {
        $packages = array_filter(Package::getAll(), function($pkg) {
            return $pkg['level_type'] !== 'admin';
        });
        View::render('home/faq.php', ['packages' => $packages]);
    }

    public function api() {
        View::render('home/api.php');
    }





    public function notifications() {
        if (!Auth::check()) { header('Location: /login'); exit; }
        $userId = Auth::id();
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll();

        View::render('home/notifications.php', ['notifications' => $notifications]);
    }

    public function markNotificationsRead() {
        if (!Auth::check()) die("Login required");
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF mismatch");
        }
        \App\Service\NotificationService::markAllRead(Auth::id() ?? 0);
        echo json_encode(['status' => 'success']);
    }

    public function contact() {
        $error = '';
        $success = '';
        $captchaEnabled = Setting::get('captcha_contact', '0') === '1';
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $captchaActive = $captchaEnabled && $captchaSiteKey !== '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } elseif ($captchaActive && !$this->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                $error = "Captcha verification failed. Please try again.";
            } else {
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $subject = trim($_POST['subject'] ?? '');
                $message = trim($_POST['message'] ?? '');

                if (empty($name) || empty($email) || empty($subject) || empty($message)) {
                    $error = "All fields are required.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email address.";
                } else {
                    $db = Database::getInstance()->getConnection();
                    
                    $encName = \App\Service\EncryptionService::encrypt($name);
                    $encEmail = \App\Service\EncryptionService::encrypt($email);
                    $encSubject = \App\Service\EncryptionService::encrypt($subject);
                    $encMessage = \App\Service\EncryptionService::encrypt($message);
                    $encIp = \App\Service\EncryptionService::encrypt(\App\Service\SecurityService::getClientIp());
                    
                    $stmt = $db->prepare("INSERT INTO contact_messages (name, email, subject, message, ip_address) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt->execute([$encName, $encEmail, $encSubject, $encMessage, $encIp])) {
                        $success = "Your message has been sent successfully. We will get back to you soon.";
                        
                        // Send Responder to User
                        \App\Service\MailService::sendTemplate($email, 'contact_form_responder', [
                            '{username}' => $name,
                            '{subject}' => $subject
                        ]);

                        // Send Alert to Admin
                        $adminEmail = Setting::get('admin_notification_email', '');
                        if ($adminEmail) {
                            \App\Service\MailService::sendTemplate($adminEmail, 'admin_notification', [
                                '{event_type}' => 'New Contact Message',
                                '{details}' => "From: $name ($email)\nSubject: $subject\n\n$message"
                            ]);
                        }
                    } else {
                        $error = "Failed to send message. Please try again later.";
                    }
                }
            }
        }

        View::render('home/contact.php', [
            'error' => $error,
            'success' => $success,
            'captchaEnabled' => $captchaActive,
            'captchaSiteKey' => $captchaSiteKey,
        ]);
    }

    public function dmca() {
        $error = '';
        $success = '';
        $captchaEnabled = Setting::get('captcha_dmca', '0') === '1';
        $captchaSiteKey = Setting::get('captcha_site_key', '');
        $captchaActive = $captchaEnabled && $captchaSiteKey !== '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $error = "Security Token Expired. Please refresh.";
            } elseif ($captchaActive && !$this->verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
                $error = "Captcha verification failed. Please try again.";
            } else {
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $url = trim($_POST['url'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $signature = trim($_POST['signature'] ?? '');
                $normalizedUrlList = $this->normalizeDmcaUrls($url);
                $normalizedUrlValue = implode("\n", $normalizedUrlList);

                if (empty($name) || empty($email) || empty($normalizedUrlList) || empty($description) || empty($signature)) {
                    $error = "All fields are required for a valid DMCA notice.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email address.";
                } else {
                    $encName = \App\Service\EncryptionService::encrypt($name);
                    $encEmail = \App\Service\EncryptionService::encrypt($email);
                    $encUrl = \App\Service\EncryptionService::encrypt($normalizedUrlValue);
                    $encDescription = \App\Service\EncryptionService::encrypt($description);
                    $encSignature = \App\Service\EncryptionService::encrypt($signature);
                    $encIp = \App\Service\EncryptionService::encrypt(\App\Service\SecurityService::getClientIp());
                    
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("INSERT INTO dmca_reports (reporter_name, reporter_email, infringing_url, description, signature, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt->execute([$encName, $encEmail, $encUrl, $encDescription, $encSignature, $encIp])) {
                        $success = "Your message has been submitted. Our legal team will review it within 48 hours.";

                        \App\Service\MailService::sendTemplate($email, 'dmca_form_responder', [
                            '{username}' => $name,
                            '{subject}' => 'DMCA Notice',
                        ]);

                        // Send Alert to Admin
                        $adminEmail = Setting::get('admin_notification_email', '');
                        if ($adminEmail) {
                            \App\Service\MailService::sendTemplate($adminEmail, 'admin_notification', [
                                '{event_type}' => 'New DMCA Report',
                                '{details}' => "From: $name ($email)\nURL(s):\n$normalizedUrlValue\n\n$description"
                            ]);
                        }
                    } else {
                        $error = "Failed to submit report. Please try again later.";
                    }
                }
            }
        }

        View::render('home/dmca.php', [
            'error' => $error,
            'success' => $success,
            'captchaEnabled' => $captchaActive,
            'captchaSiteKey' => $captchaSiteKey,
        ]);
    }

    private function normalizeDmcaUrls(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $urls = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (!preg_match('~^https?://~i', $part)) {
                $part = 'https://' . ltrim($part, '/');
            }

            if (filter_var($part, FILTER_VALIDATE_URL)) {
                $urls[] = $part;
            }
        }

        return array_values(array_unique($urls));
    }
}
