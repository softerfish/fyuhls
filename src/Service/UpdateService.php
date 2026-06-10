<?php

namespace App\Service;

use App\Model\Setting;
use App\Service\Security\PathValidator;
use ZipArchive;

class UpdateService
{
    private const GITHUB_API_BASE = 'https://api.github.com';
    private const MAX_ARCHIVE_BYTES = 104857600;
    private const MAX_EXTRACTED_BYTES = 524288000;
    private const MAX_ARCHIVE_FILES = 20000;
    private const MAX_SINGLE_FILE_BYTES = 52428800;

    /** @var null|callable(string): array */
    private $latestReleaseFetcher;

    /** @var null|callable(string, string): void */
    private $archiveDownloader;

    /** @var array<string, callable> */
    private array $environmentChecks;

    /** @var list<callable(string): string|null> */
    private array $releaseTransports;

    private string $projectRoot;

    public function __construct(
        ?callable $latestReleaseFetcher = null,
        ?string $projectRoot = null,
        ?callable $archiveDownloader = null,
        array $environmentChecks = [],
        array $releaseTransports = []
    )
    {
        $this->latestReleaseFetcher = $latestReleaseFetcher;
        $this->archiveDownloader = $archiveDownloader;
        $this->environmentChecks = array_filter($environmentChecks, 'is_callable');
        $this->releaseTransports = array_values(array_filter($releaseTransports, 'is_callable'));
        $this->projectRoot = $projectRoot !== null
            ? rtrim($projectRoot, '/\\')
            : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2));
    }

    public function getStatus(bool $refresh = false): array
    {
        $versionConfig = $this->readVersionConfig();
        $currentVersion = trim((string)($versionConfig['version'] ?? ''));
        $updateConfig = is_array($versionConfig['update'] ?? null) ? $versionConfig['update'] : [];
        $repo = trim((string)($updateConfig['github_repo'] ?? ''));

        $status = [
            'update_available' => false,
            'apply_available' => false,
            'current_version' => $currentVersion !== '' ? $currentVersion : 'unknown',
            'latest_version' => null,
            'checked_at' => null,
            'repo_configured' => $repo !== '',
            'repo' => $repo !== '' ? $repo : null,
            'release_url' => null,
            'last_update_report' => $this->latestCompletedUpdateReportSummary(),
            'apply_blockers' => [],
            'error' => null,
        ];

        if ($repo === '') {
            return $status;
        }

        if (!$this->isValidGitHubRepo($repo)) {
            $status['error'] = 'The configured updater repository is invalid. Expected owner/repository.';
            return $status;
        }

        $currentNormalized = $this->normalizeVersion($currentVersion);
        if ($currentNormalized === null) {
            $status['error'] = 'The installed version could not be parsed safely. Use the manual upgrade path.';
            return $status;
        }

        $status['checked_at'] = date('c');

        try {
            $release = $this->fetchLatestRelease($repo, $refresh);
        } catch (\Throwable $e) {
            $status['error'] = 'Could not check the latest GitHub release. Try again later or use the manual upgrade path.';
            $status['error_detail'] = $this->sanitizeDiagnostic($e->getMessage());
            return $status;
        }

        if (!empty($release['draft'])) {
            $status['error'] = 'The latest GitHub release is a draft and cannot be installed.';
            return $status;
        }

        if (!empty($release['prerelease'])) {
            $status['error'] = 'The latest GitHub release is a prerelease. Prerelease one-click updates are disabled.';
            return $status;
        }

        $latestVersion = trim((string)($release['tag_name'] ?? ''));
        if ($latestVersion === '') {
            $latestVersion = trim((string)($release['name'] ?? ''));
        }

        $latestNormalized = $this->normalizeVersion($latestVersion);
        if ($latestNormalized === null) {
            $status['error'] = 'The latest GitHub release version could not be parsed safely.';
            return $status;
        }

        $status['latest_version'] = $latestVersion;
        $status['release_url'] = isset($release['html_url']) && is_string($release['html_url'])
            ? $this->normalizeGitHubReleaseUrl($release['html_url'])
            : null;
        $status['update_available'] = version_compare($latestNormalized, $currentNormalized, '>');
        if ($status['update_available']) {
            $status['apply_blockers'] = $this->applyAvailabilityBlockers();
            $status['apply_available'] = $status['apply_blockers'] === [];
        }

        return $status;
    }

    public function previewLatestRelease(bool $refresh = true): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The PHP ZipArchive extension is required to preview updates.');
        }

        $package = $this->prepareLatestReleaseArchive($refresh);
        try {
            $preview = $package['preview'];
            unset($preview['validated_entries']);
            return $preview;
        } finally {
            if (is_file($package['archive_path'])) {
                @unlink($package['archive_path']);
            }
        }
    }

    /**
     * @return array{archive_path: string, preview: array<string, mixed>}
     */
    private function prepareLatestReleaseArchive(bool $refresh): array
    {
        $versionConfig = $this->readVersionConfig();
        $updateConfig = is_array($versionConfig['update'] ?? null) ? $versionConfig['update'] : [];
        $repo = trim((string)($updateConfig['github_repo'] ?? ''));
        $currentVersion = trim((string)($versionConfig['version'] ?? ''));
        if (!$this->isValidGitHubRepo($repo)) {
            throw new \RuntimeException('The configured updater repository is invalid.');
        }

        $currentNormalized = $this->normalizeVersion($currentVersion);
        if ($currentNormalized === null) {
            throw new \RuntimeException('The installed version could not be parsed safely.');
        }

        $release = $this->fetchLatestRelease($repo, $refresh);
        $latestVersion = $this->validatedReleaseVersion($release);
        $latestNormalized = $this->normalizeVersion($latestVersion);
        if ($latestNormalized === null || !version_compare($latestNormalized, $currentNormalized, '>')) {
            throw new \RuntimeException('The latest GitHub release is not newer than the installed version.');
        }

        $archiveSource = $this->selectArchiveSource($release, $updateConfig);
        $downloadDirectory = $this->projectRoot . '/storage/cache/update_downloads';
        $this->ensureDirectory($downloadDirectory);
        $archivePath = $downloadDirectory . '/preview-' . bin2hex(random_bytes(12)) . '.zip';

        try {
            $this->downloadArchive($archiveSource['url'], $archivePath);
            $archiveSize = filesize($archivePath);
            if (!is_int($archiveSize) || $archiveSize <= 0 || $archiveSize > self::MAX_ARCHIVE_BYTES) {
                throw new \RuntimeException('The update archive is empty or exceeds the maximum allowed size.');
            }

            $preview = $this->inspectArchive(
                $archivePath,
                $archiveSize,
                $currentVersion,
                $latestVersion,
                $archiveSource,
                $this->configuredPreservePaths($updateConfig)
            );

            return ['archive_path' => $archivePath, 'preview' => $preview];
        } catch (\Throwable $e) {
            if (is_file($archivePath)) {
                @unlink($archivePath);
            }
            throw $e;
        }
    }

    public function preflightLatestRelease(bool $refresh = true): array
    {
        if ($this->isDemoModeEnabled()) {
            throw new \RuntimeException('One-click updates are disabled while demo mode is enabled.');
        }

        $lockHandle = $this->acquireUpdateLock();
        try {
            if (!$this->isMaintenanceModeEnabled()) {
                throw new \RuntimeException('Enable maintenance mode before running the one-click update preflight.');
            }

            // Preview fetches the release again under the lock and rejects equal or older versions.
            $preview = $this->previewLatestRelease($refresh);
            $this->assertWritableTargets($preview);
            $this->assertSufficientDiskSpace($preview);
            $this->assertComposerCompatibility($preview);

            return $preview + [
                'preflight_ready' => true,
                'version_rechecked' => true,
                'maintenance_mode_confirmed' => true,
                'updater_lock_confirmed' => true,
                'apply_available' => false,
            ];
        } finally {
            $this->releaseUpdateLock($lockHandle);
        }
    }

    public function applyLatestRelease(): array
    {
        if ($this->isDemoModeEnabled()) {
            throw new \RuntimeException('One-click updates are disabled while demo mode is enabled.');
        }

        $archivePath = null;
        $lockHandle = $this->acquireUpdateLock();
        try {
            if (!$this->isMaintenanceModeEnabled()) {
                throw new \RuntimeException('Enable maintenance mode before applying a one-click update.');
            }

            $this->assertNoIncompleteUpdateRun();
            $package = $this->prepareLatestReleaseArchive(true);
            $archivePath = $package['archive_path'];
            $preview = $package['preview'];
            $this->assertWritableTargets($preview);
            $this->assertSufficientDiskSpace($preview);
            $this->assertComposerCompatibility($preview);

            return $this->applyValidatedPackage($archivePath, $preview);
        } finally {
            if (is_string($archivePath) && is_file($archivePath)) {
                @unlink($archivePath);
            }
            $this->releaseUpdateLock($lockHandle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readVersionConfig(): array
    {
        $versionFile = $this->projectRoot . '/config/version.php';
        if (!is_file($versionFile)) {
            return [];
        }

        $config = include $versionFile;
        return is_array($config) ? $config : [];
    }

    private function isValidGitHubRepo(string $repo): bool
    {
        return (bool)preg_match('/\\A[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+\\z/', $repo);
    }

    private function normalizeVersion(string $version): ?string
    {
        $normalized = trim($version);
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/\\Av(?=\\d)/i', '', $normalized) ?? $normalized;
        if (!preg_match('/\\A\\d+(?:\\.\\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?\\z/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLatestRelease(string $repo, bool $refresh): array
    {
        if ($this->latestReleaseFetcher !== null) {
            $release = ($this->latestReleaseFetcher)($repo);
            if (!is_array($release)) {
                throw new \RuntimeException('Updater release fetcher returned an invalid response.');
            }
            return $release;
        }

        $url = self::GITHUB_API_BASE . '/repos/' . rawurlencode(explode('/', $repo)[0])
            . '/' . rawurlencode(explode('/', $repo)[1]) . '/releases/latest';

        $json = $this->fetchLatestReleaseJson($url);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GitHub latest release response was invalid JSON.');
        }

        if (isset($decoded['message']) && !isset($decoded['tag_name'])) {
            throw new \RuntimeException('GitHub latest release lookup failed.');
        }

        return $decoded;
    }

    private function fetchLatestReleaseJson(string $url): string
    {
        $failures = [];
        $transports = $this->releaseTransports;
        if ($transports === []) {
            $transports = [
                fn (string $targetUrl): ?string => $this->fetchLatestReleaseJsonWithStreams($targetUrl),
                fn (string $targetUrl): ?string => $this->fetchLatestReleaseJsonWithCurl($targetUrl),
            ];
        }

        foreach ($transports as $transport) {
            try {
                $json = $transport($url);
                if (is_string($json) && trim($json) !== '') {
                    $decoded = json_decode($json, true);
                    if (!is_array($decoded)) {
                        $failures[] = 'transport returned invalid JSON';
                        continue;
                    }
                    return $json;
                }
                $failures[] = 'transport returned an empty response';
            } catch (\Throwable $e) {
                $failures[] = $this->sanitizeDiagnostic($e->getMessage());
            }
        }

        $suffix = $failures !== [] ? ' Details: ' . implode(' | ', array_unique($failures)) : '';
        throw new \RuntimeException('GitHub latest release response was empty.' . $suffix);
    }

    private function fetchLatestReleaseJsonWithStreams(string $url): ?string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('PHP URL fopen wrappers are disabled.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    'User-Agent: Fyuhls-Updater',
                ]),
            ],
        ]);

        $json = @file_get_contents($url, false, $context);
        if (!is_string($json) || trim($json) === '') {
            throw new \RuntimeException('GitHub latest release response was empty.');
        }

        return $json;
    }

    private function fetchLatestReleaseJsonWithCurl(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is unavailable.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('PHP cURL could not initialize the release request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: Fyuhls-Updater',
            ],
        ]);

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $json = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($json)) {
            throw new \RuntimeException('GitHub latest release cURL request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        if (trim($json) === '') {
            throw new \RuntimeException('GitHub latest release cURL response was empty.');
        }

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new \RuntimeException('GitHub latest release cURL response returned HTTP ' . $httpCode . '.');
        }

        return $json;
    }

    private function validatedReleaseVersion(array $release): string
    {
        if (!empty($release['draft'])) {
            throw new \RuntimeException('Draft GitHub releases cannot be installed.');
        }
        if (!empty($release['prerelease'])) {
            throw new \RuntimeException('Prerelease GitHub releases cannot be installed.');
        }

        $version = trim((string)($release['tag_name'] ?? ''));
        if ($version === '') {
            $version = trim((string)($release['name'] ?? ''));
        }
        if ($this->normalizeVersion($version) === null) {
            throw new \RuntimeException('The latest GitHub release version could not be parsed safely.');
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $updateConfig
     * @return array{type: string, url: string}
     */
    private function selectArchiveSource(array $release, array $updateConfig): array
    {
        $configuredAsset = trim((string)($updateConfig['release_asset_name'] ?? ''));
        if ($configuredAsset !== '') {
            foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
                if (!is_array($asset) || (string)($asset['name'] ?? '') !== $configuredAsset) {
                    continue;
                }

                $url = trim((string)($asset['browser_download_url'] ?? ''));
                $this->assertAllowedArchiveUrl($url);
                return ['type' => 'release_asset', 'url' => $url];
            }

            throw new \RuntimeException('The configured update release asset was not found.');
        }

        $url = trim((string)($release['zipball_url'] ?? ''));
        $this->assertAllowedArchiveUrl($url);
        return ['type' => 'github_source_zip', 'url' => $url];
    }

    private function assertAllowedArchiveUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        if ($scheme !== 'https' || !in_array($host, ['github.com', 'api.github.com'], true)) {
            throw new \RuntimeException('The update archive URL is not an approved HTTPS GitHub URL.');
        }
    }

    private function downloadArchive(string $url, string $destination): void
    {
        if ($this->archiveDownloader !== null) {
            ($this->archiveDownloader)($url, $destination);
            if (!is_file($destination)) {
                throw new \RuntimeException('The update archive downloader did not produce a file.');
            }
            return;
        }

        try {
            $this->downloadArchiveWithStreams($url, $destination);
            return;
        } catch (\Throwable) {
            if (is_file($destination)) {
                @unlink($destination);
            }
        }

        $this->downloadArchiveWithCurl($url, $destination);
    }

    private function downloadArchiveWithStreams(string $url, string $destination): void
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('PHP URL fopen wrappers are disabled.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'follow_location' => 1,
                'max_redirects' => 5,
                'ignore_errors' => false,
                'header' => implode("\r\n", $this->archiveRequestHeaders($url)),
            ],
        ]);
        $source = @fopen($url, 'rb', false, $context);
        if ($source === false) {
            throw new \RuntimeException('The update archive could not be downloaded.');
        }

        $target = @fopen($destination, 'xb');
        if ($target === false) {
            fclose($source);
            throw new \RuntimeException('The update archive could not be staged safely.');
        }

        try {
            $copied = stream_copy_to_stream($source, $target, self::MAX_ARCHIVE_BYTES + 1);
            if (!is_int($copied) || $copied <= 0 || $copied > self::MAX_ARCHIVE_BYTES) {
                throw new \RuntimeException('The update archive download was empty or exceeded the maximum allowed size.');
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function downloadArchiveWithCurl(string $url, string $destination): void
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is unavailable.');
        }

        $target = @fopen($destination, 'xb');
        if ($target === false) {
            throw new \RuntimeException('The update archive could not be staged safely.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($target);
            @unlink($destination);
            throw new \RuntimeException('PHP cURL could not initialize the archive download.');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $target,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $this->archiveRequestHeaders($url),
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($curl, float $downloadTotal, float $downloaded, float $uploadTotal, float $uploaded): int {
                return $downloaded > self::MAX_ARCHIVE_BYTES ? 1 : 0;
            },
        ]);

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $success = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($target);

        if ($success !== true || $errno !== 0) {
            @unlink($destination);
            throw new \RuntimeException('The update archive cURL download failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        if ($httpCode < 200 || $httpCode >= 400) {
            @unlink($destination);
            throw new \RuntimeException('The update archive cURL download returned HTTP ' . $httpCode . '.');
        }

        clearstatcache(true, $destination);
        $size = filesize($destination);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_ARCHIVE_BYTES) {
            @unlink($destination);
            throw new \RuntimeException('The update archive download was empty or exceeded the maximum allowed size.');
        }
    }

    /**
     * @return list<string>
     */
    private function archiveRequestHeaders(string $url): array
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        $accept = $host === 'api.github.com'
            ? 'application/vnd.github+json'
            : 'application/octet-stream';

        return [
            'Accept: ' . $accept,
            'User-Agent: Fyuhls-Updater',
        ];
    }

    private function sanitizeDiagnostic(string $message): string
    {
        $diagnostic = trim((string)preg_replace('/\s+/', ' ', $message));
        if ($diagnostic === '') {
            return 'No transport detail available.';
        }

        return strlen($diagnostic) > 300 ? substr($diagnostic, 0, 297) . '...' : $diagnostic;
    }

    /**
     * @param array{type: string, url: string} $archiveSource
     * @param list<string> $preservePaths
     * @return array<string, mixed>
     */
    private function inspectArchive(
        string $archivePath,
        int $archiveSize,
        string $currentVersion,
        string $latestVersion,
        array $archiveSource,
        array $preservePaths
    ): array {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new \RuntimeException('The update archive is not a valid ZIP file.');
        }

        $root = null;
        $seenPaths = [];
        $fileEntries = [];
        $totalExtractedBytes = 0;
        $fileCount = 0;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = (string)$zip->getNameIndex($index);
                $normalized = $this->normalizeArchiveEntry($entryName);
                if ($normalized === null) {
                    throw new \RuntimeException('The update archive contains an unsafe entry path.');
                }

                $parts = explode('/', rtrim($normalized, '/'));
                $entryRoot = array_shift($parts);
                if ($entryRoot === null || $entryRoot === '') {
                    throw new \RuntimeException('The update archive contains an invalid root entry.');
                }
                if ($root === null) {
                    $root = $entryRoot;
                } elseif ($root !== $entryRoot) {
                    throw new \RuntimeException('The update archive must contain exactly one top-level release directory.');
                }
                if (str_starts_with($entryRoot, '.')) {
                    throw new \RuntimeException('The update archive contains an unsafe hidden root directory.');
                }

                if ($parts === []) {
                    continue;
                }

                $relativePath = implode('/', $parts);
                $directory = str_ends_with($normalized, '/');
                if ($this->shouldSkipReleaseMetadataPath($relativePath)) {
                    continue;
                }
                $this->assertSafeHiddenPath($relativePath);
                $this->assertSafeArchiveEntryType($zip, $index, $directory);

                $collisionKey = strtolower($relativePath);
                if ($this->hasArchivePathCollision($collisionKey, $directory, $seenPaths)) {
                    throw new \RuntimeException('The update archive contains a case-insensitive path collision.');
                }
                $seenPaths[$collisionKey] = $directory;

                if ($directory) {
                    continue;
                }

                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new \RuntimeException('The update archive contains unreadable entry metadata.');
                }
                $size = (int)($stat['size'] ?? -1);
                if ($size < 0 || $size > self::MAX_SINGLE_FILE_BYTES) {
                    throw new \RuntimeException('The update archive contains an oversized file.');
                }

                $fileCount++;
                $totalExtractedBytes += $size;
                if ($fileCount > self::MAX_ARCHIVE_FILES || $totalExtractedBytes > self::MAX_EXTRACTED_BYTES) {
                    throw new \RuntimeException('The update archive exceeds safe extraction limits.');
                }

                $fileEntries[] = [
                    'archive_name' => $entryName,
                    'path' => $relativePath,
                    'size' => $size,
                ];
            }

            if ($root === null || $fileEntries === []) {
                throw new \RuntimeException('The update archive does not contain any release files.');
            }

            return $this->buildPreviewReport(
                $zip,
                $fileEntries,
                $archiveSize,
                $totalExtractedBytes,
                $currentVersion,
                $latestVersion,
                $archiveSource,
                $preservePaths
            );
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, bool> $seenPaths
     */
    private function hasArchivePathCollision(string $path, bool $directory, array $seenPaths): bool
    {
        if (isset($seenPaths[$path])) {
            return true;
        }

        foreach ($seenPaths as $seenPath => $seenIsDirectory) {
            if (!$seenIsDirectory && str_starts_with($path . '/', $seenPath . '/')) {
                return true;
            }
            if (!$directory && str_starts_with($seenPath . '/', $path . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeArchiveEntry(string $entryName): ?string
    {
        if ($entryName === '' || str_contains($entryName, "\0") || str_contains($entryName, '\\')) {
            return null;
        }
        if (rawurldecode($entryName) !== $entryName) {
            return null;
        }

        $directory = str_ends_with($entryName, '/');
        $trimmed = $directory ? rtrim($entryName, '/') : $entryName;
        if (!PathValidator::isSafeZipEntry($trimmed)) {
            return null;
        }

        return $trimmed . ($directory ? '/' : '');
    }

    private function assertSafeHiddenPath(string $relativePath): void
    {
        $allowed = ['.htaccess', '.gitignore', '.gitkeep', '.well-known'];
        $vendorAllowed = ['.php-cs-fixer.dist.php', '.psalm'];
        $segments = explode('/', $relativePath);
        $underVendor = ($segments[0] ?? '') === 'vendor';
        foreach ($segments as $segment) {
            if (!str_starts_with($segment, '.')) {
                continue;
            }
            if (!in_array($segment, $allowed, true) && !($underVendor && in_array($segment, $vendorAllowed, true))) {
                throw new \RuntimeException('The update archive contains an unsafe hidden entry.');
            }
        }
    }

    private function shouldSkipReleaseMetadataPath(string $relativePath): bool
    {
        return in_array('.github', explode('/', trim($relativePath, '/')), true);
    }

    private function assertSafeArchiveEntryType(ZipArchive $zip, int $index, bool $directory): void
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return;
        }

        $mode = ($attributes >> 16) & 0xFFFF;
        if ($mode === 0) {
            return;
        }

        $type = $mode & 0170000;
        $expectedType = $directory ? 0040000 : 0100000;
        if ($type !== 0 && $type !== $expectedType) {
            throw new \RuntimeException('The update archive contains a symlink or unsupported special file.');
        }
        if (($mode & 07000) !== 0) {
            throw new \RuntimeException('The update archive contains unsafe special permissions.');
        }
    }

    /**
     * @param list<array{archive_name: string, path: string, size: int}> $fileEntries
     * @param array{type: string, url: string} $archiveSource
     * @param list<string> $preservePaths
     * @return array<string, mixed>
     */
    private function buildPreviewReport(
        ZipArchive $zip,
        array $fileEntries,
        int $archiveSize,
        int $totalExtractedBytes,
        string $currentVersion,
        string $latestVersion,
        array $archiveSource,
        array $preservePaths
    ): array {
        $filesToCopy = [];
        $preservedPaths = [];
        $localModifiedFiles = [];
        $requiredWritablePaths = [];
        $overwriteBytes = 0;

        foreach ($fileEntries as $entry) {
            $path = $entry['path'];
            if ($this->isPreservedPath($path, $preservePaths)) {
                $preservedPaths[] = $path;
                continue;
            }

            $targetPath = $this->safeProjectPath($path);
            if (is_link($targetPath)) {
                throw new \RuntimeException('An update target is a symbolic link and cannot be previewed safely.');
            }

            if (!file_exists($targetPath)) {
                $filesToCopy[] = $path;
                $requiredWritablePaths[] = $this->nearestExistingParent($targetPath);
                continue;
            }
            if (!is_file($targetPath)) {
                throw new \RuntimeException('An update target is not a regular file.');
            }

            $stream = $zip->getStream($entry['archive_name']);
            if ($stream === false) {
                throw new \RuntimeException('The update archive contains a file that could not be read.');
            }
            $releaseHash = hash_init('sha256');
            hash_update_stream($releaseHash, $stream);
            fclose($stream);
            $localHash = hash_file('sha256', $targetPath);
            if (!is_string($localHash)) {
                throw new \RuntimeException('An existing update target could not be read safely.');
            }
            if (!hash_equals($localHash, hash_final($releaseHash))) {
                $filesToCopy[] = $path;
                $requiredWritablePaths[] = $this->nearestExistingParent($targetPath);
                $overwriteBytes += max(0, (int)filesize($targetPath));
                $localModifiedFiles[] = $path;
            }
        }

        sort($filesToCopy);
        sort($preservedPaths);
        sort($localModifiedFiles);
        $requiredWritablePaths = array_values(array_unique($requiredWritablePaths));
        sort($requiredWritablePaths);

        return [
            'from_version' => $currentVersion,
            'to_version' => $latestVersion,
            'archive_source_type' => $archiveSource['type'],
            'archive_url' => $archiveSource['url'],
            'archive_size_bytes' => $archiveSize,
            'extracted_size_bytes' => $totalExtractedBytes,
            'estimated_space_bytes' => $archiveSize + ($totalExtractedBytes * 2) + $overwriteBytes + 1048576,
            'files_to_copy' => $filesToCopy,
            'preserved_paths_skipped' => $preservedPaths,
            'local_modified_files' => $localModifiedFiles,
            'stale_files' => [],
            'stale_detection' => 'unavailable_without_trusted_manifest',
            'required_writable_paths' => $requiredWritablePaths,
            'apply_available' => false,
            'validated_entries' => $fileEntries,
        ];
    }

    /**
     * @param array<string, mixed> $updateConfig
     * @return list<string>
     */
    private function configuredPreservePaths(array $updateConfig): array
    {
        if (isset($updateConfig['preserve_paths']) && !is_array($updateConfig['preserve_paths'])) {
            throw new \RuntimeException('The configured updater preserve_paths value must be an array.');
        }
        $configured = is_array($updateConfig['preserve_paths'] ?? null) ? $updateConfig['preserve_paths'] : [];
        $paths = array_merge([
            'config/app.php',
            'config/database.php',
            'config/version.php',
            'storage',
            'themes/custom',
            'src/Plugin',
            '.env',
        ], $configured);

        $safe = [];
        foreach ($paths as $path) {
            $normalized = trim(str_replace('\\', '/', (string)$path), '/');
            if ($normalized === '' || !PathValidator::isSafeZipEntry($normalized)) {
                throw new \RuntimeException('The updater contains an invalid preserved path configuration.');
            }
            $safe[] = $normalized;
        }

        return array_values(array_unique($safe));
    }

    /**
     * @param list<string> $preservePaths
     */
    private function isPreservedPath(string $path, array $preservePaths): bool
    {
        foreach ($preservePaths as $preserved) {
            if ($path === $preserved || str_starts_with($path, $preserved . '/')) {
                return true;
            }
        }

        return false;
    }

    private function safeProjectPath(string $relativePath): string
    {
        $path = PathValidator::buildSafeChildPath($this->projectRoot, $relativePath);
        if ($path === null) {
            throw new \RuntimeException('The update archive contains a path outside the project root.');
        }

        return $path;
    }

    private function nearestExistingParent(string $path): string
    {
        $candidate = dirname($path);
        while (!is_dir($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                throw new \RuntimeException('A writable parent directory could not be resolved safely.');
            }
            $candidate = $parent;
        }

        if (!PathValidator::isPathWithinBase($this->projectRoot, $candidate)) {
            throw new \RuntimeException('An update target resolved outside the project root.');
        }

        $realRoot = realpath($this->projectRoot);
        $realCandidate = realpath($candidate);
        if (!is_string($realRoot) || !is_string($realCandidate)
            || !PathValidator::isPathWithinBase($realRoot, $realCandidate)
        ) {
            throw new \RuntimeException('An update target resolves outside the project root through a symbolic link.');
        }

        return $candidate;
    }

    /**
     * @return resource
     */
    private function acquireUpdateLock()
    {
        $lockDirectory = $this->projectRoot . '/storage/cache';
        $this->ensureDirectory($lockDirectory);
        $lockHandle = @fopen($lockDirectory . '/update.lock', 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException('The updater lock could not be opened.');
        }
        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            throw new \RuntimeException('Another update operation is already running.');
        }

        return $lockHandle;
    }

    /**
     * @param resource $lockHandle
     */
    private function releaseUpdateLock($lockHandle): void
    {
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    private function isDemoModeEnabled(): bool
    {
        if (isset($this->environmentChecks['demo_mode'])) {
            return (bool)($this->environmentChecks['demo_mode'])();
        }

        return DemoModeService::isEnabled();
    }

    private function isMaintenanceModeEnabled(): bool
    {
        if (isset($this->environmentChecks['maintenance_mode'])) {
            return (bool)($this->environmentChecks['maintenance_mode'])();
        }

        return Setting::get('maintenance_mode', '0') === '1';
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function assertWritableTargets(array $preview): void
    {
        $writableCheck = $this->environmentChecks['writable'] ?? static fn (string $path): bool => is_writable($path);
        foreach (is_array($preview['required_writable_paths'] ?? null) ? $preview['required_writable_paths'] : [] as $path) {
            if (!is_string($path) || !PathValidator::isPathWithinBase($this->projectRoot, $path) || !$writableCheck($path)) {
                throw new \RuntimeException('One or more update target directories are not writable.');
            }
        }
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function assertSufficientDiskSpace(array $preview): void
    {
        $required = max(1, (int)($preview['estimated_space_bytes'] ?? 0));
        $freeSpaceCheck = $this->environmentChecks['disk_free_space']
            ?? static fn (string $path): int|false => disk_free_space($path);
        $available = $freeSpaceCheck($this->projectRoot);
        if (!is_int($available) && !is_float($available)) {
            throw new \RuntimeException('Available disk space could not be determined safely.');
        }
        if ($available < $required) {
            throw new \RuntimeException('There is not enough free disk space to apply this update safely.');
        }
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function assertComposerCompatibility(array $preview): void
    {
        $filesToCopy = is_array($preview['files_to_copy'] ?? null) ? $preview['files_to_copy'] : [];
        if (!in_array('composer.lock', $filesToCopy, true)) {
            return;
        }

        $localModifiedFiles = is_array($preview['local_modified_files'] ?? null) ? $preview['local_modified_files'] : [];
        $composerLockChanges = !is_file($this->projectRoot . '/composer.lock')
            || in_array('composer.lock', $localModifiedFiles, true);
        if (!$composerLockChanges) {
            return;
        }

        $hasVendorAutoloader = in_array('vendor/autoload.php', $filesToCopy, true);
        $hasVendorInventory = in_array('vendor/composer/installed.php', $filesToCopy, true)
            || in_array('vendor/composer/installed.json', $filesToCopy, true);
        if ($hasVendorAutoloader && $hasVendorInventory) {
            return;
        }

        throw new \RuntimeException('This release changes composer.lock without including a complete updated vendor directory.');
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function applyValidatedPackage(string $archivePath, array $preview): array
    {
        $entries = is_array($preview['validated_entries'] ?? null) ? $preview['validated_entries'] : [];
        if ($entries === []) {
            throw new \RuntimeException('The validated update package contains no files to apply.');
        }

        $filesToCopy = is_array($preview['files_to_copy'] ?? null) ? $preview['files_to_copy'] : [];
        $copySet = array_fill_keys(array_map('strval', $filesToCopy), true);
        if ($copySet === []) {
            throw new \RuntimeException('The update package contains no applicable files after preserve rules.');
        }

        $runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $backupDir = $this->projectRoot . '/storage/update_backups/' . $runId;
        $reportDir = $this->projectRoot . '/storage/update_reports';
        $reportPath = $reportDir . '/' . $runId . '.json';
        $this->ensureDirectory($backupDir);
        $this->ensureDirectory($reportDir);

        $report = [
            'run_id' => $runId,
            'status' => 'copying',
            'completed' => false,
            'created_at' => date('c'),
            'from_version' => $preview['from_version'] ?? null,
            'to_version' => $preview['to_version'] ?? null,
            'backup_path' => $this->projectRelativePath($backupDir),
            'quarantine_path' => null,
            'files_to_copy' => array_values(array_keys($copySet)),
            'preserved_paths_skipped' => $preview['preserved_paths_skipped'] ?? [],
            'stale_detection' => $preview['stale_detection'] ?? 'unavailable_without_trusted_manifest',
            'backed_up_files' => [],
            'new_files' => [],
            'copied_files' => [],
            'directories_created' => [],
            'rollback' => null,
            'errors' => [],
        ];
        $this->writeUpdateReport($reportPath, $report);

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new \RuntimeException('The validated update archive could not be reopened for copying.');
        }

        try {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $relativePath = (string)($entry['path'] ?? '');
                if ($relativePath === '' || !isset($copySet[$relativePath])) {
                    continue;
                }

                $targetPath = $this->safeProjectPath($relativePath);
                if (is_dir($targetPath)) {
                    throw new \RuntimeException('An update target is a directory and cannot be overwritten as a file.');
                }
                if (is_link($targetPath)) {
                    throw new \RuntimeException('An update target is a symbolic link and cannot be overwritten safely.');
                }

                $wasExistingFile = is_file($targetPath);
                $createdDirs = $this->ensureDirectoryForUpdate(dirname($targetPath));
                foreach ($createdDirs as $createdDir) {
                    $report['directories_created'][] = $createdDir;
                }

                $backup = $this->backupExistingFile($targetPath, $relativePath, $backupDir);
                if ($backup !== null) {
                    $report['backed_up_files'][] = $backup;
                }

                $this->copyZipEntryAtomically($zip, (string)$entry['archive_name'], $targetPath, (int)$entry['size']);
                $report['copied_files'][] = $relativePath;
                if (!$wasExistingFile) {
                    $report['new_files'][] = $relativePath;
                }
                $report['updated_at'] = date('c');
                $this->writeUpdateReport($reportPath, $report);
                $this->invokeUpdateHook('after_copy', [
                    'path' => $relativePath,
                    'report_path' => $this->projectRelativePath($reportPath),
                ]);
            }

            $this->finalizeAppliedUpdate($preview, $backupDir, $reportPath, $report);
        } catch (\Throwable $e) {
            $report['status'] = 'failed';
            $report['completed'] = false;
            $report['failed_at'] = date('c');
            $report['error_category'] = 'copy_failed';
            $report['errors'][] = [
                'category' => 'copy_failed',
                'message' => $e->getMessage(),
            ];
            $report['rollback'] = $this->rollbackAppliedFiles($report, $backupDir);
            $report['status'] = !empty($report['rollback']['complete']) ? 'rolled_back' : 'rollback_failed';
            $report['completed'] = !empty($report['rollback']['complete']);
            $this->writeUpdateReport($reportPath, $report);

            if (!empty($report['rollback']['complete'])) {
                throw new \RuntimeException('Update failed before completion. Files copied during this attempt were restored from backup.');
            }

            throw new \RuntimeException('Update failed and rollback could not be proven. Keep maintenance mode enabled and review the update report before retrying.');
        } finally {
            $zip->close();
        }

        $report['status'] = 'completed';
        $report['completed'] = true;
        $report['completed_at'] = date('c');
        $report['directories_created'] = array_values(array_unique($report['directories_created']));
        $this->writeUpdateReport($reportPath, $report);

        return [
            'from_version' => (string)($preview['from_version'] ?? ''),
            'to_version' => (string)($preview['to_version'] ?? ''),
            'files_copied' => count($report['copied_files']),
            'directories_created' => count($report['directories_created']),
            'backup_path' => $report['backup_path'],
            'quarantine_path' => null,
            'report_path' => $this->projectRelativePath($reportPath),
            'copied_files' => $report['copied_files'],
            'skipped_files' => $preview['preserved_paths_skipped'] ?? [],
            'stale_detection' => $report['stale_detection'],
            'opcache' => $report['opcache'] ?? [],
            'warnings' => $report['warnings'] ?? [],
            'status' => $report['status'],
        ];
    }

    /**
     * @return list<string>
     */
    private function ensureDirectoryForUpdate(string $directory): array
    {
        if (is_link($directory)) {
            throw new \RuntimeException('An update target directory is a symbolic link.');
        }
        if (is_dir($directory)) {
            $this->assertExistingDirectoryInsideProject($directory);
            return [];
        }

        $missing = [];
        $candidate = $directory;
        while (!is_dir($candidate)) {
            $missing[] = $candidate;
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                throw new \RuntimeException('An update target directory could not be resolved safely.');
            }
            $candidate = $parent;
        }
        $this->assertExistingDirectoryInsideProject($candidate);

        $created = [];
        foreach (array_reverse($missing) as $path) {
            $safePath = PathValidator::buildSafeChildPath($this->projectRoot, $this->projectRelativePath($path));
            if ($safePath === null || $safePath !== $path) {
                throw new \RuntimeException('An update target directory resolved outside the project root.');
            }
            if (!mkdir($path, 0755) && !is_dir($path)) {
                throw new \RuntimeException('An update target directory could not be created.');
            }
            $created[] = $this->projectRelativePath($path);
        }

        return $created;
    }

    private function assertExistingDirectoryInsideProject(string $directory): void
    {
        $realRoot = realpath($this->projectRoot);
        $realDirectory = realpath($directory);
        if (!is_string($realRoot) || !is_string($realDirectory)
            || !PathValidator::isPathWithinBase($realRoot, $realDirectory)
        ) {
            throw new \RuntimeException('An update target directory resolves outside the project root.');
        }
    }

    /**
     * @return null|array{path: string, backup_path: string}
     */
    private function backupExistingFile(string $targetPath, string $relativePath, string $backupDir): ?array
    {
        if (!file_exists($targetPath)) {
            return null;
        }
        if (!is_file($targetPath) || is_link($targetPath)) {
            throw new \RuntimeException('An existing update target is not a regular file.');
        }

        $backupPath = PathValidator::buildSafeChildPath($backupDir, $relativePath);
        if ($backupPath === null) {
            throw new \RuntimeException('A backup target path could not be resolved safely.');
        }
        $this->ensureDirectoryForUpdate(dirname($backupPath));
        if (!copy($targetPath, $backupPath)) {
            throw new \RuntimeException('An existing file could not be backed up before update.');
        }

        return [
            'path' => $relativePath,
            'backup_path' => $this->projectRelativePath($backupPath),
        ];
    }

    private function copyZipEntryAtomically(ZipArchive $zip, string $archiveName, string $targetPath, int $expectedBytes): void
    {
        $stream = $zip->getStream($archiveName);
        if ($stream === false) {
            throw new \RuntimeException('A validated update file could not be read from the archive.');
        }

        $tempPath = $targetPath . '.update-' . bin2hex(random_bytes(6)) . '.tmp';
        $out = @fopen($tempPath, 'xb');
        if ($out === false) {
            fclose($stream);
            throw new \RuntimeException('A temporary update file could not be created atomically.');
        }

        try {
            $copied = stream_copy_to_stream($stream, $out);
            if (!is_int($copied) || $copied !== $expectedBytes) {
                throw new \RuntimeException('A validated update file copied with an unexpected size.');
            }
        } finally {
            fclose($stream);
            fclose($out);
        }

        @chmod($tempPath, 0644);
        if (is_link($targetPath) || (file_exists($targetPath) && !is_file($targetPath))) {
            @unlink($tempPath);
            throw new \RuntimeException('An update target changed before the atomic rename.');
        }
        if (!@rename($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new \RuntimeException('A temporary update file could not be atomically published.');
        }
    }

    private function assertNoIncompleteUpdateRun(): void
    {
        foreach ($this->incompleteUpdateReportProblems() as $problem) {
            throw new \RuntimeException($problem);
        }
    }

    /**
     * @return list<string>
     */
    private function applyAvailabilityBlockers(): array
    {
        $blockers = [];
        if ($this->isDemoModeEnabled()) {
            $blockers[] = 'One-click updates are disabled while demo mode is enabled.';
        }
        if (!$this->isMaintenanceModeEnabled()) {
            $blockers[] = 'Enable maintenance mode before one-click apply is available.';
        }

        return array_merge($blockers, $this->incompleteUpdateReportProblems());
    }

    /**
     * @return list<string>
     */
    private function incompleteUpdateReportProblems(): array
    {
        $reportDir = $this->projectRoot . '/storage/update_reports';
        if (!is_dir($reportDir)) {
            return [];
        }

        $problems = [];
        foreach (glob($reportDir . '/*.json') ?: [] as $reportPath) {
            if (!is_file($reportPath)) {
                continue;
            }
            $decoded = json_decode((string)file_get_contents($reportPath), true);
            if (!is_array($decoded)) {
                $problems[] = 'A previous update report could not be read safely. Review it manually before retrying.';
                continue;
            }
            if (($decoded['completed'] ?? false) !== true) {
                $problems[] = 'A previous update run did not complete. Review or recover the update report before retrying.';
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $report
     */
    private function finalizeAppliedUpdate(
        array $preview,
        string $backupDir,
        string $reportPath,
        array &$report
    ): void {
        $targetVersion = (string)($preview['to_version'] ?? '');
        $versionFile = $this->projectRoot . '/config/version.php';
        $versionBackup = $this->backupExistingFile($versionFile, 'config/version.php', $backupDir);
        if ($versionBackup === null) {
            throw new \RuntimeException('The installed version file is missing and cannot be finalized safely.');
        }
        $report['backed_up_files'][] = $versionBackup;
        $this->writeInstalledVersionAtomically($targetVersion);
        $report['version_file_updated'] = true;
        $report['updated_at'] = date('c');
        $this->writeUpdateReport($reportPath, $report);
        $this->invokeUpdateHook('after_version_update', [
            'version' => $targetVersion,
            'report_path' => $this->projectRelativePath($reportPath),
        ]);

        $report['opcache'] = $this->invalidateChangedPhpFiles(array_merge(
            is_array($report['copied_files'] ?? null) ? $report['copied_files'] : [],
            ['config/version.php']
        ));
        $report['warnings'] = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
        foreach (is_array($report['opcache']['warnings'] ?? null) ? $report['opcache']['warnings'] : [] as $warning) {
            $report['warnings'][] = $warning;
        }
        foreach (is_array($report['copied_files'] ?? null) ? $report['copied_files'] : [] as $copiedFile) {
            $lower = strtolower((string)$copiedFile);
            if (str_starts_with($lower, 'public/') && (str_ends_with($lower, '.js') || str_ends_with($lower, '.css'))) {
                $report['warnings'][] = 'Public JavaScript or CSS changed; verify browser/CDN cache-busting before ending the maintenance window.';
                break;
            }
        }
        $report['cache_actions'] = ['clearstatcache'];
        clearstatcache();

        try {
            $this->recordUpdateStaffActivity($report);
            $report['staff_activity_recorded'] = true;
        } catch (\Throwable $e) {
            $report['staff_activity_recorded'] = false;
            $report['warnings'][] = 'The update completed, but the staff activity record could not be written.';
        }

        $report['updated_at'] = date('c');
        $this->writeUpdateReport($reportPath, $report);
    }

    private function writeInstalledVersionAtomically(string $version): void
    {
        $normalized = $this->normalizeVersion($version);
        if ($normalized === null) {
            throw new \RuntimeException('The applied release version could not be written safely.');
        }

        $versionFile = $this->projectRoot . '/config/version.php';
        $config = $this->readVersionConfig();
        if ($config === []) {
            throw new \RuntimeException('The installed version configuration could not be loaded safely.');
        }
        $config['version'] = $normalized;
        $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        $tempPath = $versionFile . '.update-' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('The installed version file could not be staged safely.');
        }
        @chmod($tempPath, 0644);
        if (is_link($versionFile) || !is_file($versionFile) || !@rename($tempPath, $versionFile)) {
            @unlink($tempPath);
            throw new \RuntimeException('The installed version file could not be published atomically.');
        }
    }

    /**
     * @param list<string> $changedFiles
     * @return array<string, mixed>
     */
    private function invalidateChangedPhpFiles(array $changedFiles): array
    {
        $result = [
            'available' => function_exists('opcache_invalidate') || isset($this->environmentChecks['opcache_invalidate']),
            'attempted_files' => [],
            'invalidated_files' => [],
            'failed_files' => [],
            'warnings' => [],
        ];

        $invalidate = $this->environmentChecks['opcache_invalidate']
            ?? static fn (string $path): bool => opcache_invalidate($path, true);
        foreach (array_values(array_unique($changedFiles)) as $relativePath) {
            if (!str_ends_with(strtolower($relativePath), '.php')) {
                continue;
            }
            $result['attempted_files'][] = $relativePath;
            if (!$result['available']) {
                continue;
            }
            try {
                if ($invalidate($this->safeProjectPath($relativePath))) {
                    $result['invalidated_files'][] = $relativePath;
                } else {
                    $result['failed_files'][] = $relativePath;
                }
            } catch (\Throwable $e) {
                $result['failed_files'][] = $relativePath;
            }
        }

        if (!$result['available']) {
            $result['warnings'][] = 'OPcache invalidation is unavailable; restart PHP-FPM or clear OPcache before relying on the updated PHP files.';
        } elseif ($result['failed_files'] !== []) {
            $result['warnings'][] = 'Some updated PHP files could not be invalidated from OPcache; a PHP-FPM restart may be required.';
        }

        $validateTimestamps = ini_get('opcache.validate_timestamps');
        if ($validateTimestamps !== false && trim((string)$validateTimestamps) === '0') {
            $result['warnings'][] = 'OPcache timestamp validation is disabled; restart PHP-FPM before ending the maintenance window.';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function recordUpdateStaffActivity(array $report): void
    {
        $metadata = [
            'from_version' => $report['from_version'] ?? null,
            'to_version' => $report['to_version'] ?? null,
            'result' => 'completed',
            'report_path' => $this->projectRelativePath(
                $this->projectRoot . '/storage/update_reports/' . (string)$report['run_id'] . '.json'
            ),
            'backup_path' => $report['backup_path'] ?? null,
            'files_copied' => count(is_array($report['copied_files'] ?? null) ? $report['copied_files'] : []),
        ];
        if (isset($this->environmentChecks['staff_activity'])) {
            ($this->environmentChecks['staff_activity'])($metadata);
            return;
        }

        StaffActivityService::log(
            'system_updated',
            'system_update',
            null,
            sprintf(
                'Updated the application from %s to %s.',
                (string)($report['from_version'] ?? 'unknown'),
                (string)($report['to_version'] ?? 'unknown')
            ),
            $metadata
        );
    }

    /**
     * @return null|array<string, mixed>
     */
    private function latestCompletedUpdateReportSummary(): ?array
    {
        $reportDir = $this->projectRoot . '/storage/update_reports';
        $reports = is_dir($reportDir) ? (glob($reportDir . '/*.json') ?: []) : [];
        rsort($reports);
        foreach ($reports as $reportPath) {
            $report = json_decode((string)@file_get_contents($reportPath), true);
            if (!is_array($report) || ($report['completed'] ?? false) !== true) {
                continue;
            }

            return [
                'status' => (string)($report['status'] ?? 'unknown'),
                'from_version' => $report['from_version'] ?? null,
                'to_version' => $report['to_version'] ?? null,
                'completed_at' => $report['completed_at'] ?? null,
                'backup_path' => $report['backup_path'] ?? null,
                'quarantine_path' => $report['quarantine_path'] ?? null,
                'report_path' => $this->projectRelativePath($reportPath),
                'copied_files_count' => count(is_array($report['copied_files'] ?? null) ? $report['copied_files'] : []),
                'skipped_files_count' => count(is_array($report['preserved_paths_skipped'] ?? null) ? $report['preserved_paths_skipped'] : []),
                'stale_detection' => $report['stale_detection'] ?? null,
                'warnings' => is_array($report['warnings'] ?? null) ? $report['warnings'] : [],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function rollbackAppliedFiles(array $report, string $backupDir): array
    {
        $backupMap = [];
        foreach (is_array($report['backed_up_files'] ?? null) ? $report['backed_up_files'] : [] as $backup) {
            if (!is_array($backup) || !is_string($backup['path'] ?? null) || !is_string($backup['backup_path'] ?? null)) {
                continue;
            }
            $backupMap[$backup['path']] = $backup['backup_path'];
        }

        $newFiles = array_fill_keys(
            array_map('strval', is_array($report['new_files'] ?? null) ? $report['new_files'] : []),
            true
        );
        $restored = [];
        $removedNew = [];
        $failed = [];
        $copiedFiles = array_map(
            'strval',
            is_array($report['copied_files'] ?? null) ? $report['copied_files'] : []
        );
        if (!empty($report['version_file_updated'])) {
            $copiedFiles[] = 'config/version.php';
        }
        $copiedFiles = array_reverse(array_values(array_unique($copiedFiles)));

        foreach ($copiedFiles as $relativePath) {
            try {
                $targetPath = $this->safeProjectPath($relativePath);
                if (isset($backupMap[$relativePath])) {
                    $this->invokeUpdateHook('before_restore', [
                        'path' => $relativePath,
                        'backup_path' => $backupMap[$relativePath],
                    ]);
                    $backupPath = $this->safeProjectPath($backupMap[$relativePath]);
                    if (!PathValidator::isPathWithinBase($backupDir, $backupPath) || !is_file($backupPath)) {
                        throw new \RuntimeException('Backup file missing for rollback.');
                    }
                    $this->restoreBackupAtomically($backupPath, $targetPath);
                    $restored[] = $relativePath;
                    continue;
                }

                if (isset($newFiles[$relativePath]) && file_exists($targetPath)) {
                    if (!is_file($targetPath) || is_link($targetPath) || !@unlink($targetPath)) {
                        throw new \RuntimeException('New update file could not be removed during rollback.');
                    }
                    $removedNew[] = $relativePath;
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'path' => $relativePath,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $removedDirs = [];
        foreach (array_reverse(array_map(
            'strval',
            is_array($report['directories_created'] ?? null) ? array_unique($report['directories_created']) : []
        )) as $relativeDir) {
            try {
                $dir = $this->safeProjectPath($relativeDir);
                if (is_dir($dir) && @rmdir($dir)) {
                    $removedDirs[] = $relativeDir;
                }
            } catch (\Throwable $e) {
                $failed[] = [
                    'path' => $relativeDir,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'complete' => $failed === [],
            'restored_files' => $restored,
            'removed_new_files' => $removedNew,
            'removed_directories' => $removedDirs,
            'failed' => $failed,
            'completed_at' => date('c'),
        ];
    }

    private function restoreBackupAtomically(string $backupPath, string $targetPath): void
    {
        $this->ensureDirectoryForUpdate(dirname($targetPath));
        $tempPath = $targetPath . '.rollback-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!copy($backupPath, $tempPath)) {
            throw new \RuntimeException('Backup file could not be staged for rollback.');
        }
        @chmod($tempPath, 0644);
        if (is_link($targetPath) || (file_exists($targetPath) && !is_file($targetPath))) {
            @unlink($tempPath);
            throw new \RuntimeException('Rollback target changed before restore.');
        }
        if (!@rename($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new \RuntimeException('Backup file could not be restored atomically.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function invokeUpdateHook(string $hook, array $context): void
    {
        if (!isset($this->environmentChecks[$hook])) {
            return;
        }

        ($this->environmentChecks[$hook])($context);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeUpdateReport(string $reportPath, array $report): void
    {
        $this->ensureDirectory(dirname($reportPath));
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('The update report could not be encoded.');
        }

        $tempPath = $reportPath . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tempPath, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('The update report could not be written.');
        }
        if (!@rename($tempPath, $reportPath)) {
            @unlink($tempPath);
            throw new \RuntimeException('The update report could not be published atomically.');
        }
    }

    private function projectRelativePath(string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->projectRoot), '/');
        $normalizedPath = str_replace('\\', '/', $path);
        if ($normalizedPath === $normalizedRoot) {
            return '.';
        }
        if (!str_starts_with($normalizedPath . '/', $normalizedRoot . '/')) {
            throw new \RuntimeException('A path could not be represented relative to the project root.');
        }

        return ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('The update staging directory could not be created.');
        }
    }

    private function normalizeGitHubReleaseUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host !== 'github.com') {
            return null;
        }

        return $url;
    }
}
