<?php

namespace App\Controller;

use App\Model\Package;
use App\Core\Auth;
use App\Core\View;
use App\Core\Csrf;
use App\Core\Logger;
use App\Service\PaymentService;

class CheckoutController {
    
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
            'stripeEnabled' => \App\Model\Setting::get('payment_stripe_enabled', '0') === '1',
            'paypalEnabled' => \App\Model\Setting::get('payment_paypal_enabled', '0') === '1',
            'cancelledGateway' => $_GET['gateway'] ?? '',
        ]);
    }

    public function process() {
        if (!Auth::check()) {
            http_response_code(401); die("Unauthorized");
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') die("Method Not Allowed");
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF Mismatch");

        $packageId = (int)$_POST['package_id'];
        $gateway = $_POST['gateway'] ?? '';
        $package = Package::find($packageId);

        if (!$package || ($package['level_type'] ?? '') !== 'paid') die("Invalid package.");
        if (!in_array($gateway, ['stripe', 'paypal'], true)) {
            http_response_code(422);
            die("Invalid payment method.");
        }

        if (($gateway === 'stripe' && \App\Model\Setting::get('payment_stripe_enabled', '0') !== '1')
            || ($gateway === 'paypal' && \App\Model\Setting::get('payment_paypal_enabled', '0') !== '1')) {
            http_response_code(422);
            die("Selected payment method is not enabled.");
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
            http_response_code(422);
            echo 'The checkout request could not be completed.';
            exit;
        }
    }

    public function callback(string $gateway) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die("Method Not Allowed");
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
