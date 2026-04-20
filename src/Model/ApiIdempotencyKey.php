<?php

namespace App\Model;

use App\Core\Database;

class ApiIdempotencyKey
{
    public static function find(string $key, string $endpoint, string $actorKey, ?int $userId, ?int $tokenId): ?array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT *
            FROM api_idempotency_keys
            WHERE idem_key = ?
              AND endpoint = ?
              AND actor_key = ?
            LIMIT 1
        ");
        $stmt->execute([$key, $endpoint, $actorKey]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $key, string $endpoint, string $actorKey, ?int $userId, ?int $tokenId, string $requestHash): int
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        try {
            $stmt = $db->prepare("
                INSERT INTO api_idempotency_keys (idem_key, endpoint, actor_key, user_id, api_token_id, request_hash, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$key, $endpoint, $actorKey, $userId, $tokenId, $requestHash]);
            return (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            $existing = self::find($key, $endpoint, $actorKey, $userId, $tokenId);
            if ($existing) {
                return (int)$existing['id'];
            }

            throw $e;
        }
    }

    public static function complete(int $id, int $statusCode, array $response): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE api_idempotency_keys
            SET status = 'completed', response_code = ?, response_json = ?, completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$statusCode, json_encode($response, JSON_UNESCAPED_SLASHES), $id]);
    }

    private static function ensureSchema(): void
    {
        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS `api_idempotency_keys` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `idem_key` VARCHAR(128) NOT NULL,
                `endpoint` VARCHAR(80) NOT NULL,
                `actor_key` VARCHAR(96) NOT NULL,
                `user_id` BIGINT UNSIGNED NULL,
                `api_token_id` BIGINT UNSIGNED NULL,
                `request_hash` CHAR(64) NOT NULL,
                `status` ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
                `response_code` SMALLINT UNSIGNED NULL,
                `response_json` LONGTEXT NULL,
                `completed_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `api_idem_lookup` (`idem_key`, `endpoint`, `actor_key`),
                KEY `api_idem_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        try { $db->exec("ALTER TABLE `api_idempotency_keys` ADD COLUMN `actor_key` VARCHAR(96) NOT NULL DEFAULT '' AFTER `endpoint`"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `api_idempotency_keys` DROP INDEX `api_idem_lookup`"); } catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE `api_idempotency_keys` ADD UNIQUE KEY `api_idem_lookup` (`idem_key`, `endpoint`, `actor_key`)"); } catch (\Throwable $e) {}
    }
}
