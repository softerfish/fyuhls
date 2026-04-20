<?php

namespace App\Service;

use App\Core\Database;
use App\Core\PluginManager;
use App\Model\Setting;
use PDO;

class DashboardService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->ensureStatsSchema();
    }

    /**
     * Get the consolidated dashboard stats bundle.
     * Uses cached system_stats with a live fallback if the cache is stale or missing.
     */
    public function getStatsBundle(): array
    {
        $stats = $this->getSystemStats();
        $history = $this->getStatsHistory(30);
        
        return [
            'stats' => $stats,
            'history' => $history,
            'cron_healthy' => $this->isCronHealthy(),
            'last_cron_run' => Setting::get('last_cron_run_timestamp', 0),
            'widgets' => $this->getWidgetData(),
        ];
    }

    /**
     * Retrieve global totals from system_stats.
     * Fallback: If table is empty or cron hasn't run, calculate live and cache for 60s.
     */
    private function getSystemStats(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM system_stats WHERE id = 1");
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($stats) {
                $liveTotalFiles = (int)$this->db->query("SELECT COUNT(*) FROM files WHERE status = 'active'")->fetchColumn();
                if ((int)($stats['total_files'] ?? 0) !== $liveTotalFiles) {
                    $this->refreshSystemStats();
                    $stmt = $this->db->query("SELECT * FROM system_stats WHERE id = 1");
                    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats;
                }
                return $stats;
            }
        } catch (\Exception $e) {
            // Table might not exist yet; proceed to live fallback
        }

        // --- GHOST STATS FALLBACK (Anti-Stampede 60s Cache) ---
        $cacheFile = defined('BASE_PATH') ? BASE_PATH . '/storage/cache/dashboard_fallback.json' : dirname(__DIR__, 2) . '/storage/cache/dashboard_fallback.json';
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) return $cached;
        }

        // Try to get a lock so only one request calculates the live stats
        $lockFile = $cacheFile . '.lock';
        $fp = @fopen($lockFile, 'w');
        if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
            // We got the lock, calculate live
            $liveStats = $this->calculateLiveStats();
            
            // Ensure directory exists
            if (!is_dir(dirname($cacheFile))) {
                @mkdir(dirname($cacheFile), 0755, true);
            }
            
            @file_put_contents($cacheFile, json_encode($liveStats));
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
            
            return $liveStats;
        } else {
            // Couldn't get lock, meaning someone else is calculating it right now.
            // Wait up to 3 seconds for them to finish.
            if ($fp) fclose($fp);
            $waited = 0;
            while ($waited < 30) { // 30 * 100ms = 3s
                usleep(100000);
                if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
                    $cached = json_decode(file_get_contents($cacheFile), true);
                    if ($cached) return $cached;
                }
                $waited++;
            }
            
            // If all else fails (extreme edge case), return a 0'd out shell
            return [
                'total_files' => 0, 'total_users' => 0, 'total_storage_bytes' => 0,
                'pending_withdrawals' => 0, 'pending_reports' => 0, 'is_live' => true
            ];
        }
    }

    public function calculateLiveStats(): array
    {
        try {
            $totalUsers = (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $totalFiles = (int)$this->db->query("SELECT COUNT(*) FROM files WHERE status = 'active'")->fetchColumn();
            
            // Check if stored_files exists before joining
            $totalStorage = 0;
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'stored_files'")->fetch();
            if ($tableCheck) {
                $totalStorage = (float)$this->db->query("
                    SELECT SUM(sf.file_size) 
                    FROM files f 
                    JOIN stored_files sf ON f.stored_file_id = sf.id 
                    WHERE f.status = 'active'
                ")->fetchColumn();
            }

            $pendingWithdrawals = 0;
            // Check if withdrawals table exists before querying it.
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'withdrawals'")->fetch();
            if ($tableCheck) {
                $pendingWithdrawals = (int)$this->db->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
            }

            $pendingReports = 0;
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'abuse_reports'")->fetch();
            if ($tableCheck) {
                $pendingReports = (int)$this->db->query("SELECT COUNT(*) FROM abuse_reports WHERE status = 'pending'")->fetchColumn();
            }
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'dmca_reports'")->fetch();
            if ($tableCheck) {
                $pendingReports += (int)$this->db->query("SELECT COUNT(*) FROM dmca_reports WHERE status = 'pending'")->fetchColumn();
            }

            return [
                'total_files' => $totalFiles,
                'total_users' => $totalUsers,
                'total_storage_bytes' => $totalStorage,
                'pending_withdrawals' => $pendingWithdrawals,
                'pending_reports' => $pendingReports,
                'is_live' => true 
            ];
        } catch (\Exception $e) {
            return [
                'total_files' => 0, 'total_users' => 0, 'total_storage_bytes' => 0,
                'pending_withdrawals' => 0, 'pending_reports' => 0, 'is_live' => true
            ];
        }
    }

    /**
     * Update the system_stats table with fresh data (called by Cron)
     */
    public function refreshSystemStats(): void
    {
        $this->ensureStatsSchema();
        $live = $this->calculateLiveStats();
        
        $sql = "INSERT INTO system_stats (id, total_files, total_users, total_storage_bytes, pending_withdrawals, pending_reports)
                VALUES (1, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                total_files = VALUES(total_files),
                total_users = VALUES(total_users),
                total_storage_bytes = VALUES(total_storage_bytes),
                pending_withdrawals = VALUES(pending_withdrawals),
                pending_reports = VALUES(pending_reports),
                last_updated = CURRENT_TIMESTAMP";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $live['total_files'],
            $live['total_users'],
            $live['total_storage_bytes'],
            $live['pending_withdrawals'],
            $live['pending_reports']
        ]);

        // Also take a daily snapshot if not already taken today
        $this->takeDailySnapshot($live);
    }

    private function takeDailySnapshot(array $stats): void
    {
        $today = date('Y-m-d');
        $startOfDay = date('Y-m-d 00:00:00');
        $endOfDay = date('Y-m-d 23:59:59');
        
        // Count today's uploads (Index-friendly range query)
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM files WHERE created_at >= ? AND created_at <= ?");
        $stmt->execute([$startOfDay, $endOfDay]);
        $uploadsToday = (int)$stmt->fetchColumn();
        
        // Count today's active download IPs (approximate)
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT ip_address) FROM active_downloads WHERE started_at >= ? AND started_at <= ?");
        $stmt->execute([$startOfDay, $endOfDay]);
        $downloadsToday = (int)$stmt->fetchColumn();

        $sql = "INSERT INTO stats_history (date, uploads_count, downloads_count, active_users)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                uploads_count = VALUES(uploads_count),
                downloads_count = VALUES(downloads_count),
                active_users = VALUES(active_users)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$today, $uploadsToday, $downloadsToday, $stats['total_users']]);
    }

    public function getStatsHistory(int $days = 30): array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM stats_history ORDER BY date DESC LIMIT ?");
            $stmt->bindValue(1, $days, PDO::PARAM_INT);
            $stmt->execute();
            return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return []; // Table doesn't exist yet
        }
    }

    public function isCronHealthy(): bool
    {
        $lastCron = (int)Setting::get('last_cron_run_timestamp', 0);
        return (time() - $lastCron) < 1860; // 31 minutes
    }

    /**
     * Clean up old stats history (Retention Policy)
     */
    public function purgeOldHistory(int $days = 30): int
    {
        $stmt = $this->db->prepare("DELETE FROM stats_history WHERE date < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    private function getWidgetData(): array
    {
        $host = (new HostService())->getMetrics();

        return [
            'revenue' => $this->getRevenueSnapshot(),
            'upload_pipeline' => $this->getUploadPipelineHealth(),
            'storage_capacity' => $this->getStorageCapacity($host),
            'moderation_queue' => $this->getModerationQueue(),
            'user_growth' => $this->getUserGrowth(),
            'email_queue' => $this->getEmailQueueHealth(),
            'security_watch' => $this->getSecurityWatch(),
            'automation' => $this->getAutomationSummary(),
            'download_mix' => $this->getDownloadDeliveryMix(),
            'file_lifecycle' => $this->getFileLifecycle(),
            'support_diagnostics' => $this->getSupportDiagnostics(),
            'top_content' => $this->getTopContent(),
            'recent_activity' => $this->getRecentActivity(),
            'host' => $host,
        ];
    }

    private function getRevenueSnapshot(): array
    {
        $data = [
            'today_earnings' => 0.0,
            'week_earnings' => 0.0,
            'month_earnings' => 0.0,
            'month_downloads' => 0,
            'effective_rpm' => 0.0,
            'pending_withdrawals' => 0,
            'pending_withdrawal_amount' => 0.0,
            'active_subscriptions' => 0,
            'completed_transactions' => 0,
        ];

        if ($this->tableExists('stats_daily')) {
            $row = $this->fetchRow("
                SELECT
                    COALESCE(SUM(CASE WHEN day = CURDATE() THEN earnings ELSE 0 END), 0) AS today_earnings,
                    COALESCE(SUM(CASE WHEN day >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN earnings ELSE 0 END), 0) AS week_earnings,
                    COALESCE(SUM(CASE WHEN day >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN earnings ELSE 0 END), 0) AS month_earnings,
                    COALESCE(SUM(CASE WHEN day >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN downloads ELSE 0 END), 0) AS month_downloads
                FROM stats_daily
            ");
            $data['today_earnings'] = (float)($row['today_earnings'] ?? 0);
            $data['week_earnings'] = (float)($row['week_earnings'] ?? 0);
            $data['month_earnings'] = (float)($row['month_earnings'] ?? 0);
            $data['month_downloads'] = (int)($row['month_downloads'] ?? 0);
            if ($data['month_downloads'] > 0) {
                $data['effective_rpm'] = round($data['month_earnings'] / ($data['month_downloads'] / 1000), 2);
            }
        }

        if ($this->tableExists('withdrawals')) {
            $row = $this->fetchRow("
                SELECT COUNT(*) AS pending_withdrawals, COALESCE(SUM(amount), 0) AS pending_withdrawal_amount
                FROM withdrawals
                WHERE status = 'pending'
            ");
            $data['pending_withdrawals'] = (int)($row['pending_withdrawals'] ?? 0);
            $data['pending_withdrawal_amount'] = (float)($row['pending_withdrawal_amount'] ?? 0);
        }

        if ($this->tableExists('subscriptions')) {
            $data['active_subscriptions'] = (int)$this->fetchValue("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
        }

        if ($this->tableExists('transactions')) {
            $data['completed_transactions'] = (int)$this->fetchValue("
                SELECT COUNT(*)
                FROM transactions
                WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
        }

        return $data;
    }

    private function getUploadPipelineHealth(): array
    {
        $data = [
            'active_sessions' => 0,
            'failed_sessions' => 0,
            'stale_sessions' => 0,
            'stuck_completing' => 0,
            'active_reservations' => 0,
            'reserved_bytes' => 0,
            'checksum_backlog' => 0,
            'pending_remote_uploads' => 0,
        ];

        if ($this->tableExists('upload_sessions')) {
            $row = $this->fetchRow("
                SELECT
                    SUM(CASE WHEN status IN ('pending', 'uploading', 'completing', 'processing') THEN 1 ELSE 0 END) AS active_sessions,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_sessions,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() AND status IN ('pending', 'uploading', 'completing', 'processing') THEN 1 ELSE 0 END) AS stale_sessions,
                    SUM(CASE WHEN status = 'completing' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) AS stuck_completing
                FROM upload_sessions
            ");
            $data['active_sessions'] = (int)($row['active_sessions'] ?? 0);
            $data['failed_sessions'] = (int)($row['failed_sessions'] ?? 0);
            $data['stale_sessions'] = (int)($row['stale_sessions'] ?? 0);
            $data['stuck_completing'] = (int)($row['stuck_completing'] ?? 0);
        }

        if ($this->tableExists('quota_reservations')) {
            $row = $this->fetchRow("
                SELECT COUNT(*) AS active_reservations, COALESCE(SUM(reserved_bytes), 0) AS reserved_bytes
                FROM quota_reservations
                WHERE status = 'active'
            ");
            $data['active_reservations'] = (int)($row['active_reservations'] ?? 0);
            $data['reserved_bytes'] = (int)($row['reserved_bytes'] ?? 0);
        }

        if ($this->tableExists('stored_files')) {
            $data['checksum_backlog'] = (int)$this->fetchValue("
                SELECT COUNT(*)
                FROM stored_files
                WHERE file_hash IS NOT NULL
                  AND (checksum_verified_at IS NULL OR checksum_verified_at = '0000-00-00 00:00:00')
            ");
        }

        if ($this->tableExists('remote_upload_queue')) {
            $data['pending_remote_uploads'] = (int)$this->fetchValue("
                SELECT COUNT(*)
                FROM remote_upload_queue
                WHERE status IN ('pending', 'processing')
            ");
        }

        return $data;
    }

    private function getStorageCapacity(array $host): array
    {
        $data = [
            'disk' => $host['disk'] ?? ['percent' => 0, 'readable_used' => '0 B', 'readable_total' => '0 B'],
            'active_servers' => 0,
            'read_only_servers' => 0,
            'nodes_over_80' => 0,
            'hottest_node' => null,
        ];

        if (!$this->tableExists('file_servers')) {
            return $data;
        }

        $servers = $this->fetchAll("
            SELECT name, status, current_usage_bytes, max_capacity_bytes
            FROM file_servers
            ORDER BY id ASC
        ");

        foreach ($servers as $server) {
            $status = (string)($server['status'] ?? '');
            if ($status === 'active') {
                $data['active_servers']++;
            }
            if ($status === 'read-only') {
                $data['read_only_servers']++;
            }

            $capacity = (int)($server['max_capacity_bytes'] ?? 0);
            $usage = (int)($server['current_usage_bytes'] ?? 0);
            $percent = $capacity > 0 ? round(($usage / $capacity) * 100, 1) : 0;
            if ($percent >= 80) {
                $data['nodes_over_80']++;
            }

            if ($data['hottest_node'] === null || $percent > $data['hottest_node']['percent']) {
                $data['hottest_node'] = [
                    'name' => (string)($server['name'] ?? 'Unknown server'),
                    'percent' => $percent,
                    'used' => $usage,
                    'capacity' => $capacity,
                ];
            }
        }

        return $data;
    }

    private function getModerationQueue(): array
    {
        return [
            'abuse_pending' => $this->tableExists('abuse_reports') ? (int)$this->fetchValue("SELECT COUNT(*) FROM abuse_reports WHERE status = 'pending'") : 0,
            'dmca_pending' => $this->tableExists('dmca_reports') ? (int)$this->fetchValue("SELECT COUNT(*) FROM dmca_reports WHERE status = 'pending'") : 0,
            'new_contacts' => $this->tableExists('contact_messages') ? (int)$this->fetchValue("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'") : 0,
            'investigating_dmca' => $this->tableExists('dmca_reports') ? (int)$this->fetchValue("SELECT COUNT(*) FROM dmca_reports WHERE status = 'investigating'") : 0,
        ];
    }

    private function getUserGrowth(): array
    {
        $data = [
            'new_today' => 0,
            'new_7d' => 0,
            'new_30d' => 0,
            'pending_verification' => 0,
            'active_premium' => 0,
            'recent_signups' => [],
        ];

        if (!$this->tableExists('users')) {
            return $data;
        }

        $row = $this->fetchRow("
            SELECT
                SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS new_today,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS new_7d,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS new_30d,
                SUM(CASE WHEN email_verified = 0 AND role = 'user' THEN 1 ELSE 0 END) AS pending_verification
            FROM users
            WHERE role <> 'guest'
        ");
        $data['new_today'] = (int)($row['new_today'] ?? 0);
        $data['new_7d'] = (int)($row['new_7d'] ?? 0);
        $data['new_30d'] = (int)($row['new_30d'] ?? 0);
        $data['pending_verification'] = (int)($row['pending_verification'] ?? 0);

        if ($this->tableExists('subscriptions')) {
            $data['active_premium'] = (int)$this->fetchValue("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");
        }

        $signups = $this->fetchAll("
            SELECT public_id, username, created_at
            FROM users
            WHERE role = 'user'
            ORDER BY created_at DESC
            LIMIT 5
        ");
        foreach ($signups as &$signup) {
            $signup['username'] = $this->decryptMaybe($signup['username'] ?? '');
        }
        $data['recent_signups'] = $signups;

        return $data;
    }

    private function getEmailQueueHealth(): array
    {
        $data = [
            'pending' => 0,
            'failed' => 0,
            'sent_24h' => 0,
            'oldest_pending_at' => null,
            'last_sent_at' => null,
        ];

        if (!$this->tableExists('mail_queue')) {
            return $data;
        }

        $row = $this->fetchRow("
            SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS sent_24h,
                MIN(CASE WHEN status = 'pending' THEN created_at ELSE NULL END) AS oldest_pending_at,
                MAX(CASE WHEN status = 'sent' THEN sent_at ELSE NULL END) AS last_sent_at
            FROM mail_queue
        ");
        $data['pending'] = (int)($row['pending_count'] ?? 0);
        $data['failed'] = (int)($row['failed_count'] ?? 0);
        $data['sent_24h'] = (int)($row['sent_24h'] ?? 0);
        $data['oldest_pending_at'] = $row['oldest_pending_at'] ?? null;
        $data['last_sent_at'] = $row['last_sent_at'] ?? null;

        return $data;
    }

    private function getSecurityWatch(): array
    {
        $data = [
            'failed_logins_24h' => 0,
            'restricted_ips_24h' => 0,
            'vpn_hits_24h' => 0,
            'recent_2fa_actions' => 0,
        ];

        if ($this->tableExists('rate_limits')) {
            $row = $this->fetchRow("
                SELECT
                    SUM(CASE WHEN action = 'login' THEN 1 ELSE 0 END) AS failed_logins,
                    COUNT(DISTINCT identifier) AS restricted_ips
                FROM rate_limits
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $data['failed_logins_24h'] = (int)($row['failed_logins'] ?? 0);
            $data['restricted_ips_24h'] = (int)($row['restricted_ips'] ?? 0);
        }

        if ($this->tableExists('security_cache')) {
            $data['vpn_hits_24h'] = (int)$this->fetchValue("
                SELECT COUNT(*)
                FROM security_cache
                WHERE is_vpn = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
        }

        if ($this->tableExists('admin_activity_log')) {
            $data['recent_2fa_actions'] = (int)$this->fetchValue("
                SELECT COUNT(*)
                FROM admin_activity_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND item_type LIKE '%2fa%'
            ");
        }

        return $data;
    }

    private function getAutomationSummary(): array
    {
        $lastCron = (int)Setting::get('last_cron_run_timestamp', 0);
        $data = [
            'healthy' => $this->isCronHealthy(),
            'last_cron_run' => $lastCron > 0 ? date('Y-m-d H:i:s', $lastCron) : null,
            'overdue_tasks' => 0,
            'failed_tasks' => 0,
            'tasks' => [],
        ];

        if (!$this->tableExists('cron_tasks')) {
            return $data;
        }

        $tasks = $this->fetchAll("
            SELECT
                task_key,
                task_name,
                interval_mins,
                last_run_at,
                last_status
            FROM cron_tasks
            ORDER BY task_name ASC
        ");

        foreach ($tasks as $task) {
            $overdue = empty($task['last_run_at']);
            if (!$overdue) {
                $lastRun = strtotime((string)$task['last_run_at']);
                $overdue = $lastRun !== false && (time() - $lastRun) > (((int)$task['interval_mins']) * 120);
            }

            if ($overdue) {
                $data['overdue_tasks']++;
            }
            if (($task['last_status'] ?? '') === 'failed') {
                $data['failed_tasks']++;
            }

            $task['is_overdue'] = $overdue;
            $data['tasks'][] = $task;
        }

        usort($data['tasks'], static function (array $a, array $b): int {
            return ((int)!empty($b['is_overdue'])) <=> ((int)!empty($a['is_overdue']));
        });
        $data['tasks'] = array_slice($data['tasks'], 0, 5);

        return $data;
    }

    private function getDownloadDeliveryMix(): array
    {
        $data = [
            'public_object_files' => 0,
            'private_object_files' => 0,
            'local_files' => 0,
            'cdn_eligible_files' => 0,
            'signed_origin_files' => 0,
            'app_controlled_files' => 0,
            'active_downloads' => 0,
        ];

        $cdnEnabled = Setting::get('cdn_download_redirects_enabled', '0') === '1';
        $cdnBaseConfigured = trim(Setting::get('cdn_download_base_url', '')) !== '';
        $progressThreshold = (int)Setting::get('ppd_min_download_percent', '0');

        $data['cdn_enabled'] = $cdnEnabled;
        $data['cdn_base_configured'] = $cdnBaseConfigured;
        $data['ppd_progress_tracking'] = $progressThreshold;

        if ($this->tableExists('files') && $this->tableExists('stored_files')) {
            $row = $this->fetchRow("
                SELECT
                    SUM(CASE WHEN sf.storage_provider <> 'local' AND f.is_public = 1 THEN 1 ELSE 0 END) AS public_object_files,
                    SUM(CASE WHEN sf.storage_provider <> 'local' AND f.is_public = 0 THEN 1 ELSE 0 END) AS private_object_files,
                    SUM(CASE WHEN sf.storage_provider = 'local' THEN 1 ELSE 0 END) AS local_files
                FROM files f
                JOIN stored_files sf ON sf.id = f.stored_file_id
                WHERE f.status IN ('active', 'ready', 'processing', 'hidden')
            ");
            $data['public_object_files'] = (int)($row['public_object_files'] ?? 0);
            $data['private_object_files'] = (int)($row['private_object_files'] ?? 0);
            $data['local_files'] = (int)($row['local_files'] ?? 0);

            $objectFiles = $data['public_object_files'] + $data['private_object_files'];
            if ($progressThreshold > 0) {
                $data['app_controlled_files'] = $objectFiles + $data['local_files'];
            } else {
                $data['cdn_eligible_files'] = ($cdnEnabled && $cdnBaseConfigured) ? $data['public_object_files'] : 0;
                $data['signed_origin_files'] = max(0, $objectFiles - $data['cdn_eligible_files']);
                $data['app_controlled_files'] = $data['local_files'];
            }
        }

        if ($this->tableExists('active_downloads')) {
            $data['active_downloads'] = (int)$this->fetchValue("SELECT COUNT(*) FROM active_downloads");
        }

        return $data;
    }

    private function getFileLifecycle(): array
    {
        $data = [
            'pending_purge' => 0,
            'deleted' => 0,
            'quarantined' => 0,
            'failed' => 0,
            'duplicated_objects' => 0,
            'orphaned_objects' => 0,
        ];

        if ($this->tableExists('files')) {
            $row = $this->fetchRow("
                SELECT
                    SUM(CASE WHEN status = 'pending_purge' THEN 1 ELSE 0 END) AS pending_purge,
                    SUM(CASE WHEN status = 'deleted' THEN 1 ELSE 0 END) AS deleted_count,
                    SUM(CASE WHEN status = 'quarantined' THEN 1 ELSE 0 END) AS quarantined_count,
                    SUM(CASE WHEN status IN ('failed', 'abandoned') THEN 1 ELSE 0 END) AS failed_count
                FROM files
            ");
            $data['pending_purge'] = (int)($row['pending_purge'] ?? 0);
            $data['deleted'] = (int)($row['deleted_count'] ?? 0);
            $data['quarantined'] = (int)($row['quarantined_count'] ?? 0);
            $data['failed'] = (int)($row['failed_count'] ?? 0);
        }

        if ($this->tableExists('stored_files')) {
            $data['duplicated_objects'] = (int)$this->fetchValue("SELECT COUNT(*) FROM stored_files WHERE ref_count > 1");
            if ($this->tableExists('files')) {
                $data['orphaned_objects'] = (int)$this->fetchValue("
                    SELECT COUNT(*)
                    FROM stored_files sf
                    LEFT JOIN files f ON f.stored_file_id = sf.id
                    WHERE f.id IS NULL
                ");
            }
        }

        return $data;
    }

    private function getSupportDiagnostics(): array
    {
        $logFile = defined('BASE_PATH') ? BASE_PATH . '/storage/logs/app.log' : dirname(__DIR__, 2) . '/storage/logs/app.log';
        $recentErrors = 0;
        if (file_exists($logFile)) {
            $lines = array_slice(@file($logFile) ?: [], -200);
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                if (($decoded['level'] ?? '') === 'error') {
                    $recentErrors++;
                }
            }
        }

        $activePlugins = 0;
        if ($this->tableExists('plugins')) {
            $activePlugins = (int)$this->fetchValue("SELECT COUNT(*) FROM plugins WHERE is_active = 1");
        } else {
            $activePlugins = count(PluginManager::getActivePlugins());
        }

        return [
            'recent_errors' => $recentErrors,
            'support_email' => DiagnosticsService::SUPPORT_EMAIL,
            'smtp_configured' => trim(Setting::get('email_smtp_host', '')) !== '' && trim(Setting::get('email_from_address', '')) !== '',
            'active_plugins' => $activePlugins,
        ];
    }

    private function getTopContent(): array
    {
        $data = [
            'top_files' => [],
            'top_storage_users' => [],
            'top_earners' => [],
        ];

        if ($this->tableExists('files')) {
            $files = $this->fetchAll("
                SELECT short_id, filename, downloads
                FROM files
                WHERE status IN ('active', 'ready', 'hidden')
                ORDER BY downloads DESC, id DESC
                LIMIT 5
            ");
            foreach ($files as &$file) {
                $file['filename'] = $this->decryptMaybe($file['filename'] ?? '');
            }
            $data['top_files'] = $files;
        }

        if ($this->tableExists('users')) {
            $users = $this->fetchAll("
                SELECT public_id, username, storage_used
                FROM users
                WHERE role = 'user'
                ORDER BY storage_used DESC, id DESC
                LIMIT 5
            ");
            foreach ($users as &$user) {
                $user['username'] = $this->decryptMaybe($user['username'] ?? '');
            }
            $data['top_storage_users'] = $users;
        }

        if ($this->tableExists('stats_daily') && $this->tableExists('users')) {
            $earners = $this->fetchAll("
                SELECT u.public_id, u.username, COALESCE(SUM(sd.earnings), 0) AS earnings_30d
                FROM stats_daily sd
                JOIN users u ON u.id = sd.user_id
                WHERE sd.day >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                GROUP BY u.id, u.public_id, u.username
                ORDER BY earnings_30d DESC
                LIMIT 5
            ");
            foreach ($earners as &$earner) {
                $earner['username'] = $this->decryptMaybe($earner['username'] ?? '');
            }
            $data['top_earners'] = $earners;
        }

        return $data;
    }

    private function getRecentActivity(): array
    {
        if (!$this->tableExists('user_activity_log')) {
            return [];
        }

        $logs = $this->fetchAll("
            SELECT l.created_at, l.activity_type, l.description, l.user_id, u.username, u.public_id
            FROM user_activity_log l
            LEFT JOIN users u ON l.user_id = u.id
            ORDER BY l.created_at DESC
            LIMIT 25
        ");

        foreach ($logs as &$log) {
            $username = $this->decryptMaybe($log['username'] ?? '');
            $log['display_name'] = $username !== ''
                ? $username
                : (!empty($log['public_id']) ? $log['public_id'] : (!empty($log['user_id']) ? 'user #' . $log['user_id'] : 'guest'));
        }

        return $logs;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $stmt = $this->db->query("SHOW TABLES LIKE " . $this->db->quote($table));
            $cache[$table] = (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    private function fetchValue(string $sql, array $params = [], $default = 0)
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function fetchRow(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function decryptMaybe(?string $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (!str_starts_with($value, 'ENC:')) {
            return $value;
        }

        $decrypted = \App\Service\EncryptionService::decrypt($value);
        return is_string($decrypted) ? $decrypted : '';
    }

    private function ensureStatsSchema(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `system_stats` (
                    `id` TINYINT UNSIGNED NOT NULL,
                    `total_files` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `total_users` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `total_storage_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `pending_withdrawals` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `pending_reports` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
        }
    }
}
