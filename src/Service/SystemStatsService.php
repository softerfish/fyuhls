<?php

namespace App\Service;

use App\Core\Database;

/**
 * SystemStatsService - Enterprise Incremental Counter
 * 
 * Prevents expensive COUNT(*) queries by maintaining a summary table.
 * Used for dashboard metrics and large-scale pagination estimation.
 */
class SystemStatsService
{
    private static bool $schemaReady = false;

    public static function increment(string $key, int $amount = 1): void
    {
        self::update($key, $amount);
    }

    public static function decrement(string $key, int $amount = 1): void
    {
        self::update($key, -$amount);
    }

    private static function update(string $key, int $amount): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $validKeys = ['total_files', 'total_users', 'total_storage_bytes', 'pending_withdrawals', 'pending_reports'];
            
            if (!in_array($key, $validKeys)) return;
            self::ensureSchema($db);

            // Enterprise robust update: Create row if missing, else increment
            $stmt = $db->prepare("
                INSERT INTO system_stats (id, $key) VALUES (1, ?)
                ON DUPLICATE KEY UPDATE 
                    $key = GREATEST(0, CAST($key AS SIGNED) + ?), 
                    last_updated = CURRENT_TIMESTAMP
            ");
            $stmt->execute([max(0, $amount), $amount]);
        } catch (\Exception $e) {
            error_log("SystemStats: Update failed for $key: " . $e->getMessage());
        }
    }

    /**
     * Initial Seed - Only run during install or manual repair
     */
    public static function fullRebuild(): void
    {
        $dashboard = new DashboardService();
        $dashboard->refreshSystemStats();
    }

    private static function ensureSchema($db): void
    {
        if (self::$schemaReady || $db->inTransaction()) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS `system_stats` (
                `id` TINYINT UNSIGNED NOT NULL,
                `total_files` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `total_users` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `total_storage_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `pending_withdrawals` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `pending_reports` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }
}
