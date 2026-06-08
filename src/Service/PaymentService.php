<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Model\Package;
use App\Model\PackageBillingOption;
use App\Model\Setting;
use App\Model\User;
use App\Service\Database\SchemaService;
use App\Service\EncryptionService;
use App\Service\MonetizationModelService;
use App\Service\CouponService;

class PaymentService
{
    public const DEFAULT_PRICE = 9.99;
    public const DEFAULT_CURRENCY = 'USD';
    public const DEFAULT_BILLING_PERIOD = 'monthly';
    private const DEFAULT_AFFILIATE_HOLD_DAYS = 5;
    private const STRIPE_WEBHOOK_TOLERANCE = 300;
    private const STALE_PENDING_PAYMENT_MINUTES = 1440;
    private const GATEWAY_SYNC_STALE_MINUTES = 15;
    private static $httpRequestHandler = null;

    private static function assertDecryptedSecret(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \RuntimeException($label . ' is not configured.');
        }
        if (str_starts_with($value, 'ENC:')) {
            throw new \RuntimeException($label . ' could not be decrypted. Re-save it in Config Hub.');
        }
        return $value;
    }

    public static function ensureTablesExist(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        SchemaService::ensureTables(['coupons', 'coupon_redemptions', 'transactions', 'subscriptions', 'payment_webhook_events'], false);
        SchemaService::ensureTables(['payment_gateway_sync_jobs'], false);
        $ensured = true;
    }

    public static function packageTermDays(array $package): int
    {
        return Package::subscriptionTermDays($package);
    }

    public static function packageRenewalEnabled(array $package): bool
    {
        return Package::renewalEnabled($package);
    }

    public static function checkoutBillingOptions(array $package): array
    {
        $options = $package['billing_options'] ?? null;
        if (!is_array($options)) {
            $options = PackageBillingOption::forPackage((int)($package['id'] ?? 0), true);
        } else {
            $options = array_values(array_filter($options, static fn(array $row): bool => !isset($row['is_active']) || !empty($row['is_active'])));
        }

        if ($options === []) {
            $options[] = [
                'id' => null,
                'option_label' => null,
                'price' => (float)($package['price'] ?? self::DEFAULT_PRICE),
                'term_days' => self::packageTermDays($package),
                'renewal_enabled' => self::packageRenewalEnabled($package) ? 1 : 0,
                'is_active' => 1,
                'display_order' => 0,
            ];
        }

        return array_map(static function (array $option): array {
            $termDays = max(1, (int)($option['term_days'] ?? 30));
            $label = trim((string)($option['option_label'] ?? ''));
            return [
                'id' => isset($option['id']) ? (int)$option['id'] : null,
                'option_label' => $label !== '' ? $label : self::formatTermLabel($termDays),
                'price' => round((float)($option['price'] ?? self::DEFAULT_PRICE), 2),
                'term_days' => $termDays,
                'renewal_enabled' => !empty($option['renewal_enabled']) ? 1 : 0,
                'is_active' => !isset($option['is_active']) || !empty($option['is_active']) ? 1 : 0,
                'display_order' => (int)($option['display_order'] ?? 0),
            ];
        }, $options);
    }

    public static function resolveCheckoutBillingOption(array $package, ?int $billingOptionId = null): array
    {
        $options = self::checkoutBillingOptions($package);
        if ($billingOptionId !== null && $billingOptionId > 0) {
            foreach ($options as $option) {
                if ((int)($option['id'] ?? 0) === $billingOptionId) {
                    return $option;
                }
            }
        }
        return $options[0];
    }

    public static function formatTermLabel(int $days): string
    {
        $days = max(1, $days);
        if ($days % 365 === 0) {
            $years = (int)($days / 365);
            return $years === 1 ? '1 year' : ($years . ' years');
        }
        if ($days % 30 === 0) {
            $months = (int)($days / 30);
            return $months === 1 ? '1 month' : ($months . ' months');
        }
        if ($days % 7 === 0) {
            $weeks = (int)($days / 7);
            return $weeks === 1 ? '1 week' : ($weeks . ' weeks');
        }
        return $days === 1 ? '1 day' : ($days . ' days');
    }

    public static function formatRenewalSummary(int $days): string
    {
        return 'Every ' . self::formatTermLabel($days);
    }

    public static function recurringTermValidationError(int $days): ?string
    {
        $days = max(1, $days);

        if ($days % 365 === 0) {
            $years = (int)($days / 365);
            if ($years <= 1) {
                return null;
            }
            return 'Auto-renew terms can be at most 1 year when you want them to work cleanly across Stripe and PayPal.';
        }

        if ($days % 30 === 0) {
            $months = (int)($days / 30);
            if ($months >= 1 && $months <= 12) {
                return null;
            }
            return 'Auto-renew month-based terms must stay between 1 and 12 months.';
        }

        if ($days % 7 === 0) {
            $weeks = (int)($days / 7);
            if ($weeks >= 1 && $weeks <= 52) {
                return null;
            }
            return 'Auto-renew week-based terms must stay between 1 and 52 weeks.';
        }

        if ($days >= 1 && $days <= 365) {
            return null;
        }

        return 'Auto-renew day-based terms must stay between 1 and 365 days.';
    }

    private static function billingPeriodForDays(int $days): string
    {
        return $days >= 365 ? 'yearly' : 'monthly';
    }

    private static function stripeRecurringSpecForDays(int $days): array
    {
        $days = max(1, $days);
        if ($days % 365 === 0) {
            return ['interval' => 'year', 'interval_count' => max(1, (int)($days / 365))];
        }
        if ($days % 30 === 0) {
            return ['interval' => 'month', 'interval_count' => max(1, (int)($days / 30))];
        }
        if ($days % 7 === 0) {
            return ['interval' => 'week', 'interval_count' => max(1, (int)($days / 7))];
        }
        return ['interval' => 'day', 'interval_count' => $days];
    }

    private static function paypalRecurringSpecForDays(int $days): array
    {
        $days = max(1, $days);
        if ($days % 365 === 0) {
            return ['unit' => 'YEAR', 'count' => max(1, (int)($days / 365))];
        }
        if ($days % 30 === 0) {
            return ['unit' => 'MONTH', 'count' => max(1, (int)($days / 30))];
        }
        if ($days % 7 === 0) {
            return ['unit' => 'WEEK', 'count' => max(1, (int)($days / 7))];
        }
        return ['unit' => 'DAY', 'count' => $days];
    }

    private static function cycleDurationMonths(int $days, int $cycles): ?int
    {
        $days = max(1, $days);
        $cycles = max(1, $cycles);
        if ($days % 365 === 0) {
            return (int)(($days / 365) * 12 * $cycles);
        }
        if ($days % 30 === 0) {
            return (int)(($days / 30) * $cycles);
        }
        return null;
    }

    public static function assertCheckoutIntentAllowed(
        int $userId,
        array $package,
        array $billingOption,
        string $gateway,
        bool $autoRenew,
        ?array $coupon = null,
        float $discountAmount = 0.0,
        ?float $finalAmount = null
    ): void {
        if (!$autoRenew) {
            return;
        }

        $gateway = strtolower(trim($gateway));
        $termDays = max(1, (int)($billingOption['term_days'] ?? self::packageTermDays($package)));
        $renewalEnabled = !empty($billingOption['renewal_enabled']);
        if (!$renewalEnabled) {
            throw new \RuntimeException('This package is configured as a one-time purchase and cannot auto-renew.');
        }

        $termError = self::recurringTermValidationError($termDays);
        if ($termError !== null) {
            throw new \RuntimeException($termError);
        }

        if (!in_array($gateway, ['stripe', 'paypal'], true)) {
            throw new \RuntimeException('Auto-renew checkout needs a supported recurring payment gateway.');
        }

        if (self::hasFutureSamePackageSubscription($userId, (int)($package['id'] ?? 0))) {
            throw new \RuntimeException('This package already has time remaining on your account. To avoid losing paid time, wait until the current term ends before starting auto-renew, or use a one-time checkout to extend the package.');
        }

        if ($finalAmount !== null && $finalAmount <= 0.0) {
            throw new \RuntimeException('Auto-renew needs a paid first charge. Adjust the coupon or use a one-time checkout for this order.');
        }

        if ($gateway === 'paypal' && $discountAmount > 0.0) {
            throw new \RuntimeException('Recurring PayPal checkouts do not currently support coupon discounts safely. Use Stripe for recurring discounts, or turn off auto-renew and use PayPal for a one-time discounted term.');
        }

        if ($gateway === 'stripe' && $discountAmount > 0.0 && is_array($coupon)) {
            self::assertStripeRecurringCouponCompatible($coupon, $termDays, $discountAmount);
        }
    }

    public static function createPendingTransaction(int $userId, int $packageId, string $gateway, string $ipAddress, string $couponCode = '', bool $autoRenew = false, ?int $billingOptionId = null): array
    {
        self::ensureTablesExist();

        $package = Package::find($packageId);
        if (!$package) {
            throw new \RuntimeException('Invalid package.');
        }

        $billingOption = self::resolveCheckoutBillingOption($package, $billingOptionId);
        $amount = self::packageAmount($package, $billingOption);
        if ($amount <= 0) {
            throw new \RuntimeException('This package does not have a purchase price configured.');
        }

        $termDays = (int)$billingOption['term_days'];

        $reference = self::generateReference($gateway);
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        try {
            self::lockCheckoutIntentScope($db, $userId);
            self::assertCheckoutIntentAllowed(
                $userId,
                $package,
                $billingOption,
                $gateway,
                $autoRenew
            );
            self::assertNoPendingRecurringCheckout($db, $userId, $packageId, $autoRenew);

            $stmt = $db->prepare("
                INSERT INTO transactions
                    (user_id, package_id, package_billing_option_id, coupon_id, coupon_code, original_amount, discount_amount, amount, currency, term_days, auto_renew, gateway, gateway_reference, status, ip_address)
                VALUES (?, ?, ?, NULL, NULL, ?, 0.00, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->execute([
                $userId,
                $packageId,
                $billingOption['id'],
                $amount,
                $amount,
                self::DEFAULT_CURRENCY,
                $termDays,
                $autoRenew ? 1 : 0,
                $gateway,
                $reference,
                EncryptionService::encrypt($ipAddress),
            ]);

            $transactionId = (int)$db->lastInsertId();
            $couponApplication = CouponService::applyToPendingTransaction($db, $transactionId, $userId, $package, $billingOption, $amount, $couponCode);
            $storedGateway = $gateway;
            $storedReference = $reference;
            $coupon = self::loadCouponMetadataForCheckout($db, $couponApplication['coupon_id'] ?? null);
            self::assertCheckoutIntentAllowed(
                $userId,
                $package,
                $billingOption,
                $gateway,
                $autoRenew,
                $coupon,
                (float)($couponApplication['discount_amount'] ?? 0),
                isset($couponApplication['final_amount']) ? (float)$couponApplication['final_amount'] : null
            );
            if ((float)$couponApplication['final_amount'] <= 0.0) {
                $storedGateway = 'coupon';
                $storedReference = self::generateReference('coupon');
            }

            $db->prepare("
                UPDATE transactions
                SET coupon_id = ?, coupon_code = ?, original_amount = ?, discount_amount = ?, amount = ?, gateway = ?, gateway_reference = ?
                WHERE id = ?
            ")->execute([
                $couponApplication['coupon_id'],
                $couponApplication['coupon_code'],
                $couponApplication['original_amount'],
                $couponApplication['discount_amount'],
                $couponApplication['final_amount'],
                $storedGateway,
                $storedReference,
                $transactionId,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'id' => $transactionId,
            'reference' => $storedReference,
            'amount' => $couponApplication['final_amount'],
            'original_amount' => $couponApplication['original_amount'],
            'discount_amount' => $couponApplication['discount_amount'],
            'coupon_code' => $couponApplication['coupon_code'],
            'currency' => self::DEFAULT_CURRENCY,
            'billing_period' => self::billingPeriodForDays($termDays),
            'term_days' => $termDays,
            'auto_renew' => $autoRenew,
            'package' => $package,
            'gateway' => $storedGateway,
            'billing_option' => $billingOption,
            'package_billing_option_id' => $billingOption['id'],
        ];
    }

    public static function completeZeroAmountTransaction(int $transactionId, int $expectedUserId): array
    {
        self::ensureTablesExist();

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT gateway, gateway_reference, user_id, amount, status
            FROM transactions
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch();
        if (!$transaction || (int)$transaction['user_id'] !== $expectedUserId) {
            throw new \RuntimeException('Transaction not found.');
        }
        if ((string)$transaction['status'] !== 'pending') {
            throw new \RuntimeException('This checkout is no longer pending.');
        }
        if ((float)$transaction['amount'] > 0) {
            throw new \RuntimeException('Only zero-dollar premium checkouts can be completed internally.');
        }

        return self::applyGatewayStatus((string)$transaction['gateway'], (string)$transaction['gateway_reference'], 'completed');
    }

    public static function expireStalePendingTransactions(int $olderThanMinutes = self::STALE_PENDING_PAYMENT_MINUTES): array
    {
        self::ensureTablesExist();

        $olderThanMinutes = max(60, (int)$olderThanMinutes);
        $db = Database::getInstance()->getConnection();
        $cleanupInstructions = [];

        $db->beginTransaction();
        try {
            $select = $db->prepare("
                SELECT *
                FROM transactions
                WHERE status = 'pending'
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                ORDER BY id ASC
                FOR UPDATE
            ");
            $select->execute([$olderThanMinutes]);
            $transactions = $select->fetchAll() ?: [];

            $update = $db->prepare("
                UPDATE transactions
                SET status = 'failed'
                WHERE id = ? AND status = 'pending'
            ");

            $expired = 0;
            foreach ($transactions as $transaction) {
                $update->execute([(int)$transaction['id']]);
                if ($update->rowCount() !== 1) {
                    continue;
                }

                CouponService::finalizeTransactionCoupon($db, (int)$transaction['id'], 'failed');
                $cleanup = self::pendingTransactionCleanupInstruction($transaction);
                if ($cleanup !== null) {
                    $cleanupInstructions[] = $cleanup;
                }
                $expired++;
            }
            CouponService::releaseExpiredPendingReservations($db, $olderThanMinutes);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::cleanupPendingGatewayResources($cleanupInstructions);

        if ($expired > 0) {
            Logger::info('stale pending payment transactions expired', [
                'expired' => $expired,
                'older_than_minutes' => $olderThanMinutes,
            ]);
        }

        return [
            'expired' => $expired,
            'older_than_minutes' => $olderThanMinutes,
        ];
    }

    public static function expireStaleActiveSubscriptions(\PDO $db, ?int $userId = null): int
    {
        $sql = "
            UPDATE subscriptions
            SET status = 'expired',
                updated_at = NOW()
            WHERE status = 'active'
              AND expires_at <= NOW()
        ";
        $params = [];

        if ($userId !== null && $userId > 0) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function countLiveOrPendingSubscriptions(\PDO $db, int $userId, ?int $excludeSubscriptionId = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        self::expireStaleActiveSubscriptions($db, $userId);

        $sql = "
            SELECT COUNT(*)
            FROM subscriptions
            WHERE user_id = ?
              AND " . self::liveOrPendingSubscriptionCondition() . "
        ";
        $params = [$userId];

        if ($excludeSubscriptionId !== null && $excludeSubscriptionId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $excludeSubscriptionId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    public static function createGatewayCheckoutUrl(string $gateway, array $transaction, array $package): string
    {
        return match ($gateway) {
            'stripe' => self::createStripeCheckoutUrl($transaction, $package),
            'paypal' => self::createPayPalCheckoutUrl($transaction, $package),
            default => throw new \RuntimeException('Unsupported payment gateway.'),
        };
    }

    public static function failPendingTransactionByReference(string $gateway, string $reference, int $userId, string $status = 'failed'): bool
    {
        self::ensureTablesExist();
        $status = in_array($status, ['failed', 'denied'], true) ? $status : 'failed';
        $gateway = trim($gateway);
        $reference = trim($reference);
        if ($userId <= 0 || $gateway === '' || $reference === '') {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $cleanupInstruction = null;
        $resolved = true;
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT *
                FROM transactions
                WHERE gateway = ? AND gateway_reference = ? AND user_id = ? AND status = 'pending'
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$gateway, $reference, $userId]);
            $transaction = $stmt->fetch();
            if ($transaction) {
                $update = $db->prepare("UPDATE transactions SET status = ? WHERE id = ? AND status = 'pending'");
                $update->execute([$status, (int)$transaction['id']]);
                if ($update->rowCount() === 1) {
                    CouponService::finalizeTransactionCoupon($db, (int)$transaction['id'], $status);
                    $cleanupInstruction = self::pendingTransactionCleanupInstruction($transaction);
                }
            } else {
                $resolved = true;
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::cleanupPendingGatewayResources($cleanupInstruction !== null ? [$cleanupInstruction] : []);
        return $resolved;
    }

    public static function setHttpRequestHandlerForTests(?callable $handler): void
    {
        self::$httpRequestHandler = $handler;
    }

    private static function gatewaySyncTimestamp(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    private static function gatewaySyncDedupeKey(string $gateway, string $action, string $providerSubscriptionId): string
    {
        return strtolower(trim($gateway)) . ':' . strtolower(trim($action)) . ':' . trim($providerSubscriptionId);
    }

    private static function queueGatewaySyncJob(\PDO $db, string $gateway, string $action, string $providerSubscriptionId, ?int $subscriptionId = null, array $payload = []): int
    {
        self::ensureTablesExist();

        $gateway = strtolower(trim($gateway));
        $action = strtolower(trim($action));
        $providerSubscriptionId = trim($providerSubscriptionId);
        if ($gateway === '' || $action === '' || $providerSubscriptionId === '') {
            throw new \RuntimeException('Gateway sync job is missing required identifiers.');
        }

        $dedupeKey = self::gatewaySyncDedupeKey($gateway, $action, $providerSubscriptionId);
        $payloadJson = $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payload !== [] && !is_string($payloadJson)) {
            throw new \RuntimeException('Gateway sync payload could not be encoded.');
        }

        $now = self::gatewaySyncTimestamp();
        try {
            $stmt = $db->prepare("
                INSERT INTO payment_gateway_sync_jobs
                    (dedupe_key, gateway, action, provider_subscription_id, subscription_id, payload_json, status, attempt_count, last_error, available_at, processed_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, NULL, ?, NULL, ?, ?)
            ");
            $stmt->execute([$dedupeKey, $gateway, $action, $providerSubscriptionId, $subscriptionId, $payloadJson, $now, $now, $now]);
        } catch (\PDOException $e) {
            $stmt = $db->prepare("
                UPDATE payment_gateway_sync_jobs
                SET gateway = ?,
                    action = ?,
                    provider_subscription_id = ?,
                    subscription_id = ?,
                    payload_json = ?,
                    status = 'pending',
                    attempt_count = 0,
                    last_error = NULL,
                    available_at = ?,
                    processed_at = NULL
                WHERE dedupe_key = ?
            ");
            $stmt->execute([$gateway, $action, $providerSubscriptionId, $subscriptionId, $payloadJson, $now, $dedupeKey]);
        }

        $lookup = $db->prepare("SELECT id FROM payment_gateway_sync_jobs WHERE dedupe_key = ? LIMIT 1");
        $lookup->execute([$dedupeKey]);
        $jobId = (int)$lookup->fetchColumn();
        if ($jobId <= 0) {
            throw new \RuntimeException('Gateway sync job could not be queued.');
        }

        return $jobId;
    }

    private static function processGatewaySyncJob(array $job): array
    {
        $gateway = strtolower(trim((string)($job['gateway'] ?? '')));
        $action = strtolower(trim((string)($job['action'] ?? '')));
        $providerSubscriptionId = trim((string)($job['provider_subscription_id'] ?? ''));
        $subscriptionId = !empty($job['subscription_id']) ? (int)$job['subscription_id'] : null;
        $payload = [];
        if (!empty($job['payload_json'])) {
            $decoded = json_decode((string)$job['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if ($gateway === '' || $action === '' || $providerSubscriptionId === '') {
            throw new \RuntimeException('Gateway sync job is incomplete.');
        }

        if ($action === 'cancel_subscription') {
            if ($gateway === 'stripe') {
                self::cancelStripeSubscription($providerSubscriptionId);
            } elseif ($gateway === 'paypal') {
                self::cancelPayPalSubscription($providerSubscriptionId);
            } else {
                throw new \RuntimeException('Unsupported cancellation gateway: ' . $gateway);
            }

            return ['subscription_id' => $subscriptionId];
        }

        if ($action === 'stripe_auto_renew') {
            if ($gateway !== 'stripe') {
                throw new \RuntimeException('Stripe auto-renew sync requires the stripe gateway.');
            }

            $enabled = !empty($payload['enabled']);
            $secretKey = self::assertDecryptedSecret((string)Setting::getEncrypted('payment_stripe_secret_key', ''), 'Stripe secret key');
            $stripeSubscription = self::httpRequest(
                'POST',
                'https://api.stripe.com/v1/subscriptions/' . rawurlencode($providerSubscriptionId),
                [
                    'cancel_at_period_end' => $enabled ? 'false' : 'true',
                ],
                [
                    'Authorization: Bearer ' . $secretKey,
                    'Content-Type: application/x-www-form-urlencoded',
                ]
            );

            $expiresAt = null;
            if (isset($stripeSubscription['current_period_end'])) {
                $expiresAt = date('Y-m-d H:i:s', (int)$stripeSubscription['current_period_end']);
            }

            return [
                'subscription_id' => $subscriptionId,
                'expires_at' => $expiresAt,
            ];
        }

        throw new \RuntimeException('Unsupported gateway sync action: ' . $action);
    }

    private static function applyGatewaySyncLocalResult(\PDO $db, array $job, array $result): void
    {
        $subscriptionId = !empty($result['subscription_id'])
            ? (int)$result['subscription_id']
            : (!empty($job['subscription_id']) ? (int)$job['subscription_id'] : 0);
        if ($subscriptionId <= 0) {
            return;
        }

        $action = strtolower(trim((string)($job['action'] ?? '')));
        $payload = [];
        if (!empty($job['payload_json'])) {
            $decoded = json_decode((string)$job['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $updates = ['updated_at = NOW()'];
        $params = [];

        if ($action === 'stripe_auto_renew') {
            $updates[] = 'auto_renew = ?';
            $params[] = !empty($payload['enabled']) ? 1 : 0;
        } elseif ($action === 'cancel_subscription') {
            $updates[] = 'auto_renew = 0';
        }

        $expiresAt = isset($result['expires_at']) && is_string($result['expires_at']) ? trim($result['expires_at']) : '';
        if ($expiresAt !== '') {
            $updates[] = 'expires_at = ?';
            $params[] = $expiresAt;
        }

        $params[] = $subscriptionId;
        $db->prepare("
            UPDATE subscriptions
            SET " . implode(', ', $updates) . "
            WHERE id = ?
        ")->execute($params);
    }

    private static function markGatewaySyncJobComplete(\PDO $db, array $job, array $result): void
    {
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0) {
            throw new \RuntimeException('Gateway sync completion is missing the job id.');
        }

        self::applyGatewaySyncLocalResult($db, $job, $result);

        $now = self::gatewaySyncTimestamp();
        $db->prepare("
            UPDATE payment_gateway_sync_jobs
            SET status = 'completed',
                processed_at = ?,
                available_at = NULL,
                last_error = NULL
            WHERE id = ?
        ")->execute([$now, $jobId]);

    }

    private static function markGatewaySyncJobFailed(\PDO $db, int $jobId, \Throwable $error): void
    {
        $attemptStmt = $db->prepare("SELECT attempt_count FROM payment_gateway_sync_jobs WHERE id = ? LIMIT 1");
        $attemptStmt->execute([$jobId]);
        $attemptCount = max(0, (int)$attemptStmt->fetchColumn()) + 1;
        $backoffSeconds = min(3600, max(60, $attemptCount * 60));
        $db->prepare("
            UPDATE payment_gateway_sync_jobs
            SET status = 'failed',
                attempt_count = ?,
                last_error = ?,
                available_at = ?,
                processed_at = NULL
            WHERE id = ?
        ")->execute([
            $attemptCount,
            substr($error->getMessage(), 0, 65535),
            self::gatewaySyncTimestamp($backoffSeconds),
            $jobId,
        ]);
    }

    private static function processGatewaySyncJobsStrict(array $jobIds): array
    {
        self::ensureTablesExist();
        $db = Database::getInstance()->getConnection();
        $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn (int $id): bool => $id > 0)));
        if ($jobIds === []) {
            return ['processed' => 0, 'completed' => 0, 'failed' => 0, 'failed_job_ids' => []];
        }

        $jobs = self::claimGatewaySyncJobs($db, count($jobIds), $jobIds);
        $results = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'failed_job_ids' => []];

        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $results['processed']++;
            try {
                $result = self::processGatewaySyncJob($job);
                self::markGatewaySyncJobComplete($db, $job, $result);
                $results['completed']++;
            } catch (\Throwable $e) {
                self::markGatewaySyncJobFailed($db, $jobId, $e);
                $results['failed']++;
                $results['failed_job_ids'][] = $jobId;
                throw new \RuntimeException(
                    'Gateway sync failed for ' . strtoupper((string)($job['gateway'] ?? 'gateway')) . ' '
                    . str_replace('_', ' ', (string)($job['action'] ?? 'operation')) . ': '
                    . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $results;
    }

    private static function claimGatewaySyncJobs(\PDO $db, int $limit, ?array $jobIds = null): array
    {
        self::ensureTablesExist();
        $limit = max(1, $limit);
        $jobIds = $jobIds !== null ? array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn (int $id): bool => $id > 0))) : null;
        $now = self::gatewaySyncTimestamp();
        $staleBefore = self::gatewaySyncTimestamp(-(self::GATEWAY_SYNC_STALE_MINUTES * 60));

        $params = [];
        $claimUpdateSql = '';
        $claimUpdateParams = [];
        if ($jobIds !== null && $jobIds !== []) {
            $sql = "
                SELECT *
                FROM payment_gateway_sync_jobs
                WHERE (
                    status IN ('pending', 'failed')
                    OR (
                        status = 'processing'
                        AND updated_at <= ?
                    )
                )
                  AND id IN (" . implode(',', array_fill(0, count($jobIds), '?')) . ")
                ORDER BY id ASC
            ";
            $params[] = $staleBefore;
            array_push($params, ...$jobIds);
            $claimUpdateSql = "
                UPDATE payment_gateway_sync_jobs
                SET status = 'processing',
                    processed_at = NULL,
                    available_at = NULL,
                    updated_at = NOW()
                WHERE id = ?
                  AND (
                        status IN ('pending', 'failed')
                        OR (
                            status = 'processing'
                            AND updated_at <= ?
                        )
                  )
            ";
        }
        else {
            $sql = "
                SELECT *
                FROM payment_gateway_sync_jobs
                WHERE (
                    (
                        status IN ('pending', 'failed')
                        AND (available_at IS NULL OR available_at <= ?)
                    )
                    OR (
                        status = 'processing'
                        AND updated_at <= ?
                    )
                )
                ORDER BY id ASC
                LIMIT " . $limit;
            $params[] = $now;
            $params[] = $staleBefore;
            $claimUpdateSql = "
                UPDATE payment_gateway_sync_jobs
                SET status = 'processing',
                    processed_at = NULL,
                    available_at = NULL,
                    updated_at = NOW()
                WHERE id = ?
                  AND (
                        (
                            status IN ('pending', 'failed')
                            AND (available_at IS NULL OR available_at <= ?)
                        )
                        OR (
                            status = 'processing'
                            AND updated_at <= ?
                        )
                  )
            ";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll();
        $claimed = [];
        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $update = $db->prepare($claimUpdateSql);
            $updateParams = $jobIds !== null && $jobIds !== []
                ? [$jobId, $staleBefore]
                : [$jobId, $now, $staleBefore];
            $update->execute($updateParams);
            if ($update->rowCount() !== 1) {
                continue;
            }

            $job['status'] = 'processing';
            $claimed[] = $job;
            if (count($claimed) >= $limit) {
                break;
            }
        }

        return $claimed;
    }

    public static function processGatewaySyncQueue(int $limit = 25, ?array $jobIds = null): array
    {
        self::ensureTablesExist();
        $db = Database::getInstance()->getConnection();
        $claimed = self::claimGatewaySyncJobs($db, $limit, $jobIds);
        $results = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'failed_job_ids' => []];

        foreach ($claimed as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $results['processed']++;
            try {
                $result = self::processGatewaySyncJob($job);
                self::markGatewaySyncJobComplete($db, $job, $result);
                $results['completed']++;
            } catch (\Throwable $e) {
                self::markGatewaySyncJobFailed($db, $jobId, $e);
                $results['failed']++;
                $results['failed_job_ids'][] = $jobId;
                Logger::warning('Payment gateway sync job failed', [
                    'job_id' => $jobId,
                    'gateway' => $job['gateway'] ?? null,
                    'action' => $job['action'] ?? null,
                    'provider_subscription_id' => $job['provider_subscription_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public static function unresolvedGatewaySyncStates(array $subscriptionIds): array
    {
        self::ensureTablesExist();
        $subscriptionIds = array_values(array_unique(array_filter(array_map('intval', $subscriptionIds), static fn (int $id): bool => $id > 0)));
        if ($subscriptionIds === []) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $placeholders = implode(',', array_fill(0, count($subscriptionIds), '?'));
        $stmt = $db->prepare("
            SELECT id, subscription_id, gateway, action, status, last_error, available_at, updated_at
            FROM payment_gateway_sync_jobs
            WHERE subscription_id IN ($placeholders)
              AND status IN ('pending', 'processing', 'failed')
            ORDER BY subscription_id ASC, id DESC
        ");
        $stmt->execute($subscriptionIds);
        $states = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $subscriptionId = (int)($row['subscription_id'] ?? 0);
            if ($subscriptionId <= 0 || isset($states[$subscriptionId])) {
                continue;
            }

            $status = strtolower(trim((string)($row['status'] ?? 'pending')));
            $states[$subscriptionId] = [
                'job_id' => (int)($row['id'] ?? 0),
                'gateway' => strtolower(trim((string)($row['gateway'] ?? ''))),
                'action' => strtolower(trim((string)($row['action'] ?? ''))),
                'status' => $status,
                'last_error' => (string)($row['last_error'] ?? ''),
                'available_at' => (string)($row['available_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }

        return $states;
    }

    private static function clearPendingGatewayCancellationSync(\PDO $db, int $subscriptionId, string $gateway, string $providerSubscriptionId): void
    {
        if ($subscriptionId <= 0) {
            return;
        }

        $gateway = strtolower(trim($gateway));
        $providerSubscriptionId = trim($providerSubscriptionId);
        if ($gateway === '' || $providerSubscriptionId === '') {
            return;
        }

        $now = self::gatewaySyncTimestamp();
        $db->prepare("
            UPDATE payment_gateway_sync_jobs
            SET status = 'completed',
                processed_at = COALESCE(processed_at, ?),
                available_at = NULL,
                last_error = NULL,
                updated_at = NOW()
            WHERE subscription_id = ?
              AND gateway = ?
              AND action = 'cancel_subscription'
              AND provider_subscription_id = ?
              AND status IN ('pending', 'processing', 'failed')
        ")->execute([
            $now,
            $subscriptionId,
            $gateway,
            $providerSubscriptionId,
        ]);
    }

    private static function pendingTransactionCleanupInstruction(array $transaction): ?array
    {
        $gateway = strtolower(trim((string)($transaction['gateway'] ?? '')));
        $providerSubscriptionId = trim((string)($transaction['provider_subscription_id'] ?? ''));
        if ($gateway !== 'paypal' || $providerSubscriptionId === '' || empty($transaction['auto_renew'])) {
            return null;
        }

        return [
            'gateway' => $gateway,
            'provider_subscription_id' => $providerSubscriptionId,
            'reference' => (string)($transaction['gateway_reference'] ?? ''),
            'transaction_id' => (int)($transaction['id'] ?? 0),
        ];
    }

    private static function cleanupPendingGatewayResources(array $cleanupInstructions): void
    {
        foreach ($cleanupInstructions as $instruction) {
            $gateway = strtolower(trim((string)($instruction['gateway'] ?? '')));
            $providerSubscriptionId = trim((string)($instruction['provider_subscription_id'] ?? ''));
            if ($gateway !== 'paypal' || $providerSubscriptionId === '') {
                continue;
            }

            try {
                self::cancelPayPalSubscription($providerSubscriptionId);
            } catch (\Throwable $e) {
                Logger::warning('Pending gateway resource cleanup failed', [
                    'gateway' => $gateway,
                    'provider_subscription_id' => $providerSubscriptionId,
                    'reference' => $instruction['reference'] ?? null,
                    'transaction_id' => $instruction['transaction_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public static function confirmStripeSuccess(string $sessionId, ?int $expectedUserId = null): array
    {
        $secretKey = self::assertDecryptedSecret((string)Setting::getEncrypted('payment_stripe_secret_key', ''), 'Stripe secret key');

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

        if ($expectedUserId !== null) {
            self::assertTransactionOwnedByUser('stripe', $reference, $expectedUserId);
        }

        if (!empty($session['subscription'])) {
            $db = Database::getInstance()->getConnection();
            $db->prepare("UPDATE transactions SET provider_subscription_id = ? WHERE gateway = 'stripe' AND gateway_reference = ? LIMIT 1")
                ->execute([(string)$session['subscription'], $reference]);
        }

        $status = (($session['payment_status'] ?? '') === 'paid') ? 'completed' : 'pending';
        return self::applyGatewayStatus('stripe', $reference, $status);
    }

    public static function capturePayPalOrder(string $orderId, string $reference, ?int $expectedUserId = null): array
    {
        if ($expectedUserId !== null) {
            self::assertTransactionOwnedByUser('paypal', $reference, $expectedUserId);
        }

        $accessToken = self::paypalAccessToken();
        $baseUrl = self::payPalBaseUrl();
        $capture = self::httpRequest(
            'POST',
            $baseUrl . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
            ['_empty_object' => true],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        $capturedReference = self::extractPayPalReference($capture);
        if ($capturedReference === '') {
            throw new \RuntimeException('PayPal capture response did not include an internal payment reference.');
        }
        if (!hash_equals($capturedReference, $reference)) {
            throw new \RuntimeException('PayPal capture response did not match the expected payment reference.');
        }

        $status = strtolower((string)($capture['status'] ?? ''));
        $mapped = $status === 'completed' ? 'completed' : ($status === 'payer_action_required' ? 'pending' : 'failed');
        return self::applyGatewayStatus('paypal', $capturedReference, $mapped);
    }

    public static function confirmPayPalSubscription(string $reference, ?int $expectedUserId = null): array
    {
        if ($expectedUserId !== null) {
            self::assertTransactionOwnedByUser('paypal', $reference, $expectedUserId);
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT provider_subscription_id
            FROM transactions
            WHERE gateway = 'paypal' AND gateway_reference = ?
            LIMIT 1
        ");
        $stmt->execute([$reference]);
        $providerSubscriptionId = trim((string)($stmt->fetchColumn() ?: ''));
        if ($providerSubscriptionId === '') {
            throw new \RuntimeException('PayPal subscription reference could not be found.');
        }

        $subscription = self::fetchPayPalSubscription($providerSubscriptionId);
        $status = strtolower(trim((string)($subscription['status'] ?? '')));
        if (!empty($subscription['custom_id']) && !hash_equals((string)$subscription['custom_id'], $reference)) {
            throw new \RuntimeException('PayPal subscription did not match the expected internal payment reference.');
        }

        // PayPal subscription activation is only a lifecycle state. Do not
        // grant premium locally until the first recurring payment settles.
        return self::applyGatewayStatus('paypal', $reference, 'pending');
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
        $secretKey = self::assertDecryptedSecret((string)Setting::getEncrypted('payment_stripe_secret_key', ''), 'Stripe secret key');

        $successUrl = SeoService::trustedBaseUrl() . '/payment/stripe/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = SeoService::trustedBaseUrl() . '/payment/cancel?gateway=stripe&reference=' . rawurlencode((string)$transaction['reference']);

        $autoRenew = !empty($transaction['auto_renew']);
        $payload = [
            'mode' => $autoRenew ? 'subscription' : 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string)$transaction['reference'],
            'payment_method_types[0]' => 'card',
        ];

        if ($autoRenew) {
            $recurring = self::stripeRecurringSpecForDays((int)($transaction['term_days'] ?? 30));
            $payload['line_items[0][quantity]'] = '1';
            $payload['line_items[0][price_data][currency]'] = self::DEFAULT_CURRENCY;
            $payload['line_items[0][price_data][unit_amount]'] = (string)((int)round((float)($transaction['original_amount'] ?? self::packageAmount($package)) * 100));
            $payload['line_items[0][price_data][product_data][name]'] = (string)($package['name'] ?? 'Premium Package');
            $payload['line_items[0][price_data][product_data][description]'] = 'Auto-renewing premium access for ' . (string)($package['name'] ?? 'package');
            $payload['line_items[0][price_data][recurring][interval]'] = $recurring['interval'];
            $payload['line_items[0][price_data][recurring][interval_count]'] = (string)$recurring['interval_count'];
            $payload['subscription_data[metadata][fyuhls_reference]'] = (string)$transaction['reference'];
            $payload['subscription_data[metadata][fyuhls_term_days]'] = (string)((int)($transaction['term_days'] ?? 30));

            $stripeCouponId = self::createStripeCouponForRecurringCheckout($secretKey, $transaction);
            if ($stripeCouponId !== null) {
                $payload['discounts[0][coupon]'] = $stripeCouponId;
            }
        } else {
            $payload['line_items[0][quantity]'] = '1';
            $payload['line_items[0][price_data][currency]'] = self::DEFAULT_CURRENCY;
            $payload['line_items[0][price_data][unit_amount]'] = (string)((int)round(((float)$transaction['amount']) * 100));
            $payload['line_items[0][price_data][product_data][name]'] = (string)($package['name'] ?? 'Premium Package');
            $payload['line_items[0][price_data][product_data][description]'] = 'Access upgrade for ' . (string)($package['name'] ?? 'package');
        }

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

    private static function createStripeCouponForRecurringCheckout(string $secretKey, array $transaction): ?string
    {
        $discountAmount = (float)($transaction['discount_amount'] ?? 0);
        if ($discountAmount <= 0) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT id, discount_type, discount_value, percent_cap_amount, duration_type, duration_cycles
            FROM coupons
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$transaction['coupon_id'] ?? 0]);
        $coupon = $stmt->fetch();
        if (!$coupon) {
            throw new \RuntimeException('Coupon metadata could not be loaded for recurring checkout.');
        }
        self::assertStripeRecurringCouponCompatible($coupon, (int)($transaction['term_days'] ?? 30), $discountAmount);

        $payload = [
            'name' => 'Fyuhls ' . (string)($transaction['coupon_code'] ?? 'coupon') . ' ' . (string)$transaction['reference'],
            'metadata[fyuhls_reference]' => (string)$transaction['reference'],
        ];

        $durationType = (string)($coupon['duration_type'] ?? 'once');
        if ($durationType === 'forever') {
            $payload['duration'] = 'forever';
        } elseif ($durationType === 'cycles') {
            $months = self::cycleDurationMonths((int)($transaction['term_days'] ?? 30), (int)($coupon['duration_cycles'] ?? 1));
            if ($months === null) {
                throw new \RuntimeException('This coupon uses repeated renewal discounts that only map cleanly to monthly or yearly renewable terms. Use a monthly, quarterly, semiannual, or yearly term for recurring checkout.');
            }
            $payload['duration'] = 'repeating';
            $payload['duration_in_months'] = (string)$months;
        } else {
            $payload['duration'] = 'once';
        }

        if ((string)($coupon['discount_type'] ?? 'amount') === 'percent') {
            if ((float)($coupon['percent_cap_amount'] ?? 0) > 0) {
                if ($durationType !== 'once') {
                    throw new \RuntimeException('Percent coupons with a dollar cap are only supported for one-time introductory recurring discounts right now. Use a one-time purchase or remove the cap for multi-cycle recurring promotions.');
                }
                $payload['amount_off'] = (string)((int)round($discountAmount * 100));
                $payload['currency'] = self::DEFAULT_CURRENCY;
            } else {
                $payload['percent_off'] = (string)round((float)($coupon['discount_value'] ?? 0), 2);
            }
        } else {
            $payload['amount_off'] = (string)((int)round($discountAmount * 100));
            $payload['currency'] = self::DEFAULT_CURRENCY;
        }

        $response = self::httpRequest(
            'POST',
            'https://api.stripe.com/v1/coupons',
            $payload,
            [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );

        if (empty($response['id'])) {
            throw new \RuntimeException('Stripe could not prepare the recurring coupon discount.');
        }

        return (string)$response['id'];
    }

    private static function fetchStripeSubscription(string $subscriptionId): array
    {
        $secretKey = self::assertDecryptedSecret((string)Setting::getEncrypted('payment_stripe_secret_key', ''), 'Stripe secret key');
        return self::httpRequest(
            'GET',
            'https://api.stripe.com/v1/subscriptions/' . rawurlencode($subscriptionId),
            [],
            [
                'Authorization: Bearer ' . $secretKey,
            ]
        );
    }

    private static function fetchPayPalSubscription(string $subscriptionId): array
    {
        $accessToken = self::paypalAccessToken();
        return self::httpRequest(
            'GET',
            self::payPalBaseUrl() . '/v1/billing/subscriptions/' . rawurlencode($subscriptionId),
            [],
            [
                'Authorization: Bearer ' . $accessToken,
            ]
        );
    }

    private static function cancelPayPalSubscription(string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            return;
        }

        $accessToken = self::paypalAccessToken();
        self::httpRequest(
            'POST',
            self::payPalBaseUrl() . '/v1/billing/subscriptions/' . rawurlencode($subscriptionId) . '/cancel',
            [
                'reason' => 'Cancelled by Fyuhls account or refund flow.',
            ],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );
    }

    private static function paypalSubscriptionExpiry(array $subscription, ?int $fallbackTimestamp = null): string
    {
        $nextBilling = trim((string)($subscription['billing_info']['next_billing_time'] ?? $subscription['next_billing_time'] ?? ''));
        if ($nextBilling !== '') {
            $timestamp = strtotime($nextBilling);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        if ($fallbackTimestamp !== null && $fallbackTimestamp > 0) {
            return date('Y-m-d H:i:s', $fallbackTimestamp);
        }

        return date('Y-m-d H:i:s');
    }

    private static function cancelStripeSubscription(string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            return;
        }

        $secretKey = self::assertDecryptedSecret((string)Setting::getEncrypted('payment_stripe_secret_key', ''), 'Stripe secret key');
        self::httpRequest(
            'POST',
            'https://api.stripe.com/v1/subscriptions/' . rawurlencode($subscriptionId),
            [
                'cancel_at_period_end' => 'true',
            ],
            [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );
    }

    public static function updateAutoRenewPreference(int $subscriptionId, int $expectedUserId, bool $enabled): void
    {
        self::ensureTablesExist();

        $db = Database::getInstance()->getConnection();
        $syncJobId = 0;
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT *
                FROM subscriptions
                WHERE id = ? AND user_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$subscriptionId, $expectedUserId]);
            $subscription = $stmt->fetch();
            if (!$subscription) {
                throw new \RuntimeException('Subscription not found.');
            }
            if (empty($subscription['provider_subscription_id'])) {
                throw new \RuntimeException('This subscription cannot be managed from the current auto-renew controls.');
            }
            if ((string)($subscription['status'] ?? '') !== 'active') {
                throw new \RuntimeException('Only active subscriptions can change auto-renew right now.');
            }

            $gateway = strtolower(trim((string)($subscription['gateway'] ?? '')));
            $providerSubscriptionId = trim((string)($subscription['provider_subscription_id'] ?? ''));

            if ($gateway === 'paypal') {
                if ($enabled) {
                    throw new \RuntimeException('PayPal auto-renew can be turned off from Fyuhls, but turning it back on later currently needs a fresh PayPal subscription checkout.');
                }

                $syncJobId = self::queueGatewaySyncJob($db, 'paypal', 'cancel_subscription', $providerSubscriptionId, $subscriptionId, [
                    'source' => 'user_auto_renew_preference',
                ]);
            } elseif ($gateway === 'stripe') {
                $syncJobId = self::queueGatewaySyncJob($db, 'stripe', 'stripe_auto_renew', $providerSubscriptionId, $subscriptionId, [
                    'enabled' => $enabled ? 1 : 0,
                    'source' => 'user_auto_renew_preference',
                ]);
            } else {
                throw new \RuntimeException('This subscription cannot be managed from the current auto-renew controls.');
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $result = self::processGatewaySyncJobsStrict([$syncJobId]);
        if ((int)($result['completed'] ?? 0) !== 1 || (int)($result['failed'] ?? 0) !== 0) {
            throw new \RuntimeException('The auto-renew preference could not be synchronized safely right now.');
        }
    }

    private static function createPayPalCheckoutUrl(array $transaction, array $package): string
    {
        if (!empty($transaction['auto_renew'])) {
            if ((float)($transaction['discount_amount'] ?? 0) > 0) {
                throw new \RuntimeException('Recurring PayPal checkout is available, but recurring coupon discounts currently need Stripe or a one-time PayPal purchase.');
            }
            return self::createPayPalSubscriptionUrl($transaction, $package);
        }

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
            $rel = strtolower(trim((string)($link['rel'] ?? '')));
            if (in_array($rel, ['approve', 'payer-action'], true) && !empty($link['href'])) {
                return (string)$link['href'];
            }
        }

        throw new \RuntimeException('PayPal approval URL was missing from the order response.');
    }

    private static function createPayPalSubscriptionUrl(array $transaction, array $package): string
    {
        $accessToken = self::paypalAccessToken();
        $baseUrl = self::payPalBaseUrl();
        $termDays = max(1, (int)($transaction['term_days'] ?? 30));
        $interval = self::paypalRecurringSpecForDays($termDays);
        $amount = number_format((float)$transaction['amount'], 2, '.', '');
        $planContext = self::ensurePayPalRecurringPlanId($accessToken, $baseUrl, $package, $termDays, $interval, $amount, self::DEFAULT_CURRENCY);
        $subscriptionId = '';

        try {
            try {
                $subscription = self::createPayPalSubscriptionCheckout($accessToken, $baseUrl, $planContext['plan_id'], $transaction);
            } catch (\Throwable $e) {
                if (empty($planContext['from_cache'])) {
                    throw $e;
                }
                $planContext = self::ensurePayPalRecurringPlanId($accessToken, $baseUrl, $package, $termDays, $interval, $amount, self::DEFAULT_CURRENCY, true);
                $subscription = self::createPayPalSubscriptionCheckout($accessToken, $baseUrl, $planContext['plan_id'], $transaction);
            }

            if (empty($subscription['id']) || empty($subscription['links']) || !is_array($subscription['links'])) {
                throw new \RuntimeException('PayPal did not return a subscription approval link.');
            }

            $subscriptionId = (string)$subscription['id'];
            $db = Database::getInstance()->getConnection();
            $db->prepare("UPDATE transactions SET provider_subscription_id = ? WHERE gateway = 'paypal' AND gateway_reference = ? LIMIT 1")
                ->execute([$subscriptionId, (string)$transaction['reference']]);

            foreach ($subscription['links'] as $link) {
                $rel = strtolower(trim((string)($link['rel'] ?? '')));
                if ($rel === 'approve' && !empty($link['href'])) {
                    return (string)$link['href'];
                }
            }

            throw new \RuntimeException('PayPal subscription approval URL was missing from the response.');
        } catch (\Throwable $e) {
            if ($subscriptionId !== '') {
                try {
                    self::cancelPayPalSubscription($subscriptionId);
                } catch (\Throwable $cleanupError) {
                    Logger::warning('PayPal subscription cleanup failed after checkout-start error', [
                        'reference' => $transaction['reference'] ?? null,
                        'provider_subscription_id' => $subscriptionId,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }
            throw $e;
        }
    }

    private static function createPayPalSubscriptionCheckout(string $accessToken, string $baseUrl, string $planId, array $transaction): array
    {
        return self::httpRequest(
            'POST',
            $baseUrl . '/v1/billing/subscriptions',
            [
                'plan_id' => $planId,
                'custom_id' => (string)$transaction['reference'],
                'application_context' => [
                    'brand_name' => (string)Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')),
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => SeoService::trustedBaseUrl() . '/payment/paypal/return?reference=' . rawurlencode((string)$transaction['reference']),
                    'cancel_url' => SeoService::trustedBaseUrl() . '/payment/cancel?gateway=paypal&reference=' . rawurlencode((string)$transaction['reference']),
                ],
            ],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'PayPal-Request-Id: SUB-' . strtoupper(bin2hex(random_bytes(8))),
            ]
        );
    }

    private static function ensurePayPalRecurringPlanId(
        string $accessToken,
        string $baseUrl,
        array $package,
        int $termDays,
        array $interval,
        string $amount,
        string $currency,
        bool $forceFresh = false
    ): array {
        $settingKey = self::payPalRecurringPlanSettingKey($termDays, $amount, $currency);
        $cachedPlanId = $forceFresh ? '' : trim((string)Setting::get($settingKey, ''));
        if ($cachedPlanId !== '') {
            return [
                'plan_id' => $cachedPlanId,
                'setting_key' => $settingKey,
                'from_cache' => true,
            ];
        }

        $productId = self::ensurePayPalRecurringProductId($accessToken, $baseUrl, $package, $forceFresh);
        $plan = self::httpRequest(
            'POST',
            $baseUrl . '/v1/billing/plans',
            [
                'product_id' => $productId,
                'name' => (string)($package['name'] ?? 'Premium Package') . ' - ' . self::formatTermLabel($termDays),
                'description' => 'Fyuhls recurring premium access',
                'status' => 'ACTIVE',
                'billing_cycles' => [[
                    'frequency' => [
                        'interval_unit' => $interval['unit'],
                        'interval_count' => $interval['count'],
                    ],
                    'tenure_type' => 'REGULAR',
                    'sequence' => 1,
                    'total_cycles' => 0,
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value' => $amount,
                            'currency_code' => $currency,
                        ],
                    ],
                ]],
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'payment_failure_threshold' => 1,
                ],
            ],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'PayPal-Request-Id: PLAN-' . strtoupper(bin2hex(random_bytes(8))),
            ]
        );

        if (empty($plan['id'])) {
            throw new \RuntimeException('PayPal did not return a billing plan for recurring checkout.');
        }

        Setting::set($settingKey, (string)$plan['id'], 'payments');
        return [
            'plan_id' => (string)$plan['id'],
            'setting_key' => $settingKey,
            'from_cache' => false,
        ];
    }

    private static function ensurePayPalRecurringProductId(string $accessToken, string $baseUrl, array $package, bool $forceFresh = false): string
    {
        $settingKey = 'payment_paypal_recurring_product_id';
        $cachedProductId = $forceFresh ? '' : trim((string)Setting::get($settingKey, ''));
        if ($cachedProductId !== '') {
            return $cachedProductId;
        }

        $product = self::httpRequest(
            'POST',
            $baseUrl . '/v1/catalogs/products',
            [
                'name' => (string)Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')) . ' Premium Subscription',
                'description' => 'Fyuhls premium subscription catalog item for recurring premium access.',
                'type' => 'SERVICE',
                'category' => 'SOFTWARE',
            ],
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'PayPal-Request-Id: PROD-' . strtoupper(bin2hex(random_bytes(8))),
            ]
        );

        if (empty($product['id'])) {
            throw new \RuntimeException('PayPal did not return a product ID for recurring checkout.');
        }

        Setting::set($settingKey, (string)$product['id'], 'payments');
        return (string)$product['id'];
    }

    private static function payPalRecurringPlanSettingKey(int $termDays, string $amount, string $currency): string
    {
        return 'pp_rplan_' . substr(hash('sha256', implode('|', [$currency, $amount, (string)$termDays])), 0, 24);
    }

    private static function handleStripeWebhook(array $payload, string $signature): array
    {
        $secret = self::assertDecryptedSecret((string)Setting::getEncrypted('payment_stripe_webhook_secret', ''), 'Stripe webhook secret');

        self::verifyStripeWebhookSignature((string)($payload['_raw_body'] ?? ''), $signature, $secret);
        $eventId = trim((string)($payload['id'] ?? ''));

        $eventType = (string)($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? [];

        if ($eventType === 'invoice.paid') {
            return self::recordStripeRenewalInvoice($object, $eventId);
        }

        if ($eventType === 'invoice.payment_failed') {
            return self::syncStripeSubscriptionStateFromWebhook(
                trim((string)($object['subscription'] ?? '')),
                $eventId,
                false
            );
        }

        if ($eventType === 'customer.subscription.updated') {
            return self::syncStripeSubscriptionStateFromWebhook(
                trim((string)($object['id'] ?? '')),
                $eventId,
                false
            );
        }

        if ($eventType === 'customer.subscription.deleted') {
            return self::syncStripeSubscriptionStateFromWebhook(
                trim((string)($object['id'] ?? '')),
                $eventId,
                true
            );
        }

        $reference = (string)($object['client_reference_id'] ?? '');
        if ($reference === '') {
            throw new \RuntimeException('Stripe webhook did not include an internal payment reference.');
        }

        if (!empty($object['subscription'])) {
            $db = Database::getInstance()->getConnection();
            $db->prepare("UPDATE transactions SET provider_subscription_id = ? WHERE gateway = 'stripe' AND gateway_reference = ? LIMIT 1")
                ->execute([(string)$object['subscription'], $reference]);
        }

        $status = match ($eventType) {
            'checkout.session.completed' => (($object['payment_status'] ?? '') === 'paid') ? 'completed' : 'pending',
            'checkout.session.async_payment_failed',
            'payment_intent.payment_failed' => 'failed',
            default => 'pending',
        };

        return self::applyGatewayStatus('stripe', $reference, $status, $eventId);
    }

    private static function recordStripeRenewalInvoice(array $invoice, string $eventId): array
    {
        $subscriptionId = trim((string)($invoice['subscription'] ?? ''));
        if ($subscriptionId === '') {
            throw new \RuntimeException('Stripe renewal invoice did not include a subscription ID.');
        }

        $billingReason = strtolower(trim((string)($invoice['billing_reason'] ?? '')));
        if ($billingReason !== 'subscription_cycle') {
            $db = Database::getInstance()->getConnection();
            $db->beginTransaction();
            try {
                if (!self::claimWebhookEvent($db, 'stripe', $eventId)) {
                    $db->commit();
                    return ['status' => 'ignored', 'message' => 'Stripe invoice event already processed.'];
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            return ['status' => 'ignored', 'message' => 'Stripe invoice does not represent a renewal cycle.'];
        }

        $db = Database::getInstance()->getConnection();
        $touchUserIds = [];
        $db->beginTransaction();
        try {
            if (!self::claimWebhookEvent($db, 'stripe', $eventId)) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'Stripe renewal invoice already processed.'];
            }

            $stmt = $db->prepare("
                SELECT *
                FROM subscriptions
                WHERE provider_subscription_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$subscriptionId]);
            $subscription = $stmt->fetch();
            if (!$subscription) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'Local subscription record was not found for this Stripe renewal.'];
            }

            if ((string)($subscription['status'] ?? '') === 'cancelled') {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'Local subscription remains cancelled while the Stripe cancellation workflow finishes upstream.'];
            }

            $stripeSubscription = self::fetchStripeSubscription($subscriptionId);
            $expiresAt = date('Y-m-d H:i:s', (int)($stripeSubscription['current_period_end'] ?? time()));
            $autoRenew = empty($stripeSubscription['cancel_at_period_end']) ? 1 : 0;

            $db->prepare("
                UPDATE subscriptions
                SET status = 'active', auto_renew = ?, expires_at = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$autoRenew, $expiresAt, (int)$subscription['id']]);

            $invoiceId = trim((string)($invoice['id'] ?? ''));
            $exists = $db->prepare("SELECT id FROM transactions WHERE gateway = 'stripe' AND gateway_reference = ? LIMIT 1");
            $exists->execute([$invoiceId]);

            if (!$exists->fetchColumn()) {
                $originalAmount = ((float)($invoice['subtotal'] ?? 0)) / 100;
                if ($originalAmount <= 0) {
                    $originalAmount = (float)($subscription['original_amount'] ?? $subscription['amount']);
                }
                $amountPaid = ((float)($invoice['amount_paid'] ?? 0)) / 100;
                $discountAmount = max(0, round($originalAmount - $amountPaid, 2));

                $db->prepare("
                    INSERT INTO transactions
                        (user_id, package_id, package_billing_option_id, coupon_id, coupon_code, original_amount, discount_amount, amount, currency, term_days, auto_renew, gateway, gateway_reference, provider_subscription_id, status, ip_address)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'stripe', ?, ?, 'completed', NULL)
                ")->execute([
                    (int)$subscription['user_id'],
                    (int)$subscription['package_id'],
                    $subscription['package_billing_option_id'] !== null ? (int)$subscription['package_billing_option_id'] : null,
                    $subscription['coupon_id'] !== null ? (int)$subscription['coupon_id'] : null,
                    $discountAmount > 0 ? ($subscription['coupon_code'] ?: null) : null,
                    $originalAmount,
                    $discountAmount,
                    $amountPaid,
                    (string)($subscription['currency'] ?? self::DEFAULT_CURRENCY),
                    (int)($subscription['term_days'] ?? 30),
                    $invoiceId,
                    $subscriptionId,
                ]);

                $renewalTransactionId = (int)$db->lastInsertId();
                $touchUserIds = array_merge($touchUserIds, self::awardAffiliateCommission($db, [
                    'id' => $renewalTransactionId,
                    'user_id' => (int)$subscription['user_id'],
                    'package_id' => (int)$subscription['package_id'],
                    'amount' => $amountPaid,
                    'currency' => (string)($subscription['currency'] ?? self::DEFAULT_CURRENCY),
                    'gateway_reference' => $invoiceId,
                ], 'stripe'));
            }

            self::restoreUserPackageFromActiveSubscriptionOrGuest($db, (int)$subscription['user_id']);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($touchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($touchUserIds, true, [
                'workflow' => 'stripe_recurring_invoice',
                'subscription_id' => $subscriptionId,
                'event_kind' => 'invoice.paid',
            ]);
        }

        return ['status' => 'completed', 'message' => 'Stripe renewal invoice recorded.'];
    }

    private static function syncStripeSubscriptionStateFromWebhook(string $subscriptionId, string $eventId, bool $deleted): array
    {
        if ($subscriptionId === '') {
            throw new \RuntimeException('Stripe subscription lifecycle event did not include a subscription ID.');
        }

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        try {
            if (!self::claimWebhookEvent($db, 'stripe', $eventId)) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'Stripe subscription event already processed.'];
            }

            $stmt = $db->prepare("
                SELECT id, user_id
                FROM subscriptions
                WHERE provider_subscription_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$subscriptionId]);
            $subscription = $stmt->fetch();
            if (!$subscription) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'No local subscription matched this Stripe lifecycle event.'];
            }

            if ($deleted) {
                self::clearPendingGatewayCancellationSync($db, (int)($subscription['id'] ?? 0), 'stripe', $subscriptionId);
                $db->prepare("UPDATE subscriptions SET status = 'cancelled', auto_renew = 0, updated_at = NOW() WHERE id = ?")
                    ->execute([(int)$subscription['id']]);
            } else {
                $stripeSubscription = self::fetchStripeSubscription($subscriptionId);
                $status = in_array((string)($stripeSubscription['status'] ?? 'active'), ['canceled', 'incomplete_expired', 'unpaid'], true)
                    ? 'cancelled'
                    : 'active';
                $expiresAt = date('Y-m-d H:i:s', (int)($stripeSubscription['current_period_end'] ?? time()));
                $autoRenew = empty($stripeSubscription['cancel_at_period_end']) ? 1 : 0;
                if ((string)($subscription['status'] ?? '') === 'cancelled' && $status !== 'cancelled') {
                    $db->commit();
                    return ['status' => 'ignored', 'message' => 'Local subscription remains cancelled while the Stripe cancellation workflow finishes upstream.'];
                }
                if ($status === 'cancelled') {
                    self::clearPendingGatewayCancellationSync($db, (int)($subscription['id'] ?? 0), 'stripe', $subscriptionId);
                }
                $db->prepare("
                    UPDATE subscriptions
                    SET status = ?, auto_renew = ?, expires_at = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$status, $autoRenew, $expiresAt, (int)$subscription['id']]);
            }

            self::restoreUserPackageFromActiveSubscriptionOrGuest($db, (int)$subscription['user_id']);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return ['status' => 'updated', 'message' => 'Stripe subscription state synchronized.'];
    }

    private static function handlePayPalWebhook(array $payload): array
    {
        self::verifyPayPalWebhook($payload);
        $eventId = trim((string)($payload['id'] ?? ($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '')));

        $eventType = (string)($payload['event_type'] ?? '');
        $resource = $payload['resource'] ?? [];

        if ($eventType === 'BILLING.SUBSCRIPTION.ACTIVATED') {
            $reference = self::extractPayPalReference($resource);
            if ($reference !== '') {
                $subscriptionId = trim((string)($resource['id'] ?? ''));
                if ($subscriptionId !== '') {
                    $db = Database::getInstance()->getConnection();
                    $db->prepare("UPDATE transactions SET provider_subscription_id = ? WHERE gateway = 'paypal' AND gateway_reference = ? LIMIT 1")
                        ->execute([$subscriptionId, $reference]);
                }
                return self::applyGatewayStatus('paypal', $reference, 'pending', $eventId);
            }
        }

        if ($eventType === 'PAYMENT.SALE.COMPLETED') {
            $subscriptionId = trim((string)($resource['billing_agreement_id'] ?? $resource['subscription_id'] ?? ''));
            if ($subscriptionId !== '') {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    SELECT gateway_reference
                    FROM transactions
                    WHERE gateway = 'paypal'
                      AND provider_subscription_id = ?
                      AND status = 'pending'
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([$subscriptionId]);
                $pendingReference = trim((string)($stmt->fetchColumn() ?: ''));
                if ($pendingReference !== '') {
                    return self::applyGatewayStatus('paypal', $pendingReference, 'completed', $eventId);
                }
            }
            return self::syncPayPalRecurringPayment($resource, $eventId);
        }

        if (in_array($eventType, ['BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED', 'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED'], true)) {
            return self::syncPayPalSubscriptionStateFromWebhook($resource, $eventType, $eventId);
        }

        $reference = self::extractPayPalReference($resource);

        if ($reference === '') {
            throw new \RuntimeException('PayPal webhook did not include an internal payment reference.');
        }

        $status = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => 'completed',
            'CHECKOUT.ORDER.APPROVED' => 'pending',
            'PAYMENT.CAPTURE.DENIED' => 'denied',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            'PAYMENT.CAPTURE.PENDING' => 'pending',
            default => 'pending',
        };

        return self::applyGatewayStatus('paypal', $reference, $status, $eventId);
    }

    private static function syncPayPalRecurringPayment(array $resource, string $eventId): array
    {
        $subscriptionId = trim((string)($resource['billing_agreement_id'] ?? $resource['subscription_id'] ?? ''));
        if ($subscriptionId === '') {
            throw new \RuntimeException('PayPal recurring payment webhook did not include a subscription ID.');
        }

        $db = Database::getInstance()->getConnection();
        $touchUserIds = [];
        $db->beginTransaction();
        try {
            if (!self::claimWebhookEvent($db, 'paypal', $eventId)) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'PayPal recurring payment event already processed.'];
            }

            $stmt = $db->prepare("
                SELECT *
                FROM subscriptions
                WHERE provider_subscription_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$subscriptionId]);
            $subscription = $stmt->fetch();
            if (!$subscription) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'No local PayPal subscription matched this renewal payment.'];
            }

            if ((string)($subscription['status'] ?? '') === 'cancelled') {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'Local subscription remains cancelled while the PayPal cancellation workflow finishes upstream.'];
            }

            $paypalSubscription = self::fetchPayPalSubscription($subscriptionId);
            $expiresAt = self::paypalSubscriptionExpiry($paypalSubscription, (int)($resource['next_billing_time'] ?? 0));
            $paypalStatus = strtolower(trim((string)($paypalSubscription['status'] ?? 'ACTIVE')));
            $status = $paypalStatus === 'active' ? 'active' : 'pending';
            $autoRenew = in_array($paypalStatus, ['cancelled', 'expired'], true) ? 0 : 1;

            $db->prepare("
                UPDATE subscriptions
                SET status = ?, auto_renew = ?, expires_at = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$status, $autoRenew, $expiresAt, (int)$subscription['id']]);

            $paymentReference = trim((string)($resource['id'] ?? ''));
            if ($paymentReference !== '') {
                $exists = $db->prepare("SELECT id FROM transactions WHERE gateway = 'paypal' AND gateway_reference = ? LIMIT 1");
                $exists->execute([$paymentReference]);
                if (!$exists->fetchColumn()) {
                    $amountPaid = (float)($subscription['amount'] ?? 0);
                    if (isset($resource['amount']['total'])) {
                        $amountPaid = (float)$resource['amount']['total'];
                    } elseif (isset($resource['amount']['value'])) {
                        $amountPaid = (float)$resource['amount']['value'];
                    }

                    $originalAmount = (float)($subscription['original_amount'] ?? $amountPaid);
                    if ($originalAmount <= 0) {
                        $originalAmount = $amountPaid;
                    }
                    $discountAmount = max(0, round($originalAmount - $amountPaid, 2));

                    $db->prepare("
                    INSERT INTO transactions
                            (user_id, package_id, package_billing_option_id, coupon_id, coupon_code, original_amount, discount_amount, amount, currency, term_days, auto_renew, gateway, gateway_reference, provider_subscription_id, status, ip_address)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'paypal', ?, ?, 'completed', NULL)
                    ")->execute([
                        (int)$subscription['user_id'],
                        (int)$subscription['package_id'],
                        $subscription['package_billing_option_id'] !== null ? (int)$subscription['package_billing_option_id'] : null,
                        $subscription['coupon_id'] !== null ? (int)$subscription['coupon_id'] : null,
                        $discountAmount > 0 ? ($subscription['coupon_code'] ?: null) : null,
                        $originalAmount,
                        $discountAmount,
                        $amountPaid,
                        (string)($subscription['currency'] ?? self::DEFAULT_CURRENCY),
                        (int)($subscription['term_days'] ?? 30),
                        $paymentReference,
                        $subscriptionId,
                    ]);

                    $renewalTransactionId = (int)$db->lastInsertId();
                    $touchUserIds = self::awardAffiliateCommission($db, [
                        'id' => $renewalTransactionId,
                        'user_id' => (int)$subscription['user_id'],
                        'package_id' => (int)$subscription['package_id'],
                        'amount' => $amountPaid,
                        'currency' => (string)($subscription['currency'] ?? self::DEFAULT_CURRENCY),
                        'gateway_reference' => $paymentReference,
                    ], 'paypal');
                }
            }

            self::restoreUserPackageFromActiveSubscriptionOrGuest($db, (int)$subscription['user_id']);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($touchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($touchUserIds, true, [
                'workflow' => 'paypal_recurring_payment',
                'subscription_id' => $subscriptionId,
                'event_kind' => 'PAYMENT.SALE.COMPLETED',
            ]);
        }

        return ['status' => 'completed', 'message' => 'PayPal recurring payment synchronized.'];
    }

    private static function syncPayPalSubscriptionStateFromWebhook(array $resource, string $eventType, string $eventId): array
    {
        $subscriptionId = trim((string)($resource['id'] ?? $resource['subscription_id'] ?? ''));
        if ($subscriptionId === '') {
            throw new \RuntimeException('PayPal subscription webhook did not include a subscription ID.');
        }

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        try {
            if (!self::claimWebhookEvent($db, 'paypal', $eventId)) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'PayPal subscription event already processed.'];
            }

            $stmt = $db->prepare("
                SELECT id, user_id
                FROM subscriptions
                WHERE provider_subscription_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$subscriptionId]);
            $subscription = $stmt->fetch();
            if (!$subscription) {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'No local PayPal subscription matched this lifecycle event.'];
            }

            $paypalSubscription = self::fetchPayPalSubscription($subscriptionId);
            $paypalStatus = strtolower(trim((string)($paypalSubscription['status'] ?? '')));
            $localStatus = match ($paypalStatus) {
                'active', 'approved' => 'active',
                'cancelled', 'expired', 'suspended' => 'cancelled',
                default => 'pending',
            };
            $autoRenew = in_array($paypalStatus, ['cancelled', 'expired'], true) ? 0 : 1;
            $expiresAt = self::paypalSubscriptionExpiry($paypalSubscription);
            if ((string)($subscription['status'] ?? '') === 'cancelled' && $localStatus !== 'cancelled') {
                $db->commit();
                return ['status' => 'ignored', 'message' => 'Local subscription remains cancelled while the PayPal cancellation workflow finishes upstream.'];
            }
            if ($localStatus === 'cancelled') {
                self::clearPendingGatewayCancellationSync($db, (int)($subscription['id'] ?? 0), 'paypal', $subscriptionId);
            }

            $db->prepare("
                UPDATE subscriptions
                SET status = ?, auto_renew = ?, expires_at = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$localStatus, $autoRenew, $expiresAt, (int)$subscription['id']]);

            self::restoreUserPackageFromActiveSubscriptionOrGuest($db, (int)$subscription['user_id']);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['status' => 'updated', 'message' => 'PayPal subscription state synchronized.'];
    }

    private static function handleSignedInternalCallback(string $gateway, array $payload, string $signature): array
    {
        $reference = trim((string)($payload['reference'] ?? $payload['gateway_reference'] ?? ''));
        if ($reference === '') {
            throw new \RuntimeException('Missing payment reference.');
        }

        $status = self::normalizeStatus((string)($payload['status'] ?? ''));
        if ($status === '') {
            throw new \RuntimeException('Unsupported payment status.');
        }

        if (!self::verifyInternalSignature($payload, $signature)) {
            throw new \RuntimeException('Invalid payment callback signature.');
        }

        return self::applyGatewayStatus($gateway, $reference, $status);
    }

    private static function applyGatewayStatus(string $gateway, string $reference, string $status, string $eventId = ''): array
    {
        $db = Database::getInstance()->getConnection();
        $bonusTouchUserIds = [];
        $subscriptionId = null;
        $transaction = null;
        $previousStatus = '';
        $gatewaySyncJobIds = [];
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT t.*, u.username, u.email
                FROM transactions t
                JOIN users u ON u.id = t.user_id
                WHERE t.gateway_reference = ? AND t.gateway = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$reference, $gateway]);
            $transaction = $stmt->fetch();
            if (!$transaction) {
                throw new \RuntimeException('Transaction not found.');
            }

            if ($eventId !== '') {
                if (!self::claimWebhookEvent($db, $gateway, $eventId)) {
                    $db->commit();
                    return [
                        'transaction_id' => (int)$transaction['id'],
                        'status' => (string)($transaction['status'] ?? ''),
                        'message' => 'Webhook event already processed.',
                    ];
                }
            }

            $previousStatus = (string)$transaction['status'];
            if (!self::isAllowedStatusTransition($previousStatus, $status)) {
                $db->commit();
                return [
                    'transaction_id' => (int)$transaction['id'],
                    'status' => $previousStatus,
                    'message' => 'Ignored stale or invalid payment status transition.',
                ];
            }

            if ($previousStatus === $status) {
                $db->commit();
                return [
                    'transaction_id' => (int)$transaction['id'],
                    'status' => $status,
                    'message' => 'Callback already applied.',
                ];
            }

            $update = $db->prepare("UPDATE transactions SET status = ? WHERE id = ? AND status = ?");
            $update->execute([$status, (int)$transaction['id'], $previousStatus]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Transaction status changed while processing callback.');
            }

            if ($status === 'completed') {
                $activation = self::activateSubscription($db, $transaction, $gateway);
                $subscriptionId = $activation['subscription_id'];
                $bonusTouchUserIds = array_merge(
                    $bonusTouchUserIds,
                    $activation['touch_user_ids'],
                    self::awardAffiliateCommission($db, $transaction, $gateway)
                );
                $gatewaySyncJobIds = array_merge($gatewaySyncJobIds, $activation['gateway_sync_job_ids'] ?? []);
            } elseif (in_array($status, ['refunded', 'denied'], true)) {
                $revoke = self::revokeSubscription($db, $transaction, $gateway);
                $bonusTouchUserIds = array_merge(
                    $bonusTouchUserIds,
                    $revoke['touch_user_ids'] ?? [],
                    self::reverseAffiliateCommission($db, $transaction, $gateway, $status)
                );
                $gatewaySyncJobIds = array_merge($gatewaySyncJobIds, $revoke['gateway_sync_job_ids'] ?? []);
            }

            if (in_array($status, ['completed', 'failed', 'denied', 'refunded'], true)) {
                CouponService::finalizeTransactionCoupon($db, (int)$transaction['id'], $status, $subscriptionId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if (!$transaction) {
            throw new \RuntimeException('Transaction not found.');
        }

        if ($bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'payment_status_applied',
                'gateway' => $gateway,
                'reference' => $reference,
                'status' => $status,
            ]);
        }

        $gatewaySyncResults = !empty($gatewaySyncJobIds)
            ? self::processGatewaySyncQueue(count($gatewaySyncJobIds), $gatewaySyncJobIds)
            : ['processed' => 0, 'completed' => 0, 'failed' => 0, 'failed_job_ids' => []];

        if (($gatewaySyncResults['failed'] ?? 0) > 0) {
            Logger::error('Payment status applied with unresolved upstream subscription sync', [
                'transaction_id' => (int)$transaction['id'],
                'gateway' => $gateway,
                'status' => $status,
                'failed_job_ids' => $gatewaySyncResults['failed_job_ids'] ?? [],
            ]);
        }

        try {
            self::sendPaymentStatusEmail($transaction, $status, $previousStatus, $gateway);
        } catch (\Throwable $e) {
            Logger::warning('payment status email failed after commit', [
                'transaction_id' => (int)$transaction['id'],
                'gateway' => $gateway,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            Logger::info('payment status applied', [
                'transaction_id' => (int)$transaction['id'],
                'gateway' => $gateway,
                'status' => $status,
                'previous_status' => $previousStatus,
            ]);
        } catch (\Throwable $e) {
            error_log('Payment status logging failed: ' . $e->getMessage());
        }

        return [
            'transaction_id' => (int)$transaction['id'],
            'status' => $status,
            'message' => 'Payment status applied.',
            'gateway_sync' => $gatewaySyncResults,
        ];
    }

    private static function activateSubscription($db, array $transaction, string $gateway): array
    {
        $userId = (int)$transaction['user_id'];
        $packageId = (int)$transaction['package_id'];
        $termDays = max(1, (int)($transaction['term_days'] ?? 30));
        $autoRenew = !empty($transaction['auto_renew']);
        $providerSubscriptionId = trim((string)($transaction['provider_subscription_id'] ?? ''));
        self::expireStaleActiveSubscriptions($db, $userId);
        $preserveSamePackageRecurring = !$autoRenew && self::hasLiveRecurringSubscriptionForPackage($db, $userId, $packageId);
        $baseTimestamp = time();
        if (!$autoRenew) {
            $carryForwardExpiry = self::futureExpiryForSamePackage($db, $userId, $packageId);
            if ($carryForwardExpiry !== null) {
                $baseTimestamp = max($baseTimestamp, strtotime($carryForwardExpiry));
            }
        }
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $termDays . ' days', $baseTimestamp));

        if ($gateway === 'stripe' && $autoRenew && $providerSubscriptionId !== '') {
            $details = self::fetchStripeSubscription($providerSubscriptionId);
            $expiresAt = date('Y-m-d H:i:s', (int)($details['current_period_end'] ?? strtotime('+' . $termDays . ' days')));
        } elseif ($gateway === 'paypal' && $autoRenew && $providerSubscriptionId !== '') {
            $details = self::fetchPayPalSubscription($providerSubscriptionId);
            $expiresAt = self::paypalSubscriptionExpiry($details);
        }

        $existingStmt = $db->prepare("
            SELECT id, package_id, gateway, provider_subscription_id
            FROM subscriptions
            WHERE user_id = ? AND " . self::liveOrPendingSubscriptionCondition() . "
            FOR UPDATE
        ");
        $existingStmt->execute([$userId]);
        $gatewaySyncJobIds = [];
        foreach ($existingStmt->fetchAll() as $existingSubscription) {
            $existingPackageId = (int)($existingSubscription['package_id'] ?? 0);
            $existingProviderSubscriptionId = trim((string)($existingSubscription['provider_subscription_id'] ?? ''));
            $existingGateway = strtolower(trim((string)($existingSubscription['gateway'] ?? '')));
            if ($preserveSamePackageRecurring && $existingPackageId === $packageId) {
                continue;
            }
            if ($existingProviderSubscriptionId === '' || $existingProviderSubscriptionId === $providerSubscriptionId) {
                continue;
            }
            if (in_array($existingGateway, ['stripe', 'paypal'], true)) {
                $gatewaySyncJobIds[] = self::queueGatewaySyncJob(
                    $db,
                    $existingGateway,
                    'cancel_subscription',
                    $existingProviderSubscriptionId,
                    (int)($existingSubscription['id'] ?? 0),
                    [
                        'source' => 'activate_subscription',
                        'replacement_reference' => (string)($transaction['gateway_reference'] ?? ''),
                    ]
                );
            }
        }

        if ($preserveSamePackageRecurring) {
            $db->prepare("
                UPDATE subscriptions
                SET status = 'cancelled',
                    auto_renew = 0,
                    updated_at = NOW()
                WHERE user_id = ?
                  AND " . self::liveOrPendingSubscriptionCondition() . "
                  AND package_id <> ?
            ")->execute([$userId, $packageId]);
        } else {
            $db->prepare("
                UPDATE subscriptions
                SET status = 'cancelled',
                    auto_renew = 0,
                    updated_at = NOW()
                WHERE user_id = ? AND " . self::liveOrPendingSubscriptionCondition() . "
            ")->execute([$userId]);
        }

        $db->prepare("
            INSERT INTO subscriptions
                (user_id, package_id, package_billing_option_id, coupon_id, coupon_code, original_amount, discount_amount, status, amount, currency, term_days, auto_renew, billing_period, gateway, gateway_reference, provider_subscription_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $userId,
            $packageId,
            $transaction['package_billing_option_id'] !== null ? (int)$transaction['package_billing_option_id'] : null,
            $transaction['coupon_id'] !== null ? (int)$transaction['coupon_id'] : null,
            $transaction['coupon_code'] ?: null,
            (float)($transaction['original_amount'] ?? $transaction['amount']),
            (float)($transaction['discount_amount'] ?? 0),
            (float)$transaction['amount'],
            (string)$transaction['currency'],
            $termDays,
            $autoRenew ? 1 : 0,
            self::billingPeriodForDays($termDays),
            $gateway,
            (string)$transaction['gateway_reference'],
            $providerSubscriptionId !== '' ? $providerSubscriptionId : null,
            $expiresAt,
        ]);
        $subscriptionId = (int)$db->lastInsertId();

        $db->prepare("UPDATE users SET package_id = ?, premium_expiry = ?, premium_started_at = COALESCE(premium_started_at, NOW()) WHERE id = ?")
            ->execute([$packageId, $expiresAt, $userId]);

        return [
            'touch_user_ids' => [$userId],
            'subscription_id' => $subscriptionId,
            'gateway_sync_job_ids' => $gatewaySyncJobIds,
        ];
    }

    private static function awardAffiliateCommission($db, array $transaction, string $gateway): array
    {
        if (!FeatureService::rewardsEnabled()) {
            return [];
        }

        (new RewardFraudService())->ensureSchema();

        $buyerStmt = $db->prepare("
            SELECT id, referrer_id, referrer_source, status, email_lookup, payment_method, payment_details
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $buyerStmt->execute([(int)$transaction['user_id']]);
        $buyer = $buyerStmt->fetch();
        if (
            !$buyer ||
            empty($buyer['referrer_id']) ||
            (string)($buyer['referrer_source'] ?? '') !== 'pps'
        ) {
            return [];
        }

        $sellerId = (int)$buyer['referrer_id'];
        if ($sellerId <= 0 || $sellerId === (int)$buyer['id']) {
            return [];
        }

        $sellerStmt = $db->prepare("
            SELECT u.id, u.referrer_id, u.status, u.email_lookup, u.payment_method, u.payment_details, u.monetization_model, p.ppd_enabled, p.pps_enabled
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $sellerStmt->execute([$sellerId]);
        $seller = $sellerStmt->fetch();
        if (!$seller || !AffiliateRewardService::isReferralRelationshipEligible($seller, $buyer)) {
            return [];
        }

        $sellerPercent = self::resolvePpsPercentForModel(
            (string)($seller['monetization_model'] ?? 'ppd'),
            [
                'pps_enabled' => (int)($seller['pps_enabled'] ?? 0),
                'ppd_enabled' => (int)($seller['ppd_enabled'] ?? 0),
            ]
        );
        if ($sellerPercent <= 0) {
            return [$sellerId];
        }

        $description = self::ppsRewardDescription($gateway, (string)$transaction['gateway_reference']);
        $gatewayReference = (string)$transaction['gateway_reference'];
        [$descriptionExact, $descriptionHeldLike] = self::ppsRewardMatchPatterns($gateway, $gatewayReference);

        $exists = $db->prepare("
            SELECT id
            FROM earnings
            WHERE user_id = ?
              AND type = 'pps_reward'
              AND (
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.gateway_reference')) = ?
                    OR description = ?
                    OR description LIKE ? ESCAPE '\\\\'
              )
            LIMIT 1
        ");
        $exists->execute([$sellerId, $gatewayReference, $descriptionExact, $descriptionHeldLike]);
        if ($exists->fetchColumn()) {
            return [$sellerId];
        }

        $sellerAmount = round(((float)$transaction['amount']) * ($sellerPercent / 100), 4);
        if ($sellerAmount <= 0) {
            return [$sellerId];
        }

        $holdDays = max(0, (int)Setting::get('affiliate_hold_days', (string)self::DEFAULT_AFFILIATE_HOLD_DAYS, 'rewards'));
        $status = $holdDays > 0 ? 'held' : 'cleared';
        $holdUntil = $holdDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$holdDays} days")) : null;
        $description = $holdDays > 0
            ? $description . sprintf(' (Held %d days)', $holdDays)
            : $description;

        $db->prepare("
            INSERT INTO earnings (user_id, amount, type, status, description, hold_until, metadata)
            VALUES (?, ?, 'pps_reward', ?, ?, ?, ?)
        ")->execute([
            $sellerId,
            $sellerAmount,
            $status,
            $description,
            $holdUntil,
            json_encode([
                'gateway' => $gateway,
                'gateway_reference' => (string)$transaction['gateway_reference'],
                'transaction_id' => isset($transaction['id']) ? (int)$transaction['id'] : null,
                'buyer_user_id' => (int)$buyer['id'],
                'kind' => 'pps_reward',
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $earningId = (int)$db->lastInsertId();

        $referrerId = AffiliateRewardService::awardReferralForUserEarning(
            $db,
            $sellerId,
            $sellerAmount,
            $earningId,
            $status,
            $holdUntil,
            $description
        );

        $touchIds = [$sellerId];
        if ($referrerId !== null && $referrerId > 0) {
            $touchIds[] = $referrerId;
        }

        return $touchIds;
    }

    private static function resolvePpsPercentForModel(string $model, ?array $package = null): int
    {
        $model = strtolower(trim($model));
        if (!MonetizationModelService::ppsEligible($model, $package)) {
            return 0;
        }

        if ($model === 'mixed') {
            $ppsBase = max(0, min(100, (int)Setting::get('pps_commission_percent', '50', 'rewards')));
            $mixedPercent = max(0, min(100, (int)Setting::get('mixed_pps_percent', '30', 'rewards')));
            return (int)round($ppsBase * ($mixedPercent / 100));
        }

        if ($model === 'pps') {
            return max(0, min(100, (int)Setting::get('pps_commission_percent', '50', 'rewards')));
        }

        return 0;
    }

    private static function reverseAffiliateCommission($db, array $transaction, string $gateway, string $status): array
    {
        $gatewayReference = (string)$transaction['gateway_reference'];
        [$descriptionExact, $descriptionHeldLike] = self::ppsRewardMatchPatterns($gateway, $gatewayReference);
        $stmt = $db->prepare("
            SELECT id, user_id, file_id, session_id, parent_earning_id, type, amount, ip_hash, risk_score,
                   risk_reasons_json, review_note, country_code, network_type, asn, metadata, status
            FROM earnings
            WHERE type = 'pps_reward'
              AND (
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.gateway_reference')) = ?
                    OR description = ?
                    OR description LIKE ? ESCAPE '\\\\'
              )
            ORDER BY id DESC
        ");
        $stmt->execute([$gatewayReference, $descriptionExact, $descriptionHeldLike]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            return [];
        }

        $cancel = $db->prepare("UPDATE earnings SET status = 'cancelled', hold_until = NULL WHERE id = ?");
        $reverse = $db->prepare("UPDATE earnings SET status = 'reversed', hold_until = NULL WHERE id = ?");
        $touchUserIds = [];

        foreach ($rows as $row) {
            $earningStatus = (string)($row['status'] ?? '');
            if (in_array($earningStatus, ['reversed', 'cancelled'], true)) {
                continue;
            }

            $touchUserIds[] = (int)($row['user_id'] ?? 0);

            if (in_array($earningStatus, ['held', 'pending'], true)) {
                $cancel->execute([(int)$row['id']]);
                AffiliateRewardService::syncReferralChildrenForParent($db, (int)$row['id'], 'cancelled');
            } else {
                $reversal = RewardService::ensureLedgerReversalEntry(
                    $db,
                    $row,
                    'PPS reward reversed because the related payment was ' . $status . '.',
                    null,
                    [
                        'source' => 'pps_payment_reversal',
                        'gateway' => $gateway,
                        'gateway_reference' => $gatewayReference,
                    ]
                );
                if (($reversal['id'] ?? 0) <= 0) {
                    continue;
                }
                $touchUserIds = array_merge(
                    $touchUserIds,
                    AffiliateRewardService::reverseReferralChildrenForParent(
                        $db,
                        (int)$row['id'],
                        'Referral commission reversed because the related PPS reward was reversed.',
                        null
                    )
                );
            }
        }

        return array_values(array_unique(array_filter($touchUserIds, static fn (int $id): bool => $id > 0)));
    }

    private static function revokeSubscription($db, array $transaction, string $gateway): array
    {
        $providerSubscriptionId = trim((string)($transaction['provider_subscription_id'] ?? ''));
        $gatewaySyncJobIds = [];
        $matchingSubscriptions = [];
        self::expireStaleActiveSubscriptions($db, (int)$transaction['user_id']);

        $matchParams = [
            (int)$transaction['user_id'],
            $gateway,
            (string)$transaction['gateway_reference'],
        ];
        $matchSql = "
            SELECT id, provider_subscription_id
            FROM subscriptions
            WHERE user_id = ? AND gateway = ? AND gateway_reference = ? AND " . self::liveOrPendingSubscriptionCondition() . "
            FOR UPDATE
        ";
        if ($providerSubscriptionId !== '') {
            $matchSql = "
                SELECT id, provider_subscription_id
                FROM subscriptions
                WHERE user_id = ? AND gateway = ? AND (
                    gateway_reference = ?
                    OR provider_subscription_id = ?
                ) AND " . self::liveOrPendingSubscriptionCondition() . "
                FOR UPDATE
            ";
            $matchParams[] = $providerSubscriptionId;
        }
        $matchStmt = $db->prepare($matchSql);
        $matchStmt->execute($matchParams);
        $matchingSubscriptions = $matchStmt->fetchAll() ?: [];

        $params = [
            (int)$transaction['user_id'],
            $gateway,
            (string)$transaction['gateway_reference'],
        ];
        $sql = "
            UPDATE subscriptions
            SET status = 'cancelled'
            WHERE user_id = ? AND gateway = ? AND gateway_reference = ? AND " . self::liveOrPendingSubscriptionCondition() . "
        ";
        if ($providerSubscriptionId !== '') {
            $sql = "
                UPDATE subscriptions
                SET status = 'cancelled'
                WHERE user_id = ? AND gateway = ? AND (
                    gateway_reference = ?
                    OR provider_subscription_id = ?
                ) AND " . self::liveOrPendingSubscriptionCondition() . "
            ";
            $params[] = $providerSubscriptionId;
        }
        $db->prepare($sql)->execute($params);

        if (in_array($gateway, ['stripe', 'paypal'], true)) {
            foreach ($matchingSubscriptions as $subscriptionRow) {
                $rowProviderSubscriptionId = trim((string)($subscriptionRow['provider_subscription_id'] ?? ''));
                if ($rowProviderSubscriptionId === '') {
                    continue;
                }

                $gatewaySyncJobIds[] = self::queueGatewaySyncJob(
                    $db,
                    $gateway,
                    'cancel_subscription',
                    $rowProviderSubscriptionId,
                    (int)($subscriptionRow['id'] ?? 0) ?: null,
                    [
                        'source' => 'revoke_subscription',
                        'reference' => (string)($transaction['gateway_reference'] ?? ''),
                    ]
                );
            }
        }

        self::restoreUserPackageFromActiveSubscriptionOrGuest($db, (int)$transaction['user_id']);

        return [
            'touch_user_ids' => [(int)$transaction['user_id']],
            'gateway_sync_job_ids' => $gatewaySyncJobIds,
        ];
    }

    public static function syncUserEntitlementsFromSubscriptions(\PDO $db, int $userId): void
    {
        self::restoreUserPackageFromActiveSubscriptionOrGuest($db, $userId);
    }

    private static function restoreUserPackageFromActiveSubscriptionOrGuest($db, int $userId): void
    {
        self::expireStaleActiveSubscriptions($db, $userId);

        $activeStmt = $db->prepare("
            SELECT package_id, expires_at, created_at
            FROM subscriptions
            WHERE user_id = ? AND status = 'active' AND expires_at > NOW()
            ORDER BY expires_at DESC, id DESC
            LIMIT 1
        ");
        $activeStmt->execute([$userId]);
        $active = $activeStmt->fetch();

        if ($active) {
            $db->prepare("UPDATE users SET package_id = ?, premium_expiry = ?, premium_started_at = COALESCE(premium_started_at, ?) WHERE id = ?")
                ->execute([(int)$active['package_id'], $active['expires_at'], $active['created_at'] ?? date('Y-m-d H:i:s'), $userId]);
            return;
        }

        $fallbackPackage = Package::getFreePackage() ?: Package::getGuestPackage();
        if ($fallbackPackage) {
            $db->prepare("UPDATE users SET package_id = ?, premium_expiry = NULL, premium_started_at = NULL WHERE id = ?")
                ->execute([(int)$fallbackPackage['id'], $userId]);
        } else {
            $db->prepare("UPDATE users SET premium_expiry = NULL, premium_started_at = NULL WHERE id = ?")
                ->execute([$userId]);
        }
    }

    private static function ppsRewardDescription(string $gateway, string $reference): string
    {
        return sprintf(
            'PPS reward for %s purchase %s',
            strtoupper($gateway),
            $reference
        );
    }

    private static function ppsRewardMatchPatterns(string $gateway, string $reference): array
    {
        $base = self::ppsRewardDescription($gateway, $reference);
        return [$base, self::escapeLikePattern($base) . ' (Held %'];
    }

    private static function escapeLikePattern(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '%' => '\%',
            '_' => '\_',
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
            'failed' => 'failed',
            'error' => 'failed',
            'denied' => 'denied',
            'declined' => 'denied',
            'refunded' => 'refunded',
        ];

        return $aliases[$status] ?? '';
    }

    private static function packageAmount(array $package, ?array $billingOption = null): float
    {
        if ($billingOption !== null && isset($billingOption['price'])) {
            return (float)$billingOption['price'];
        }
        return isset($package['price']) ? (float)$package['price'] : self::DEFAULT_PRICE;
    }

    private static function loadCouponMetadataForCheckout(\PDO $db, $couponId): ?array
    {
        $couponId = (int)$couponId;
        if ($couponId <= 0) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT id, discount_type, discount_value, percent_cap_amount, duration_type, duration_cycles
            FROM coupons
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$couponId]);
        return $stmt->fetch() ?: null;
    }

    private static function assertStripeRecurringCouponCompatible(array $coupon, int $termDays, float $discountAmount): void
    {
        if ($discountAmount <= 0) {
            return;
        }

        $durationType = (string)($coupon['duration_type'] ?? 'once');
        if ($durationType === 'cycles') {
            $months = self::cycleDurationMonths($termDays, (int)($coupon['duration_cycles'] ?? 1));
            if ($months === null) {
                throw new \RuntimeException('This coupon uses repeated renewal discounts that only map cleanly to monthly or yearly renewable terms. Use a monthly, quarterly, semiannual, or yearly term for recurring checkout.');
            }
        }

        if ((string)($coupon['discount_type'] ?? 'amount') === 'percent'
            && (float)($coupon['percent_cap_amount'] ?? 0) > 0
            && $durationType !== 'once') {
            throw new \RuntimeException('Percent coupons with a dollar cap are only supported for one-time introductory recurring discounts right now. Use a one-time purchase or remove the cap for multi-cycle recurring promotions.');
        }
    }

    private static function hasFutureSamePackageSubscription(int $userId, int $packageId): bool
    {
        if ($userId <= 0 || $packageId <= 0) {
            return false;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM subscriptions
            WHERE user_id = ?
              AND package_id = ?
              AND status = 'active'
              AND expires_at > NOW()
        ");
        $stmt->execute([$userId, $packageId]);
        return (int)($stmt->fetchColumn() ?: 0) > 0;
    }

    private static function lockCheckoutIntentScope(\PDO $db, int $userId): void
    {
        if ($userId <= 0) {
            throw new \RuntimeException('A valid user is required before starting checkout.');
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            throw new \RuntimeException('The account could not be loaded for checkout.');
        }
    }

    private static function assertNoPendingRecurringCheckout(\PDO $db, int $userId, int $packageId, bool $autoRenew): void
    {
        if (!$autoRenew || $userId <= 0 || $packageId <= 0) {
            return;
        }

        $stmt = $db->prepare("
            SELECT id
            FROM transactions
            WHERE user_id = ?
              AND package_id = ?
              AND auto_renew = 1
              AND status = 'pending'
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$userId, $packageId]);
        if ((int)($stmt->fetchColumn() ?: 0) > 0) {
            throw new \RuntimeException('A recurring checkout for this package is already pending. Finish or cancel that checkout before starting another auto-renew attempt.');
        }
    }

    private static function futureExpiryForSamePackage($db, int $userId, int $packageId): ?string
    {
        $stmt = $db->prepare("
            SELECT expires_at
            FROM subscriptions
            WHERE user_id = ?
              AND package_id = ?
              AND status = 'active'
              AND expires_at > NOW()
            ORDER BY expires_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $packageId]);
        $expiry = $stmt->fetchColumn();
        return $expiry !== false ? (string)$expiry : null;
    }

    private static function liveOrPendingSubscriptionCondition(string $alias = ''): string
    {
        $prefix = trim($alias);
        if ($prefix !== '' && !str_ends_with($prefix, '.')) {
            $prefix .= '.';
        }

        return "((" . $prefix . "status = 'active' AND " . $prefix . "expires_at > NOW()) OR " . $prefix . "status = 'pending')";
    }

    private static function hasLiveRecurringSubscriptionForPackage($db, int $userId, int $packageId): bool
    {
        $stmt = $db->prepare("
            SELECT id
            FROM subscriptions
            WHERE user_id = ?
              AND package_id = ?
              AND " . self::liveOrPendingSubscriptionCondition() . "
              AND auto_renew = 1
              AND provider_subscription_id IS NOT NULL
              AND provider_subscription_id <> ''
            LIMIT 1
        ");
        $stmt->execute([$userId, $packageId]);
        return (int)($stmt->fetchColumn() ?: 0) > 0;
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

        try {
            return hash_equals(self::callbackSignature($payload), $signature);
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    private static function callbackSecret(): string
    {
        $configured = trim((string)Setting::get('payment_callback_secret', ''));
        if ($configured !== '') {
            return $configured;
        }

        $fallback = \App\Service\SecurityService::getSecureAppKey();
        if ($fallback === null) {
            throw new \RuntimeException('Payment callback validation requires a rotated application key or explicit callback secret.');
        }

        return $fallback;
    }

    private static function assertTransactionOwnedByUser(string $gateway, string $reference, int $expectedUserId): void
    {
        if ($expectedUserId <= 0) {
            throw new \RuntimeException('Payment confirmation requires an authenticated account owner.');
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT user_id
            FROM transactions
            WHERE gateway = ? AND gateway_reference = ?
            LIMIT 1
        ");
        $stmt->execute([$gateway, $reference]);
        $ownerId = (int)$stmt->fetchColumn();
        if ($ownerId <= 0) {
            throw new \RuntimeException('Transaction not found.');
        }

        if ($ownerId !== $expectedUserId) {
            throw new \RuntimeException('You are not authorized to finalize this payment.');
        }
    }

    private static function claimWebhookEvent(\PDO $db, string $gateway, string $eventId): bool
    {
        if ($eventId === '') {
            throw new \RuntimeException('Webhook event ID is missing.');
        }

        $stmt = $db->prepare("
            INSERT IGNORE INTO payment_webhook_events (gateway, event_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$gateway, $eventId]);
        return $stmt->rowCount() === 1;
    }

    private static function isAllowedStatusTransition(string $previousStatus, string $newStatus): bool
    {
        if ($previousStatus === $newStatus) {
            return true;
        }

        return match ($previousStatus) {
            'pending' => in_array($newStatus, ['completed', 'failed', 'denied'], true),
            'completed' => in_array($newStatus, ['refunded', 'denied'], true),
            'failed', 'refunded', 'denied' => false,
            default => false,
        };
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
        $clientSecret = self::assertDecryptedSecret((string)Setting::getEncrypted('payment_paypal_client_secret', ''), 'PayPal client secret');
        if ($clientId === '') {
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

    private static function extractPayPalReference(array $payload): string
    {
        $candidates = [
            $payload['custom_id'] ?? null,
            $payload['reference_id'] ?? null,
            $payload['purchase_units'][0]['custom_id'] ?? null,
            $payload['purchase_units'][0]['reference_id'] ?? null,
            $payload['payments']['captures'][0]['custom_id'] ?? null,
            $payload['payments']['captures'][0]['reference_id'] ?? null,
            $payload['purchase_units'][0]['payments']['captures'][0]['custom_id'] ?? null,
            $payload['purchase_units'][0]['payments']['captures'][0]['reference_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function httpRequest(string $method, string $url, array $payload = [], array $headers = []): array
    {
        if (is_callable(self::$httpRequestHandler)) {
            $response = (self::$httpRequestHandler)($method, $url, $payload, $headers);
            if (!is_array($response)) {
                throw new \RuntimeException('Payment test HTTP handler must return an array response.');
            }
            return $response;
        }

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
                if ($payload === ['_empty_object' => true]) {
                    $body = '{}';
                } else {
                    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
                }
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
            $message = 'Gateway request failed.';
            if (is_array($decoded)) {
                $parts = [];
                foreach ([
                    $decoded['error_description'] ?? null,
                    $decoded['message'] ?? null,
                    $decoded['error'] ?? null,
                    $decoded['name'] ?? null,
                    $decoded['details'][0]['description'] ?? null,
                    $decoded['details'][0]['issue'] ?? null,
                    isset($decoded['debug_id']) ? ('debug_id=' . $decoded['debug_id']) : null,
                ] as $candidate) {
                    $candidate = trim((string)$candidate);
                    if ($candidate !== '' && !in_array($candidate, $parts, true)) {
                        $parts[] = $candidate;
                    }
                }
                if ($parts !== []) {
                    $message = implode(' | ', $parts);
                }
            }
            throw new \RuntimeException($message);
        }

        return is_array($decoded) ? $decoded : [];
    }

}
