<?php

namespace App\Core;

use App\Interface\StorageProvider;
use App\Service\Storage\LocalStorage;
use App\Service\Storage\ConfigurableLocalStorage;
use App\Service\Storage\S3StorageProvider;
use App\Service\Storage\ServerProviderFactory;
use App\Service\Storage\StoragePathGuard;
use Aws\S3\S3Client;
use PDO;

class StorageManager {
    public const MISSING_FILE_SERVER_MESSAGE = 'The referenced storage node is unavailable until an administrator repairs file storage.';

    private static array $providers = [];
    private static ?StorageProvider $activeProvider = null;
    private static $providerFactoryForTests = null;

    public static function setProviderFactoryForTests(?callable $factory): void
    {
        self::$providerFactoryForTests = $factory;
    }

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
     *
     * Upload placement must fail closed when no configured server can safely
     * accept the object. Silent fallback to unmanaged local storage causes
     * placement drift and bypasses file-server accounting/capacity policy.
     */
    public static function resolveFromDb(PDO $db, int $requiredBytes = 0, bool $includeReservedCapacity = false): array {
        $requiredBytes = max(0, $requiredBytes);
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

            $activeServerCount = count($rows);
            $activeDefaultCount = count(array_filter($rows, static fn(array $server): bool => (int)($server['is_default'] ?? 0) === 1));
            if ($activeServerCount > 1 && $activeDefaultCount !== 1) {
                \App\Core\Logger::error('[StorageManager] Upload target selection is ambiguous', [
                    'active_server_count' => $activeServerCount,
                    'active_default_count' => $activeDefaultCount,
                ]);
                throw new \RuntimeException('Storage upload target configuration is ambiguous. Choose exactly one active default storage server before accepting new uploads.');
            }

            foreach ($rows as $server) {
                $serverId = (int)($server['id'] ?? 0);
                $maxCapacity = (int)($server['max_capacity_bytes'] ?? 0);
                $currentUsage = (int)($server['current_usage_bytes'] ?? 0);

                if ($maxCapacity > 0) {
                    $reservedBytes = 0;
                    if ($includeReservedCapacity && $serverId > 0 && class_exists(\App\Model\QuotaReservation::class)) {
                        $reservedBytes = \App\Model\QuotaReservation::activeReservedBytesForServer($serverId);
                    }

                    if (($currentUsage + $reservedBytes + $requiredBytes) > $maxCapacity) {
                        \App\Core\Logger::warning('[StorageManager] Skipping server without enough free capacity', [
                            'id' => $serverId,
                            'usage' => $currentUsage,
                            'reserved' => $reservedBytes,
                            'required' => $requiredBytes,
                            'max' => $maxCapacity,
                        ]);
                        continue;
                    }
                }

                try {
                    $key = self::keyForServer($server);
                    $provider = self::makeProvider($server);
                } catch (\Throwable $e) {
                    \App\Core\Logger::warning('[StorageManager] Skipping invalid storage server during upload selection', [
                        'id' => $serverId,
                        'type' => (string)($server['server_type'] ?? 'unknown'),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

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

        throw new \RuntimeException('No configured storage server is currently available for new uploads.');
    }

    public static function acquireServerCapacityLock(PDO $db, int $fileServerId, int $timeoutSeconds = 5): bool
    {
        if ($fileServerId <= 0) {
            return true;
        }

        $stmt = $db->prepare("SELECT GET_LOCK(?, ?)");
        $stmt->execute(['fyuhls_file_server_capacity_' . $fileServerId, max(1, $timeoutSeconds)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public static function releaseServerCapacityLock(PDO $db, int $fileServerId): void
    {
        if ($fileServerId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_file_server_capacity_' . $fileServerId]);
        } catch (\Throwable $e) {
        }
    }

    public static function assertServerHasCapacity(PDO $db, ?int $fileServerId, int $requiredBytes, bool $includeReservedCapacity = true): void
    {
        $fileServerId = (int)$fileServerId;
        $requiredBytes = max(0, $requiredBytes);
        if ($fileServerId <= 0 || $requiredBytes <= 0) {
            return;
        }

        $stmt = $db->prepare("SELECT current_usage_bytes, max_capacity_bytes FROM file_servers WHERE id = ? LIMIT 1");
        $stmt->execute([$fileServerId]);
        $server = $stmt->fetch();
        if (!$server || (int)($server['max_capacity_bytes'] ?? 0) <= 0) {
            return;
        }

        $reservedBytes = 0;
        if ($includeReservedCapacity && class_exists(\App\Model\QuotaReservation::class)) {
            $reservedBytes = \App\Model\QuotaReservation::activeReservedBytesForServer($fileServerId);
        }

        $currentUsage = (int)($server['current_usage_bytes'] ?? 0);
        $maxCapacity = (int)($server['max_capacity_bytes'] ?? 0);
        if (($currentUsage + $reservedBytes + $requiredBytes) > $maxCapacity) {
            throw new \Exception('The selected storage node does not have enough free capacity.');
        }
    }

    /**
     * Resolve a specific provider for an existing file record.
     */
    public static function getProviderById(?int $serverId, PDO $db): StorageProvider {
        if (!$serverId) {
            try {
                return new LocalStorage();
            } catch (\Throwable $e) {
                Logger::warning('[StorageManager] Default local storage provider is unavailable.', [
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException(self::MISSING_FILE_SERVER_MESSAGE, 0, $e);
            }
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
            Logger::warning('[StorageManager] Existing object references a missing storage node.', [
                'server_id' => $serverId,
            ]);
            throw new \RuntimeException(self::MISSING_FILE_SERVER_MESSAGE);
        }

        try {
            $provider = self::makeProvider($server);
        } catch (\Throwable $e) {
            Logger::warning('[StorageManager] Existing object references an invalid storage node configuration.', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(self::MISSING_FILE_SERVER_MESSAGE, 0, $e);
        }
        self::register(self::keyForServer($server), $provider);
        return $provider;
    }

    /**
     * Bump current_usage_bytes on the file_servers row after a successful upload.
     */
    public static function recordUsage(PDO $db, int $fileServerId, int $bytes): void {
        try {
            self::recordUsageOrFail($db, $fileServerId, $bytes);
        } catch (\Exception $e) {
            error_log('[StorageManager] recordUsage failed: ' . $e->getMessage());
        }
    }

    public static function recordUsageOrFail(PDO $db, int $fileServerId, int $bytes): void {
        if ($fileServerId <= 0 || $bytes <= 0) {
            return;
        }

        $stmt = $db->prepare("UPDATE file_servers SET current_usage_bytes = current_usage_bytes + ? WHERE id = ?");
        $stmt->execute([$bytes, $fileServerId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Storage usage could not be recorded because the target file server is missing.');
        }
    }

    /**
     * Reduce current_usage_bytes (called on file delete).
     */
    public static function releaseUsage(PDO $db, int $fileServerId, int $bytes): void {
        try {
            self::releaseUsageOrFail($db, $fileServerId, $bytes);
        } catch (\Exception $e) {
            error_log('[StorageManager] releaseUsage failed: ' . $e->getMessage());
        }
    }

    public static function releaseUsageOrFail(PDO $db, int $fileServerId, int $bytes): void {
        if ($fileServerId <= 0 || $bytes <= 0) {
            return;
        }

        $stmt = $db->prepare("UPDATE file_servers SET current_usage_bytes = GREATEST(0, current_usage_bytes - ?) WHERE id = ?");
        $stmt->execute([$bytes, $fileServerId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Storage usage could not be released because the target file server is missing.');
        }
    }

    private static function keyForServer(array $server): string {
        return $server['server_type'] . '_' . $server['id'];
    }

    private static function makeProvider(array $server): StorageProvider {
        if (is_callable(self::$providerFactoryForTests)) {
            $provider = (self::$providerFactoryForTests)($server);
            if ($provider instanceof StorageProvider) {
                return $provider;
            }
        }

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
                throw new \RuntimeException('Local storage path could not be decrypted for fallback provider.', 0, $e);
            }
        }

        $path = StoragePathGuard::normalizeLocalRootPath((string)$path);

        return new ConfigurableLocalStorage($path, $server['public_url'] ?? '');
    }

    private static function makeLegacyS3Provider(array $server): StorageProvider {
        $config = self::normalizeLegacyServerConfig($server['config'] ?? [], $server);
        $endpoint = $config['s3_endpoint'] ?? '';
        $key = $config['s3_key'] ?? '';
        $secret = $config['s3_secret'] ?? '';
        $region = $config['s3_region'] ?? 'us-east-1';
        $bucket = $config['bucket_name'] ?? ($server['storage_path'] ?? '');

        if (!empty($bucket) && str_starts_with($bucket, 'ENC:')) {
            try {
                $bucket = \App\Service\EncryptionService::decrypt($bucket);
            } catch (\Exception $e) {
                throw new \RuntimeException('Storage bucket name could not be decrypted for fallback provider.', 0, $e);
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

        $endpoint = StoragePathGuard::normalizeS3Endpoint((string)$endpoint);
        $bucket = StoragePathGuard::normalizeBucketName((string)$bucket);

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

    private static function normalizeLegacyServerConfig(mixed $rawConfig, array $server): array {
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
            throw new \RuntimeException('Storage configuration could not be decrypted for fallback provider.', 0, $e);
        }

        if (!is_string($decrypted) || $decrypted === '') {
            throw new \RuntimeException('Storage configuration is empty for fallback provider.');
        }

        $decoded = json_decode($decrypted, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Storage configuration is invalid for fallback provider.');
        }

        return $decoded;
    }
}
