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
        $type   = $server['server_type'];
        $rawConfig = $server['config'] ?? '{}';
        
        // Decrypt if it looks like an encrypted blob (not starting with {)
        if (!empty($rawConfig) && !str_starts_with($rawConfig, '{')) {
            try {
                $rawConfig = \App\Service\EncryptionService::decrypt($rawConfig);
            } catch (\Exception $e) {
                error_log('[ServerProviderFactory] Decryption failed for server ' . ($server['id'] ?? 'unknown'));
                $rawConfig = '{}';
            }
        }

        $config = json_decode($rawConfig, true) ?? [];

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

    private static function makeLocal(array $server): StorageProvider {
        $path = !empty($server['storage_path']) ? $server['storage_path'] : 'storage/uploads';

        // Decrypt if it looks like an encrypted blob
        if (!empty($path) && str_starts_with($path, 'ENC:')) {
            try {
                $path = \App\Service\EncryptionService::decrypt($path);
            } catch (\Exception $e) {
                error_log('[ServerProviderFactory] Local storage path decryption failed');
            }
        }

        // Safety: strip any leading public/
        $path = preg_replace('/^(\/?public\/)/i', '', $path);
        
        // ensure absolute path relative to project root if not already absolute
        if (!self::isAbsolutePath($path)) {
            $path = ltrim($path, '/\\');
            $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
            $path = $root . '/' . $path;
        }

        return new ConfigurableLocalStorage($path, $server['public_url'] ?? '');
    }

    private static function makeS3(array $server, array $config): StorageProvider {
        $endpoint = $config['s3_endpoint'] ?? '';
        $key      = $config['s3_key']      ?? '';
        $secret   = $config['s3_secret']   ?? '';
        $region   = $config['s3_region']   ?? 'us-east-1';
        $bucket   = $config['bucket_name'] ?? ($server['storage_path'] ?? '');

        // Decrypt bucket if it's from storage_path and encrypted
        if (!empty($bucket) && str_starts_with($bucket, 'ENC:')) {
            try {
                $bucket = \App\Service\EncryptionService::decrypt($bucket);
            } catch (\Exception $e) {
                error_log('[ServerProviderFactory] S3 bucket decryption failed');
            }
        }

        if (!$endpoint && (($config['provider_preset'] ?? '') === 'b2' || ($server['provider_preset'] ?? '') === 'b2')) {
            $endpoint = 'https://s3.' . $region . '.backblazeb2.com';
        }

        if (!$endpoint && (($config['provider_preset'] ?? '') === 'wasabi' || ($server['provider_preset'] ?? '') === 'wasabi')) {
            $endpoint = 'https://s3.' . $region . '.wasabisys.com';
        }

        // If endpoint is a 32-character hex string (Cloudflare Account ID), append domain
        if (preg_match('/^[a-f0-9]{32}$/i', $endpoint)) {
            $endpoint .= '.r2.cloudflarestorage.com';
        }

        // endpoint must have a scheme
        if ($endpoint && !str_starts_with($endpoint, 'http')) {
            $endpoint = 'https://' . $endpoint;
        }

        // detect if this is a Cloudflare R2 endpoint
        $isR2 = str_contains($endpoint, 'r2.cloudflarestorage.com');

        $clientConfig = [
            'credentials' => ['key' => $key, 'secret' => $secret],
            // R2 requires region 'auto' - all other providers use the configured region
            'region'      => $isR2 ? 'auto' : $region,
            'version'     => 'latest',
            // R2 uses path-style (bucket in URL path, not subdomain), same as B2/Wasabi
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

    private static function isAbsolutePath(string $path): bool {
        return $path !== '' && (
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 ||
            str_starts_with($path, '\\\\') ||
            str_starts_with($path, '/')
        );
    }
}
