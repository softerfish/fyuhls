<?php

namespace App\Core;

use App\Interface\StorageProvider;
use App\Service\Storage\LocalStorage;
use App\Service\Storage\ConfigurableLocalStorage;
use App\Service\Storage\S3StorageProvider;
use App\Service\Storage\ServerProviderFactory;
use Aws\S3\S3Client;
use PDO;

class StorageManager {
    private static array $providers = [];
    private static ?StorageProvider $activeProvider = null;

    public static function register(string $key, StorageProvider $provider): void {
        self::$providers[$key] = $provider;
    }

    public static function getProvider(?string $key = null): StorageProvider {
        // specific provider by key
        if ($key && isset(self::$providers[$key])) {
            return self::$providers[$key];
        }

        // return active/default
        if (self::$activeProvider === null) {
            $default = new LocalStorage();
            self::register('local', $default);
            self::$activeProvider = $default;
        }

        return self::$activeProvider;
    }

    public static function setActive(string $key): void {
        if (isset(self::$providers[$key])) {
            self::$activeProvider = self::$providers[$key];
        }
    }

    public static function getAvailableProviders(): array {
        return self::$providers;
    }

    /**
     * Pick the best upload server from the file_servers table and return
     * [providerKey, StorageProvider, fileServerId] so FileProcessor can record
     * which server it used.
     *
     * Priority:
     *   1. Active server marked is_default = 1 and not full
     *   2. Active server with most remaining capacity
     *   3. Any active server
     *   4. Local fallback
     */
    public static function resolveFromDb(PDO $db): array {
        try {
            // grab active servers, default first, then sorted by remaining space
            $rows = $db->query("
                SELECT * FROM file_servers
                WHERE LOWER(status) = 'active'
                ORDER BY is_default DESC,
                         CASE WHEN max_capacity_bytes = 0 THEN 1 ELSE 0 END DESC,
                         CAST(max_capacity_bytes AS SIGNED) - CAST(current_usage_bytes AS SIGNED) DESC
            ")->fetchAll();

            if (empty($rows)) {
                \App\Core\Logger::warning('[StorageManager] No active file servers found in database. Check status columns.');
            }

            foreach ($rows as $server) {
                // skip servers that are full
                if ($server['max_capacity_bytes'] > 0
                    && (float)$server['current_usage_bytes'] >= (float)$server['max_capacity_bytes']) {
                    \App\Core\Logger::warning("[StorageManager] Skipping full server", ['id' => $server['id'], 'usage' => $server['current_usage_bytes'], 'max' => $server['max_capacity_bytes']]);
                    continue;
                }

                $key      = self::keyForServer($server);
                $provider = self::makeProvider($server);
                self::register($key, $provider);

                \App\Core\Logger::info('Storage node selected for upload', [
                    'server_id' => (int)$server['id'],
                    'server_name' => (string)($server['name'] ?? ''),
                    'server_type' => (string)$server['server_type'],
                    'provider_key' => $key,
                    'status' => (string)($server['status'] ?? 'active'),
                ]);
                return [$key, $provider, (int) $server['id']];
            }
        } catch (\Exception $e) {
            \App\Core\Logger::error('[StorageManager] resolveFromDb failed', ['error' => $e->getMessage()]);
        }

        // hard fallback - local storage
        error_log('[StorageManager] Falling back to local storage (no suitable server found or query failed)');
        $local = new LocalStorage();
        self::register('local', $local);
        return ['local', $local, null];
    }

    /**
     * Resolve a specific provider for an existing file record.
     */
    public static function getProviderById(?int $serverId, PDO $db): StorageProvider {
        if (!$serverId) {
            return new LocalStorage();
        }

        // check cache first
        foreach (self::$providers as $key => $provider) {
            if (str_ends_with($key, '_' . $serverId)) {
                return $provider;
            }
        }

        // fetch from DB
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();

        if (!$server) {
            return new LocalStorage();
        }

        $provider = self::makeProvider($server);
        self::register(self::keyForServer($server), $provider);
        return $provider;
    }

    /**
     * Bump current_usage_bytes on the file_servers row after a successful upload.
     */
    public static function recordUsage(PDO $db, int $fileServerId, int $bytes): void {
        try {
            $db->prepare("UPDATE file_servers SET current_usage_bytes = current_usage_bytes + ? WHERE id = ?")
               ->execute([$bytes, $fileServerId]);
        } catch (\Exception $e) {
            error_log('[StorageManager] recordUsage failed: ' . $e->getMessage());
        }
    }

    /**
     * Reduce current_usage_bytes (called on file delete).
     */
    public static function releaseUsage(PDO $db, int $fileServerId, int $bytes): void {
        try {
            $db->prepare("UPDATE file_servers SET current_usage_bytes = GREATEST(0, current_usage_bytes - ?) WHERE id = ?")
               ->execute([$bytes, $fileServerId]);
        } catch (\Exception $e) {
            error_log('[StorageManager] releaseUsage failed: ' . $e->getMessage());
        }
    }

    private static function keyForServer(array $server): string {
        return $server['server_type'] . '_' . $server['id'];
    }

    private static function makeProvider(array $server): StorageProvider {
        if (class_exists(ServerProviderFactory::class)) {
            return ServerProviderFactory::make($server);
        }

        \App\Core\Logger::warning('[StorageManager] ServerProviderFactory missing, using legacy provider fallback.', [
            'server_id' => (int)($server['id'] ?? 0),
            'server_type' => (string)($server['server_type'] ?? 'unknown'),
        ]);

        $type = strtolower((string)($server['server_type'] ?? 'local'));

        if (in_array($type, ['s3', 'wasabi', 'b2', 'r2', 'backblaze'], true)) {
            return self::makeLegacyS3Provider($server);
        }

        return self::makeLegacyLocalProvider($server);
    }

    private static function makeLegacyLocalProvider(array $server): StorageProvider {
        $path = !empty($server['storage_path']) ? $server['storage_path'] : 'storage/uploads';

        if (!empty($path) && str_starts_with($path, 'ENC:')) {
            try {
                $path = \App\Service\EncryptionService::decrypt($path);
            } catch (\Exception $e) {
                \App\Core\Logger::warning('[StorageManager] Local storage path decryption failed for fallback provider.', [
                    'server_id' => (int)($server['id'] ?? 0),
                ]);
            }
        }

        $path = preg_replace('/^(\/?public\/)/i', '', $path);

        if (!self::isAbsolutePath($path)) {
            $path = ltrim($path, '/\\');
            $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
            $path = $root . '/' . $path;
        }

        return new ConfigurableLocalStorage($path, $server['public_url'] ?? '');
    }

    private static function makeLegacyS3Provider(array $server): StorageProvider {
        $rawConfig = $server['config'] ?? '{}';

        if (!empty($rawConfig) && !str_starts_with($rawConfig, '{')) {
            try {
                $rawConfig = \App\Service\EncryptionService::decrypt($rawConfig);
            } catch (\Exception $e) {
                \App\Core\Logger::warning('[StorageManager] Storage config decryption failed for fallback provider.', [
                    'server_id' => (int)($server['id'] ?? 0),
                ]);
                $rawConfig = '{}';
            }
        }

        $config = json_decode($rawConfig, true) ?? [];
        $endpoint = $config['s3_endpoint'] ?? '';
        $key = $config['s3_key'] ?? '';
        $secret = $config['s3_secret'] ?? '';
        $region = $config['s3_region'] ?? 'us-east-1';
        $bucket = $config['bucket_name'] ?? ($server['storage_path'] ?? '');

        if (!empty($bucket) && str_starts_with($bucket, 'ENC:')) {
            try {
                $bucket = \App\Service\EncryptionService::decrypt($bucket);
            } catch (\Exception $e) {
                \App\Core\Logger::warning('[StorageManager] Bucket decryption failed for fallback provider.', [
                    'server_id' => (int)($server['id'] ?? 0),
                ]);
            }
        }

        if (!$endpoint && (($config['provider_preset'] ?? '') === 'b2' || ($server['provider_preset'] ?? '') === 'b2')) {
            $endpoint = 'https://s3.' . $region . '.backblazeb2.com';
        }

        if (!$endpoint && (($config['provider_preset'] ?? '') === 'wasabi' || ($server['provider_preset'] ?? '') === 'wasabi')) {
            $endpoint = 'https://s3.' . $region . '.wasabisys.com';
        }

        if (preg_match('/^[a-f0-9]{32}$/i', (string)$endpoint)) {
            $endpoint .= '.r2.cloudflarestorage.com';
        }

        if ($endpoint && !str_starts_with($endpoint, 'http')) {
            $endpoint = 'https://' . $endpoint;
        }

        $isR2 = str_contains((string)$endpoint, 'r2.cloudflarestorage.com');
        $clientConfig = [
            'credentials' => ['key' => $key, 'secret' => $secret],
            'region' => $isR2 ? 'auto' : $region,
            'version' => 'latest',
            'use_path_style_endpoint' => true,
            'http' => ['connect_timeout' => 10, 'timeout' => 0],
        ];

        if ($endpoint) {
            $clientConfig['endpoint'] = $endpoint;
        }

        $client = new S3Client($clientConfig);
        $isB2 = (($config['provider_preset'] ?? '') === 'b2')
            || (($server['provider_preset'] ?? '') === 'b2')
            || str_contains((string)$endpoint, 'backblazeb2.com');

        return new S3StorageProvider($client, (string)$bucket, $server['public_url'] ?? '', $isB2);
    }

    private static function isAbsolutePath(string $path): bool {
        return $path !== '' && (
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 ||
            str_starts_with($path, '\\\\') ||
            str_starts_with($path, '/')
        );
    }
}
