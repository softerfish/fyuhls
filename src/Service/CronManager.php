<?php

namespace App\Service;

use App\Core\Database;
use App\Model\Setting;
use App\Service\Database\SchemaService;
use PDO;
use Exception;
use Throwable;

class CronManager {

    private array $tasks = [];
    private array $registeredPluginTasks = [];
    private string $lockFile;
    private string $updateLockFile;

    // truth list for core tasks
    private const CORE_TASKS = [
        'cleanup'           => ['File & Cache Cleanup', 15],
        'cf_sync'           => ['Cloudflare IP Sync', 1440],
        'rl_purge'          => ['Rate Limit Log Purge', 1440],
        'account_downgrade' => ['Premium Expiry & Downgrade', 60],
        'account_expiry'    => ['Premium Expiry Reminder Emails', 1440],
        'server_monitoring' => ['Storage Node Health Check', 60],
        'mail_queue'        => ['Background Email Worker', 1],
        'payment_gateway_sync' => ['Payment Gateway Sync Retry Queue', 1],
        'payment_cleanup'   => ['Stale Pending Payment Cleanup', 60],
        'reward_flush'      => ['Reward Queue Flush', 1],
        'reward_rollup'     => ['Reward History Rollup', 1440],
        'fraud_scores'      => ['Rewards Fraud Score Refresh', 15],
        'fraud_clearance'   => ['Rewards Hold Clearance', 15],
        'fraud_cleanup'     => ['Rewards Fraud Log Cleanup', 1440],
        'db_health'         => ['Database Schema Health Check', 1440],
        'log_purge'         => ['Application Log Rotation', 1440],
        'file_purge'        => ['Background File Purge', 15],
        'storage_audit'     => ['Storage Usage Audit', 60],
        'security_purge'    => ['Security Cache Cleanup', 1440],
        'refresh_stats'     => ['Dashboard Statistics Refresh', 15],
        'remote_uploads'    => ['Background Remote URL Uploads', 1],
        'nginx_download_logs' => ['Nginx Download Log Ingestion', 1],
        'upload_sessions'   => ['Multipart Upload Session Cleanup', 10],
        'upload_reconcile'  => ['Multipart Upload Reconciliation', 15],
        'checksum_jobs'     => ['Checksum Verification Jobs', 15]
    ];

    public function __construct(?string $projectRoot = null) {
        $root = $projectRoot !== null ? rtrim($projectRoot, '/\\') : dirname(__DIR__, 2);
        $this->lockFile = $root . '/storage/cron.lock';
        $this->updateLockFile = $root . '/storage/cache/update.lock';
    }

    /**
     * Define a task to be executed
     */
    public function register(string $key, callable $callback): void {
        $this->tasks[$key] = $callback;
    }

    /**
     * Register a task specifically from a plugin
     */
    public function registerPluginTask(string $key, string $name, int $defaultInterval, string $pluginDir): void {
        $this->registeredPluginTasks[$key] = [
            'name' => $name,
            'interval' => $defaultInterval,
            'plugin' => $pluginDir
        ];
    }

    /**
     * Sync database table with code-defined tasks (Self-Healing)
     */
    public function sync(): void {
        $db = Database::getInstance()->getConnection();
        if (!$db) return;

        $this->ensureTableExists();

        // add/update core tasks
        foreach (self::CORE_TASKS as $key => [$name, $interval]) {
            $stmt = $db->prepare("INSERT IGNORE INTO cron_tasks (task_key, task_name, interval_mins, plugin_dir) VALUES (?, ?, ?, NULL)");
            $stmt->execute([$key, $name, $interval]);
        }

        // add/update plugin tasks
        foreach ($this->registeredPluginTasks as $key => $data) {
            $stmt = $db->prepare("INSERT IGNORE INTO cron_tasks (task_key, task_name, interval_mins, plugin_dir) VALUES (?, ?, ?, ?)");
            $stmt->execute([$key, $data['name'], $data['interval'], $data['plugin']]);
        }

        // remove orphaned tasks (not in core and not in active plugins)
        $activePlugins = [];
        $stmt = $db->query("SELECT directory FROM plugins WHERE is_active = 1");
        while($row = $stmt->fetch()) { $activePlugins[] = $row['directory']; }

        $hasPluginRegistrations = !empty($this->registeredPluginTasks);
        $stmt = $db->query("SELECT task_key, plugin_dir FROM cron_tasks");
        while ($row = $stmt->fetch()) {
            $key = $row['task_key'];
            $plugin = $row['plugin_dir'];

            $isCore = isset(self::CORE_TASKS[$key]);
            $isPluginTask = !empty($plugin);
            $isActivePlugin = $isPluginTask && in_array($plugin, $activePlugins, true);
            $isRegisteredPluginTask = isset($this->registeredPluginTasks[$key]);

            if ($isCore) {
                continue;
            }

            if ($isPluginTask) {
                if (!$isActivePlugin || ($hasPluginRegistrations && !$isRegisteredPluginTask)) {
                    $db->prepare("DELETE FROM cron_tasks WHERE task_key = ?")->execute([$key]);
                }
                continue;
            }

            if (!$isPluginTask) {
                $db->prepare("DELETE FROM cron_tasks WHERE task_key = ?")->execute([$key]);
            }
        }
    }

    /**
     * Main execution loop
     *
     * @throws Exception
     */
    public function run(bool $ignoreFrequency = false): array {
        if ($this->updateLockIsHeld()) {
            return ['status' => 'skipped', 'message' => 'An application update is running on this node.'];
        }

        $db = Database::getInstance()->getConnection();
        if (!$db) throw new Exception("Database connection unavailable.");
        $this->ensureTableExists();

        // Global overlap prevention (File lock for this local node)
        $fp = fopen($this->lockFile, 'c+');
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return ['status' => 'skipped', 'message' => 'Cron already running on this node.'];
        }

        $results = [];
        $stmt = $db->query("SELECT * FROM cron_tasks");
        $dbTasks = $stmt->fetchAll();

        foreach ($dbTasks as $task) {
            $key = $task['task_key'];

            // Skip if logic not registered in this run
            if (!isset($this->tasks[$key])) continue;

            // 1. Check frequency
            $lastRun = $task['last_run_at'] ? strtotime($task['last_run_at']) : 0;
            if (!$ignoreFrequency && (time() - $lastRun) < ($task['interval_mins'] * 60)) continue;

            // 2. ATOMIC DISTRIBUTED LOCK (Multi-Server Support)
            // Attempt to claim this task. Only one server in the cluster will succeed.
            // Timeout lock after 1 hour in case of a crash.
            if (!$this->claimTaskLock($db, $key, $ignoreFrequency)) {
                $results[$key] = 'skipped (locked by another node)';
                continue;
            }

            // Execute
            $start = microtime(true);
            try {
                $output = call_user_func($this->tasks[$key]);
                $status = 'success';
                $error = is_array($output) ? json_encode($output) : null;
            } catch (Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
            }
            $duration = microtime(true) - $start;

            // 3. Update DB & RELEASE LOCK
            $upd = $db->prepare("UPDATE cron_tasks SET last_run_at = NOW(), last_status = ?, last_error = ?, execution_time = ?, locked_at = NULL WHERE task_key = ?");
            $upd->execute([$status, $error, $duration, $key]);

            $results[$key] = $status;
        }

        // Update global heartbeat
        Setting::set('last_cron_run_timestamp', time());

        flock($fp, LOCK_UN);
        fclose($fp);
        return $results;
    }

    private function updateLockIsHeld(): bool
    {
        if (!is_file($this->updateLockFile)) {
            return false;
        }

        $handle = @fopen($this->updateLockFile, 'c+');
        if ($handle === false) {
            return true;
        }
        if (!@flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            return true;
        }

        @flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }

    public function ensureTableExists(): void {
        SchemaService::withRepairWindow(static function (): void {
            SchemaService::ensureTables(['cron_tasks'], true);
        });
    }

    private function claimTaskLock(PDO $db, string $taskKey, bool $ignoreFrequency): bool
    {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "
                UPDATE cron_tasks
                SET locked_at = CURRENT_TIMESTAMP
                WHERE task_key = ?
                  AND (
                        locked_at IS NULL
                        OR datetime(locked_at) < datetime('now', '-1 hour')
                      )
            ";

            if (!$ignoreFrequency) {
                $sql .= "
                  AND (
                        last_run_at IS NULL
                        OR datetime(last_run_at) <= datetime('now', '-' || interval_mins || ' minutes')
                      )
                ";
            }
        } else {
            $sql = "
                UPDATE cron_tasks
                SET locked_at = NOW()
                WHERE task_key = ?
                  AND (
                        locked_at IS NULL
                        OR locked_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                      )
            ";

            if (!$ignoreFrequency) {
                $sql .= "
                  AND (
                        last_run_at IS NULL
                        OR last_run_at <= DATE_SUB(NOW(), INTERVAL interval_mins MINUTE)
                      )
                ";
            }
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([$taskKey]);

        return $stmt->rowCount() === 1;
    }
}
