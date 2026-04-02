<?php

namespace App\Controller\Admin;

use App\Core\Auth;
use App\Core\View;
use App\Core\Csrf;
use App\Core\Logger;
use App\Model\Setting;
use App\Service\CloudflareSyncService;
use App\Service\Database\SchemaService;
use App\Service\DemoModeService;
use App\Core\Database;

class SecurityController {

    private function ensureDemoAdminReadOnly(): void
    {
        if (!DemoModeService::currentViewerIsDemoAdmin()) {
            return;
        }

        $_SESSION['error'] = 'This demo admin account is read-only while demo mode is enabled.';
        header('Location: /admin/configuration?tab=security');
        exit;
    }
    
    public function migrateEncryption() {
        if (!Auth::isAdmin()) die('Unauthorized');
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die('CSRF Mismatch');

        $service = new \App\Service\Migration\EncryptionMigrationService();
        $service->expandColumns();
        $results = $service->encryptLegacyData();

        $_SESSION['success'] = "Successfully migrated {$results['migrated']} items to encrypted format.";
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

            $_SESSION['error'] = "Encountered {$results['errors']} errors during migration."
                . (!empty($detailParts) ? ' Example: ' . implode(' | ', array_slice($detailParts, 0, 3)) : '');
        }

        header('Location: /admin/configuration?tab=security&sec_tab=migration');
    }

    /**
     * @throws \Exception
     */
    public function syncSchema() {
        if (!Auth::isAdmin()) die('Unauthorized');
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die('CSRF Mismatch');

        $repairDrift = isset($_POST['repair_drift']) && $_POST['repair_drift'] === '1';
        $service = new SchemaService();
        $results = $service->sync($repairDrift);

        if ($results['success']) {
            $msg = "Database schema synchronized successfully!" . ($repairDrift ? " (Deep Repair engaged)" : "");
            
            // Clear the drift flag
            Setting::set('db_drift_detected', '0', 'system');
            Setting::set('db_drift_error', '', 'system');

            // Proactive Check: Do we need an encryption migration now?
            $migrationService = new \App\Service\Migration\EncryptionMigrationService();
            $pendingCount = $migrationService->getPendingCount();
            if ($pendingCount > 0) {
                $msg .= " <br><strong>Notice:</strong> You have $pendingCount items pending encryption. Please visit the <a href='?tab=security&sec_tab=migration'>Encryption Migration</a> tab to secure your data.";
            }
            
            $_SESSION['success'] = $msg;
            $_SESSION['sync_logs'] = $results['logs'];
        } else {
            $_SESSION['error'] = "Schema sync failed: " . $results['error'];
        }

        header('Location: /admin/configuration?tab=security&sec_tab=health');
    }


    public function updateSettings() {
        if (!Auth::isAdmin()) die('Unauthorized');
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die('CSRF Mismatch');

        $activeTab = $_GET['tab'] ?? 'cloudflare';

        try {
            if ($activeTab === 'cloudflare') {
                Setting::set('trust_cloudflare', isset($_POST['trust_cloudflare']) ? '1' : '0', 'security');
            }

            if ($activeTab === 'identity') {
                $mode = (string)($_POST['vpn_proxy_mode'] ?? 'enforcement');
                if (!in_array($mode, ['enforcement', 'intelligence'], true)) {
                    $mode = 'enforcement';
                }

                Setting::set('vpn_proxy_mode', $mode, 'security');
                Setting::set('block_vpn_traffic', $mode === 'enforcement' ? '1' : '0', 'security');
                Setting::setEncrypted('proxycheck_api_key', trim($_POST['proxycheck_api_key'] ?? ''), 'security');
                Setting::set('vpn_whitelist', trim($_POST['vpn_whitelist'] ?? ''), 'security');

                // Brute Force Limits - ensure we don't save 0 if missing from POST
                $loginLimit = isset($_POST['rate_limit_login']) ? (int)$_POST['rate_limit_login'] : 5;
                $regLimit = isset($_POST['rate_limit_registration']) ? (int)$_POST['rate_limit_registration'] : 5;
                
                Setting::set('rate_limit_login', (string)($loginLimit > 0 ? $loginLimit : 5), 'security');
                Setting::set('rate_limit_registration', (string)($regLimit > 0 ? $regLimit : 5), 'security');
            }
        } catch (\RuntimeException $e) {
            Logger::error('Security settings save failed', [
                'tab' => $activeTab,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Security settings could not be saved. Review the form values and try again.';
            header('Location: /admin/configuration?tab=security&sec_tab=' . $activeTab);
            exit;
        }

        $_SESSION['success'] = "Security settings updated.";
        header('Location: /admin/configuration?tab=security&sec_tab=' . $activeTab);
    }

    /**
     * @throws \Exception
     */
    public function syncCloudflare() {
        if (!Auth::isAdmin()) die('Unauthorized');
        $this->ensureDemoAdminReadOnly();
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) die('CSRF Mismatch');

        $sync = new CloudflareSyncService();
        if ($sync->sync()) {
            $_SESSION['success'] = "Cloudflare IP ranges synced successfully.";
        } else {
            $_SESSION['error'] = "Failed to sync Cloudflare IPs. Check logs.";
        }

        header('Location: /admin/configuration?tab=security&sec_tab=cloudflare');
    }
}
