<?php

namespace App\Model;

use App\Core\Database;
use App\Core\Logger;
use App\Service\Database\SchemaService;

class PackageBillingOption
{
    private static bool $runtimeReady = false;
    private static bool $backfillReady = false;

    public static function ensureTable(bool $allowBackfill = false): void
    {
        self::ensureTableRuntime();
        if ($allowBackfill && !self::$backfillReady) {
            self::backfillDefaults(Database::getInstance()->getConnection());
            self::$backfillReady = true;
        }
    }

    public static function ensureTableRuntime(): void
    {
        if (self::$runtimeReady) {
            return;
        }

        SchemaService::ensureTables(['package_billing_options'], false);
        self::$runtimeReady = true;
    }

    public static function ensureTableForMaintenance(bool $allowBackfill = false): void
    {
        if (!self::$runtimeReady) {
            SchemaService::withRepairWindow(static function (): void {
                SchemaService::ensureTables(['package_billing_options'], true);
            });
            self::$runtimeReady = true;
        }

        if ($allowBackfill && !self::$backfillReady) {
            self::backfillDefaults(Database::getInstance()->getConnection());
            self::$backfillReady = true;
        }
    }

    public static function forPackage(int $packageId, bool $activeOnly = false): array
    {
        if (!self::runtimeAvailable()) {
            return [];
        }
        $db = Database::getInstance()->getConnection();
        $sql = "SELECT * FROM package_billing_options WHERE package_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY display_order ASC, term_days ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$packageId]);
        return $stmt->fetchAll() ?: [];
    }

    public static function groupedForPackages(array $packageIds, bool $activeOnly = false): array
    {
        if (!self::runtimeAvailable()) {
            return [];
        }
        $packageIds = array_values(array_filter(array_map('intval', $packageIds), static fn (int $id): bool => $id > 0));
        if ($packageIds === []) {
            return [];
        }
        $db = Database::getInstance()->getConnection();
        $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
        $sql = "SELECT * FROM package_billing_options WHERE package_id IN ($placeholders)";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY package_id ASC, display_order ASC, term_days ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($packageIds);
        $grouped = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $grouped[(int)$row['package_id']][] = $row;
        }
        return $grouped;
    }

    public static function syncForPackage(int $packageId, array $options): void
    {
        self::ensureTableForMaintenance(true);
        $db = Database::getInstance()->getConnection();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $existingStmt = $db->prepare("SELECT id FROM package_billing_options WHERE package_id = ?");
            $existingStmt->execute([$packageId]);
            $existingIds = array_map('intval', $existingStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

            $keptIds = [];
            foreach ($options as $index => $option) {
                $payload = [
                    (string)($option['option_label'] ?? '') !== '' ? (string)$option['option_label'] : null,
                    number_format((float)($option['price'] ?? 0), 2, '.', ''),
                    max(1, (int)($option['term_days'] ?? 30)),
                    !empty($option['renewal_enabled']) ? 1 : 0,
                    !empty($option['is_active']) ? 1 : 0,
                    (int)($option['display_order'] ?? $index),
                ];

                $optionId = (int)($option['id'] ?? 0);
                if ($optionId > 0 && in_array($optionId, $existingIds, true)) {
                    $stmt = $db->prepare("
                        UPDATE package_billing_options
                        SET option_label = ?, price = ?, term_days = ?, renewal_enabled = ?, is_active = ?, display_order = ?
                        WHERE id = ? AND package_id = ?
                    ");
                    $stmt->execute(array_merge($payload, [$optionId, $packageId]));
                    $keptIds[] = $optionId;
                    continue;
                }

                $stmt = $db->prepare("
                    INSERT INTO package_billing_options
                        (package_id, option_label, price, term_days, renewal_enabled, is_active, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute(array_merge([$packageId], $payload));
                $keptIds[] = (int)$db->lastInsertId();
            }

            $deleteIds = array_values(array_diff($existingIds, $keptIds));
            if ($deleteIds !== []) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $params = array_merge([$packageId], $deleteIds);
                $db->prepare("DELETE FROM package_billing_options WHERE package_id = ? AND id IN ($placeholders)")
                    ->execute($params);
            }

            if ($ownTransaction && $db->inTransaction()) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function backfillDefaults($db): void
    {
        $packages = $db->query("
            SELECT p.id, p.price, p.subscription_term_days, p.renewal_enabled
            FROM packages p
            LEFT JOIN package_billing_options bo ON bo.package_id = p.id
            WHERE p.level_type = 'paid'
              AND p.price > 0
            GROUP BY p.id
            HAVING COUNT(bo.id) = 0
        ")->fetchAll() ?: [];

        if ($packages === []) {
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO package_billing_options
                (package_id, option_label, price, term_days, renewal_enabled, is_active, display_order)
            VALUES (?, NULL, ?, ?, ?, 1, 0)
        ");
        foreach ($packages as $package) {
            $stmt->execute([
                (int)$package['id'],
                number_format((float)($package['price'] ?? 0), 2, '.', ''),
                max(1, (int)($package['subscription_term_days'] ?? 30)),
                !empty($package['renewal_enabled']) ? 1 : 0,
            ]);
        }
    }

    public static function runtimeAvailable(): bool
    {
        try {
            self::ensureTableRuntime();
            return true;
        } catch (\Throwable $e) {
            Logger::warning('package billing option runtime schema unavailable', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
