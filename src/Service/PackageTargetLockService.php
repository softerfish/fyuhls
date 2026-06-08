<?php

namespace App\Service;

use PDO;

final class PackageTargetLockService
{
    /**
     * @return array<int, string>
     */
    public static function lockPackageIds(PDO $db, array $packageIds, int $timeoutSeconds = 5): array
    {
        $packageIds = self::normalizePackageIds($packageIds);
        if ($packageIds === []) {
            return [];
        }

        $acquired = [];
        $stmt = $db->prepare("SELECT GET_LOCK(?, ?)");

        try {
            foreach ($packageIds as $packageId) {
                $lockKey = self::lockKey($packageId);
                $stmt->execute([$lockKey, $timeoutSeconds]);
                if ((int)$stmt->fetchColumn() !== 1) {
                    throw new \RuntimeException('Could not acquire package integrity lock for package #' . $packageId . '.');
                }
                $acquired[] = $lockKey;
            }
        } catch (\Throwable $e) {
            self::releaseLocks($db, $acquired);
            throw $e;
        }

        return $acquired;
    }

    /**
     * @param array<int, string> $lockKeys
     */
    public static function releaseLocks(PDO $db, array $lockKeys): void
    {
        if ($lockKeys === []) {
            return;
        }

        $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
        foreach (array_reverse($lockKeys) as $lockKey) {
            $stmt->execute([(string)$lockKey]);
        }
    }

    /**
     * @return array<int>
     */
    public static function normalizePackageIds(array $packageIds): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $packageIds),
            static fn (int $id): bool => $id > 0
        )));
        sort($normalized);
        return $normalized;
    }

    /**
     * @return array<int>
     */
    public static function assertPackagesExist(PDO $db, array $packageIds, string $message = 'Selected packages must exist.'): array
    {
        $packageIds = self::normalizePackageIds($packageIds);
        if ($packageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
        $stmt = $db->prepare("SELECT id FROM packages WHERE id IN ($placeholders)");
        $stmt->execute($packageIds);
        $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        sort($existingIds);

        if ($existingIds !== $packageIds) {
            throw new \RuntimeException($message);
        }

        return $packageIds;
    }

    /**
     * @return array<int, string>
     */
    public static function lockPackageNames(PDO $db, array $packageNames, int $timeoutSeconds = 5): array
    {
        $normalizedNames = self::normalizePackageNames($packageNames);
        if ($normalizedNames === []) {
            return [];
        }

        $acquired = [];
        $stmt = $db->prepare("SELECT GET_LOCK(?, ?)");

        try {
            foreach ($normalizedNames as $packageName) {
                $lockKey = self::nameLockKey($packageName);
                $stmt->execute([$lockKey, $timeoutSeconds]);
                if ((int)$stmt->fetchColumn() !== 1) {
                    throw new \RuntimeException('Could not acquire package name integrity lock for "' . $packageName . '".');
                }
                $acquired[] = $lockKey;
            }
        } catch (\Throwable $e) {
            self::releaseLocks($db, $acquired);
            throw $e;
        }

        return $acquired;
    }

    private static function lockKey(int $packageId): string
    {
        return 'package_integrity:' . $packageId;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizePackageNames(array $packageNames): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($name): string => strtolower(trim((string)$name)),
            $packageNames
        ))));
        sort($normalized);
        return $normalized;
    }

    private static function nameLockKey(string $packageName): string
    {
        return 'package_name:' . sha1($packageName);
    }
}
