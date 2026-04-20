<?php

namespace App\Service;

use App\Core\Database;
use App\Model\Setting;
use Exception;

class MailQueueService {
    
    /**
     * Add an email to the queue
     */
    public static function queue(string $to, string $subject, string $body, string $priority = 'low'): bool {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("INSERT INTO mail_queue (recipient, subject, body, priority, status) VALUES (?, ?, ?, ?, 'pending')");
            return $stmt->execute([$to, $subject, $body, $priority]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Process a batch of pending emails (triggered by cron)
     */
    public static function processBatch(): array {
        $db = Database::getInstance();
        
        // 0. Check if SMTP is configured to avoid PHP warnings
        $host = trim(Setting::get('email_smtp_host', ''));
        if (empty($host)) {
            return ['error' => 'SMTP not configured. Skipping queue.'];
        }
        
        // 1. Get limit from settings
        $limit = (int)Setting::get('email_limit_per_minute', '20');
        
        // 2. Fetch pending, high priority first
        $stmt = $db->prepare("SELECT * FROM mail_queue WHERE status = 'pending' ORDER BY priority ASC, created_at ASC LIMIT ?");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $emails = $stmt->fetchAll();

        if (empty($emails)) return ['sent' => 0, 'failed' => 0];

        $results = ['sent' => 0, 'failed' => 0];
        
        try {
            $mailService = MailService::createFromSettings();
            
            foreach ($emails as $email) {
                try {
                    if ($mailService->send($email['recipient'], $email['subject'], $email['body'])) {
                        $db->prepare("UPDATE mail_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 WHERE id = ?")->execute([$email['id']]);
                        $results['sent']++;
                    }
                } catch (Exception $e) {
                    $db->prepare("UPDATE mail_queue SET status = 'failed', last_error = ?, attempts = attempts + 1 WHERE id = ?")->execute([$e->getMessage(), $email['id']]);
                    $results['failed']++;
                }
            }
        } catch (Exception $e) {
            // SMTP Config might be broken
            return ['error' => $e->getMessage()];
        }

        return $results;
    }
}
