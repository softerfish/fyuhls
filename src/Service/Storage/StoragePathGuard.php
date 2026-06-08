<?php

namespace App\Service\Storage;

use App\Service\SafeNetworkTargetService;

final class StoragePathGuard
{
    public static function normalizeLocalRootPath(string $path, ?string $basePath = null): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \RuntimeException('Local storage path is not configured.');
        }

        $projectRoot = $basePath !== null && $basePath !== ''
            ? $basePath
            : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3));

        $safeRoots = self::allowedLocalRootPrefixes($projectRoot);
        $candidate = self::isAbsoluteFilesystemPath($path)
            ? self::canonicalizePath($path)
            : self::canonicalizePath(self::joinPath($projectRoot, ltrim(self::normalizeFilesystemSeparators($path), DIRECTORY_SEPARATOR)));

        foreach ($safeRoots as $safeRoot) {
            if (self::pathStartsWith($candidate, $safeRoot)) {
                return rtrim(self::normalizeFilesystemSeparators($candidate), DIRECTORY_SEPARATOR);
            }
        }

        throw new \RuntimeException('Local storage paths must stay inside the Fyuhls storage directory.');
    }

    public static function absoluteObjectPath(string $rootPath, string $objectPath, string $label = 'Storage object key'): string
    {
        $rootPath = trim($rootPath);
        if ($rootPath === '') {
            throw new \RuntimeException('Local storage root is not configured.');
        }

        $normalizedRoot = self::canonicalizePath($rootPath);
        $normalizedObject = self::normalizeObjectPath($objectPath, $label);
        $candidate = self::canonicalizePath($normalizedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedObject));

        if (!self::pathStartsWith($candidate, $normalizedRoot)) {
            throw new \RuntimeException($label . ' must stay inside the configured storage root.');
        }

        return $candidate;
    }

    public static function normalizeObjectPath(string $path, string $label = 'Storage object key'): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            throw new \RuntimeException($label . ' is required.');
        }

        if (preg_match('/[\x00-\x1F]/', $path) === 1) {
            throw new \RuntimeException($label . ' contains invalid control characters.');
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new \RuntimeException($label . ' must be a relative object path.');
        }

        $segments = explode('/', $path);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException($label . ' contains invalid path traversal segments.');
            }

            if (preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
                throw new \RuntimeException($label . ' contains unsupported characters.');
            }

            $normalized[] = $segment;
        }

        return implode('/', $normalized);
    }

    public static function normalizeBucketName(string $bucket): string
    {
        $bucket = trim($bucket);
        if ($bucket === '') {
            throw new \RuntimeException('Storage bucket name is required.');
        }

        return $bucket;
    }

    public static function normalizeS3Endpoint(string $endpoint, string $label = 'Storage endpoint'): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }

        if (!str_starts_with($endpoint, 'http://') && !str_starts_with($endpoint, 'https://')) {
            $endpoint = 'https://' . $endpoint;
        }

        return rtrim(SafeNetworkTargetService::normalizePublicHttpUrl($endpoint, $label, false), '/');
    }

    private static function allowedLocalRootPrefixes(string $projectRoot): array
    {
        $roots = [
            self::canonicalizePath(self::joinPath($projectRoot, 'storage')),
        ];

        $testScratchRoot = self::canonicalizePath(self::joinPath($projectRoot, 'tests' . DIRECTORY_SEPARATOR . '.tmp'));
        $roots[] = $testScratchRoot;

        return array_values(array_unique($roots));
    }

    private static function normalizeFilesystemSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function joinPath(string $base, string $suffix): string
    {
        $base = rtrim(self::normalizeFilesystemSeparators($base), DIRECTORY_SEPARATOR);
        $suffix = ltrim(self::normalizeFilesystemSeparators($suffix), DIRECTORY_SEPARATOR);

        return $base . DIRECTORY_SEPARATOR . $suffix;
    }

    private static function isAbsoluteFilesystemPath(string $path): bool
    {
        return $path !== '' && (
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/')
        );
    }

    private static function canonicalizePath(string $path): string
    {
        $normalized = self::normalizeFilesystemSeparators($path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:\\\\/', $normalized) === 1) {
            $prefix = strtoupper(substr($normalized, 0, 2)) . DIRECTORY_SEPARATOR;
            $normalized = substr($normalized, 3);
        } elseif (str_starts_with($normalized, '\\\\')) {
            $prefix = '\\\\';
            $normalized = ltrim(substr($normalized, 2), '\\');
        } elseif (str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);
        }

        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $normalized) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (!empty($parts) && end($parts) !== '..') {
                    array_pop($parts);
                    continue;
                }

                if ($prefix === '') {
                    $parts[] = '..';
                }
                continue;
            }

            $parts[] = $part;
        }

        return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private static function pathStartsWith(string $candidate, string $base): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');

        return $candidate === $base || str_starts_with($candidate . '/', $base . '/');
    }
}
