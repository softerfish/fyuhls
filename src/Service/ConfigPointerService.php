<?php

namespace App\Service;

use RuntimeException;

class ConfigPointerService
{
    public static function resolveLinkedConfigTarget(string $configPointerPath): ?string
    {
        if (!is_file($configPointerPath)) {
            return null;
        }

        $raw = @file_get_contents($configPointerPath);
        if (!is_string($raw) || $raw === '') {
            return $configPointerPath;
        }

        if (preg_match('/return\s+require\s+([\'"])(.+?)\1\s*;/s', $raw, $matches) !== 1) {
            return $configPointerPath;
        }

        $target = (string)$matches[2];
        if ($target === '') {
            return $configPointerPath;
        }

        if (!InstallSecurityService::isAbsolutePath($target)) {
            $target = dirname($configPointerPath) . DIRECTORY_SEPARATOR . $target;
        }

        return InstallSecurityService::normalizeAbsolutePath($target);
    }

    public static function loadLinkedConfigArray(string $configPointerPath): array
    {
        if (!is_file($configPointerPath)) {
            throw new RuntimeException('The database config pointer file is missing.');
        }

        $target = self::resolveLinkedConfigTarget($configPointerPath);
        if ($target === null || !is_file($target)) {
            throw new RuntimeException('The hidden config file referenced by config/database.php is missing or unreadable.');
        }

        try {
            $config = require $configPointerPath;
        } catch (\Throwable $e) {
            throw new RuntimeException('The database config pointer could not be loaded safely.', 0, $e);
        }

        if (!is_array($config)) {
            throw new RuntimeException('The database config pointer did not return a valid configuration array.');
        }

        return $config;
    }
}
