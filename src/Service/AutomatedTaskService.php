<?php

namespace App\Service;

use App\Core\Database;
use App\Model\Setting;
use Exception;

class AutomatedTaskService {
    
    /**
     * Check for expired premium accounts and move them to Free tier
     */
    public function downgradeExpiredAccounts(): array {
        $db = Database::getInstance()->getConnection();
        $results = ['downgraded' => 0];

        // 1. Find all users where premium_expiry < NOW() and package_id is NOT Free (2) or Admin (4)
        $stmt = $db->query("SELECT id, username, email FROM users WHERE premium_expiry IS NOT NULL AND premium_expiry < NOW() AND package_id NOT IN (2, 4)");
        $expiredUsers = $stmt->fetchAll();

        foreach ($expiredUsers as $user) {
            // Downgrade to Free tier (2)
            $upd = $db->prepare("UPDATE users SET package_id = 2, premium_expiry = NULL WHERE id = ?");
            if ($upd->execute([$user['id']])) {
                $results['downgraded']++;
                
                $username = \App\Service\EncryptionService::decrypt($user['username']);
                $email = \App\Service\EncryptionService::decrypt($user['email']);
                
                \App\Service\MailService::sendTemplate($email, 'account_downgrade', [
                    '{username}' => $username
                ], 'low');
            }
        }

        return $results;
    }

    /**
     * Notify users before their premium status expires
     */
    public function sendExpiryReminders(): array {
        $db = Database::getInstance()->getConnection();
        $results = ['reminders_sent' => 0];

        // Intervals to check: 7 days and 1 day
        $intervals = [
            ['days' => 7, 'template' => 'premium_expiry_reminder_7d'],
            ['days' => 1, 'template' => 'premium_expiry_reminder_1d']
        ];

        foreach ($intervals as $int) {
            $days = $int['days'];
            // Find users expiring exactly N days from now (within a 1-day range to avoid duplicate sends)
            // We use a flag or just DATE check to ensure we only send once per interval.
            $stmt = $db->prepare("
                SELECT id, username, email, premium_expiry 
                FROM users 
                WHERE premium_expiry IS NOT NULL 
                AND DATE(premium_expiry) = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND package_id NOT IN (2, 4)
            ");
            $stmt->execute([$days]);
            $users = $stmt->fetchAll();

            foreach ($users as $user) {
                $username = \App\Service\EncryptionService::decrypt($user['username']);
                $email = \App\Service\EncryptionService::decrypt($user['email']);
                
                \App\Service\MailService::sendTemplate($email, $int['template'], [
                    '{username}' => $username,
                    '{expiry_date}' => date('Y-m-d', strtotime($user['premium_expiry']))
                ], 'low');
                
                $results['reminders_sent']++;
            }
        }

        return $results;
    }


    /**
     * Check health of all active storage servers (Parallel Heartbeats)
     * 
     * Optimized for 15+ servers using curl_multi to prevent sequential timeouts.
     */
    public function monitorServerHealth(): array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM file_servers WHERE status != 'disabled'");
        $servers = $stmt->fetchAll();
        
        $results = ['online' => 0, 'offline' => 0];
        $mh = curl_multi_init();
        $handles = [];

        // 1. Prepare parallel pings
        foreach ($servers as $server) {
            $url = $server['public_url'];
            if (empty($url)) continue;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            curl_multi_add_handle($mh, $ch);
            $handles[$server['id']] = [
                'ch' => $ch,
                'start' => microtime(true),
                'server' => $server
            ];
        }

        // 2. Execute handles simultaneously
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($active > 0);

        // 3. Process results and log to DB
        foreach ($handles as $serverId => $data) {
            $ch = $data['ch'];
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $duration = (int)((microtime(true) - $data['start']) * 1000);
            
            $isOnline = ($httpCode >= 200 && $httpCode < 400);
            $status = $isOnline ? 'online' : 'offline';
            $error = $isOnline ? null : "HTTP Code: $httpCode " . curl_error($ch);

            // Log result
            $log = $db->prepare("INSERT INTO server_monitoring_log (server_id, status, response_time_ms, error_message) VALUES (?, ?, ?, ?)");
            $log->execute([$serverId, $status, $duration, $error]);

            if ($isOnline) $results['online']++;
            else $results['offline']++;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        // Fallback for servers without public_url (Local/S3)
        foreach ($servers as $server) {
            if (isset($handles[$server['id']])) continue;

            $start = microtime(true);
            try {
                $provider = \App\Service\Storage\ServerProviderFactory::make($server);
                $isOnline = $provider->testConnection();
            } catch (Exception $e) { $isOnline = false; }

            $duration = (int)((microtime(true) - $start) * 1000);
            $status = $isOnline ? 'online' : 'offline';
            
            $log = $db->prepare("INSERT INTO server_monitoring_log (server_id, status, response_time_ms, error_message) VALUES (?, ?, ?, ?)");
            $log->execute([$server['id'], $status, $duration, null]);

            if ($isOnline) $results['online']++;
            else $results['offline']++;
        }

        return $results;
    }

    /**
     * Purge old logs to prevent disk space exhaustion (Log Rotation)
     */
    public function purgeOldLogs(): array {
        $db = Database::getInstance()->getConnection();
        $results = ['purged_activity' => 0, 'purged_downloads' => 0];

        // 1. Purge user activity logs > 30 days (Anti-cheat historical data)
        $stmt = $db->prepare("DELETE FROM user_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute();
        $results['purged_activity'] = $stmt->rowCount();

        // 2. Purge active_downloads > 24 hours (Transient session data)
        $stmt = $db->prepare("DELETE FROM active_downloads WHERE started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        $results['purged_downloads'] = $stmt->rowCount();

        return $results;
    }

    /**
     * processFilePurgeQueue (Enterprise Background Purge)
     * 
     * Physically deletes files marked as 'pending_purge' in small batches.
     * Prevents UI timeouts when deleting thousands of files.
     */
    public function processFilePurgeQueue(int $batchSize = 50): array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM files WHERE status = 'pending_purge' LIMIT ?");
        $stmt->execute([$batchSize]);
        $files = $stmt->fetchAll();

        $results = ['deleted' => 0, 'errors' => []];
        foreach ($files as $f) {
            try {
                \App\Model\File::hardDelete($f['id']);
                $results['deleted']++;
            } catch (Exception $e) {
                $results['errors'][] = "File #{$f['id']}: " . $e->getMessage();
            }
        }
        return $results;
    }

    /**
     * auditUserStorage (Enterprise Quota Integrity)
     * 
     * Reconciles storage_used for random users to ensure counters never drift permanently.
     */
    public function auditUserStorage(int $userCount = 5): array {
        $db = Database::getInstance()->getConnection();
        // Pick 5 random users who have uploaded recently
        $userCount = max(1, (int)$userCount);
        $stmt = $db->prepare("SELECT id, storage_used FROM users ORDER BY RAND() LIMIT ?");
        $stmt->bindValue(1, $userCount, \PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        $results = ['audited' => 0, 'corrected' => 0];
        foreach ($users as $u) {
            $results['audited']++;
            $userId = $u['id'];
            
            // Calculate real usage
            $usageStmt = $db->prepare("
                SELECT SUM(sf.file_size) 
                FROM files f 
                JOIN stored_files sf ON f.stored_file_id = sf.id 
                WHERE f.user_id = ? AND f.status = 'active'
            ");
            $usageStmt->execute([(int)$userId]);
            $realUsage = (float)$usageStmt->fetchColumn();

            if (abs($realUsage - $u['storage_used']) > 1) { // 1 byte tolerance
                $upd = $db->prepare("UPDATE users SET storage_used = ? WHERE id = ?");
                $upd->execute([$realUsage, $userId]);
                $results['corrected']++;
            }
        }
        return $results;
    }

    /**
     * Process queued remote URL imports in small batches so shared hosting does not
     * get hammered by many synchronous cURL downloads at once.
     */
    public function processRemoteUploadQueue(int $batchSize = 5): array {
        $db = Database::getInstance()->getConnection();
        $results = ['processed' => 0, 'completed' => 0, 'failed' => 0];

        $stmt = $db->prepare("
            SELECT id, user_id, folder_id, url
            FROM remote_upload_queue
            WHERE status = 'pending'
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->execute([$batchSize]);
        $jobs = $stmt->fetchAll();

        foreach ($jobs as $job) {
            $claim = $db->prepare("
                UPDATE remote_upload_queue
                SET status = 'processing', error_message = NULL
                WHERE id = ? AND status = 'pending'
            ");
            $claim->execute([(int)$job['id']]);
            if ($claim->rowCount() === 0) {
                continue;
            }

            $results['processed']++;

            try {
                $this->processRemoteUploadJob($job);
                $db->prepare("UPDATE remote_upload_queue SET status = 'completed', processed_at = NOW(), error_message = NULL WHERE id = ?")
                    ->execute([(int)$job['id']]);
                $results['completed']++;
            } catch (\Throwable $e) {
                $db->prepare("UPDATE remote_upload_queue SET status = 'failed', processed_at = NOW(), error_message = ? WHERE id = ?")
                    ->execute([substr($e->getMessage(), 0, 65535), (int)$job['id']]);
                $results['failed']++;
            }
        }

        return $results;
    }

    private function processRemoteUploadJob(array $job): void {
        $userId = (int)$job['user_id'];
        $folderId = !empty($job['folder_id']) ? (int)$job['folder_id'] : null;
        $url = trim((string)$job['url']);

        if ($userId <= 0 || $url === '') {
            throw new Exception('Remote upload job is missing required data.');
        }

        $package = \App\Model\Package::getUserPackage($userId);
        if (!$package || empty($package['allow_remote_upload'])) {
            throw new Exception('Remote upload is not allowed for this user package.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new Exception('Invalid protocol. Only HTTP and HTTPS allowed.');
        }

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new Exception('Embedded credentials are not allowed in remote URLs.');
        }

        $host = parse_url($url, PHP_URL_HOST);
        $approvedIps = $this->resolveApprovedRemoteIps($host);
        if (empty($approvedIps)) {
            throw new Exception('Could not resolve host.');
        }

        $maxRemoteBytes = $this->resolveRemoteUploadByteLimit($userId, $package);
        if ($maxRemoteBytes <= 0) {
            throw new Exception('Remote upload is not available because remaining limits are exhausted.');
        }

        $tempPath = sys_get_temp_dir() . '/' . uniqid('remote_', true);
        $fp = fopen($tempPath, 'wb');
        if (!$fp) {
            throw new Exception('Could not open temporary storage for remote download.');
        }

        $resolvedHost = str_contains((string)$host, ':') ? '[' . $host . ']' : (string)$host;
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        $resolveEntries = array_map(static fn(string $ip): string => $resolvedHost . ':' . $port . ':' . $ip, $approvedIps);
        $downloadedBytes = 0;
        $contentLengthChecked = false;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_RESOLVE, $resolveEntries);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function($curl, string $headerLine) use ($maxRemoteBytes, &$contentLengthChecked) {
            if (stripos($headerLine, 'Content-Length:') === 0) {
                $length = (int)trim(substr($headerLine, strlen('Content-Length:')));
                $contentLengthChecked = true;
                if ($length > 0 && $length > $maxRemoteBytes) {
                    return -1;
                }
            }
            return strlen($headerLine);
        });
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($curl, float $downloadTotal, float $downloadNow) use ($maxRemoteBytes, &$downloadedBytes) {
            $downloadedBytes = (int)$downloadNow;
            return $downloadNow > $maxRemoteBytes ? 1 : 0;
        });

        $success = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $tempFileSize = file_exists($tempPath) ? (int)filesize($tempPath) : 0;
        if (!$success) {
            @unlink($tempPath);
            if ($downloadedBytes > $maxRemoteBytes || $curlErrNo === 23 || $curlErrNo === 63 || (!$contentLengthChecked && $tempFileSize > $maxRemoteBytes)) {
                throw new Exception('Remote file exceeds the allowed upload size or remaining storage quota.');
            }
            throw new Exception('Could not fetch file from URL.' . ($curlErr ? ' Transfer error: ' . $curlErr : ''));
        }

        if ($tempFileSize > $maxRemoteBytes) {
            @unlink($tempPath);
            throw new Exception('Remote file exceeds the allowed upload size or remaining storage quota.');
        }

        $originalName = basename((string)parse_url($url, PHP_URL_PATH)) ?: 'downloaded_file';
        try {
            $processor = new \App\Service\FileProcessor();
            $processor->processUpload($tempPath, $originalName, $userId, $folderId);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function resolveApprovedRemoteIps(?string $host): array {
        if (!$host || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $approved = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$ip || !$this->isAllowedRemoteIp($ip)) {
                continue;
            }
            $approved[] = $ip;
        }

        return array_values(array_unique($approved));
    }

    private function isAllowedRemoteIp(string $ip): bool {
        $blockedRanges = [
            '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            '169.254.0.0/16', '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24',
            '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
            '224.0.0.0/4', '240.0.0.0/4', '::1/128', 'fc00::/7', 'fe80::/10', '2001:db8::/32',
        ];

        foreach ($blockedRanges as $range) {
            if (\App\Service\SecurityService::ipInCidr($ip, $range)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function resolveRemoteUploadByteLimit(int $userId, array $package): int {
        $limit = (int)($package['max_upload_size'] ?? 0);
        if ($limit <= 0) {
            $limit = PHP_INT_MAX;
        }

        $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
        if ($maxStorage > 0 && $userId > 0) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $storageUsed = (int)$stmt->fetchColumn();
            $remaining = max(0, $maxStorage - $storageUsed);
            $limit = min($limit, $remaining);
        }

        return max(0, $limit);
    }
}
