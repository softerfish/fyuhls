<?php

namespace App\Service;

class BackblazeB2Service
{
    public function discoverBuckets(string $keyId, string $applicationKey): array
    {
        $auth = $this->authorize($keyId, $applicationKey);
        $apiUrl = (string)($auth['apiInfo']['storageApi']['apiUrl'] ?? '');
        $s3ApiUrl = (string)($auth['apiInfo']['storageApi']['s3ApiUrl'] ?? '');

        if ($apiUrl === '') {
            throw new \RuntimeException('Backblaze did not return a storage API URL.');
        }

        $response = $this->request(
            'POST',
            $apiUrl . '/b2api/v3/b2_list_buckets',
            ['Authorization: ' . (string)$auth['authorizationToken']],
            [
                'accountId' => (string)$auth['accountId'],
            ]
        );

        $region = $this->extractRegionFromS3Url($s3ApiUrl);
        $endpoint = $region !== null ? ('https://s3.' . $region . '.backblazeb2.com') : '';

        $buckets = [];
        foreach (($response['buckets'] ?? []) as $bucket) {
            $buckets[] = [
                'bucket_id' => (string)($bucket['bucketId'] ?? ''),
                'bucket_name' => (string)($bucket['bucketName'] ?? ''),
                'bucket_type' => (string)($bucket['bucketType'] ?? ''),
                'region' => $region ?? 'us-west-004',
                'endpoint' => $endpoint,
            ];
        }

        return [
            'account_id' => (string)$auth['accountId'],
            'api_url' => $apiUrl,
            's3_api_url' => $s3ApiUrl,
            'region' => $region ?? 'us-west-004',
            'endpoint' => $endpoint !== '' ? $endpoint : 'https://s3.us-west-004.backblazeb2.com',
            'buckets' => $buckets,
        ];
    }

    public function applyFyuhlsCors(string $keyId, string $applicationKey, string $bucketName, array|string $origins): array
    {
        $origins = is_array($origins) ? $origins : [$origins];
        $normalizedOrigins = [];
        foreach ($origins as $origin) {
            $origin = trim((string)$origin);
            if ($origin === '' || !preg_match('#^https?://#i', $origin)) {
                continue;
            }

            $normalizedOrigins[] = rtrim($origin, '/');
        }

        $normalizedOrigins = array_values(array_unique($normalizedOrigins));
        if (empty($normalizedOrigins)) {
            throw new \RuntimeException('A valid Fyuhls origin is required before applying B2 CORS.');
        }

        $auth = $this->authorize($keyId, $applicationKey);
        $apiUrl = (string)($auth['apiInfo']['storageApi']['apiUrl'] ?? '');
        if ($apiUrl === '') {
            throw new \RuntimeException('Backblaze did not return a storage API URL.');
        }

        $bucket = $this->findBucket($apiUrl, (string)$auth['authorizationToken'], (string)$auth['accountId'], $bucketName);
        if ($bucket === null) {
            throw new \RuntimeException('The selected B2 bucket could not be found.');
        }

        $existingRules = is_array($bucket['corsRules'] ?? null) ? $bucket['corsRules'] : [];
        $preservedRules = array_values(array_filter($existingRules, static function ($rule) {
            return (string)($rule['corsRuleName'] ?? '') !== 'fyuhls-upload';
        }));

        $preservedRules[] = [
            'corsRuleName' => 'fyuhls-upload',
            'allowedOrigins' => $normalizedOrigins,
            'allowedHeaders' => ['*'],
            'allowedOperations' => ['s3_put', 's3_get', 's3_head'],
            'exposeHeaders' => ['ETag'],
            'maxAgeSeconds' => 3600,
        ];

        $updated = $this->request(
            'POST',
            $apiUrl . '/b2api/v3/b2_update_bucket',
            ['Authorization: ' . (string)$auth['authorizationToken']],
            [
                'accountId' => (string)$auth['accountId'],
                'bucketId' => (string)$bucket['bucketId'],
                'bucketType' => (string)$bucket['bucketType'],
                'corsRules' => $preservedRules,
            ]
        );

        return [
            'bucket_name' => (string)($updated['bucketName'] ?? $bucketName),
            'bucket_type' => (string)($updated['bucketType'] ?? ($bucket['bucketType'] ?? '')),
            'cors_rule_count' => count((array)($updated['corsRules'] ?? $preservedRules)),
            'applied_origin' => $normalizedOrigins[0],
            'applied_origins' => $normalizedOrigins,
        ];
    }

    private function authorize(string $keyId, string $applicationKey): array
    {
        $keyId = trim($keyId);
        $applicationKey = trim($applicationKey);
        if ($keyId === '' || $applicationKey === '') {
            throw new \RuntimeException('Both the Key ID and Application Key are required.');
        }

        $basicAuth = base64_encode($keyId . ':' . $applicationKey);
        return $this->request(
            'GET',
            'https://api.backblazeb2.com/b2api/v3/b2_authorize_account',
            ['Authorization: Basic ' . $basicAuth],
            null
        );
    }

    private function findBucket(string $apiUrl, string $authorizationToken, string $accountId, string $bucketName): ?array
    {
        $response = $this->request(
            'POST',
            $apiUrl . '/b2api/v3/b2_list_buckets',
            ['Authorization: ' . $authorizationToken],
            ['accountId' => $accountId]
        );

        foreach (($response['buckets'] ?? []) as $bucket) {
            if ((string)($bucket['bucketName'] ?? '') === $bucketName) {
                return $bucket;
            }
        }

        return null;
    }

    private function extractRegionFromS3Url(string $s3ApiUrl): ?string
    {
        $host = (string)parse_url($s3ApiUrl, PHP_URL_HOST);
        if ($host !== '' && preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/i', $host, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function request(string $method, string $url, array $headers, ?array $payload): array
    {
        $ch = curl_init($url);
        $method = strtoupper($method);
        $requestHeaders = $headers;
        $body = null;

        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $requestHeaders[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADER => true,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Backblaze request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawBody = substr($response, $headerSize);
        curl_close($ch);

        $decoded = json_decode($rawBody, true);
        if ($status >= 400) {
            $message = is_array($decoded)
                ? (string)($decoded['message'] ?? $decoded['code'] ?? 'Backblaze request failed.')
                : 'Backblaze request failed.';
            throw new \RuntimeException($message);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Backblaze returned an unexpected response.');
        }

        return $decoded;
    }
}
