<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Service\Database\SchemaService;
use PDO;

class NotificationService
{
    private static bool $runtimeSchemaReady = false;
    private const FALLBACK_DIR = 'storage/cache/notification_fallback';
    private const FALLBACK_VERSION = 1;
    private static ?string $fallbackDirOverride = null;
    private static $beforeEventPersistHandler = null;

    private static function ensureSchema(): void
    {
        if (self::$runtimeSchemaReady) {
            return;
        }

        SchemaService::ensureTables(['notifications'], false);

        self::$runtimeSchemaReady = true;
    }

    private static function schemaAvailable(): bool
    {
        try {
            self::ensureSchema();
            return true;
        } catch (\Throwable $e) {
            Logger::warning('notification runtime schema unavailable', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public static function setFallbackDirectoryForTests(?string $path): void
    {
        self::$fallbackDirOverride = $path;
    }

    public static function setBeforeEventPersistHandlerForTests(?callable $handler): void
    {
        self::$beforeEventPersistHandler = $handler;
    }

    /**
     * Send a notification to a user
     */
    public static function send(int $userId, string $title, string $message, string $type = 'info'): void
    {
        if (!self::schemaAvailable()) {
            self::storeFallbackNotification($userId, [
                'category' => 'general',
                'event_key' => null,
                'title' => $title,
                'message' => $message,
                'action_url' => null,
                'metadata_json' => null,
                'type' => $type,
            ]);
            return;
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, category, title, message, type) VALUES (?, 'general', ?, ?, ?)");
        $stmt->execute([$userId, $title, $message, $type]);
    }

    public static function sendEvent(int $userId, string $category, string $eventKey, string $title, string $message, string $type = 'info', ?string $actionUrl = null, array $metadata = []): void
    {
        $metadataJson = $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null;
        if (!self::schemaAvailable()) {
            self::storeFallbackNotification($userId, [
                'category' => $category,
                'event_key' => $eventKey,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'metadata_json' => $metadataJson,
                'type' => $type,
            ]);
            return;
        }
        $db = Database::getInstance()->getConnection();
        self::withEventLock($db, $userId, $eventKey, static function () use ($db, $userId, $category, $eventKey, $title, $message, $type, $actionUrl, $metadataJson): void {
            if (is_callable(self::$beforeEventPersistHandler)) {
                (self::$beforeEventPersistHandler)([
                    'user_id' => $userId,
                    'event_key' => $eventKey,
                    'title' => $title,
                ]);
            }

            $stmt = $db->prepare("
                SELECT id
                FROM notifications
                WHERE user_id = ? AND event_key = ?
                ORDER BY is_read ASC, created_at DESC, id DESC
                FOR UPDATE
            ");
            $stmt->execute([$userId, $eventKey]);
            $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

            if ($existingIds !== []) {
                $primaryId = (int)array_shift($existingIds);
                $update = $db->prepare("
                    UPDATE notifications
                    SET category = ?, title = ?, message = ?, type = ?, action_url = ?, metadata_json = ?, is_read = 0, read_at = NULL
                    WHERE id = ?
                ");
                $update->execute([$category, $title, $message, $type, $actionUrl, $metadataJson, $primaryId]);

                if ($existingIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                    $delete = $db->prepare("DELETE FROM notifications WHERE id IN ($placeholders)");
                    $delete->execute($existingIds);
                }
            } else {
                $insert = $db->prepare("
                    INSERT INTO notifications (user_id, category, event_key, title, message, action_url, metadata_json, type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert->execute([$userId, $category, $eventKey, $title, $message, $actionUrl, $metadataJson, $type]);
            }
        });
        self::removeFallbackNotificationsByEventKey($userId, $eventKey);
    }

    /**
     * Get unread notifications for a user
     */
    public static function getUnread(int $userId): array
    {
        if (!self::schemaAvailable()) {
            return self::fallbackUnreadNotifications($userId);
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $databaseRows = $stmt->fetchAll();
        $fallbackRows = self::fallbackUnreadNotifications($userId);
        if ($fallbackRows === []) {
            return $databaseRows;
        }

        return self::mergeFallbackNotifications($databaseRows, $fallbackRows);
    }

    public static function getRecent(int $userId, int $limit = 50): array
    {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }

        $fallbackRows = self::fallbackUnreadNotifications($userId);
        if (!self::schemaAvailable()) {
            return array_slice(self::sortNotificationsNewestFirst($fallbackRows), 0, $limit);
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT " . max(1, (int)$limit));
        $stmt->execute([$userId]);
        $databaseRows = $stmt->fetchAll();

        return array_slice(self::sortNotificationsNewestFirst(self::mergeFallbackNotifications($databaseRows, $fallbackRows)), 0, $limit);
    }

    public static function markRead(int $userId, string $notificationId): bool
    {
        if ($userId <= 0 || $notificationId === '') {
            return false;
        }

        if (!self::schemaAvailable()) {
            return self::removeFallbackNotification($userId, $notificationId);
        }

        if (!ctype_digit($notificationId)) {
            return self::removeFallbackNotification($userId, $notificationId);
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE user_id = ? AND id = ? AND is_read = 0
        ");
        $stmt->execute([$userId, (int)$notificationId]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return self::removeFallbackNotification($userId, $notificationId);
    }

    /**
     * Mark all notifications as read for a user
     */
    public static function markAllRead(int $userId): void
    {
        if (!self::schemaAvailable()) {
            self::clearFallbackNotifications($userId);
            return;
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ?");
        $stmt->execute([$userId]);
        self::clearFallbackNotifications($userId);
    }

    private static function storeFallbackNotification(int $userId, array $payload): void
    {
        if ($userId <= 0) {
            return;
        }

        $path = self::fallbackFilePath($userId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            Logger::warning('notification fallback storage unavailable', ['user_id' => $userId]);
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                Logger::warning('notification fallback lock unavailable', ['user_id' => $userId]);
                return;
            }

            $existing = stream_get_contents($handle);
            $rows = self::decodeFallbackRows($existing);
            $payloadRecord = [
                'id' => 'fallback_' . bin2hex(random_bytes(8)),
                'user_id' => $userId,
                'category' => (string)($payload['category'] ?? 'general'),
                'event_key' => $payload['event_key'] !== null ? (string)$payload['event_key'] : null,
                'title' => (string)($payload['title'] ?? ''),
                'message' => (string)($payload['message'] ?? ''),
                'action_url' => $payload['action_url'] !== null ? (string)$payload['action_url'] : null,
                'metadata_json' => $payload['metadata_json'] !== null ? (string)$payload['metadata_json'] : null,
                'type' => (string)($payload['type'] ?? 'info'),
                'is_read' => 0,
                'read_at' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
                '_fallback' => true,
            ];

            $replaced = false;
            if ($payloadRecord['event_key'] !== null && $payloadRecord['event_key'] !== '') {
                foreach ($rows as $index => $row) {
                    if ((int)($row['is_read'] ?? 0) !== 0) {
                        continue;
                    }
                    if ((string)($row['event_key'] ?? '') !== $payloadRecord['event_key']) {
                        continue;
                    }
                    $payloadRecord['id'] = (string)($row['id'] ?? $payloadRecord['id']);
                    $payloadRecord['created_at'] = (string)($row['created_at'] ?? $payloadRecord['created_at']);
                    $rows[$index] = $payloadRecord;
                    $replaced = true;
                    break;
                }
            }

            if (!$replaced) {
                array_unshift($rows, $payloadRecord);
            }

            $encoded = self::encodeFallbackRows($rows);
            if ($encoded === null) {
                Logger::warning('notification fallback encoding unavailable', ['user_id' => $userId]);
                return;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function fallbackUnreadNotifications(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return array_values(array_filter(
            self::readFallbackNotifications($userId),
            static fn(array $row): bool => (int)($row['is_read'] ?? 0) === 0
        ));
    }

    private static function clearFallbackNotifications(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $path = self::fallbackFilePath($userId);
        if (!is_file($path)) {
            return;
        }

        if (!@unlink($path)) {
            $handle = @fopen($path, 'c+');
            if ($handle === false) {
                return;
            }
            try {
                if (!flock($handle, LOCK_EX)) {
                    return;
                }
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, self::encodeFallbackRows([]) ?? '');
                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private static function removeFallbackNotification(int $userId, string $notificationId): bool
    {
        if ($userId <= 0 || $notificationId === '') {
            return false;
        }

        $path = self::fallbackFilePath($userId);
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $rows = self::decodeFallbackRows(stream_get_contents($handle) ?: '');
            $updated = [];
            $removed = false;

            foreach ($rows as $row) {
                if ((string)($row['id'] ?? '') === $notificationId) {
                    $removed = true;
                    continue;
                }
                $updated[] = $row;
            }

            if (!$removed) {
                return false;
            }

            $encoded = self::encodeFallbackRows($updated);
            if ($encoded === null) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function readFallbackNotifications(int $userId): array
    {
        $path = self::fallbackFilePath($userId);
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        return self::decodeFallbackRows($raw === false ? '' : $raw);
    }

    private static function decodeFallbackRows(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Logger::warning('notification fallback payload invalid');
            return [];
        }

        $version = (int)($decoded['version'] ?? 0);
        $ciphertext = (string)($decoded['ciphertext'] ?? '');
        $signature = (string)($decoded['signature'] ?? '');
        if ($version !== self::FALLBACK_VERSION || $ciphertext === '' || $signature === '') {
            Logger::warning('notification fallback envelope invalid', ['version' => $version]);
            return [];
        }

        $secret = self::fallbackSigningSecret();
        if ($secret === '') {
            Logger::warning('notification fallback signing secret unavailable');
            return [];
        }

        $expected = hash_hmac('sha256', $version . '|' . $ciphertext, $secret);
        if (!hash_equals($expected, $signature)) {
            Logger::warning('notification fallback signature mismatch');
            return [];
        }

        $plaintext = EncryptionService::decrypt($ciphertext);
        if (!is_string($plaintext) || $plaintext === '' || str_starts_with($plaintext, 'ENC:')) {
            Logger::warning('notification fallback ciphertext unreadable');
            return [];
        }

        $rows = json_decode($plaintext, true);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private static function fallbackFilePath(int $userId): string
    {
        $baseDir = self::$fallbackDirOverride ?? (self::projectRoot() . DIRECTORY_SEPARATOR . self::FALLBACK_DIR);
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'user_' . $userId . '.json';
    }

    private static function sortNotificationsNewestFirst(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $leftTimestamp = strtotime((string)($left['created_at'] ?? '')) ?: 0;
            $rightTimestamp = strtotime((string)($right['created_at'] ?? '')) ?: 0;

            return $rightTimestamp <=> $leftTimestamp;
        });

        return $rows;
    }

    private static function projectRoot(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH;
        }

        return dirname(__DIR__, 2);
    }

    private static function encodeFallbackRows(array $rows): ?string
    {
        $secret = self::fallbackSigningSecret();
        if ($secret === '') {
            return null;
        }

        $json = json_encode(array_values($rows), JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return null;
        }

        $ciphertext = EncryptionService::encrypt($json);
        if (!is_string($ciphertext) || $ciphertext === '' || !str_starts_with($ciphertext, 'ENC:')) {
            return null;
        }

        $version = self::FALLBACK_VERSION;
        $signature = hash_hmac('sha256', $version . '|' . $ciphertext, $secret);

        return json_encode([
            'version' => $version,
            'ciphertext' => $ciphertext,
            'signature' => $signature,
        ], JSON_UNESCAPED_SLASHES) ?: null;
    }

    private static function fallbackSigningSecret(): string
    {
        $secret = SecurityService::getSecureAppKey();
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        return trim((string)Config::get('security.encryption_key', ''));
    }

    private static function withEventLock(PDO $db, int $userId, string $eventKey, callable $callback): void
    {
        $lockKey = 'notif:' . $userId . ':' . sha1($eventKey);
        $lockStmt = $db->prepare('SELECT GET_LOCK(?, 10)');
        $lockStmt->execute([$lockKey]);
        $acquired = (int)$lockStmt->fetchColumn() === 1;
        if (!$acquired) {
            throw new \RuntimeException('Notification delivery lock could not be acquired.');
        }

        try {
            $callback();
        } finally {
            $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStmt->execute([$lockKey]);
        }
    }

    private static function mergeFallbackNotifications(array $databaseRows, array $fallbackRows): array
    {
        if ($fallbackRows === []) {
            return $databaseRows;
        }

        $databaseEventKeys = [];
        foreach ($databaseRows as $row) {
            $eventKey = trim((string)($row['event_key'] ?? ''));
            if ($eventKey !== '') {
                $databaseEventKeys[$eventKey] = true;
            }
        }

        $filteredFallback = [];
        foreach ($fallbackRows as $row) {
            $eventKey = trim((string)($row['event_key'] ?? ''));
            if ($eventKey !== '' && isset($databaseEventKeys[$eventKey])) {
                continue;
            }
            $filteredFallback[] = $row;
        }

        return array_merge($filteredFallback, $databaseRows);
    }

    private static function removeFallbackNotificationsByEventKey(int $userId, string $eventKey): void
    {
        if ($userId <= 0 || trim($eventKey) === '') {
            return;
        }

        $path = self::fallbackFilePath($userId);
        if (!is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $rows = self::decodeFallbackRows(stream_get_contents($handle) ?: '');
            $updated = [];
            $removed = false;

            foreach ($rows as $row) {
                if ((string)($row['event_key'] ?? '') === $eventKey) {
                    $removed = true;
                    continue;
                }
                $updated[] = $row;
            }

            if (!$removed) {
                return;
            }

            $encoded = self::encodeFallbackRows($updated);
            if ($encoded === null) {
                return;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
