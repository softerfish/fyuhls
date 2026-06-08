<?php

namespace App\Service;

use App\Core\Database;
use App\Model\Setting;
use Exception;
use PDO;
use Throwable;

class MailQueueService {
    private const CLAIM_MARKER = '__processing__';
    private const STALE_CLAIM_MINUTES = 15;
    private static $mailServiceFactory = null;
    private static $afterSuccessfulSendHandler = null;

    /**
     * Add an email to the queue
     */
    public static function queue(string $to, string $subject, string $body, string $priority = 'low'): bool {
        try {
            $to = MailService::normalizeEnvelopeAddress($to);
            $subject = MailService::normalizeHeaderValue($subject);
            $db = Database::getInstance();
            $stmt = $db->prepare("INSERT INTO mail_queue (recipient, subject, body, priority, status) VALUES (?, ?, ?, ?, 'pending')");
            return $stmt->execute([$to, $subject, $body, $priority]);
        } catch (Exception $e) {
            return false;
        }
    }

    public static function setMailServiceFactoryForTests(?callable $factory): void
    {
        self::$mailServiceFactory = $factory;
    }

    public static function setAfterSuccessfulSendHandlerForTests(?callable $handler): void
    {
        self::$afterSuccessfulSendHandler = $handler;
    }

    /**
     * Process a batch of pending emails (triggered by cron)
     */
    public static function processBatch(): array {
        $database = Database::getInstance();
        $db = $database->getConnection();
        if (!$db instanceof PDO) {
            return ['error' => 'Database connection unavailable.'];
        }

        // 0. Check if SMTP is configured to avoid PHP warnings
        $host = trim(Setting::get('email_smtp_host', ''));
        if (empty($host)) {
            return ['error' => 'SMTP not configured. Skipping queue.'];
        }

        // 1. Get limit from settings
        $limit = (int)Setting::get('email_limit_per_minute', '20');

        try {
            $mailService = is_callable(self::$mailServiceFactory)
                ? (self::$mailServiceFactory)()
                : MailService::createFromSettings();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        // 2. Claim eligible rows before sending so a crash cannot silently resend the same message on the next run.
        $emails = self::claimBatch($db, max(1, $limit));

        if (empty($emails)) return ['sent' => 0, 'failed' => 0];

        $results = ['sent' => 0, 'failed' => 0];

        try {
            foreach ($emails as $email) {
                try {
                    $delivered = $mailService->send($email['recipient'], $email['subject'], $email['body']);
                } catch (Throwable $e) {
                    self::markFailed($db, (int)$email['id'], $e->getMessage());
                    $results['failed']++;
                    continue;
                }

                if ($delivered) {
                    if (is_callable(self::$afterSuccessfulSendHandler)) {
                        (self::$afterSuccessfulSendHandler)($email, $db);
                    }

                    self::markSent($db, (int)$email['id']);
                    $results['sent']++;
                } else {
                    self::markFailed($db, (int)$email['id'], 'SMTP transport returned a non-success response.');
                    $results['failed']++;
                }
            }
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function claimBatch(PDO $db, int $limit): array
    {
        $stmt = $db->prepare("
            SELECT *
            FROM mail_queue
            WHERE status = 'pending'
              AND (
                    sent_at IS NULL
                    OR (last_error = ? AND sent_at < DATE_SUB(NOW(), INTERVAL " . self::STALE_CLAIM_MINUTES . " MINUTE))
                  )
            ORDER BY priority ASC, created_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, self::CLAIM_MARKER, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($candidates === []) {
            return [];
        }

        $claimed = [];
        foreach ($candidates as $candidate) {
            $emailId = (int)($candidate['id'] ?? 0);
            if ($emailId <= 0) {
                continue;
            }

            $claimStmt = $db->prepare("
                UPDATE mail_queue
                SET last_error = ?, sent_at = NOW(), attempts = attempts + 1
                WHERE id = ?
                  AND status = 'pending'
                  AND (
                        sent_at IS NULL
                        OR (last_error = ? AND sent_at = ?)
                      )
            ");
            $claimStmt->execute([
                self::CLAIM_MARKER,
                $emailId,
                self::CLAIM_MARKER,
                $candidate['sent_at'],
            ]);

            if ($claimStmt->rowCount() !== 1) {
                continue;
            }

            $candidate['last_error'] = self::CLAIM_MARKER;
            $claimed[] = $candidate;
        }

        return $claimed;
    }

    private static function markSent(PDO $db, int $emailId): void
    {
        $stmt = $db->prepare("
            UPDATE mail_queue
            SET status = 'sent',
                sent_at = NOW(),
                last_error = NULL
            WHERE id = ?
              AND status = 'pending'
              AND last_error = ?
        ");
        $stmt->execute([$emailId, self::CLAIM_MARKER]);
    }

    private static function markFailed(PDO $db, int $emailId, string $message): void
    {
        $stmt = $db->prepare("
            UPDATE mail_queue
            SET status = 'failed',
                last_error = ?,
                sent_at = NULL
            WHERE id = ?
              AND status = 'pending'
              AND last_error = ?
        ");
        $stmt->execute([$message, $emailId, self::CLAIM_MARKER]);
    }
}
