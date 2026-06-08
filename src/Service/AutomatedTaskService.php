<?php

namespace App\Service;

use App\Core\Database;
use App\Model\Setting;
use Exception;
use App\Service\Database\SchemaService;

class AutomatedTaskService {
    private static bool $remoteQueueSchemaReady = false;
    private static bool $subscriptionReminderSchemaReady = false;
    private const REMOTE_UPLOAD_RESERVATION_TTL_SECONDS = 1800;
    private const REMOTE_UPLOAD_STALE_PROCESSING_SECONDS = 1800;

    private function ensureRemoteUploadQueueSchema(): void
    {
        if (self::$remoteQueueSchemaReady) {
            return;
        }

        SchemaService::ensureTables(['remote_upload_queue'], false);

        self::$remoteQueueSchemaReady = true;
    }

    private function ensureSubscriptionReminderSchema(): void
    {
        if (self::$subscriptionReminderSchemaReady) {
            return;
        }

        SchemaService::ensureTables(['subscription_reminder_dispatches'], false);

        self::$subscriptionReminderSchemaReady = true;
    }

    private function claimExpiryReminderDispatch(\PDO $db, int $userId, string $templateKey, string $expiryDate): bool
    {
        $driver = (string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "
                INSERT OR IGNORE INTO subscription_reminder_dispatches
                    (user_id, reminder_type, target_expiry_date, sent_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ";
        } else {
            $sql = "
                INSERT IGNORE INTO subscription_reminder_dispatches
                    (user_id, reminder_type, target_expiry_date, sent_at)
                VALUES (?, ?, ?, NOW())
            ";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $templateKey, $expiryDate]);

        return $stmt->rowCount() === 1;
    }
    private function resolveCorePackageIds(): array
    {
        $db = Database::getInstance()->getConnection();
        $rows = $db->query("SELECT id, level_type FROM packages")->fetchAll();

        $freeId = null;
        $adminId = null;
        foreach ($rows as $row) {
            $levelType = strtolower((string)($row['level_type'] ?? ''));
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if ($levelType === 'free' && $freeId === null) {
                $freeId = $id;
            } elseif ($levelType === 'admin' && $adminId === null) {
                $adminId = $id;
            }
        }

        return [
            'free' => $freeId,
            'admin' => $adminId,
        ];
    }

    /**
     * Check for expired premium accounts and move them to Free tier
     */
    public function downgradeExpiredAccounts(): array {
        $db = Database::getInstance()->getConnection();
        $results = ['downgraded' => 0];
        $packageIds = $this->resolveCorePackageIds();
        $freePackageId = (int)($packageIds['free'] ?? 0);
        $adminPackageId = (int)($packageIds['admin'] ?? 0);

        if ($freePackageId <= 0) {
            return $results;
        }

        PaymentService::expireStaleActiveSubscriptions($db);

        // 1. Find all users where premium_expiry < NOW() and package_id is not the
        // resolved Free or Admin package for this install.
        $sql = "SELECT id, username, email FROM users WHERE premium_expiry IS NOT NULL AND premium_expiry < NOW()";
        $params = [];
        $excludedIds = [$freePackageId];
        if ($adminPackageId > 0) {
            $excludedIds[] = $adminPackageId;
        }
        if (!empty($excludedIds)) {
            $sql .= " AND package_id NOT IN (" . implode(',', array_fill(0, count($excludedIds), '?')) . ")";
            $params = $excludedIds;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $expiredUsers = $stmt->fetchAll();
        $bonusTouchUserIds = [];

        foreach ($expiredUsers as $user) {
            $upd = $db->prepare("UPDATE users SET package_id = ?, premium_expiry = NULL, premium_started_at = NULL WHERE id = ?");
            if ($upd->execute([$freePackageId, $user['id']])) {
                $results['downgraded']++;
                $bonusTouchUserIds[] = (int)$user['id'];

                $username = \App\Service\EncryptionService::decrypt($user['username']);
                $email = \App\Service\EncryptionService::decrypt($user['email']);

                \App\Service\MailService::sendTemplate($email, 'account_downgrade', [
                    '{username}' => $username
                ], 'low');
            }
        }

        if ($bonusTouchUserIds !== []) {
            \App\Service\BonusOfferService::touchUsersFailSoft($bonusTouchUserIds, true, [
                'workflow' => 'downgrade_expired_accounts',
            ]);
        }

        return $results;
    }

    /**
     * Notify users before their premium status expires
     */
    public function sendExpiryReminders(): array {
        $db = Database::getInstance()->getConnection();
        $results = ['reminders_sent' => 0];
        $this->ensureSubscriptionReminderSchema();
        $packageIds = $this->resolveCorePackageIds();
        $freePackageId = (int)($packageIds['free'] ?? 0);
        $adminPackageId = (int)($packageIds['admin'] ?? 0);

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
                AND package_id NOT IN (?, ?)
            ");
            $stmt->execute([$days, $freePackageId, $adminPackageId > 0 ? $adminPackageId : -1]);
            $users = $stmt->fetchAll();

            foreach ($users as $user) {
                $expiryDate = date('Y-m-d', strtotime((string)$user['premium_expiry']));

                $db->beginTransaction();
                try {
                    if (!$this->claimExpiryReminderDispatch($db, (int)$user['id'], (string)$int['template'], $expiryDate)) {
                        $db->rollBack();
                        continue;
                    }

                    $username = \App\Service\EncryptionService::decrypt($user['username']);
                    $email = \App\Service\EncryptionService::decrypt($user['email']);

                    $sent = \App\Service\MailService::sendTemplate($email, (string)$int['template'], [
                        '{username}' => $username,
                        '{expiry_date}' => $expiryDate
                    ], 'low');
                    if (!$sent) {
                        throw new \RuntimeException('Premium expiry reminder send failed.');
                    }

                    $db->commit();
                    $results['reminders_sent']++;
                } catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    \App\Core\Logger::warning('premium expiry reminder dispatch failed', [
                        'user_id' => (int)$user['id'],
                        'template_key' => (string)$int['template'],
                        'expiry_date' => $expiryDate,
                        'error' => $e->getMessage(),
                    ]);
                }
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

            try {
                SafeNetworkTargetService::assertSafeRemoteHttpUrl((string)$url, 'Server monitoring URL');
            } catch (\RuntimeException $e) {
                $log = $db->prepare("INSERT INTO server_monitoring_log (server_id, status, response_time_ms, error_message) VALUES (?, ?, ?, ?)");
                $log->execute([(int)$server['id'], 'offline', 0, $e->getMessage()]);
                $results['offline']++;
                continue;
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

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
        $results = ['purged_activity' => 0, 'purged_downloads' => 0, 'purged_legacy_search_logs' => 0];

        // 1. Purge user activity logs > 30 days (Anti-cheat historical data)
        $stmt = $db->prepare("DELETE FROM user_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute();
        $results['purged_activity'] = $stmt->rowCount();

        // 2. Purge active_downloads > 24 hours (Transient session data)
        $stmt = $db->prepare("DELETE FROM active_downloads WHERE started_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        $results['purged_downloads'] = $stmt->rowCount();

        $legacySearchLog = rtrim(\App\Core\Logger::logDirectory(), '/\\') . '/admin_search.log';
        if (is_file($legacySearchLog) && @unlink($legacySearchLog)) {
            $results['purged_legacy_search_logs'] = 1;
        }

        return $results;
    }

    /**
     * Mark abandoned pending package-payment attempts as failed after they have
     * been stale long enough that they are no longer realistically in-flight.
     */
    public function cleanupStalePendingPayments(int $olderThanMinutes = 1440): array {
        return PaymentService::expireStalePendingTransactions($olderThanMinutes);
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
        $stmt->bindValue(1, max(1, (int)$batchSize), \PDO::PARAM_INT);
        $stmt->execute();
        $files = $stmt->fetchAll();

        $results = ['deleted' => 0, 'errors' => []];
        foreach ($files as $f) {
            try {
                $audit = null;
                $storedAudit = \App\Model\FileDeletionLog::findLatestByOriginalFileId((int)$f['id']);
                if (is_array($storedAudit)) {
                    $audit = [
                        'deleted_by_user_id' => isset($storedAudit['deleted_by_user_id']) ? (int)$storedAudit['deleted_by_user_id'] : null,
                        'deleted_by_role' => isset($storedAudit['deleted_by_role']) ? (string)$storedAudit['deleted_by_role'] : null,
                        'deleted_by_label' => isset($storedAudit['deleted_by_label']) ? (string)$storedAudit['deleted_by_label'] : null,
                        'delete_reason' => isset($storedAudit['delete_reason']) ? (string)$storedAudit['delete_reason'] : null,
                        'delete_file_earnings' => !empty($storedAudit['delete_file_earnings']),
                        'delete_file_earnings_authorized' => !empty($storedAudit['delete_file_earnings_authorized']),
                        'rewards_reviewer_id' => isset($storedAudit['rewards_reviewer_id']) ? (int)$storedAudit['rewards_reviewer_id'] : null,
                    ];
                }

                \App\Model\File::hardDelete((int)$f['id'], $audit);
                $results['deleted']++;
            } catch (Exception $e) {
                $results['errors'][] = "File #{$f['id']}: " . $e->getMessage();
            }
        }

        $storedFilePurge = $this->processReleasedStoredFileQueue($batchSize);
        $results['stored_files_deleted'] = (int)($storedFilePurge['deleted'] ?? 0);
        $results['stored_files_retained'] = (int)($storedFilePurge['retained'] ?? 0);
        $results['stored_file_errors'] = $storedFilePurge['errors'] ?? [];

        return $results;
    }

    public function processReleasedStoredFileQueue(int $batchSize = 50): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM stored_files WHERE ref_count = 0 LIMIT ?");
        $stmt->bindValue(1, max(1, (int)$batchSize), \PDO::PARAM_INT);
        $stmt->execute();
        $storedFiles = $stmt->fetchAll();

        $results = ['deleted' => 0, 'retained' => 0, 'errors' => []];
        foreach ($storedFiles as $storedFile) {
            $storedFileId = (int)($storedFile['id'] ?? 0);
            if ($storedFileId <= 0) {
                continue;
            }

            try {
                $purgeResult = \App\Model\StoredFile::purgeIfUnreferenced($storedFileId);
                $status = (string)($purgeResult['status'] ?? '');
                if ($status === 'purged') {
                    $results['deleted']++;
                    continue;
                }

                if ($status === 'retained') {
                    $results['retained']++;
                    continue;
                }

                $results['errors'][] = 'Stored file #' . $storedFileId . ': ' . (string)($purgeResult['reason'] ?? 'cleanup_not_proven');
            } catch (\Throwable $e) {
                $results['errors'][] = 'Stored file #' . $storedFileId . ': ' . $e->getMessage();
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

        $countedStatuses = \App\Model\File::storageConsumingStatuses();
        $statusPlaceholders = implode(', ', array_fill(0, count($countedStatuses), '?'));

        $results = ['audited' => 0, 'corrected' => 0];
        foreach ($users as $u) {
            $results['audited']++;
            $userId = $u['id'];

            // Calculate real usage
            $usageStmt = $db->prepare("
                SELECT SUM(sf.file_size)
                FROM files f
                JOIN stored_files sf ON f.stored_file_id = sf.id
                WHERE f.user_id = ? AND f.status IN ($statusPlaceholders)
            ");
            $usageStmt->execute(array_merge([(int)$userId], $countedStatuses));
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
        $this->ensureRemoteUploadQueueSchema();
        $db = Database::getInstance()->getConnection();
        $results = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'recovered_stale' => 0];
        $results['recovered_stale'] = $this->recoverStaleRemoteUploadJobs($db);

        $stmt = $db->prepare("
            SELECT id, user_id, folder_id, url
            FROM remote_upload_queue
            WHERE status = 'pending'
              AND (available_at IS NULL OR available_at <= NOW())
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->execute([$batchSize]);
        $jobs = $stmt->fetchAll();

        foreach ($jobs as $job) {
            $claim = $db->prepare("
                UPDATE remote_upload_queue
                SET status = 'processing', error_message = NULL, started_at = NOW(), attempt_count = attempt_count + 1
                WHERE id = ? AND status = 'pending'
            ");
            $claim->execute([(int)$job['id']]);
            if ($claim->rowCount() === 0) {
                continue;
            }

            $results['processed']++;

            try {
                $this->processRemoteUploadJob($job);
                $complete = $db->prepare("UPDATE remote_upload_queue SET status = 'completed', processed_at = NOW(), error_message = NULL WHERE id = ? AND status = 'processing'");
                $complete->execute([(int)$job['id']]);
                if ($complete->rowCount() === 0) {
                    continue;
                }
                NotificationService::sendEvent(
                    (int)$job['user_id'],
                    'remote_uploads',
                    'remote_upload:' . (int)$job['id'],
                    'Remote upload completed',
                    'One of your remote uploads finished successfully.',
                    'success',
                    '/notifications',
                    ['job_id' => (int)$job['id']]
                );
                $results['completed']++;
            } catch (\Throwable $e) {
                $fail = $db->prepare("UPDATE remote_upload_queue SET status = 'failed', processed_at = NOW(), error_message = ? WHERE id = ? AND status = 'processing'");
                $fail->execute([substr($e->getMessage(), 0, 65535), (int)$job['id']]);
                if ($fail->rowCount() === 0) {
                    continue;
                }
                NotificationService::sendEvent(
                    (int)$job['user_id'],
                    'remote_uploads',
                    'remote_upload:' . (int)$job['id'],
                    'Remote upload failed',
                    'A remote upload failed. Open your notifications to review the latest error and try again.',
                    'warning',
                    '/notifications',
                    ['job_id' => (int)$job['id']]
                );
                $results['failed']++;
            }
        }

        return $results;
    }

    private function recoverStaleRemoteUploadJobs(\PDO $db): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::REMOTE_UPLOAD_STALE_PROCESSING_SECONDS);
        $failedAt = date('Y-m-d H:i:s');
        $staleMessage = 'Remote upload worker stopped before the job finished. The job was marked failed so it can be retried safely.';

        $select = $db->prepare("
            SELECT id
            FROM remote_upload_queue
            WHERE status = 'processing'
              AND started_at IS NOT NULL
              AND started_at < ?
            ORDER BY started_at ASC, id ASC
        ");
        $select->execute([$cutoff]);
        $jobIds = array_values(array_filter(array_map('intval', $select->fetchAll(\PDO::FETCH_COLUMN) ?: []), static fn(int $id): bool => $id > 0));
        if ($jobIds === []) {
            return 0;
        }

        $update = $db->prepare("
            UPDATE remote_upload_queue
            SET status = 'failed',
                processed_at = ?,
                error_message = ?
            WHERE id = ?
              AND status = 'processing'
              AND started_at IS NOT NULL
              AND started_at < ?
        ");
        $releaseReservation = $db->prepare("
            UPDATE quota_reservations
            SET status = 'released',
                released_at = ?
            WHERE public_id = ?
              AND status = 'active'
        ");

        $recovered = 0;
        foreach ($jobIds as $jobId) {
            $update->execute([$failedAt, $staleMessage, $jobId, $cutoff]);
            if ($update->rowCount() !== 1) {
                continue;
            }

            $releaseReservation->execute([$failedAt, 'remote_job:' . $jobId]);
            $recovered++;
        }

        return $recovered;
    }

    private function remoteUploadJobStillProcessing(\PDO $db, int $jobId): bool
    {
        $stmt = $db->prepare("SELECT status FROM remote_upload_queue WHERE id = ? LIMIT 1");
        $stmt->execute([$jobId]);
        return (string)$stmt->fetchColumn() === 'processing';
    }

    private function processRemoteUploadJob(array $job): void {
        $db = Database::getInstance()->getConnection();
        $jobId = (int)($job['id'] ?? 0);
        $userId = (int)$job['user_id'];
        $folderId = !empty($job['folder_id']) ? (int)$job['folder_id'] : null;
        $url = trim((string)$job['url']);
        $reservationId = null;

        if ($jobId <= 0 || $userId <= 0 || $url === '') {
            throw new Exception('Remote upload job is missing required data.');
        }

        if (!$this->remoteUploadJobStillProcessing($db, $jobId)) {
            throw new Exception('Remote upload was canceled before processing started.');
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

        $tempPath = TemporaryArtifactService::createTempFile('remote_');
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

        $contentLength = $this->probeRemoteContentLength($url, $scheme, (string)$host, $port, $resolveEntries);
        if ($contentLength !== null) {
            if ($contentLength > $maxRemoteBytes) {
                fclose($fp);
                @unlink($tempPath);
                throw new Exception('Remote file exceeds the allowed upload size or remaining storage quota.');
            }

            $reservationId = $this->claimRemoteUploadReservation($userId, $jobId, $contentLength, $package);
        }

        $success = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $tempFileSize = file_exists($tempPath) ? (int)filesize($tempPath) : 0;
        if (!$success) {
            if ($reservationId !== null) {
                \App\Model\QuotaReservation::updateStatus($reservationId, 'released');
            }
            @unlink($tempPath);
            if ($downloadedBytes > $maxRemoteBytes || $curlErrNo === 23 || $curlErrNo === 63 || (!$contentLengthChecked && $tempFileSize > $maxRemoteBytes)) {
                throw new Exception('Remote file exceeds the allowed upload size or remaining storage quota.');
            }
            throw new Exception('Could not fetch file from URL.' . ($curlErr ? ' Transfer error: ' . $curlErr : ''));
        }

        if ($tempFileSize > $maxRemoteBytes) {
            if ($reservationId !== null) {
                \App\Model\QuotaReservation::updateStatus($reservationId, 'released');
            }
            @unlink($tempPath);
            throw new Exception('Remote file exceeds the allowed upload size or remaining storage quota.');
        }

        if ($reservationId === null) {
            $reservationId = $this->claimRemoteUploadReservation($userId, $jobId, $tempFileSize, $package);
        } else {
            \App\Model\QuotaReservation::updateReservedBytes($reservationId, $tempFileSize);
        }

        $originalName = basename((string)parse_url($url, PHP_URL_PATH)) ?: 'downloaded_file';
        try {
            if (!$this->remoteUploadJobStillProcessing($db, $jobId)) {
                throw new Exception('Remote upload was canceled before import finished.');
            }

            $processor = new \App\Service\FileProcessor();
            $result = $processor->processUpload($tempPath, $originalName, $userId, $folderId, $reservationId);
            if ($reservationId !== null) {
                \App\Model\QuotaReservation::updateStatus($reservationId, 'committed');
                $reservationId = null;
            }

            if (!$this->remoteUploadJobStillProcessing($db, $jobId)) {
                if (!empty($result['file_id'])) {
                    try {
                        \App\Model\File::hardDelete((int)$result['file_id']);
                    } catch (\Throwable $cleanupError) {
                        \App\Core\Logger::warning('Remote upload cancellation cleanup failed after import completed', [
                            'job_id' => $jobId,
                            'file_id' => (int)$result['file_id'],
                            'error' => $cleanupError->getMessage(),
                        ]);
                    }
                }
                throw new Exception('Remote upload was canceled before import finished.');
            }
        } finally {
            if ($reservationId !== null) {
                \App\Model\QuotaReservation::updateStatus($reservationId, 'released');
            }
            TemporaryArtifactService::cleanup($tempPath);
        }
    }

    private function claimRemoteUploadReservation(int $userId, int $jobId, int $reservedBytes, array $package): ?int
    {
        if ($userId <= 0 || $jobId <= 0 || $reservedBytes < 0) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $quotaLockHeld = false;
        if (!$this->acquireUserStorageQuotaLock($db, $userId)) {
            throw new Exception('Remote upload could not reserve quota safely right now. Please try again.');
        }
        $quotaLockHeld = true;

        $publicId = 'remote_job:' . $jobId;
        try {
            $existing = \App\Model\QuotaReservation::findActiveByPublicId($publicId);
            $maxStorage = (int)($package['max_storage_bytes'] ?? 0);
            if ($maxStorage > 0) {
                $stmt = $db->prepare("SELECT storage_used FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$userId]);
                $storageUsed = (int)$stmt->fetchColumn();
                $activeReserved = \App\Model\QuotaReservation::activeReservedBytesForUser($userId);
                if ($existing) {
                    $activeReserved = max(0, $activeReserved - (int)($existing['reserved_bytes'] ?? 0));
                }

                if (($storageUsed + $activeReserved + $reservedBytes) > $maxStorage) {
                    throw new Exception('Remote upload is not available because remaining limits are exhausted.');
                }
            }

            if ($existing) {
                \App\Model\QuotaReservation::updateReservedBytes((int)$existing['id'], $reservedBytes, date('Y-m-d H:i:s', time() + self::REMOTE_UPLOAD_RESERVATION_TTL_SECONDS));
                return (int)$existing['id'];
            }

            return \App\Model\QuotaReservation::create([
                'public_id' => $publicId,
                'user_id' => $userId,
                'reserved_bytes' => max(0, $reservedBytes),
                'status' => 'active',
                'expires_at' => date('Y-m-d H:i:s', time() + self::REMOTE_UPLOAD_RESERVATION_TTL_SECONDS),
            ]);
        } finally {
            if ($quotaLockHeld) {
                $this->releaseUserStorageQuotaLock($db, $userId);
            }
        }
    }

    private function probeRemoteContentLength(string $url, string $scheme, string $host, int $port, array $resolveEntries): ?int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_RESOLVE, $resolveEntries);

        $success = curl_exec($ch);
        if ($success === false) {
            curl_close($ch);
            return null;
        }

        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
        curl_close($ch);

        if (!is_int($contentLength) && !is_float($contentLength)) {
            return null;
        }

        return $contentLength >= 0 ? (int)$contentLength : null;
    }

    private function resolveApprovedRemoteIps(?string $host): array {
        return \App\Service\SecurityService::resolveApprovedRemoteDestinationIps($host);
    }

    private function isAllowedRemoteIp(string $ip): bool {
        return \App\Service\SecurityService::isAllowedRemoteDestinationIp($ip);
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
            $activeReserved = \App\Model\QuotaReservation::activeReservedBytesForUser($userId);
            $remaining = max(0, $maxStorage - $storageUsed - $activeReserved);
            $limit = min($limit, $remaining);
        }

        return max(0, $limit);
    }

    private function acquireUserStorageQuotaLock(\PDO $db, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $db->prepare("SELECT GET_LOCK(?, 5)");
        $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseUserStorageQuotaLock(\PDO $db, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute(['fyuhls_user_storage_quota_' . $userId]);
        } catch (\Throwable $e) {
        }
    }
}
