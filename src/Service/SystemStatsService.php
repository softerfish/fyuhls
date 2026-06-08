<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Service\Database\SchemaService;

/**
 * SystemStatsService - Enterprise Incremental Counter
 *
 * Prevents expensive COUNT(*) queries by maintaining a summary table.
 * Used for dashboard metrics and large-scale pagination estimation.
 */
class SystemStatsService
{
    private static bool $schemaReady = false;
    private static bool $schemaUnavailable = false;

    public static function increment(string $key, int $amount = 1): void
    {
        self::update($key, $amount);
    }

    public static function decrement(string $key, int $amount = 1): void
    {
        self::update($key, -$amount);
    }

    public static function refreshCounter(string $key): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            if (!self::isValidKey($key) || !self::schemaAvailable($db)) {
                return;
            }

            $value = self::calculateLiveValue($db, $key);
            if ($value === null) {
                return;
            }

            $stmt = $db->prepare("
                INSERT INTO system_stats (id, $key) VALUES (1, ?)
                ON DUPLICATE KEY UPDATE
                    $key = VALUES($key),
                    last_updated = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$value]);
        } catch (\Exception $e) {
            Logger::warning('system stats counter refresh skipped', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function update(string $key, int $amount): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            if (!self::isValidKey($key)) return;
            if (!self::schemaAvailable($db)) {
                return;
            }

            // Enterprise robust update: Create row if missing, else increment
            $stmt = $db->prepare("
                INSERT INTO system_stats (id, $key) VALUES (1, ?)
                ON DUPLICATE KEY UPDATE
                    $key = GREATEST(0, CAST($key AS SIGNED) + ?),
                    last_updated = CURRENT_TIMESTAMP
            ");
            $stmt->execute([max(0, $amount), $amount]);
        } catch (\Exception $e) {
            Logger::warning('system stats counter update skipped', [
                'key' => $key,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function isValidKey(string $key): bool
    {
        return in_array($key, ['total_files', 'total_users', 'total_storage_bytes', 'pending_withdrawals', 'pending_reports'], true);
    }

    private static function calculateLiveValue($db, string $key): int
    {
        return match ($key) {
            'total_users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'total_files' => (int)$db->query("SELECT COUNT(*) FROM files WHERE status = 'active'")->fetchColumn(),
            'total_storage_bytes' => self::calculateActiveStorageBytes($db),
            'pending_withdrawals' => self::countIfTableExists($db, 'withdrawals', "SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'"),
            'pending_reports' => self::countIfTableExists($db, 'abuse_reports', "SELECT COUNT(*) FROM abuse_reports WHERE status = 'pending'")
                + self::countIfTableExists($db, 'dmca_reports', "SELECT COUNT(*) FROM dmca_reports WHERE status = 'pending'"),
            default => 0,
        };
    }

    private static function calculateActiveStorageBytes($db): int
    {
        if (!self::tableExists($db, 'stored_files')) {
            return 0;
        }

        return (int)$db->query("
            SELECT COALESCE(SUM(sf.file_size), 0)
            FROM files f
            JOIN stored_files sf ON f.stored_file_id = sf.id
            WHERE f.status = 'active'
        ")->fetchColumn();
    }

    private static function countIfTableExists($db, string $table, string $sql): int
    {
        if (!self::tableExists($db, $table)) {
            return 0;
        }

        return (int)$db->query($sql)->fetchColumn();
    }

    private static function tableExists($db, string $table): bool
    {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Initial Seed - Only run during install or manual repair
     */
    public static function fullRebuild(): void
    {
        $dashboard = new DashboardService();
        $dashboard->refreshSystemStats();
    }

    private static function schemaAvailable($db): bool
    {
        if (self::$schemaReady) {
            return true;
        }

        if (self::$schemaUnavailable || $db->inTransaction()) {
            return false;
        }

        try {
            SchemaService::ensureTables(['system_stats'], false);
            self::$schemaReady = true;
            self::$schemaUnavailable = false;
            return true;
        } catch (\Throwable $e) {
            self::$schemaUnavailable = true;
            Logger::warning('system stats runtime schema unavailable', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
