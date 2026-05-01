<?php

namespace App\Controller;

use App\Model\Package;
use App\Core\Auth;
use App\Core\View;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Database;
use App\Service\PaymentService;
use App\Service\PackageAllowanceService;
use App\Service\RateLimiterService;
use App\Service\SecurityService;

class CheckoutController {
    private function storageQuotaInfo(int $userId): array
    {
        $stmt = Database::getInstance()->getConnection()->prepare('SELECT storage_used FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $used = (int)($stmt->fetchColumn() ?: 0);

        $package = \App\Model\Package::getUserPackage($userId);
        $limit = (int)($package['max_storage_bytes'] ?? 0);

        return ['used' => $used, 'limit' => $limit];
    }

    private function decryptedSecretReady(string $value): bool
    {
        $value = trim($value);
        return $value !== '' && !str_starts_with($value, 'ENC:');
    }

    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    private function stripeCheckoutReady(): bool
    {
        if (\App\Model\Setting::get('payment_stripe_enabled', '0') !== '1') {
            return false;
        }

        return $this->decryptedSecretReady((string)\App\Model\Setting::getEncrypted('payment_stripe_secret_key', ''));
    }

    private function paypalCheckoutReady(): bool
    {
        if (\App\Model\Setting::get('payment_paypal_enabled', '0') !== '1') {
            return false;
        }

        return trim((string)\App\Model\Setting::get('payment_paypal_client_id', '')) !== ''
            && $this->decryptedSecretReady((string)\App\Model\Setting::getEncrypted('payment_paypal_client_secret', ''));
    }

    private function shouldExposeGatewayFailure(string $gateway): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }

        if ($gateway === 'paypal' && \App\Model\Setting::get('payment_paypal_sandbox', '1') === '1') {
            return true;
        }

        return false;
    }

    public function plans(): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $allPackages = Package::getAll();
        $paidPackages = array_values(array_filter($allPackages, static function (array $pkg): bool {
            return ($pkg['level_type'] ?? '') === 'paid';
        }));

        if ($paidPackages === []) {
            header('Location: /');
            exit;
        }

        $currentPackage = Package::getUserPackage((int) Auth::id());

        View::render('home/plans.php', [
            'paidPackages' => $paidPackages,
            'currentPackageId' => (int)($currentPackage['id'] ?? 0),
            'stripeEnabled' => $this->stripeCheckoutReady(),
            'paypalEnabled' => $this->paypalCheckoutReady(),
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary((int) Auth::id(), $currentPackage ?: []),
            'storageQuota' => $this->storageQuotaInfo((int) Auth::id()),
        ]);
    }

    public function history(): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        PaymentService::ensureTablesExist();

        $userId = (int) Auth::id();
        $db = Database::getInstance()->getConnection();
        $currentPackage = \App\Model\Package::getUserPackage($userId);
        $currentUser = Auth::user();

        $transactionStmt = $db->prepare("
            SELECT
                t.id,
                t.amount,
                t.currency,
                t.gateway,
                t.gateway_reference,
                t.status,
                t.created_at,
                p.name AS package_name
            FROM transactions t
            LEFT JOIN packages p ON p.id = t.package_id
            WHERE t.user_id = ?
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT 100
        ");
        $transactionStmt->execute([$userId]);
        $transactions = $transactionStmt->fetchAll();

        $subscriptionStmt = $db->prepare("
            SELECT
                s.id,
                s.status,
                s.amount,
                s.currency,
                s.billing_period,
                s.gateway,
                s.gateway_reference,
                s.expires_at,
                s.created_at,
                s.updated_at,
                p.name AS package_name
            FROM subscriptions s
            LEFT JOIN packages p ON p.id = s.package_id
            WHERE s.user_id = ?
            ORDER BY s.created_at DESC, s.id DESC
            LIMIT 50
        ");
        $subscriptionStmt->execute([$userId]);
        $subscriptions = $subscriptionStmt->fetchAll();

        $summaryStmt = $db->prepare("
            SELECT
                COUNT(*) AS transaction_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS completed_total,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END), 0) AS refunded_total
            FROM transactions
            WHERE user_id = ?
        ");
        $summaryStmt->execute([$userId]);
        $summary = $summaryStmt->fetch() ?: [
            'transaction_count' => 0,
            'completed_total' => 0,
            'refunded_total' => 0,
        ];

        View::render('home/payments.php', [
            'transactions' => $transactions,
            'subscriptions' => $subscriptions,
            'summary' => $summary,
            'currentPackage' => $currentPackage,
            'currentUser' => $currentUser,
            'dailyDownloadLimitSummary' => PackageAllowanceService::dailyDownloadLimitSummary($userId, $currentPackage ?: []),
            'storageQuota' => $this->storageQuotaInfo($userId),
        ]);
    }
    
    public function index(string $id) {
        if (!Auth::check()) {
            header('Location: /login'); exit;
        }

        $packageId = (int)$id;
        $package = Package::find($packageId);
        if (!$package || $package['level_type'] !== 'paid') {
            header('Location: /'); exit;
        }

        View::render('home/checkout.php', [
            'package' => $package,
            'stripeEnabled' => $this->stripeCheckoutReady(),
            'paypalEnabled' => $this->paypalCheckoutReady(),
            'cancelledGateway' => $_GET['gateway'] ?? '',
            'checkoutError' => $_SESSION['checkout_error'] ?? '',
        ]);
        unset($_SESSION['checkout_error']);
    }

    public function process() {
        if (!Auth::check()) {
            $this->abortText(401, "Unauthorized");
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Mismatch");
        }

        $packageId = (int)$_POST['package_id'];
        $gateway = $_POST['gateway'] ?? '';
        $package = Package::find($packageId);

        if (!$package || ($package['level_type'] ?? '') !== 'paid') {
            $this->abortText(422, "Invalid package.");
        }
        if (!in_array($gateway, ['stripe', 'paypal'], true)) {
            $this->abortText(422, "Invalid payment method.");
        }

        if (($gateway === 'stripe' && !$this->stripeCheckoutReady())
            || ($gateway === 'paypal' && !$this->paypalCheckoutReady())) {
            $this->abortText(422, "Selected payment method is not fully configured.");
        }

        try {
            $transaction = PaymentService::createPendingTransaction(
                Auth::id(),
                $packageId,
                $gateway,
                \App\Service\SecurityService::getClientIp()
            );
            $url = PaymentService::createGatewayCheckoutUrl($gateway, $transaction, $package);
            header('Location: ' . $url);
            exit;
        } catch (\Throwable $e) {
            Logger::error('Checkout process failed', [
                'gateway' => $gateway,
                'package_id' => $packageId,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['checkout_error'] = $this->shouldExposeGatewayFailure($gateway)
                ? $e->getMessage()
                : 'The selected payment gateway could not start checkout. Please try again or contact support.';
            header('Location: /checkout/' . $packageId . '?checkout_error_gateway=' . urlencode($gateway));
            exit;
        }
    }

    public function callback(string $gateway) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }

        $clientIp = SecurityService::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        $rateKey = $gateway . ':' . $clientIp;
        if (!RateLimiterService::check('payment_callback', $rateKey, 30, 300)) {
            $this->abortText(429, "Too Many Requests");
        }

        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $payload = is_array($decoded) ? $decoded : $_POST;
        $signature = (string)($_SERVER['HTTP_X_FYUHLS_SIGNATURE'] ?? ($payload['signature'] ?? ''));
        unset($payload['signature']);
        if ($raw !== '') {
            $payload['_raw_body'] = $raw;
        }

        try {
            $result = PaymentService::handleCallback($gateway, $payload, $signature);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success'] + $result);
        } catch (\Throwable $e) {
            Logger::error('Payment callback failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Callback processing failed.',
            ]);
        }
    }

    public function stripeSuccess()
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $sessionId = trim((string)($_GET['session_id'] ?? ''));
        if ($sessionId === '') {
            header('Location: /settings?payment=stripe_missing_session');
            exit;
        }

        try {
            PaymentService::confirmStripeSuccess($sessionId);
            header('Location: /settings?payment=stripe_success');
        } catch (\Throwable $e) {
            header('Location: /settings?payment=stripe_pending');
        }
        exit;
    }

    public function paypalReturn()
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $orderId = trim((string)($_GET['token'] ?? ''));
        $reference = trim((string)($_GET['reference'] ?? ''));
        if ($orderId === '' || $reference === '') {
            header('Location: /settings?payment=paypal_missing_order');
            exit;
        }

        try {
            PaymentService::capturePayPalOrder($orderId, $reference);
            header('Location: /settings?payment=paypal_success');
        } catch (\Throwable $e) {
            Logger::error('PayPal return capture failed', [
                'order_id' => $orderId,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['payment_error'] = $this->shouldExposeGatewayFailure('paypal')
                ? $e->getMessage()
                : 'We could not finalize your PayPal checkout. Please contact support if the payment completed on PayPal.';
            header('Location: /settings?payment=paypal_failed');
        }
        exit;
    }

    public function cancel()
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $gateway = trim((string)($_GET['gateway'] ?? 'payment'));
        header('Location: /settings?payment=' . urlencode($gateway . '_cancelled'));
        exit;
    }

}
