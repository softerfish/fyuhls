<?php

namespace App\Controller\Admin;

use App\Model\Package;
use App\Model\PackageBillingOption;
use App\Model\Setting;
use App\Model\User;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;
use App\Core\Database;
use App\Core\Logger;
use App\Service\EncryptionService;
use App\Service\DemoModeService;
use App\Service\DiagnosticsService;
use App\Service\Database\SchemaService;
use App\Service\MailService;
use App\Service\PackageTargetLockService;
use App\Service\ReviewIntegrityService;
use App\Service\SafeNetworkTargetService;
use App\Service\StaffActivityService;
use App\Service\StaffPermissionService;
use App\Service\UpdateService;
use App\Service\CouponService;
use App\Service\PaymentService;

/**
 * AdminController - General Admin Operations
 *
 * Logic for non-configuration administrative tasks like user management,
 * reports, and server monitoring history.
 */
class AdminController
{
    private static $legacyRequestReplySenderForTests = null;
    private static $afterRequestActivityPersistHandler = null;
    private static bool $skipRequestInboxSchemaForTests = false;
    private static bool $requestInboxSchemaReady = false;
    private const REQUEST_QUEUE_PAGE_SIZE = 25;
    private const REQUEST_QUEUE_SOURCE_WINDOW = 250;
    private const REQUEST_QUEUE_SEARCH_SOURCE_WINDOW = 1000;
    private const REQUEST_QUEUE_MAX_PAGE = 1000;
    private const REQUEST_QUEUE_MAX_SOURCE_WINDOW = 25000;

    public static function setLegacyRequestReplySenderForTests(?callable $handler): void
    {
        self::$legacyRequestReplySenderForTests = $handler;
    }

    public static function setAfterRequestActivityPersistHandlerForTests(?callable $handler): void
    {
        self::$afterRequestActivityPersistHandler = $handler;
    }

    public static function setSkipRequestInboxSchemaForTests(bool $skip): void
    {
        self::$skipRequestInboxSchemaForTests = $skip;
        self::$requestInboxSchemaReady = false;
    }

    private static function fireAfterRequestActivityPersistForTests(array $context = []): void
    {
        if (!is_callable(self::$afterRequestActivityPersistHandler)) {
            return;
        }

        (self::$afterRequestActivityPersistHandler)($context);
    }

    private function canAccessDashboardFinancials(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'withdrawals.manage', 'subscriptions.manage', 'rewards_fraud.manage']);
    }

    private function canAccessDashboardIdentityInsights(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'investigations.view', 'users.manage', 'files.moderate', 'abuse.manage', 'dmca.manage', 'requests.manage']);
    }

    private function canAccessDashboardReadiness(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage']);
    }

    private function canAccessDashboardSupportDiagnostics(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'status.view', 'docs.view']);
    }

    private function canAccessDashboardModerationQueue(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'files.moderate', 'abuse.manage', 'dmca.manage', 'requests.manage']);
    }

    private function canAccessDashboardSecurityWatch(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'investigations.view', 'status.view']);
    }

    private function canAccessDashboardAutomation(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'status.view']);
    }

    private function canAccessDashboardDeliveryInsights(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'file_servers.manage', 'status.view']);
    }

    private function canAccessDashboardInfrastructureHealth(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'file_servers.manage', 'status.view']);
    }

    private function canAccessDashboardFileLifecycleInsights(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'file_servers.manage', 'files.moderate', 'status.view']);
    }

    private function canAccessOperationalDiagnostics(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage']);
    }

    private function enforceOperationalDiagnosticsAccess(string $message): void
    {
        if (!$this->canAccessOperationalDiagnostics()) {
            Auth::denyAccess($message);
        }
    }

    private function canAccessLiveDownloadIdentities(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'investigations.view', 'abuse.manage']);
    }

    private function canAccessLiveDownloadFileDetails(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'investigations.view', 'files.moderate']);
    }

    private function canAccessServerMonitoringHistory(): bool
    {
        return Auth::hasAnyCapability(['configuration.manage', 'support.manage', 'file_servers.manage']);
    }

    private function ensureDemoAdminReadOnly(bool $json = false, string $redirect = '/admin'): void
    {
        if (!DemoModeService::currentViewerIsDemoAdmin()) {
            return;
        }

        $message = 'This demo admin account is read-only while demo mode is enabled.';

        if ($json) {
            $this->jsonResponse(['success' => false, 'message' => $message], 403);
        }

        $_SESSION['error'] = $message;
        header('Location: ' . $redirect);
        exit;
    }

    private function formatReadableBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : 2;
        return number_format($value, $precision) . ' ' . $units[$unitIndex];
    }

    private function projectRoot(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
    }

    private function databaseUsesSqlite(\PDO $db): bool
    {
        return strtolower((string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME)) === 'sqlite';
    }

    private function appendForUpdateClause(\PDO $db, string $sql): string
    {
        if ($this->databaseUsesSqlite($db)) {
            return $sql;
        }

        return $sql . ' FOR UPDATE';
    }

    private function lockedFileServerRows(\PDO $db): array
    {
        $stmt = $db->query($this->appendForUpdateClause($db, "SELECT id, status, is_default FROM file_servers"));
        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    private function lockedFileServerRow(\PDO $db, int $serverId, string $columns = '*'): ?array
    {
        $stmt = $db->prepare($this->appendForUpdateClause($db, "SELECT {$columns} FROM file_servers WHERE id = ? LIMIT 1"));
        $stmt->execute([$serverId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function normalizeDefaultUploadTargetConfiguration(\PDO $db, ?int $preferredServerId = null): ?int
    {
        $rows = $this->lockedFileServerRows($db);
        $knownIds = [];
        $activeIds = [];
        $activeDefaultIds = [];

        foreach ($rows as $row) {
            $serverId = (int)($row['id'] ?? 0);
            if ($serverId <= 0) {
                continue;
            }

            $knownIds[$serverId] = $serverId;
            if (strtolower(trim((string)($row['status'] ?? ''))) !== 'active') {
                continue;
            }

            $activeIds[$serverId] = $serverId;
            if ((int)($row['is_default'] ?? 0) === 1) {
                $activeDefaultIds[$serverId] = $serverId;
            }
        }

        $knownIds = array_values($knownIds);
        $activeIds = array_values($activeIds);
        $activeDefaultIds = array_values($activeDefaultIds);

        if ($preferredServerId !== null) {
            if (!in_array($preferredServerId, $knownIds, true)) {
                throw new \RuntimeException('Storage server not found.');
            }
            if (!in_array($preferredServerId, $activeIds, true)) {
                throw new \RuntimeException('Only active storage servers can become the default upload target.');
            }

            $defaultServerId = $preferredServerId;
        } elseif (count($activeIds) === 1) {
            $defaultServerId = (int)$activeIds[0];
        } elseif (count($activeIds) > 1 && count($activeDefaultIds) === 1) {
            $defaultServerId = (int)$activeDefaultIds[0];
        } elseif (count($activeIds) <= 0) {
            $db->exec("UPDATE file_servers SET is_default = 0");
            return null;
        } else {
            throw new \RuntimeException('Choose exactly one active default upload target before multiple storage servers can accept new uploads.');
        }

        $db->prepare("UPDATE file_servers SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END")
            ->execute([$defaultServerId]);

        return $defaultServerId;
    }

    private function assertNoActiveUploadWorkflowsForStorageServer(\PDO $db, int $serverId): void
    {
        if ($serverId <= 0) {
            return;
        }

        $sessionStmt = $db->prepare($this->appendForUpdateClause($db, "
            SELECT COUNT(*)
            FROM upload_sessions
            WHERE storage_server_id = ?
              AND status IN ('pending', 'uploading', 'processing', 'completing')
        "));
        $sessionStmt->execute([$serverId]);
        if ((int)$sessionStmt->fetchColumn() > 0) {
            throw new \RuntimeException('Cannot delete this storage server while multipart uploads are still in progress on it. Abort or finish those uploads first.');
        }

        $reservationStmt = $db->prepare($this->appendForUpdateClause($db, "
            SELECT COUNT(*)
            FROM quota_reservations
            WHERE storage_server_id = ?
              AND status = 'active'
        "));
        $reservationStmt->execute([$serverId]);
        if ((int)$reservationStmt->fetchColumn() > 0) {
            throw new \RuntimeException('Cannot delete this storage server while active upload quota reservations still reference it. Clear the uploads first.');
        }
    }

    private function updateServiceAvailable(): bool
    {
        return file_exists($this->projectRoot() . '/src/Service/UpdateService.php');
    }

    private function updateServiceUnavailableStatus(): array
    {
        return [
            'update_available' => false,
            'current_version' => $this->resolveInstalledVersionLabel(),
            'latest_version' => null,
            'checked_at' => null,
            'repo_configured' => null,
            'error' => 'This deployment does not include the one-click updater service. Use the manual upgrade path for releases.',
        ];
    }

    private function resolveInstalledVersionLabel(): string
    {
        $versionFile = $this->projectRoot() . '/config/version.php';
        if (is_file($versionFile)) {
            $versionData = include $versionFile;
            if (is_array($versionData) && !empty($versionData['version'])) {
                return (string)$versionData['version'];
            }
        }

        return 'unknown';
    }

    private function normalizeFilesystemSeparators(string $path): string
    {
        return preg_replace('#[\\\\/]+#', DIRECTORY_SEPARATOR, $path) ?? $path;
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        return $path !== '' && (
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 ||
            str_starts_with($path, '\\\\') ||
            str_starts_with($path, '/')
        );
    }

    private function canonicalizePathForValidation(string $path): string
    {
        $normalized = $this->normalizeFilesystemSeparators($path);
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

    private function pathStartsWith(string $candidate, string $base): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');
        return $candidate === $base || str_starts_with($candidate . '/', $base . '/');
    }

    private function validateLocalStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \RuntimeException('Storage Path is required.');
        }

        $safeBase = $this->canonicalizePathForValidation($this->projectRoot() . DIRECTORY_SEPARATOR . 'storage');
        if ($this->isAbsoluteFilesystemPath($path)) {
            $candidate = $this->canonicalizePathForValidation($path);
        } else {
            $relative = ltrim($this->normalizeFilesystemSeparators($path), DIRECTORY_SEPARATOR);
            $candidate = $this->canonicalizePathForValidation($this->projectRoot() . DIRECTORY_SEPARATOR . $relative);
        }

        if (!$this->pathStartsWith($candidate, $safeBase)) {
            throw new \RuntimeException('Local storage paths must stay inside the Fyuhls storage directory. Use a path under storage/, such as storage/uploads.');
        }

        return $path;
    }

    private function normalizeProviderPreset(?string $preset, string $serverType): string
    {
        $preset = strtolower(trim((string)$preset));
        if ($serverType === 'local') {
            return 'local';
        }

        return in_array($preset, ['b2', 'r2', 'wasabi', 's3'], true) ? $preset : 's3';
    }

    private function fallbackProviderPresetTab(?string $preset, ?string $type = null): string
    {
        $candidate = strtolower(trim((string)$preset));
        if ($candidate === '') {
            $candidate = strtolower(trim((string)$type));
        }

        return in_array($candidate, ['local', 'b2', 'r2', 'wasabi', 's3'], true) ? $candidate : 'local';
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return SafeNetworkTargetService::isPrivateOrReservedIp($ip);
    }

    private function validateResolvableHostSafety(string $host): void
    {
        SafeNetworkTargetService::assertSafeResolvableHost($host, 'Storage endpoint');
    }

    private function normalizeEndpointUrl(string $endpoint, string $label): array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new \RuntimeException($label . ' is required.');
        }

        if (!str_starts_with($endpoint, 'http://') && !str_starts_with($endpoint, 'https://')) {
            $endpoint = 'https://' . $endpoint;
        }

        $parts = parse_url($endpoint);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int)$parts['port'] : null;

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException($label . ' must be a valid http:// or https:// URL.');
        }
        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['path']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            throw new \RuntimeException($label . ' must only contain the endpoint host and optional port.');
        }

        $this->validateResolvableHostSafety($host);

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'url' => $scheme . '://' . $host . ($port !== null ? ':' . $port : ''),
        ];
    }

    private function normalizePublicUrl(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }

        $validated = SafeNetworkTargetService::normalizePublicHttpUrl($url, 'Public Download URL');
        return rtrim($validated, '/') . '/';
    }

    private function normalizeDeliveryMethod(?string $method): string
    {
        $method = trim((string)$method);
        return in_array($method, ['php', 'nginx', 'apache', 'litespeed'], true) ? $method : 'php';
    }

    private function normalizeFileServerType(?string $type): string
    {
        $type = trim((string)$type);
        if (!in_array($type, ['local', 's3'], true)) {
            throw new \RuntimeException('Unsupported storage server type.');
        }

        return $type;
    }

    private function validateStorageAutomationOrigin(string $origin): string
    {
        $origin = rtrim(trim($origin), '/');
        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        if ($origin === '' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \RuntimeException('Set your real production URL in Config Hub > SEO before using automatic storage CORS. Fyuhls should not apply upload CORS for localhost.');
        }

        return $origin;
    }

    private function getStorageAutomationOrigins(): array
    {
        $origins = [];
        $origins[] = $this->validateStorageAutomationOrigin(\App\Service\SeoService::trustedBaseUrl());

        $requestHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($requestHost !== '') {
            $requestScheme = 'http';
            $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            if ($forwardedProto !== '') {
                $requestScheme = explode(',', $forwardedProto)[0] === 'https' ? 'https' : 'http';
            } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
                $requestScheme = strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
            } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                $requestScheme = 'https';
            }

            $origins[] = $this->validateStorageAutomationOrigin($requestScheme . '://' . $requestHost);
        }

        return array_values(array_unique($origins));
    }

    private function requestArchiveStatusMap(): array
    {
        return [
            'support_ticket' => ['closed'],
            'site_request' => ['archived', 'closed'],
            'dmca_report' => ['accepted', 'rejected', 'closed'],
            'abuse_report' => ['action_taken', 'ignored', 'closed'],
        ];
    }

    private function isArchivedRequestStatus(string $type, string $status): bool
    {
        return in_array($status, $this->requestArchiveStatusMap()[$type] ?? [], true);
    }

    private function normalizeRequestQueuePage(): int
    {
        return max(1, min(self::REQUEST_QUEUE_MAX_PAGE, (int)($_GET['page'] ?? 1)));
    }

    private function requestQueueSourceLimit(int $page, int $perPage, bool $hasSearch): int
    {
        $minimumWindow = $hasSearch ? self::REQUEST_QUEUE_SEARCH_SOURCE_WINDOW : self::REQUEST_QUEUE_SOURCE_WINDOW;
        return min(self::REQUEST_QUEUE_MAX_SOURCE_WINDOW, max($minimumWindow, ($page + 1) * $perPage));
    }

    private function requestQueueCandidateTypes(?string $lockedType, string $filterType, array $availableTypeLinks): array
    {
        $orderedTypes = ['support_ticket', 'site_request', 'abuse_report', 'dmca_report'];

        if ($lockedType !== null) {
            return in_array($lockedType, $orderedTypes, true) ? [$lockedType] : [];
        }

        if (in_array($filterType, $orderedTypes, true)) {
            return [$filterType];
        }

        $types = [];
        foreach ($orderedTypes as $type) {
            if (isset($availableTypeLinks[$type]) && $this->currentStaffCanAccessRequestType($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private function requestQueueStaleThreshold(string $filterStale): ?string
    {
        $days = match ($filterStale) {
            '3d' => 3,
            '7d' => 7,
            '14d' => 14,
            default => 0,
        };

        return $days > 0 ? date('Y-m-d H:i:s', time() - ($days * 86400)) : null;
    }

    private function appendRequestQueueStatusSql(array &$clauses, array &$params, string $type, string $column, bool $showArchived, string $filterStatus): void
    {
        $archiveStatuses = $this->requestArchiveStatusMap()[$type] ?? [];
        if ($archiveStatuses !== []) {
            $placeholders = implode(', ', array_fill(0, count($archiveStatuses), '?'));
            $clauses[] = $showArchived ? "{$column} IN ({$placeholders})" : "{$column} NOT IN ({$placeholders})";
            foreach ($archiveStatuses as $status) {
                $params[] = $status;
            }
        }

        if ($filterStatus !== '') {
            $clauses[] = "{$column} = ?";
            $params[] = $filterStatus;
        }
    }

    private function requestQueueFixedPriorityMatches(string $type, string $filterPriority): bool
    {
        if (!in_array($filterPriority, ['normal', 'high'], true)) {
            return true;
        }

        $fixedPriority = in_array($type, ['abuse_report', 'dmca_report'], true) ? 'high' : 'normal';
        return $fixedPriority === $filterPriority;
    }

    private function fetchLegacyRequestQueueItems(
        \PDO $db,
        string $type,
        bool $showArchived,
        string $filterStatus,
        string $filterPriority,
        ?string $staleThreshold,
        int $limit
    ): array {
        if (!$this->requestQueueFixedPriorityMatches($type, $filterPriority)) {
            return [];
        }

        $clauses = [];
        $params = [];
        $items = [];

        if ($type === 'site_request') {
            $this->appendRequestQueueStatusSql($clauses, $params, $type, 'status', $showArchived, $filterStatus);
            if ($staleThreshold !== null) {
                $clauses[] = 'created_at <= ?';
                $params[] = $staleThreshold;
            }
            $sql = 'SELECT * FROM contact_messages'
                . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
                . ' ORDER BY created_at DESC LIMIT ?';
            $stmt = $db->prepare($sql);
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param);
            }
            $stmt->bindValue(count($params) + 1, max(1, $limit), \PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $m) {
                $items[] = [
                    'request_type' => 'Site Request',
                    'type_key' => 'site_request',
                    'backend' => 'legacy',
                    'id' => (int)$m['id'],
                    'created_at' => $m['created_at'],
                    'submitter_name' => EncryptionService::decrypt($m['name']),
                    'submitter_email' => EncryptionService::decrypt($m['email']),
                    'target' => EncryptionService::decrypt($m['subject']),
                    'summary' => EncryptionService::decrypt($m['message']),
                    'details' => EncryptionService::decrypt($m['message']),
                    'status' => $m['status'],
                ];
            }
        } elseif ($type === 'abuse_report') {
            $this->appendRequestQueueStatusSql($clauses, $params, $type, 'r.status', $showArchived, $filterStatus);
            if ($staleThreshold !== null) {
                $clauses[] = 'r.created_at <= ?';
                $params[] = $staleThreshold;
            }
            $sql = 'SELECT r.*, f.filename, f.short_id FROM abuse_reports r JOIN files f ON r.file_id = f.id'
                . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
                . ' ORDER BY r.created_at DESC LIMIT ?';
            $stmt = $db->prepare($sql);
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param);
            }
            $stmt->bindValue(count($params) + 1, max(1, $limit), \PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $r) {
                $items[] = [
                    'request_type' => 'Abuse Report',
                    'type_key' => 'abuse_report',
                    'backend' => 'legacy',
                    'id' => (int)$r['id'],
                    'created_at' => $r['created_at'],
                    'submitter_name' => 'Reporter IP',
                    'submitter_email' => EncryptionService::decrypt($r['reporter_ip']),
                    'target' => EncryptionService::decrypt($r['filename']) . ' (' . ($r['short_id'] ?? '') . ')',
                    'summary' => strtoupper((string)$r['reason']) . (!empty($r['details']) ? ' - ' . EncryptionService::decrypt($r['details']) : ''),
                    'details' => EncryptionService::decrypt((string)($r['details'] ?? '')),
                    'reason' => strtoupper((string)$r['reason']),
                    'status' => $r['status'],
                ];
            }
        } elseif ($type === 'dmca_report') {
            $this->appendRequestQueueStatusSql($clauses, $params, $type, 'status', $showArchived, $filterStatus);
            if ($staleThreshold !== null) {
                $clauses[] = 'created_at <= ?';
                $params[] = $staleThreshold;
            }
            $sql = 'SELECT * FROM dmca_reports'
                . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
                . ' ORDER BY created_at DESC LIMIT ?';
            $stmt = $db->prepare($sql);
            foreach ($params as $index => $param) {
                $stmt->bindValue($index + 1, $param);
            }
            $stmt->bindValue(count($params) + 1, max(1, $limit), \PDO::PARAM_INT);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $r) {
                $target = EncryptionService::decrypt($r['infringing_url']);
                $items[] = [
                    'request_type' => 'DMCA Report',
                    'type_key' => 'dmca_report',
                    'backend' => 'legacy',
                    'id' => (int)$r['id'],
                    'created_at' => $r['created_at'],
                    'submitter_name' => EncryptionService::decrypt($r['reporter_name']),
                    'submitter_email' => EncryptionService::decrypt($r['reporter_email']),
                    'target' => $target,
                    'summary' => EncryptionService::decrypt($r['description']),
                    'details' => EncryptionService::decrypt($r['description']),
                    'status' => $r['status'],
                    'signature' => EncryptionService::decrypt($r['signature']),
                    'target_files' => [],
                ];
            }
        }

        return $items;
    }

    private function decorateRequestQueueItems(array $items): array
    {
        foreach ($items as &$item) {
            $item['priority'] = $item['priority'] ?? (in_array((string)($item['type_key'] ?? ''), ['abuse_report', 'dmca_report'], true) ? 'high' : 'normal');
            $lastTouchAt = (string)($item['sort_at'] ?? $item['created_at'] ?? '');
            $item['last_touch_at'] = $lastTouchAt;
            $item['stale_days'] = $lastTouchAt !== '' ? (int)floor(max(0, time() - strtotime($lastTouchAt)) / 86400) : 0;
            $item['needs_staff_action'] = in_array((string)($item['status'] ?? ''), ['open', 'new', 'pending', 'waiting_staff', 'investigating', 'reviewed'], true);
        }
        unset($item);

        return $items;
    }

    private function hydrateRequestQueuePageItems(array $items): array
    {
        $ticketIds = [];
        foreach ($items as $item) {
            if (($item['backend'] ?? 'legacy') === 'ticket') {
                $ticketIds[] = (int)$item['id'];
            }
        }
        $threadsByTicketId = \App\Service\TicketService::getThreads($ticketIds, true);

        foreach ($items as &$item) {
            if (($item['backend'] ?? 'legacy') === 'ticket') {
                $thread = $threadsByTicketId[(int)$item['id']] ?? [];
                $item['thread'] = $thread;

                foreach ($thread as $message) {
                    if (($message['message_type'] ?? '') === 'intake') {
                        $item['details'] = (string)($message['body'] ?? '');
                        break;
                    }
                }

                if (empty($item['latest_reply'])) {
                    foreach (array_reverse($thread) as $message) {
                        if (($message['message_type'] ?? '') === 'reply') {
                            $item['latest_reply'] = [
                                'created_at' => $message['created_at'],
                                'username' => $message['author_name'] ?? (($message['author_type'] ?? '') === 'admin' ? 'Staff' : null),
                            ];
                            break;
                        }
                    }
                }
            }
        }
        unset($item);

        return $this->hydrateRequestQueueDmcaTargets($items);
    }

    private function hydrateRequestQueueDmcaTargets(array $items): array
    {
        $targetsByItemIndex = [];
        $shortIds = [];

        foreach ($items as $itemIndex => $item) {
            if (($item['type_key'] ?? '') !== 'dmca_report' || !empty($item['target_files'])) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim((string)($item['target'] ?? ''))) ?: [];
            foreach ($lines as $line) {
                $url = trim($line);
                if ($url === '') {
                    continue;
                }

                $shortId = $this->extractShortIdFromDmcaUrl($url);
                $targetsByItemIndex[$itemIndex][] = [
                    'url' => $url,
                    'short_id' => $shortId,
                    'file_id' => 0,
                    'filename' => null,
                    'status' => null,
                    'matched' => false,
                ];
                if ($shortId !== null) {
                    $shortIds[$shortId] = true;
                }
            }
        }

        $filesByShortId = [];
        if ($shortIds !== []) {
            $shortIds = array_keys($shortIds);
            $placeholders = implode(', ', array_fill(0, count($shortIds), '?'));
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT f.id, f.short_id, f.filename, f.status
                FROM files f
                JOIN stored_files sf ON sf.id = f.stored_file_id
                WHERE f.short_id IN ({$placeholders})
            ");
            $stmt->execute($shortIds);

            foreach ($stmt->fetchAll() as $file) {
                $shortId = (string)($file['short_id'] ?? '');
                if ($shortId === '') {
                    continue;
                }
                $filesByShortId[$shortId] = [
                    'id' => (int)$file['id'],
                    'filename' => EncryptionService::decrypt((string)($file['filename'] ?? '')),
                    'status' => (string)($file['status'] ?? ''),
                ];
            }
        }

        foreach ($targetsByItemIndex as $itemIndex => &$targets) {
            foreach ($targets as &$target) {
                $file = $target['short_id'] !== null ? ($filesByShortId[$target['short_id']] ?? null) : null;
                if ($file === null) {
                    continue;
                }
                $target['file_id'] = $file['id'];
                $target['filename'] = $file['filename'];
                $target['status'] = $file['status'];
                $target['matched'] = true;
            }
            unset($target);
            $items[$itemIndex]['target_files'] = $targets;
        }
        unset($targets);

        return $items;
    }

    private function maskDemoRequestQueueItems(array $items): array
    {
        foreach ($items as &$item) {
            $item['submitter_name'] = DemoModeService::maskPerson((string)($item['submitter_name'] ?? ''));
            $rawSubmitter = (string)($item['submitter_email'] ?? '');
            $item['submitter_email'] = str_contains($rawSubmitter, '@')
                ? DemoModeService::maskEmail($rawSubmitter)
                : DemoModeService::maskIp($rawSubmitter);

            foreach (['summary', 'details', 'signature'] as $field) {
                if (isset($item[$field])) {
                    $item[$field] = DemoModeService::hiddenLabel();
                }
            }

            if (!empty($item['latest_reply']['username'])) {
                $item['latest_reply']['username'] = DemoModeService::maskPerson((string)$item['latest_reply']['username']);
            }

            if (!empty($item['thread']) && is_array($item['thread'])) {
                foreach ($item['thread'] as &$threadMessage) {
                    $threadMessage['body'] = DemoModeService::hiddenLabel();
                    if (!empty($threadMessage['author_name'])) {
                        $threadMessage['author_name'] = DemoModeService::maskPerson((string)$threadMessage['author_name']);
                    }
                }
                unset($threadMessage);
            }

            if (!empty($item['activities']) && is_array($item['activities'])) {
                foreach ($item['activities'] as &$activity) {
                    if (!empty($activity['subject'])) {
                        $activity['subject'] = DemoModeService::hiddenLabel();
                    }
                    if (!empty($activity['body'])) {
                        $activity['body'] = DemoModeService::hiddenLabel();
                    }
                }
                unset($activity);
            }

            if (($item['type_key'] ?? '') === 'dmca_report') {
                $item['target'] = DemoModeService::hiddenLabel();
                $targetFiles = is_array($item['target_files'] ?? null) ? $item['target_files'] : [];
                foreach ($targetFiles as &$targetFile) {
                    $targetFile['url'] = DemoModeService::hiddenLabel();
                    if (!empty($targetFile['filename'])) {
                        $targetFile['filename'] = DemoModeService::hiddenLabel();
                    }
                }
                unset($targetFile);
                $item['target_files'] = $targetFiles;
            }
        }
        unset($item);

        return $items;
    }

    private function ensureRequestInboxSchema(): void
    {
        if (self::$skipRequestInboxSchemaForTests || self::$requestInboxSchemaReady) {
            return;
        }

        SchemaService::ensureTables(['admin_request_activity'], false);
        self::$requestInboxSchemaReady = true;
    }

    private function addRequestActivity(string $requestType, int $requestId, string $activityType, ?string $subject = null, ?string $body = null, array $metadata = []): void
    {
        $this->ensureRequestInboxSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO admin_request_activity (request_type, request_id, admin_user_id, activity_type, subject, body, metadata_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $requestType,
            $requestId,
            Auth::id() ? (int)Auth::id() : null,
            $activityType,
            $subject,
            $body,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);
        self::fireAfterRequestActivityPersistForTests([
            'request_type' => $requestType,
            'request_id' => $requestId,
            'activity_type' => $activityType,
            'subject' => $subject,
            'body' => $body,
            'metadata' => $metadata,
        ]);
    }

    private function fetchRequestActivityMap(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $this->ensureRequestInboxSchema();
        $db = Database::getInstance()->getConnection();
        $clauses = [];
        $params = [];

        foreach ($items as $item) {
            $clauses[] = '(request_type = ? AND request_id = ?)';
            $params[] = (string)($item['activity_type'] ?? $item['type_key']);
            $params[] = (int)$item['id'];
        }

        $sql = "
            SELECT a.*, u.username
            FROM admin_request_activity a
            LEFT JOIN users u ON a.admin_user_id = u.id
            WHERE " . implode(' OR ', $clauses) . "
            ORDER BY a.created_at DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['request_type'] . ':' . $row['request_id'];
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
            $metadata = [];
            if (!empty($row['metadata_json'])) {
                $decoded = json_decode((string)$row['metadata_json'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
            if (!empty($metadata['encrypted'])) {
                if (!empty($row['subject'])) {
                    $row['subject'] = EncryptionService::decrypt((string)$row['subject']);
                }
                if (!empty($row['body'])) {
                    $row['body'] = EncryptionService::decrypt((string)$row['body']);
                }
            }
            $row['username'] = EncryptionService::decrypt((string)($row['username'] ?? ''));
            $map[$key][] = $row;
        }

        return $map;
    }

    private function updateInboxStatus(string $type, int $id, string $status): void
    {
        $db = Database::getInstance()->getConnection();

        if ($type === 'support_ticket') {
            \App\Service\TicketService::updateStatusByAdmin($id, (int)(Auth::id() ?? 0), $status);
            return;
        }

        if ($type === 'site_request') {
            $allowed = ['new', 'read', 'replied', 'archived', 'closed'];
            if (!in_array($status, $allowed, true)) {
                throw new \RuntimeException('Invalid contact status.');
            }
            $db->prepare("UPDATE contact_messages SET status = ? WHERE id = ?")->execute([$status === 'closed' ? 'archived' : $status, $id]);
            return;
        }

        if ($type === 'dmca_report') {
            $allowed = ['pending', 'investigating', 'accepted', 'rejected', 'resolved'];
            if (!in_array($status, $allowed, true)) {
                throw new \RuntimeException('Invalid DMCA status.');
            }
            $db->prepare("UPDATE dmca_reports SET status = ? WHERE id = ?")->execute([$status === 'resolved' ? 'accepted' : $status, $id]);
            return;
        }

        if ($type === 'abuse_report') {
            $allowed = ['pending', 'reviewed', 'action_taken', 'ignored', 'dismissed'];
            if (!in_array($status, $allowed, true)) {
                throw new \RuntimeException('Invalid abuse status.');
            }
            $db->prepare("UPDATE abuse_reports SET status = ? WHERE id = ?")->execute([$status === 'dismissed' ? 'ignored' : $status, $id]);
            return;
        }

        throw new \RuntimeException('Unknown request type.');
    }

    private function assertRequestExists(string $type, int $id): void
    {
        $db = Database::getInstance()->getConnection();

        if ($type === 'support_ticket') {
            \App\Service\TicketService::ensureSchema();
            $stmt = $db->prepare("SELECT 1 FROM support_tickets WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Support ticket not found.');
            }
            return;
        }

        if ($type === 'site_request') {
            $stmt = $db->prepare("SELECT 1 FROM contact_messages WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Contact message not found.');
            }
            return;
        }

        if ($type === 'dmca_report') {
            $stmt = $db->prepare("SELECT 1 FROM dmca_reports WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('DMCA report not found.');
            }
            return;
        }

        if ($type === 'abuse_report') {
            $stmt = $db->prepare("SELECT 1 FROM abuse_reports WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Abuse report not found.');
            }
            return;
        }

        throw new \RuntimeException('Unknown request type.');
    }

    private function runDatabaseTransaction(callable $callback): mixed
    {
        $db = Database::getInstance()->getConnection();
        $startedTransaction = !$db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $result = $callback($db);
            if ($startedTransaction) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function sendLegacyRequestReply(string $requestType, string $email, string $subject, string $message): void
    {
        if (is_callable(self::$legacyRequestReplySenderForTests)) {
            (self::$legacyRequestReplySenderForTests)([
                'request_type' => $requestType,
                'email' => $email,
                'subject' => $subject,
                'message' => $message,
            ]);
            return;
        }

        $mail = MailService::createFromSettings();
        $mail->send($email, $subject, $message);
    }

    private function touchUsersAfterModerationCommit(array $userIds, string $workflow, array $context = []): void
    {
        $normalizedUserIds = [];
        foreach ($userIds as $userId) {
            $normalizedUserId = (int)$userId;
            if ($normalizedUserId > 0) {
                $normalizedUserIds[$normalizedUserId] = $normalizedUserId;
            }
        }

        if ($normalizedUserIds === []) {
            return;
        }

        \App\Service\BonusOfferService::touchUsersFailSoft(array_values($normalizedUserIds), true, array_merge([
            'workflow' => $workflow,
        ], $context));
    }

    private function resolveTicketBackedRequest(?string $publicId, int $expectedId, string $expectedType): ?array
    {
        $publicId = trim((string)$publicId);
        if ($publicId === '') {
            $ticketById = \App\Service\TicketService::getAdminTicketById($expectedId);
            if ($ticketById) {
                $resolvedType = \App\Service\TicketService::queueTypeKeyForTicketType((string)($ticketById['ticket_type'] ?? 'support'));
                if (!$this->currentStaffCanSeeTicketRow(array_merge($ticketById, ['type_key' => $resolvedType, 'backend' => 'ticket']))) {
                    throw new \RuntimeException('Ticket not found.');
                }
                if ($resolvedType === $expectedType) {
                    throw new \RuntimeException('Ticket public ID is required for this queue action.');
                }
            }
            return null;
        }

        $ticket = \App\Service\TicketService::getAdminTicketByPublicId($publicId);
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }

        if ((int)($ticket['id'] ?? 0) !== $expectedId) {
            throw new \RuntimeException('Ticket target mismatch.');
        }

        $resolvedType = \App\Service\TicketService::queueTypeKeyForTicketType((string)($ticket['ticket_type'] ?? 'support'));
        if ($resolvedType !== $expectedType) {
            throw new \RuntimeException('Ticket type mismatch.');
        }
        if (!$this->currentStaffCanSeeTicketRow(array_merge($ticket, ['type_key' => $resolvedType, 'backend' => 'ticket']))) {
            throw new \RuntimeException('Ticket not found.');
        }

        return $ticket;
    }

    private function resolveDmcaTargetFiles(?string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim((string)$raw)) ?: [];
        $targets = [];

        foreach ($lines as $line) {
            $url = trim($line);
            if ($url === '') {
                continue;
            }

            $shortId = $this->extractShortIdFromDmcaUrl($url);
            $file = $shortId !== null ? $this->findFileByExactShortId($shortId) : null;
            $targets[] = [
                'url' => $url,
                'short_id' => $shortId,
                'file_id' => (int)($file['id'] ?? 0),
                'filename' => isset($file['filename']) ? (string)$file['filename'] : null,
                'status' => isset($file['status']) ? (string)$file['status'] : null,
                'matched' => $file !== null,
            ];
        }

        return $targets;
    }

    private function extractShortIdFromDmcaUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !$this->isDmcaTargetLocal($parts)) {
            return null;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
        $fileIndex = array_search('file', $segments, true);
        if ($fileIndex === false || !isset($segments[$fileIndex + 1])) {
            return null;
        }

        $shortId = trim((string)$segments[$fileIndex + 1]);
        return $shortId !== '' ? $shortId : null;
    }

    private function isDmcaTargetLocal(array $parts): bool
    {
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '') {
            return false;
        }

        $trustedHost = strtolower((string)(parse_url(\App\Service\SeoService::trustedBaseUrl(), PHP_URL_HOST) ?? ''));
        if ($trustedHost !== '' && $host === $trustedHost) {
            return true;
        }

        $requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        if ($requestHost !== '') {
            $requestHost = strtolower((string)(parse_url('http://' . $requestHost, PHP_URL_HOST) ?? $requestHost));
        }

        return $requestHost !== '' && $host === $requestHost;
    }

    private function findFileByExactShortId(string $shortId): ?array
    {
        $shortId = trim($shortId);
        if ($shortId === '') {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM files WHERE short_id = ? LIMIT 1");
        $stmt->execute([$shortId]);
        $fileId = (int)($stmt->fetchColumn() ?: 0);

        return $fileId > 0 ? \App\Model\File::findAnyStatus($fileId) : null;
    }

    private function processDmcaFileRemovalBatch(array $fileIds, int $requestId): array
    {
        $this->assertCanModerateFilesForSpecializedRequest('DMCA');
        $processedCount = 0;
        $alreadyRemovedCount = 0;
        $processedLabels = [];
        $bonusTouchUserIds = [];
        $pendingFileIds = [];
        $audit = [
            'deleted_by_user_id' => Auth::id() ? (int)Auth::id() : null,
            'deleted_by_role' => 'admin',
            'deleted_by_label' => 'Administrator',
            'delete_reason' => 'Removed due to DMCA report.',
        ];

        foreach ($fileIds as $fileId) {
            $file = \App\Model\File::findAnyStatus($fileId);
            if (!$file) {
                continue;
            }

            $status = (string)($file['status'] ?? '');
            if (in_array($status, ['deleted', 'pending_purge', 'failed', 'abandoned', 'quarantined'], true)) {
                $alreadyRemovedCount++;
                continue;
            }

            $pendingFileIds[] = (int)$file['id'];
        }

        \App\Model\File::validateHardDeleteBatch($pendingFileIds, $audit);

        foreach ($pendingFileIds as $pendingFileId) {
            $file = \App\Model\File::findAnyStatus($pendingFileId);
            if (!$file) {
                continue;
            }

            $reversalResult = \App\Model\File::markPendingPurge($pendingFileId, $audit);
            $processedCount++;
            $label = (string)($file['filename'] ?? ('File #' . $pendingFileId));
            $shortId = trim((string)($file['short_id'] ?? ''));
            if ($shortId !== '') {
                $label .= ' (' . $shortId . ')';
            }
            $processedLabels[] = $label;
            foreach ((array)($reversalResult['user_ids'] ?? []) as $userId) {
                $normalizedUserId = (int)$userId;
                if ($normalizedUserId > 0) {
                    $bonusTouchUserIds[$normalizedUserId] = $normalizedUserId;
                }
            }
        }

        return [$processedCount, $alreadyRemovedCount, $processedLabels, array_values($bonusTouchUserIds)];
    }

    private function assertCanModerateFilesForSpecializedRequest(string $label): void
    {
        if (!Auth::hasCapability('files.moderate')) {
            throw new \RuntimeException($label . ' reviewers need file moderation permission before they can remove files.');
        }
    }

    private function sanitizeInternalRedirect(?string $target, string $fallback = '/admin'): string
    {
        if (!is_string($target) || $target === '') {
            return $fallback;
        }

        if ($target[0] !== '/' || str_starts_with($target, '//')) {
            return $fallback;
        }

        return $target;
    }

    private function checkAuth(string|array|null $capabilities = null, bool $allowStaff = false): void
    {
        if ($allowStaff) {
            Auth::requireStaff();
            if ($capabilities !== null) {
                $required = is_array($capabilities) ? $capabilities : [$capabilities];
                Auth::requireAnyCapability($required);
            }
            return;
        }

        Auth::requireAdmin();
        if ($capabilities !== null) {
            $required = is_array($capabilities) ? $capabilities : [$capabilities];
            Auth::requireAnyCapability($required);
        }
    }

    private function investigationAccessAllowed(): void
    {
        $this->checkAuth('investigations.view', true);
    }

    private function requestQueueCapabilitiesForType(?string $type): array
    {
        return match ($type) {
            'abuse_report' => ['abuse.manage'],
            'dmca_report' => ['dmca.manage'],
            'support_ticket', 'site_request', 'all', 'archived', null, '' => ['requests.manage'],
            default => ['requests.manage'],
        };
    }

    private function currentStaffCanAccessRequestType(string $type): bool
    {
        return match ($type) {
            'abuse_report' => Auth::hasCapability('abuse.manage'),
            'dmca_report' => Auth::hasCapability('dmca.manage'),
            'support_ticket', 'site_request' => Auth::hasCapability('requests.manage'),
            default => false,
        };
    }

    private function currentStaffCanAccessRequestTypeForUser(int $userId, string $role, string $type): bool
    {
        if ($userId <= 0 || $role === '') {
            return false;
        }

        return match ($type) {
            'abuse_report' => StaffPermissionService::userHasCapability($userId, $role, 'abuse.manage'),
            'dmca_report' => StaffPermissionService::userHasCapability($userId, $role, 'dmca.manage'),
            'support_ticket', 'site_request' => StaffPermissionService::userHasCapability($userId, $role, 'requests.manage'),
            default => false,
        };
    }

    private function currentStaffCanSeeTicketRow(array $ticket): bool
    {
        $type = (string)($ticket['type_key'] ?? \App\Service\TicketService::queueTypeKeyForTicketType((string)($ticket['ticket_type'] ?? 'support')));
        if (!$this->currentStaffCanAccessRequestType($type)) {
            return false;
        }

        if (empty($ticket['hidden_from_others'])) {
            return true;
        }

        $viewerId = (int)(Auth::id() ?? 0);
        if ($viewerId <= 0) {
            return false;
        }

        if (Auth::isSuperAdmin()) {
            return true;
        }

        if (!empty($ticket['hidden_by_admin_user_id']) && (int)$ticket['hidden_by_admin_user_id'] === $viewerId) {
            return true;
        }

        if (!empty($ticket['assigned_staff_user_id']) && (int)$ticket['assigned_staff_user_id'] === $viewerId) {
            return true;
        }

        return false;
    }

    private function filterAccessibleRequestItems(array $items): array
    {
        return array_values(array_filter($items, function (array $item): bool {
            $type = (string)($item['type_key'] ?? '');
            if ($type === '') {
                return false;
            }

            if (($item['backend'] ?? 'legacy') === 'ticket') {
                return $this->currentStaffCanSeeTicketRow($item);
            }

            return $this->currentStaffCanAccessRequestType($type);
        }));
    }

    private function activeAssignableStaffForRequestType(string $type): array
    {
        return $this->activeAssignableStaffForRequestTypes([$type])[$type] ?? [];
    }

    private function activeAssignableStaffForRequestTypes(array $types): array
    {
        $capabilitiesByType = [
            'support_ticket' => 'requests.manage',
            'site_request' => 'requests.manage',
            'abuse_report' => 'abuse.manage',
            'dmca_report' => 'dmca.manage',
        ];
        $types = array_values(array_intersect(array_keys($capabilitiesByType), array_unique(array_map('strval', $types))));
        $staffByType = array_fill_keys(array_keys($capabilitiesByType), []);
        if ($types === []) {
            return $staffByType;
        }

        $db = Database::getInstance()->getConnection();
        $rows = $db->query("
            SELECT id, role, username
            FROM users
            WHERE role IN ('admin', 'moderator') AND status = 'active'
            ORDER BY role ASC, id ASC
        ")->fetchAll();

        foreach ($rows as $row) {
            $userId = (int)($row['id'] ?? 0);
            $role = (string)($row['role'] ?? '');
            $name = EncryptionService::decrypt((string)($row['username'] ?? ''));
            $staff = [
                'id' => $userId,
                'role' => $role,
                'name' => $name !== '' ? $name : ('Staff #' . $userId),
            ];
            $capabilityMap = StaffPermissionService::getCapabilityMapForUser($userId, $role);

            foreach ($types as $type) {
                if (!empty($capabilityMap[$capabilitiesByType[$type]])) {
                    $staffByType[$type][] = $staff;
                }
            }
        }

        foreach ($types as $type) {
            usort($staffByType[$type], static function (array $a, array $b): int {
                $roleOrder = ['admin' => 0, 'moderator' => 1];
                $aOrder = $roleOrder[$a['role']] ?? 99;
                $bOrder = $roleOrder[$b['role']] ?? 99;
                if ($aOrder !== $bOrder) {
                    return $aOrder <=> $bOrder;
                }

                return strcasecmp((string)$a['name'], (string)$b['name']);
            });
        }

        return $staffByType;
    }

    private function requestQueueTypeLinksForCurrentStaff(): array
    {
        $links = [
            'all' => 'All',
            'archived' => 'Archive',
        ];

        if ($this->currentStaffCanAccessRequestType('support_ticket')) {
            $links['support_ticket'] = 'Support';
            $links['site_request'] = 'Contact';
        }

        if ($this->currentStaffCanAccessRequestType('dmca_report')) {
            $links['dmca_report'] = 'DMCA';
        }

        if ($this->currentStaffCanAccessRequestType('abuse_report')) {
            $links['abuse_report'] = 'Abuse';
        }

        return $links;
    }

    private function requireRequestQueueAccess(?string $type = null): void
    {
        $this->checkAuth($this->requestQueueCapabilitiesForType($type), true);
    }

    private function resolveRequestQueueBasePath(?string $lockedType = null): string
    {
        return match ($lockedType) {
            'site_request' => '/admin/contacts',
            'abuse_report' => '/admin/abuse-reports',
            'dmca_report' => '/admin/dmca',
            default => '/admin/requests',
        };
    }

    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    private function requestExpectsJson(): bool
    {
        $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    }

    private function formatRequestActivityForJson(array $activity): array
    {
        return [
            'activity_type' => (string)($activity['activity_type'] ?? ''),
            'activity_label' => str_replace('_', ' ', (string)($activity['activity_type'] ?? '')),
            'subject' => (string)($activity['subject'] ?? ''),
            'body' => (string)($activity['body'] ?? ''),
            'created_at' => (string)($activity['created_at'] ?? ''),
            'created_at_display' => !empty($activity['created_at']) ? date('Y-m-d H:i', strtotime((string)$activity['created_at'])) : '',
            'username' => (string)($activity['username'] ?? ''),
        ];
    }

    public function dashboard()
    {
        $this->checkAuth('dashboard.view', true);
        $statsService = new \App\Service\DashboardService();
        $canAccessDashboardFinancials = $this->canAccessDashboardFinancials();
        $canAccessDashboardIdentityInsights = $this->canAccessDashboardIdentityInsights();
        $canAccessDashboardReadiness = $this->canAccessDashboardReadiness();
        $canAccessDashboardSupportDiagnostics = $this->canAccessDashboardSupportDiagnostics();
        $canAccessDashboardModerationQueue = $this->canAccessDashboardModerationQueue();
        $canAccessDashboardSecurityWatch = $this->canAccessDashboardSecurityWatch();
        $canAccessDashboardAutomation = $this->canAccessDashboardAutomation();
        $canAccessDashboardDeliveryInsights = $this->canAccessDashboardDeliveryInsights();
        $canAccessDashboardInfrastructureHealth = $this->canAccessDashboardInfrastructureHealth();
        $canAccessDashboardFileLifecycleInsights = $this->canAccessDashboardFileLifecycleInsights();
        $canAccessDashboardConfiguration = Auth::hasCapability('configuration.manage');
        $canAccessDashboardSupport = Auth::hasCapability('support.manage');
        $canAccessDashboardStatus = Auth::hasCapability('status.view');
        $canAccessDashboardDocs = Auth::hasCapability('docs.view');
        $canAccessDashboardFileServers = Auth::hasCapability('file_servers.manage');
        $canAccessDashboardRequests = Auth::hasCapability('requests.manage');
        $canAccessDashboardAbuse = Auth::hasCapability('abuse.manage');
        $canAccessDashboardDmca = Auth::hasCapability('dmca.manage');
        $bundle = $this->filterDashboardBundleForViewer(
            $statsService->getStatsBundle(),
            [
                'financials' => $canAccessDashboardFinancials,
                'identity' => $canAccessDashboardIdentityInsights,
                'support_diagnostics' => $canAccessDashboardSupportDiagnostics,
                'moderation_queue' => $canAccessDashboardModerationQueue,
                'security_watch' => $canAccessDashboardSecurityWatch,
                'automation' => $canAccessDashboardAutomation,
                'delivery' => $canAccessDashboardDeliveryInsights,
                'infrastructure' => $canAccessDashboardInfrastructureHealth,
                'file_lifecycle' => $canAccessDashboardFileLifecycleInsights,
            ]
        );
        $systemPathReport = [];
        if ($canAccessDashboardReadiness) {
            $systemPathReport = (new DiagnosticsService())->getRuntimePathChecks();
        }

        View::render('admin/dashboard.php', [
            'bundle' => $bundle,
            'systemPathReport' => $systemPathReport,
            'canAccessDashboardFinancials' => $canAccessDashboardFinancials,
            'canAccessDashboardIdentityInsights' => $canAccessDashboardIdentityInsights,
            'canAccessDashboardReadiness' => $canAccessDashboardReadiness,
            'canAccessDashboardSupportDiagnostics' => $canAccessDashboardSupportDiagnostics,
            'canAccessDashboardModerationQueue' => $canAccessDashboardModerationQueue,
            'canAccessDashboardSecurityWatch' => $canAccessDashboardSecurityWatch,
            'canAccessDashboardAutomation' => $canAccessDashboardAutomation,
            'canAccessDashboardDeliveryInsights' => $canAccessDashboardDeliveryInsights,
            'canAccessDashboardInfrastructureHealth' => $canAccessDashboardInfrastructureHealth,
            'canAccessDashboardFileLifecycleInsights' => $canAccessDashboardFileLifecycleInsights,
            'canAccessDashboardConfiguration' => $canAccessDashboardConfiguration,
            'canAccessDashboardSupport' => $canAccessDashboardSupport,
            'canAccessDashboardStatus' => $canAccessDashboardStatus,
            'canAccessDashboardDocs' => $canAccessDashboardDocs,
            'canAccessDashboardFileServers' => $canAccessDashboardFileServers,
            'canAccessDashboardRequests' => $canAccessDashboardRequests,
            'canAccessDashboardAbuse' => $canAccessDashboardAbuse,
            'canAccessDashboardDmca' => $canAccessDashboardDmca,
        ]);
    }

    private function filterDashboardBundleForViewer(array $bundle, array $access): array
    {
        $canAccessFinancials = !empty($access['financials']);
        $canAccessIdentityInsights = !empty($access['identity']);

        if (!$canAccessFinancials) {
            unset($bundle['widgets']['revenue']);
            if (isset($bundle['widgets']['top_content']) && is_array($bundle['widgets']['top_content'])) {
                $bundle['widgets']['top_content']['top_earners'] = [];
            }
        }

        if (!$canAccessIdentityInsights) {
            $bundle['widgets']['user_growth'] = [];
            if (isset($bundle['widgets']['top_content']) && is_array($bundle['widgets']['top_content'])) {
                $bundle['widgets']['top_content']['top_files'] = [];
                $bundle['widgets']['top_content']['top_storage_users'] = [];
            }
            $bundle['widgets']['recent_activity'] = [];
        }

        $widgetAccessMap = [
            'support_diagnostics' => !empty($access['support_diagnostics']),
            'moderation_queue' => !empty($access['moderation_queue']),
            'email_queue' => !empty($access['support_diagnostics']),
            'security_watch' => !empty($access['security_watch']),
            'automation' => !empty($access['automation']),
            'upload_pipeline' => !empty($access['infrastructure']),
            'storage_capacity' => !empty($access['infrastructure']),
            'host' => !empty($access['infrastructure']),
            'download_mix' => !empty($access['delivery']),
            'file_lifecycle' => !empty($access['file_lifecycle']),
        ];

        foreach ($widgetAccessMap as $widgetKey => $allowed) {
            if (!$allowed) {
                unset($bundle['widgets'][$widgetKey]);
            }
        }

        return $bundle;
    }

    public function viewLogs()
    {
        $this->enforceOperationalDiagnosticsAccess('Application logs require Configuration or Support diagnostics access.');
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $logFile = Logger::logFilePath();
        $logMaxBytes = Logger::maxBytes();
        $logExists = is_file($logFile);
        clearstatcache(true, $logFile);
        $logSizeBytes = $logExists ? (int)(filesize($logFile) ?: 0) : 0;

        $lines = [];
        if ($demoAdmin) {
            $lines[] = "Raw application logs are hidden for the demo admin account. Use a non-demo admin account for direct log access." . PHP_EOL;
        } elseif (!$logExists) {
            $lines[] = "Application log file does not exist yet. The first written log entry will create it." . PHP_EOL;
        } else {
            $fp = @fopen($logFile, 'r');
            if ($fp === false) {
                $lines[] = "Application log file could not be opened for reading." . PHP_EOL;
            } else {
                fseek($fp, 0, SEEK_END);
                $pos = ftell($fp);
                $count = 0;
                while ($pos > 0 && $count < 200) {
                    fseek($fp, $pos--);
                    if (fgetc($fp) === "\n") {
                        $count++;
                    }
                }
                while ($line = fgets($fp)) {
                    $decoded = json_decode($line, true);
                    if ($decoded && isset($decoded['ctx'])) {
                        foreach ($decoded['ctx'] as $key => &$val) {
                            $val = $this->sanitizeLogContextValue($val);
                        }
                        unset($val);
                        $line = json_encode($decoded) . PHP_EOL;
                    } else {
                        $line = $this->sanitizeLogRawText($line);
                    }
                    $lines[] = $line;
                }
                fclose($fp);
            }
        }

        View::render('admin/logs.php', [
            'logContent' => implode('', array_reverse($lines)),
            'demoAdmin' => $demoAdmin,
            'logSizeBytes' => $logSizeBytes,
            'logSizeReadable' => $this->formatReadableBytes($logSizeBytes),
            'logMaxBytes' => $logMaxBytes,
            'logMaxReadable' => $this->formatReadableBytes($logMaxBytes),
        ]);
    }

    public function clearLogs()
    {
        $this->checkAuth('configuration.manage', true);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $logFile = Logger::logFilePath();
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
            }
        }
        $target = $this->sanitizeInternalRedirect($_POST['redirect'] ?? null, '/admin/logs');
        header("Location: " . $target); exit;
    }

    public function deleteSetupFile()
    {
        $this->checkAuth('configuration.manage', true);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $type = $_POST['type'] ?? 'install';
            $root = defined('BASE_PATH') ? BASE_PATH : realpath(__DIR__ . '/../../..');
            $targets = [
                'install' => $root . '/public/install.php',
                'post_install_check' => $root . '/public/post_install_check.php',
            ];
            $target = $targets[$type] ?? $targets['install'];
            $target = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target);

            if (file_exists($target)) {
                $removed = false;
                if (is_dir($target)) {
                    $removed = $this->deleteDirectoryRecursively($target);
                } else {
                    $removed = @unlink($target);
                }
                clearstatcache(true, $target);
                if ($removed && !file_exists($target)) {
                    $_SESSION['success'] = "Maintenance cleanup successful.";
                } else {
                    $_SESSION['error'] = "Maintenance cleanup could not remove the selected setup artifact. Check filesystem permissions and delete it manually if needed.";
                }
            }
        }
        header("Location: /admin"); exit;
    }

    private function deleteDirectoryRecursively(string $path): bool
    {
        $items = @scandir($path);
        if ($items === false) {
            return false;
        }

        $success = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $success = $this->deleteDirectoryRecursively($itemPath) && $success;
            } elseif (file_exists($itemPath)) {
                $success = @unlink($itemPath) && $success;
            }
        }

        return @rmdir($path) && $success;
    }

    public function subscriptions()
    {
        $this->checkAuth('subscriptions.manage');
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT s.*, u.username, p.name as package_name FROM subscriptions s LEFT JOIN users u ON s.user_id = u.id LEFT JOIN packages p ON s.package_id = p.id ORDER BY s.created_at DESC");
        $subscriptions = $stmt->fetchAll();
        $subscriptionSyncStates = \App\Service\PaymentService::unresolvedGatewaySyncStates(array_map(
            static fn (array $subscription): int => (int)($subscription['id'] ?? 0),
            $subscriptions
        ));

        foreach ($subscriptions as &$sub) {
            $sub['username'] = EncryptionService::decrypt($sub['username'] ?? '');
            $sub['gateway_sync'] = $subscriptionSyncStates[(int)($sub['id'] ?? 0)] ?? null;
        }

        View::render('admin/subscriptions.php', ['subscriptions' => $subscriptions]);
    }

    public function createSubscription(): void
    {
        $this->checkAuth('subscriptions.manage');

        $paidPackages = array_values(array_filter(Package::getAll(), static fn (array $pkg): bool => ($pkg['level_type'] ?? '') === 'paid'));
        $formData = $_SESSION['subscription_form_data'] ?? [
            'user_lookup' => '',
            'package_id' => $paidPackages[0]['id'] ?? 0,
            'amount' => isset($paidPackages[0]['price']) ? (string)$paidPackages[0]['price'] : '0.00',
            'currency' => 'USD',
            'term_days' => isset($paidPackages[0]['subscription_term_days']) ? (int)$paidPackages[0]['subscription_term_days'] : 30,
            'status' => 'active',
            'expires_at' => date('Y-m-d\TH:i', strtotime('+30 days')),
            'admin_note' => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/subscriptions');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $_SESSION['error'] = 'Security check failed. Please try again.';
                header('Location: /admin/subscription/create');
                exit;
            }

            try {
                $subscriptionId = $this->saveManualSubscription($_POST);
                unset($_SESSION['subscription_form_data']);
                $_SESSION['success'] = 'Manual subscription created.';
                header('Location: /admin/subscriptions');
                exit;
            } catch (\Throwable $e) {
                $_SESSION['error'] = $e->getMessage();
                $_SESSION['subscription_form_data'] = $this->normalizeSubscriptionFormData($_POST);
                header('Location: /admin/subscription/create');
                exit;
            }
        }

        unset($_SESSION['subscription_form_data']);

        View::render('admin/subscription_create.php', [
            'subscription' => $formData,
            'paidPackages' => $paidPackages,
        ]);
    }

    public function updateManualSubscriptionStatus(): void
    {
        $this->checkAuth('subscriptions.manage');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, 'Method Not Allowed');
        }
        $this->ensureDemoAdminReadOnly(false, '/admin/subscriptions');
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF Token Mismatch');
        }

        $subscriptionId = (int)($_POST['subscription_id'] ?? 0);
        $nextStatus = strtolower(trim((string)($_POST['status'] ?? '')));
        $adminNote = trim((string)($_POST['admin_note'] ?? ''));
        if ($subscriptionId <= 0) {
            $_SESSION['error'] = 'Invalid subscription.';
            header('Location: /admin/subscriptions');
            exit;
        }
        if (!in_array($nextStatus, ['active', 'pending', 'cancelled', 'expired'], true)) {
            $_SESSION['error'] = 'Invalid subscription status.';
            header('Location: /admin/subscriptions');
            exit;
        }

        $db = Database::getInstance()->getConnection();
        try {
            $this->applyManualSubscriptionStatusChange($db, $subscriptionId, $nextStatus, $adminNote);
            $_SESSION['success'] = 'Manual subscription updated.';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'The manual subscription could not be updated safely right now.';
        }

        header('Location: /admin/subscriptions');
        exit;
    }

    private function applyManualSubscriptionStatusChange(\PDO $db, int $subscriptionId, string $nextStatus, string $adminNote = ''): void
    {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT s.*, u.username, p.name AS package_name
                FROM subscriptions s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN packages p ON p.id = s.package_id
                WHERE s.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$subscriptionId]);
            $subscription = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$subscription) {
                throw new \RuntimeException('Subscription not found.');
            }
            if (strtolower(trim((string)($subscription['gateway'] ?? ''))) !== 'manual') {
                throw new \RuntimeException('Only manual subscriptions can be managed from this screen.');
            }

            $userId = (int)($subscription['user_id'] ?? 0);
            if ($userId <= 0) {
                throw new \RuntimeException('This manual subscription is missing a valid user.');
            }

            $previousStatus = strtolower(trim((string)($subscription['status'] ?? 'pending')));
            if ($nextStatus === 'active') {
                ReviewIntegrityService::assertNotSelfManualSubscriptionGrant(Auth::id(), $userId);
                $expiresAt = strtotime((string)($subscription['expires_at'] ?? ''));
                if (!$expiresAt || $expiresAt <= time()) {
                    throw new \RuntimeException('Manual subscriptions can only be reactivated when their expiry is still in the future.');
                }

                if (PaymentService::countLiveOrPendingSubscriptions($db, $userId, $subscriptionId) > 0) {
                    throw new \RuntimeException('This user already has another live subscription. Cancel or expire that one before reactivating this manual subscription.');
                }
            }

            $update = $db->prepare("
                UPDATE subscriptions
                SET status = ?, auto_renew = 0, updated_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$nextStatus, $subscriptionId]);

            \App\Service\PaymentService::syncUserEntitlementsFromSubscriptions($db, $userId);
            StaffActivityService::logWithConnection(
                $db,
                'subscription_updated',
                'subscription',
                $subscriptionId,
                'Updated a manual premium subscription.',
                [
                    'user_id' => $userId,
                    'username' => (string)($subscription['username'] ?? ''),
                    'package_id' => (int)($subscription['package_id'] ?? 0),
                    'package_name' => (string)($subscription['package_name'] ?? ''),
                    'previous_status' => $previousStatus,
                    'status' => $nextStatus,
                    'expires_at' => (string)($subscription['expires_at'] ?? ''),
                    'admin_note' => $adminNote,
                ]
            );

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function coupons(): void
    {
        $this->checkAuth('coupons.manage');

        $coupons = CouponService::getCouponsForAdmin();
        $stats = [
            'active' => 0,
            'scheduled' => 0,
            'expired' => 0,
            'redeemed' => 0,
        ];
        $now = time();

        foreach ($coupons as $coupon) {
            $stats['redeemed'] += (int)($coupon['redeemed_count'] ?? 0);
            if ((int)($coupon['is_active'] ?? 0) !== 1) {
                continue;
            }
            if (!empty($coupon['starts_at']) && strtotime((string)$coupon['starts_at']) > $now) {
                $stats['scheduled']++;
            } elseif (!empty($coupon['expires_at']) && strtotime((string)$coupon['expires_at']) < $now) {
                $stats['expired']++;
            } else {
                $stats['active']++;
            }
        }

        View::render('admin/coupons.php', [
            'coupons' => $coupons,
            'stats' => $stats,
        ]);
    }

    public function createCoupon(): void
    {
        $this->checkAuth('coupons.manage');

        $paidPackages = array_values(array_filter(Package::getAll(), static fn (array $pkg): bool => ($pkg['level_type'] ?? '') === 'paid'));
        $formData = $_SESSION['coupon_form_data'] ?? [
            'code' => '',
            'internal_label' => '',
            'is_active' => 1,
            'starts_at' => null,
            'expires_at' => null,
            'discount_type' => 'amount',
            'discount_value' => '0.00',
            'percent_cap_amount' => null,
            'applies_to_all_paid' => 1,
            'eligible_package_ids' => [],
            'eligible_billing_option_ids' => [],
            'purchase_scope' => 'both',
            'new_account_rule' => 'first_paid_subscription',
            'renewal_rule' => 'active_or_returning',
            'duration_type' => 'once',
            'duration_cycles' => null,
            'total_redemption_limit' => null,
            'per_user_redemption_limit' => null,
            'notes' => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/coupons');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $_SESSION['error'] = 'Security check failed. Please try again.';
                header('Location: /admin/coupon/create');
                exit;
            }

            try {
                $couponCode = CouponService::normalizeCode((string)($_POST['code'] ?? ''));
                $discountType = (string)($_POST['discount_type'] ?? '');
                $discountValue = (float)($_POST['discount_value'] ?? 0);
                $couponId = CouponService::saveCoupon(
                    $_POST,
                    null,
                    static function (\PDO $db, int $savedCouponId) use ($couponCode, $discountType, $discountValue): void {
                        StaffActivityService::logWithConnection(
                            $db,
                            'coupon_created',
                            'coupon',
                            $savedCouponId,
                            'Created a premium coupon.',
                            [
                                'code' => $couponCode,
                                'discount_type' => $discountType,
                                'discount_value' => $discountValue,
                            ]
                        );
                    }
                );
                unset($_SESSION['coupon_form_data']);
                $_SESSION['success'] = 'Coupon created.';
                header('Location: /admin/coupon/edit/' . $couponId);
                exit;
            } catch (\Throwable $e) {
                $_SESSION['error'] = $e->getMessage();
                $_SESSION['coupon_form_data'] = CouponService::normalizeFormData($_POST);
                header('Location: /admin/coupon/create');
                exit;
            }
        }

        unset($_SESSION['coupon_form_data']);

        View::render('admin/coupon_edit.php', [
            'coupon' => $formData,
            'isNewCoupon' => true,
            'paidPackages' => $paidPackages,
            'recentRedemptions' => [],
        ]);
    }

    public function editCoupon(string $id): void
    {
        $this->checkAuth('coupons.manage');
        $couponId = (int)$id;
        $coupon = CouponService::findCouponForAdmin($couponId);
        if (!$coupon) {
            $_SESSION['error'] = 'Coupon not found.';
            header('Location: /admin/coupons');
            exit;
        }

        $paidPackages = array_values(array_filter(Package::getAll(), static fn (array $pkg): bool => ($pkg['level_type'] ?? '') === 'paid'));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/coupons');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                $_SESSION['error'] = 'Security check failed. Please try again.';
                header('Location: /admin/coupon/edit/' . $couponId);
                exit;
            }

            try {
                $couponCode = CouponService::normalizeCode((string)($_POST['code'] ?? ''));
                $discountType = (string)($_POST['discount_type'] ?? '');
                $discountValue = (float)($_POST['discount_value'] ?? 0);
                $isActive = !empty($_POST['is_active']) ? 1 : 0;
                CouponService::saveCoupon(
                    $_POST,
                    $couponId,
                    static function (\PDO $db, int $savedCouponId) use ($couponCode, $discountType, $discountValue, $isActive): void {
                        StaffActivityService::logWithConnection(
                            $db,
                            'coupon_updated',
                            'coupon',
                            $savedCouponId,
                            'Updated a premium coupon.',
                            [
                                'code' => $couponCode,
                                'discount_type' => $discountType,
                                'discount_value' => $discountValue,
                                'is_active' => $isActive,
                            ]
                        );
                    }
                );
                $_SESSION['success'] = 'Coupon updated.';
                header('Location: /admin/coupon/edit/' . $couponId);
                exit;
            } catch (\Throwable $e) {
                $_SESSION['error'] = $e->getMessage();
                $coupon = array_merge($coupon, CouponService::normalizeFormData($_POST));
            }
        }

        $recentRedemptions = CouponService::recentRedemptionsForCoupon($couponId, 100);

        View::render('admin/coupon_edit.php', [
            'coupon' => $coupon,
            'isNewCoupon' => false,
            'paidPackages' => $paidPackages,
            'recentRedemptions' => $recentRedemptions,
        ]);
    }

    private function normalizeSubscriptionFormData(array $input): array
    {
        return [
            'user_lookup' => trim((string)($input['user_lookup'] ?? '')),
            'package_id' => (int)($input['package_id'] ?? 0),
            'amount' => trim((string)($input['amount'] ?? '0.00')),
            'currency' => strtoupper(trim((string)($input['currency'] ?? 'USD'))),
            'term_days' => max(1, (int)($input['term_days'] ?? 30)),
            'status' => trim((string)($input['status'] ?? 'active')),
            'expires_at' => trim((string)($input['expires_at'] ?? '')),
            'admin_note' => trim((string)($input['admin_note'] ?? '')),
        ];
    }

    private function saveManualSubscription(array $input): int
    {
        $data = $this->normalizeSubscriptionFormData($input);
        $note = $data['admin_note'];
        if ($note === '') {
            throw new \RuntimeException('An admin note is required when creating a manual subscription.');
        }

        $user = $this->resolveSubscriptionTargetUser($data['user_lookup']);
        if (!$user) {
            throw new \RuntimeException('User not found. Use an exact user ID, public ID, username, or email.');
        }
        ReviewIntegrityService::assertNotSelfManualSubscriptionGrant(Auth::id(), (int)($user['id'] ?? 0));

        $package = Package::find((int)$data['package_id']);
        if (!$package || ($package['level_type'] ?? '') !== 'paid') {
            throw new \RuntimeException('Select a valid paid package.');
        }

        $status = strtolower($data['status']);
        if (!in_array($status, ['active', 'pending', 'cancelled', 'expired'], true)) {
            throw new \RuntimeException('Invalid subscription status.');
        }

        $expiresAtTs = strtotime((string)$data['expires_at']);
        if (!$expiresAtTs) {
            throw new \RuntimeException('Enter a valid expiry date and time.');
        }
        if ($status === 'active' && $expiresAtTs <= time()) {
            throw new \RuntimeException('Active manual subscriptions need a future expiry.');
        }
        if ($status === 'expired' && $expiresAtTs > time()) {
            throw new \RuntimeException('Expired manual subscriptions should use a past expiry.');
        }

        $amount = round((float)$data['amount'], 2);
        if ($amount < 0) {
            throw new \RuntimeException('Amount cannot be negative.');
        }

        $currency = $data['currency'] !== '' ? $data['currency'] : 'USD';
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException('Currency must be a 3-letter code like USD.');
        }

        $db = Database::getInstance()->getConnection();
        $manualSubscriptionLockKey = null;
        $db->beginTransaction();
        try {
            $manualSubscriptionLockKey = $this->acquireManualSubscriptionLock($db, (int)$user['id']);

            if (in_array($status, ['active', 'pending'], true)
                && PaymentService::countLiveOrPendingSubscriptions($db, (int)$user['id']) > 0) {
                throw new \RuntimeException('This user already has a live subscription. Edit or cancel it before adding another one.');
            }

            $db->prepare("
                INSERT INTO subscriptions
                    (user_id, package_id, coupon_id, coupon_code, original_amount, discount_amount, status, amount, currency, term_days, auto_renew, billing_period, gateway, gateway_reference, provider_subscription_id, expires_at)
                VALUES (?, ?, NULL, NULL, ?, 0.00, ?, ?, ?, ?, 0, ?, 'manual', ?, NULL, ?)
            ")->execute([
                (int)$user['id'],
                (int)$package['id'],
                $amount,
                $status,
                $amount,
                $currency,
                (int)$data['term_days'],
                $this->manualSubscriptionBillingPeriod((int)$data['term_days']),
                'manual_' . bin2hex(random_bytes(8)),
                date('Y-m-d H:i:s', $expiresAtTs),
            ]);
            $subscriptionId = (int)$db->lastInsertId();

            if ($status === 'active') {
                $db->prepare("
                    UPDATE users
                    SET package_id = ?, premium_expiry = ?, premium_started_at = COALESCE(premium_started_at, NOW())
                    WHERE id = ?
                ")->execute([
                    (int)$package['id'],
                    date('Y-m-d H:i:s', $expiresAtTs),
                    (int)$user['id'],
                ]);
            }

            StaffActivityService::logWithConnection(
                $db,
                'subscription_created',
                'subscription',
                $subscriptionId,
                'Created a manual premium subscription.',
                [
                    'user_id' => (int)$user['id'],
                    'username' => (string)($user['username'] ?? ''),
                    'package_id' => (int)$package['id'],
                    'package_name' => (string)($package['name'] ?? ''),
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => $currency,
                    'term_days' => (int)$data['term_days'],
                    'expires_at' => date('Y-m-d H:i:s', $expiresAtTs),
                    'admin_note' => $note,
                ]
            );

            $db->commit();
            $this->releaseManualSubscriptionLock($db, $manualSubscriptionLockKey);
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->releaseManualSubscriptionLock($db, $manualSubscriptionLockKey);
            throw $e;
        }

        return $subscriptionId;
    }

    private function acquireManualSubscriptionLock(\PDO $db, int $userId, int $timeoutSeconds = 5): string
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Manual subscription lock requires a valid user.');
        }

        $lockKey = 'manual_subscription:' . $userId;
        $stmt = $db->prepare("SELECT GET_LOCK(?, ?)");
        $stmt->execute([$lockKey, $timeoutSeconds]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new \RuntimeException('Could not acquire the subscription integrity lock for this user.');
        }

        return $lockKey;
    }

    private function releaseManualSubscriptionLock(\PDO $db, ?string $lockKey): void
    {
        $lockKey = trim((string)$lockKey);
        if ($lockKey === '') {
            return;
        }

        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockKey]);
        } catch (\Throwable $e) {
        }
    }

    private function resolveSubscriptionTargetUser(string $lookup): ?array
    {
        $lookup = trim($lookup);
        if ($lookup === '') {
            return null;
        }

        if (ctype_digit($lookup)) {
            return User::find((int)$lookup);
        }

        if (str_starts_with($lookup, 'u_')) {
            return User::findByPublicId($lookup);
        }

        return User::findByCredentials($lookup);
    }

    private function manualSubscriptionBillingPeriod(int $termDays): string
    {
        return $termDays >= 365 ? 'yearly' : 'monthly';
    }

    public function resources()
    {
        $this->checkAuth('resources.view', true);

        $resourceSections = [
            [
                'group' => 'tools',
                'title' => 'Affiliates',
                'description' => 'Affiliate programs that can help Fyuhls operators monetize traffic, test offer quality, or compare ad networks while building out their download pages and landing flows.',
                'items' => [
                    [
                        'name' => 'PopAds',
                        'url' => 'https://www.popads.net/users/refer/3654847',
                        'description' => 'PopAds is simply the best paying advertising network specialized in popunders on the Internet. We guarantee you that no other popunder ad network will pay better than us! Just register and see for yourself. Prepare to be astonished!',
                        'best_for' => 'Popunder monetization and payout comparison',
                    ],
                    [
                        'name' => 'HilltopAds',
                        'url' => 'https://hilltopads.com/?ref=327244',
                        'description' => 'A mainstream ad network worth reviewing if you want additional monetization options around download pages, redirects, and broader traffic monetization tests.',
                        'best_for' => 'Comparing ad-network payout and fill quality',
                    ],
                    [
                        'name' => 'Monetag',
                        'url' => 'https://monetag.com/?ref_id=zlFr',
                        'description' => 'A traffic monetization platform that can be useful when you want to compare ad formats, payout approaches, and fill quality against your existing setup.',
                        'best_for' => 'Testing traffic monetization beyond a single ad stack',
                    ],
                ],
            ],
            [
                'group' => 'tools',
                'title' => 'Technology Partners',
                'description' => 'Services and software resources that can strengthen fraud controls, operational insight, and the business side of a new file hosting site.',
                'items' => [
                    [
                        'name' => 'proxycheck.io',
                        'url' => 'https://proxycheck.io/',
                        'description' => 'A powerful API service for detecting VPNs, proxies, Tor exit nodes, and bad actors. It\'s an excellent tool to integrate if you want to block bots from inflating download counts or protect your platform from serial abusers and fraudulent reward claims. They offer a generous free tier, making it very easy to test out their intelligence feed alongside your own security rules.',
                        'best_for' => 'VPN, proxy, Tor, and fraud filtering',
                    ],
                    [
                        'name' => 'themasoftware.com',
                        'url' => 'https://themasoftware.com/',
                        'description' => 'A suite of mass-posting and content automation software widely used by top-tier uploaders and affiliates. Their tools (like themaPoster and themaManager) help users blast file links across hundreds of forums and blogs automatically. Understanding how these tools work is incredibly useful if you want to attract high-volume uploaders to your platform and monetize their traffic.',
                        'best_for' => 'Understanding uploader workflows and traffic sources',
                    ],
                ],
            ],
            [
                'group' => 'partners',
                'title' => 'Hosting Partners',
                'description' => 'Hosting and operator services that can help new Fyuhls admins launch faster, keep overhead lower, and get the basics in place without piecing everything together from scratch.',
                'items' => [
                    [
                        'name' => 'Hostinger Shared and VPS Web Hosting',
                        'url' => 'https://www.hostinger.com/?REFERRALCODE=PHXCORRECHKN',
                        'description' => 'Shared hosting and VPS options worth considering if you want a simple starting point for launching Fyuhls on a normal commercial host. Using the supplied link can get you an additional 20% off any prepaid period.',
                        'best_for' => 'Getting a new install online quickly',
                    ],
                    [
                        'name' => 'Hostinger Business Email',
                        'url' => 'https://www.hostinger.com/?REFERRALCODE=PHXCORRECHKN',
                        'description' => 'Business email packages for operators who want branded mailbox coverage for support, alerts, and transactional admin communication. Packages start at $0.39/month before coupon, and using the supplied link can get you an additional 20% off.',
                        'best_for' => 'Support inboxes, alerts, and branded email',
                    ],
                ],
            ],
        ];

        View::render('admin/resources.php', [
            'resourceSections' => $resourceSections,
            'sponsorEmail' => 'fyuhls.script@gmail.com',
        ]);
    }

    public function scalingGuide()
    {
        $this->checkAuth('status.view', true);
        if (!Auth::hasAnyCapability(['configuration.manage', 'file_servers.manage'])) {
            Auth::denyAccess('Additional staff permission is required to open this area.');
        }

        $db = Database::getInstance()->getConnection();
        $snapshot = $this->loadScalingGuideSnapshot($db);
        $servers = $snapshot['servers'];
        $packagesWithSpeedLimit = $snapshot['packagesWithSpeedLimit'];
        $packagesWithConcurrentLimit = $snapshot['packagesWithConcurrentLimit'];
        $loadWarnings = $snapshot['loadWarnings'];

        $summary = [
            'active_servers' => 0,
            'object_servers' => 0,
            'backblaze_like_object_servers' => 0,
            'local_servers' => 0,
            'nginx_servers' => 0,
            'apache_like_servers' => 0,
            'php_servers' => 0,
            'remote_accelerated_servers' => 0,
        ];

        foreach ($servers as $server) {
            $status = strtolower(trim((string)($server['status'] ?? '')));
            $status = str_replace('_', '-', $status);
            if (!in_array($status, ['active', 'read-only', 'readonly', 'enabled'], true)) {
                continue;
            }

            $summary['active_servers']++;
            $serverType = strtolower((string)($server['server_type'] ?? 'local'));
            $deliveryMethod = strtolower((string)($server['delivery_method'] ?? 'php'));
            $providerPreset = strtolower((string)(($server['config']['provider_preset'] ?? '') ?: ''));
            $isObject = in_array($serverType, ['s3', 'wasabi', 'backblaze', 'b2', 'r2'], true)
                || in_array($providerPreset, ['r2', 'b2', 'backblaze', 'wasabi', 's3'], true);

            if ($isObject) {
                $summary['object_servers']++;
                if (in_array($providerPreset, ['b2', 'backblaze'], true)) {
                    $summary['backblaze_like_object_servers']++;
                }
            } else {
                $summary['local_servers']++;
            }

            if ($deliveryMethod === 'nginx') {
                $summary['nginx_servers']++;
            } elseif (in_array($deliveryMethod, ['apache', 'litespeed'], true)) {
                $summary['apache_like_servers']++;
            } else {
                $summary['php_servers']++;
            }

            if ($isObject && in_array($deliveryMethod, ['php', 'nginx'], true)) {
                $summary['remote_accelerated_servers']++;
            }
        }

        $settings = [
            'ppd_min_download_percent' => max(0, (int)Setting::get('ppd_min_download_percent', '0')),
            'rewards_verified_completion_required' => Setting::get('rewards_verified_completion_required', '1') === '1',
            'track_current_downloads' => Setting::get('track_current_downloads', '0') === '1',
            'cdn_download_redirects_enabled' => Setting::get('cdn_download_redirects_enabled', '0') === '1',
            'cdn_download_base_url' => trim((string)Setting::get('cdn_download_base_url', '')),
            'streaming_support_enabled' => Setting::get('streaming_support_enabled', '0') === '1',
        ];
        $viewerCanConfig = Auth::hasCapability('configuration.manage');
        $viewerCanFileServers = Auth::hasCapability('file_servers.manage');
        $viewerCanPackages = Auth::hasCapability('packages.manage');
        $viewerCanRewardsFraud = \App\Service\FeatureService::rewardsEnabled() && Auth::hasCapability('rewards_fraud.manage');
        $canViewPolicyDetails = $viewerCanConfig;
        $hasEffectiveConcurrentPackagePressure = $settings['track_current_downloads'] && $packagesWithConcurrentLimit > 0;
        $hasPackageDeliveryPressure = $packagesWithSpeedLimit > 0 || $hasEffectiveConcurrentPackagePressure;
        $hasProviderDeliveryConstraints = $summary['backblaze_like_object_servers'] > 0;
        $hasNginxPercentOffloadSupport = $summary['nginx_servers'] > 0;

        $recommendations = [];
        $goodPractices = [];
        $throughputHelpers = [];
        $verificationFeatures = [];
        $conflicts = [];
        $currentBehavior = [];
        $scenarioMatrix = [];
        $quickActions = [];
        $whatIWouldDo = [];
        $recommendedProfile = [];

        $heavyFeatureCount = 0;
        if ($canViewPolicyDetails && $settings['ppd_min_download_percent'] > 0) {
            $heavyFeatureCount++;
        }
        if ($canViewPolicyDetails && $settings['rewards_verified_completion_required']) {
            $heavyFeatureCount++;
        }
        if ($canViewPolicyDetails && $settings['track_current_downloads']) {
            $heavyFeatureCount++;
        }
        if ($canViewPolicyDetails && $packagesWithSpeedLimit > 0) {
            $heavyFeatureCount++;
        }
        if ($canViewPolicyDetails && $hasEffectiveConcurrentPackagePressure) {
            $heavyFeatureCount++;
        }

        $cdnMisconfigured = $canViewPolicyDetails && $settings['cdn_download_redirects_enabled'] && $settings['cdn_download_base_url'] === '';
        $verdictClass = 'info';
        $verdictLabel = 'Balanced';
        $verdictSummary = 'This install can scale well, but a few settings still pull more download work back through the app than necessary.';

        if (!$canViewPolicyDetails) {
            $verdictClass = 'info';
            $verdictLabel = 'Storage Delivery View';
            $verdictSummary = 'This reduced view focuses on storage and delivery posture only. Monetization and package-enforcement details are intentionally hidden from this account.';
        } elseif ($cdnMisconfigured) {
            $verdictClass = 'danger';
            $verdictLabel = 'Scaling Conflict';
            $verdictSummary = 'One or more high-scale settings are incomplete. Fix those first so the platform does not advertise a fast path it cannot actually use.';
        } elseif ($summary['object_servers'] > 0 && $heavyFeatureCount === 0 && !$hasProviderDeliveryConstraints) {
            $verdictClass = 'success';
            $verdictLabel = 'High Throughput';
            $verdictSummary = 'This install is strongly biased toward lightweight authorization and remote byte delivery, which is the right shape for very high concurrency.';
        } elseif ($heavyFeatureCount >= 3) {
            $verdictClass = 'warning';
            $verdictLabel = 'Verification Heavy';
            $verdictSummary = 'Several current settings intentionally trade some throughput for stronger proof and tighter live enforcement. That can be the right choice, but it is not the lightest path.';
        }

        $throughputHelpers[] = [
            'title' => 'Object storage footprint',
            'state' => $summary['object_servers'] > 0 ? 'Active' : 'Not in use',
            'impact' => $summary['object_servers'] > 0
                ? 'Wasabi, R2, B2, and S3-style nodes let Fyuhls approve requests without having to move most of the file itself.'
                : 'Local-only installs can still scale, but they lose some of the easiest "app authorizes, backend delivers" patterns.',
            'action_label' => $viewerCanConfig ? 'Open Storage Settings' : null,
            'action_href' => $viewerCanConfig ? '/admin/configuration?tab=storage' : null,
        ];
        $throughputHelpers[] = [
            'title' => 'Nginx handoff',
            'state' => $summary['nginx_servers'] > 0 ? ($summary['nginx_servers'] . ' node' . ($summary['nginx_servers'] === 1 ? '' : 's')) : 'Not configured',
            'impact' => $summary['nginx_servers'] > 0
                ? 'Nginx can deliver bytes after Fyuhls makes the policy decision, which is much friendlier to concurrency than long-lived PHP transfers.'
                : 'If you need server-side control on local storage, Nginx is a stronger acceleration path than pure PHP delivery.',
            'action_label' => $viewerCanConfig ? 'Open Storage Settings' : null,
            'action_href' => $viewerCanConfig ? '/admin/configuration?tab=storage' : null,
        ];
        $throughputHelpers[] = [
            'title' => 'CDN-aware redirects',
            'state' => $settings['cdn_download_redirects_enabled'] ? 'Enabled' : 'Disabled',
            'impact' => $settings['cdn_download_redirects_enabled']
                ? ($settings['cdn_download_base_url'] !== '' ? 'A CDN-aware path is configured for eligible public object downloads without exposing the configured endpoint here.' : 'Redirect mode is enabled, but it still needs a CDN base URL before it can be trusted as an offload option.')
                : 'Leaving this off is fine, but eligible public object traffic has one less offload option.',
            'action_label' => $viewerCanConfig ? 'Open Download Settings' : null,
            'action_href' => $viewerCanConfig ? '/admin/configuration?tab=downloads' : null,
        ];
        if ($hasProviderDeliveryConstraints) {
            $throughputHelpers[] = [
                'title' => 'Provider-specific delivery constraints',
                'state' => $summary['backblaze_like_object_servers'] . ' node' . ($summary['backblaze_like_object_servers'] === 1 ? '' : 's'),
                'impact' => 'Some active object providers still need Fyuhls to proxy certain downloads, so object storage does not automatically mean every file can take the lightest possible path.',
                'action_label' => $viewerCanConfig ? 'Open Storage Settings' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=storage' : null,
            ];
        }

        if ($canViewPolicyDetails) {
            $verificationFeatures[] = [
                'title' => 'PPD minimum download percent',
                'state' => $settings['ppd_min_download_percent'] . '%',
                'impact' => $settings['ppd_min_download_percent'] > 0
                    ? ($hasNginxPercentOffloadSupport
                        ? 'Rewarded downloads need stronger proof before crediting. Direct storage handoff becomes less available, though Nginx can still support threshold proof through its completion-log path.'
                        : 'Rewarded downloads need stronger proof before crediting, which makes direct storage handoff less available and usually keeps more of the flow closer to Fyuhls.')
                    : 'Standard rewarded downloads are not being forced into percent-based proof.',
                'action_label' => $viewerCanConfig ? 'Open Monetization' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=monetization' : null,
            ];
            $verificationFeatures[] = [
                'title' => 'Verified reward completion',
                'state' => $settings['rewards_verified_completion_required'] ? 'Required' : 'Optional / off',
                'impact' => $settings['rewards_verified_completion_required']
                    ? 'Fyuhls must observe more of the download lifecycle before crediting, which is stricter but heavier.'
                    : 'The install can allow lighter reward flows where your policy permits it.',
                'action_label' => $viewerCanRewardsFraud ? 'Open Rewards Fraud' : ($viewerCanConfig ? 'Open Monetization' : null),
                'action_href' => $viewerCanRewardsFraud ? '/admin/rewards-fraud' : ($viewerCanConfig ? '/admin/configuration?tab=monetization' : null),
            ];
            $verificationFeatures[] = [
                'title' => 'Live concurrent-download tracking',
                'state' => $settings['track_current_downloads'] ? 'Enabled' : 'Disabled',
                'impact' => $settings['track_current_downloads']
                    ? 'Active downloads cause more live state work in the database, which matters at very high concurrency.'
                    : 'The hot path stays lighter because live transfer state is not being tracked globally.',
                'action_label' => $viewerCanConfig ? 'Open Download Settings' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=downloads' : null,
            ];
            $verificationFeatures[] = [
                'title' => 'Package speed and concurrency rules',
                'state' => $packagesWithSpeedLimit . ' speed-limited / ' . $packagesWithConcurrentLimit . ' concurrency-limited',
                'impact' => ($packagesWithSpeedLimit > 0 || $hasEffectiveConcurrentPackagePressure)
                    ? 'These are valid product controls, but they increase the chance that Fyuhls has to stay involved longer during downloads.'
                    : ($packagesWithConcurrentLimit > 0
                        ? 'Some packages define concurrency limits, but global live download tracking is off right now, so those limits are not currently adding hot-path enforcement.'
                        : 'Packages are not currently forcing additional live download enforcement.'),
                'action_label' => $viewerCanPackages ? 'Open Packages' : null,
                'action_href' => $viewerCanPackages ? '/admin/packages' : null,
            ];
        }

        if ($canViewPolicyDetails && $summary['object_servers'] > 0 && $settings['ppd_min_download_percent'] > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'title' => 'Percent-based PPD proof keeps the app in the download path',
                'body' => $hasNginxPercentOffloadSupport
                    ? 'Standard downloads that need threshold-based proof cannot stay fully on the direct storage path. Nginx can still help through its completion-log path, but the lightest redirect options become less available.'
                    : 'Standard downloads that need threshold-based proof cannot stay fully on the direct storage path. This is safer for payout verification, but it costs throughput on busy installs.',
                'action_label' => $viewerCanConfig ? 'Open Monetization' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=monetization' : null,
            ];
        }
        if ($canViewPolicyDetails && $settings['rewards_verified_completion_required']) {
            $recommendations[] = [
                'severity' => 'warning',
                'title' => 'Verified reward completion is stricter but heavier',
                'body' => 'When reward verification is forced, the app has to observe more of the download lifecycle instead of approving lightweight start-based flows.',
                'action_label' => $viewerCanRewardsFraud ? 'Open Rewards Fraud' : null,
                'action_href' => $viewerCanRewardsFraud ? '/admin/rewards-fraud' : null,
            ];
        }
        if ($canViewPolicyDetails && $settings['track_current_downloads']) {
            $recommendations[] = [
                'severity' => 'warning',
                'title' => 'Live concurrent-download tracking adds database work to active downloads',
                'body' => 'Tracking and enforcing concurrent downloads is useful, but it adds state updates during live transfers. Keep it intentional, especially on high-traffic installs.',
                'action_label' => $viewerCanConfig ? 'Open Download Settings' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=downloads' : null,
            ];
        }
        if ($canViewPolicyDetails && $packagesWithSpeedLimit > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'title' => 'Speed-limited packages reduce fast-path delivery options',
                'body' => $packagesWithSpeedLimit . ' package' . ($packagesWithSpeedLimit === 1 ? ' is' : 's are') . ' using download speed limits. Those users are more likely to fall back to app-controlled delivery.',
                'action_label' => $viewerCanPackages ? 'Review Packages' : null,
                'action_href' => $viewerCanPackages ? '/admin/packages' : null,
            ];
        }
        if ($canViewPolicyDetails && $cdnMisconfigured) {
            $recommendations[] = [
                'severity' => 'danger',
                'title' => 'CDN redirects are enabled without a CDN base URL',
                'body' => 'That leaves the install in an incomplete state and removes one of the cleaner high-scale download paths.',
                'action_label' => $viewerCanConfig ? 'Open Download Settings' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=downloads' : null,
            ];
        } elseif ($canViewPolicyDetails && $summary['object_servers'] > 0 && !$settings['cdn_download_redirects_enabled']) {
            $recommendations[] = [
                'severity' => 'info',
                'title' => 'Object storage is active, but CDN redirects are not enabled',
                'body' => 'You can still let storage serve approved downloads directly, but a CDN-aware path can give eligible public object downloads another offload option during busy periods.',
                'action_label' => $viewerCanConfig ? 'Open Download Settings' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=downloads' : null,
            ];
        }
        if ($summary['object_servers'] > 0 && $summary['apache_like_servers'] > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'title' => 'Apache and LiteSpeed handoff are not the cleanest match for remote object storage',
                'body' => 'For Wasabi, R2, B2, and S3-style backends, the strongest scaling pattern is letting storage or the CDN move the file while Fyuhls just approves the request.',
                'action_label' => $viewerCanConfig ? 'Review Storage Servers' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=storage' : null,
            ];
        }
        if ($summary['object_servers'] > 0 && $hasProviderDeliveryConstraints) {
            $recommendations[] = [
                'severity' => 'info',
                'title' => 'Some object providers still force app proxy for certain downloads',
                'body' => 'Object storage helps overall, but some active providers still need Fyuhls to stay in the middle for parts of the download flow.',
                'action_label' => $viewerCanConfig ? 'Review Storage Servers' : null,
                'action_href' => $viewerCanConfig ? '/admin/configuration?tab=storage' : null,
            ];
        }

        if ($summary['object_servers'] > 0 && (!$canViewPolicyDetails || ($settings['ppd_min_download_percent'] === 0 && !$settings['rewards_verified_completion_required'] && !$hasProviderDeliveryConstraints))) {
            $goodPractices[] = [
                'title' => 'Standard object downloads can stay lightweight',
                'body' => $hasProviderDeliveryConstraints
                    ? 'Object storage is doing useful scale work here, even though some active providers still need app proxy in parts of the flow.'
                    : 'This install can let storage or the CDN handle more ordinary downloads because standard-file reward proof is not forcing app-controlled verification.',
            ];
        }
        if ($summary['nginx_servers'] > 0) {
            $goodPractices[] = [
                'title' => 'Nginx handoff is available',
                'body' => 'That gives you a stronger accelerated path than pure PHP delivery, especially when you need some server-side coordination around downloads.',
            ];
        }
        if ($canViewPolicyDetails && !$settings['track_current_downloads']) {
            $goodPractices[] = [
                'title' => 'Live download tracking is off',
                'body' => 'That keeps the hot path lighter for large-scale download concurrency.',
            ];
        }
        if ($summary['php_servers'] === 0 || $summary['object_servers'] > 0) {
            $goodPractices[] = [
                'title' => 'The install is not fully tied to app-controlled delivery',
                'body' => 'That is the right direction for scale. Fyuhls should be making decisions, not pushing most of the file bytes.',
            ];
        }

        if ($canViewPolicyDetails && $cdnMisconfigured) {
            $conflicts[] = [
                'severity' => 'danger',
                'title' => 'CDN redirects are incomplete',
                'body' => 'Redirect mode is enabled, but the install is missing the safe CDN base URL needed to use that path reliably.',
            ];
        }
        if ($canViewPolicyDetails && $summary['object_servers'] > 0 && $settings['ppd_min_download_percent'] > 0) {
            $conflicts[] = [
                'severity' => 'warning',
                'title' => 'Object storage plus percent proof creates a heavier path',
                'body' => $hasNginxPercentOffloadSupport
                    ? 'Remote backends help scale, but percent-based reward proof still removes the lightest direct-storage paths for rewarded downloads. Nginx can soften that, but it does not make the flow fully lightweight.'
                    : 'Remote backends help scale, but percent-based reward proof can pull rewarded downloads back into a heavier app-controlled path.',
            ];
        }
        if ($canViewPolicyDetails && $summary['object_servers'] > 0 && $settings['rewards_verified_completion_required']) {
            $conflicts[] = [
                'severity' => 'warning',
                'title' => 'Verified reward completion limits the lightest direct-storage path',
                'body' => 'This is safer for reward quality, but it reduces how often Fyuhls can step out of the data path early.',
            ];
        }
        if ($summary['object_servers'] > 0 && $summary['apache_like_servers'] > 0) {
            $conflicts[] = [
                'severity' => 'info',
                'title' => 'Apache/LiteSpeed are not the cleanest fit for object-backed scale',
                'body' => 'They work, but they are not as naturally aligned with remote-object delivery as direct storage or CDN-backed flows.',
            ];
        }
        if ($canViewPolicyDetails && $settings['track_current_downloads'] && $packagesWithConcurrentLimit > 0) {
            $conflicts[] = [
                'severity' => 'info',
                'title' => 'Multiple live-enforcement controls are stacking up',
                'body' => 'Global download tracking plus package concurrency rules increase hot-path state work during busy periods.',
            ];
        }

        $currentBehavior[] = [
            'title' => 'Standard object-storage downloads',
            'path' => $summary['object_servers'] > 0
                ? (($hasProviderDeliveryConstraints || ($canViewPolicyDetails && ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'] || $hasPackageDeliveryPressure))) ? 'Mixed: some providers or policies keep the app involved longer' : 'Usually storage or the CDN handles the file after approval')
                : 'Not applicable',
            'summary' => $summary['object_servers'] > 0
                ? (($hasProviderDeliveryConstraints || ($canViewPolicyDetails && ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'] || $hasPackageDeliveryPressure)))
                    ? 'Provider-specific behavior or policy rules can keep some of these closer to Fyuhls instead of letting storage or CDN take over immediately.'
                    : 'Fyuhls can approve the request and then get out of the way much earlier on many standard downloads.')
                : 'This install is not currently using object storage nodes.',
        ];
        if ($canViewPolicyDetails) {
            $currentBehavior[] = [
                'title' => 'Reward-verified downloads',
                'path' => ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'])
                    ? ($settings['ppd_min_download_percent'] > 0 && $hasNginxPercentOffloadSupport && !$settings['rewards_verified_completion_required'] ? 'Mixed: proof-heavy, but Nginx can still assist' : 'App-observed or mixed')
                    : 'Lighter start-based path available',
                'summary' => ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'])
                    ? (($settings['ppd_min_download_percent'] > 0 && $hasNginxPercentOffloadSupport && !$settings['rewards_verified_completion_required'])
                        ? 'The app still needs stronger proof before crediting, but Nginx can keep some of the transfer work off the PHP hot path.'
                        : 'The app needs more proof before crediting, so this path is intentionally heavier.')
                    : 'Reward logic is not forcing every standard download into a closely watched proof path.',
            ];
            $currentBehavior[] = [
                'title' => 'Streaming or watch-validated traffic',
                'path' => $settings['streaming_support_enabled'] ? 'App-coordinated' : 'Not emphasized',
                'summary' => $settings['streaming_support_enabled']
                    ? 'Streaming-style proof is naturally stricter and usually keeps Fyuhls involved longer than a plain redirect.'
                    : 'The install is not currently leaning on stream-specific delivery rules.',
            ];
        }
        $currentBehavior[] = [
            'title' => 'Local accelerated downloads',
            'path' => $summary['nginx_servers'] > 0 ? 'Nginx handoff available' : ($summary['local_servers'] > 0 ? 'More likely to lean on PHP or Apache/LiteSpeed' : 'Not a local-storage-heavy install'),
            'summary' => $summary['nginx_servers'] > 0
                ? 'This is the best local-storage acceleration path currently configured.'
                : ($summary['local_servers'] > 0 ? 'Local nodes are present, but they do not currently have the strongest acceleration profile.' : 'Local acceleration is not the main story on this install.'),
        ];

        $scenarioMatrix = [
            [
                'scenario' => 'A normal file download from object storage',
                'path' => $summary['object_servers'] > 0 ? (($hasProviderDeliveryConstraints || ($canViewPolicyDetails && ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'] || $hasPackageDeliveryPressure))) ? 'Some downloads still stay with Fyuhls longer' : 'Storage or CDN usually handles the file') : 'You are not using object storage here',
                'scale' => $summary['object_servers'] > 0 ? (($hasProviderDeliveryConstraints || ($canViewPolicyDetails && ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'] || $hasPackageDeliveryPressure))) ? 'Can be okay, but heavier' : 'Good for heavy traffic') : 'Not relevant here',
                'why' => $summary['object_servers'] > 0 ? (($hasProviderDeliveryConstraints || ($canViewPolicyDetails && ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'] || $hasPackageDeliveryPressure))) ? 'Provider behavior or policy rules can stop the fastest handoff path, so Fyuhls stays involved longer.' : 'Fyuhls can approve the request and let remote storage move most of the file bytes.') : 'This install does not currently have active Wasabi, R2, B2, or S3-style download nodes in use.',
            ],
            [
                'scenario' => 'A local-disk download with acceleration',
                'path' => $summary['nginx_servers'] > 0 ? 'Nginx can take over after approval' : ($summary['local_servers'] > 0 ? 'Fyuhls or the web server stays more involved' : 'You are not relying on local storage here'),
                'scale' => $summary['nginx_servers'] > 0 ? 'Good for heavy traffic' : ($summary['local_servers'] > 0 ? 'Can work, but not the lightest path' : 'Not relevant here'),
                'why' => $summary['nginx_servers'] > 0 ? 'Nginx can move the file after Fyuhls approves the request, which reduces PHP load.' : ($summary['local_servers'] > 0 ? 'Local delivery exists, but it is not currently on the strongest acceleration profile.' : 'Local delivery is not a meaningful part of this install right now.'),
            ],
        ];
        if ($canViewPolicyDetails) {
            $scenarioMatrix[] = [
                'scenario' => 'A rewarded download that needs stronger proof',
                'path' => ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'])
                    ? ($settings['ppd_min_download_percent'] > 0 && $hasNginxPercentOffloadSupport && !$settings['rewards_verified_completion_required'] ? 'Fyuhls verifies more, and Nginx may help carry the file' : 'Fyuhls needs to stay involved and verify more')
                    : 'A lighter reward path is available',
                'scale' => ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'])
                    ? ($settings['ppd_min_download_percent'] > 0 && $hasNginxPercentOffloadSupport && !$settings['rewards_verified_completion_required'] ? 'Mixed: safer, but not the lightest' : 'Heavier on your app')
                    : 'Good for heavy traffic',
                'why' => ($settings['ppd_min_download_percent'] > 0 || $settings['rewards_verified_completion_required'])
                    ? (($settings['ppd_min_download_percent'] > 0 && $hasNginxPercentOffloadSupport && !$settings['rewards_verified_completion_required'])
                        ? 'Fyuhls still needs stronger proof before it credits earnings, but Nginx can support threshold proof through the completion-log path instead of forcing every byte through PHP.'
                        : 'Fyuhls needs stronger proof before it credits earnings, so it cannot always hand the download off early.')
                    : 'Your current reward settings do not force every standard rewarded download into the heavier proof path.',
            ];
            $scenarioMatrix[] = [
                'scenario' => 'Streaming or watch-based proof',
                'path' => $settings['streaming_support_enabled'] ? 'Fyuhls coordinates more of the session' : 'This is not a major path right now',
                'scale' => $settings['streaming_support_enabled'] ? 'Usually heavier than a normal file download' : 'Not relevant here',
                'why' => $settings['streaming_support_enabled'] ? 'Watch-based proof is more stateful than a simple file redirect, so the app naturally does more work.' : 'Streaming enforcement is not a major part of the current setup.',
            ];
        }

        if ($viewerCanConfig) {
            $quickActions[] = [
                'label' => 'Reduce app-controlled delivery',
                'href' => '/admin/configuration?tab=downloads',
                'copy' => 'Review CDN redirects, delivery behavior, and hot-path download settings.',
            ];
            $quickActions[] = [
                'label' => 'Review reward-proof settings',
                'href' => '/admin/configuration?tab=monetization',
                'copy' => 'Tune the tradeoff between stronger reward proof and lighter delivery.',
            ];
            $quickActions[] = [
                'label' => 'Check storage-server fit',
                'href' => '/admin/configuration?tab=storage',
                'copy' => 'Compare object-backed nodes, local nodes, and delivery methods.',
            ];
        }
        if ($viewerCanPackages) {
            $quickActions[] = [
                'label' => 'Audit package pressure',
                'href' => '/admin/packages',
                'copy' => 'Look for speed limits or concurrency rules that are pushing more work into the hot path.',
            ];
        }

        $whatIWouldDo = [
            'Let storage or the CDN handle ordinary object-storage downloads whenever reward policy allows it.',
            'Reserve app-controlled delivery for streaming, stricter proof flows, or package rules that truly need closer enforcement.',
            'Prefer Nginx for local acceleration when you still need Fyuhls to stay involved in the decision path.',
            'Be careful about stacking percent-based PPD proof, verified completion, and live download tracking on the same busy install.',
            'Treat this page as a posture guide, not a place to expose infrastructure details. Safe summaries are better than raw internals here.',
        ];

        $recommendedProfile = [
            'Let storage or the CDN handle ordinary object-storage downloads whenever possible.',
            'Reserve app-controlled PHP delivery for cases that truly need stricter proof, streaming proof, or package-specific enforcement.',
            'Keep concurrent-download tracking and speed limiting intentional, because they increase live-request overhead.',
            'Use Nginx when you need acceleration and still want more control than a pure redirect path.',
            'Treat CDN redirects as a scaling tool for busy public download traffic, especially on object storage backends.',
        ];

        View::render('admin/scaling.php', [
            'summary' => $summary,
            'settings' => $settings,
            'loadWarnings' => $loadWarnings,
            'canViewPolicyDetails' => $canViewPolicyDetails,
            'packagesWithSpeedLimit' => $packagesWithSpeedLimit,
            'packagesWithConcurrentLimit' => $packagesWithConcurrentLimit,
            'recommendations' => $recommendations,
            'goodPractices' => $goodPractices,
            'throughputHelpers' => $throughputHelpers,
            'verificationFeatures' => $verificationFeatures,
            'conflicts' => $conflicts,
            'currentBehavior' => $currentBehavior,
            'scenarioMatrix' => $scenarioMatrix,
            'quickActions' => $quickActions,
            'whatIWouldDo' => $whatIWouldDo,
            'recommendedProfile' => $recommendedProfile,
            'verdictClass' => $verdictClass,
            'verdictLabel' => $verdictLabel,
            'verdictSummary' => $verdictSummary,
            'servers' => $servers,
        ]);
    }

    /**
     * @return array{
     *   servers: array<int, array<string, mixed>>,
     *   packagesWithSpeedLimit: int,
     *   packagesWithConcurrentLimit: int,
     *   loadWarnings: array<int, string>
     * }
     */
    private function loadScalingGuideSnapshot(\PDO $db): array
    {
        $servers = [];
        $packagesWithSpeedLimit = 0;
        $packagesWithConcurrentLimit = 0;
        $loadWarnings = [];

        try {
            $stmt = $db->query("SELECT id, name, status, server_type, delivery_method, config FROM file_servers ORDER BY id ASC");
            $servers = $stmt->fetchAll() ?: [];
            foreach ($servers as &$server) {
                $server = $this->decryptFileServerRow($server);
            }
            unset($server);
        } catch (\Throwable $e) {
            $servers = [];
            $loadWarnings[] = 'Storage delivery data could not be loaded from the current database state. Review file-server configuration or schema health before trusting this snapshot.';
        }

        try {
            $stmt = $db->query("
                SELECT
                    SUM(CASE WHEN COALESCE(download_speed, 0) > 0 THEN 1 ELSE 0 END) AS speed_limited,
                    SUM(CASE WHEN COALESCE(concurrent_downloads, 0) > 0 THEN 1 ELSE 0 END) AS concurrent_limited
                FROM packages
            ");
            $row = $stmt->fetch() ?: [];
            $packagesWithSpeedLimit = (int)($row['speed_limited'] ?? 0);
            $packagesWithConcurrentLimit = (int)($row['concurrent_limited'] ?? 0);
        } catch (\Throwable $e) {
            $packagesWithSpeedLimit = 0;
            $packagesWithConcurrentLimit = 0;
            $loadWarnings[] = 'Package delivery rules could not be loaded from the current database state. Speed-limit and concurrency guidance on this page may be incomplete.';
        }

        return [
            'servers' => $servers,
            'packagesWithSpeedLimit' => $packagesWithSpeedLimit,
            'packagesWithConcurrentLimit' => $packagesWithConcurrentLimit,
            'loadWarnings' => $loadWarnings,
        ];
    }

    public function withdrawals()
    {
        $this->checkAuth('withdrawals.manage');
        if (!\App\Service\FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT w.*, u.username, u.email as user_email FROM withdrawals w JOIN users u ON w.user_id = u.id ORDER BY w.created_at DESC");
        $withdrawals = $stmt->fetchAll();

        foreach ($withdrawals as &$w) {
            $w['username']   = EncryptionService::decrypt($w['username']);
            $w['user_email'] = EncryptionService::decrypt($w['user_email']);
            $w['details']    = EncryptionService::decrypt($w['details']);
            $w['admin_note'] = EncryptionService::decrypt($w['admin_note'] ?? '');
            $w['method_label'] = \App\Service\PayoutProcessorService::label((string)($w['method'] ?? ''));
            $w['destination_label'] = \App\Service\PayoutProcessorService::destinationLabel((string)($w['method'] ?? ''));
        }

        View::render('admin/withdrawals.php', ['withdrawals' => $withdrawals]);
    }

    public function updateWithdrawal()
    {
        $this->checkAuth('withdrawals.manage');
        if (!\App\Service\FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $id = (int)$_POST['id'];
            $newStatus = trim((string)($_POST['status'] ?? ''));
            $note = trim((string)($_POST['admin_note'] ?? ''));
            $encNote = EncryptionService::encrypt($note);
            $adminId = Auth::id();
            $allowedStatuses = ['pending', 'approved', 'paid', 'rejected'];

            if (!in_array($newStatus, $allowedStatuses, true)) {
                $_SESSION['error'] = "Invalid withdrawal status.";
                header("Location: /admin/withdrawals"); exit;
            }

            $db = Database::getInstance()->getConnection();
            $statusChanged = false;
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("SELECT status, user_id, amount, method FROM withdrawals WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $current = $stmt->fetch();

                if (!$current) {
                    $db->rollBack();
                    die("Withdrawal not found");
                }

                $currentStatus = (string)($current['status'] ?? '');
                if (in_array($currentStatus, ['paid', 'rejected'], true)) {
                    $db->rollBack();
                    $_SESSION['error'] = "This withdrawal is locked and cannot be modified.";
                    header("Location: /admin/withdrawals"); exit;
                }

                ReviewIntegrityService::assertNotSelfWithdrawalReview((int)$adminId, (int)($current['user_id'] ?? 0));

                $allowedTransitions = [
                    'pending' => ['pending', 'approved', 'rejected'],
                    'approved' => ['approved', 'paid', 'rejected'],
                ];
                if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
                    $db->rollBack();
                    $_SESSION['error'] = "Invalid withdrawal transition.";
                    header("Location: /admin/withdrawals"); exit;
                }

                $statusChanged = $newStatus !== $currentStatus;
                if ($statusChanged && $note === '') {
                    $db->rollBack();
                    $_SESSION['error'] = "An admin note is required when changing a withdrawal status.";
                    header("Location: /admin/withdrawals"); exit;
                }
                $this->assertWithdrawalDecisionStillFunded(
                    $db,
                    $id,
                    (int)($current['user_id'] ?? 0),
                    (float)($current['amount'] ?? 0),
                    $newStatus
                );
                if ($statusChanged) {
                    $stmt = $db->prepare("
                        UPDATE withdrawals
                        SET status = ?, admin_note = ?, processed_at = NOW(), processed_by_admin_id = ?
                        WHERE id = ? AND status = ?
                    ");
                    $stmt->execute([$newStatus, $encNote, $adminId, $id, $currentStatus]);
                    if ($stmt->rowCount() !== 1) {
                        throw new \RuntimeException('Withdrawal status changed during review.');
                    }
                } else {
                    if ($note === '') {
                        $db->rollBack();
                        $_SESSION['error'] = "A review note is required when updating withdrawal notes.";
                        header("Location: /admin/withdrawals"); exit;
                    }

                    $noteStmt = $db->prepare("SELECT admin_note FROM withdrawals WHERE id = ? LIMIT 1 FOR UPDATE");
                    $noteStmt->execute([$id]);
                    $existingEncryptedNote = (string)($noteStmt->fetchColumn() ?: '');
                    $existingNote = $existingEncryptedNote !== '' ? EncryptionService::decrypt($existingEncryptedNote) : '';
                    if (trim((string)$existingNote) === $note) {
                        $db->rollBack();
                        $_SESSION['error'] = "That withdrawal note is already saved.";
                        header("Location: /admin/withdrawals"); exit;
                    }

                    $stmt = $db->prepare("
                        UPDATE withdrawals
                        SET admin_note = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$encNote, $id]);
                }

                if ($statusChanged) {
                    StaffActivityService::logWithConnection(
                        $db,
                        'withdrawal_updated',
                        'withdrawal',
                        $id,
                        'Updated withdrawal #' . $id . ' from ' . $currentStatus . ' to ' . $newStatus . '.',
                        [
                            'amount' => number_format((float)$current['amount'], 2, '.', ''),
                            'method' => strtoupper((string)($current['method'] ?? '')),
                            'target_user_id' => (int)$current['user_id'],
                            'reason_note' => $note,
                            'before' => [
                                'status' => $currentStatus,
                            ],
                            'after' => [
                                'status' => $newStatus,
                            ],
                        ],
                        (int)$current['user_id']
                    );
                } elseif ($note !== '') {
                    StaffActivityService::logWithConnection(
                        $db,
                        'withdrawal_note_updated',
                        'withdrawal',
                        $id,
                        'Updated the review note for withdrawal #' . $id . '.',
                        [
                            'amount' => number_format((float)$current['amount'], 2, '.', ''),
                            'method' => strtoupper((string)($current['method'] ?? '')),
                            'target_user_id' => (int)$current['user_id'],
                            'reason_note' => $note,
                            'before' => [
                                'status' => $currentStatus,
                            ],
                            'after' => [
                                'status' => $newStatus,
                            ],
                        ],
                        (int)$current['user_id']
                    );
                }

                $db->commit();
            } catch (\RuntimeException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = $e->getMessage();
                header("Location: /admin/withdrawals"); exit;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = "Unable to update the withdrawal right now. Please try again.";
                header("Location: /admin/withdrawals"); exit;
            }

            if ($statusChanged) {
                if (($current['status'] ?? '') === 'pending' && $newStatus !== 'pending') {
                    \App\Service\SystemStatsService::decrement('pending_withdrawals');
                } elseif (($current['status'] ?? '') !== 'pending' && $newStatus === 'pending') {
                    \App\Service\SystemStatsService::increment('pending_withdrawals');
                }
            }

            if ($statusChanged) {
                $userStmt = $db->prepare("SELECT username, email FROM users WHERE id = ?");
                $userStmt->execute([$current['user_id']]);
                $user = $userStmt->fetch();
                if ($user) {
                    $email = EncryptionService::decrypt((string)$user['email']);
                    $username = EncryptionService::decrypt((string)$user['username']);
                    $templateMap = [
                        'approved' => 'withdrawal_status_approved',
                        'paid' => 'withdrawal_status_paid',
                        'rejected' => 'withdrawal_status_rejected',
                    ];
                    if (isset($templateMap[$newStatus]) && $email !== '') {
                        MailService::sendTemplate($email, $templateMap[$newStatus], [
                            '{username}' => $username,
                            '{amount}' => '$' . number_format((float)$current['amount'], 2),
                            '{method}' => strtoupper((string)($current['method'] ?? 'PAYOUT')),
                            '{admin_note}' => $note !== '' ? $note : 'No additional note provided.',
                        ], 'low');
                    }
                }

                \App\Service\NotificationService::send(
                    $current['user_id'],
                    "Withdrawal Updated",
                    "Your withdrawal request for $" . number_format($current['amount'], 2) . " has been " . strtoupper($newStatus),
                    ($newStatus === 'paid' ? 'success' : 'info')
                );

            }

            \App\Service\BonusOfferService::touchUserFailSoft((int)$current['user_id'], false, [
                'workflow' => 'admin_withdrawal_review',
                'withdrawal_id' => $id,
                'status' => $newStatus,
            ]);
        }
        header("Location: /admin/withdrawals"); exit;
    }

    private function assertWithdrawalDecisionStillFunded(\PDO $db, int $withdrawalId, int $userId, float $amount, string $newStatus): void
    {
        if ($userId <= 0 || $withdrawalId <= 0 || $amount <= 0 || !in_array($newStatus, ['approved', 'paid'], true)) {
            return;
        }

        $userLock = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        $userLock->execute([$userId]);
        if (!$userLock->fetchColumn()) {
            throw new \RuntimeException('The withdrawal owner could not be loaded for balance verification.');
        }

        $clearedStmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM earnings
            WHERE user_id = ?
              AND status = 'cleared'
            FOR UPDATE
        ");
        $clearedStmt->execute([$userId]);
        $clearedBalance = (float)$clearedStmt->fetchColumn();

        $reservedStmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM withdrawals
            WHERE user_id = ?
              AND status IN ('pending', 'approved', 'paid')
              AND id <> ?
            FOR UPDATE
        ");
        $reservedStmt->execute([$userId, $withdrawalId]);
        $otherReserved = (float)$reservedStmt->fetchColumn();

        $available = $clearedBalance - $otherReserved;
        if ($amount > $available + 0.00001) {
            throw new \RuntimeException('This withdrawal no longer has enough cleared balance to approve or pay. Recalculate the balance and review the account before proceeding.');
        }
    }

    public function rewardsFraud()
    {
        $this->checkAuth('rewards_fraud.manage');
        if (!\App\Service\FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }

        $fraud = new \App\Service\RewardFraudService();
        $queueFilters = [
            'query' => trim((string)($_GET['q'] ?? '')),
            'uploader_name' => trim((string)($_GET['uploader_name'] ?? '')),
            'file_name' => trim((string)($_GET['file_name'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
            'risk_band' => trim((string)($_GET['risk_band'] ?? '')),
            'country_code' => trim((string)($_GET['country'] ?? '')),
            'network_type' => trim((string)($_GET['network'] ?? '')),
            'uploader_id' => (int)($_GET['uploader_id'] ?? 0),
            'file_id' => (int)($_GET['file_id'] ?? 0),
            'sort' => trim((string)($_GET['sort'] ?? 'risk_desc')),
        ];
        $queuePage = max(1, (int)($_GET['page'] ?? 1));
        $queuePerPage = max(10, min(100, (int)($_GET['per_page'] ?? 50)));

        $reviewQueuePage = $fraud->getReviewQueuePage($queueFilters, $queuePage, $queuePerPage);
          View::render('admin/rewards_fraud.php', [
              'overview' => $fraud->getOverview(),
              'reviewQueuePage' => $reviewQueuePage,
              'reviewCaseContext' => $fraud->buildReviewCaseContext($reviewQueuePage['items'] ?? []),
              'reviewClusters' => $fraud->getReviewClusters($queueFilters, 6),
              'recentRewardActivity' => $fraud->getRecentRewardActivity(14, 20),
              'recentRewardWindowDays' => 14,
              'reviewFilterOptions' => $fraud->getReviewFilterOptions(),
              'trustTierOptions' => $fraud->getTrustTierOptions(),
              'queueFilters' => $queueFilters,
              'uploaderScores' => $fraud->getUploaderScores(50),
              'networkInsights' => $fraud->getNetworkInsights(25),
              'cloudflareHealth' => $fraud->getCloudflareHealth(),
              'canManageFraudSettings' => Auth::hasCapability('configuration.manage'),
              'settings' => [
                'rewards_fraud_enabled' => Setting::get('rewards_fraud_enabled', '1'),
                  'rewards_verified_completion_required' => Setting::get('rewards_verified_completion_required', '1'),
                  'rewards_auto_clear_low_risk' => Setting::get('rewards_auto_clear_low_risk', '0'),
                  'rewards_auto_reverse_high_risk' => Setting::get('rewards_auto_reverse_high_risk', '1'),
                  'rewards_hold_days' => Setting::get('rewards_hold_days', '7'),
                  'rewards_review_threshold' => Setting::get('rewards_review_threshold', '25'),
                  'rewards_flag_threshold' => Setting::get('rewards_flag_threshold', '50'),
                  'rewards_auto_reverse_threshold' => Setting::get('rewards_auto_reverse_threshold', '85'),
                  'rewards_fraud_event_retention_days' => Setting::get('rewards_fraud_event_retention_days', '30'),
                  'rewards_fraud_trim_mb' => Setting::get('rewards_fraud_trim_mb', '1024'),
                  'rewards_use_cloudflare_intel' => Setting::get('rewards_use_cloudflare_intel', '1'),
                'rewards_use_proxy_intel' => Setting::get('rewards_use_proxy_intel', '0'),
                'rewards_use_ip_hash' => Setting::get('rewards_use_ip_hash', '1'),
                'rewards_use_ua_hash' => Setting::get('rewards_use_ua_hash', '1'),
                'rewards_use_cookie_hash' => Setting::get('rewards_use_cookie_hash', '1'),
                'rewards_use_accept_language_hash' => Setting::get('rewards_use_accept_language_hash', '1'),
                'rewards_use_timezone_offset' => Setting::get('rewards_use_timezone_offset', '1'),
                'rewards_use_platform_screen' => Setting::get('rewards_use_platform_screen', '1'),
                'rewards_use_asn_network' => Setting::get('rewards_use_asn_network', '1'),
                  'rewards_ppd_guests_only' => (
                      Setting::get('rewards_ppd_guests_only', '0') === '1'
                      || Setting::get('ppd_only_guests_count', '0') === '1'
                  ) ? '1' : '0',
                'rewards_require_downloader_verification' => Setting::get('rewards_require_downloader_verification', '0'),
                'rewards_min_downloader_account_age_days' => Setting::get('rewards_min_downloader_account_age_days', '0'),
                'rewards_block_linked_downloader_accounts' => Setting::get('rewards_block_linked_downloader_accounts', '0'),
                'rewards_hold_new_account_downloads' => Setting::get('rewards_hold_new_account_downloads', '0'),
            ],
        ]);
    }

    public function saveRewardsFraud()
    {
        $this->checkAuth('configuration.manage');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $db = Database::getInstance()->getConnection();
        if (!$db) {
            $_SESSION['error'] = 'Rewards fraud settings could not be saved because the database connection is unavailable.';
            header('Location: /admin/rewards-fraud');
            return;
        }

          $boolKeys = [
              'rewards_fraud_enabled',
              'rewards_verified_completion_required',
              'rewards_auto_clear_low_risk',
              'rewards_auto_reverse_high_risk',
              'rewards_use_cloudflare_intel',
              'rewards_use_proxy_intel',
              'rewards_use_ip_hash',
            'rewards_use_ua_hash',
            'rewards_use_cookie_hash',
            'rewards_use_accept_language_hash',
            'rewards_use_timezone_offset',
            'rewards_use_platform_screen',
            'rewards_use_asn_network',
            'rewards_ppd_guests_only',
            'rewards_require_downloader_verification',
            'rewards_block_linked_downloader_accounts',
            'rewards_hold_new_account_downloads',
        ];

        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            foreach ($boolKeys as $key) {
                $value = isset($_POST[$key]) ? '1' : '0';
                Setting::set($key, $value, 'rewards_fraud');
                if ($key === 'rewards_ppd_guests_only') {
                    Setting::set('ppd_only_guests_count', $value, 'rewards');
                }
            }

              Setting::set('rewards_hold_days', (string)max(0, (int)($_POST['rewards_hold_days'] ?? 7)), 'rewards_fraud');
              Setting::set('rewards_review_threshold', (string)max(0, (int)($_POST['rewards_review_threshold'] ?? 25)), 'rewards_fraud');
              Setting::set('rewards_flag_threshold', (string)max(1, (int)($_POST['rewards_flag_threshold'] ?? 50)), 'rewards_fraud');
              Setting::set('rewards_auto_reverse_threshold', (string)max(1, (int)($_POST['rewards_auto_reverse_threshold'] ?? 85)), 'rewards_fraud');
              Setting::set('rewards_fraud_event_retention_days', (string)max(7, (int)($_POST['rewards_fraud_event_retention_days'] ?? 30)), 'rewards_fraud');
              Setting::set('rewards_fraud_trim_mb', (string)max(64, (int)($_POST['rewards_fraud_trim_mb'] ?? 1024)), 'rewards_fraud');
              Setting::set('rewards_min_downloader_account_age_days', (string)max(0, (int)($_POST['rewards_min_downloader_account_age_days'] ?? 0)), 'rewards_fraud');

            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Rewards fraud settings could not be saved. Review the form values and try again.';
            header('Location: /admin/rewards-fraud');
            return;
        }

        $_SESSION['success'] = 'Rewards fraud settings updated.';
        header('Location: /admin/rewards-fraud');
        return;
    }

    public function reviewRewardsFraud()
    {
        $this->checkAuth('rewards_fraud.manage');
        if (!\App\Service\FeatureService::rewardsEnabled()) {
            http_response_code(404);
            exit('Not found');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

          $earningId = (int)($_POST['earning_id'] ?? 0);
          $earningIds = $this->normalizeRewardReviewIds($_POST['earning_ids'] ?? []);
          $clusterType = trim((string)($_POST['cluster_type'] ?? ''));
          $clusterKey = trim((string)($_POST['cluster_key'] ?? ''));
          $action = trim((string)($_POST['review_action'] ?? ''));
          $note = trim((string)($_POST['review_note'] ?? ''));
          $fraud = new \App\Service\RewardFraudService();
        $returnTo = trim((string)($_POST['return_to'] ?? '/admin/rewards-fraud'));
        if (!str_starts_with($returnTo, '/admin/rewards-fraud')) {
            $returnTo = '/admin/rewards-fraud';
        }

          $validationError = $this->rewardReviewValidationError($earningId, $earningIds, $clusterType, $clusterKey, $action);
          if ($validationError !== null) {
              $_SESSION['error'] = $validationError;
              header('Location: ' . $returnTo);
              exit;
          }

          $processed = 0;
          try {
              if ($clusterType !== '' && $clusterKey !== '') {
                  $result = $fraud->reviewCluster($clusterType, $clusterKey, $action, (int)(Auth::id() ?? 0), $note);
                  $processed = (int)($result['processed'] ?? 0);
                  $matched = (int)($result['matched'] ?? 0);
                  if ($processed > 0) {
                      $_SESSION['success'] = $matched > $processed
                          ? "{$processed} reward reviews updated from the selected cluster slice."
                          : ($processed === 1 ? '1 reward review updated from the selected cluster.' : "{$processed} reward reviews updated from the selected cluster.");
                  } else {
                      $_SESSION['error'] = 'Could not update that review cluster.';
                  }
              } elseif (!empty($earningIds)) {
                  $processed = $fraud->reviewEarningsBulk($earningIds, $action, (int)(Auth::id() ?? 0), $note);
                  if ($processed > 0) {
                      $_SESSION['success'] = $processed === 1 ? '1 reward review updated.' : "{$processed} reward reviews updated.";
                } else {
                    $_SESSION['error'] = 'Could not update the selected earning reviews.';
                }
            } elseif ($fraud->reviewEarning($earningId, $action, (int)(Auth::id() ?? 0), $note)) {
                $_SESSION['success'] = 'Rewards fraud review updated.';
            } else {
                $_SESSION['error'] = 'Could not update that earning review.';
            }
          } catch (\RuntimeException $e) {
              $_SESSION['error'] = $e->getMessage();
          }

          header('Location: ' . $returnTo);
          exit;
      }

      private function normalizeRewardReviewIds(array $earningIds): array
      {
          return array_values(array_filter(
              array_map('intval', $earningIds),
              static fn(int $id): bool => $id > 0
          ));
      }

      private function rewardReviewValidationError(int $earningId, array $earningIds, string $clusterType, string $clusterKey, string $action): ?string
      {
          if (!in_array($action, ['clear', 'hold', 'reverse', 'recommended'], true)) {
              return 'Invalid review action.';
          }

          if ($earningId <= 0 && empty($earningIds) && ($clusterType === '' || $clusterKey === '')) {
              return 'Select at least one reward review to update.';
          }

          return null;
      }

      public function saveRewardsFraudTrust()
      {
          $this->checkAuth('rewards_fraud.manage');
          if (!\App\Service\FeatureService::rewardsEnabled()) {
              http_response_code(404);
              exit('Not found');
          }
          if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
              die("CSRF mismatch");
          }

          $userId = (int)($_POST['user_id'] ?? 0);
          $tier = trim((string)($_POST['trust_tier'] ?? 'normal'));
          $note = trim((string)($_POST['trust_note'] ?? ''));
          $returnTo = trim((string)($_POST['return_to'] ?? '/admin/rewards-fraud'));
          if (!str_starts_with($returnTo, '/admin/rewards-fraud')) {
              $returnTo = '/admin/rewards-fraud';
          }

          $fraud = new \App\Service\RewardFraudService();
          try {
              if ($fraud->saveUploaderTrustTier($userId, $tier, (int)(Auth::id() ?? 0), $note)) {
                  $_SESSION['success'] = 'Uploader trust tier updated.';
              } else {
                  $_SESSION['error'] = 'Could not update that uploader trust tier.';
              }
          } catch (\RuntimeException $e) {
              $_SESSION['error'] = $e->getMessage();
          }

          header('Location: ' . $returnTo);
          exit;
      }

    public function abuseReports()
    {
        $this->renderRequestsQueue('abuse_report');
    }

    public function handleAbuseReport()
    {
        $this->checkAuth('abuse.manage', true);
        $redirectTo = $this->sanitizeInternalRedirect($_POST['return_to'] ?? null, '/admin/requests');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $id = (int)($_POST['report_id'] ?? $_POST['id'] ?? 0);
            $action = $_POST['action'] ?? ''; // delete_file, dismiss, ignore
            $requestPublicId = (string)($_POST['request_public_id'] ?? '');

            try {
                $ticketTarget = $this->resolveTicketBackedRequest($requestPublicId, $id, 'abuse_report');

                if ($ticketTarget !== null) {
                    if ($action === 'delete_file') {
                        $this->assertCanModerateFilesForSpecializedRequest('Abuse');
                        $fileId = (int)($ticketTarget['related_file_id'] ?? 0);
                        if ($fileId <= 0) {
                            throw new \RuntimeException('No file is linked to this abuse report.');
                        }
                        $audit = [
                            'deleted_by_user_id' => Auth::id() ? (int)Auth::id() : null,
                            'deleted_by_role' => 'admin',
                            'deleted_by_label' => 'Administrator',
                            'delete_reason' => 'Removed due to abuse report.',
                        ];
                        $bonusTouchUserIds = $this->runDatabaseTransaction(function () use ($fileId, $audit, $id): array {
                            \App\Model\File::validateHardDeleteBatch([$fileId], $audit);
                            $reversalResult = \App\Model\File::markPendingPurge($fileId, $audit);
                            \App\Service\TicketService::addAdminNote($id, (int)(Auth::id() ?? 0), 'File deleted by staff as part of abuse review.');
                            \App\Service\TicketService::updateStatusByAdmin($id, (int)(Auth::id() ?? 0), 'closed');
                            return (array)($reversalResult['user_ids'] ?? []);
                        });
                        $this->touchUsersAfterModerationCommit($bonusTouchUserIds, 'soft_delete_file', [
                            'request_type' => 'abuse_report',
                            'request_id' => $id,
                            'file_id' => $fileId,
                        ]);
                        $_SESSION['success'] = "File queued for background deletion and report marked as closed.";
                    } elseif (in_array($action, ['dismiss', 'ignore'], true)) {
                        \App\Service\TicketService::addAdminNote($id, (int)(Auth::id() ?? 0), 'Abuse report dismissed by staff.');
                        \App\Service\TicketService::updateStatusByAdmin($id, (int)(Auth::id() ?? 0), 'closed');
                        $_SESSION['success'] = "Report dismissed.";
                    }
                } else {
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("SELECT r.*, f.user_id, f.filename FROM abuse_reports r JOIN files f ON r.file_id = f.id WHERE r.id = ?");
                    $stmt->execute([$id]);
                    $report = $stmt->fetch();

                    if (!$report) die("Report not found");

                    if ($action === 'delete_file') {
                        $this->assertCanModerateFilesForSpecializedRequest('Abuse');
                        $fileId = (int)$report['file_id'];
                        $audit = [
                            'deleted_by_user_id' => Auth::id() ? (int)Auth::id() : null,
                            'deleted_by_role' => 'admin',
                            'deleted_by_label' => 'Administrator',
                            'delete_reason' => 'Removed due to abuse report.',
                        ];
                        $bonusTouchUserIds = $this->runDatabaseTransaction(function () use ($fileId, $audit, $id): array {
                            \App\Model\File::validateHardDeleteBatch([$fileId], $audit);
                            $reversalResult = \App\Model\File::markPendingPurge($fileId, $audit);
                            $this->updateInboxStatus('abuse_report', $id, 'action_taken');
                            $this->addRequestActivity('abuse_report', $id, 'status', 'Status changed', 'Marked as action taken after file deletion.', [
                                'status' => 'action_taken',
                            ]);
                            return (array)($reversalResult['user_ids'] ?? []);
                        });
                        $this->touchUsersAfterModerationCommit($bonusTouchUserIds, 'soft_delete_file', [
                            'request_type' => 'abuse_report',
                            'request_id' => $id,
                            'file_id' => $fileId,
                        ]);
                        $_SESSION['success'] = "File queued for background deletion and report marked as action taken.";
                    } elseif (in_array($action, ['dismiss', 'ignore'], true)) {
                        $this->updateInboxStatus('abuse_report', $id, 'dismissed');
                        $this->addRequestActivity('abuse_report', $id, 'status', 'Status changed', 'Abuse report dismissed.', [
                            'status' => 'dismissed',
                        ]);
                        $_SESSION['success'] = "Report dismissed.";
                    }
                }
            } catch (\Throwable $e) {
                Logger::error('Admin abuse report action failed', [
                    'request_id' => $id,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                $_SESSION['error'] = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Could not process that abuse report action. Please try again.';
            }
        }
        header("Location: " . $redirectTo); exit;
    }

    public function contacts()
    {
        $this->renderRequestsQueue('site_request');
    }

    public function requests()
    {
        $this->renderRequestsQueue();
    }

    private function renderRequestsQueue(?string $lockedType = null): void
    {
        $this->requireRequestQueueAccess($lockedType);
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $db = Database::getInstance()->getConnection();
        $requestBasePath = $this->resolveRequestQueueBasePath($lockedType);
        $filterType = $lockedType !== null ? $lockedType : (string)($_GET['type'] ?? 'all');
        $filterStatus = trim((string)($_GET['status'] ?? ''));
        $filterPriority = trim((string)($_GET['priority'] ?? ''));
        $filterStale = trim((string)($_GET['stale'] ?? ''));
        $searchQuery = trim((string)($_GET['q'] ?? ''));
        $page = $this->normalizeRequestQueuePage();
        $perPage = self::REQUEST_QUEUE_PAGE_SIZE;
        $availableTypeLinks = $this->requestQueueTypeLinksForCurrentStaff();
        if ($lockedType === null && !isset($availableTypeLinks[$filterType])) {
            $filterType = 'all';
        }
        $showArchived = $lockedType === null && $filterType === 'archived';
        $staleThreshold = $this->requestQueueStaleThreshold($filterStale);
        $sourceLimit = $this->requestQueueSourceLimit($page, $perPage, $searchQuery !== '');
        $candidateTypes = $this->requestQueueCandidateTypes($lockedType, $filterType, $availableTypeLinks);

        $items = \App\Service\TicketService::getAdminSupportItems(
            $candidateTypes,
            $sourceLimit,
            false,
            $showArchived,
            $filterStatus,
            $filterPriority,
            $staleThreshold,
            $searchQuery !== ''
        );
        foreach ($candidateTypes as $candidateType) {
            if (in_array($candidateType, ['site_request', 'abuse_report', 'dmca_report'], true)) {
                $items = array_merge($items, $this->fetchLegacyRequestQueueItems(
                    $db,
                    $candidateType,
                    $showArchived,
                    $filterStatus,
                    $filterPriority,
                    $staleThreshold,
                    $sourceLimit
                ));
            }
        }

        $items = $this->filterAccessibleRequestItems($items);

        if (!$showArchived && $filterType !== 'all') {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['type_key'] === $filterType));
        }

        $items = array_values(array_filter($items, function (array $item) use ($showArchived): bool {
            $status = (string)($item['status'] ?? '');
            $type = (string)($item['type_key'] ?? '');
            $archived = $this->isArchivedRequestStatus($type, $status);
            return $showArchived ? $archived : !$archived;
        }));

        if ($filterStatus !== '') {
            $items = array_values(array_filter($items, static fn (array $item): bool => strcasecmp((string)$item['status'], $filterStatus) === 0));
        }

        $items = $this->decorateRequestQueueItems($items);

        $allStatusOptions = [];
        foreach ($items as $item) {
            $status = (string)($item['status'] ?? '');
            if ($status !== '') {
                $allStatusOptions[$status] = true;
            }
        }
        $allStatusOptions = array_keys($allStatusOptions);
        sort($allStatusOptions);

        if ($searchQuery !== '') {
            $searchNeedle = mb_strtolower($searchQuery);
            $items = array_values(array_filter($items, static function (array $item) use ($searchNeedle): bool {
                $haystacks = [
                    (string)($item['request_type'] ?? ''),
                    (string)($item['submitter_name'] ?? ''),
                    (string)($item['submitter_email'] ?? ''),
                    (string)($item['target'] ?? ''),
                    (string)($item['summary'] ?? ''),
                    (string)($item['details'] ?? ''),
                    (string)($item['public_id'] ?? ''),
                ];

                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && mb_stripos($haystack, $searchNeedle) !== false) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($filterPriority !== '' && in_array($filterPriority, ['normal', 'high'], true)) {
            $items = array_values(array_filter($items, static fn (array $item): bool => (string)($item['priority'] ?? 'normal') === $filterPriority));
        }

        if ($filterStale !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($filterStale): bool {
                $days = (int)($item['stale_days'] ?? 0);
                return match ($filterStale) {
                    '3d' => $days >= 3,
                    '7d' => $days >= 7,
                    '14d' => $days >= 14,
                    default => true,
                };
            }));
        }

        usort($items, static function (array $a, array $b): int {
            $aSort = (string)($a['sort_at'] ?? $a['created_at'] ?? '');
            $bSort = (string)($b['sort_at'] ?? $b['created_at'] ?? '');
            return strcmp($bSort, $aSort);
        });

        $summaryItems = array_values(array_filter($items, function (array $item): bool {
            return !$this->isArchivedRequestStatus((string)($item['type_key'] ?? ''), (string)($item['status'] ?? ''));
        }));

        $summary = [
            'open_total' => count($summaryItems),
            'needs_staff_action' => 0,
            'waiting_on_user' => 0,
            'high_priority' => 0,
            'stale_over_3d' => 0,
            'stale_over_7d' => 0,
        ];
        $typeCounts = [
            'support_ticket' => 0,
            'site_request' => 0,
            'abuse_report' => 0,
            'dmca_report' => 0,
        ];

        foreach ($summaryItems as $item) {
            $typeKey = (string)($item['type_key'] ?? '');
            if (isset($typeCounts[$typeKey])) {
                $typeCounts[$typeKey]++;
            }
            if (!empty($item['needs_staff_action'])) {
                $summary['needs_staff_action']++;
            }
            if ((string)($item['status'] ?? '') === 'waiting_user') {
                $summary['waiting_on_user']++;
            }
            if ((string)($item['priority'] ?? 'normal') === 'high') {
                $summary['high_priority']++;
            }
            $staleDays = (int)($item['stale_days'] ?? 0);
            if ($staleDays >= 3) {
                $summary['stale_over_3d']++;
            }
            if ($staleDays >= 7) {
                $summary['stale_over_7d']++;
            }
        }

        $totalItems = count($items);
        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $items = array_slice($items, $offset, $perPage);
        $items = $this->hydrateRequestQueuePageItems($items);

        $activityMap = $this->fetchRequestActivityMap($items);
        foreach ($items as &$item) {
            $key = (string)($item['activity_type'] ?? $item['type_key']) . ':' . $item['id'];
            $activities = $activityMap[$key] ?? [];
            $item['activities'] = $activities;
            $item['latest_reply'] = $item['latest_reply'] ?? null;
            foreach ($activities as $activity) {
                if ($item['latest_reply'] === null && $activity['activity_type'] === 'reply') {
                    $item['latest_reply'] = $activity;
                }
            }
        }
        unset($item);

        if ($demoAdmin) {
            $items = $this->maskDemoRequestQueueItems($items);
        }

        $pageTitle = 'Tickets';
        $pageIntro = 'One queue for support tickets, contact submissions, abuse reports, and DMCA notices.';
        if ($lockedType === 'abuse_report') {
            $pageTitle = 'Abuse Reports';
            $pageIntro = 'Review abuse reports in their own moderation queue without the rest of the support inbox mixed in.';
        } elseif ($lockedType === 'dmca_report') {
            $pageTitle = 'DMCA Reports';
            $pageIntro = 'Work DMCA notices in a dedicated queue so copyright review stays separate from the general request inbox.';
        } elseif ($lockedType === 'site_request') {
            $pageTitle = 'Contact Requests';
            $pageIntro = 'Review contact submissions in their own request queue without loading unrelated moderation queues.';
        }

        $viewerId = (int)(Auth::id() ?? 0);
        $viewerIsSuperAdmin = Auth::isSuperAdmin();
        foreach ($items as &$item) {
            $hiddenFromOthers = !empty($item['hidden_from_others']);
            $hiddenByViewer = $viewerId > 0 && !empty($item['hidden_by_admin_user_id']) && (int)$item['hidden_by_admin_user_id'] === $viewerId;
            $item['can_reassign_hidden_ticket'] = !$hiddenFromOthers || $viewerIsSuperAdmin || $hiddenByViewer;
        }
        unset($item);

        $visibleTicketTypes = [];
        foreach ($items as $item) {
            if (($item['backend'] ?? 'legacy') === 'ticket' && !empty($item['type_key'])) {
                $visibleTicketTypes[(string)$item['type_key']] = true;
            }
        }
        $assignableStaffByType = $this->activeAssignableStaffForRequestTypes(array_keys($visibleTicketTypes));

        View::render('admin/requests.php', [
            'items' => $items,
            'filterType' => $filterType,
            'filterStatus' => $filterStatus,
            'filterPriority' => $filterPriority,
            'filterStale' => $filterStale,
            'searchQuery' => $searchQuery,
            'showArchived' => $showArchived,
            'demoAdmin' => $demoAdmin,
            'summary' => $summary,
            'typeCounts' => $typeCounts,
            'allStatusOptions' => $allStatusOptions,
            'requestBasePath' => $requestBasePath,
            'returnTo' => $_SERVER['REQUEST_URI'] ?? $requestBasePath,
            'requestsLockedType' => $lockedType,
            'availableTypeLinks' => $availableTypeLinks,
            'requestsPageTitle' => $pageTitle,
            'requestsPageIntro' => $pageIntro,
            'requestPagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalItems,
                'total_pages' => $totalPages,
                'source_window' => $sourceLimit,
            ],
            'assignableStaffByType' => $assignableStaffByType,
            'canHideTicketVisibility' => Auth::isAdmin(),
        ]);
    }

    public function replyToRequest()
    {
        $type = (string)($_POST['request_type'] ?? '');
        $this->requireRequestQueueAccess($type);
        $redirectTo = $this->sanitizeInternalRedirect($_POST['return_to'] ?? null, '/admin/requests');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $redirectTo);
            exit;
        }

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $id = (int)($_POST['request_id'] ?? 0);
        $subject = trim((string)($_POST['reply_subject'] ?? ''));
        $message = trim((string)($_POST['reply_message'] ?? ''));
        $statusAfterReply = trim((string)($_POST['status_after_reply'] ?? ''));
        $requestPublicId = (string)($_POST['request_public_id'] ?? '');

        if ($id <= 0 || $message === '' || (($requestPublicId === '' || $type !== 'support_ticket') && $subject === '')) {
            $_SESSION['error'] = ($requestPublicId !== '' && $type === 'support_ticket') ? 'A reply message is required.' : 'Reply subject and message are required.';
            header('Location: ' . $redirectTo);
            exit;
        }

        $db = Database::getInstance()->getConnection();

        try {
            $ticketTarget = $this->resolveTicketBackedRequest($requestPublicId, $id, $type);
            if ($type === 'support_ticket' && $ticketTarget === null) {
                throw new \RuntimeException('Support ticket target mismatch.');
            }
            if ($ticketTarget !== null) {
                \App\Service\TicketService::addAdminReply($id, (int)(Auth::id() ?? 0), $message, $statusAfterReply, $type === 'support_ticket' ? null : $subject);
                $_SESSION['success'] = $type === 'support_ticket'
                    ? 'Reply sent to the support ticket successfully.'
                    : 'Reply sent to the ticket successfully.';
            } elseif ($type === 'site_request') {
                $statusToPersist = $statusAfterReply !== '' ? $statusAfterReply : 'replied';
                $email = $this->runDatabaseTransaction(function () use ($db, $type, $id, $statusToPersist, $subject, $message): string {
                    $this->assertRequestExists($type, $id);
                    $stmt = $db->prepare("SELECT email FROM contact_messages WHERE id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    $row = $stmt->fetch();
                    $email = EncryptionService::decrypt((string)($row['email'] ?? ''));

                    $this->updateInboxStatus($type, $id, $statusToPersist);
                    $this->addRequestActivity($type, $id, 'reply', $subject, $message, [
                        'recipient' => $email,
                        'status' => $statusToPersist,
                    ]);

                    return $email;
                });

                try {
                    $this->sendLegacyRequestReply($type, $email, $subject, $message);
                    $_SESSION['success'] = 'Reply sent to the contact ticket successfully.';
                } catch (\Throwable $mailError) {
                    Logger::warning('Legacy contact reply email failed after queue state committed', [
                        'request_type' => $type,
                        'request_id' => $id,
                        'error' => $mailError->getMessage(),
                    ]);
                    $_SESSION['success'] = 'Reply saved to the contact ticket.';
                    $_SESSION['warning'] = 'The reply was saved, but the email could not be delivered. Check mail settings and send a follow-up reply if needed.';
                }
            } elseif ($type === 'dmca_report') {
                $statusToPersist = $statusAfterReply !== '' ? $statusAfterReply : 'investigating';
                $email = $this->runDatabaseTransaction(function () use ($db, $type, $id, $statusToPersist, $subject, $message): string {
                    $this->assertRequestExists($type, $id);
                    $stmt = $db->prepare("SELECT reporter_email FROM dmca_reports WHERE id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    $row = $stmt->fetch();
                    $email = EncryptionService::decrypt((string)($row['reporter_email'] ?? ''));

                    $this->updateInboxStatus($type, $id, $statusToPersist);
                    $this->addRequestActivity($type, $id, 'reply', $subject, $message, [
                        'recipient' => $email,
                        'status' => $statusToPersist,
                    ]);

                    return $email;
                });

                try {
                    $this->sendLegacyRequestReply($type, $email, $subject, $message);
                    $_SESSION['success'] = 'Reply sent to the DMCA reporter successfully.';
                } catch (\Throwable $mailError) {
                    Logger::warning('Legacy DMCA reply email failed after queue state committed', [
                        'request_type' => $type,
                        'request_id' => $id,
                        'error' => $mailError->getMessage(),
                    ]);
                    $_SESSION['success'] = 'Reply saved to the DMCA ticket.';
                    $_SESSION['warning'] = 'The reply was saved, but the email could not be delivered. Check mail settings and send a follow-up reply if needed.';
                }
            } else {
                throw new \RuntimeException('This ticket type does not support replies.');
            }
        } catch (\Throwable $e) {
            Logger::error('Admin request reply failed', [
                'request_type' => $type,
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Reply failed. Check the ticket details and mail settings, then try again.';
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    public function addRequestNote()
    {
        $type = (string)($_POST['request_type'] ?? '');
        $this->requireRequestQueueAccess($type);
        $redirectTo = $this->sanitizeInternalRedirect($_POST['return_to'] ?? null, '/admin/requests');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $redirectTo);
            exit;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $id = (int)($_POST['request_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $requestPublicId = (string)($_POST['request_public_id'] ?? '');

        if ($id <= 0 || $note === '') {
            $_SESSION['error'] = 'A note is required.';
            header('Location: ' . $redirectTo);
            exit;
        }

        try {
            $ticketTarget = $this->resolveTicketBackedRequest($requestPublicId, $id, $type);
            if ($type === 'support_ticket' && $ticketTarget === null) {
                throw new \RuntimeException('Support ticket target mismatch.');
            }
            if ($ticketTarget !== null) {
                \App\Service\TicketService::addAdminNote($id, (int)(Auth::id() ?? 0), $note);
            } else {
                $this->assertRequestExists($type, $id);
                $this->addRequestActivity($type, $id, 'note', 'Internal note', $note);
            }
            $_SESSION['success'] = 'Internal note added.';
        } catch (\Throwable $e) {
            Logger::error('Admin request note save failed', [
                'request_type' => $type,
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Could not save that note. Please try again.';
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    public function updateRequestStatus()
    {
        $type = (string)($_POST['request_type'] ?? '');
        $this->requireRequestQueueAccess($type);
        $redirectTo = $this->sanitizeInternalRedirect($_POST['return_to'] ?? null, '/admin/requests');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $redirectTo);
            exit;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $id = (int)($_POST['request_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        $requestPublicId = (string)($_POST['request_public_id'] ?? '');

        try {
            if ($id <= 0 || $status === '') {
                throw new \RuntimeException('A ticket and status are required.');
            }

            $ticketTarget = $this->resolveTicketBackedRequest($requestPublicId, $id, $type);
            if ($type === 'support_ticket' && $ticketTarget === null) {
                throw new \RuntimeException('Support ticket target mismatch.');
            }

            if ($ticketTarget !== null) {
                \App\Service\TicketService::updateStatusByAdmin($id, (int)(Auth::id() ?? 0), $status);
            } else {
                $this->assertRequestExists($type, $id);
                $this->updateInboxStatus($type, $id, $status);
                $this->addRequestActivity($type, $id, 'status', 'Status changed', 'Ticket status updated.', [
                    'status' => $status,
                ]);
            }
            $_SESSION['success'] = 'Ticket status updated.';
        } catch (\Throwable $e) {
            Logger::error('Admin request status update failed', [
                'request_type' => $type,
                'request_id' => $id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Status update failed. Please try again.';
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    public function assignRequest()
    {
        $type = (string)($_POST['request_type'] ?? '');
        $this->requireRequestQueueAccess($type);
        $redirectTo = $this->sanitizeInternalRedirect($_POST['return_to'] ?? null, '/admin/requests');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $redirectTo);
            exit;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }

        $id = (int)($_POST['request_id'] ?? 0);
        $requestPublicId = (string)($_POST['request_public_id'] ?? '');
        $assignedStaffUserId = (int)($_POST['assigned_staff_user_id'] ?? 0);
        $assignedStaffUserId = $assignedStaffUserId > 0 ? $assignedStaffUserId : null;

        try {
            if ($id <= 0) {
                throw new \RuntimeException('A valid ticket is required.');
            }

            $ticketTarget = $this->resolveTicketBackedRequest($requestPublicId, $id, $type);
            if ($ticketTarget === null) {
                throw new \RuntimeException('Only ticket-backed requests can be assigned.');
            }
            if (
                !empty($ticketTarget['hidden_from_others'])
                && !Auth::isSuperAdmin()
                && (int)($ticketTarget['hidden_by_admin_user_id'] ?? 0) !== (int)(Auth::id() ?? 0)
            ) {
                throw new \RuntimeException('Only the admin who hid this ticket or the protected super admin can reassign it while it is hidden.');
            }

            if ($assignedStaffUserId !== null) {
                $assignableIds = array_column($this->activeAssignableStaffForRequestType($type), 'id');
                if (!in_array($assignedStaffUserId, $assignableIds, true)) {
                    throw new \RuntimeException('Select an active staff member with access to this queue.');
                }
            }

            \App\Service\TicketService::updateAssignment($id, (int)(Auth::id() ?? 0), $assignedStaffUserId);
            $_SESSION['success'] = $assignedStaffUserId !== null
                ? 'Ticket assignment updated.'
                : 'Ticket assignment cleared.';
        } catch (\Throwable $e) {
            Logger::error('Admin request assignment failed', [
                'request_type' => $type,
                'request_id' => $id,
                'assigned_staff_user_id' => $assignedStaffUserId,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Ticket assignment failed. Please try again.';
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    public function updateRequestVisibility()
    {
        $type = (string)($_POST['request_type'] ?? '');
        $this->requireRequestQueueAccess($type);
        $redirectTo = $this->sanitizeInternalRedirect($_POST['return_to'] ?? null, '/admin/requests');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $redirectTo);
            exit;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die('CSRF mismatch');
        }
        if (!Auth::isAdmin()) {
            Auth::denyAccess('Only admins can hide tickets from other staff.');
        }

        $id = (int)($_POST['request_id'] ?? 0);
        $requestPublicId = (string)($_POST['request_public_id'] ?? '');
        $hidden = !empty($_POST['hidden_from_others']);

        try {
            if ($id <= 0) {
                throw new \RuntimeException('A valid ticket is required.');
            }

            $ticketTarget = $this->resolveTicketBackedRequest($requestPublicId, $id, $type);
            if ($ticketTarget === null) {
                throw new \RuntimeException('Only ticket-backed requests can change hidden visibility.');
            }

            \App\Service\TicketService::updateVisibility($id, (int)(Auth::id() ?? 0), $hidden);
            $_SESSION['success'] = $hidden
                ? 'Ticket hidden from other staff.'
                : 'Ticket is visible to eligible staff again.';
        } catch (\Throwable $e) {
            Logger::error('Admin request visibility update failed', [
                'request_type' => $type,
                'request_id' => $id,
                'hidden_from_others' => $hidden,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Ticket visibility update failed. Please try again.';
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    public function processDmcaFiles()
    {
        $this->checkAuth('dmca.manage', true);
        $redirectTo = $this->sanitizeInternalRedirect($_POST['return_to'] ?? null, '/admin/requests');
        $expectsJson = $this->requestExpectsJson();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($expectsJson) {
                $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
            }
            header('Location: ' . $redirectTo);
            exit;
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            if ($expectsJson) {
                $this->jsonResponse(['success' => false, 'message' => 'CSRF mismatch.'], 403);
            }
            die('CSRF mismatch');
        }

        $requestId = (int)($_POST['request_id'] ?? 0);
        $processMode = trim((string)($_POST['process_mode'] ?? 'selected'));
        $selectedFileIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['file_ids'] ?? [])))));
        $requestPublicId = (string)($_POST['request_public_id'] ?? '');

        try {
            if ($requestId <= 0) {
                throw new \RuntimeException('Invalid DMCA ticket.');
            }

            $ticketTarget = $this->resolveTicketBackedRequest($requestPublicId, $requestId, 'dmca_report');
            if ($ticketTarget !== null) {
                $targetValue = (string)($ticketTarget['metadata']['infringing_url'] ?? $ticketTarget['subject'] ?? '');
            } else {
                $this->assertRequestExists('dmca_report', $requestId);
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT infringing_url FROM dmca_reports WHERE id = ? LIMIT 1");
                $stmt->execute([$requestId]);
                $row = $stmt->fetch();
                if (!$row) {
                    throw new \RuntimeException('DMCA report not found.');
                }
                $targetValue = EncryptionService::decrypt((string)$row['infringing_url']);
            }

            $targets = $this->resolveDmcaTargetFiles($targetValue);
            $matchedFileIds = [];
            foreach ($targets as $target) {
                if (!empty($target['file_id'])) {
                    $matchedFileIds[] = (int)$target['file_id'];
                }
            }
            $matchedFileIds = array_values(array_unique($matchedFileIds));

            $fileIdsToProcess = $processMode === 'all'
                ? $matchedFileIds
                : array_values(array_intersect($selectedFileIds, $matchedFileIds));

            if (empty($fileIdsToProcess)) {
                throw new \RuntimeException('No DMCA target files were selected for removal.');
            }

            [$processedCount, $alreadyRemovedCount, $processedLabels, $bonusTouchUserIds] = $this->runDatabaseTransaction(function () use ($fileIdsToProcess, $requestId, $ticketTarget): array {
                [$processedCount, $alreadyRemovedCount, $processedLabels, $bonusTouchUserIds] = $this->processDmcaFileRemovalBatch($fileIdsToProcess, $requestId);

                if ($processedCount > 0) {
                    if ($ticketTarget !== null) {
                        \App\Service\TicketService::addAdminNote($requestId, (int)(Auth::id() ?? 0), "DMCA file removal processed:\n" . implode("\n", $processedLabels));
                        \App\Service\TicketService::addAdminQueueActivity($requestId, 'status', 'DMCA file removal processed', "Marked the following file(s) for removal:\n" . implode("\n", $processedLabels));
                    } else {
                        $this->addRequestActivity(
                            'dmca_report',
                            $requestId,
                            'status',
                            'DMCA file removal processed',
                            "Marked the following file(s) for removal:\n" . implode("\n", $processedLabels),
                            [
                                'processed_count' => $processedCount,
                                'already_removed_count' => $alreadyRemovedCount,
                                'file_ids' => $fileIdsToProcess,
                            ]
                        );
                    }
                }

                return [$processedCount, $alreadyRemovedCount, $processedLabels, $bonusTouchUserIds];
            });
            $latestActivity = null;

            if ($processedCount > 0) {
                if ($ticketTarget !== null) {
                    $thread = \App\Service\TicketService::getThread($requestId, true);
                    $lastMessage = !empty($thread) ? end($thread) : null;
                    $latestActivity = $lastMessage ? [
                        'activity_type' => 'status',
                        'subject' => 'DMCA file removal processed',
                        'body' => "Marked the following file(s) for removal:\n" . implode("\n", $processedLabels),
                        'created_at' => (string)($lastMessage['created_at'] ?? ''),
                        'username' => Auth::username() ?? '',
                    ] : null;
                } else {
                    $activityMap = $this->fetchRequestActivityMap([
                        ['type_key' => 'dmca_report', 'id' => $requestId],
                    ]);
                    $latestActivity = $activityMap['dmca_report:' . $requestId][0] ?? null;
                }

                $this->touchUsersAfterModerationCommit($bonusTouchUserIds, 'soft_delete_file', [
                    'request_type' => 'dmca_report',
                    'request_id' => $requestId,
                    'file_ids' => $fileIdsToProcess,
                ]);
            }

            $_SESSION['success'] = $processedCount > 0
                ? "Processed {$processedCount} file(s) for DMCA removal." . ($alreadyRemovedCount > 0 ? " {$alreadyRemovedCount} were already removed or pending removal." : '')
                : 'All selected files were already removed or pending removal.';

            if ($expectsJson) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => $_SESSION['success'],
                    'request_id' => $requestId,
                    'processed_count' => $processedCount,
                    'already_removed_count' => $alreadyRemovedCount,
                    'handled_file_ids' => $fileIdsToProcess,
                    'activity' => $latestActivity ? $this->formatRequestActivityForJson($latestActivity) : null,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('DMCA file removal processing failed', [
                'request_id' => $requestId,
                'process_mode' => $processMode,
                'selected_file_ids' => $selectedFileIds,
                'error' => $e->getMessage(),
            ]);
            if ($expectsJson) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
            $_SESSION['error'] = $e->getMessage();
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    public function dmcaReports()
    {
        $this->renderRequestsQueue('dmca_report');
    }

    public function currentDownloadsView()
    {
        $this->checkAuth('downloads.live');
        View::render('admin/current_downloads.php', [
            'demoAdmin' => DemoModeService::currentViewerIsDemoAdmin(),
            'canAccessLiveDownloadIdentities' => $this->canAccessLiveDownloadIdentities(),
            'canAccessLiveDownloadFileDetails' => $this->canAccessLiveDownloadFileDetails(),
        ]);
    }

    public function currentDownloadsData()
    {
        $this->checkAuth('downloads.live');
        header('Content-Type: application/json');

        if (Setting::get('track_current_downloads', '0') !== '1') {
            echo json_encode([]);
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT a.file_id, a.user_id, a.ip_address, a.started_at, f.filename, f.short_id
            FROM active_downloads a
            LEFT JOIN files f ON a.file_id = f.id
            ORDER BY a.started_at DESC
        ");
        $downloads = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $canAccessIdentities = $this->canAccessLiveDownloadIdentities();
        $canAccessFileDetails = $this->canAccessLiveDownloadFileDetails();
        foreach ($downloads as &$dl) {
            $dl['filename'] = EncryptionService::decrypt($dl['filename'] ?? '');
            $dl['ip_address'] = EncryptionService::decrypt($dl['ip_address'] ?? '');
            if ($demoAdmin || !$canAccessIdentities) {
                $dl['ip_address'] = DemoModeService::maskIp((string)$dl['ip_address']);
            }
            if (!$canAccessIdentities) {
                $dl['user_id'] = null;
            }
            if (!$canAccessFileDetails) {
                $dl['filename'] = 'Restricted file';
                $dl['short_id'] = null;
                $dl['file_id'] = null;
            }
        }

        echo json_encode($downloads);
        exit;
    }

    public function staffActivity(): void
    {
        $this->checkAuth('staff.activity.view', true);

        $filters = [
            'query' => trim((string)($_GET['q'] ?? '')),
            'actor_id' => (int)($_GET['actor_id'] ?? 0),
            'action' => trim((string)($_GET['action'] ?? '')),
            'item_type' => trim((string)($_GET['item_type'] ?? '')),
            'risk' => trim((string)($_GET['risk'] ?? '')),
            'date_from' => trim((string)($_GET['date_from'] ?? '')),
            'date_to' => trim((string)($_GET['date_to'] ?? '')),
        ];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pagination = StaffActivityService::searchPaginated($filters, $page, 50);

        View::render('admin/staff_activity.php', [
            'activities' => $pagination['items'],
            'activityPagination' => $pagination,
            'activityFilters' => $filters,
            'activityActors' => StaffActivityService::actorOptions(),
            'activityActions' => StaffActivityService::actionOptions(),
            'activityItemTypes' => StaffActivityService::itemTypeOptions(),
        ]);
    }

    public function investigateUploader(string $id): void
    {
        $this->investigationAccessAllowed();
        $db = Database::getInstance()->getConnection();
        $uploaderId = (int)$id;

        $stmt = $db->prepare("
            SELECT u.*, p.name AS package_name
            FROM users u
            LEFT JOIN packages p ON p.id = u.package_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$uploaderId]);
        $uploader = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$uploader) {
            $_SESSION['error'] = 'Uploader not found.';
            header('Location: /admin/users');
            exit;
        }

        $uploader['username'] = EncryptionService::decrypt((string)($uploader['username'] ?? ''));
        $uploader['email'] = EncryptionService::decrypt((string)($uploader['email'] ?? ''));

        $summaryStmt = $db->prepare("
            SELECT
                (SELECT COUNT(*) FROM files f WHERE f.user_id = u.id) AS file_count,
                (SELECT COALESCE(SUM(f.downloads), 0) FROM files f WHERE f.user_id = u.id) AS lifetime_downloads,
                (SELECT COALESCE(SUM(e.amount), 0) FROM earnings e WHERE e.user_id = u.id AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS rewards_30d,
                (SELECT COUNT(*) FROM earnings e WHERE e.user_id = u.id AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND e.amount > 0) AS reward_rows_30d,
                (SELECT COUNT(*) FROM earnings e WHERE e.user_id = u.id AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND e.status = 'cleared' AND e.amount > 0) AS cleared_rows_30d,
                (SELECT COUNT(*) FROM earnings e WHERE e.user_id = u.id AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND e.status = 'paid' AND e.amount > 0) AS paid_rows_30d,
                (SELECT COUNT(*) FROM earnings e WHERE e.user_id = u.id AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND e.status IN ('held', 'flagged_review')) AS review_rows_30d
            FROM users u
            WHERE u.id = ?
            LIMIT 1
        ");
        $summaryStmt->execute([$uploaderId]);
        $summary = $summaryStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $topFilesStmt = $db->prepare("
            SELECT
                f.id,
                f.filename,
                f.downloads,
                COALESCE(SUM(CASE WHEN e.amount > 0 THEN 1 ELSE 0 END), 0) AS reward_rows,
                COALESCE(SUM(e.amount), 0) AS reward_total
            FROM files f
            LEFT JOIN earnings e ON e.file_id = f.id AND e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE f.user_id = ?
            GROUP BY f.id, f.filename, f.downloads
            ORDER BY reward_total DESC, f.downloads DESC
            LIMIT 12
        ");
        $topFilesStmt->execute([$uploaderId]);
        $topFiles = $topFilesStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($topFiles as &$file) {
            $file['filename'] = EncryptionService::decrypt((string)($file['filename'] ?? ''));
        }
        unset($file);

        $countryStmt = $db->prepare("
            SELECT ds.country_code, COUNT(*) AS session_count
            FROM download_sessions ds
            WHERE ds.uploader_user_id = ?
              AND ds.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY ds.country_code
            ORDER BY session_count DESC
            LIMIT 10
        ");
        $countryStmt->execute([$uploaderId]);
        $countries = $countryStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $referrerStmt = $db->prepare("
            SELECT
                ds.download_page_referrer_url,
                COUNT(*) AS session_count,
                COALESCE(SUM(e.amount), 0) AS reward_total
            FROM download_sessions ds
            LEFT JOIN earnings e ON e.session_id = ds.id
            WHERE ds.uploader_user_id = ?
              AND ds.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND ds.download_page_referrer_url IS NOT NULL
              AND ds.download_page_referrer_url <> ''
            GROUP BY ds.download_page_referrer_url
            ORDER BY session_count DESC, reward_total DESC
            LIMIT 10
        ");
        $referrerStmt->execute([$uploaderId]);
        $referrers = $referrerStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $staffActivity = StaffActivityService::activityForTargetUser($uploaderId, 25);

        View::render('admin/investigations/uploader.php', [
            'uploader' => $uploader,
            'summary' => $summary,
            'topFiles' => $topFiles,
            'countries' => $countries,
            'referrers' => $referrers,
            'staffActivity' => $staffActivity,
        ]);
    }

    public function investigateFile(string $id): void
    {
        $this->investigationAccessAllowed();
        $db = Database::getInstance()->getConnection();
        $fileId = (int)$id;

        $stmt = $db->prepare("
            SELECT f.*, u.username, u.id AS uploader_id
            FROM files f
            LEFT JOIN users u ON u.id = f.user_id
            WHERE f.id = ?
            LIMIT 1
        ");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$file) {
            $_SESSION['error'] = 'File not found.';
            header('Location: /admin/files');
            exit;
        }

        $file['filename'] = EncryptionService::decrypt((string)($file['filename'] ?? ''));
        $file['username'] = EncryptionService::decrypt((string)($file['username'] ?? ''));

        $summaryStmt = $db->prepare("
            SELECT
                SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) AS reward_rows_30d,
                COALESCE(SUM(amount), 0) AS reward_total_30d,
                SUM(CASE WHEN status = 'cleared' AND amount > 0 THEN 1 ELSE 0 END) AS cleared_rows_30d,
                SUM(CASE WHEN status = 'paid' AND amount > 0 THEN 1 ELSE 0 END) AS paid_rows_30d,
                SUM(CASE WHEN status IN ('held', 'flagged_review') THEN 1 ELSE 0 END) AS review_rows_30d
            FROM earnings
            WHERE file_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $summaryStmt->execute([$fileId]);
        $summary = $summaryStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $countryStmt = $db->prepare("
            SELECT ds.country_code, COUNT(*) AS session_count
            FROM download_sessions ds
            WHERE ds.file_id = ?
              AND ds.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY ds.country_code
            ORDER BY session_count DESC
            LIMIT 10
        ");
        $countryStmt->execute([$fileId]);
        $countries = $countryStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $referrerStmt = $db->prepare("
            SELECT
                ds.download_page_referrer_url,
                COUNT(*) AS session_count,
                COALESCE(SUM(e.amount), 0) AS reward_total
            FROM download_sessions ds
            LEFT JOIN earnings e ON e.session_id = ds.id
            WHERE ds.file_id = ?
              AND ds.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND ds.download_page_referrer_url IS NOT NULL
              AND ds.download_page_referrer_url <> ''
            GROUP BY ds.download_page_referrer_url
            ORDER BY session_count DESC, reward_total DESC
            LIMIT 10
        ");
        $referrerStmt->execute([$fileId]);
        $referrers = $referrerStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $queueStmt = $db->prepare("
            SELECT id, amount, status, risk_score, review_note, created_at
            FROM earnings
            WHERE file_id = ?
              AND status IN ('held', 'flagged_review', 'reversed', 'cancelled', 'cleared', 'paid')
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $queueStmt->execute([$fileId]);
        $recentEarnings = $queueStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $staffActivity = StaffActivityService::activityForItem('file', $fileId, 25);

        View::render('admin/investigations/file.php', [
            'file' => $file,
            'summary' => $summary,
            'countries' => $countries,
            'referrers' => $referrers,
            'recentEarnings' => $recentEarnings,
            'staffActivity' => $staffActivity,
        ]);
    }

    public function serverMonitoringHistory()
    {
        if (!$this->canAccessServerMonitoringHistory()) {
            Auth::denyAccess('Storage monitoring history requires File Server management, Support diagnostics, or full Configuration access.');
        }
        $db = Database::getInstance()->getConnection();
        $limit = (int)Setting::get('monitoring_log_limit', '50');
        $stmt = $db->prepare("SELECT l.*, s.name as server_name FROM server_monitoring_log l JOIN file_servers s ON l.server_id = s.id ORDER BY l.checked_at DESC LIMIT ?");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        View::render('admin/server_monitoring.php', [
            'logs' => $stmt->fetchAll(),
            'canManageMonitoringLimit' => Auth::hasCapability('configuration.manage'),
        ]);
    }

    public function migrateFiles()
    {
        $this->checkAuth('file_servers.manage');
        $this->ensureDemoAdminReadOnly(false, '/admin/file-server/migrate');
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM file_servers ORDER BY id ASC");
        $servers = $stmt->fetchAll();

        $results = null;
        $migrationForm = [
            'from_server' => isset($_SESSION['migration_form']['from_server']) ? (int)$_SESSION['migration_form']['from_server'] : (int)($servers[0]['id'] ?? 0),
            'to_server' => isset($_SESSION['migration_form']['to_server']) ? (int)$_SESSION['migration_form']['to_server'] : (int)($servers[0]['id'] ?? 0),
            'batch_limit' => isset($_SESSION['migration_form']['batch_limit']) ? max(1, (int)$_SESSION['migration_form']['batch_limit']) : 50,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            $migrationForm = [
                'from_server' => (int)($_POST['from_server'] ?? 0),
                'to_server' => (int)($_POST['to_server'] ?? 0),
                'batch_limit' => max(1, (int)($_POST['batch_limit'] ?? 50)),
            ];
            $_SESSION['migration_form'] = $migrationForm;
            $service = new \App\Service\MigrationService();
            $results = $service->migrate($migrationForm['from_server'], $migrationForm['to_server'], $migrationForm['batch_limit']);
        }

        View::render('admin/file_servers/migrate.php', [
            'servers' => $servers,
            'results' => $results,
            'migrationForm' => $migrationForm,
        ]);
    }

    public function addFileServer()
    {
        $this->checkAuth('file_servers.manage');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/file-server/add');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            if (($_POST['type'] ?? '') === 'ftp') {
                $_SESSION['error'] = 'FTP storage is not implemented in this build.';
                header("Location: /admin/file-server/add");
                exit;
            }

            $db = Database::getInstance()->getConnection();
            $status = in_array($_POST['status'] ?? 'active', ['active', 'disabled', 'read-only'], true) ? $_POST['status'] : 'active';
            $stmt = $db->prepare("INSERT INTO file_servers (name, server_type, status, storage_path, public_url, config, max_capacity_bytes, delivery_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $makeDefault = isset($_POST['make_default']);
            $preset = $this->fallbackProviderPresetTab($_POST['provider_preset'] ?? null, $_POST['type'] ?? null);

            try {
                $serverType = $this->normalizeFileServerType($_POST['type'] ?? 'local');
                $preset = $this->normalizeProviderPreset($_POST['provider_preset'] ?? $_POST['type'] ?? 'local', $serverType);
                $name = trim((string)($_POST['name'] ?? ''));
                $storagePath = trim((string)($_POST['path'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException('Server Friendly Name is required.');
                }
                if ($storagePath === '') {
                    throw new \RuntimeException('Storage Path or Bucket Name is required.');
                }
                if ($serverType === 'local') {
                    $storagePath = $this->validateLocalStoragePath($storagePath);
                }
                if ($makeDefault && $status !== 'active') {
                    throw new \RuntimeException('Only active storage servers can become the default upload target.');
                }
                $config = $this->normalizeFileServerConfig($_POST['config'] ?? [], $preset);
                $encConfig = EncryptionService::encrypt(json_encode($config));
                $encPath = EncryptionService::encrypt($storagePath);
                $publicUrl = $this->normalizePublicUrl($_POST['url'] ?? '');
                $deliveryMethod = $this->normalizeDeliveryMethod($_POST['delivery_method'] ?? 'php');
                $db->beginTransaction();
                $stmt->execute([$name, $serverType, $status, $encPath, $publicUrl, $encConfig, max(0, (int)$_POST['capacity']), $deliveryMethod]);
                $serverId = (int)$db->lastInsertId();
                $this->normalizeDefaultUploadTargetConfiguration($db, $makeDefault ? $serverId : null);
                $db->commit();
                header("Location: /admin/configuration?tab=storage"); exit;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                Logger::error('File server add failed', [
                    'provider_preset' => $preset,
                    'error' => $e->getMessage(),
                ]);
                $_SESSION['error'] = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'The storage server could not be saved. Review the form values and try again.';
                header("Location: /admin/file-server/add?tab=" . rawurlencode($preset));
                exit;
            }
        }
        View::render('admin/file_servers/add.php');
    }

    public function editFileServer(string $id)
    {
        $this->checkAuth('file_servers.manage');
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) die("Server not found");
        $server = $this->decryptFileServerRow($server);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/file-server/edit/' . rawurlencode($id));
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");
            if ($server['server_type'] === 'ftp') {
                $_SESSION['error'] = 'FTP storage is not implemented in this build.';
                header("Location: /admin/configuration?tab=storage");
                exit;
            }

            $status = in_array($_POST['status'] ?? 'active', ['active', 'disabled', 'read-only'], true) ? $_POST['status'] : 'disabled';
            $stmt = $db->prepare("UPDATE file_servers SET name = ?, status = ?, storage_path = ?, public_url = ?, config = ?, max_capacity_bytes = ?, delivery_method = ? WHERE id = ?");
            $makeDefault = isset($_POST['make_default']);
            try {
                $preset = $this->normalizeProviderPreset($_POST['provider_preset'] ?? $server['server_type'], (string)$server['server_type']);
                $name = trim((string)($_POST['name'] ?? ''));
                $storagePath = trim((string)($_POST['path'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException('Server Friendly Name is required.');
                }
                if ($storagePath === '') {
                    throw new \RuntimeException('Storage Path or Bucket Name is required.');
                }
                if (($server['server_type'] ?? '') === 'local') {
                    $storagePath = $this->validateLocalStoragePath($storagePath);
                }
                if ($makeDefault && $status !== 'active') {
                    throw new \RuntimeException('Only active storage servers can become the default upload target.');
                }
                $config = $this->normalizeFileServerConfig($_POST['config'] ?? [], $preset, $server['config'] ?? []);
                $encConfig = EncryptionService::encrypt(json_encode($config));
                $encPath = EncryptionService::encrypt($storagePath);
                $publicUrl = $this->normalizePublicUrl($_POST['url'] ?? '');
                $deliveryMethod = $this->normalizeDeliveryMethod($_POST['delivery_method'] ?? 'php');
                $db->beginTransaction();
                if ($this->lockedFileServerRow($db, (int)$id, 'id') === null) {
                    throw new \RuntimeException('Storage server not found.');
                }
                $stmt->execute([$name, $status, $encPath, $publicUrl, $encConfig, max(0, (int)$_POST['capacity']), $deliveryMethod, $id]);
                $this->normalizeDefaultUploadTargetConfiguration($db, $makeDefault ? (int)$id : null);
                $db->commit();
                header("Location: /admin/configuration?tab=storage"); exit;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                Logger::error('File server update failed', [
                    'server_id' => $id,
                    'provider_preset' => $preset,
                    'error' => $e->getMessage(),
                ]);
                $_SESSION['error'] = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'The storage server could not be saved. Review the form values and try again.';
                header("Location: /admin/file-server/edit/" . rawurlencode($id));
                exit;
            }
        }

        View::render('admin/file_servers/edit.php', ['server' => $server, 'config' => $server['config'] ?? []]);
    }

    public function deleteFileServer()
    {
        $this->checkAuth('file_servers.manage');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/configuration?tab=storage');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $id = (int)$_POST['server_id'];
            $db = Database::getInstance()->getConnection();
            try {
                $db->beginTransaction();
                if ($this->lockedFileServerRow($db, $id, 'id, status, is_default') === null) {
                    throw new \RuntimeException('Storage server not found.');
                }

                $stmt = $db->prepare($this->appendForUpdateClause($db, "SELECT COUNT(*) FROM stored_files WHERE file_server_id = ?"));
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new \RuntimeException("Cannot delete server while it still contains files. Please migrate them first.");
                }

                $this->assertNoActiveUploadWorkflowsForStorageServer($db, $id);
                $db->prepare("DELETE FROM file_servers WHERE id = ?")->execute([$id]);
                $this->normalizeDefaultUploadTargetConfiguration($db);
                $db->commit();
                $_SESSION['success'] = "File server deleted.";
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'The storage server could not be deleted safely right now.';
            }
        }
        header("Location: /admin/configuration?tab=storage"); exit;
    }

    public function setDefaultFileServer()
    {
        $this->checkAuth('file_servers.manage');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/configuration?tab=storage');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) die("CSRF mismatch");

            $id = (int)$_POST['server_id'];
            $db = Database::getInstance()->getConnection();
            try {
                $db->beginTransaction();
                $this->normalizeDefaultUploadTargetConfiguration($db, $id);
                $db->commit();
                $_SESSION['success'] = "Default storage server updated.";
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'The default storage server could not be updated safely right now.';
            }
        }
        header("Location: /admin/configuration?tab=storage"); exit;
    }

    public function testFileServerDelivery(string $id)
    {
        $this->checkAuth('file_servers.manage');
        $this->ensureDemoAdminReadOnly(false, '/admin/file-server/edit/' . rawurlencode($id));
        $requestValidation = $this->processFileServerDeliveryTestRequest($_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST['csrf_token'] ?? null);
        if (($requestValidation['allowed'] ?? false) !== true) {
            http_response_code((int)($requestValidation['status'] ?? 405));
            exit((string)($requestValidation['message'] ?? 'Method not allowed.'));
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();
        if (!$server) die("Server not found");
        $server = $this->decryptFileServerRow($server);

        $content = "Fyuhls Server Delivery Test\nTimestamp: " . date('Y-m-d H:i:s') . "\nServer: " . $server['name'] . "\nMethod: " . $server['delivery_method'];
        $testPath = '__fyuhls_test/fyuhls_test.txt';
        $provider = \App\Service\Storage\ServerProviderFactory::make($server);
        $method = strtolower((string)($server['delivery_method'] ?? 'php'));

        try {
            $preparedTest = $this->prepareFileServerDeliveryTestArtifact($provider, $method, $content, $testPath);
        } catch (\RuntimeException $e) {
            http_response_code(500);
            exit($e->getMessage());
        }

        if (($preparedTest['status'] ?? 200) !== 200) {
            http_response_code((int)($preparedTest['status'] ?? 422));
            exit((string)($preparedTest['message'] ?? 'Delivery test could not be prepared.'));
        }

        $path = (string)($preparedTest['path'] ?? '');
        $looksLikeFilesystemPath = (bool)($preparedTest['looksLikeFilesystemPath'] ?? false);

        if ($method === 'nginx') {
            if (!$looksLikeFilesystemPath) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="fyuhls_test.txt"');
                header('Content-Length: ' . strlen($content));
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                $this->streamFileServerDeliveryTestArtifact($provider, $testPath);
                exit;
            }
            $safePath = preg_replace('/[^a-zA-Z0-9\/\._-]/', '', $testPath);
            register_shutdown_function(function () use ($provider, $testPath): void {
                $this->deleteFileServerDeliveryTestArtifact($provider, $testPath);
            });
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="fyuhls_test.txt"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-Accel-Redirect: /protected_uploads/' . $safePath);
            exit;
        }

        if ($method === 'apache') {
            register_shutdown_function(function () use ($provider, $testPath): void {
                $this->deleteFileServerDeliveryTestArtifact($provider, $testPath);
            });
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="fyuhls_test.txt"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-SendFile: ' . $path);
            exit;
        }

        if ($method === 'litespeed') {
            register_shutdown_function(function () use ($provider, $testPath): void {
                $this->deleteFileServerDeliveryTestArtifact($provider, $testPath);
            });
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="fyuhls_test.txt"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-LiteSpeed-Location: ' . $path);
            exit;
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="fyuhls_test.txt"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        $this->streamFileServerDeliveryTestArtifact($provider, $testPath);
        exit;
    }

    private function prepareFileServerDeliveryTestArtifact(\App\Interface\StorageProvider $provider, string $method, string $content, string $testPath): array
    {
        $path = (string)$provider->getAbsolutePath($testPath);
        $looksLikeFilesystemPath = preg_match('/^[A-Za-z]:[\\\\\\/]|^\/|^\\\\\\\\/', $path) === 1;
        $compatibilityError = $this->fileServerDeliveryTestFilesystemCompatibilityError($method, $looksLikeFilesystemPath);

        if ($compatibilityError !== null) {
            return [
                'status' => 422,
                'message' => $compatibilityError,
                'path' => $path,
                'looksLikeFilesystemPath' => $looksLikeFilesystemPath,
            ];
        }

        $this->saveFileServerDeliveryTestArtifact($provider, $content, $testPath);

        return [
            'status' => 200,
            'path' => $path,
            'looksLikeFilesystemPath' => $looksLikeFilesystemPath,
        ];
    }

    private function fileServerDeliveryTestFilesystemCompatibilityError(string $method, bool $looksLikeFilesystemPath): ?string
    {
        if ($looksLikeFilesystemPath) {
            return null;
        }

        if ($method === 'apache') {
            return 'Apache handoff delivery tests require a filesystem-backed storage path. This server currently resolves to an object-storage key instead of a local file path, so this test cannot safely verify X-SendFile.';
        }

        if ($method === 'litespeed') {
            return 'LiteSpeed handoff delivery tests require a filesystem-backed storage path. This server currently resolves to an object-storage key instead of a local file path, so this test cannot safely verify X-LiteSpeed-Location.';
        }

        return null;
    }

    private function saveFileServerDeliveryTestArtifact(\App\Interface\StorageProvider $provider, string $content, string $testPath): void
    {
        $tmpPath = \App\Service\TemporaryArtifactService::createTempFile('fy_srv_');

        try {
            if (file_put_contents($tmpPath, $content) === false) {
                throw new \RuntimeException('Failed to prepare the delivery test file.');
            }

            $provider->delete($testPath);
            if (!$provider->save($tmpPath, $testPath)) {
                throw new \RuntimeException('Failed to write the delivery test file to the selected storage server.');
            }
        } finally {
            \App\Service\TemporaryArtifactService::cleanup($tmpPath);
        }
    }

    private function streamFileServerDeliveryTestArtifact(\App\Interface\StorageProvider $provider, string $testPath): void
    {
        try {
            $provider->stream($testPath);
        } finally {
            $this->deleteFileServerDeliveryTestArtifact($provider, $testPath);
        }
    }

    private function deleteFileServerDeliveryTestArtifact(\App\Interface\StorageProvider $provider, string $testPath): void
    {
        try {
            $provider->delete($testPath);
        } catch (\Throwable $e) {
            // Best-effort cleanup only; the delivery test response has already been emitted.
        }
    }

    private function processFileServerDeliveryTestRequest(string $method, ?string $csrfToken): array
    {
        $method = strtoupper(trim($method));
        if ($method !== 'POST') {
            return [
                'allowed' => false,
                'status' => 405,
                'message' => 'Run delivery tests from the authenticated file server form. Direct GET requests are not allowed.',
            ];
        }

        if (!Csrf::verify((string)$csrfToken)) {
            return [
                'allowed' => false,
                'status' => 403,
                'message' => 'CSRF mismatch',
            ];
        }

        return ['allowed' => true];
    }

    public function testFileServerConnection()
    {
        $this->checkAuth('file_servers.manage');
        $this->ensureDemoAdminReadOnly(true);
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'CSRF Token Mismatch']);
            return;
        }
        header('Content-Type: application/json');

        $id = (int)($_POST['server_id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid server ID']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ?");
        $stmt->execute([$id]);
        $server = $stmt->fetch();

        if (!$server) {
            echo json_encode(['success' => false, 'message' => 'Server not found']);
            return;
        }

        try {
            $provider = \App\Service\Storage\ServerProviderFactory::make($server);
            $result = $provider->testConnection();

            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Connection successful!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Connection failed. Check your configuration and permissions.']);
            }
        } catch (\Exception $e) {
            Logger::error('File server connection test failed', [
                'server_id' => $id,
                'error' => $e->getMessage(),
            ]);
            echo json_encode(['success' => false, 'message' => 'Connection test failed. Check the server configuration and logs.']);
        }
    }

    public function discoverBackblazeBuckets(): void
    {
        $this->checkAuth('file_servers.manage');
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $service = new \App\Service\BackblazeB2Service();
            $result = $service->discoverBuckets(
                (string)($_POST['key_id'] ?? ''),
                (string)($_POST['application_key'] ?? '')
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'Backblaze buckets loaded successfully.',
                'account_id' => $result['account_id'],
                'region' => $result['region'],
                'endpoint' => $result['endpoint'],
                'buckets' => $result['buckets'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Backblaze bucket discovery failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'Backblaze bucket discovery failed. Check the credentials and logs.'], 422);
        }
    }

    public function applyBackblazeCors(): void
    {
        $this->checkAuth('file_servers.manage');
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $origins = $this->getStorageAutomationOrigins();
            $service = new \App\Service\BackblazeB2Service();
            $result = $service->applyFyuhlsCors(
                (string)($_POST['key_id'] ?? ''),
                (string)($_POST['application_key'] ?? ''),
                trim((string)($_POST['bucket_name'] ?? '')),
                $origins
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'The recommended Fyuhls CORS rule was applied to the selected B2 bucket.',
                'origin' => $result['applied_origin'],
                'origins' => $result['applied_origins'] ?? [$result['applied_origin']],
                'bucket_name' => $result['bucket_name'],
                'bucket_type' => $result['bucket_type'],
                'cors_rule_count' => $result['cors_rule_count'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Backblaze CORS apply failed', [
                'bucket_name' => trim((string)($_POST['bucket_name'] ?? '')),
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'The CORS update failed. Check the bucket settings and logs.'], 422);
        }
    }

    public function discoverWasabiBuckets(): void
    {
        $this->checkAuth('file_servers.manage');
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $service = new \App\Service\WasabiService();
            $result = $service->discoverBuckets(
                (string)($_POST['access_key'] ?? ''),
                (string)($_POST['secret_key'] ?? ''),
                trim((string)($_POST['region'] ?? 'us-east-1')),
                trim((string)($_POST['endpoint'] ?? ''))
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'Wasabi buckets loaded successfully.',
                'region' => $result['region'],
                'endpoint' => $result['endpoint'],
                'buckets' => $result['buckets'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Wasabi bucket discovery failed', [
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'Wasabi bucket discovery failed. Check the credentials, region, endpoint, and logs.'], 422);
        }
    }

    public function applyWasabiCors(): void
    {
        $this->checkAuth('file_servers.manage');
        $this->ensureDemoAdminReadOnly(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->jsonResponse(['success' => false, 'message' => 'CSRF Token Mismatch'], 403);
        }

        try {
            $origins = $this->getStorageAutomationOrigins();
            $service = new \App\Service\WasabiService();
            $result = $service->applyFyuhlsCors(
                (string)($_POST['access_key'] ?? ''),
                (string)($_POST['secret_key'] ?? ''),
                trim((string)($_POST['bucket_name'] ?? '')),
                $origins,
                trim((string)($_POST['region'] ?? 'us-east-1')),
                trim((string)($_POST['endpoint'] ?? ''))
            );

            $this->jsonResponse([
                'success' => true,
                'message' => 'The recommended Fyuhls CORS rule was applied to the selected Wasabi bucket.',
                'origin' => $result['applied_origin'],
                'origins' => $result['applied_origins'] ?? [$result['applied_origin']],
                'bucket_name' => $result['bucket_name'],
                'cors_rule_count' => $result['cors_rule_count'],
                'region' => $result['region'],
                'endpoint' => $result['endpoint'],
            ]);
        } catch (\Throwable $e) {
            Logger::error('Wasabi CORS apply failed', [
                'bucket_name' => trim((string)($_POST['bucket_name'] ?? '')),
                'error' => $e->getMessage(),
            ]);
            $this->jsonResponse(['success' => false, 'message' => 'The Wasabi CORS update failed. Check the bucket settings and logs.'], 422);
        }
    }

    public function packages()
    {
        $this->checkAuth('packages.manage');
        $packages = Package::getAll();
        $db = Database::getInstance()->getConnection();

        $usageRows = $db->query("SELECT package_id, COUNT(*) AS total FROM users GROUP BY package_id")->fetchAll();
        $userCounts = [];
        foreach ($usageRows as $row) {
            $userCounts[(int)$row['package_id']] = (int)$row['total'];
        }

        View::render('admin/packages/index.php', [
            'packages' => $packages,
            'userCounts' => $userCounts,
        ]);
    }

    private function packageNameExists(string $name, ?int $excludeId = null): bool
    {
        $db = Database::getInstance()->getConnection();
        $sql = 'SELECT 1 FROM packages WHERE LOWER(name) = LOWER(?)';
        $params = [$name];

        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private function defaultPackageTemplate(): array
    {
        return [
            'id' => 0,
            'name' => '',
            'price' => '0.00',
            'subscription_term_days' => 30,
            'renewal_enabled' => 1,
            'billing_options' => [[
                'id' => 0,
                'option_label' => '1 month',
                'price' => '9.99',
                'term_days' => 30,
                'renewal_enabled' => 1,
                'is_active' => 1,
                'display_order' => 0,
            ]],
            'level_type' => 'free',
            'max_storage_bytes' => 0,
            'max_upload_size' => 0,
            'max_daily_downloads' => 0,
            'download_speed' => 0,
            'wait_time' => 0,
            'wait_time_enabled' => 0,
            'concurrent_uploads' => 1,
            'concurrent_downloads' => 1,
            'accepted_file_types' => '',
            'show_ads' => 1,
            'file_expiry_days' => 0,
            'allow_direct_links' => 0,
            'allow_remote_upload' => 0,
            'ppd_enabled' => 0,
            'ppd_rate_per_1000' => '0.00',
            'pps_enabled' => 0,
            'pps_commission_percent' => 0,
            'block_adblock' => 0,
            'block_vpn' => 0,
        ];
    }

    private function packageFormToggleKeys(): array
    {
        return [
            'wait_time_enabled',
            'show_ads',
            'allow_direct_links',
            'allow_remote_upload',
            'ppd_enabled',
            'pps_enabled',
        ];
    }

    private function clampPackageInt($value, int $min = 0, ?int $max = null): int
    {
        $value = (int)$value;
        if ($value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }
        return $value;
    }

    private function clampPackageFloat($value, float $min = 0.0, ?float $max = null): float
    {
        $value = (float)$value;
        if ($value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }
        return $value;
    }

    private function packageUsageCounts(): array
    {
        $db = Database::getInstance()->getConnection();
        $rows = $db->query("SELECT package_id, COUNT(*) AS total FROM users GROUP BY package_id")->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['package_id']] = (int)$row['total'];
        }
        return $counts;
    }

    private function validatePackagePricing(string $levelType, float $price): ?string
    {
        $levelType = strtolower(trim($levelType));

        if ($levelType === 'paid' && $price <= 0) {
            return 'Paid packages must have a price greater than 0.';
        }

        if (in_array($levelType, ['free', 'guest', 'admin'], true) && $price > 0) {
            return 'Free, Guest, and Admin packages must use a price of 0.';
        }

        return null;
    }

    private function validatePackageTerm(string $levelType, int $termDays, bool $renewalEnabled): ?string
    {
        $levelType = strtolower(trim($levelType));

        if ($levelType === 'paid' && $termDays <= 0) {
            return 'Paid packages must have a subscription term of at least 1 day.';
        }

        return null;
    }

    private function uniqueClonedPackageName(string $baseName, ?int $excludeId = null): string
    {
        $baseName = trim($baseName);
        if ($baseName === '') {
            $baseName = 'Package';
        }

        $candidate = mb_substr($baseName . ' Copy', 0, 100);
        if (!$this->packageNameExists($candidate, $excludeId)) {
            return $candidate;
        }

        $suffix = 2;
        do {
            $label = $baseName . ' Copy ' . $suffix;
            $candidate = mb_substr($label, 0, 100);
            $suffix++;
        } while ($this->packageNameExists($candidate, $excludeId));

        return $candidate;
    }

    private function buildPackagePayload(array $package, array $source, bool $fromPost = false, array $postToggleKeys = []): array
    {
        $postToggleKeys = $fromPost ? array_fill_keys($postToggleKeys, true) : [];
        $toggleValue = static function (string $key) use ($package, $source, $fromPost, $postToggleKeys): int {
            if ($fromPost) {
                if (!isset($postToggleKeys[$key])) {
                    return (int)($package[$key] ?? 0);
                }
                return isset($source[$key]) ? 1 : 0;
            }
            return (int)($package[$key] ?? 0);
        };

        return [
            'name' => trim((string)($source['name'] ?? $package['name'] ?? '')),
            'price' => number_format($this->clampPackageFloat($source['price'] ?? ($package['price'] ?? 0), 0.0), 2, '.', ''),
            'subscription_term_days' => max(1, $this->clampPackageInt($source['subscription_term_days'] ?? ($package['subscription_term_days'] ?? 30), 1)),
            'renewal_enabled' => $toggleValue('renewal_enabled'),
            'level_type' => (string)($package['level_type'] ?? 'free'),
            'max_storage_bytes' => $this->clampPackageInt($source['max_storage_bytes'] ?? ($package['max_storage_bytes'] ?? 0), 0),
            'max_upload_size' => $this->clampPackageInt($source['max_upload_size'] ?? ($package['max_upload_size'] ?? 0), 0),
            'max_daily_downloads' => $this->clampPackageInt($source['max_daily_downloads'] ?? ($package['max_daily_downloads'] ?? 0), 0),
            'download_speed' => $this->clampPackageInt($source['download_speed'] ?? ($package['download_speed'] ?? 0), 0),
            'wait_time' => $this->clampPackageInt($source['wait_time'] ?? ($package['wait_time'] ?? 0), 0),
            'wait_time_enabled' => $toggleValue('wait_time_enabled'),
            'concurrent_uploads' => max(1, $this->clampPackageInt($source['concurrent_uploads'] ?? ($package['concurrent_uploads'] ?? 1), 0)),
            'concurrent_downloads' => $this->clampPackageInt($source['concurrent_downloads'] ?? ($package['concurrent_downloads'] ?? 1), 0),
            'accepted_file_types' => trim((string)($source['accepted_file_types'] ?? ($package['accepted_file_types'] ?? ''))),
            'show_ads' => $toggleValue('show_ads'),
            'file_expiry_days' => $this->clampPackageInt($source['file_expiry_days'] ?? ($package['file_expiry_days'] ?? 0), 0),
            'allow_direct_links' => $toggleValue('allow_direct_links'),
            'allow_remote_upload' => $toggleValue('allow_remote_upload'),
            'ppd_enabled' => $toggleValue('ppd_enabled'),
            'ppd_rate_per_1000' => number_format($this->clampPackageFloat($source['ppd_rate_per_1000'] ?? ($package['ppd_rate_per_1000'] ?? 0), 0.0), 2, '.', ''),
            'pps_enabled' => $toggleValue('pps_enabled'),
            'pps_commission_percent' => $this->clampPackageInt($source['pps_commission_percent'] ?? ($package['pps_commission_percent'] ?? 0), 0, 100),
            'block_adblock' => $toggleValue('block_adblock'),
            'block_vpn' => $toggleValue('block_vpn'),
        ];
    }

    private function packageActivitySnapshot(array $package): array
    {
        $billingOptions = is_array($package['billing_options'] ?? null) ? $package['billing_options'] : [];
        return [
            'name' => (string)($package['name'] ?? ''),
            'level_type' => (string)($package['level_type'] ?? ''),
            'price' => number_format((float)($package['price'] ?? 0), 2, '.', ''),
            'subscription_term_days' => (int)($package['subscription_term_days'] ?? 30),
            'renewal_enabled' => (int)($package['renewal_enabled'] ?? 0),
            'max_storage_bytes' => (int)($package['max_storage_bytes'] ?? 0),
            'max_upload_size' => (int)($package['max_upload_size'] ?? 0),
            'max_daily_downloads' => (int)($package['max_daily_downloads'] ?? 0),
            'download_speed' => (int)($package['download_speed'] ?? 0),
            'wait_time' => (int)($package['wait_time'] ?? 0),
            'wait_time_enabled' => (int)($package['wait_time_enabled'] ?? 0),
            'concurrent_uploads' => (int)($package['concurrent_uploads'] ?? 0),
            'concurrent_downloads' => (int)($package['concurrent_downloads'] ?? 0),
            'accepted_file_types' => (string)($package['accepted_file_types'] ?? ''),
            'show_ads' => (int)($package['show_ads'] ?? 0),
            'file_expiry_days' => (int)($package['file_expiry_days'] ?? 0),
            'allow_direct_links' => (int)($package['allow_direct_links'] ?? 0),
            'allow_remote_upload' => (int)($package['allow_remote_upload'] ?? 0),
            'ppd_enabled' => (int)($package['ppd_enabled'] ?? 0),
            'ppd_rate_per_1000' => number_format((float)($package['ppd_rate_per_1000'] ?? 0), 2, '.', ''),
            'pps_enabled' => (int)($package['pps_enabled'] ?? 0),
            'pps_commission_percent' => (int)($package['pps_commission_percent'] ?? 0),
            'block_adblock' => (int)($package['block_adblock'] ?? 0),
            'block_vpn' => (int)($package['block_vpn'] ?? 0),
            'billing_options' => array_map(static function (array $option): array {
                return [
                    'id' => isset($option['id']) ? (int)$option['id'] : 0,
                    'option_label' => (string)($option['option_label'] ?? ''),
                    'price' => number_format((float)($option['price'] ?? 0), 2, '.', ''),
                    'term_days' => (int)($option['term_days'] ?? 30),
                    'renewal_enabled' => !empty($option['renewal_enabled']) ? 1 : 0,
                    'is_active' => !empty($option['is_active']) ? 1 : 0,
                    'display_order' => (int)($option['display_order'] ?? 0),
                ];
            }, $billingOptions),
        ];
    }

    private function draftPackageBillingOptions(array $source, string $levelType, array $fallback = []): array
    {
        if ($levelType !== 'paid') {
            return [];
        }

        $ids = is_array($source['billing_option_id'] ?? null) ? $source['billing_option_id'] : [];
        $labels = is_array($source['billing_option_label'] ?? null) ? $source['billing_option_label'] : [];
        $prices = is_array($source['billing_option_price'] ?? null) ? $source['billing_option_price'] : [];
        $terms = is_array($source['billing_option_term_days'] ?? null) ? $source['billing_option_term_days'] : [];
        $renewals = is_array($source['billing_option_renewal_enabled'] ?? null) ? $source['billing_option_renewal_enabled'] : [];
        $activeFlags = is_array($source['billing_option_is_active'] ?? null) ? $source['billing_option_is_active'] : [];

        $rowCount = max(
            count($ids),
            count($labels),
            count($prices),
            count($terms),
            count($fallback)
        );

        if ($rowCount <= 0) {
            $rowCount = 1;
        }

        $useSourceCheckboxes = count($ids) > 0 || count($labels) > 0 || count($prices) > 0 || count($terms) > 0;
        $rows = [];

        for ($i = 0; $i < $rowCount; $i++) {
            $fallbackRow = is_array($fallback[$i] ?? null) ? $fallback[$i] : [];
            $termDays = max(1, $this->clampPackageInt($terms[$i] ?? ($fallbackRow['term_days'] ?? 30), 1));
            $label = trim((string)($labels[$i] ?? ($fallbackRow['option_label'] ?? '')));
            if ($label === '') {
                $label = PaymentService::formatTermLabel($termDays);
            }

            $rows[] = [
                'id' => (int)($ids[$i] ?? ($fallbackRow['id'] ?? 0)),
                'option_label' => mb_substr($label, 0, 100),
                'price' => round(max(0, (float)($prices[$i] ?? ($fallbackRow['price'] ?? 0))), 2),
                'term_days' => $termDays,
                'renewal_enabled' => $useSourceCheckboxes
                    ? (isset($renewals[$i]) ? 1 : 0)
                    : (!empty($fallbackRow['renewal_enabled']) ? 1 : 0),
                'is_active' => $useSourceCheckboxes
                    ? (isset($activeFlags[$i]) ? 1 : 0)
                    : (!empty($fallbackRow['is_active']) ? 1 : 0),
                'display_order' => $i,
            ];
        }

        return $rows;
    }

    private function hydratePackageDraftFromFormData(array $package, array $formData): array
    {
        $draft = array_merge(
            $package,
            $this->buildPackagePayload($package, $formData, true, $this->packageFormToggleKeys())
        );

        $levelType = trim((string)($formData['level_type'] ?? ($package['level_type'] ?? 'free')));
        if (!in_array($levelType, ['free', 'paid', 'guest', 'admin'], true)) {
            $levelType = (string)($package['level_type'] ?? 'free');
        }

        $draft['name'] = trim((string)($formData['name'] ?? ($package['name'] ?? '')));
        $draft['level_type'] = $levelType;
        $draft['billing_options'] = $this->draftPackageBillingOptions(
            $formData,
            $levelType,
            is_array($package['billing_options'] ?? null) ? $package['billing_options'] : []
        );

        return $draft;
    }

    private function packageChangeMetadata(array $before, array $after): array
    {
        $changedKeys = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changedKeys[] = (string)$key;
            }
        }

        $beforeChanged = [];
        $afterChanged = [];
        foreach ($changedKeys as $key) {
            $beforeChanged[$key] = $before[$key] ?? null;
            $afterChanged[$key] = $after[$key] ?? null;
        }

        return [
            'changed_keys' => $changedKeys,
            'before' => $beforeChanged,
            'after' => $afterChanged,
        ];
    }

    private function parsePackageBillingOptions(array $source, string $levelType, array $existingPackage = []): array
    {
        if ($levelType !== 'paid') {
            return [];
        }

        $ids = $source['billing_option_id'] ?? [];
        $labels = $source['billing_option_label'] ?? [];
        $prices = $source['billing_option_price'] ?? [];
        $terms = $source['billing_option_term_days'] ?? [];
        $renewals = $source['billing_option_renewal_enabled'] ?? [];
        $activeFlags = $source['billing_option_is_active'] ?? [];

        $rowCount = max(
            is_array($ids) ? count($ids) : 0,
            is_array($labels) ? count($labels) : 0,
            is_array($prices) ? count($prices) : 0,
            is_array($terms) ? count($terms) : 0
        );

        $options = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $price = round(max(0, (float)($prices[$i] ?? 0)), 2);
            $termDays = max(1, (int)($terms[$i] ?? 30));
            $label = trim((string)($labels[$i] ?? ''));
            $isActive = isset($activeFlags[$i]) ? 1 : 0;
            $renewalEnabled = isset($renewals[$i]) ? 1 : 0;
            if ($label === '') {
                $label = PaymentService::formatTermLabel($termDays);
            }
            if ($price <= 0) {
                continue;
            }
            if ($renewalEnabled) {
                $termError = PaymentService::recurringTermValidationError($termDays);
                if ($termError !== null) {
                    throw new \RuntimeException('Billing option "' . ($label !== '' ? $label : ('Row ' . ($i + 1))) . '" cannot auto-renew. ' . $termError);
                }
            }
            $options[] = [
                'id' => (int)($ids[$i] ?? 0),
                'option_label' => mb_substr($label, 0, 100),
                'price' => $price,
                'term_days' => $termDays,
                'renewal_enabled' => $renewalEnabled,
                'is_active' => $isActive,
                'display_order' => count($options),
            ];
        }

        if ($options === []) {
            throw new \RuntimeException('Paid packages need at least one billing option with a price and term.');
        }

        $activeOptions = array_values(array_filter($options, static fn(array $option): bool => !empty($option['is_active'])));
        if ($activeOptions === []) {
            throw new \RuntimeException('At least one billing option must stay active for a paid package.');
        }

        return $options;
    }

    private function syncPackagePricingFromBillingOptions(array &$data, string $levelType, array $billingOptions): void
    {
        if ($levelType !== 'paid') {
            $data['price'] = '0.00';
            $data['subscription_term_days'] = 30;
            $data['renewal_enabled'] = 0;
            return;
        }

        $primaryOption = null;
        foreach ($billingOptions as $option) {
            if (!empty($option['is_active'])) {
                $primaryOption = $option;
                break;
            }
        }
        if ($primaryOption === null) {
            $primaryOption = $billingOptions[0];
        }

        $data['price'] = number_format((float)$primaryOption['price'], 2, '.', '');
        $data['subscription_term_days'] = (int)$primaryOption['term_days'];
        $data['renewal_enabled'] = !empty($primaryOption['renewal_enabled']) ? 1 : 0;
    }

    private function packageDeleteBlockedReason(int $packageId): ?string
    {
        $db = Database::getInstance()->getConnection();

        $userStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE package_id = ?");
        $userStmt->execute([$packageId]);
        if ((int)$userStmt->fetchColumn() > 0) {
            return 'Users are still assigned to this package.';
        }

        $subStmt = $db->prepare("SELECT COUNT(*) FROM subscriptions WHERE package_id = ?");
        $subStmt->execute([$packageId]);
        if ((int)$subStmt->fetchColumn() > 0) {
            return 'Subscriptions are still linked to this package.';
        }

        $txStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE package_id = ?");
        $txStmt->execute([$packageId]);
        if ((int)$txStmt->fetchColumn() > 0) {
            return 'Transactions are still linked to this package.';
        }

        $billingOptionStmt = $db->prepare("SELECT id FROM package_billing_options WHERE package_id = ?");
        $billingOptionStmt->execute([$packageId]);
        $packageBillingOptionIds = array_map('intval', $billingOptionStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        $couponRows = $db->query("SELECT id, eligible_package_ids, eligible_billing_option_ids, applies_to_all_paid FROM coupons")->fetchAll() ?: [];
        foreach ($couponRows as $couponRow) {
            $eligibleBillingOptions = json_decode((string)($couponRow['eligible_billing_option_ids'] ?? '[]'), true);
            if (is_array($eligibleBillingOptions) && array_intersect($packageBillingOptionIds, array_map('intval', $eligibleBillingOptions))) {
                return 'At least one coupon still targets a billing option on this package.';
            }
            if (!empty($couponRow['applies_to_all_paid'])) {
                continue;
            }
            $eligible = json_decode((string)($couponRow['eligible_package_ids'] ?? '[]'), true);
            if (is_array($eligible) && in_array($packageId, array_map('intval', $eligible), true)) {
                return 'At least one coupon still targets this package.';
            }
        }

        try {
            \App\Service\BonusOfferService::ensureSchema(false, false);
            $bonusOfferRows = $db->query("SELECT id, audience_type, audience_json FROM bonus_offers WHERE audience_type = 'selected_packages'")->fetchAll() ?: [];
            foreach ($bonusOfferRows as $offerRow) {
                $audience = json_decode((string)($offerRow['audience_json'] ?? '[]'), true);
                if (!is_array($audience)) {
                    continue;
                }
                if (in_array($packageId, array_map('intval', $audience), true)) {
                    return 'At least one bonus offer still targets this package.';
                }
            }
        } catch (\Throwable $e) {
            return 'Bonus-offer dependency checks could not be completed for this package. Repair the bonus-offer schema or data before deleting it.';
        }

        return null;
    }

    private function currentActorCanEditPackage(array $package): bool
    {
        return !Package::isSystemPackage($package) || Auth::isSuperAdmin();
    }

    private function packageEditBlockedMessage(array $package): string
    {
        if (!Package::isSystemPackage($package)) {
            return 'This package can be edited.';
        }

        return 'Guest and Admin packages are protected system plans and can only be edited by a super admin.';
    }

    public function createPackage()
    {
        $this->checkAuth('packages.manage');
        $package = $this->defaultPackageTemplate();
        $db = Database::getInstance()->getConnection();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/packages');
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $levelType = trim((string)($_POST['level_type'] ?? 'free'));
            if (!in_array($levelType, ['free', 'paid'], true)) {
                $levelType = 'free';
            }

            if ($name === '') {
                $_SESSION['error'] = 'Package name is required.';
                $_SESSION['admin_package_create_form'] = $_POST;
                header('Location: /admin/package/create');
                exit;
            }

            if ($this->packageNameExists($name)) {
                $_SESSION['error'] = 'That package name is already in use.';
                $_SESSION['admin_package_create_form'] = $_POST;
                header('Location: /admin/package/create');
                exit;
            }

            try {
                $billingOptions = $this->parsePackageBillingOptions($_POST, $levelType, $package);
            } catch (\RuntimeException $e) {
                $_SESSION['error'] = $e->getMessage();
                $_SESSION['admin_package_create_form'] = $_POST;
                header('Location: /admin/package/create');
                exit;
            }

            $data = $this->buildPackagePayload($package, $_POST, true, $this->packageFormToggleKeys());
            $data['name'] = $name;
            $data['level_type'] = $levelType;
            $this->syncPackagePricingFromBillingOptions($data, $levelType, $billingOptions);

            $pricingError = $this->validatePackagePricing($levelType, (float)($data['price'] ?? 0));
            if ($pricingError !== null) {
                $_SESSION['error'] = $pricingError;
                $_SESSION['admin_package_create_form'] = $_POST;
                header('Location: /admin/package/create');
                exit;
            }

            $packageLockKeys = [];
            try {
                $db->beginTransaction();
                $packageLockKeys = PackageTargetLockService::lockPackageNames($db, [$name]);
                if ($this->packageNameExists($name)) {
                    throw new \RuntimeException('That package name is already in use.');
                }
                if (($data['concurrent_downloads'] ?? 0) > 0) {
                    Setting::set('track_current_downloads', '1', 'downloads');
                }

                $newId = Package::create($data);
                PackageBillingOption::syncForPackage($newId, $billingOptions);
                $createdPackage = Package::find((int)$newId) ?: array_merge($package, $data, ['id' => $newId]);
                StaffActivityService::log(
                    'package_created',
                    'package',
                    (int)$newId,
                    'Created package ' . $name . '.',
                    [
                        'after' => $this->packageActivitySnapshot($createdPackage),
                    ]
                );
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'We could not save the package and its billing options together. Nothing was applied.';
                $_SESSION['admin_package_create_form'] = $_POST;
                header('Location: /admin/package/create');
                exit;
            } finally {
                PackageTargetLockService::releaseLocks($db, $packageLockKeys);
            }
            unset($_SESSION['admin_package_create_form']);
            $_SESSION['success'] = 'Package created successfully.';
            header('Location: /admin/package/edit/' . rawurlencode((string)$newId));
            exit;
        }

        $error = (string)($_SESSION['error'] ?? '');
        $success = (string)($_SESSION['success'] ?? '');
        $formData = is_array($_SESSION['admin_package_create_form'] ?? null) ? $_SESSION['admin_package_create_form'] : [];
        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['admin_package_create_form']);

        if ($formData !== []) {
            $package = $this->hydratePackageDraftFromFormData($package, $formData);
        }

        View::render('admin/packages/edit.php', [
            'package' => $package,
            'userCounts' => [],
            'allPackages' => Package::getAll(),
            'isNewPackage' => true,
            'error' => $error,
            'success' => $success,
        ]);
    }

    public function editPackage(string $id)
    {
        $this->checkAuth('packages.manage');
        $package = Package::find((int)$id);
        $db = Database::getInstance()->getConnection();

        if (!$package) {
            $_SESSION['error'] = 'That package no longer exists.';
            header('Location: /admin/packages');
            exit;
        }

        $canEditPackage = $this->currentActorCanEditPackage($package);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->ensureDemoAdminReadOnly(false, '/admin/package/edit/' . rawurlencode((string)$package['id']));
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }

            if (!$canEditPackage) {
                $_SESSION['error'] = $this->packageEditBlockedMessage($package);
                header("Location: /admin/package/edit/" . rawurlencode((string)$package['id']));
                exit;
            }

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $_SESSION['error'] = 'Package name is required.';
                $_SESSION['admin_package_edit_form'][(int)$package['id']] = $_POST;
                header("Location: /admin/package/edit/" . rawurlencode((string)$package['id']));
                exit;
            }

            if ($this->packageNameExists($name, (int)$package['id'])) {
                $_SESSION['error'] = 'That package name is already in use.';
                $_SESSION['admin_package_edit_form'][(int)$package['id']] = $_POST;
                header("Location: /admin/package/edit/" . rawurlencode((string)$package['id']));
                exit;
            }

            try {
                $billingOptions = $this->parsePackageBillingOptions($_POST, (string)($package['level_type'] ?? 'free'), $package);
            } catch (\RuntimeException $e) {
                $_SESSION['error'] = $e->getMessage();
                $_SESSION['admin_package_edit_form'][(int)$package['id']] = $_POST;
                header("Location: /admin/package/edit/" . rawurlencode((string)$package['id']));
                exit;
            }

            $data = $this->buildPackagePayload($package, $_POST, true, $this->packageFormToggleKeys());
            $data['name'] = $name;
            $this->syncPackagePricingFromBillingOptions($data, (string)($package['level_type'] ?? 'free'), $billingOptions);

            $pricingError = $this->validatePackagePricing((string)($package['level_type'] ?? 'free'), (float)($data['price'] ?? 0));
            if ($pricingError !== null) {
                $_SESSION['error'] = $pricingError;
                $_SESSION['admin_package_edit_form'][(int)$package['id']] = $_POST;
                header("Location: /admin/package/edit/" . rawurlencode((string)$package['id']));
                exit;
            }

            $beforeSnapshot = $this->packageActivitySnapshot($package);
            $packageLockKeys = [];
            $packageNameLockKeys = [];
            try {
                $db->beginTransaction();
                $packageNameLockKeys = PackageTargetLockService::lockPackageNames($db, [$name]);
                if ($this->packageNameExists($name, (int)$package['id'])) {
                    throw new \RuntimeException('That package name is already in use.');
                }
                $packageLockKeys = PackageTargetLockService::lockPackageIds($db, [(int)$package['id']]);
                $lockStmt = $db->prepare("SELECT id FROM packages WHERE id = ? LIMIT 1 FOR UPDATE");
                $lockStmt->execute([(int)$package['id']]);
                if ((int)($lockStmt->fetchColumn() ?: 0) !== (int)$package['id']) {
                    throw new \RuntimeException('Package not found.');
                }
                if (($data['concurrent_downloads'] ?? 0) > 0) {
                    Setting::set('track_current_downloads', '1', 'downloads');
                }
                Package::updateForActor((int)$package['id'], $data, (int)(Auth::id() ?? 0), Auth::role(), Auth::isSuperAdmin());
                PackageBillingOption::syncForPackage((int)$package['id'], $billingOptions);
                $updatedPackage = Package::find((int)$package['id']) ?: array_merge($package, $data);
                $afterSnapshot = $this->packageActivitySnapshot($updatedPackage);
                StaffActivityService::log(
                    'package_updated',
                    'package',
                    (int)$package['id'],
                    'Updated package ' . $name . '.',
                    $this->packageChangeMetadata($beforeSnapshot, $afterSnapshot)
                );
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error'] = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'We could not save the package and its billing options together. Nothing was applied.';
                $_SESSION['admin_package_edit_form'][(int)$package['id']] = $_POST;
                header("Location: /admin/package/edit/" . rawurlencode((string)$package['id']));
                exit;
            } finally {
                PackageTargetLockService::releaseLocks($db, $packageLockKeys);
                PackageTargetLockService::releaseLocks($db, $packageNameLockKeys);
            }
            unset($_SESSION['admin_package_edit_form'][(int)$package['id']]);
            $_SESSION['success'] = 'Package settings saved.';
            header("Location: /admin/packages");
            exit;
        }

        $error = (string)($_SESSION['error'] ?? '');
        $success = (string)($_SESSION['success'] ?? '');
        $editForms = is_array($_SESSION['admin_package_edit_form'] ?? null) ? $_SESSION['admin_package_edit_form'] : [];
        $formData = is_array($editForms[(int)$package['id']] ?? null) ? $editForms[(int)$package['id']] : [];
        unset($_SESSION['error'], $_SESSION['success']);
        if (isset($_SESSION['admin_package_edit_form'][(int)$package['id']])) {
            unset($_SESSION['admin_package_edit_form'][(int)$package['id']]);
            if ($_SESSION['admin_package_edit_form'] === []) {
                unset($_SESSION['admin_package_edit_form']);
            }
        }

        if ($formData !== []) {
            $package = $this->hydratePackageDraftFromFormData($package, $formData);
        }

        View::render('admin/packages/edit.php', [
            'package' => $package,
            'userCounts' => $this->packageUsageCounts(),
            'allPackages' => Package::getAll(),
            'canEditPackage' => $canEditPackage,
            'packageEditBlockedMessage' => $this->packageEditBlockedMessage($package),
            'error' => $error,
            'success' => $success,
        ]);
    }

    public function clonePackage(string $id)
    {
        $this->checkAuth('packages.manage');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            header('Location: /admin/packages');
            exit;
        }
        $this->ensureDemoAdminReadOnly(false, '/admin/packages');
        $db = Database::getInstance()->getConnection();

        $package = Package::find((int)$id);
        if (!$package) {
            $_SESSION['error'] = 'Package not found.';
            header('Location: /admin/packages');
            exit;
        }

        $levelType = (string)($package['level_type'] ?? '');
        if (in_array($levelType, ['guest', 'admin'], true)) {
            $_SESSION['error'] = 'Guest and Admin packages are singleton system plans and cannot be cloned.';
            header('Location: /admin/packages');
            exit;
        }

        $data = $this->buildPackagePayload($package, []);
        $baseName = trim((string)($package['name'] ?? 'Package'));
        $cloneName = $this->uniqueClonedPackageName($baseName, (int)($package['id'] ?? 0));
        $data['name'] = $cloneName;

        $pricingError = $this->validatePackagePricing((string)($data['level_type'] ?? 'free'), (float)($data['price'] ?? 0));
        if ($pricingError !== null) {
            $_SESSION['error'] = 'This package cannot be cloned until its plan type and price are corrected.';
            header('Location: /admin/package/edit/' . rawurlencode((string)$package['id']));
            exit;
        }

        $packageLockKeys = [];
        $packageNameLockKeys = [];
        try {
            $db->beginTransaction();
            $packageNameLockKeys = PackageTargetLockService::lockPackageNames($db, [$cloneName]);
            if ($this->packageNameExists($cloneName)) {
                throw new \RuntimeException('That package name is already in use.');
            }
            $packageLockKeys = PackageTargetLockService::lockPackageIds($db, [(int)$package['id']]);
            $lockStmt = $db->prepare("SELECT id FROM packages WHERE id = ? LIMIT 1 FOR UPDATE");
            $lockStmt->execute([(int)$package['id']]);
            if ((int)($lockStmt->fetchColumn() ?: 0) !== (int)$package['id']) {
                throw new \RuntimeException('Package not found.');
            }
            $newId = Package::create($data);
            $sourceOptions = PackageBillingOption::forPackage((int)$package['id']);
            if ($sourceOptions !== []) {
                foreach ($sourceOptions as &$option) {
                    $option['id'] = 0;
                }
                unset($option);
                PackageBillingOption::syncForPackage($newId, $sourceOptions);
            }
            $clonedPackage = Package::find((int)$newId) ?: array_merge($package, $data, ['id' => $newId]);
            StaffActivityService::log(
                'package_cloned',
                'package',
                (int)$newId,
                'Cloned package ' . $baseName . ' into ' . $cloneName . '.',
                [
                    'source_package_id' => (int)($package['id'] ?? 0),
                    'source_name' => $baseName,
                    'after' => $this->packageActivitySnapshot($clonedPackage),
                ]
            );
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'We could not clone the package and its billing options together. Nothing was applied.';
            header('Location: /admin/package/edit/' . rawurlencode((string)$package['id']));
            exit;
        } finally {
            PackageTargetLockService::releaseLocks($db, $packageLockKeys);
            PackageTargetLockService::releaseLocks($db, $packageNameLockKeys);
        }
        $_SESSION['success'] = 'Package cloned successfully. Review the copied plan before using it live.';
        header('Location: /admin/package/edit/' . rawurlencode((string)$newId));
        exit;
    }

    public function deletePackage(string $id): void
    {
        $this->checkAuth('packages.manage');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            header('Location: /admin/packages');
            exit;
        }
        $this->ensureDemoAdminReadOnly(false, '/admin/package/edit/' . rawurlencode($id));

        $package = Package::find((int)$id);
        if (!$package) {
            $_SESSION['error'] = 'Package not found.';
            header('Location: /admin/packages');
            exit;
        }

        if (in_array((string)($package['level_type'] ?? ''), ['guest', 'admin'], true)) {
            $_SESSION['error'] = 'Guest and Admin packages are system packages and cannot be deleted.';
            header('Location: /admin/package/edit/' . rawurlencode((string)$package['id']));
            exit;
        }

        $blockedReason = $this->packageDeleteBlockedReason((int)$package['id']);
        if ($blockedReason !== null) {
            $_SESSION['error'] = $blockedReason;
            header('Location: /admin/package/edit/' . rawurlencode((string)$package['id']));
            exit;
        }

        $db = Database::getInstance()->getConnection();
        $packageLockKeys = [];
        try {
            $db->beginTransaction();
            $packageLockKeys = PackageTargetLockService::lockPackageIds($db, [(int)$package['id']]);
            $lockStmt = $db->prepare("SELECT id FROM packages WHERE id = ? LIMIT 1 FOR UPDATE");
            $lockStmt->execute([(int)$package['id']]);
            if ((int)($lockStmt->fetchColumn() ?: 0) !== (int)$package['id']) {
                throw new \RuntimeException('Package not found.');
            }

            $blockedReason = $this->packageDeleteBlockedReason((int)$package['id']);
            if ($blockedReason !== null) {
                throw new \RuntimeException($blockedReason);
            }

            $db->prepare("DELETE FROM packages WHERE id = ? LIMIT 1")->execute([(int)$package['id']]);
            StaffActivityService::log(
                'package_deleted',
                'package',
                (int)$package['id'],
                'Deleted package ' . (string)($package['name'] ?? 'package') . '.',
                [
                    'before' => $this->packageActivitySnapshot($package),
                ]
            );
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = $e instanceof \RuntimeException ? $e->getMessage() : 'We could not delete the package safely. Nothing was applied.';
            header('Location: /admin/package/edit/' . rawurlencode((string)$package['id']));
            exit;
        } finally {
            PackageTargetLockService::releaseLocks($db, $packageLockKeys);
        }
        $_SESSION['success'] = 'Package deleted.';
        header('Location: /admin/packages');
        exit;
    }

    private function decryptFileServerRow(array $server): array
    {
        if (!empty($server['storage_path'])) {
            $server['storage_path'] = EncryptionService::decrypt($server['storage_path']);
        }

        if (!empty($server['config'])) {
            $dec = EncryptionService::decrypt($server['config']);
            $server['config'] = json_decode($dec, true) ?? json_decode($server['config'], true) ?? [];
        } else {
            $server['config'] = [];
        }

        return $server;
    }

    private function normalizeFileServerConfig(array $postedConfig, string $preset, array $existingConfig = []): array
    {
        $config = $postedConfig;
        $config['provider_preset'] = $preset;

        foreach (['s3_key', 's3_secret'] as $secretKey) {
            $postedValue = trim((string)($config[$secretKey] ?? ''));
            if ($postedValue === '' && isset($existingConfig[$secretKey])) {
                $config[$secretKey] = $existingConfig[$secretKey];
            }
        }

        if ($preset === 'b2') {
            $region = trim((string)($config['s3_region'] ?? 'us-west-004'));
            $endpointInput = trim((string)($config['s3_endpoint'] ?? ''));
            $endpointHost = '';

            if ($endpointInput !== '') {
                $normalized = $this->normalizeEndpointUrl($endpointInput, 'B2 endpoint');
                $endpointHost = $normalized['host'];
            }
            if ($endpointHost !== '' && preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/i', $endpointHost, $matches)) {
                $region = strtolower($matches[1]);
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://' . $endpointHost;
            } else {
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://s3.' . $region . '.backblazeb2.com';
            }
        } elseif ($preset === 'wasabi') {
            $region = trim((string)($config['s3_region'] ?? 'us-east-1'));
            $endpointInput = trim((string)($config['s3_endpoint'] ?? ''));
            $endpointHost = '';

            if ($endpointInput !== '') {
                $normalized = $this->normalizeEndpointUrl($endpointInput, 'Wasabi endpoint');
                $endpointHost = $normalized['host'];
            }

            if ($endpointHost !== '' && preg_match('/(?:^|\.)(s3\.([a-z0-9-]+)\.wasabisys\.com)$/i', $endpointHost, $matches)) {
                $region = strtolower($matches[2]);
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://' . strtolower($matches[1]);
            } else {
                $config['s3_region'] = $region;
                $config['s3_endpoint'] = 'https://s3.' . $region . '.wasabisys.com';
            }
        } elseif ($preset === 'r2') {
            $config['s3_region'] = 'auto';
            $accountId = trim((string)($config['s3_endpoint'] ?? ''));
            if (!preg_match('/^[a-f0-9]{32}$/i', $accountId)) {
                throw new \RuntimeException('Cloudflare Account ID must be a 32-character hexadecimal string.');
            }
            $config['s3_endpoint'] = strtolower($accountId);
        } elseif ($preset === 's3') {
            $normalized = $this->normalizeEndpointUrl((string)($config['s3_endpoint'] ?? ''), 'Endpoint URL');
            $config['s3_endpoint'] = $normalized['url'];
        }

        return $config;
    }

    public function status()
    {
        $this->checkAuth('status.view', true);
        $canAccessOperationalDiagnostics = $this->canAccessOperationalDiagnostics();

        // Initialize default values to prevent view warnings
        $writable = 'unknown';
        $blocked = 0;
        $logs = [];
        $errorsOnly = [];

        $hostService = new \App\Service\HostService();
        $logFile = Logger::logFilePath();
        $logMaxBytes = Logger::maxBytes();
        if ($canAccessOperationalDiagnostics && file_exists($logFile)) {
            $lines = @file($logFile);
            $logs = array_slice($lines ?? [], -50);
        }
        clearstatcache(true, $logFile);
        $logSizeBytes = file_exists($logFile) ? (int)filesize($logFile) : 0;

        $metrics = $hostService->getMetrics();
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();
        $uploadStorageRoot = dirname(__DIR__, 3) . '/storage/uploads';
        $writable = is_dir($uploadStorageRoot) && is_writable($uploadStorageRoot) ? 'ok' : 'not writable';
        $gdOk = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
        $ffmpegPath = Setting::getOrConfig('video.ffmpeg_path', Config::get('video.ffmpeg_path', ''));
        $ffmpegEnabled = Setting::getOrConfig('video.ffmpeg_enabled', '1');
        $ffmpegOk = $ffmpegEnabled === '1' && !empty($ffmpegPath) && file_exists($ffmpegPath);
        $updateStatus = [
            'update_available' => false,
            'current_version' => null,
            'latest_version' => null,
            'checked_at' => null,
        ];
        if ($this->updateServiceAvailable()) {
            $updater = new UpdateService();
            $updateStatus = $updater->getStatus(isset($_GET['refresh_update']) && $_GET['refresh_update'] === '1');
        } else {
            $updateStatus = $this->updateServiceUnavailableStatus();
        }

        $limit = Config::get('security.rate_limit.download.limit', 5);
        $window = (int)Config::get('security.rate_limit.download.window', 600);
        $currentWindow = floor(time() / $window) * $window;
        $db = Database::getInstance()->getConnection();

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM download_limits WHERE window_start = ? AND attempt_count > ?");
            $stmt->execute([$currentWindow, $limit]);
            $blocked = (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        $uploadStats = [
            'active_sessions' => 0,
            'stale_sessions' => 0,
            'failed_sessions' => 0,
            'active_reservations' => 0,
            'reserved_bytes' => 0,
            'stuck_completing' => 0,
            'checksum_backlog' => 0,
            'expired_reservations' => 0,
        ];

        $deliveryStats = [
            'public_object_files' => 0,
            'private_object_files' => 0,
            'local_files' => 0,
            'cdn_eligible_files' => 0,
            'signed_origin_files' => 0,
            'app_controlled_files' => 0,
            'cdn_enabled' => Setting::get('cdn_download_redirects_enabled', '0') === '1',
            'cdn_base_configured' => trim(Setting::get('cdn_download_base_url', '')) !== '',
            'ppd_progress_tracking' => (int)Setting::get('ppd_min_download_percent', '0'),
        ];

        try {
            $stmt = $db->query("
                SELECT
                    SUM(CASE WHEN status IN ('pending', 'uploading', 'completing', 'processing') THEN 1 ELSE 0 END) AS active_sessions,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_sessions,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() AND status IN ('pending', 'uploading', 'completing', 'processing') THEN 1 ELSE 0 END) AS stale_sessions,
                    SUM(CASE WHEN status = 'completing' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) AS stuck_completing
                FROM upload_sessions
            ");
            $row = $stmt->fetch() ?: [];
            $uploadStats['active_sessions'] = (int)($row['active_sessions'] ?? 0);
            $uploadStats['failed_sessions'] = (int)($row['failed_sessions'] ?? 0);
            $uploadStats['stale_sessions'] = (int)($row['stale_sessions'] ?? 0);
            $uploadStats['stuck_completing'] = (int)($row['stuck_completing'] ?? 0);
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT
                    COUNT(*) AS active_reservations,
                    COALESCE(SUM(reserved_bytes), 0) AS reserved_bytes
                FROM quota_reservations
                WHERE status = 'active'
            ");
            $row = $stmt->fetch() ?: [];
            $uploadStats['active_reservations'] = (int)($row['active_reservations'] ?? 0);
            $uploadStats['reserved_bytes'] = (int)($row['reserved_bytes'] ?? 0);
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT COUNT(*)
                FROM quota_reservations
                WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()
            ");
            $uploadStats['expired_reservations'] = (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT COUNT(*)
                FROM stored_files
                WHERE file_hash IS NOT NULL
                  AND (checksum_verified_at IS NULL OR checksum_verified_at = '0000-00-00 00:00:00')
            ");
            $uploadStats['checksum_backlog'] = (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        try {
            $stmt = $db->query("
                SELECT
                    SUM(CASE WHEN sf.storage_provider <> 'local' AND f.is_public = 1 THEN 1 ELSE 0 END) AS public_object_files,
                    SUM(CASE WHEN sf.storage_provider <> 'local' AND f.is_public = 0 THEN 1 ELSE 0 END) AS private_object_files,
                    SUM(CASE WHEN sf.storage_provider = 'local' THEN 1 ELSE 0 END) AS local_files
                FROM files f
                JOIN stored_files sf ON sf.id = f.stored_file_id
                WHERE f.status IN ('active', 'ready', 'processing', 'hidden')
            ");
            $row = $stmt->fetch() ?: [];
            $deliveryStats['public_object_files'] = (int)($row['public_object_files'] ?? 0);
            $deliveryStats['private_object_files'] = (int)($row['private_object_files'] ?? 0);
            $deliveryStats['local_files'] = (int)($row['local_files'] ?? 0);

            $objectFiles = $deliveryStats['public_object_files'] + $deliveryStats['private_object_files'];
            if ($deliveryStats['ppd_progress_tracking'] > 0) {
                $deliveryStats['app_controlled_files'] = $objectFiles + $deliveryStats['local_files'];
            } else {
                $deliveryStats['cdn_eligible_files'] = ($deliveryStats['cdn_enabled'] && $deliveryStats['cdn_base_configured'])
                    ? $deliveryStats['public_object_files']
                    : 0;
                $deliveryStats['signed_origin_files'] = max(0, $objectFiles - $deliveryStats['cdn_eligible_files']);
                $deliveryStats['app_controlled_files'] = $deliveryStats['local_files'];
            }
        } catch (\PDOException $e) {
            // Tables might not exist yet
        }

        $recentUploadSessions = [];
        $recentReservations = [];
        if ($canAccessOperationalDiagnostics) {
            try {
                $stmt = $db->query("
                    SELECT
                        us.public_id,
                        us.user_id,
                        us.original_filename,
                        us.storage_provider,
                        us.expected_size,
                        us.uploaded_bytes,
                        us.completed_parts,
                        us.status,
                        us.error_message,
                        us.expires_at,
                        us.created_at,
                        us.updated_at,
                        u.username
                    FROM upload_sessions us
                    LEFT JOIN users u ON u.id = us.user_id
                    ORDER BY us.id DESC
                    LIMIT 25
                ");
                $recentUploadSessions = $stmt->fetchAll() ?: [];
                foreach ($recentUploadSessions as &$session) {
                    if (!empty($session['username']) && str_starts_with((string)$session['username'], 'ENC:')) {
                        $session['username'] = EncryptionService::decrypt($session['username']);
                    }
                    if (!empty($session['original_filename']) && str_starts_with((string)$session['original_filename'], 'ENC:')) {
                        $session['original_filename'] = EncryptionService::decrypt($session['original_filename']);
                    }
                }
                unset($session);
            } catch (\PDOException $e) {
                // Table might not exist yet
            }

            try {
                $stmt = $db->query("
                    SELECT
                        qr.public_id,
                        qr.user_id,
                        qr.upload_session_id,
                        qr.storage_server_id,
                        qr.reserved_bytes,
                        qr.status,
                        qr.expires_at,
                        qr.created_at,
                        u.username,
                        us.public_id AS upload_public_id
                    FROM quota_reservations qr
                    LEFT JOIN users u ON u.id = qr.user_id
                    LEFT JOIN upload_sessions us ON us.id = qr.upload_session_id
                    ORDER BY qr.id DESC
                    LIMIT 25
                ");
                $recentReservations = $stmt->fetchAll() ?: [];
                foreach ($recentReservations as &$reservation) {
                    if (!empty($reservation['username']) && str_starts_with((string)$reservation['username'], 'ENC:')) {
                        $reservation['username'] = EncryptionService::decrypt($reservation['username']);
                    }
                }
                unset($reservation);
            } catch (\PDOException $e) {
                // Table might not exist yet
            }
        }

        $formattedLogs = [];
        foreach ($logs as $line) {
            $formattedLogs[] = $this->formatApplicationLogLine($line);
        }

        if ($demoAdmin) {
            foreach ($formattedLogs as &$entry) {
                $entry['context'] = DemoModeService::redactContext((array)($entry['context'] ?? []));
                $entry['message'] = DemoModeService::redactTextContent((string)($entry['message'] ?? ''));
                $entry['raw'] = DemoModeService::hiddenLabel();
            }
            unset($entry);
        }

        foreach (array_reverse($logs) as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (isset($decoded['ctx'])) {
                foreach ($decoded['ctx'] as $key => &$val) {
                    $val = $this->sanitizeLogContextValue($val);
                }
                unset($val);
                $line = json_encode($decoded);
            } else {
                $line = $this->sanitizeLogRawText($line);
            }

            if (($decoded['level'] ?? '') === 'error') {
                $errorsOnly[] = $demoAdmin ? DemoModeService::redactTextContent($line) : $line;
                if (count($errorsOnly) >= 20) {
                    break;
                }
            }
        }
        View::render('admin/status.php', [
            'writable' => $writable,
            'gdOk' => $gdOk,
            'ffmpegOk' => $ffmpegOk,
            'blocked' => $blocked,
            'errors' => $errorsOnly,
            'logs' => $logs,
            'formattedLogs' => $formattedLogs,
            'metrics' => $metrics,
            'supportEmail' => $demoAdmin ? DemoModeService::hiddenLabel() : DiagnosticsService::SUPPORT_EMAIL,
            'smtpConfigured' => $this->isSupportEmailAvailable(),
            'updateStatus' => $updateStatus,
            'uploadStats' => $uploadStats,
            'deliveryStats' => $deliveryStats,
            'recentUploadSessions' => $recentUploadSessions,
            'recentReservations' => $recentReservations,
            'demoAdmin' => $demoAdmin,
            'logSizeBytes' => $logSizeBytes,
            'logSizeReadable' => $this->formatReadableBytes($logSizeBytes),
            'logMaxBytes' => $logMaxBytes,
            'logMaxReadable' => $this->formatReadableBytes($logMaxBytes),
            'canAccessOperationalDiagnostics' => $canAccessOperationalDiagnostics,
            'canAccessSupportDiagnostics' => Auth::hasCapability('support.manage'),
            'canAccessServerMonitoringHistory' => $this->canAccessServerMonitoringHistory(),
            'canManageUpdates' => Auth::hasCapability('configuration.manage'),
            'canManageConfiguration' => Auth::hasCapability('configuration.manage'),
        ]);
    }

    private function formatApplicationLogLine(string $line): array
    {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return [
                'timestamp' => '',
                'level' => 'raw',
                'message' => trim($line),
                'context' => [],
                'raw' => trim($line),
            ];
        }

        $context = [];
        if (isset($decoded['ctx']) && is_array($decoded['ctx'])) {
            foreach ($decoded['ctx'] as $key => $val) {
                $context[$key] = $this->sanitizeLogContextValue($val);
            }
        }

        return [
            'timestamp' => (string)($decoded['ts'] ?? ''),
            'level' => (string)($decoded['level'] ?? 'info'),
            'message' => $this->sanitizeLogRawText((string)($decoded['msg'] ?? trim($line))),
            'context' => $context,
            'raw' => $this->sanitizeLogRawText(trim($line)),
        ];
    }

    private function sanitizeLogContextValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->sanitizeLogContextValue($child);
            }
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        return $this->sanitizeLogRawText($value);
    }

    private function sanitizeLogRawText(string $value): string
    {
        $value = (string)preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_REDACTED]', $value);
        $value = (string)preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP_REDACTED]', $value);
        $value = (string)preg_replace('/\b([a-f0-9]{1,4}:){2,}[a-f0-9]{1,4}\b/i', '[IPV6_REDACTED]', $value);
        $value = (string)preg_replace('/((?:key|secret|password|token|signature|authorization|cookie|session|email|username|user|host|path|ip)(?:[_-]?[a-z0-9]+)?)\s*[:=]\s*[^\s,]+/i', '$1: [REDACTED]', $value);
        $value = (string)preg_replace('~(?:[A-Za-z]:[\\\\/]|/)(?:[^\\s"\']+[\\\\/])*[^\\s"\']*~', '[PATH_REDACTED]', $value);
        return (string)preg_replace('/ENC:[A-Za-z0-9+\/=:_-]+/', '[ENCRYPTED_VALUE]', $value);
    }

    public function abortUploadSession(): void
    {
        $this->checkAuth('configuration.manage', true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $publicId = (string)($_POST['session_id'] ?? '');
        if ($publicId === '') {
            $_SESSION['error'] = 'Missing upload session ID.';
            header('Location: /admin/status');
            exit;
        }

        try {
            $session = \App\Model\UploadSession::findByPublicId($publicId);
            if (!$session) {
                throw new \RuntimeException('Upload session not found.');
            }

            (new \App\Service\MultipartUploadService())->abort($session);
            $_SESSION['success'] = 'Upload session aborted: ' . $publicId;
        } catch (\Throwable $e) {
            Logger::error('Admin upload session abort failed', [
                'session_id' => $publicId,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Could not abort the upload session. Check the logs and try again.';
        }

        header('Location: /admin/status');
        exit;
    }

    public function applyUpdate(): void
    {
        $this->checkAuth('configuration.manage', true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        try {
            if (!$this->updateServiceAvailable()) {
                throw new \RuntimeException('The update service is not available in this deployment.');
            }
            $result = (new UpdateService())->applyLatestRelease();
            $_SESSION['success'] = sprintf(
                'Updated from %s to %s. Refreshed %d files and created %d directories.',
                $result['from_version'],
                $result['to_version'],
                $result['files_copied'],
                $result['directories_created']
            );
        } catch (\Throwable $e) {
            Logger::error('Admin update apply failed', [
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Update failed. Check the application logs for details.';
        }

        header('Location: /admin/status');
        exit;
    }

    public function documentation() { $this->checkAuth('docs.view', true); View::render('admin/docs.php'); }
    public function supportUs()
    {
        $this->checkAuth('support.manage');
        $demoAdmin = DemoModeService::currentViewerIsDemoAdmin();

        $issueDescription = $_SESSION['support_issue_description'] ?? '';
        unset($_SESSION['support_issue_description']);
        $bundle = [];
        $preview = '{}';
        if (!$demoAdmin) {
            $service = new DiagnosticsService();
            $bundle = $service->generateSupportPreview([
                'issue_description' => $issueDescription,
            ]);
            $preview = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        View::render('admin/support.php', [
            'supportBundle' => $bundle,
            'issueDescription' => $issueDescription,
            'supportEmail' => $demoAdmin ? DemoModeService::hiddenLabel() : DiagnosticsService::SUPPORT_EMAIL,
            'smtpConfigured' => $this->isSupportEmailAvailable(),
            'supportJsonPreview' => $preview,
            'demoAdmin' => $demoAdmin,
        ]);
    }

    public function downloadSupportBundle(): void
    {
        $this->checkAuth('support.manage');
        if (DemoModeService::currentViewerIsDemoAdmin()) {
            $_SESSION['error'] = 'Support bundle export is hidden for the demo admin account.';
            header('Location: /admin/support');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $bundle = $this->buildSupportBundleFromRequest();
        $token = $bundle['metadata']['support_token'] ?? ('support_' . date('Ymd_His'));

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $token . '.json"');
        echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function emailSupportBundle(): void
    {
        $this->checkAuth('support.manage');
        if (DemoModeService::currentViewerIsDemoAdmin()) {
            $_SESSION['error'] = 'Support bundle email is hidden for the demo admin account.';
            header('Location: /admin/support');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF mismatch");
        }

        $_SESSION['support_issue_description'] = trim((string)($_POST['issue_description'] ?? ''));

        if (($_POST['approve_data_share'] ?? '') !== '1') {
            $_SESSION['error'] = 'Please confirm that you reviewed and approve sending the sanitized bundle.';
            header('Location: /admin/support');
            exit;
        }

        if (!$this->isSupportEmailAvailable()) {
            $_SESSION['error'] = 'SMTP is not configured. Download the support bundle and send it manually instead.';
            header('Location: /admin/support');
            exit;
        }

        $bundle = $this->buildSupportBundleFromRequest();
        $service = new DiagnosticsService();
        $token = $bundle['metadata']['support_token'] ?? 'support_bundle';

        try {
            $mail = MailService::createFromSettings();
            $mail->send(
                DiagnosticsService::SUPPORT_EMAIL,
                'Fyuhls Support Bundle ' . $token,
                $service->generateSupportEmailBody($bundle),
                [[
                    'filename' => $token . '.json',
                    'content' => json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'content_type' => 'application/json',
                ]]
            );
            $_SESSION['success'] = 'Sanitized support bundle emailed to ' . DiagnosticsService::SUPPORT_EMAIL . '.';
        } catch (\Throwable $e) {
            Logger::error('Support bundle email failed', [
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Support email failed. Check the mail settings and logs, then try again.';
        }

        header('Location: /admin/support');
        exit;
    }

    private function buildSupportBundleFromRequest(): array
    {
        $service = new DiagnosticsService();

        return $service->generateSupportBundle([
            'issue_description' => trim((string)($_POST['issue_description'] ?? '')),
        ]);
    }

    private function isSupportEmailAvailable(): bool
    {
        return trim(Setting::get('email_smtp_host', '')) !== '' && trim(Setting::get('email_from_address', '')) !== '';
    }
}
