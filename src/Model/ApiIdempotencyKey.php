<?php

namespace App\Model;

use App\Core\Database;
use App\Service\Database\SchemaService;

class ApiIdempotencyKey
{
    public static function stalePendingTimeoutSeconds(): int
    {
        return 300;
    }

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

    public static function create(string $key, string $endpoint, string $actorKey, ?int $userId, ?int $tokenId, string $requestHash): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        try {
            $stmt = $db->prepare("
                INSERT INTO api_idempotency_keys (idem_key, endpoint, actor_key, user_id, api_token_id, request_hash, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$key, $endpoint, $actorKey, $userId, $tokenId, $requestHash]);
            return [
                'id' => (int)$db->lastInsertId(),
                'created' => true,
            ];
        } catch (\Throwable $e) {
            $existing = self::find($key, $endpoint, $actorKey, $userId, $tokenId);
            if ($existing) {
                return [
                    'id' => (int)$existing['id'],
                    'created' => false,
                    'status' => (string)($existing['status'] ?? ''),
                    'request_hash' => (string)($existing['request_hash'] ?? ''),
                    'response_code' => (int)($existing['response_code'] ?? 200),
                    'response_json' => (string)($existing['response_json'] ?? ''),
                ];
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

    public static function release(int $id): void
    {
        self::ensureSchema();
        if ($id <= 0) {
            return;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            DELETE FROM api_idempotency_keys
            WHERE id = ?
              AND status = 'pending'
        ");
        $stmt->execute([$id]);
    }

    public static function isPendingStale(array $row): bool
    {
        if (($row['status'] ?? '') !== 'pending') {
            return false;
        }

        $createdAt = strtotime((string)($row['created_at'] ?? ''));
        if ($createdAt === false) {
            return false;
        }

        return (time() - $createdAt) >= self::stalePendingTimeoutSeconds();
    }

    private static function ensureSchema(): void
    {
        SchemaService::ensureTables(['api_idempotency_keys'], false);
    }
}
