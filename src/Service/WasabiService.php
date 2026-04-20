<?php

namespace App\Service;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

class WasabiService
{
    private const FYUHLS_CORS_RULE_ID = 'fyuhls-upload';

    public function discoverBuckets(string $accessKey, string $secretKey, string $region = 'us-east-1', string $endpoint = ''): array
    {
        $client = $this->makeClient($accessKey, $secretKey, $region, $endpoint);

        try {
            $response = $client->listBuckets();
        } catch (AwsException $e) {
            throw new \RuntimeException($this->extractAwsMessage($e, 'Wasabi bucket discovery failed.'));
        }

        $resolvedRegion = $this->normalizeRegion($region);
        $resolvedEndpoint = $this->normalizeEndpoint($endpoint, $resolvedRegion);

        $buckets = [];
        foreach ((array)($response['Buckets'] ?? []) as $bucket) {
            $bucketName = trim((string)($bucket['Name'] ?? ''));
            if ($bucketName === '') {
                continue;
            }

            $buckets[] = [
                'bucket_name' => $bucketName,
                'bucket_type' => 'private',
                'region' => $resolvedRegion,
                'endpoint' => $resolvedEndpoint,
            ];
        }

        return [
            'region' => $resolvedRegion,
            'endpoint' => $resolvedEndpoint,
            'buckets' => $buckets,
        ];
    }

    public function applyFyuhlsCors(string $accessKey, string $secretKey, string $bucketName, array|string $origins, string $region = 'us-east-1', string $endpoint = ''): array
    {
        $bucketName = trim($bucketName);
        if ($bucketName === '') {
            throw new \RuntimeException('The Wasabi bucket name is required before Fyuhls can apply CORS.');
        }

        $normalizedOrigins = $this->normalizeOrigins($origins);
        if (empty($normalizedOrigins)) {
            throw new \RuntimeException('A valid Fyuhls origin is required before applying Wasabi CORS.');
        }

        $client = $this->makeClient($accessKey, $secretKey, $region, $endpoint);

        $preservedRules = $this->getExistingCorsRules($client, $bucketName);
        $preservedRules = array_values(array_filter($preservedRules, function (array $rule): bool {
            return (string)($rule['ID'] ?? '') !== self::FYUHLS_CORS_RULE_ID;
        }));

        $preservedRules[] = [
            'ID' => self::FYUHLS_CORS_RULE_ID,
            'AllowedHeaders' => ['*'],
            'AllowedMethods' => ['PUT', 'GET', 'HEAD'],
            'AllowedOrigins' => $normalizedOrigins,
            'ExposeHeaders' => ['ETag'],
            'MaxAgeSeconds' => 3600,
        ];

        try {
            $client->putBucketCors([
                'Bucket' => $bucketName,
                'CORSConfiguration' => [
                    'CORSRules' => $preservedRules,
                ],
            ]);
        } catch (AwsException $e) {
            throw new \RuntimeException($this->extractAwsMessage($e, 'Wasabi CORS update failed.'));
        }

        return [
            'bucket_name' => $bucketName,
            'cors_rule_count' => count($preservedRules),
            'applied_origin' => $normalizedOrigins[0],
            'applied_origins' => $normalizedOrigins,
            'region' => $this->normalizeRegion($region),
            'endpoint' => $this->normalizeEndpoint($endpoint, $this->normalizeRegion($region)),
        ];
    }

    private function makeClient(string $accessKey, string $secretKey, string $region, string $endpoint): S3Client
    {
        $accessKey = trim($accessKey);
        $secretKey = trim($secretKey);
        if ($accessKey === '' || $secretKey === '') {
            throw new \RuntimeException('Both the Wasabi Access Key and Secret Key are required.');
        }

        $resolvedRegion = $this->normalizeRegion($region);
        $resolvedEndpoint = $this->normalizeEndpoint($endpoint, $resolvedRegion);

        return new S3Client([
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
            'endpoint' => $resolvedEndpoint,
            'region' => $resolvedRegion,
            'version' => 'latest',
            'use_path_style_endpoint' => true,
            'http' => [
                'connect_timeout' => 10,
                'timeout' => 20,
            ],
        ]);
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));
        return $region !== '' ? $region : 'us-east-1';
    }

    private function normalizeEndpoint(string $endpoint, string $region): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return 'https://s3.' . $region . '.wasabisys.com';
        }

        if (!preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        return rtrim($endpoint, '/');
    }

    private function normalizeOrigins(array|string $origins): array
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

        return array_values(array_unique($normalizedOrigins));
    }

    private function getExistingCorsRules(S3Client $client, string $bucketName): array
    {
        try {
            $response = $client->getBucketCors([
                'Bucket' => $bucketName,
            ]);

            $rules = $response['CORSRules'] ?? [];
            return is_array($rules) ? $rules : [];
        } catch (AwsException $e) {
            $errorCode = (string)($e->getAwsErrorCode() ?? '');
            if (in_array($errorCode, ['NoSuchCORSConfiguration', 'NoSuchCORSConfigurationRequest'], true)) {
                return [];
            }

            throw new \RuntimeException($this->extractAwsMessage($e, 'Wasabi CORS read failed.'));
        }
    }

    private function extractAwsMessage(AwsException $e, string $fallback): string
    {
        $awsMessage = trim((string)($e->getAwsErrorMessage() ?? ''));
        if ($awsMessage !== '') {
            return $awsMessage;
        }

        $message = trim($e->getMessage());
        return $message !== '' ? $message : $fallback;
    }
}
