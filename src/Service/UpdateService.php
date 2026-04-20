<?php

namespace App\Service;

use App\Service\Security\PathValidator;

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
            'last_report' => $this->getLastReport(),
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

    public function previewLatestRelease(): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is not available on this server.');
        }

        [$release, $currentVersion, $latestVersion] = $this->resolveReleaseForUpdate(true);

        $lockHandle = $this->acquireLock();
        try {
            $workDir = $this->rootPath . '/storage/cache/updates/' . date('Ymd_His');
            $this->ensureDirectory($workDir);

            $archivePath = $workDir . '/release.zip';
            $this->downloadReleaseArchive($release, $archivePath);
            $sourceRoot = $this->extractArchive($archivePath, $workDir . '/extract');

            $result = $this->planRelease($sourceRoot, $currentVersion, $latestVersion, 'preview');
            $this->persistReport($result);

            return $result;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function applyLatestRelease(): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is not available on this server.');
        }

        [$release, $currentVersion, $latestVersion] = $this->resolveReleaseForUpdate(true);

        $lockHandle = $this->acquireLock();
        try {
            $runId = date('Ymd_His');
            $workDir = $this->rootPath . '/storage/cache/updates/' . $runId;
            $this->ensureDirectory($workDir);

            $archivePath = $workDir . '/release.zip';
            $this->downloadReleaseArchive($release, $archivePath);
            $sourceRoot = $this->extractArchive($archivePath, $workDir . '/extract');

            $result = $this->planRelease($sourceRoot, $currentVersion, $latestVersion, 'apply');
            $result = $this->executeRelease($sourceRoot, $result, $runId);

            $this->invalidateRuntimeCaches();
            $this->reloadVersionConfig();
            $installedVersion = (string)($this->versionConfig['version'] ?? '0.0.0');

            $result['installed_version'] = $installedVersion;
            $result['installed_version_matches_release'] = version_compare($installedVersion, $latestVersion, '==');

            $this->persistInstalledManifest($result['new_manifest']);
            $this->persistReport($result);

            return $result;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function getLastReport(): ?array
    {
        $path = $this->reportPath();
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function resolveReleaseForUpdate(bool $forceRefresh): array
    {
        $repo = trim((string)($this->versionConfig['update']['github_repo'] ?? ''));
        if ($repo === '') {
            throw new \RuntimeException('GitHub repo is not configured in config/version.php.');
        }

        $release = $this->getLatestRelease($forceRefresh);
        $currentVersion = (string)($this->versionConfig['version'] ?? '0.0.0');
        $latestVersion = $this->normalizeVersion((string)($release['tag_name'] ?? ''));

        if ($latestVersion === '' || !version_compare($latestVersion, $currentVersion, '>')) {
            throw new \RuntimeException('No newer release is available.');
        }

        return [$release, $currentVersion, $latestVersion];
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
                $this->ensureTrustedDownloadUrl($url);
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

    private function ensureTrustedDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw new \RuntimeException('Untrusted update download URL: ' . $url);
        }

        $allowedHosts = [
            'github.com',
            'codeload.github.com',
            'objects.githubusercontent.com',
        ];

        if (!in_array($host, $allowedHosts, true)) {
            throw new \RuntimeException('Untrusted update download URL: ' . $url);
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

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string)$zip->getNameIndex($i);
            if ($entryName === '') {
                continue;
            }

            $normalizedEntry = str_replace('\\', '/', $entryName);
            $isDirectory = str_ends_with($normalizedEntry, '/');
            $safeRelativePath = trim($normalizedEntry, '/');
            if ($safeRelativePath === '') {
                continue;
            }

            if (!PathValidator::isSafeZipEntry($safeRelativePath)) {
                $zip->close();
                throw new \RuntimeException('Release archive contains an unsafe path: ' . $entryName);
            }

            $targetPath = PathValidator::buildSafeChildPath($extractPath, $safeRelativePath);
            if ($targetPath === null) {
                $zip->close();
                throw new \RuntimeException('Release archive path escapes the extraction directory: ' . $entryName);
            }

            if ($isDirectory) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            $sourceStream = $zip->getStream($entryName);
            if ($sourceStream === false) {
                $zip->close();
                throw new \RuntimeException('Failed to read a file from the release archive: ' . $entryName);
            }

            $targetStream = fopen($targetPath, 'wb');
            if ($targetStream === false) {
                fclose($sourceStream);
                $zip->close();
                throw new \RuntimeException('Failed to create an extracted file: ' . $safeRelativePath);
            }

            stream_copy_to_stream($sourceStream, $targetStream);
            fclose($sourceStream);
            fclose($targetStream);
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

    private function planRelease(string $sourceRoot, string $currentVersion, string $latestVersion, string $mode): array
    {
        $preservePaths = $this->preservePaths();
        $skipPaths = ['.git', '.github'];
        $newManifest = $this->buildReleaseManifest($sourceRoot, $preservePaths, $skipPaths, $latestVersion);
        $oldManifest = $this->loadInstalledManifest();

        $staleAnalysis = $this->analyzeStaleCoreFiles($oldManifest, $newManifest, $preservePaths, $skipPaths);
        $copyAnalysis = $this->analyzeCopyActions($sourceRoot, $newManifest);

        return [
            'mode' => $mode,
            'generated_at' => date('c'),
            'from_version' => $currentVersion,
            'to_version' => $latestVersion,
            'preserve_paths' => $preservePaths,
            'manifest_path' => $this->manifestPath(),
            'report_path' => $this->reportPath(),
            'files_copied' => 0,
            'files_unchanged' => (int)$copyAnalysis['unchanged_count'],
            'directories_created' => 0,
            'files_backed_up' => 0,
            'stale_candidates' => (int)$staleAnalysis['candidate_count'],
            'stale_quarantined' => 0,
            'stale_missing' => (int)$staleAnalysis['missing_count'],
            'stale_modified_skipped' => (int)$staleAnalysis['modified_count'],
            'copy_candidates' => (int)$copyAnalysis['copy_count'],
            'new_manifest' => $newManifest,
            'previous_manifest' => $oldManifest,
            'quarantine_candidates' => $staleAnalysis['quarantine_candidates'],
            'stale_modified_files' => $staleAnalysis['modified_files'],
            'stale_missing_files' => $staleAnalysis['missing_files'],
            'copy_changed_files' => $copyAnalysis['changed_files'],
            'copy_unchanged_files' => $copyAnalysis['unchanged_files'],
            'backup_root' => '',
            'quarantine_root' => '',
        ];
    }

    private function executeRelease(string $sourceRoot, array $result, string $runId): array
    {
        $backupRoot = $this->rootPath . '/storage/update_backups/' . $runId;
        $quarantineRoot = $this->rootPath . '/storage/update_quarantine/' . $runId;

        $result['backup_root'] = $this->relativeDisplayPath($backupRoot);
        $result['quarantine_root'] = $this->relativeDisplayPath($quarantineRoot);

        $quarantineResult = $this->quarantineStaleFiles($result['quarantine_candidates'], $quarantineRoot);
        $result['stale_quarantined'] = $quarantineResult['files_quarantined'];
        $result['directories_created'] = $quarantineResult['directories_created'];

        $copyResult = $this->copyReleaseIntoPlace($sourceRoot, $result['new_manifest'], $backupRoot);
        $result['files_copied'] = $copyResult['files_copied'];
        $result['directories_created'] += $copyResult['directories_created'];
        $result['files_backed_up'] = $copyResult['files_backed_up'];

        return $result;
    }

    private function buildReleaseManifest(string $sourceRoot, array $preservePaths, array $skipPaths, string $version): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $sourcePath = $item->getPathname();
            $relativePath = $this->normalizeRelativePath(substr($sourcePath, strlen($sourceRoot) + 1));
            if ($relativePath === '') {
                continue;
            }

            if ($this->shouldSkip($relativePath, $preservePaths) || $this->shouldSkip($relativePath, $skipPaths)) {
                continue;
            }

            $files[$relativePath] = [
                'sha256' => hash_file('sha256', $sourcePath),
                'size' => (int)$item->getSize(),
            ];
        }

        ksort($files);

        return [
            'version' => $version,
            'generated_at' => date('c'),
            'files' => $files,
        ];
    }

    private function analyzeStaleCoreFiles(array $oldManifest, array $newManifest, array $preservePaths, array $skipPaths): array
    {
        $oldFiles = (array)($oldManifest['files'] ?? []);
        $newFiles = (array)($newManifest['files'] ?? []);

        $quarantineCandidates = [];
        $modifiedFiles = [];
        $missingFiles = [];

        foreach ($oldFiles as $relativePath => $meta) {
            $relativePath = $this->normalizeRelativePath((string)$relativePath);
            if ($relativePath === '' || isset($newFiles[$relativePath])) {
                continue;
            }

            if ($this->shouldSkip($relativePath, $preservePaths) || $this->shouldSkip($relativePath, $skipPaths)) {
                continue;
            }

            $fullPath = $this->rootPath . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($fullPath)) {
                $missingFiles[] = $relativePath;
                continue;
            }

            $currentChecksum = hash_file('sha256', $fullPath);
            $expectedChecksum = (string)($meta['sha256'] ?? '');
            if ($expectedChecksum !== '' && hash_equals($expectedChecksum, $currentChecksum)) {
                $quarantineCandidates[] = $relativePath;
                continue;
            }

            $modifiedFiles[] = $relativePath;
        }

        sort($quarantineCandidates);
        sort($modifiedFiles);
        sort($missingFiles);

        return [
            'candidate_count' => count($quarantineCandidates),
            'modified_count' => count($modifiedFiles),
            'missing_count' => count($missingFiles),
            'quarantine_candidates' => $quarantineCandidates,
            'modified_files' => $modifiedFiles,
            'missing_files' => $missingFiles,
        ];
    }

    private function analyzeCopyActions(string $sourceRoot, array $newManifest): array
    {
        $changedFiles = [];
        $unchangedFiles = [];

        foreach ((array)($newManifest['files'] ?? []) as $relativePath => $meta) {
            $sourcePath = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $destinationPath = $this->rootPath . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (is_file($destinationPath) && hash_file('sha256', $destinationPath) === (string)($meta['sha256'] ?? '')) {
                $unchangedFiles[] = $relativePath;
                continue;
            }

            $changedFiles[] = $relativePath;
        }

        return [
            'copy_count' => count($changedFiles),
            'unchanged_count' => count($unchangedFiles),
            'changed_files' => $changedFiles,
            'unchanged_files' => $unchangedFiles,
        ];
    }

    private function copyReleaseIntoPlace(string $sourceRoot, array $newManifest, string $backupRoot): array
    {
        $filesCopied = 0;
        $directoriesCreated = 0;
        $filesBackedUp = 0;

        foreach ((array)($newManifest['files'] ?? []) as $relativePath => $meta) {
            $sourcePath = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $destinationPath = $this->rootPath . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if (is_dir($destinationPath)) {
                if (!$this->removeEmptyDirectoryTree($destinationPath)) {
                    throw new \RuntimeException('Update path conflict: destination is a non-empty directory: ' . $relativePath);
                }
            }

            $parent = dirname($destinationPath);
            if (is_file($parent)) {
                throw new \RuntimeException('Update path conflict: parent path is a file: ' . $this->relativeDisplayPath($parent));
            }
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
                $directoriesCreated++;
            }

            if (is_file($destinationPath) && hash_file('sha256', $destinationPath) === (string)($meta['sha256'] ?? '')) {
                continue;
            }

            if (is_file($destinationPath)) {
                $backupPath = $backupRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $backupParent = dirname($backupPath);
                if (!is_dir($backupParent)) {
                    mkdir($backupParent, 0755, true);
                    $directoriesCreated++;
                }
                if (!copy($destinationPath, $backupPath)) {
                    throw new \RuntimeException('Failed to back up existing file before update: ' . $relativePath);
                }
                $filesBackedUp++;
            }

            if (!copy($sourcePath, $destinationPath)) {
                throw new \RuntimeException('Failed to copy update file: ' . $relativePath);
            }

            $filesCopied++;
        }

        return [
            'files_copied' => $filesCopied,
            'directories_created' => $directoriesCreated,
            'files_backed_up' => $filesBackedUp,
        ];
    }

    private function quarantineStaleFiles(array $relativePaths, string $quarantineRoot): array
    {
        $filesQuarantined = 0;
        $directoriesCreated = 0;

        foreach ($relativePaths as $relativePath) {
            $sourcePath = $this->rootPath . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($sourcePath)) {
                continue;
            }

            $quarantinePath = $quarantineRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $parent = dirname($quarantinePath);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
                $directoriesCreated++;
            }

            if (!@rename($sourcePath, $quarantinePath)) {
                if (!copy($sourcePath, $quarantinePath) || !unlink($sourcePath)) {
                    throw new \RuntimeException('Failed to quarantine stale core file: ' . $relativePath);
                }
            }

            $filesQuarantined++;
            $this->pruneEmptyParents(dirname($sourcePath));
        }

        return [
            'files_quarantined' => $filesQuarantined,
            'directories_created' => $directoriesCreated,
        ];
    }

    private function preservePaths(): array
    {
        return array_map([$this, 'normalizeRelativePath'], (array)($this->versionConfig['update']['preserve_paths'] ?? []));
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

    private function manifestPath(): string
    {
        return $this->rootPath . '/storage/cache/update_manifest.json';
    }

    private function reportPath(): string
    {
        return $this->rootPath . '/storage/cache/update_report.json';
    }

    private function loadInstalledManifest(): array
    {
        $path = $this->manifestPath();
        if (!is_file($path)) {
            return [
                'version' => (string)($this->versionConfig['version'] ?? ''),
                'generated_at' => null,
                'files' => [],
            ];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [
                'version' => (string)($this->versionConfig['version'] ?? ''),
                'generated_at' => null,
                'files' => [],
            ];
        }

        return $decoded;
    }

    private function persistInstalledManifest(array $manifest): void
    {
        $path = $this->manifestPath();
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function persistReport(array $report): void
    {
        $path = $this->reportPath();
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function relativeDisplayPath(string $absolutePath): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/');
        $absolute = str_replace('\\', '/', $absolutePath);
        if (str_starts_with($absolute, $root . '/')) {
            return ltrim(substr($absolute, strlen($root)), '/');
        }

        return $absolute;
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

    private function pruneEmptyParents(string $path): void
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/');
        $current = str_replace('\\', '/', $path);

        while ($current !== '' && $current !== $root && str_starts_with($current, $root . '/')) {
            if (!is_dir($current)) {
                break;
            }

            $entries = array_values(array_filter(scandir($current) ?: [], static function ($entry) {
                return $entry !== '.' && $entry !== '..';
            }));

            if ($entries !== []) {
                break;
            }

            @rmdir($current);
            $current = str_replace('\\', '/', dirname($current));
        }
    }

    private function removeEmptyDirectoryTree(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $entries = array_values(array_filter(scandir($path) ?: [], static function ($entry) {
            return $entry !== '.' && $entry !== '..';
        }));

        foreach ($entries as $entry) {
            $childPath = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($childPath)) {
                if (!$this->removeEmptyDirectoryTree($childPath)) {
                    return false;
                }
                continue;
            }

            return false;
        }

        return @rmdir($path) || !is_dir($path);
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
