<?php

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;

class InstallSecurityService
{
    private const INSTALL_RESERVATION_FILENAME = 'installing.lock';

    public static function configInstallLockPath(string $projectRoot): string
    {
        return self::normalizeAbsolutePath(
            $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'installed.lock'
        );
    }

    public static function installedMarkerPath(string $projectRoot): string
    {
        return self::normalizeAbsolutePath(
            $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'installed.lock'
        );
    }

    public static function installReservationPath(string $projectRoot): string
    {
        return self::normalizeAbsolutePath(
            $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . self::INSTALL_RESERVATION_FILENAME
        );
    }

    public static function hasInstalledMarker(string $projectRoot): bool
    {
        $marker = self::installedMarkerPath($projectRoot);
        return (is_file($marker) && filesize($marker) > 0)
            || self::hasConfigInstallLock($projectRoot);
    }

    public static function hasConfigInstallLock(string $projectRoot): bool
    {
        $marker = self::configInstallLockPath($projectRoot);
        return is_file($marker) && filesize($marker) > 0;
    }

    public static function writeInstalledMarker(string $projectRoot): void
    {
        $marker = self::installedMarkerPath($projectRoot);
        $dir = dirname($marker);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $payload = 'installed:' . gmdate('c') . PHP_EOL;
        if (@file_put_contents($marker, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not persist the storage install marker.');
        }

        $configLock = self::configInstallLockPath($projectRoot);
        $configDir = dirname($configLock);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0700, true);
        }
        if (@file_put_contents($configLock, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not persist the config install lock.');
        }
    }

    public static function hasRecoveryFootprint(string $projectRoot, ?string $defaultHiddenConfigPath = null): bool
    {
        return self::hasInstalledMarker($projectRoot);
    }

    public static function defaultHiddenConfigPathForProject(string $projectRoot): string
    {
        $parent = dirname($projectRoot);
        return self::normalizeAbsolutePath(
            $parent . DIRECTORY_SEPARATOR . 'fyuhls_secure' . DIRECTORY_SEPARATOR . 'fyuhls_config.php'
        );
    }

    public static function nextAvailableHiddenConfigPath(string $projectRoot): string
    {
        $defaultPath = self::defaultHiddenConfigPathForProject($projectRoot);
        if (!file_exists($defaultPath)) {
            return $defaultPath;
        }

        $directory = dirname($defaultPath);
        $filename = pathinfo($defaultPath, PATHINFO_FILENAME);
        $extension = pathinfo($defaultPath, PATHINFO_EXTENSION);

        for ($suffix = 2; $suffix <= 500; $suffix++) {
            $candidate = self::normalizeAbsolutePath(
                $directory
                . DIRECTORY_SEPARATOR
                . $filename
                . '_'
                . $suffix
                . ($extension !== '' ? '.' . $extension : '')
            );
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not find an unused default hidden config filename. Choose a custom hidden config path to continue.');
    }

    public static function prepareSessionStoragePath(string $projectRoot, ?bool $systemSessionWritable = null): string
    {
        $localSessionPath = self::normalizeAbsolutePath($projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions');
        $systemWritable = $systemSessionWritable;
        if ($systemWritable === null) {
            $systemWritable = is_writable(session_save_path() ?: sys_get_temp_dir());
        }

        if ($systemWritable) {
            return (string)(session_save_path() ?: sys_get_temp_dir());
        }

        if (!is_dir($localSessionPath)) {
            if (!mkdir($localSessionPath, 0700, true) && !is_dir($localSessionPath)) {
                throw new RuntimeException('The local session fallback directory could not be created.');
            }
        }

        if (!is_writable($localSessionPath)) {
            throw new RuntimeException('The local session fallback directory is not writable.');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_save_path($localSessionPath);
        }
        return $localSessionPath;
    }

    public static function applySecureSessionCookieSettings(bool $secure): void
    {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if ($secure) {
            ini_set('session.cookie_secure', '1');
            return;
        }

        ini_set('session.cookie_secure', '0');
    }

    public static function acquireInstallReservation(string $projectRoot)
    {
        $reservationPath = self::installReservationPath($projectRoot);
        $reservationDir = dirname($reservationPath);
        if (!is_dir($reservationDir) && !mkdir($reservationDir, 0700, true) && !is_dir($reservationDir)) {
            throw new RuntimeException('Could not prepare the installer reservation directory.');
        }

        $handle = @fopen($reservationPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not open the installer reservation lock file.');
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another install or recovery task is already running on this server. Wait for it to finish before trying again.');
        }

        $payload = json_encode([
            'pid' => getmypid(),
            'started_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($payload)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $payload . PHP_EOL);
            fflush($handle);
        }

        return $handle;
    }

    public static function releaseInstallReservation($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with(trim($path), '/');
    }

    public static function normalizeAbsolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $absoluteRoot = str_starts_with($path, '/');

        $segments = explode('/', ltrim($path, '/'));
        $normalizedSegments = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!empty($normalizedSegments)) {
                    array_pop($normalizedSegments);
                }
                continue;
            }
            $normalizedSegments[] = $segment;
        }

        $normalized = implode('/', $normalizedSegments);

        if ($absoluteRoot) {
            return '/' . $normalized;
        }

        return $normalized;
    }

    public static function pathStartsWithBase(string $candidate, string $base): bool
    {
        $candidate = self::normalizeAbsolutePath($candidate);
        $base = self::normalizeAbsolutePath($base);

        $candidateCompare = rtrim(str_replace('\\', '/', $candidate), '/');
        $baseCompare = rtrim(str_replace('\\', '/', $base), '/');

        return $candidateCompare === $baseCompare
            || str_starts_with($candidateCompare . '/', $baseCompare . '/');
    }

    public static function validateHiddenConfigPath(string $path, string $projectRoot): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Hidden config path is required.');
        }

        if (!self::isAbsolutePath($path)) {
            throw new InvalidArgumentException('Hidden config path must be an absolute filesystem path.');
        }

        if (preg_match('/[\x00-\x1F\\\\]/', $path) === 1) {
            throw new InvalidArgumentException('Hidden config path must be a Linux absolute path without backslashes or control characters.');
        }

        $normalizedPath = self::normalizeAbsolutePath($path);
        $resolvedTargetPath = self::resolveCandidatePathFromExistingParent($normalizedPath) ?? $normalizedPath;
        $projectRoot = self::normalizeAbsolutePath($projectRoot);
        $publicRoot = self::normalizeAbsolutePath($projectRoot . '/public');
        $configRoot = self::normalizeAbsolutePath($projectRoot . '/config');

        if (!str_ends_with(strtolower($normalizedPath), '.php')) {
            throw new InvalidArgumentException('Hidden config path must point to a .php file.');
        }

        if (
            self::pathStartsWithBase($normalizedPath, $projectRoot)
            || self::pathStartsWithBase($normalizedPath, $publicRoot)
            || self::pathStartsWithBase($normalizedPath, $configRoot)
            || self::pathStartsWithBase($resolvedTargetPath, $projectRoot)
            || self::pathStartsWithBase($resolvedTargetPath, $publicRoot)
            || self::pathStartsWithBase($resolvedTargetPath, $configRoot)
        ) {
            throw new InvalidArgumentException('Hidden config path must live outside the Fyuhls webroot and config directories.');
        }

        return $normalizedPath;
    }

    private static function resolveCandidatePathFromExistingParent(string $path): ?string
    {
        $path = self::normalizeAbsolutePath($path);
        if ($path === '') {
            return null;
        }

        $search = $path;
        $tailSegments = [];
        while ($search !== '' && !file_exists($search)) {
            $parent = dirname($search);
            if ($parent === $search) {
                break;
            }

            $tailSegments[] = basename($search);
            $search = $parent;
        }

        if ($search === '' || !file_exists($search)) {
            return null;
        }

        $resolvedBase = realpath($search);
        if (!is_string($resolvedBase) || $resolvedBase === '') {
            return null;
        }

        $resolvedPath = self::normalizeAbsolutePath($resolvedBase);
        foreach (array_reverse($tailSegments) as $segment) {
            $resolvedPath = self::normalizeAbsolutePath($resolvedPath . '/' . $segment);
        }

        return $resolvedPath;
    }

    public static function writeNewHiddenConfigAtomically(string $targetPath, string $content): void
    {
        if (!self::isAbsolutePath($targetPath) || preg_match('/[\x00-\x1F\\\\]/', $targetPath) === 1) {
            throw new RuntimeException('Hidden config path must be a Linux absolute filesystem path.');
        }

        $targetPath = self::normalizeAbsolutePath($targetPath);
        if ($targetPath === '') {
            throw new RuntimeException('Hidden config path is required.');
        }

        if (!str_ends_with(strtolower($targetPath), '.php')) {
            throw new RuntimeException('Hidden config path must point to a .php file.');
        }

        if (is_file($targetPath)) {
            throw new RuntimeException('The hidden config path already exists. Choose a new empty path or restore the existing install instead of overwriting it.');
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Could not create the hidden config directory.');
            }
        }

        $tempPath = $targetPath . '.tmp.' . bin2hex(random_bytes(6));

        try {
            if (@file_put_contents($tempPath, $content, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the temporary hidden config file.');
            }

            @chmod($tempPath, 0600);

            if (!@rename($tempPath, $targetPath)) {
                throw new RuntimeException('Could not publish the hidden config file atomically.');
            }
        } catch (\Throwable $e) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException('Could not create the hidden config file safely.', 0, $e);
        }
    }

    public static function canAccessPostInstallCheck(bool $isSuperAdmin, bool $canManageConfiguration): bool
    {
        return $isSuperAdmin || $canManageConfiguration;
    }
}
