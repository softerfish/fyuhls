<?php

namespace App\Model;

use App\Core\Database;
use App\Service\Database\SchemaService;
use PDO;

class Package
{
    private static bool $runtimeColumnsChecked = false;
    private const LEGACY_SYSTEM_PACKAGE_BANDWIDTH_REPAIRS = [
        [
            'target_bytes' => 5368709120,
            'match' => [
                'level_type' => 'guest',
                'name' => 'Guest',
                'max_upload_size' => 104857600,
                'max_daily_downloads' => 5,
                'download_speed' => 512000,
                'concurrent_uploads' => 1,
                'show_ads' => 1,
            ],
        ],
        [
            'target_bytes' => 21474836480,
            'match' => [
                'level_type' => 'free',
                'name' => 'Free User',
                'max_storage_bytes' => 5368709120,
                'max_upload_size' => 524288000,
                'max_daily_downloads' => 20,
                'download_speed' => 1048576,
                'concurrent_uploads' => 2,
                'show_ads' => 1,
            ],
        ],
    ];

    public static function isSystemPackage(array $package): bool
    {
        return self::isSystemLevelType((string)($package['level_type'] ?? ''));
    }

    public static function isSystemLevelType(string $levelType): bool
    {
        return in_array(strtolower(trim($levelType)), ['guest', 'admin'], true);
    }

    public static function subscriptionTermDays(array $package): int
    {
        $days = (int)($package['subscription_term_days'] ?? 30);
        return $days > 0 ? $days : 30;
    }

    public static function renewalEnabled(array $package): bool
    {
        return !empty($package['renewal_enabled']) && (($package['level_type'] ?? '') === 'paid');
    }

    public static function find(int $id): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $stmt = $db->prepare("SELECT * FROM packages WHERE id = ?");
        $stmt->execute([$id]);
        $package = $stmt->fetch() ?: null;
        if ($package) {
            $package['billing_options'] = PackageBillingOption::forPackage((int)$package['id']);
        }
        return $package;
    }

    public static function getGuestPackage(): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $stmt = $db->query("SELECT * FROM packages WHERE level_type = 'guest' LIMIT 1");
        $package = $stmt->fetch() ?: null;
        if ($package) {
            $package['billing_options'] = PackageBillingOption::forPackage((int)$package['id']);
        }
        return $package;
    }

    public static function getUserPackage(int $userId): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $stmt = $db->prepare("
            SELECT p.* FROM packages p
            JOIN users u ON u.package_id = p.id
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $package = $stmt->fetch();
        if ($package) {
            $package['billing_options'] = PackageBillingOption::forPackage((int)$package['id']);
            return $package;
        }
        return self::getGuestPackage();
    }

    public static function getFreePackage(): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $stmt = $db->query("SELECT * FROM packages WHERE level_type = 'free' LIMIT 1");
        $package = $stmt->fetch() ?: null;
        if ($package) {
            $package['billing_options'] = PackageBillingOption::forPackage((int)$package['id']);
        }
        return $package;
    }

    public static function getAll(): array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $packages = $db->query("SELECT * FROM packages ORDER BY id ASC")->fetchAll() ?: [];
        $groupedOptions = PackageBillingOption::groupedForPackages(array_map(static fn(array $pkg): int => (int)$pkg['id'], $packages));
        foreach ($packages as &$package) {
            $package['billing_options'] = $groupedOptions[(int)$package['id']] ?? [];
        }
        unset($package);
        return $packages;
    }

    public static function update(int $id, array $data): bool
    {
        return self::updateForActor($id, $data, 0, null, false);
    }

    public static function updateForActor(int $id, array $data, int $actorId = 0, ?string $actorRole = null, bool $actorIsSuperAdmin = false): bool
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $package = self::find($id);
        if (!$package) {
            return false;
        }

        self::assertSystemPackageUpdateAllowed($package, $actorId, $actorRole, $actorIsSuperAdmin);

        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        $values[] = $id;

        $sql = "UPDATE packages SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);

        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_map(static fn ($field) => $data[$field], $fields);

        $sql = "INSERT INTO packages (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        return (int)$db->lastInsertId();
    }

    public static function repairLegacySystemPackageBandwidthDefaults(PDO $db): int
    {
        $updatedRows = 0;

        foreach (self::LEGACY_SYSTEM_PACKAGE_BANDWIDTH_REPAIRS as $repair) {
            $match = is_array($repair['match'] ?? null) ? $repair['match'] : [];
            if ($match === []) {
                continue;
            }

            $conditions = [];
            $params = [(int)($repair['target_bytes'] ?? 0)];

            foreach ($match as $column => $value) {
                $conditions[] = $column . ' = ?';
                $params[] = $value;
            }

            $sql = 'UPDATE packages SET max_daily_downloads = ? WHERE ' . implode(' AND ', $conditions);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $updatedRows += $stmt->rowCount();
        }

        return $updatedRows;
    }

    private static function ensureRuntimeColumns($db): void
    {
        if (self::$runtimeColumnsChecked) {
            return;
        }

        SchemaService::ensureTables(['packages'], false);
        self::$runtimeColumnsChecked = true;
    }

    private static function assertSystemPackageUpdateAllowed(array $package, int $actorId, ?string $actorRole, bool $actorIsSuperAdmin): void
    {
        if (!self::isSystemPackage($package)) {
            return;
        }

        if ($actorIsSuperAdmin) {
            return;
        }

        throw new \RuntimeException('Guest and Admin packages are protected system plans and can only be edited by a super admin.');
    }
}
