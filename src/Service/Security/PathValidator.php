<?php

namespace App\Service\Security;

class PathValidator {
    public static function isSafeZipEntry(string $filename): bool {
        $filename = trim(str_replace('\\', '/', $filename));
        if ($filename === '' || str_contains($filename, "\0")) {
            return false;
        }

        $decoded = rawurldecode($filename);
        if ($decoded !== $filename) {
            $filename = $decoded;
        }

        if (str_starts_with($filename, '/') || preg_match('/^[A-Za-z]:\//', $filename) === 1) {
            return false;
        }

        foreach (explode('/', $filename) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function isSafePluginDir(string $dir): bool {
        return preg_match('/^[A-Za-z0-9_-]+$/', $dir) === 1;
    }

    public static function isPathWithinBase(string $basePath, string $candidatePath): bool
    {
        $basePath = self::normalizePath($basePath);
        $candidatePath = self::normalizePath($candidatePath);

        return $candidatePath === $basePath || str_starts_with($candidatePath . '/', $basePath . '/');
    }

    public static function buildSafeChildPath(string $basePath, string $childPath): ?string
    {
        if (!self::isSafeZipEntry($childPath)) {
            return null;
        }

        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $childPath = ltrim(str_replace('\\', '/', rawurldecode($childPath)), '/');
        $candidate = $basePath . '/' . $childPath;

        return self::isPathWithinBase($basePath, $candidate) ? $candidate : null;
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $prefix = strtoupper(substr($path, 0, 2));
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '//')) {
            $prefix = '//';
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = substr($path, 1);
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (!empty($parts)) {
                    array_pop($parts);
                }
                continue;
            }

            $parts[] = $part;
        }

        return $prefix . implode('/', $parts);
    }
}
