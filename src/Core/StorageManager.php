<?php

namespace App\Core;

use App\Interface\StorageProvider;
use App\Service\Storage\LocalStorage;
use App\Service\Storage\ServerProviderFactory;
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
                $provider = ServerProviderFactory::make($server);
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

        $provider = ServerProviderFactory::make($server);
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
}
