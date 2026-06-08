<?php

namespace App\Service\Storage;

use App\Interface\StorageProvider;
use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use Aws\S3\Exception\S3Exception;

/**
 * Builds the right StorageProvider from a file_servers DB row.
 * The `config` column holds a JSON blob with credentials.
 */
class ServerProviderFactory {

    public static function make(array $server): StorageProvider {
        $type   = strtolower((string)($server['server_type'] ?? 'local'));
        $config = self::normalizeConfig($server['config'] ?? [], $server['id'] ?? null);

        switch ($type) {
            case 's3':
            case 'wasabi':
            case 'b2':
            case 'r2':
            case 'backblaze':
                return self::makeS3($server, $config);

            case 'ftp':
                throw new \RuntimeException('FTP storage is not implemented in this build.');

            case 'local':
            default:
                return self::makeLocal($server);
        }
    }

    private static function normalizeConfig(mixed $rawConfig, mixed $serverId = null): array {
        if (is_array($rawConfig)) {
            return $rawConfig;
        }

        if (!is_string($rawConfig) || $rawConfig === '') {
            return [];
        }

        $decoded = json_decode($rawConfig, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        try {
            $decrypted = \App\Service\EncryptionService::decrypt($rawConfig);
        } catch (\Exception $e) {
            throw new \RuntimeException('Storage configuration could not be decrypted for server ' . ($serverId ?? 'unknown') . '.', 0, $e);
        }

        if (!is_string($decrypted) || $decrypted === '') {
            throw new \RuntimeException('Storage configuration is empty for server ' . ($serverId ?? 'unknown') . '.');
        }

        $decoded = json_decode($decrypted, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Storage configuration is invalid for server ' . ($serverId ?? 'unknown') . '.');
        }

        return $decoded;
    }

    private static function makeLocal(array $server): StorageProvider {
        $path = !empty($server['storage_path']) ? $server['storage_path'] : 'storage/uploads';

        if (!empty($path) && str_starts_with($path, 'ENC:')) {
            try {
                $path = \App\Service\EncryptionService::decrypt($path);
            } catch (\Exception $e) {
                throw new \RuntimeException('Local storage path could not be decrypted.', 0, $e);
            }
        }

        $path = StoragePathGuard::normalizeLocalRootPath((string)$path);

        return new ConfigurableLocalStorage($path, $server['public_url'] ?? '');
    }

    private static function makeS3(array $server, array $config): StorageProvider {
        $endpoint = $config['s3_endpoint'] ?? '';
        $key      = $config['s3_key']      ?? '';
        $secret   = $config['s3_secret']   ?? '';
        $region   = $config['s3_region']   ?? 'us-east-1';
        $bucket   = $config['bucket_name'] ?? ($server['storage_path'] ?? '');

        if (!empty($bucket) && str_starts_with($bucket, 'ENC:')) {
            try {
                $bucket = \App\Service\EncryptionService::decrypt($bucket);
            } catch (\Exception $e) {
                throw new \RuntimeException('Storage bucket name could not be decrypted.', 0, $e);
            }
        }

        $providerPreset = strtolower((string)($config['provider_preset'] ?? $server['provider_preset'] ?? $server['server_type'] ?? ''));

        if (!$endpoint && $providerPreset === 'b2') {
            $endpoint = 'https://s3.' . $region . '.backblazeb2.com';
        }

        if (!$endpoint && $providerPreset === 'wasabi') {
            $endpoint = 'https://s3.' . $region . '.wasabisys.com';
        }

        if ($providerPreset === 'r2') {
            if (preg_match('/^[a-f0-9]{32}$/i', (string)$endpoint) === 1) {
                $endpoint = strtolower((string)$endpoint) . '.r2.cloudflarestorage.com';
            } elseif (preg_match('/^[a-f0-9]{32}\.r2\.cloudflarestorage\.com$/i', (string)$endpoint) === 1) {
                $endpoint = strtolower((string)$endpoint);
            } else {
                throw new \RuntimeException('Cloudflare R2 endpoint must use a valid account identifier.');
            }
        }

        $endpoint = StoragePathGuard::normalizeS3Endpoint((string)$endpoint);
        $bucket = StoragePathGuard::normalizeBucketName((string)$bucket);

        $isR2 = str_contains($endpoint, 'r2.cloudflarestorage.com');

        $clientConfig = [
            'credentials' => ['key' => $key, 'secret' => $secret],
            'region'      => $isR2 ? 'auto' : $region,
            'version'     => 'latest',
            'use_path_style_endpoint' => true,
            'http' => ['connect_timeout' => 10, 'timeout' => 0],
        ];

        if ($endpoint) {
            $clientConfig['endpoint'] = $endpoint;
        }

        $client = new S3Client($clientConfig);
        $isB2 = (($config['provider_preset'] ?? '') === 'b2')
            || (($server['provider_preset'] ?? '') === 'b2')
            || str_contains($endpoint, 'backblazeb2.com');

        return new S3StorageProvider($client, $bucket, $server['public_url'] ?? '', $isB2);
    }

}
