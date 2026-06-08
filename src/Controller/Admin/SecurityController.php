<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\View;
use App\Core\Csrf;
use App\Core\Logger;
use App\Model\Setting;
use App\Service\CloudflareSyncService;
use App\Service\Database\ManualJsonColumnMigrationService;
use App\Service\Database\SchemaService;
use App\Service\DemoModeService;
use App\Core\Database;
use App\Service\StaffActivityService;

class SecurityController {
    private function runSecurityWriteTransaction(callable $callback): void
    {
        $db = Database::getInstance()->getConnection();
        if (!$db) {
            throw new \RuntimeException('Database connection unavailable.');
        }

        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $callback($db);
            if ($startedTransaction) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function summarizeSettingValueForAudit(string $key, string $value): string
    {
        if (
            str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'api_key')
        ) {
            return $value === '' ? '[not configured]' : '[configured secret]';
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '1') {
            return '[enabled]';
        }
        if ($normalized === '0') {
            return '[disabled]';
        }

        return $value;
    }

    private function captureSettingSnapshot(array $settings): array
    {
        $snapshot = [];
        foreach ($settings as $key => $mode) {
            if (is_int($key)) {
                $key = (string)$mode;
                $mode = 'plain';
            }

            $value = $mode === 'encrypted'
                ? (string)Setting::getEncrypted((string)$key, '')
                : (string)Setting::get((string)$key, '');
            $snapshot[(string)$key] = $this->summarizeSettingValueForAudit((string)$key, $value);
        }

        return $snapshot;
    }

    private function logSecurityConfigChange(string $section, array $before, array $after): void
    {
        $changedKeys = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changedKeys[] = (string)$key;
            }
        }

        if ($changedKeys === []) {
            return;
        }

        $beforeChanged = [];
        $afterChanged = [];
        foreach ($changedKeys as $key) {
            $beforeChanged[$key] = $before[$key] ?? null;
            $afterChanged[$key] = $after[$key] ?? null;
        }

        StaffActivityService::log(
            'config_updated',
            'config',
            null,
            'Updated security ' . $section . ' settings.',
            [
                'section' => 'security ' . $section,
                'changed_keys' => $changedKeys,
                'before' => $beforeChanged,
                'after' => $afterChanged,
            ]
        );
    }

    private function requireAccess(): void
    {
        Auth::requireCapability('configuration.manage');
    }

    private function queueConfigSuccess(string $message): void
    {
        $_SESSION['config_success'] = true;
        $_SESSION['config_success_message'] = $message;
    }

    private function queueConfigError(string $message): void
    {
        $_SESSION['config_errors'] = [$message];
    }

    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    private function ensureDemoAdminReadOnly(): void
    {
        if (!DemoModeService::currentViewerIsDemoAdmin()) {
            return;
        }

        $this->queueConfigError('This demo admin account is read-only while demo mode is enabled.');
        header('Location: /admin/configuration?tab=security');
        exit;
    }

    public function migrateEncryption() {
        $this->requireAccess();
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF Mismatch');
        }

        $service = new \App\Service\Migration\EncryptionMigrationService();
        $service->expandColumns();
        $results = $service->encryptLegacyData();

        $this->queueConfigSuccess("Successfully migrated {$results['migrated']} items to encrypted format.");
        if ($results['errors'] > 0) {
            $detailParts = [];
            foreach (($results['error_details'] ?? []) as $detail) {
                $pkParts = [];
                foreach (($detail['primary_keys'] ?? []) as $pk => $value) {
                    $pkParts[] = $pk . '=' . (string)$value;
                }
                $detailParts[] = ($detail['table'] ?? '?') . '.' . ($detail['column'] ?? '?')
                    . (!empty($pkParts) ? ' (' . implode(', ', $pkParts) . ')' : '')
                    . ': ' . ($detail['error'] ?? 'Unknown error');
            }

            if (empty($detailParts) && !empty($results['pending_samples'])) {
                foreach ($results['pending_samples'] as $sample) {
                    $pkParts = [];
                    foreach (($sample['primary_keys'] ?? []) as $pk => $value) {
                        $pkParts[] = $pk . '=' . (string)$value;
                    }
                    $detailParts[] = ($sample['table'] ?? '?') . '.' . ($sample['column'] ?? '?')
                        . (!empty($pkParts) ? ' (' . implode(', ', $pkParts) . ')' : '');
                }
            }

            $this->queueConfigError(
                "Encountered {$results['errors']} errors during migration."
                . (!empty($detailParts) ? ' Example: ' . implode(' | ', array_slice($detailParts, 0, 3)) : '')
            );
        }

        header('Location: /admin/configuration?tab=security&sec_tab=migration');
    }

    /**
     * @throws \Exception
     */
    public function syncSchema() {
        $this->requireAccess();
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF Mismatch');
        }

        $repairDrift = isset($_POST['repair_drift']) && $_POST['repair_drift'] === '1';
        $service = new SchemaService();
        $results = $repairDrift
            ? SchemaService::withRepairWindow(static fn() => $service->sync(true))
            : $service->sync(false);

        if ($results['success']) {
            $msg = "Database schema synchronized successfully!" . ($repairDrift ? " (Deep Repair engaged)" : "");
            $legacyPackageBandwidthRepairs = 0;
            $legacyPackageBandwidthRepairError = null;

            try {
                $legacyPackageBandwidthRepairs = \App\Model\Package::repairLegacySystemPackageBandwidthDefaults(\App\Core\Database::getInstance()->getConnection());
            } catch (\Throwable $e) {
                $legacyPackageBandwidthRepairError = $e->getMessage();
            }

            if ($legacyPackageBandwidthRepairs > 0) {
                $msg .= ' Corrected ' . $legacyPackageBandwidthRepairs . ' legacy default package bandwidth limit' . ($legacyPackageBandwidthRepairs === 1 ? '' : 's') . '.';
            }

            // Clear the drift flag atomically so a late write failure does not leave mixed health state behind.
            $this->runSecurityWriteTransaction(static function (): void {
                Setting::set('db_drift_detected', '0', 'system');
                Setting::set('db_drift_error', '', 'system');
            });

            // Proactive Check: Do we need an encryption migration now?
            $migrationService = new \App\Service\Migration\EncryptionMigrationService();
            $pendingCount = $migrationService->getPendingCount();
            if ($pendingCount > 0) {
                $msg .= " Notice: You have $pendingCount items pending encryption. Visit Security > Migration to secure that data.";
            }

            $this->queueConfigSuccess($msg);
            if ($legacyPackageBandwidthRepairError !== null) {
                $this->queueConfigError('Schema sync succeeded, but the legacy default package bandwidth repair could not finish: ' . $legacyPackageBandwidthRepairError);
            }
            $_SESSION['sync_logs'] = $results['logs'];
        } else {
            $_SESSION['sync_logs'] = $results['logs'] ?? [];
            $this->queueConfigError("Schema sync failed: " . $results['error']);
        }

        header('Location: /admin/configuration?tab=security&sec_tab=health');
    }

    public function repairLegacyJsonDrift() {
        $this->requireAccess();
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF Mismatch');
        }

        $pdo = Database::getInstance()->getConnection();
        if (!$pdo) {
            $this->queueConfigError('Legacy JSON repair could not start because the database connection is unavailable.');
            header('Location: /admin/configuration?tab=security&sec_tab=health');
            return;
        }

        try {
            $repairService = new ManualJsonColumnMigrationService($pdo);
            $repairResults = $repairService->migrate();
            $_SESSION['sync_logs'] = $repairResults['logs'] ?? [];

            $schemaService = new SchemaService($pdo);
            $schemaResults = $schemaService->sync(false);
            $_SESSION['sync_logs'] = array_merge(
                $repairResults['logs'] ?? [],
                $schemaResults['logs'] ?? []
            );

            if (!empty($schemaResults['success'])) {
                $this->runSecurityWriteTransaction(static function (): void {
                    Setting::set('db_drift_detected', '0', 'system');
                    Setting::set('db_drift_error', '', 'system');
                });
                $this->queueConfigSuccess('Legacy JSON columns were repaired and schema validation now passes.');
            } else {
                $this->queueConfigSuccess('Legacy JSON columns were repaired. Fyuhls then re-ran normal schema validation.');
                $this->queueConfigError('Schema sync still reports remaining drift: ' . (string)($schemaResults['error'] ?? 'Unknown schema validation error.'));
            }
        } catch (\Throwable $e) {
            $this->queueConfigError('Legacy JSON repair failed: ' . $e->getMessage());
        }

        header('Location: /admin/configuration?tab=security&sec_tab=health');
    }


    public function updateSettings() {
        $this->requireAccess();
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF Mismatch');
        }

        $activeTab = $_GET['tab'] ?? 'cloudflare';

        try {
            if ($activeTab === 'cloudflare') {
                $settingKeys = ['trust_cloudflare', 'trust_loopback_proxy_headers'];
                $before = $this->captureSettingSnapshot($settingKeys);
                $this->runSecurityWriteTransaction(static function (): void {
                    Setting::set('trust_cloudflare', isset($_POST['trust_cloudflare']) ? '1' : '0', 'security');
                    Setting::set('trust_loopback_proxy_headers', isset($_POST['trust_loopback_proxy_headers']) ? '1' : '0', 'security');
                });
                $this->logSecurityConfigChange('cloudflare', $before, $this->captureSettingSnapshot($settingKeys));
            }

            if ($activeTab === 'identity') {
                $settingKeys = [
                    'vpn_proxy_mode',
                    'block_vpn_traffic',
                    'vpn_proxy_scope',
                    'proxycheck_api_key' => 'encrypted',
                    'vpn_whitelist',
                    'rate_limit_login',
                    'rate_limit_registration',
                ];
                $before = $this->captureSettingSnapshot($settingKeys);
                $mode = strtolower(trim((string)($_POST['vpn_proxy_mode'] ?? 'none')));
                if (!in_array($mode, ['none', 'enforcement', 'intelligence'], true)) {
                    $mode = 'none';
                }

                $apiKey = trim((string)($_POST['proxycheck_api_key'] ?? ''));
                if ($apiKey === '') {
                    $mode = 'none';
                }

                $scope = strtolower(trim((string)($_POST['vpn_proxy_scope'] ?? 'all_pages')));
                if (!in_array($scope, ['all_pages', 'download_pages'], true)) {
                    $scope = 'all_pages';
                }

                // Brute Force Limits - ensure we don't save 0 if missing from POST
                $loginLimit = isset($_POST['rate_limit_login']) ? (int)$_POST['rate_limit_login'] : 5;
                $regLimit = isset($_POST['rate_limit_registration']) ? (int)$_POST['rate_limit_registration'] : 5;

                $this->runSecurityWriteTransaction(static function () use ($mode, $scope, $apiKey, $loginLimit, $regLimit): void {
                    Setting::set('vpn_proxy_mode', $mode, 'security');
                    Setting::set('block_vpn_traffic', $mode === 'enforcement' ? '1' : '0', 'security');
                    Setting::set('vpn_proxy_scope', $scope, 'security');
                    Setting::setEncrypted('proxycheck_api_key', $apiKey, 'security');
                    Setting::set('vpn_whitelist', trim($_POST['vpn_whitelist'] ?? ''), 'security');
                    Setting::set('rate_limit_login', (string)($loginLimit > 0 ? $loginLimit : 5), 'security');
                    Setting::set('rate_limit_registration', (string)($regLimit > 0 ? $regLimit : 5), 'security');
                });
                $this->logSecurityConfigChange('identity', $before, $this->captureSettingSnapshot($settingKeys));
            }
        } catch (\RuntimeException $e) {
            Logger::error('Security settings save failed', [
                'tab' => $activeTab,
                'error' => $e->getMessage(),
            ]);
            $this->queueConfigError('Security settings could not be saved. Review the form values and try again.');
            header('Location: /admin/configuration?tab=security&sec_tab=' . $activeTab);
            exit;
        }

        $this->queueConfigSuccess('Security settings updated.');
        header('Location: /admin/configuration?tab=security&sec_tab=' . $activeTab);
    }

    /**
     * @throws \Exception
     */
    public function syncCloudflare() {
        $this->requireAccess();
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, 'CSRF Mismatch');
        }

        $sync = new CloudflareSyncService();
        if ($sync->sync()) {
            $this->queueConfigSuccess('Cloudflare IP ranges synced successfully.');
        } else {
            $this->queueConfigError('Failed to sync Cloudflare IPs. Check logs.');
        }

        header('Location: /admin/configuration?tab=security&sec_tab=cloudflare');
    }
}
