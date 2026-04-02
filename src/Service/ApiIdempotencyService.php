<?php

namespace App\Service;

use App\Model\ApiIdempotencyKey;

class ApiIdempotencyService
{
    public function begin(?string $key, string $endpoint, string $actorKey, ?int $userId, ?int $tokenId, array $payload): ?array
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $existing = ApiIdempotencyKey::find($key, $endpoint, $actorKey, $userId, $tokenId);
        if ($existing) {
            if ((string)$existing['request_hash'] !== $requestHash) {
                throw new \RuntimeException('Idempotency key was reused with a different request payload.');
            }

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

        return [
            'id' => ApiIdempotencyKey::create($key, $endpoint, $actorKey, $userId, $tokenId, $requestHash),
            'replay' => false,
            'pending' => false,
        ];
    }

    public function complete(?array $state, int $statusCode, array $response): void
    {
        if (!$state || empty($state['id'])) {
            return;
        }

        ApiIdempotencyKey::complete((int)$state['id'], $statusCode, $response);
    }
}
