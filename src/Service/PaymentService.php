<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Model\Package;
use App\Model\Setting;
use App\Model\User;

class PaymentService
{
    public const DEFAULT_PRICE = 9.99;
    public const DEFAULT_CURRENCY = 'USD';
    public const DEFAULT_BILLING_PERIOD = 'monthly';
    private const STRIPE_WEBHOOK_TOLERANCE = 300;

    public static function ensureTablesExist(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                package_id INT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'USD',
                gateway VARCHAR(50) NOT NULL,
                gateway_reference VARCHAR(191) NULL,
                status ENUM('pending', 'completed', 'failed', 'refunded', 'on_hold', 'denied') NOT NULL DEFAULT 'pending',
                ip_address VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY transactions_gateway_reference_idx (gateway_reference),
                KEY transactions_status_created_idx (status, created_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS subscriptions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                package_id INT UNSIGNED NOT NULL,
                status ENUM('active', 'expired', 'cancelled', 'pending') NOT NULL DEFAULT 'pending',
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(3) NOT NULL DEFAULT 'USD',
                billing_period ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
                gateway VARCHAR(50) NOT NULL,
                gateway_reference VARCHAR(191) NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY subscriptions_user_status_idx (user_id, status),
                KEY subscriptions_gateway_reference_idx (gateway_reference),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS payment_webhook_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                gateway VARCHAR(50) NOT NULL,
                event_id VARCHAR(191) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY payment_webhook_gateway_event (gateway, event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::ensureTransactionStatuses($db);
        $ensured = true;
    }

    public static function createPendingTransaction(int $userId, int $packageId, string $gateway, string $ipAddress): array
    {
        self::ensureTablesExist();

        $package = Package::find($packageId);
        if (!$package) {
            throw new \RuntimeException('Invalid package.');
        }

        $amount = self::packageAmount($package);
        if ($amount <= 0) {
            throw new \RuntimeException('This package does not have a purchase price configured.');
        }

        $reference = self::generateReference($gateway);
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO transactions (user_id, package_id, amount, currency, gateway, gateway_reference, status, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([$userId, $packageId, $amount, self::DEFAULT_CURRENCY, $gateway, $reference, $ipAddress]);

        return [
            'id' => (int)$db->lastInsertId(),
            'reference' => $reference,
            'amount' => $amount,
            'currency' => self::DEFAULT_CURRENCY,
            'billing_period' => self::DEFAULT_BILLING_PERIOD,
            'package' => $package,
        ];
    }

    public static function createGatewayCheckoutUrl(string $gateway, array $transaction, array $package): string
    {
        return match ($gateway) {
            'stripe' => self::createStripeCheckoutUrl($transaction, $package),
            'paypal' => self::createPayPalCheckoutUrl($transaction, $package),
            default => throw new \RuntimeException('Unsupported payment gateway.'),
        };
    }

    public static function confirmStripeSuccess(string $sessionId): array
    {
        $secretKey = trim((string)Setting::getEncrypted('payment_stripe_secret_key', ''));
        if ($secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $session = self::httpRequest(
            'GET',
            'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId),
            [],
            [
                'Authorization: Bearer ' . $secretKey,
            ]
        );

        $reference = (string)($session['client_reference_id'] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('Stripe session is missing the internal payment reference.');
        }

        $status = (($session['payment_status'] ?? '') === 'paid') ? 'completed' : 'pending';
        return self::applyGatewayStatus('stripe', $reference, $status);
    }

    public static function capturePayPalOrder(string $orderId, string $reference): array
    {
        $accessToken = self::paypalAccessToken();
        $baseUrl = self::payPalBaseUrl();
        $capture = self::httpRequest(
            'POST',
            $baseUrl . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
            [],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        $status = strtolower((string)($capture['status'] ?? ''));
        $mapped = $status === 'completed' ? 'completed' : ($status === 'payer_action_required' ? 'pending' : 'failed');
        return self::applyGatewayStatus('paypal', $reference, $mapped);
    }

    public static function handleCallback(string $gateway, array $payload, string $signature): array
    {
        self::ensureTablesExist();

        return match ($gateway) {
            'stripe' => self::handleStripeWebhook($payload, $signature),
            'paypal' => self::handlePayPalWebhook($payload),
            default => self::handleSignedInternalCallback($gateway, $payload, $signature),
        };
    }

    public static function callbackSignature(array $payload): string
    {
        ksort($payload);
        $canonical = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        return hash_hmac('sha256', $canonical, self::callbackSecret());
    }

    private static function createStripeCheckoutUrl(array $transaction, array $package): string
    {
        $secretKey = trim((string)Setting::getEncrypted('payment_stripe_secret_key', ''));
        if ($secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $successUrl = SeoService::trustedBaseUrl() . '/payment/stripe/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = SeoService::trustedBaseUrl() . '/payment/cancel?gateway=stripe&reference=' . rawurlencode((string)$transaction['reference']);

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string)$transaction['reference'],
            'payment_method_types[0]' => 'card',
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => self::DEFAULT_CURRENCY,
            'line_items[0][price_data][unit_amount]' => (string)((int)round(((float)$transaction['amount']) * 100)),
            'line_items[0][price_data][product_data][name]' => (string)($package['name'] ?? 'Premium Package'),
            'line_items[0][price_data][product_data][description]' => 'Access upgrade for ' . (string)($package['name'] ?? 'package'),
        ];

        $response = self::httpRequest(
            'POST',
            'https://api.stripe.com/v1/checkout/sessions',
            $payload,
            [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );

        if (empty($response['url'])) {
            throw new \RuntimeException('Stripe did not return a checkout URL.');
        }

        return (string)$response['url'];
    }

    private static function createPayPalCheckoutUrl(array $transaction, array $package): string
    {
        $accessToken = self::paypalAccessToken();
        $baseUrl = self::payPalBaseUrl();

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string)$transaction['reference'],
                'custom_id' => (string)$transaction['reference'],
                'amount' => [
                    'currency_code' => self::DEFAULT_CURRENCY,
                    'value' => number_format((float)$transaction['amount'], 2, '.', ''),
                ],
                'description' => (string)($package['name'] ?? 'Premium Package'),
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => SeoService::trustedBaseUrl() . '/payment/paypal/return?reference=' . rawurlencode((string)$transaction['reference']),
                        'cancel_url' => SeoService::trustedBaseUrl() . '/payment/cancel?gateway=paypal&reference=' . rawurlencode((string)$transaction['reference']),
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ];

        $response = self::httpRequest(
            'POST',
            $baseUrl . '/v2/checkout/orders',
            $payload,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        if (empty($response['links']) || !is_array($response['links'])) {
            throw new \RuntimeException('PayPal did not return an approval URL.');
        }

        foreach ($response['links'] as $link) {
            if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
                return (string)$link['href'];
            }
        }

        throw new \RuntimeException('PayPal approval URL was missing from the order response.');
    }

    private static function handleStripeWebhook(array $payload, string $signature): array
    {
        $secret = trim((string)Setting::getEncrypted('payment_stripe_webhook_secret', ''));
        if ($secret === '') {
            throw new \RuntimeException('Stripe webhook secret is not configured.');
        }

        self::verifyStripeWebhookSignature((string)($payload['_raw_body'] ?? ''), $signature, $secret);
        self::claimWebhookEvent('stripe', trim((string)($payload['id'] ?? '')));

        $eventType = (string)($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? [];
        $reference = (string)($object['client_reference_id'] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('Stripe webhook did not include an internal payment reference.');
        }

        $status = match ($eventType) {
            'checkout.session.completed' => (($object['payment_status'] ?? '') === 'paid') ? 'completed' : 'pending',
            'checkout.session.async_payment_failed',
            'payment_intent.payment_failed' => 'failed',
            default => 'pending',
        };

        return self::applyGatewayStatus('stripe', $reference, $status);
    }

    private static function handlePayPalWebhook(array $payload): array
    {
        self::verifyPayPalWebhook($payload);
        self::claimWebhookEvent('paypal', trim((string)($payload['id'] ?? ($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? ''))));

        $eventType = (string)($payload['event_type'] ?? '');
        $resource = $payload['resource'] ?? [];
        $reference = (string)(
            $resource['custom_id']
            ?? $resource['purchase_units'][0]['custom_id']
            ?? $resource['supplementary_data']['related_ids']['order_id']
            ?? ''
        );

        if ($reference === '') {
            throw new \RuntimeException('PayPal webhook did not include an internal payment reference.');
        }

        $status = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.APPROVED' => 'completed',
            'PAYMENT.CAPTURE.DENIED' => 'denied',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            'PAYMENT.CAPTURE.PENDING' => 'pending',
            default => 'pending',
        };

        return self::applyGatewayStatus('paypal', $reference, $status);
    }

    private static function handleSignedInternalCallback(string $gateway, array $payload, string $signature): array
    {
        $reference = trim((string)($payload['reference'] ?? $payload['gateway_reference'] ?? ''));
        $status = self::normalizeStatus((string)($payload['status'] ?? ''));
        if ($reference === '' || $status === '') {
            throw new \RuntimeException('Missing payment reference or status.');
        }

        if (!self::verifyInternalSignature($payload, $signature)) {
            throw new \RuntimeException('Invalid payment callback signature.');
        }

        return self::applyGatewayStatus($gateway, $reference, $status);
    }

    private static function applyGatewayStatus(string $gateway, string $reference, string $status): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT t.*, u.username, u.email
            FROM transactions t
            JOIN users u ON u.id = t.user_id
            WHERE t.gateway_reference = ? AND t.gateway = ?
            LIMIT 1
        ");
        $stmt->execute([$reference, $gateway]);
        $transaction = $stmt->fetch();
        if (!$transaction) {
            throw new \RuntimeException('Transaction not found.');
        }

        $previousStatus = (string)$transaction['status'];
        if (!self::isAllowedStatusTransition($previousStatus, $status)) {
            return [
                'transaction_id' => (int)$transaction['id'],
                'status' => $previousStatus,
                'message' => 'Ignored stale or invalid payment status transition.',
            ];
        }

        if ($previousStatus === $status) {
            return [
                'transaction_id' => (int)$transaction['id'],
                'status' => $status,
                'message' => 'Callback already applied.',
            ];
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE transactions SET status = ? WHERE id = ?")
                ->execute([$status, (int)$transaction['id']]);

            if ($status === 'completed') {
                self::activateSubscription($db, $transaction, $gateway);
                self::awardAffiliateCommission($db, $transaction, $gateway);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        self::sendPaymentStatusEmail($transaction, $status, $previousStatus, $gateway);

        Logger::info('payment status applied', [
            'transaction_id' => (int)$transaction['id'],
            'gateway' => $gateway,
            'status' => $status,
            'previous_status' => $previousStatus,
        ]);

        return [
            'transaction_id' => (int)$transaction['id'],
            'status' => $status,
            'message' => 'Payment status applied.',
        ];
    }

    private static function activateSubscription($db, array $transaction, string $gateway): void
    {
        $userId = (int)$transaction['user_id'];
        $packageId = (int)$transaction['package_id'];
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $db->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE user_id = ? AND status IN ('active', 'pending')")
            ->execute([$userId]);

        $db->prepare("
            INSERT INTO subscriptions (user_id, package_id, status, amount, currency, billing_period, gateway, gateway_reference, expires_at)
            VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?)
        ")->execute([
            $userId,
            $packageId,
            (float)$transaction['amount'],
            (string)$transaction['currency'],
            self::DEFAULT_BILLING_PERIOD,
            $gateway,
            (string)$transaction['gateway_reference'],
            $expiresAt,
        ]);

        $db->prepare("UPDATE users SET package_id = ?, premium_expiry = ? WHERE id = ?")
            ->execute([$packageId, $expiresAt, $userId]);
    }

    private static function awardAffiliateCommission($db, array $transaction, string $gateway): void
    {
        if (!FeatureService::affiliateEnabled()) {
            return;
        }

        $buyerStmt = $db->prepare("SELECT id, referrer_id FROM users WHERE id = ? LIMIT 1");
        $buyerStmt->execute([(int)$transaction['user_id']]);
        $buyer = $buyerStmt->fetch();
        if (!$buyer || empty($buyer['referrer_id'])) {
            return;
        }

        $referrerId = (int)$buyer['referrer_id'];
        if ($referrerId <= 0 || $referrerId === (int)$buyer['id']) {
            return;
        }

        $referrerStmt = $db->prepare("SELECT id, monetization_model FROM users WHERE id = ? LIMIT 1");
        $referrerStmt->execute([$referrerId]);
        $referrer = $referrerStmt->fetch();
        if (!$referrer) {
            return;
        }

        $model = (string)($referrer['monetization_model'] ?? 'ppd');
        if (!in_array($model, ['pps', 'mixed'], true)) {
            return;
        }

        $basePercent = max(0, min(100, (int)Setting::get('pps_commission_percent', '50')));
        $effectivePercent = $basePercent;
        if ($model === 'mixed') {
            $effectivePercent = (int)round($basePercent * (max(0, min(100, (int)Setting::get('mixed_pps_percent', '30'))) / 100));
        }

        if ($effectivePercent <= 0) {
            return;
        }

        $description = sprintf(
            'Affiliate commission for %s purchase %s',
            strtoupper($gateway),
            (string)$transaction['gateway_reference']
        );

        $exists = $db->prepare("SELECT id FROM earnings WHERE user_id = ? AND type = 'referral' AND description = ? LIMIT 1");
        $exists->execute([$referrerId, $description]);
        if ($exists->fetchColumn()) {
            return;
        }

        $commission = round(((float)$transaction['amount']) * ($effectivePercent / 100), 4);
        if ($commission <= 0) {
            return;
        }

        $db->prepare("
            INSERT INTO earnings (user_id, amount, type, status, description)
            VALUES (?, ?, 'referral', 'cleared', ?)
        ")->execute([
            $referrerId,
            $commission,
            $description,
        ]);
    }

    private static function sendPaymentStatusEmail(array $transaction, string $status, string $previousStatus, string $gateway): void
    {
        if ($status === $previousStatus && $status === 'completed') {
            return;
        }

        $user = User::find((int)$transaction['user_id']);
        if (!$user || empty($user['email'])) {
            return;
        }

        $package = Package::find((int)$transaction['package_id']);
        $templateMap = [
            'pending' => 'payment_pending',
            'on_hold' => 'payment_on_hold',
            'completed' => 'payment_completed',
            'failed' => 'payment_failed',
            'denied' => 'payment_denied',
            'refunded' => 'payment_refunded',
        ];

        if (!isset($templateMap[$status])) {
            return;
        }

        MailService::sendTemplate((string)$user['email'], $templateMap[$status], [
            '{username}' => (string)($user['username'] ?? 'User'),
            '{package_name}' => (string)($package['name'] ?? ('Package #' . (int)$transaction['package_id'])),
            '{amount}' => '$' . number_format((float)$transaction['amount'], 2),
            '{gateway}' => strtoupper($gateway),
        ], 'high');
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $aliases = [
            'success' => 'completed',
            'completed' => 'completed',
            'paid' => 'completed',
            'pending' => 'pending',
            'processing' => 'pending',
            'hold' => 'on_hold',
            'on_hold' => 'on_hold',
            'failed' => 'failed',
            'error' => 'failed',
            'denied' => 'denied',
            'declined' => 'denied',
            'refunded' => 'refunded',
        ];

        return $aliases[$status] ?? '';
    }

    private static function packageAmount(array $package): float
    {
        return isset($package['price']) ? (float)$package['price'] : self::DEFAULT_PRICE;
    }

    private static function generateReference(string $gateway): string
    {
        return strtolower($gateway) . '_' . bin2hex(random_bytes(12));
    }

    private static function verifyInternalSignature(array $payload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals(self::callbackSignature($payload), $signature);
    }

    private static function callbackSecret(): string
    {
        return (string)Setting::get('payment_callback_secret', Config::get('app_key', ''));
    }

    private static function claimWebhookEvent(string $gateway, string $eventId): void
    {
        if ($eventId === '') {
            throw new \RuntimeException('Webhook event ID is missing.');
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT IGNORE INTO payment_webhook_events (gateway, event_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$gateway, $eventId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Webhook event already processed.');
        }
    }

    private static function isAllowedStatusTransition(string $previousStatus, string $newStatus): bool
    {
        if ($previousStatus === $newStatus) {
            return true;
        }

        $terminalStatuses = ['completed', 'refunded', 'denied'];
        if (in_array($previousStatus, $terminalStatuses, true)) {
            return false;
        }

        return true;
    }

    private static function verifyStripeWebhookSignature(string $rawBody, string $header, string $secret): void
    {
        if ($rawBody === '' || $header === '') {
            throw new \RuntimeException('Stripe webhook signature data is missing.');
        }

        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', $segment, 2), 2, '');
            if ($key !== '' && $value !== '') {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if ($timestamp === '' || $signature === '') {
            throw new \RuntimeException('Stripe webhook signature header is malformed.');
        }

        if (!ctype_digit($timestamp)) {
            throw new \RuntimeException('Stripe webhook timestamp is malformed.');
        }

        if (abs(time() - (int)$timestamp) > self::STRIPE_WEBHOOK_TOLERANCE) {
            throw new \RuntimeException('Stripe webhook timestamp is outside the allowed window.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Stripe webhook signature verification failed.');
        }
    }

    private static function paypalAccessToken(): string
    {
        $clientId = trim((string)Setting::get('payment_paypal_client_id', ''));
        $clientSecret = trim((string)Setting::getEncrypted('payment_paypal_client_secret', ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('PayPal credentials are not configured.');
        }

        $response = self::httpRequest(
            'POST',
            self::payPalBaseUrl() . '/v1/oauth2/token',
            ['grant_type' => 'client_credentials'],
            [
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Unable to obtain a PayPal access token.');
        }

        return (string)$response['access_token'];
    }

    private static function payPalBaseUrl(): string
    {
        return Setting::get('payment_paypal_sandbox', '1') === '1'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private static function verifyPayPalWebhook(array $payload): void
    {
        $webhookId = trim((string)Setting::get('payment_paypal_webhook_id', ''));
        if ($webhookId === '') {
            throw new \RuntimeException('PayPal webhook ID is not configured.');
        }

        $headers = [
            'PAYPAL-TRANSMISSION-ID' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '',
            'PAYPAL-TRANSMISSION-TIME' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
            'PAYPAL-TRANSMISSION-SIG' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '',
            'PAYPAL-CERT-URL' => $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '',
            'PAYPAL-AUTH-ALGO' => $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '',
        ];

        foreach ($headers as $headerValue) {
            if ($headerValue === '') {
                throw new \RuntimeException('PayPal webhook headers are incomplete.');
            }
        }

        $accessToken = self::paypalAccessToken();
        $verification = self::httpRequest(
            'POST',
            self::payPalBaseUrl() . '/v1/notifications/verify-webhook-signature',
            [
                'auth_algo' => $headers['PAYPAL-AUTH-ALGO'],
                'cert_url' => $headers['PAYPAL-CERT-URL'],
                'transmission_id' => $headers['PAYPAL-TRANSMISSION-ID'],
                'transmission_sig' => $headers['PAYPAL-TRANSMISSION-SIG'],
                'transmission_time' => $headers['PAYPAL-TRANSMISSION-TIME'],
                'webhook_id' => $webhookId,
                'webhook_event' => $payload,
            ],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        if (($verification['verification_status'] ?? '') !== 'SUCCESS') {
            throw new \RuntimeException('PayPal webhook signature verification failed.');
        }
    }

    private static function httpRequest(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $ch = curl_init($url);
        $method = strtoupper($method);

        $hasJson = false;
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type: application/json') === 0) {
                $hasJson = true;
                break;
            }
        }

        $body = null;
        if ($method !== 'GET') {
            if ($hasJson) {
                $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            } else {
                $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Payment gateway request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawBody = substr($response, $headerSize);
        curl_close($ch);

        $decoded = json_decode($rawBody, true);
        if ($status >= 400) {
            $message = is_array($decoded)
                ? ($decoded['error_description'] ?? $decoded['message'] ?? $decoded['error'] ?? 'Gateway request failed.')
                : 'Gateway request failed.';
            throw new \RuntimeException($message);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function ensureTransactionStatuses($db): void
    {
        try {
            $db->exec("
                ALTER TABLE transactions
                MODIFY COLUMN status ENUM('pending', 'completed', 'failed', 'refunded', 'on_hold', 'denied')
                NOT NULL DEFAULT 'pending'
            ");
        } catch (\Throwable $e) {
            // Table may not exist yet or may already match. Safe to ignore here.
        }
    }
}
