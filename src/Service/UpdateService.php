<?php

namespace App\Service;

class UpdateService
{
    private string $rootPath;
    private array $versionConfig;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2));
        $this->reloadVersionConfig();
    }

    public function getStatus(bool $forceRefresh = false): array
    {
        $this->reloadVersionConfig();
        $currentVersion = (string)($this->versionConfig['version'] ?? '0.0.0');
        $repo = trim((string)($this->versionConfig['update']['github_repo'] ?? ''));

        $status = [
            'current_version' => $currentVersion,
            'latest_version' => $currentVersion,
            'repo' => $repo,
            'repo_configured' => $repo !== '',
            'update_available' => false,
            'release_url' => '',
            'error' => '',
        ];

        if ($repo === '') {
            return $status;
        }

        try {
            $release = $this->getLatestRelease($forceRefresh);
            $latestVersion = $this->normalizeVersion((string)($release['tag_name'] ?? ''));
            if ($latestVersion === '') {
                throw new \RuntimeException('Latest release tag is missing or invalid.');
            }

            $status['latest_version'] = $latestVersion;
            $status['release_url'] = (string)($release['html_url'] ?? '');
            $status['update_available'] = version_compare($latestVersion, $currentVersion, '>');
        } catch (\Throwable $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    public function applyLatestRelease(): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is not available on this server.');
        }

        $repo = trim((string)($this->versionConfig['update']['github_repo'] ?? ''));
        if ($repo === '') {
            throw new \RuntimeException('GitHub repo is not configured in config/version.php.');
        }

        $release = $this->getLatestRelease(true);
        $currentVersion = (string)($this->versionConfig['version'] ?? '0.0.0');
        $latestVersion = $this->normalizeVersion((string)($release['tag_name'] ?? ''));

        if ($latestVersion === '' || !version_compare($latestVersion, $currentVersion, '>')) {
            throw new \RuntimeException('No newer release is available.');
        }

        $lockHandle = $this->acquireLock();
        try {
            $workDir = $this->rootPath . '/storage/cache/updates/' . date('Ymd_His');
            $this->ensureDirectory($workDir);

            $archivePath = $workDir . '/release.zip';
            $this->downloadReleaseArchive($release, $archivePath);
            $sourceRoot = $this->extractArchive($archivePath, $workDir . '/extract');
            $result = $this->copyReleaseIntoPlace($sourceRoot);
            $this->invalidateRuntimeCaches();
            $this->reloadVersionConfig();
            $installedVersion = (string)($this->versionConfig['version'] ?? '0.0.0');

            $result['from_version'] = $currentVersion;
            $result['to_version'] = $latestVersion;
            $result['installed_version'] = $installedVersion;
            $result['installed_version_matches_release'] = version_compare($installedVersion, $latestVersion, '==');

            return $result;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function getLatestRelease(bool $forceRefresh = false): array
    {
        $cacheFile = $this->rootPath . '/storage/cache/update_release.json';
        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $repo = trim((string)($this->versionConfig['update']['github_repo'] ?? ''));
        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $release = $this->requestJson($url);

        $this->ensureDirectory(dirname($cacheFile));
        file_put_contents($cacheFile, json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $release;
    }

    private function requestJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: Fyuhls-Updater',
            ],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('GitHub request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GitHub returned an invalid response.');
        }

        if ($httpCode >= 400) {
            $message = (string)($decoded['message'] ?? 'HTTP ' . $httpCode);
            throw new \RuntimeException('GitHub release check failed: ' . $message);
        }

        return $decoded;
    }

    private function resolveDownloadUrls(array $release): array
    {
        $repo = trim((string)($this->versionConfig['update']['github_repo'] ?? ''));
        $tagName = trim((string)($release['tag_name'] ?? ''));
        $preferredAsset = trim((string)($this->versionConfig['update']['release_asset_name'] ?? ''));
        $assets = $release['assets'] ?? [];
        $urls = [];
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                $name = (string)($asset['name'] ?? '');
                $url = (string)($asset['browser_download_url'] ?? '');
                if ($preferredAsset !== '' && strcasecmp($name, $preferredAsset) === 0) {
                    $urls[] = $url;
                }
            }

            foreach ($assets as $asset) {
                $name = strtolower((string)($asset['name'] ?? ''));
                $url = (string)($asset['browser_download_url'] ?? '');
                if ($url !== '' && str_ends_with($name, '.zip')) {
                    $urls[] = $url;
                }
            }
        }

        if ($repo !== '' && $tagName !== '') {
            $urls[] = 'https://codeload.github.com/' . $repo . '/zip/refs/tags/' . rawurlencode($tagName);
        }

        $zipball = (string)($release['zipball_url'] ?? '');
        if ($zipball !== '') {
            $urls[] = $zipball;
        }

        return array_values(array_unique(array_filter($urls, static fn(string $url): bool => trim($url) !== '')));
    }

    private function downloadReleaseArchive(array $release, string $destination): void
    {
        $urls = $this->resolveDownloadUrls($release);
        if ($urls === []) {
            throw new \RuntimeException('No release archive is available for download.');
        }

        $errors = [];
        foreach ($urls as $url) {
            try {
                $this->downloadFile($url, $destination);
                if (!$this->isZipArchive($destination)) {
                    @unlink($destination);
                    throw new \RuntimeException('Downloaded file is not a valid zip archive.');
                }
                return;
            } catch (\Throwable $e) {
                $errors[] = basename(parse_url($url, PHP_URL_PATH) ?: 'download') . ': ' . $e->getMessage();
            }
        }

        throw new \RuntimeException('Release download failed after trying all sources. ' . implode(' | ', $errors));
    }

    private function downloadFile(string $url, string $destination): void
    {
        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Failed to create temporary archive file.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream, application/zip, */*',
                'User-Agent: Fyuhls-Updater',
            ],
        ]);

        $ok = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $httpCode >= 400) {
            @unlink($destination);
            throw new \RuntimeException('Release download failed: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode));
        }
    }

    private function isZipArchive(string $path): bool
    {
        if (!is_file($path) || filesize($path) < 4) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }

    private function extractArchive(string $archivePath, string $extractPath): string
    {
        $this->ensureDirectory($extractPath);
        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new \RuntimeException('Failed to open the downloaded release archive.');
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new \RuntimeException('Failed to extract the release archive.');
        }
        $zip->close();

        $entries = array_values(array_filter(scandir($extractPath) ?: [], static function ($entry) {
            return $entry !== '.' && $entry !== '..';
        }));

        if (count($entries) === 1 && is_dir($extractPath . '/' . $entries[0])) {
            return $extractPath . '/' . $entries[0];
        }

        return $extractPath;
    }

    private function copyReleaseIntoPlace(string $sourceRoot): array
    {
        $preservePaths = array_map([$this, 'normalizeRelativePath'], (array)($this->versionConfig['update']['preserve_paths'] ?? []));
        $skipPaths = ['.git', '.github'];

        $filesCopied = 0;
        $directoriesCreated = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = $this->normalizeRelativePath(substr($sourcePath, strlen($sourceRoot) + 1));
            if ($relativePath === '') {
                continue;
            }

            if ($this->shouldSkip($relativePath, $preservePaths) || $this->shouldSkip($relativePath, $skipPaths)) {
                continue;
            }

            $destinationPath = $this->rootPath . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if ($item->isDir()) {
                if (!is_dir($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                    $directoriesCreated++;
                }
                continue;
            }

            $parent = dirname($destinationPath);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
                $directoriesCreated++;
            }

            if (!copy($sourcePath, $destinationPath)) {
                throw new \RuntimeException('Failed to copy update file: ' . $relativePath);
            }

            $filesCopied++;
        }

        return [
            'files_copied' => $filesCopied,
            'directories_created' => $directoriesCreated,
        ];
    }

    private function shouldSkip(string $relativePath, array $skipList): bool
    {
        foreach ($skipList as $skipPath) {
            $skipPath = $this->normalizeRelativePath($skipPath);
            if ($skipPath === '') {
                continue;
            }

            if ($relativePath === $skipPath || str_starts_with($relativePath, $skipPath . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        return ltrim($version, "vV");
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        return trim($path, '/');
    }

    private function reloadVersionConfig(): void
    {
        $configPath = $this->rootPath . '/config/version.php';
        clearstatcache(true, $configPath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        }

        $config = file_exists($configPath) ? require $configPath : [];
        $this->versionConfig = is_array($config) ? $config : [];
    }

    private function invalidateRuntimeCaches(): void
    {
        $paths = [
            $this->rootPath . '/config/version.php',
            $this->rootPath . '/main/index.html',
            $this->rootPath . '/main/documentation.html',
        ];

        foreach ($paths as $path) {
            clearstatcache(true, $path);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('Failed to create directory: ' . $path);
        }
    }

    private function acquireLock()
    {
        $lockPath = $this->rootPath . '/storage/cache/update.lock';
        $this->ensureDirectory(dirname($lockPath));
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to create the update lock file.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another update is already in progress.');
        }

        return $handle;
    }
}
