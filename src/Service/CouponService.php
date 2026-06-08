<?php

namespace App\Service;

use App\Core\Database;
use App\Model\Package;
use App\Service\PackageTargetLockService;
use App\Service\Database\SchemaService;
use PDO;

class CouponService
{
    private const DISCOUNT_TYPES = ['amount', 'percent'];
    private const PURCHASE_SCOPES = ['new_only', 'renewal_only', 'both'];
    private const NEW_ACCOUNT_RULES = ['first_subscription', 'first_paid_subscription'];
    private const RENEWAL_RULES = ['active_only', 'active_or_returning'];
    private const DURATION_TYPES = ['once', 'cycles', 'forever'];
    private const REDEMPTION_STATUSES = ['reserved', 'redeemed', 'released', 'refunded'];
    private const MAX_PENDING_RESERVATIONS_PER_USER = 3;
    private const MAX_PENDING_RESERVATIONS_PER_USER_PER_COUPON = 1;

    public static function ensureTables(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        SchemaService::ensureTables([
            'transactions',
            'subscriptions',
            'coupons',
            'coupon_redemptions',
        ], false);
        $ensured = true;
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return '';
        }

        return preg_match('/^[A-Z0-9_-]+$/', $code) ? $code : '';
    }

    public static function normalizeFormData(array $input): array
    {
        $code = self::normalizeCode((string)($input['code'] ?? ''));
        $internalLabel = trim((string)($input['internal_label'] ?? ''));
        $discountType = in_array(($input['discount_type'] ?? ''), self::DISCOUNT_TYPES, true)
            ? (string)$input['discount_type']
            : 'amount';
        $purchaseScope = in_array(($input['purchase_scope'] ?? ''), self::PURCHASE_SCOPES, true)
            ? (string)$input['purchase_scope']
            : 'both';
        $newAccountRule = in_array(($input['new_account_rule'] ?? ''), self::NEW_ACCOUNT_RULES, true)
            ? (string)$input['new_account_rule']
            : 'first_paid_subscription';
        $renewalRule = in_array(($input['renewal_rule'] ?? ''), self::RENEWAL_RULES, true)
            ? (string)$input['renewal_rule']
            : 'active_or_returning';
        $durationType = in_array(($input['duration_type'] ?? ''), self::DURATION_TYPES, true)
            ? (string)$input['duration_type']
            : 'once';
        $packageIds = array_values(array_unique(array_map(
            static fn ($id): int => max(0, (int)$id),
            is_array($input['eligible_package_ids'] ?? null) ? $input['eligible_package_ids'] : []
        )));
        $packageIds = array_values(array_filter($packageIds, static fn (int $id): bool => $id > 0));
        $billingOptionIds = array_values(array_unique(array_map(
            static fn ($id): int => max(0, (int)$id),
            is_array($input['eligible_billing_option_ids'] ?? null) ? $input['eligible_billing_option_ids'] : []
        )));
        $billingOptionIds = array_values(array_filter($billingOptionIds, static fn (int $id): bool => $id > 0));

        return [
            'code' => $code,
            'internal_label' => $internalLabel,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'starts_at' => self::normalizeDateTime($input['starts_at'] ?? null),
            'expires_at' => self::normalizeDateTime($input['expires_at'] ?? null),
            'discount_type' => $discountType,
            'discount_value' => round(max(0, (float)($input['discount_value'] ?? 0)), 2),
            'percent_cap_amount' => self::nullableMoney($input['percent_cap_amount'] ?? null),
            'applies_to_all_paid' => !empty($input['applies_to_all_paid']) ? 1 : 0,
            'eligible_package_ids' => $packageIds,
            'eligible_billing_option_ids' => $billingOptionIds,
            'purchase_scope' => $purchaseScope,
            'new_account_rule' => $newAccountRule,
            'renewal_rule' => $renewalRule,
            'duration_type' => $durationType,
            'duration_cycles' => self::nullablePositiveInt($input['duration_cycles'] ?? null),
            'total_redemption_limit' => self::nullablePositiveInt($input['total_redemption_limit'] ?? null),
            'per_user_redemption_limit' => self::nullablePositiveInt($input['per_user_redemption_limit'] ?? null),
            'notes' => trim((string)($input['notes'] ?? '')),
        ];
    }

    public static function validateAdminPayload(array $data): array
    {
        $errors = [];

        if ($data['code'] === '') {
            $errors[] = 'Coupon code is required.';
        }
        if ($data['internal_label'] === '') {
            $errors[] = 'Internal label is required.';
        }
        if ($data['discount_value'] <= 0) {
            $errors[] = 'Discount value must be greater than zero.';
        }
        if ($data['discount_type'] === 'percent' && $data['discount_value'] > 100) {
            $errors[] = 'Percent discounts cannot exceed 100%.';
        }
        if ($data['discount_type'] === 'amount') {
            $data['percent_cap_amount'] = null;
        }
        if ($data['discount_type'] === 'percent' && $data['percent_cap_amount'] !== null && $data['percent_cap_amount'] <= 0) {
            $errors[] = 'Percent discount cap must be greater than zero when provided.';
        }
        if (!$data['applies_to_all_paid'] && $data['eligible_package_ids'] === []) {
            $errors[] = 'Choose at least one package or apply the coupon to all paid packages.';
        }

        $paidPackages = array_values(array_filter(Package::getAll(), static fn (array $pkg): bool => ($pkg['level_type'] ?? '') === 'paid'));
        $validPackageIds = [];
        $billingOptionPackages = [];
        foreach ($paidPackages as $package) {
            $packageId = (int)($package['id'] ?? 0);
            if ($packageId <= 0) {
                continue;
            }

            $validPackageIds[$packageId] = true;
            foreach (($package['billing_options'] ?? []) as $billingOption) {
                $billingOptionId = (int)($billingOption['id'] ?? 0);
                if ($billingOptionId > 0) {
                    $billingOptionPackages[$billingOptionId] = $packageId;
                }
            }
        }

        $selectedPackageIds = array_values(array_filter(
            array_map('intval', $data['eligible_package_ids'] ?? []),
            static fn (int $id): bool => isset($validPackageIds[$id])
        ));
        if (count($selectedPackageIds) !== count($data['eligible_package_ids'] ?? [])) {
            $errors[] = 'Choose valid paid packages for this coupon.';
        }
        $data['eligible_package_ids'] = $selectedPackageIds;

        $selectedBillingOptionIds = array_values(array_filter(
            array_map('intval', $data['eligible_billing_option_ids'] ?? []),
            static fn (int $id): bool => isset($billingOptionPackages[$id])
        ));
        if (count($selectedBillingOptionIds) !== count($data['eligible_billing_option_ids'] ?? [])) {
            $errors[] = 'Choose valid billing options for this coupon.';
        }

        if (!$data['applies_to_all_paid']) {
            foreach ($selectedBillingOptionIds as $billingOptionId) {
                $packageId = $billingOptionPackages[$billingOptionId] ?? 0;
                if ($packageId <= 0 || !in_array($packageId, $selectedPackageIds, true)) {
                    $errors[] = 'Selected billing options must belong to one of the coupon\'s allowed packages.';
                    break;
                }
            }
        }
        $data['eligible_billing_option_ids'] = $selectedBillingOptionIds;

        if ($data['starts_at'] !== null && $data['expires_at'] !== null && strtotime($data['expires_at']) <= strtotime($data['starts_at'])) {
            $errors[] = 'End time must be after the start time.';
        }
        if ($data['duration_type'] === 'cycles' && ($data['duration_cycles'] ?? 0) < 1) {
            $errors[] = 'First X cycles requires a cycle count of at least 1.';
        }
        if ($data['duration_type'] !== 'cycles') {
            $data['duration_cycles'] = null;
        }

        return [$errors, $data];
    }

    public static function getCouponsForAdmin(): array
    {
        self::ensureTables();

        $db = Database::getInstance()->getConnection();
        $rows = $db->query("
            SELECT
                c.*,
                COALESCE(stats.total_redemptions, 0) AS total_redemptions,
                COALESCE(stats.redeemed_count, 0) AS redeemed_count,
                COALESCE(stats.reserved_count, 0) AS reserved_count
            FROM coupons c
            LEFT JOIN (
                SELECT
                    coupon_id,
                    COUNT(*) AS total_redemptions,
                    SUM(CASE WHEN status = 'redeemed' THEN 1 ELSE 0 END) AS redeemed_count,
                    SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved_count
                FROM coupon_redemptions
                GROUP BY coupon_id
            ) stats ON stats.coupon_id = c.id
            ORDER BY c.updated_at DESC, c.id DESC
        ")->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row = self::hydrateCouponRow($row);
        }

        return $rows;
    }

    public static function findCouponForAdmin(int $id): ?array
    {
        self::ensureTables();

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM coupons WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::hydrateCouponRow($row) : null;
    }

    public static function recentRedemptionsForCoupon(int $couponId, int $limit = 50): array
    {
        self::ensureTables();

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT
                cr.*,
                u.username,
                p.name AS package_name
            FROM coupon_redemptions cr
            LEFT JOIN users u ON u.id = cr.user_id
            LEFT JOIN packages p ON p.id = cr.package_id
            WHERE cr.coupon_id = ?
            ORDER BY cr.created_at DESC, cr.id DESC
            LIMIT " . (int)max(1, min(200, $limit))
        );
        $stmt->execute([$couponId]);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row['username'] = EncryptionService::decrypt((string)($row['username'] ?? ''));
        }

        return $rows;
    }

    public static function saveCoupon(array $data, ?int $id = null, ?callable $auditWriter = null): int
    {
        self::ensureTables();

        [$errors, $data] = self::validateAdminPayload(self::normalizeFormData($data));
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }

        $db = Database::getInstance()->getConnection();
        $lockedPackageKeys = PackageTargetLockService::lockPackageIds($db, self::packageIdsForCouponLock($db, $data));
        $packageJson = $data['applies_to_all_paid'] ? null : json_encode($data['eligible_package_ids'], JSON_UNESCAPED_SLASHES);
        $billingOptionJson = $data['eligible_billing_option_ids'] === []
            ? null
            : json_encode($data['eligible_billing_option_ids'], JSON_UNESCAPED_SLASHES);

        try {
            $db->beginTransaction();
            [$lockedErrors, $data] = self::validateAdminPayload($data);
            if ($lockedErrors !== []) {
                throw new \RuntimeException(implode(' ', $lockedErrors));
            }
            $packageJson = $data['applies_to_all_paid'] ? null : json_encode($data['eligible_package_ids'], JSON_UNESCAPED_SLASHES);
            $billingOptionJson = $data['eligible_billing_option_ids'] === []
                ? null
                : json_encode($data['eligible_billing_option_ids'], JSON_UNESCAPED_SLASHES);

            if ($id !== null) {
                $existingStmt = $db->prepare("SELECT * FROM coupons WHERE id = ? LIMIT 1 FOR UPDATE");
                $existingStmt->execute([$id]);
                $existingRow = $existingStmt->fetch();
                if (!$existingRow) {
                    throw new \RuntimeException('Coupon not found.');
                }
                $existing = self::hydrateCouponRow($existingRow);

                $stmt = $db->prepare("
                    UPDATE coupons
                    SET code = ?, internal_label = ?, is_active = ?, starts_at = ?, expires_at = ?,
                        discount_type = ?, discount_value = ?, percent_cap_amount = ?, applies_to_all_paid = ?,
                        eligible_package_ids = ?, eligible_billing_option_ids = ?, purchase_scope = ?, new_account_rule = ?, renewal_rule = ?,
                        duration_type = ?, duration_cycles = ?, total_redemption_limit = ?, per_user_redemption_limit = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['code'],
                    $data['internal_label'],
                    $data['is_active'],
                    $data['starts_at'],
                    $data['expires_at'],
                    $data['discount_type'],
                    $data['discount_value'],
                    $data['percent_cap_amount'],
                    $data['applies_to_all_paid'],
                    $packageJson,
                    $billingOptionJson,
                    $data['purchase_scope'],
                    $data['new_account_rule'],
                    $data['renewal_rule'],
                    $data['duration_type'],
                    $data['duration_cycles'],
                    $data['total_redemption_limit'],
                    $data['per_user_redemption_limit'],
                    $data['notes'],
                    $id,
                ]);

                if (self::materialCheckoutRuleSignature($existing) !== self::materialCheckoutRuleSignature($data)) {
                    self::invalidatePendingTransactionsForCoupon($db, $id);
                }

                if ($auditWriter !== null) {
                    $auditWriter($db, $id, $data, 'updated');
                }
                $db->commit();
                return $id;
            }

            $stmt = $db->prepare("
                INSERT INTO coupons
                    (code, internal_label, is_active, starts_at, expires_at, discount_type, discount_value, percent_cap_amount,
                     applies_to_all_paid, eligible_package_ids, eligible_billing_option_ids, purchase_scope, new_account_rule, renewal_rule, duration_type,
                     duration_cycles, total_redemption_limit, per_user_redemption_limit, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['code'],
                $data['internal_label'],
                $data['is_active'],
                $data['starts_at'],
                $data['expires_at'],
                $data['discount_type'],
                $data['discount_value'],
                $data['percent_cap_amount'],
                $data['applies_to_all_paid'],
                $packageJson,
                $billingOptionJson,
                $data['purchase_scope'],
                $data['new_account_rule'],
                $data['renewal_rule'],
                $data['duration_type'],
                $data['duration_cycles'],
                $data['total_redemption_limit'],
                $data['per_user_redemption_limit'],
                $data['notes'],
                (int)(\App\Core\Auth::id() ?? 0) ?: null,
            ]);

            $couponId = (int)$db->lastInsertId();
            if ($auditWriter !== null) {
                $auditWriter($db, $couponId, $data, 'created');
            }
            $db->commit();

            return $couponId;
        } catch (\PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new \RuntimeException('That coupon code already exists. Choose a different code.');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        } finally {
            PackageTargetLockService::releaseLocks($db, $lockedPackageKeys);
        }
    }

    public static function previewForCheckout(
        int $userId,
        int $packageId,
        string $rawCode,
        ?int $billingOptionId = null,
        bool $autoRenew = false,
        string $gateway = ''
    ): array
    {
        self::ensureTables();

        $inputError = self::checkoutCodeError($rawCode);
        if ($inputError !== null) {
            return ['valid' => false, 'code' => '', 'message' => $inputError];
        }

        $code = self::normalizeCode($rawCode);
        if ($code === '') {
            return ['valid' => false, 'code' => '', 'message' => ''];
        }

        $db = Database::getInstance()->getConnection();
        $coupon = self::loadCouponByCode($db, $code);
        $package = Package::find($packageId);
        if (!$coupon || !$package) {
            return ['valid' => false, 'code' => $code, 'message' => 'That coupon code could not be found.'];
        }

        try {
            $billingOption = PaymentService::resolveCheckoutBillingOption($package, $billingOptionId);
            $preview = self::evaluateCoupon($db, $coupon, $userId, $package, $billingOption, true);
            PaymentService::assertCheckoutIntentAllowed(
                $userId,
                $package,
                $billingOption,
                $gateway,
                $autoRenew,
                $coupon,
                $preview['discount_amount'],
                $preview['final_amount']
            );
            return [
                'valid' => true,
                'code' => $code,
                'coupon' => $coupon,
                'discount_amount' => $preview['discount_amount'],
                'final_amount' => $preview['final_amount'],
                'original_amount' => $preview['original_amount'],
                'purchase_kind' => $preview['purchase_kind'],
                'message' => '',
            ];
        } catch (\RuntimeException $e) {
            return ['valid' => false, 'code' => $code, 'message' => $e->getMessage()];
        }
    }

    public static function applyToPendingTransaction(
        PDO $db,
        int $transactionId,
        int $userId,
        array $package,
        array $billingOption,
        float $packageAmount,
        string $rawCode
    ): array
    {
        $inputError = self::checkoutCodeError($rawCode);
        if ($inputError !== null) {
            throw new \RuntimeException($inputError);
        }

        $code = self::normalizeCode($rawCode);
        if ($code === '') {
            return [
                'coupon_id' => null,
                'coupon_code' => null,
                'original_amount' => round($packageAmount, 2),
                'discount_amount' => 0.0,
                'final_amount' => round($packageAmount, 2),
                'redemption_id' => null,
                'purchase_kind' => null,
            ];
        }

        self::lockUserReservationScope($db, $userId);
        $coupon = self::loadCouponByCode($db, $code, true);
        if (!$coupon) {
            throw new \RuntimeException('That coupon code could not be found.');
        }

        $evaluation = self::evaluateCoupon($db, $coupon, $userId, $package, $billingOption, true);
        self::assertReservationCapacity($db, (int)$coupon['id'], $userId);
        $redemptionSequence = self::countUserUsages($db, (int)$coupon['id'], $userId, true) + 1;

        $stmt = $db->prepare("
            INSERT INTO coupon_redemptions
                (coupon_id, user_id, package_id, transaction_id, coupon_code, purchase_kind, status, discount_type, discount_value,
                 discount_amount, currency, redemption_sequence)
            VALUES (?, ?, ?, ?, ?, ?, 'reserved', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$coupon['id'],
            $userId,
            (int)$package['id'],
            $transactionId,
            (string)$coupon['code'],
            $evaluation['purchase_kind'],
            (string)$coupon['discount_type'],
            (float)$coupon['discount_value'],
            $evaluation['discount_amount'],
            PaymentService::DEFAULT_CURRENCY,
            $redemptionSequence,
        ]);

        return [
            'coupon_id' => (int)$coupon['id'],
            'coupon_code' => (string)$coupon['code'],
            'original_amount' => $evaluation['original_amount'],
            'discount_amount' => $evaluation['discount_amount'],
            'final_amount' => $evaluation['final_amount'],
            'redemption_id' => (int)$db->lastInsertId(),
            'purchase_kind' => $evaluation['purchase_kind'],
        ];
    }

    public static function finalizeTransactionCoupon(PDO $db, int $transactionId, string $status, ?int $subscriptionId = null): void
    {
        self::ensureTables();

        $stmt = $db->prepare("
            SELECT *
            FROM coupon_redemptions
            WHERE transaction_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }

        $currentStatus = (string)($row['status'] ?? '');
        if ($status === 'completed') {
            $update = $db->prepare("
                UPDATE coupon_redemptions
                SET status = 'redeemed', subscription_id = COALESCE(?, subscription_id), redeemed_at = NOW(), released_at = NULL
                WHERE id = ?
            ");
            $update->execute([$subscriptionId, (int)$row['id']]);
            return;
        }

        if ($status === 'refunded' && $currentStatus === 'redeemed') {
            $update = $db->prepare("
                UPDATE coupon_redemptions
                SET status = 'refunded', released_at = NOW()
                WHERE id = ?
            ");
            $update->execute([(int)$row['id']]);
            return;
        }

        if (in_array($status, ['failed', 'denied'], true) && $currentStatus === 'reserved') {
            $update = $db->prepare("
                UPDATE coupon_redemptions
                SET status = 'released', released_at = NOW()
                WHERE id = ?
            ");
            $update->execute([(int)$row['id']]);
        }
    }

    private static function materialCheckoutRuleSignature(array $coupon): array
    {
        return [
            'code' => self::normalizeCode((string)($coupon['code'] ?? '')),
            'is_active' => !empty($coupon['is_active']) ? 1 : 0,
            'starts_at' => self::normalizeDateTime($coupon['starts_at'] ?? null),
            'expires_at' => self::normalizeDateTime($coupon['expires_at'] ?? null),
            'discount_type' => (string)($coupon['discount_type'] ?? 'amount'),
            'discount_value' => round(max(0, (float)($coupon['discount_value'] ?? 0)), 2),
            'percent_cap_amount' => self::nullableMoney($coupon['percent_cap_amount'] ?? null),
            'applies_to_all_paid' => !empty($coupon['applies_to_all_paid']) ? 1 : 0,
            'eligible_package_ids' => array_values(array_map('intval', $coupon['eligible_package_ids'] ?? [])),
            'eligible_billing_option_ids' => array_values(array_map('intval', $coupon['eligible_billing_option_ids'] ?? [])),
            'purchase_scope' => (string)($coupon['purchase_scope'] ?? 'both'),
            'new_account_rule' => (string)($coupon['new_account_rule'] ?? 'first_paid_subscription'),
            'renewal_rule' => (string)($coupon['renewal_rule'] ?? 'active_or_returning'),
            'duration_type' => (string)($coupon['duration_type'] ?? 'once'),
            'duration_cycles' => self::nullablePositiveInt($coupon['duration_cycles'] ?? null),
            'total_redemption_limit' => self::nullablePositiveInt($coupon['total_redemption_limit'] ?? null),
            'per_user_redemption_limit' => self::nullablePositiveInt($coupon['per_user_redemption_limit'] ?? null),
        ];
    }

    private static function invalidatePendingTransactionsForCoupon(PDO $db, int $couponId): int
    {
        if ($couponId <= 0) {
            return 0;
        }

        $stmt = $db->prepare("
            SELECT t.id
            FROM transactions t
            INNER JOIN coupon_redemptions cr ON cr.transaction_id = t.id
            WHERE cr.coupon_id = ?
              AND cr.status = 'reserved'
              AND t.status = 'pending'
            ORDER BY t.id ASC
            FOR UPDATE
        ");
        $stmt->execute([$couponId]);
        $transactionIds = array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn (int $id): bool => $id > 0));
        if ($transactionIds === []) {
            return 0;
        }

        $update = $db->prepare("UPDATE transactions SET status = 'denied' WHERE id = ? AND status = 'pending'");
        $invalidated = 0;
        foreach ($transactionIds as $transactionId) {
            $update->execute([$transactionId]);
            if ($update->rowCount() !== 1) {
                continue;
            }

            self::finalizeTransactionCoupon($db, $transactionId, 'denied');
            $invalidated++;
        }

        return $invalidated;
    }

    public static function releaseExpiredPendingReservations(PDO $db, int $olderThanMinutes): void
    {
        $stmt = $db->prepare("
            UPDATE coupon_redemptions cr
            JOIN transactions t ON t.id = cr.transaction_id
            SET cr.status = 'released', cr.released_at = NOW()
            WHERE cr.status = 'reserved'
              AND t.status = 'failed'
              AND t.created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$olderThanMinutes]);
    }

    public static function cancelPendingReservationForReference(string $gateway, string $reference, int $userId): bool
    {
        return PaymentService::failPendingTransactionByReference($gateway, $reference, $userId, 'failed');
    }

    private static function loadCouponByCode(PDO $db, string $code, bool $forUpdate = false): ?array
    {
        $sql = "SELECT * FROM coupons WHERE code = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $db->prepare($sql);
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row ? self::hydrateCouponRow($row) : null;
    }

    private static function evaluateCoupon(
        PDO $db,
        array $coupon,
        int $userId,
        array $package,
        array $billingOption,
        bool $countReserved
    ): array
    {
        if ((string)($package['level_type'] ?? '') !== 'paid') {
            throw new \RuntimeException('Coupons can only be used on paid packages.');
        }

        if ((int)($coupon['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('This coupon is currently inactive.');
        }

        $now = time();
        if (!empty($coupon['starts_at']) && strtotime((string)$coupon['starts_at']) > $now) {
            throw new \RuntimeException('This coupon is not active yet.');
        }
        if (!empty($coupon['expires_at']) && strtotime((string)$coupon['expires_at']) < $now) {
            throw new \RuntimeException('This coupon has expired.');
        }

        if (!(bool)($coupon['applies_to_all_paid'] ?? false)) {
            $eligible = array_map('intval', $coupon['eligible_package_ids'] ?? []);
            if (!in_array((int)$package['id'], $eligible, true)) {
                throw new \RuntimeException('This coupon does not apply to that premium package.');
            }
        }
        $eligibleBillingOptionIds = array_map('intval', $coupon['eligible_billing_option_ids'] ?? []);
        if ($eligibleBillingOptionIds !== []) {
            $selectedBillingOptionId = (int)($billingOption['id'] ?? 0);
            if ($selectedBillingOptionId <= 0 || !in_array($selectedBillingOptionId, $eligibleBillingOptionIds, true)) {
                throw new \RuntimeException('This coupon does not apply to that billing option.');
            }
        }

        $context = self::buildUserCouponContext($db, $userId);
        if (!empty($context['blocked_from_coupon_redemption'])) {
            throw new \RuntimeException('Accounts that can manage premium coupons or subscriptions cannot redeem coupons on themselves.');
        }

        $statuses = self::eligibilityStatuses($countReserved);
        $totalUsages = self::countCouponUsages($db, (int)$coupon['id'], $statuses);
        $userUsages = self::countUserUsages($db, (int)$coupon['id'], $userId, $countReserved);

        if (($coupon['total_redemption_limit'] ?? null) !== null && $totalUsages >= (int)$coupon['total_redemption_limit']) {
            throw new \RuntimeException('This coupon has reached its total redemption limit.');
        }

        $maxUserUsages = self::resolvePerUserAllowance($coupon);
        if (($coupon['per_user_redemption_limit'] ?? null) !== null) {
            $maxUserUsages = $maxUserUsages === null
                ? (int)$coupon['per_user_redemption_limit']
                : min($maxUserUsages, (int)$coupon['per_user_redemption_limit']);
        }
        if ($maxUserUsages !== null && $userUsages >= $maxUserUsages) {
            throw new \RuntimeException('You have already used this coupon as many times as it allows.');
        }

        $purchaseKind = self::resolvePurchaseKind($coupon, $context);

        $originalAmount = round((float)($billingOption['price'] ?? ($package['price'] ?? PaymentService::DEFAULT_PRICE)), 2);
        if ($originalAmount <= 0) {
            throw new \RuntimeException('This package does not support coupon checkout.');
        }

        $discountAmount = self::calculateDiscountAmount($coupon, $originalAmount);
        $finalAmount = round(max(0, $originalAmount - $discountAmount), 2);

        return [
            'purchase_kind' => $purchaseKind,
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
        ];
    }

    private static function assertReservationCapacity(PDO $db, int $couponId, int $userId): void
    {
        $perCouponStmt = $db->prepare("
            SELECT COUNT(*)
            FROM coupon_redemptions cr
            JOIN transactions t ON t.id = cr.transaction_id
            WHERE cr.user_id = ?
              AND cr.coupon_id = ?
              AND cr.status = 'reserved'
              AND t.status = 'pending'
        ");
        $perCouponStmt->execute([$userId, $couponId]);
        $perCouponPending = (int)($perCouponStmt->fetchColumn() ?: 0);
        if ($perCouponPending >= self::MAX_PENDING_RESERVATIONS_PER_USER_PER_COUPON) {
            throw new \RuntimeException('You already have a pending checkout using this coupon. Finish it or cancel it before starting another one.');
        }

        $overallStmt = $db->prepare("
            SELECT COUNT(*)
            FROM coupon_redemptions cr
            JOIN transactions t ON t.id = cr.transaction_id
            WHERE cr.user_id = ?
              AND cr.status = 'reserved'
              AND t.status = 'pending'
        ");
        $overallStmt->execute([$userId]);
        $overallPending = (int)($overallStmt->fetchColumn() ?: 0);
        if ($overallPending >= self::MAX_PENDING_RESERVATIONS_PER_USER) {
            throw new \RuntimeException('You already have too many pending coupon checkouts. Finish or cancel one before starting another.');
        }
    }

    private static function lockUserReservationScope(PDO $db, int $userId): void
    {
        $stmt = $db->prepare("
            SELECT id
            FROM users
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$userId]);
        if ((int)($stmt->fetchColumn() ?: 0) !== $userId) {
            throw new \RuntimeException('Account not found for coupon checkout.');
        }
    }

    private static function resolvePurchaseKind(array $coupon, array $context): string
    {
        $scope = (string)($coupon['purchase_scope'] ?? 'both');

        $newAllowed = false;
        if (in_array($scope, ['new_only', 'both'], true)) {
            $newAllowed = match ((string)($coupon['new_account_rule'] ?? 'first_paid_subscription')) {
                'first_subscription' => !$context['has_any_subscription'],
                'first_paid_subscription' => !$context['has_any_paid_subscription'],
                default => false,
            };
        }

        $renewalAllowed = false;
        if (in_array($scope, ['renewal_only', 'both'], true)) {
            $renewalAllowed = match ((string)($coupon['renewal_rule'] ?? 'active_or_returning')) {
                'active_only' => $context['has_active_subscription'],
                'active_or_returning' => $context['has_any_subscription'],
                default => false,
            };
        }

        if ($scope === 'new_only') {
            if (!$newAllowed) {
                throw new \RuntimeException('This coupon is only for qualifying new premium accounts.');
            }
            return 'new';
        }

        if ($scope === 'renewal_only') {
            if (!$renewalAllowed) {
                throw new \RuntimeException('This coupon is only for qualifying premium renewals.');
            }
            return 'renewal';
        }

        if ($renewalAllowed) {
            return 'renewal';
        }
        if ($newAllowed) {
            return 'new';
        }

        throw new \RuntimeException('Your account does not match this coupon\'s eligibility rules.');
    }

    private static function calculateDiscountAmount(array $coupon, float $originalAmount): float
    {
        $type = (string)($coupon['discount_type'] ?? 'amount');
        $value = round(max(0, (float)($coupon['discount_value'] ?? 0)), 2);

        if ($type === 'percent') {
            $discount = round($originalAmount * ($value / 100), 2);
            $cap = $coupon['percent_cap_amount'] ?? null;
            if ($cap !== null) {
                $discount = min($discount, round((float)$cap, 2));
            }
            return round(min($originalAmount, max(0, $discount)), 2);
        }

        return round(min($originalAmount, $value), 2);
    }

    private static function resolvePerUserAllowance(array $coupon): ?int
    {
        return match ((string)($coupon['duration_type'] ?? 'once')) {
            'once' => 1,
            // Multi-cycle coupons already carry their repeated discount window on the
            // subscription itself. Treat the coupon redemption as a single claim so a
            // buyer cannot restart fresh subscriptions to stretch "first X cycles"
            // into more discounted billing cycles than the coupon was meant to grant.
            'cycles' => 1,
            default => null,
        };
    }

    private static function countCouponUsages(PDO $db, int $couponId, array $statuses): int
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND status IN ($placeholders)");
        $stmt->execute(array_merge([$couponId], $statuses));
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private static function countUserUsages(PDO $db, int $couponId, int $userId, bool $countReserved): int
    {
        $statuses = self::eligibilityStatuses($countReserved);
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ? AND status IN ($placeholders)");
        $stmt->execute(array_merge([$couponId, $userId], $statuses));
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private static function eligibilityStatuses(bool $countReserved): array
    {
        return $countReserved
            ? ['reserved', 'redeemed', 'refunded']
            : ['redeemed', 'refunded'];
    }

    private static function buildUserCouponContext(PDO $db, int $userId): array
    {
        $userStmt = $db->prepare("
            SELECT id, role, status, is_super_admin, email_lookup, payment_method, payment_details
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $userStmt->execute([$userId]);
        $userRow = $userStmt->fetch() ?: [
            'id' => $userId,
            'role' => '',
            'status' => '',
            'is_super_admin' => 0,
            'email_lookup' => null,
            'payment_method' => null,
            'payment_details' => null,
        ];
        $canManagePaidOffers = self::userCanManagePaidOffers($db, $userRow);
        $linkedToCouponManagingStaff = self::isLinkedToCouponManagingStaff($db, $userRow);

        $subscriptionStmt = $db->prepare("
            SELECT
                COUNT(*) AS subscription_count,
                SUM(CASE WHEN s.status = 'active' AND s.expires_at >= NOW() THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN p.level_type = 'paid' THEN 1 ELSE 0 END) AS paid_subscription_count
            FROM subscriptions s
            LEFT JOIN packages p ON p.id = s.package_id
            WHERE s.user_id = ?
        ");
        $subscriptionStmt->execute([$userId]);
        $subscriptionRow = $subscriptionStmt->fetch() ?: ['subscription_count' => 0, 'active_count' => 0, 'paid_subscription_count' => 0];

        $paymentStmt = $db->prepare("
            SELECT COUNT(*)
            FROM transactions t
            INNER JOIN packages p ON p.id = t.package_id
            WHERE t.user_id = ?
              AND t.status = 'completed'
              AND p.level_type = 'paid'
        ");
        $paymentStmt->execute([$userId]);
        $completedPaidPurchaseCount = (int)($paymentStmt->fetchColumn() ?: 0);
        $paidSubscriptionHistoryCount = (int)($subscriptionRow['paid_subscription_count'] ?? 0);

        return [
            'blocked_from_coupon_redemption' => $canManagePaidOffers || $linkedToCouponManagingStaff,
            'has_any_subscription' => ((int)($subscriptionRow['subscription_count'] ?? 0)) > 0,
            'has_active_subscription' => ((int)($subscriptionRow['active_count'] ?? 0)) > 0,
            'has_any_paid_subscription' => ($paidSubscriptionHistoryCount + $completedPaidPurchaseCount) > 0,
        ];
    }

    private static function userCanManagePaidOffers(PDO $db, array $userRow): bool
    {
        $userId = (int)($userRow['id'] ?? 0);
        $role = strtolower(trim((string)($userRow['role'] ?? '')));
        if (!in_array($role, ['admin', 'moderator'], true) || (string)($userRow['status'] ?? '') !== 'active') {
            return false;
        }

        if ((int)($userRow['is_super_admin'] ?? 0) === 1) {
            return true;
        }

        $capabilities = StaffPermissionService::defaultCapabilitiesForRole($role);
        $managedCapabilities = [
            'subscriptions.manage' => !empty($capabilities['subscriptions.manage']),
            'coupons.manage' => !empty($capabilities['coupons.manage']),
        ];

        if ($userId > 0) {
            $stmt = $db->prepare("
                SELECT capability, is_allowed
                FROM staff_permissions
                WHERE user_id = ?
                  AND capability IN ('subscriptions.manage', 'coupons.manage')
            ");
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $capability = (string)($row['capability'] ?? '');
                if ($capability === '' || !array_key_exists($capability, $managedCapabilities)) {
                    continue;
                }
                $managedCapabilities[$capability] = (int)($row['is_allowed'] ?? 0) === 1;
            }
        }

        return !empty($managedCapabilities['subscriptions.manage']) || !empty($managedCapabilities['coupons.manage']);
    }

    private static function isLinkedToCouponManagingStaff(PDO $db, array $buyerRow): bool
    {
        $buyerId = (int)($buyerRow['id'] ?? 0);
        if ($buyerId <= 0) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT id, role, status, is_super_admin, email_lookup, payment_method, payment_details
            FROM users
            WHERE id <> ?
              AND status = 'active'
              AND role IN ('admin', 'moderator')
        ");
        $stmt->execute([$buyerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            if (!self::userCanManagePaidOffers($db, $candidate)) {
                continue;
            }
            if (AffiliateRewardService::accountsAppearLinked($buyerRow, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int>
     */
    private static function packageIdsForCouponLock(PDO $db, array $data): array
    {
        $packageIds = array_map('intval', $data['eligible_package_ids'] ?? []);
        $billingOptionIds = array_values(array_filter(
            array_map('intval', $data['eligible_billing_option_ids'] ?? []),
            static fn (int $id): bool => $id > 0
        ));

        if ($billingOptionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($billingOptionIds), '?'));
            $stmt = $db->prepare("SELECT DISTINCT package_id FROM package_billing_options WHERE id IN ($placeholders)");
            $stmt->execute($billingOptionIds);
            $packageIds = array_merge($packageIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
        }

        return PackageTargetLockService::normalizePackageIds($packageIds);
    }

    private static function hydrateCouponRow(array $row): array
    {
        $row['is_active'] = (int)($row['is_active'] ?? 0);
        $row['discount_value'] = (float)($row['discount_value'] ?? 0);
        $row['percent_cap_amount'] = $row['percent_cap_amount'] !== null ? (float)$row['percent_cap_amount'] : null;
        $row['applies_to_all_paid'] = (int)($row['applies_to_all_paid'] ?? 0);
        $row['duration_cycles'] = $row['duration_cycles'] !== null ? (int)$row['duration_cycles'] : null;
        $row['total_redemption_limit'] = $row['total_redemption_limit'] !== null ? (int)$row['total_redemption_limit'] : null;
        $row['per_user_redemption_limit'] = $row['per_user_redemption_limit'] !== null ? (int)$row['per_user_redemption_limit'] : null;
        $row['eligible_package_ids'] = self::decodePackageIds($row['eligible_package_ids'] ?? null);
        $row['eligible_billing_option_ids'] = self::decodePackageIds($row['eligible_billing_option_ids'] ?? null);
        return $row;
    }

    private static function decodePackageIds($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $decoded), static fn (int $id): bool => $id > 0));
    }

    private static function normalizeDateTime($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private static function nullableMoney($value): ?float
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $amount = round(max(0, (float)$value), 2);
        return $amount > 0 ? $amount : null;
    }

    private static function nullablePositiveInt($value): ?int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $number = (int)$value;
        return $number > 0 ? $number : null;
    }

    private static function checkoutCodeError(string $rawCode): ?string
    {
        $trimmed = trim($rawCode);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/[\s,;|]+/', $trimmed)) {
            return 'Enter one coupon code at a time.';
        }

        return self::normalizeCode($trimmed) === ''
            ? 'Coupon codes can only use letters, numbers, dashes, and underscores.'
            : null;
    }
}
