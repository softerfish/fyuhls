<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Service\Database\SchemaService;
use PDOException;

class RateLimiterService {
    private static bool $schemaUnavailable = false;

    /**
     * Check if an action is within limits.
     * Returns true if allowed, false if rate limited.
     *
     * @throws PDOException
     */
    public static function check(string $action, string $key, int $limit, int $windowSeconds): bool {
        if (!self::schemaAvailable()) {
            return false;
        }

        try {
            return self::runCheck($action, $key, 1, $limit, $windowSeconds);
        } catch (\Throwable $e) {
            self::markSchemaUnavailable($e, 'rate limiter check');
            return false;
        }
    }

    public static function checkWeighted(string $action, string $key, int $cost, int $limit, int $windowSeconds): bool {
        $cost = max(1, $cost);
        if (!self::schemaAvailable()) {
            return false;
        }

        try {
            return self::runCheck($action, $key, $cost, $limit, $windowSeconds);
        } catch (\Throwable $e) {
            self::markSchemaUnavailable($e, 'rate limiter weighted check');
            return false;
        }
    }

    public static function canAttempt(string $action, string $key, int $limit, int $windowSeconds, int $cost = 1): bool
    {
        $cost = max(1, $cost);
        if (!self::schemaAvailable()) {
            return false;
        }

        try {
            return self::runCanAttempt($action, $key, $cost, $limit, $windowSeconds);
        } catch (\Throwable $e) {
            self::markSchemaUnavailable($e, 'rate limiter can-attempt');
            return false;
        }
    }

    public static function recordHit(string $action, string $key, int $cost = 1): void
    {
        $cost = max(1, $cost);
        if (!self::schemaAvailable()) {
            return;
        }

        try {
            self::runRecordHit($action, $key, $cost);
        } catch (\Throwable $e) {
            self::markSchemaUnavailable($e, 'rate limiter record-hit');
        }
    }

    /**
     * Execute an attempt while holding the relevant rate-limit locks.
     * The callback result is returned only when every limit still allows an attempt.
     * If the callback returns false, the configured limits are charged atomically.
     *
     * @param array<int, array{action:string,key:string,limit:int,window:int,cost?:int}> $limits
     * @return array{allowed:bool,result:mixed}
     */
    public static function guardAttempt(array $limits, callable $attempt): array
    {
        if (!self::schemaAvailable()) {
            return ['allowed' => false, 'result' => null];
        }

        try {
            return self::runGuardAttempt($limits, $attempt);
        } catch (\Throwable $e) {
            self::markSchemaUnavailable($e, 'rate limiter guard-attempt');
            return ['allowed' => false, 'result' => null];
        }
    }

    private static function runCheck(string $action, string $key, int $cost, int $limit, int $windowSeconds): bool {
        $db = Database::getInstance()->getConnection();
        $now = time();
        $cutoff = $now - $windowSeconds;
        $lockName = self::lockName($action, $key);

        $lockStmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $lockStmt->execute([$lockName]);
        $lockAcquired = (int)$lockStmt->fetchColumn() === 1;
        if (!$lockAcquired) {
            return false;
        }

        try {
            self::ensureWeightColumn();

            $stmt = $db->prepare("SELECT COALESCE(SUM(weight), 0) FROM rate_limits WHERE action = ? AND identifier = ? AND created_at >= FROM_UNIXTIME(?)");
            $stmt->execute([$action, $key, $cutoff]);
            $count = (int)$stmt->fetchColumn();

            if (($count + $cost) > $limit) {
                return false;
            }

            $stmt = $db->prepare("INSERT INTO rate_limits (action, identifier, weight, created_at) VALUES (?, ?, ?, FROM_UNIXTIME(?))");
            $stmt->execute([$action, $key, $cost, $now]);

            return true;
        } finally {
            $unlockStmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $unlockStmt->execute([$lockName]);
        }
    }

    private static function runCanAttempt(string $action, string $key, int $cost, int $limit, int $windowSeconds): bool
    {
        $db = Database::getInstance()->getConnection();
        $cutoff = time() - $windowSeconds;
        $lockName = self::lockName($action, $key);

        $lockStmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $lockStmt->execute([$lockName]);
        $lockAcquired = (int)$lockStmt->fetchColumn() === 1;
        if (!$lockAcquired) {
            return false;
        }

        try {
            self::ensureWeightColumn();

            $stmt = $db->prepare("SELECT COALESCE(SUM(weight), 0) FROM rate_limits WHERE action = ? AND identifier = ? AND created_at >= FROM_UNIXTIME(?)");
            $stmt->execute([$action, $key, $cutoff]);
            $count = (int)$stmt->fetchColumn();

            return ($count + $cost) <= $limit;
        } finally {
            $unlockStmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $unlockStmt->execute([$lockName]);
        }
    }

    private static function runRecordHit(string $action, string $key, int $cost): void
    {
        $db = Database::getInstance()->getConnection();
        $now = time();
        $lockName = self::lockName($action, $key);

        $lockStmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $lockStmt->execute([$lockName]);
        $lockAcquired = (int)$lockStmt->fetchColumn() === 1;
        if (!$lockAcquired) {
            return;
        }

        try {
            self::ensureWeightColumn();
            $stmt = $db->prepare("INSERT INTO rate_limits (action, identifier, weight, created_at) VALUES (?, ?, ?, FROM_UNIXTIME(?))");
            $stmt->execute([$action, $key, $cost, $now]);
        } finally {
            $unlockStmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $unlockStmt->execute([$lockName]);
        }
    }

    private static function runGuardAttempt(array $limits, callable $attempt): array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureWeightColumn();

        $normalized = [];
        foreach ($limits as $limit) {
            $action = (string)($limit['action'] ?? '');
            $key = (string)($limit['key'] ?? '');
            $window = max(1, (int)($limit['window'] ?? 0));
            $entryLimit = max(1, (int)($limit['limit'] ?? 0));
            $cost = max(1, (int)($limit['cost'] ?? 1));
            if ($action === '' || $key === '') {
                continue;
            }

            $normalized[] = [
                'action' => $action,
                'key' => $key,
                'window' => $window,
                'limit' => $entryLimit,
                'cost' => $cost,
                'lock' => self::lockName($action, $key),
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            return strcmp($a['lock'], $b['lock']);
        });

        $acquiredLocks = [];
        try {
            foreach ($normalized as $entry) {
                $lockStmt = $db->prepare("SELECT GET_LOCK(?, 5)");
                $lockStmt->execute([$entry['lock']]);
                if ((int)$lockStmt->fetchColumn() !== 1) {
                    return ['allowed' => false, 'result' => null];
                }
                $acquiredLocks[] = $entry['lock'];
            }

            foreach ($normalized as $entry) {
                $cutoff = time() - $entry['window'];
                $stmt = $db->prepare("SELECT COALESCE(SUM(weight), 0) FROM rate_limits WHERE action = ? AND identifier = ? AND created_at >= FROM_UNIXTIME(?)");
                $stmt->execute([$entry['action'], $entry['key'], $cutoff]);
                $count = (int)$stmt->fetchColumn();
                if (($count + $entry['cost']) > $entry['limit']) {
                    return ['allowed' => false, 'result' => null];
                }
            }

            $result = $attempt();
            if ($result === false) {
                $now = time();
                $insert = $db->prepare("INSERT INTO rate_limits (action, identifier, weight, created_at) VALUES (?, ?, ?, FROM_UNIXTIME(?))");
                foreach ($normalized as $entry) {
                    $insert->execute([$entry['action'], $entry['key'], $entry['cost'], $now]);
                }
            }

            return ['allowed' => true, 'result' => $result];
        } finally {
            foreach (array_reverse($acquiredLocks) as $lockName) {
                $unlockStmt = $db->prepare("SELECT RELEASE_LOCK(?)");
                $unlockStmt->execute([$lockName]);
            }
        }
    }

    private static function lockName(string $action, string $key): string
    {
        return 'rate_limit:' . hash('sha256', $action . '|' . $key);
    }

    public static function cleanup(int $maxAgeSeconds = 86400): int {
        if (!self::schemaAvailable()) {
            return 0;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $cutoff = time() - $maxAgeSeconds;
            $stmt = $db->prepare("DELETE FROM rate_limits WHERE created_at < FROM_UNIXTIME(?)");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            self::markSchemaUnavailable($e, 'rate limiter cleanup');
            return 0;
        }
    }

    public static function createTable(): void {
        SchemaService::ensureTables(['rate_limits'], true);
    }

    private static function ensureWeightColumn(): void {
        static $weightColumnReady = false;
        if ($weightColumnReady) {
            return;
        }

        SchemaService::ensureTables(['rate_limits'], false);
        $weightColumnReady = true;
    }

    private static function schemaAvailable(): bool
    {
        if (self::$schemaUnavailable) {
            return false;
        }

        try {
            self::ensureWeightColumn();
            return true;
        } catch (\Throwable $e) {
            self::markSchemaUnavailable($e, 'rate limiter bootstrap');
            return false;
        }
    }

    private static function markSchemaUnavailable(\Throwable $e, string $context): void
    {
        self::$schemaUnavailable = true;
        Logger::warning('rate limiter schema unavailable', [
            'context' => $context,
            'error' => $e->getMessage(),
        ]);
    }
}
