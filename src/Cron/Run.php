<?php
/**
 * Command Line Cron Task Entry Point
 * Usage: * * * * * php /path/to/src/Cron/Run.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\App;
use App\Service\CronManager;
use App\Service\CleanupService;
use App\Service\CloudflareSyncService;
use App\Service\RateLimiterService;
use App\Core\Config;
use App\Service\EncryptionService;
use App\Service\ConfigPointerService;
use App\Service\InstallSecurityService;

try {
    if (getenv('FYUHLS_CRON_THROWABLE_TEST') === '1') {
        throw new TypeError('Injected cron throwable test.');
    }

    $app = new App();

    // Environment-Agnostic Path Detection
    $rootDir = realpath(__DIR__ . '/../../');

    Config::load($rootDir . '/config/app.php');
    $dbConfigPath = $rootDir . '/config/database.php';

    if (!file_exists($dbConfigPath) && InstallSecurityService::hasInstalledMarker($rootDir)) {
        throw new RuntimeException('Cron bootstrap halted because config/database.php is missing. Restore the config pointer to the hidden config file before running scheduled tasks.');
    }

    if (file_exists($dbConfigPath)) {
        Config::loadArray(ConfigPointerService::loadLinkedConfigArray($dbConfigPath));
        // Initialize Encryption for CLI context
        $encryptionKey = Config::get('security.encryption_key', '');
        EncryptionService::setKey($encryptionKey);
    }

    if (\App\Core\Database::getInstance()->getConnection() === null) {
        $error = \App\Core\Database::getLastConnectionError() ?? 'The database connection could not be initialized.';
        throw new RuntimeException('Cron bootstrap halted because the database connection failed. Verify the hidden config database credentials and server availability. Details: ' . $error);
    }

    $manager = new CronManager();
    $manager->ensureTableExists();
    $manager->sync();

    // 1. File & Cache Cleanup
    $manager->register('cleanup', function() {
        $cleanup = new CleanupService();
        return $cleanup->runExpiredCleanup();
    });

    // 2. Cloudflare Security Sync
    $manager->register('cf_sync', function() {
        $cfSync = new CloudflareSyncService();
        return $cfSync->sync();
    });

    // 3. Rate Limit Log Purge
    $manager->register('rl_purge', function() {
        return RateLimiterService::cleanup(86400);
    });

    // 4. Premium Expiry & Downgrade
    $manager->register('account_downgrade', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->downgradeExpiredAccounts();
    });

    // 4b. Premium Expiry Reminders
    $manager->register('account_expiry', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->sendExpiryReminders();
    });

    // 5. Storage Node Health Check
    $manager->register('server_monitoring', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->monitorServerHealth();
    });

    // 6. Background Email Worker
    $manager->register('mail_queue', function() {
        return \App\Service\MailQueueService::processBatch();
    });

    // 6b. Deferred payment gateway synchronization
    $manager->register('payment_gateway_sync', function() {
        return \App\Service\PaymentService::processGatewaySyncQueue(25);
    });

    // 6c. Stale Pending Payment Cleanup
    $manager->register('payment_cleanup', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->cleanupStalePendingPayments(1440);
    });

    if (\App\Service\FeatureService::rewardsEnabled()) {
        // 7. Reward Processing (Enterprise Buffer & Flush)
        $manager->register('reward_flush', function() {
            $rewards = new \App\Service\RewardService();
            return $rewards->flushQueue(1000);
        });

        // 8. Rewards Data Rollup (Archive History)
        $manager->register('reward_rollup', function() {
            $rewards = new \App\Service\RewardService();
            return ['rolled_up' => $rewards->rollupHistory(\App\Service\RewardService::retentionDays())];
        });

        // 8b. Rewards Fraud background maintenance
        $manager->register('fraud_scores', function() {
            $fraud = new \App\Service\RewardFraudService();
            return ['recomputed' => $fraud->recomputeAccountScores()];
        });

        $manager->register('fraud_clearance', function() {
            $fraud = new \App\Service\RewardFraudService();
            return ['cleared' => $fraud->clearHeldEarnings()];
        });

        $manager->register('fraud_cleanup', function() {
            $fraud = new \App\Service\RewardFraudService();
            return ['purged' => $fraud->purgeOldEventData()];
        });
    }

    // 9. Database Schema Health Check
    $manager->register('db_health', function() {
        $schema = new \App\Service\Database\SchemaService();
        return $schema->sync(false);
    });

    // 10. Log Rotation & Pruning
    $manager->register('log_purge', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->purgeOldLogs();
    });

    // 11. Background File Purge
    $manager->register('file_purge', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->processFilePurgeQueue(50);
    });

    // 12. Storage Quota Audit
    $manager->register('storage_audit', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->auditUserStorage(5);
    });

    // 13. Security Cache Purge
    $manager->register('security_purge', function() {
        $security = new \App\Service\SecurityService();
        return ['purged' => $security->purgeCache(30)];
    });

    // 14. Dashboard Statistics Refresher
    $manager->register('refresh_stats', function() {
        $service = new \App\Service\DashboardService();
        $service->refreshSystemStats();

        // Cleanup old history (default 30 days)
        $retention = (int)\App\Model\Setting::get('stats_history_retention_days', 30);
        $purged = $service->purgeOldHistory($retention);

        return ['status' => 'updated', 'purged' => $purged];
    });

    // 15. Background Remote URL Uploads
    $manager->register('remote_uploads', function() {
        $auto = new \App\Service\AutomatedTaskService();
        return $auto->processRemoteUploadQueue(5);
    });

    // 16. Nginx Download Completion Log Ingestion
    $manager->register('nginx_download_logs', function() {
        $service = new \App\Service\NginxDownloadLogService();
        return $service->process();
    });

    // 17. Multipart Upload Session Cleanup
    $manager->register('upload_sessions', function() {
        $service = new \App\Service\MultipartUploadService();
        return [
            'sessions' => $service->expireStaleSessions(200),
            'reservations' => $service->releaseExpiredReservations(200),
        ];
    });

    // 18. Multipart Upload Reconciliation
    $manager->register('upload_reconcile', function() {
        $service = new \App\Service\MultipartUploadService();
        return $service->reconcileActiveSessions(100);
    });

    // 19. Completed Upload Checksum Marking
    $manager->register('checksum_jobs', function() {
        $service = new \App\Service\MultipartUploadService();
        return $service->reconcileCompletedChecksums(200);
    });

    // Run all registered tasks
    $results = $manager->run();

    if (isset($results['error'])) {
        echo "[Cron SKIP] " . $results['error'] . "\n";
    } else {
        $summary = [];
        foreach ($results as $key => $status) {
            $summary[] = strtoupper($key) . ": " . $status;
        }
        echo "[Cron SUCCESS] " . implode(' | ', $summary) . "\n";
    }

} catch (\Throwable $e) {
    echo "[Cron ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
