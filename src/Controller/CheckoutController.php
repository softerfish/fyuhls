<?php

namespace App\Controller;

use App\Model\Package;
use App\Core\Auth;
use App\Core\View;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Database;
use App\Service\PaymentService;
use App\Service\CouponService;
use App\Service\PackageAllowanceService;
use App\Service\RateLimiterService;
use App\Service\SecurityService;

class CheckoutController {
    private function unresolvedCancellationMessage(string $reference): string
    {
        $reference = trim($reference);
        $suffix = $reference !== '' ? ' Reference: ' . $reference . '.' : '';
        return 'We could not confirm that your pending coupon checkout was cancelled. No further checkout changes were applied.' . $suffix . ' Please try again in a moment or contact support before starting another coupon checkout.';
    }

    private function rememberPendingCancellation(string $gateway, string $reference): void
    {
        $_SESSION['pending_payment_cancel'] = [
            'gateway' => $gateway,
            'reference' => $reference,
        ];
    }

    private function clearPendingCancellation(): void
    {
        unset($_SESSION['pending_payment_cancel']);
    }

    private function processCancelRequest(string $method, string $gateway, string $reference, int $userId, ?string $csrfToken = null): array
    {
        if ($userId <= 0) {
            return ['action' => 'login'];
        }

        if ($method === 'POST') {
            if (!Csrf::verify($csrfToken ?? '')) {
                return ['action' => 'error', 'status' => 403, 'message' => 'CSRF Mismatch'];
            }

            if ($reference !== '') {
                try {
                    CouponService::cancelPendingReservationForReference($gateway, $reference, $userId);
                } catch (\Throwable $e) {
                    Logger::error('Pending checkout cancellation failed', [
                        'gateway' => $gateway,
                        'reference' => $reference,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->rememberPendingCancellation($gateway, $reference);
                    return [
                        'action' => 'error',
                        'status' => 503,
                        'message' => $this->unresolvedCancellationMessage($reference),
                    ];
                }
            }

            $this->clearPendingCancellation();

            return ['action' => 'redirect', 'location' => '/settings?payment=' . urlencode($gateway . '_cancelled')];
        }

        if ($reference === '') {
            return ['action' => 'redirect', 'location' => '/settings?payment=' . urlencode($gateway . '_cancelled')];
        }

        $this->rememberPendingCancellation($gateway, $reference);

        return ['action' => 'confirm', 'gateway' => $gateway, 'reference' => $reference];
    }

    private function renderCancelConfirmation(string $gateway, string $reference): void
    {
        $safeGateway = htmlspecialchars($gateway, ENT_QUOTES, 'UTF-8');
        $safeReference = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
        $safeBack = htmlspecialchars('/settings?payment=' . urlencode($gateway . '_resume'), ENT_QUOTES, 'UTF-8');

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Confirm checkout cancellation</title></head><body>';
        echo '<main style="max-width:32rem;margin:4rem auto;font-family:Arial,sans-serif;line-height:1.5;">';
        echo '<h1>Confirm checkout cancellation</h1>';
        echo '<p>Confirm below if you want to release the pending coupon reservation for reference <code>' . $safeReference . '</code>. We only complete the cancellation after this same-site confirmation step.</p>';
        echo '<form method="post" action="/payment/cancel">';
        echo Csrf::field();
        echo '<input type="hidden" name="gateway" value="' . $safeGateway . '">';
        echo '<input type="hidden" name="reference" value="' . $safeReference . '">';
        echo '<button type="submit">Cancel checkout</button>';
        echo '</form>';
        echo '<p style="margin-top:1rem;"><a href="' . $safeBack . '">Go back without cancelling</a></p>';
        echo '</main></body></html>';
        exit;
    }

    private function pullCheckoutCouponState(int $packageId): array
    {
        $state = $_SESSION['checkout_coupon_state'] ?? null;
        if (!is_array($state) || (int)($state['package_id'] ?? 0) !== $packageId) {
            unset($_SESSION['checkout_coupon_state']);
            return [
                'couponCode' => '',
                'couponPreview' => ['valid' => false, 'code' => '', 'message' => ''],
                'autoRenew' => null,
                'billingOptionId' => null,
            ];
        }

        unset($_SESSION['checkout_coupon_state']);

        return [
            'couponCode' => (string)($state['coupon_code'] ?? ''),
            'couponPreview' => is_array($state['coupon_preview'] ?? null)
                ? $state['coupon_preview']
                : ['valid' => false, 'code' => '', 'message' => ''],
            'autoRenew' => array_key_exists('auto_renew', $state) ? (bool)$state['auto_renew'] : null,
            'billingOptionId' => isset($state['billing_option_id']) ? (int)$state['billing_option_id'] : null,
        ];
    }

    private function stashCheckoutCouponState(int $packageId, string $couponCode, array $couponPreview, ?bool $autoRenew = null, ?int $billingOptionId = null): void
    {
        $_SESSION['checkout_coupon_state'] = [
            'package_id' => $packageId,
            'coupon_code' => $couponCode,
            'coupon_preview' => $couponPreview,
            'auto_renew' => $autoRenew,
            'billing_option_id' => $billingOptionId,
        ];
    }

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
                t.original_amount,
                t.discount_amount,
                t.coupon_code,
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
                s.original_amount,
                s.discount_amount,
                s.coupon_code,
                s.amount,
                s.currency,
                s.term_days,
                s.auto_renew,
                s.billing_period,
                s.gateway,
                s.gateway_reference,
                s.provider_subscription_id,
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
        $subscriptionSyncStates = PaymentService::unresolvedGatewaySyncStates(array_map(
            static fn (array $subscription): int => (int)($subscription['id'] ?? 0),
            $subscriptions
        ));
        foreach ($subscriptions as &$subscription) {
            $subscription['gateway_sync'] = $subscriptionSyncStates[(int)($subscription['id'] ?? 0)] ?? null;
        }
        unset($subscription);

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

        $couponState = $this->pullCheckoutCouponState($packageId);
        $couponCode = $couponState['couponCode'];
        $billingOptions = PaymentService::checkoutBillingOptions($package);
        $requestedOptionId = isset($_GET['option']) ? (int)$_GET['option'] : null;
        $selectedBillingOption = PaymentService::resolveCheckoutBillingOption($package, $requestedOptionId ?: $couponState['billingOptionId']);
        $selectedBillingOptionId = isset($selectedBillingOption['id']) ? (int)$selectedBillingOption['id'] : null;
        $couponPreview = $couponState['billingOptionId'] === $selectedBillingOptionId ? $couponState['couponPreview'] : ['valid' => false, 'code' => '', 'message' => ''];
        $zeroDollarCoupon = !empty($couponPreview['valid']) && (float)($couponPreview['final_amount'] ?? 0) <= 0;
        $termDays = (int)$selectedBillingOption['term_days'];
        $renewalEnabled = !empty($selectedBillingOption['renewal_enabled']);
        $autoRenewDefault = $renewalEnabled && !$zeroDollarCoupon && ($this->stripeCheckoutReady() || $this->paypalCheckoutReady());
        $autoRenewSelected = $couponState['autoRenew'];
        if ($autoRenewSelected === null) {
            $autoRenewSelected = $autoRenewDefault;
        }
        if ($zeroDollarCoupon) {
            $autoRenewSelected = false;
        }

        View::render('home/checkout.php', [
            'package' => $package,
            'stripeEnabled' => $this->stripeCheckoutReady(),
            'paypalEnabled' => $this->paypalCheckoutReady(),
            'cancelledGateway' => $_GET['gateway'] ?? '',
            'checkoutError' => $_SESSION['checkout_error'] ?? '',
            'couponCode' => $couponCode,
            'couponPreview' => $couponPreview,
            'zeroDollarCoupon' => $zeroDollarCoupon,
            'billingOptions' => $billingOptions,
            'selectedBillingOption' => $selectedBillingOption,
            'termDays' => $termDays,
            'termLabel' => PaymentService::formatTermLabel($termDays),
            'renewalEnabled' => $renewalEnabled,
            'renewalSummary' => PaymentService::formatRenewalSummary($termDays),
            'autoRenewSelected' => $autoRenewSelected,
        ]);
        unset($_SESSION['checkout_error']);
    }

    public function preview(): void
    {
        if (!Auth::check()) {
            $this->abortText(401, "Unauthorized");
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Mismatch");
        }

        $packageId = (int)($_POST['package_id'] ?? 0);
        $couponCode = trim((string)($_POST['coupon_code'] ?? ''));
        $autoRenew = isset($_POST['auto_renew']) && $_POST['auto_renew'] === '1';
        $billingOptionId = isset($_POST['billing_option_id']) ? (int)$_POST['billing_option_id'] : null;
        $package = Package::find($packageId);
        if (!$package || ($package['level_type'] ?? '') !== 'paid') {
            $this->abortText(422, "Invalid package.");
        }

        if ($couponCode !== '') {
            $clientIp = SecurityService::getClientIp();
            if (
                !RateLimiterService::check('checkout_coupon_preview_user', (string)(Auth::id() ?? 0), 12, 600)
                || !RateLimiterService::check('checkout_coupon_preview_ip', $clientIp, 40, 600)
            ) {
                $this->stashCheckoutCouponState($packageId, $couponCode, [
                    'valid' => false,
                    'code' => CouponService::normalizeCode($couponCode),
                    'message' => 'Too many coupon preview attempts. Please wait a few minutes before trying another code.',
                ], $autoRenew, $billingOptionId);

                $redirect = '/checkout/' . $packageId;
                if ($billingOptionId !== null && $billingOptionId > 0) {
                    $redirect .= '?option=' . $billingOptionId;
                }
                header('Location: ' . $redirect);
                exit;
            }
        }

        $couponPreview = $couponCode !== ''
            ? CouponService::previewForCheckout((int)Auth::id(), $packageId, $couponCode, $billingOptionId, $autoRenew, (string)($_POST['gateway'] ?? ''))
            : ['valid' => false, 'code' => '', 'message' => ''];

        $this->stashCheckoutCouponState($packageId, $couponCode, $couponPreview, $autoRenew, $billingOptionId);
        $redirect = '/checkout/' . $packageId;
        if ($billingOptionId !== null && $billingOptionId > 0) {
            $redirect .= '?option=' . $billingOptionId;
        }
        header('Location: ' . $redirect);
        exit;
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
        $couponCode = trim((string)($_POST['coupon_code'] ?? ''));
        $autoRenew = isset($_POST['auto_renew']) && $_POST['auto_renew'] === '1';
        $billingOptionId = isset($_POST['billing_option_id']) ? (int)$_POST['billing_option_id'] : null;
        $clientIp = \App\Service\SecurityService::getClientIp();
        $package = Package::find($packageId);
        $couponPreview = $couponCode !== ''
            ? CouponService::previewForCheckout((int)Auth::id(), $packageId, $couponCode, $billingOptionId, $autoRenew, $gateway)
            : ['valid' => false, 'code' => '', 'message' => ''];
        $zeroDollarCoupon = !empty($couponPreview['valid']) && (float)($couponPreview['final_amount'] ?? 0) <= 0;

        if (!$package || ($package['level_type'] ?? '') !== 'paid') {
            $this->abortText(422, "Invalid package.");
        }
        if ($zeroDollarCoupon) {
            $gateway = 'coupon';
        }
        if (!in_array($gateway, ['stripe', 'paypal', 'coupon'], true)) {
            $this->abortText(422, "Invalid payment method.");
        }

        if (($gateway === 'stripe' && !$this->stripeCheckoutReady())
            || ($gateway === 'paypal' && !$this->paypalCheckoutReady())) {
            $this->abortText(422, "Selected payment method is not fully configured.");
        }
        if (
            !RateLimiterService::check('checkout_start_user', (string)(Auth::id() ?? 0), 6, 600)
            || !RateLimiterService::check('checkout_start_ip', $clientIp, 20, 600)
        ) {
            $this->abortText(429, 'Too many checkout attempts. Please wait a few minutes before trying again.');
        }

        $transaction = null;
        try {
            $transaction = PaymentService::createPendingTransaction(
                Auth::id(),
                $packageId,
                $gateway,
                $clientIp,
                $couponCode,
                $autoRenew,
                $billingOptionId
            );
            if ((float)($transaction['amount'] ?? 0) <= 0) {
                PaymentService::completeZeroAmountTransaction((int)$transaction['id'], (int)Auth::id());
                header('Location: /settings?payment=coupon_success');
                exit;
            }
            $url = PaymentService::createGatewayCheckoutUrl($gateway, $transaction, $package);
            header('Location: ' . $url);
            exit;
        } catch (\Throwable $e) {
            Logger::error('Checkout process failed', [
                'gateway' => $gateway,
                'package_id' => $packageId,
                'error' => $e->getMessage(),
            ]);
            $cleanupFailed = false;
            if (is_array($transaction) && !empty($transaction['reference'])) {
                $cleanupReference = (string)$transaction['reference'];
                try {
                    CouponService::cancelPendingReservationForReference($gateway, $cleanupReference, (int)Auth::id());
                    $this->clearPendingCancellation();
                } catch (\Throwable $cleanupError) {
                    Logger::error('Checkout startup cleanup failed', [
                        'gateway' => $gateway,
                        'package_id' => $packageId,
                        'reference' => $cleanupReference,
                        'error' => $cleanupError->getMessage(),
                    ]);
                    $cleanupFailed = true;
                    $this->rememberPendingCancellation($gateway, $cleanupReference);
                }
            }
            $this->stashCheckoutCouponState($packageId, $couponCode, $couponPreview, $autoRenew, $billingOptionId);
            if ($cleanupFailed ?? false) {
                $_SESSION['checkout_error'] = $this->unresolvedCancellationMessage($cleanupReference ?? '');
                header('Location: /payment/cancel?gateway=' . urlencode($gateway) . '&reference=' . urlencode((string)($cleanupReference ?? '')));
                exit;
            }
            $_SESSION['checkout_error'] = $this->shouldExposeGatewayFailure($gateway)
                ? $e->getMessage()
                : 'The selected payment gateway could not start checkout. Please try again or contact support.';
            $redirect = '/checkout/' . $packageId;
            if ($billingOptionId !== null && $billingOptionId > 0) {
                $redirect .= '?option=' . $billingOptionId . '&checkout_error_gateway=' . urlencode($gateway);
            } else {
                $redirect .= '?checkout_error_gateway=' . urlencode($gateway);
            }
            header('Location: ' . $redirect);
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
            PaymentService::confirmStripeSuccess($sessionId, (int)(Auth::id() ?? 0));
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
        if ($reference === '') {
            header('Location: /settings?payment=paypal_missing_order');
            exit;
        }

        try {
            if ($orderId !== '') {
                PaymentService::capturePayPalOrder($orderId, $reference, (int)(Auth::id() ?? 0));
                header('Location: /settings?payment=paypal_success');
            } else {
                $result = PaymentService::confirmPayPalSubscription($reference, (int)(Auth::id() ?? 0));
                header('Location: /settings?payment=' . (($result['status'] ?? '') === 'completed' ? 'paypal_success' : 'paypal_pending'));
            }
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

        $isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        $payload = $isPost ? $_POST : $_GET;
        $gateway = trim((string)($payload['gateway'] ?? 'payment'));
        $reference = trim((string)($payload['reference'] ?? ''));

        if (!$isPost && $reference === '' && isset($_SESSION['pending_payment_cancel']) && is_array($_SESSION['pending_payment_cancel'])) {
            $gateway = trim((string)($_SESSION['pending_payment_cancel']['gateway'] ?? $gateway));
            $reference = trim((string)($_SESSION['pending_payment_cancel']['reference'] ?? ''));
        }

        $result = $this->processCancelRequest(
            $isPost ? 'POST' : 'GET',
            $gateway,
            $reference,
            (int)(Auth::id() ?? 0),
            $isPost ? (string)($_POST['csrf_token'] ?? '') : null
        );

        if (($result['action'] ?? '') === 'login') {
            header('Location: /login');
            exit;
        }
        if (($result['action'] ?? '') === 'error') {
            $this->abortText((int)($result['status'] ?? 400), (string)($result['message'] ?? 'Request failed'));
        }
        if (($result['action'] ?? '') === 'confirm') {
            $this->renderCancelConfirmation((string)$result['gateway'], (string)$result['reference']);
        }

        header('Location: ' . (string)($result['location'] ?? '/settings'));
        exit;
    }

    public function updateAutoRenew(string $id)
    {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Mismatch");
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

        try {
            PaymentService::updateAutoRenewPreference((int)$id, (int)(Auth::id() ?? 0), $enabled);
            $_SESSION['success'] = $enabled ? 'Auto-renew turned back on.' : 'Auto-renew will stop after the current term ends.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
        }

        header('Location: /payments');
        exit;
    }

}
