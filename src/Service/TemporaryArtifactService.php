<?php

namespace App\Service;

final class TemporaryArtifactService
{
    private const PROD_ROOT = 'storage/cache/tmp';
    private const TEST_ROOT = 'tests/.tmp';

    public static function rootPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $relativeRoot = defined('TEST_MODE') && TEST_MODE
            ? self::TEST_ROOT
            : self::PROD_ROOT;
        $path = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('Temporary workspace storage is unavailable.');
        }

        return $path;
    }

    public static function createTempFile(string $prefix = 'fyu_'): string
    {
        $path = tempnam(self::rootPath(), self::sanitizePrefix($prefix));
        if ($path === false) {
            throw new \RuntimeException('Failed to allocate a temporary workspace file.');
        }

        return $path;
    }

    public static function createTempPath(string $prefix = 'fyu_', string $suffix = ''): string
    {
        $root = self::rootPath();
        $suffix = preg_replace('/[^A-Za-z0-9._-]/', '', $suffix) ?? '';
        $prefix = self::sanitizePrefix($prefix);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $root . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8)) . $suffix;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Failed to reserve a temporary workspace path.');
    }

    public static function createTempDirectory(string $prefix = 'fyu_'): string
    {
        $path = self::createTempPath($prefix);
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Failed to create a temporary workspace directory.');
        }

        return $path;
    }

    public static function cleanup(?string $path): void
    {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            self::deleteDirectory($path);
            return;
        }

        @unlink($path);
    }

    private static function deleteDirectory(string $path): void
    {
        $items = @scandir($path);
        if ($items === false) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                self::deleteDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }

    private static function sanitizePrefix(string $prefix): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix) ?? 'tmp_';
        $prefix = trim($prefix, '_-');
        if ($prefix === '') {
            return 'tmp_';
        }

        return substr($prefix, 0, 16) . '_';
    }
}
