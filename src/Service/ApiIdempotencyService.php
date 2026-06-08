<?php

namespace App\Service;

use App\Core\Logger;
use App\Model\ApiIdempotencyKey;

class ApiIdempotencyService
{
    public const STORAGE_UNAVAILABLE_MESSAGE = 'Upload replay protection is temporarily unavailable. Retry after an administrator repairs the upload API storage.';

    public function begin(?string $key, string $endpoint, string $actorKey, ?int $userId, ?int $tokenId, array $payload): ?array
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        try {
            $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $existing = ApiIdempotencyKey::find($key, $endpoint, $actorKey, $userId, $tokenId);
            if ($existing) {
                if ((string)$existing['request_hash'] !== $requestHash) {
                    throw new \RuntimeException('Idempotency key was reused with a different request payload.');
                }

                if (ApiIdempotencyKey::isPendingStale($existing)) {
                    ApiIdempotencyKey::release((int)$existing['id']);
                    $existing = null;
                }
            }

            if ($existing) {
                if (($existing['status'] ?? '') === 'completed' && !empty($existing['response_json'])) {
                    return [
                        'id' => (int)$existing['id'],
                        'replay' => true,
                        'status_code' => (int)($existing['response_code'] ?? 200),
                        'payload' => json_decode((string)$existing['response_json'], true) ?: [],
                    ];
                }

                return [
                    'id' => (int)$existing['id'],
                    'replay' => false,
                    'pending' => true,
                ];
            }

            $created = ApiIdempotencyKey::create($key, $endpoint, $actorKey, $userId, $tokenId, $requestHash);
            if (!empty($created['created'])) {
                return [
                    'id' => (int)$created['id'],
                    'replay' => false,
                    'pending' => false,
                ];
            }

            if (($created['request_hash'] ?? '') !== $requestHash) {
                throw new \RuntimeException('Idempotency key was reused with a different request payload.');
            }

            if (($created['status'] ?? '') === 'completed' && !empty($created['response_json'])) {
                return [
                    'id' => (int)$created['id'],
                    'replay' => true,
                    'status_code' => (int)($created['response_code'] ?? 200),
                    'payload' => json_decode((string)$created['response_json'], true) ?: [],
                ];
            }

            return [
                'id' => (int)$created['id'],
                'replay' => false,
                'pending' => true,
            ];
        } catch (\Throwable $e) {
            Logger::warning('api idempotency storage unavailable during runtime request', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(self::STORAGE_UNAVAILABLE_MESSAGE, 0, $e);
        }
    }

    public function release(?array $state): void
    {
        if (!$state || empty($state['id'])) {
            return;
        }

        try {
            ApiIdempotencyKey::release((int)$state['id']);
        } catch (\Throwable $e) {
            Logger::warning('api idempotency release storage unavailable during runtime request', [
                'id' => (int)$state['id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function complete(?array $state, int $statusCode, array $response): void
    {
        if (!$state || empty($state['id'])) {
            return;
        }

        try {
            ApiIdempotencyKey::complete((int)$state['id'], $statusCode, $response);
        } catch (\Throwable $e) {
            Logger::warning('api idempotency completion storage unavailable during runtime request', [
                'id' => (int)$state['id'],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
