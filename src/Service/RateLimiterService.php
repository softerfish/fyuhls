<?php

namespace App\Service;

use App\Core\Database;
use PDOException;

class RateLimiterService {
    
    /**
     * Check if an action is within limits.
     * Returns true if allowed, false if rate limited.
     * 
     * @throws PDOException
     */
    public static function check(string $action, string $key, int $limit, int $windowSeconds): bool {
        try {
            return self::runCheck($action, $key, $limit, $windowSeconds);
        } catch (PDOException $e) {
            // If table doesn't exist (SQLSTATE 42S02), create it and retry once
            if ($e->getCode() === '42S02') {
                self::createTable();
                return self::runCheck($action, $key, $limit, $windowSeconds);
            }
            throw $e;
        }
    }

    private static function runCheck(string $action, string $key, int $limit, int $windowSeconds): bool {
        $db = Database::getInstance()->getConnection();
        $now = time();
        $cutoff = $now - $windowSeconds;

        $stmt = $db->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = ? AND identifier = ? AND created_at >= FROM_UNIXTIME(?)");
        $stmt->execute([$action, $key, $cutoff]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= $limit) {
            return false;
        }

        $stmt = $db->prepare("INSERT INTO rate_limits (action, identifier, created_at) VALUES (?, ?, FROM_UNIXTIME(?))");
        $stmt->execute([$action, $key, $now]);

        return true;
    }

    public static function cleanup(int $maxAgeSeconds = 86400): int {
        try {
            $db = Database::getInstance()->getConnection();
            $cutoff = time() - $maxAgeSeconds;
            $stmt = $db->prepare("DELETE FROM rate_limits WHERE created_at < FROM_UNIXTIME(?)");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return 0; // Table might not exist yet, ignore cleanup
        }
    }

    public static function createTable(): void {
        $db = Database::getInstance()->getConnection();
        $sql = "CREATE TABLE IF NOT EXISTS `rate_limits` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `action` VARCHAR(50) NOT NULL,
            `identifier` VARCHAR(128) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `action_identifier_created` (`action`, `identifier`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
    }
}
