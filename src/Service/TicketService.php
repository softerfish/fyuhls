<?php

namespace App\Service;

use App\Core\Auth;
use App\Core\Database;
use App\Model\Setting;

class TicketService
{
    private const TYPE_SUPPORT = 'support';
    private const TYPE_CONTACT = 'contact';
    private const TYPE_ABUSE = 'abuse';
    private const TYPE_DMCA = 'dmca';

    private const STATUS_OPEN = 'open';
    private const STATUS_WAITING_USER = 'waiting_user';
    private const STATUS_WAITING_STAFF = 'waiting_staff';
    private const STATUS_CLOSED = 'closed';

    private const PRIORITY_NORMAL = 'normal';
    private const PRIORITY_HIGH = 'high';

    public static function ensureSchema(): void
    {
        $db = Database::getInstance()->getConnection();

        $db->exec("
            CREATE TABLE IF NOT EXISTS support_tickets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id VARCHAR(32) NOT NULL,
                ticket_type VARCHAR(32) NOT NULL DEFAULT 'support',
                user_id BIGINT UNSIGNED NULL,
                status ENUM('open','waiting_user','waiting_staff','closed') NOT NULL DEFAULT 'open',
                priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
                subject TEXT NOT NULL,
                submitter_name TEXT NULL,
                submitter_email TEXT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'account',
                metadata_json TEXT NULL,
                related_file_id BIGINT UNSIGNED NULL,
                ip_address VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_reply_at DATETIME NULL,
                last_user_reply_at DATETIME NULL,
                last_staff_reply_at DATETIME NULL,
                closed_at DATETIME NULL,
                closed_by_user_id BIGINT UNSIGNED NULL,
                closed_by_admin_id BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY support_tickets_public_id_unique (public_id),
                KEY support_tickets_user_status_idx (user_id, status, updated_at),
                KEY support_tickets_type_status_updated_idx (ticket_type, status, updated_at),
                KEY support_tickets_status_updated_idx (status, updated_at),
                KEY support_tickets_priority_status_idx (priority, status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS support_ticket_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id BIGINT UNSIGNED NOT NULL,
                author_type ENUM('user','admin','system') NOT NULL,
                author_user_id BIGINT UNSIGNED NULL,
                message_type ENUM('intake','reply','note') NOT NULL DEFAULT 'reply',
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY support_ticket_messages_ticket_idx (ticket_id, created_at),
                KEY support_ticket_messages_author_idx (author_user_id, created_at),
                CONSTRAINT support_ticket_messages_ticket_fk FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS support_ticket_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                actor_type ENUM('user','admin','system') NOT NULL DEFAULT 'system',
                actor_user_id BIGINT UNSIGNED NULL,
                payload_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY support_ticket_events_ticket_idx (ticket_id, created_at),
                KEY support_ticket_events_type_idx (event_type, created_at),
                CONSTRAINT support_ticket_events_ticket_fk FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::ensureTicketColumns($db);
    }

    public static function userStatusOptions(): array
    {
        return [
            self::STATUS_OPEN => 'Open',
            self::STATUS_WAITING_USER => 'Waiting on You',
            self::STATUS_WAITING_STAFF => 'Waiting on Staff',
            self::STATUS_CLOSED => 'Closed',
        ];
    }

    public static function adminStatusOptions(): array
    {
        return [
            self::STATUS_OPEN => 'Open',
            self::STATUS_WAITING_USER => 'Waiting on User',
            self::STATUS_WAITING_STAFF => 'Waiting on Staff',
            self::STATUS_CLOSED => 'Closed',
        ];
    }

    public static function createSupportTicket(int $userId, string $subject, string $body, ?string $ipAddress = null): string
    {
        self::ensureSchema();

        $subject = self::normalizeSubject($subject);
        $body = self::normalizeBody($body);
        if ($subject === '' || $body === '') {
            throw new \RuntimeException('A subject and message are required.');
        }

        $db = Database::getInstance()->getConnection();
        $publicId = self::generatePublicId();
        $now = date('Y-m-d H:i:s');
        $user = self::getUserIdentity($userId);
        $encSubject = EncryptionService::encrypt($subject);
        $encName = $user['username'] !== '' ? EncryptionService::encrypt($user['username']) : null;
        $encEmail = $user['email'] !== '' ? EncryptionService::encrypt($user['email']) : null;
        $encIp = $ipAddress !== null && $ipAddress !== '' ? EncryptionService::encrypt($ipAddress) : null;

        $stmt = $db->prepare("
            INSERT INTO support_tickets (public_id, ticket_type, user_id, status, priority, subject, submitter_name, submitter_email, source, ip_address, last_reply_at, last_user_reply_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$publicId, self::TYPE_SUPPORT, $userId, self::STATUS_OPEN, self::PRIORITY_NORMAL, $encSubject, $encName, $encEmail, 'account', $encIp, $now, $now]);
        $ticketId = (int)$db->lastInsertId();

        self::insertMessage($ticketId, 'user', $userId, 'intake', $body);
        self::addEvent($ticketId, 'opened', 'user', $userId, ['status' => self::STATUS_OPEN]);
        self::addAdminActivity('support_ticket', $ticketId, 'opened', 'Ticket opened', $body);

        self::notifyAdmins(
            'New support ticket',
            ($user['username'] !== '' ? $user['username'] : 'A user') . ' opened ticket ' . $publicId . '.',
            'ticket_opened_admin',
            'ticket_notify_admin_on_open',
            [
                '{ticket_id}' => $publicId,
                '{ticket_subject}' => $subject,
                '{ticket_status}' => 'Open',
                '{ticket_type}' => 'support',
                '{ticket_url}' => SeoService::trustedBaseUrl() . '/admin/requests',
                '{user_name}' => $user['username'],
                '{user_email}' => $user['email'],
                '{reply_message}' => $body,
                '{support_inbox_email}' => self::supportInboxEmail(),
            ]
        );

        if ($user['email'] !== '' && self::shouldSendEmail('ticket_notify_user_on_open')) {
            MailService::sendTemplate($user['email'], 'ticket_opened_user', [
                '{ticket_id}' => $publicId,
                '{ticket_subject}' => $subject,
                '{ticket_status}' => 'Open',
                '{ticket_type}' => 'support',
                '{ticket_url}' => SeoService::trustedBaseUrl() . '/tickets/' . rawurlencode($publicId),
                '{user_name}' => $user['username'],
                '{user_email}' => $user['email'],
                '{reply_message}' => $body,
                '{support_inbox_email}' => self::supportInboxEmail(),
            ]);
        }

        return $publicId;
    }

    public static function createExternalTicket(string $ticketType, array $payload): string
    {
        self::ensureSchema();

        $type = in_array($ticketType, [self::TYPE_CONTACT, self::TYPE_ABUSE, self::TYPE_DMCA], true) ? $ticketType : self::TYPE_CONTACT;
        $subject = self::normalizeSubject((string)($payload['subject'] ?? ''));
        $body = self::normalizeBody((string)($payload['body'] ?? ''));
        $name = self::normalizeName((string)($payload['name'] ?? ''));
        $email = self::normalizeEmail((string)($payload['email'] ?? ''));
        $ipAddress = (string)($payload['ip_address'] ?? '');
        $source = trim((string)($payload['source'] ?? 'public_form')) ?: 'public_form';
        $userId = !empty($payload['user_id']) ? (int)$payload['user_id'] : null;
        $relatedFileId = !empty($payload['related_file_id']) ? (int)$payload['related_file_id'] : null;
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        if ($subject === '' || $body === '') {
            throw new \RuntimeException('A subject and message are required.');
        }

        $db = Database::getInstance()->getConnection();
        $publicId = self::generatePublicId();
        $now = date('Y-m-d H:i:s');
        $priority = in_array($type, [self::TYPE_ABUSE, self::TYPE_DMCA], true) ? self::PRIORITY_HIGH : self::PRIORITY_NORMAL;

        $encSubject = EncryptionService::encrypt($subject);
        $encName = $name !== '' ? EncryptionService::encrypt($name) : null;
        $encEmail = $email !== '' ? EncryptionService::encrypt($email) : null;
        $encIp = $ipAddress !== '' ? EncryptionService::encrypt($ipAddress) : null;
        $encMetadata = !empty($metadata) ? EncryptionService::encrypt(json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null;

        $stmt = $db->prepare("
            INSERT INTO support_tickets (public_id, ticket_type, user_id, status, priority, subject, submitter_name, submitter_email, source, metadata_json, related_file_id, ip_address, last_reply_at, last_user_reply_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $publicId,
            $type,
            $userId,
            self::STATUS_OPEN,
            $priority,
            $encSubject,
            $encName,
            $encEmail,
            $source,
            $encMetadata,
            $relatedFileId,
            $encIp,
            $now,
            $now,
        ]);

        $ticketId = (int)$db->lastInsertId();
        self::insertMessage($ticketId, $userId ? 'user' : 'system', $userId, 'intake', $body);
        self::addEvent($ticketId, 'opened', $userId ? 'user' : 'system', $userId, ['status' => self::STATUS_OPEN, 'ticket_type' => $type]);
        self::addAdminActivity(self::activityRequestTypeForTicketType($type), $ticketId, 'opened', self::requestTypeLabel($type) . ' opened', $body);

        $notifyConfig = match ($type) {
            self::TYPE_CONTACT => [
                'title' => 'New contact ticket',
                'message' => ($name !== '' ? $name : 'A visitor') . ' submitted a contact ticket.',
                'template' => 'contact_submitted_admin',
                'toggle' => 'ticket_notify_admin_on_contact',
                'type_label' => 'contact',
            ],
            self::TYPE_ABUSE => [
                'title' => 'New abuse report',
                'message' => ($name !== '' ? $name : 'A reporter') . ' submitted an abuse report.',
                'template' => 'abuse_report_submitted_admin',
                'toggle' => 'ticket_notify_admin_on_abuse',
                'type_label' => 'abuse',
            ],
            default => [
                'title' => 'New DMCA notice',
                'message' => ($name !== '' ? $name : 'A reporter') . ' submitted a DMCA notice.',
                'template' => 'dmca_report_submitted_admin',
                'toggle' => 'ticket_notify_admin_on_dmca',
                'type_label' => 'dmca',
            ],
        };

        self::notifyAdmins(
            $notifyConfig['title'],
            $notifyConfig['message'],
            $notifyConfig['template'],
            $notifyConfig['toggle'],
            [
                '{ticket_id}' => $publicId,
                '{ticket_subject}' => $subject,
                '{ticket_status}' => 'Open',
                '{ticket_type}' => $notifyConfig['type_label'],
                '{ticket_url}' => SeoService::trustedBaseUrl() . '/admin/requests',
                '{user_name}' => $name,
                '{user_email}' => $email,
                '{reply_message}' => $body,
                '{support_inbox_email}' => self::supportInboxEmail(),
            ]
        );

        return $publicId;
    }

    public static function getUserTickets(int $userId): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT t.*,
                   (
                       SELECT m.body
                       FROM support_ticket_messages m
                       WHERE m.ticket_id = t.id
                       AND m.message_type IN ('intake','reply')
                       ORDER BY m.created_at DESC, m.id DESC
                       LIMIT 1
                   ) AS latest_message
            FROM support_tickets t
            WHERE t.user_id = ? AND t.ticket_type = ?
            ORDER BY t.updated_at DESC, t.id DESC
        ");
        $stmt->execute([$userId, self::TYPE_SUPPORT]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['subject'] = EncryptionService::decrypt((string)$row['subject']);
            $row['latest_message'] = EncryptionService::decrypt((string)($row['latest_message'] ?? ''));
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getUserTicketByPublicId(int $userId, string $publicId): ?array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_id = ? AND public_id = ? AND ticket_type = ? LIMIT 1");
        $stmt->execute([$userId, $publicId, self::TYPE_SUPPORT]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return null;
        }

        return self::hydrateTicket($ticket, true);
    }

    public static function addUserReply(string $publicId, int $userId, string $body): void
    {
        self::ensureSchema();
        $body = self::normalizeBody($body);
        if ($body === '') {
            throw new \RuntimeException('A reply is required.');
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM support_tickets WHERE public_id = ? AND user_id = ? AND ticket_type = ? LIMIT 1");
        $stmt->execute([$publicId, $userId, self::TYPE_SUPPORT]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }

        $ticketId = (int)$ticket['id'];
        $now = date('Y-m-d H:i:s');
        $wasClosed = (string)$ticket['status'] === self::STATUS_CLOSED;

        self::insertMessage($ticketId, 'user', $userId, 'reply', $body);

        $status = self::STATUS_OPEN;
        $stmt = $db->prepare("
            UPDATE support_tickets
            SET status = ?, updated_at = CURRENT_TIMESTAMP, last_reply_at = ?, last_user_reply_at = ?, closed_at = NULL, closed_by_user_id = NULL, closed_by_admin_id = NULL
            WHERE id = ?
        ");
        $stmt->execute([$status, $now, $now, $ticketId]);

        if ($wasClosed) {
            self::addEvent($ticketId, 'reopened', 'user', $userId, ['status' => $status]);
            self::addAdminActivity('support_ticket', $ticketId, 'reopened', 'Ticket reopened by user', $body);
        }
        self::addEvent($ticketId, 'replied_user', 'user', $userId, ['status' => $status]);
        self::addAdminActivity('support_ticket', $ticketId, 'reply', 'User reply', $body);

        $user = self::getUserIdentity($userId);
        self::notifyAdmins(
            'User replied to a ticket',
            ($user['username'] !== '' ? $user['username'] : 'A user') . ' replied to ticket ' . $publicId . '.',
            'ticket_user_replied',
            'ticket_notify_admin_on_user_reply',
            [
                '{ticket_id}' => $publicId,
                '{ticket_subject}' => EncryptionService::decrypt((string)$ticket['subject']),
                '{ticket_status}' => 'Open',
                '{ticket_type}' => 'support',
                '{ticket_url}' => SeoService::trustedBaseUrl() . '/admin/requests',
                '{user_name}' => $user['username'],
                '{user_email}' => $user['email'],
                '{reply_message}' => $body,
                '{support_inbox_email}' => self::supportInboxEmail(),
            ]
        );
    }

    public static function closeTicketByUser(string $publicId, int $userId): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, status FROM support_tickets WHERE public_id = ? AND user_id = ? AND ticket_type = ? LIMIT 1");
        $stmt->execute([$publicId, $userId, self::TYPE_SUPPORT]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }
        if ((string)$ticket['status'] === self::STATUS_CLOSED) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("
            UPDATE support_tickets
            SET status = ?, updated_at = CURRENT_TIMESTAMP, closed_at = ?, closed_by_user_id = ?, closed_by_admin_id = NULL
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_CLOSED, $now, $userId, (int)$ticket['id']]);
        self::addEvent((int)$ticket['id'], 'closed', 'user', $userId, ['status' => self::STATUS_CLOSED]);
        self::addAdminActivity('support_ticket', (int)$ticket['id'], 'status_change', 'Ticket closed by user', 'Ticket status changed to closed.');
    }

    public static function getAdminSupportItems(): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT t.*,
                   u.username,
                   u.email,
                   (
                       SELECT m.body
                       FROM support_ticket_messages m
                       WHERE m.ticket_id = t.id
                       AND m.message_type IN ('intake','reply')
                       ORDER BY m.created_at DESC, m.id DESC
                       LIMIT 1
                   ) AS latest_message
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            ORDER BY t.updated_at DESC, t.id DESC
        ");

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $thread = self::getThread((int)$row['id'], true);
            $metadata = self::decodeMetadata((string)($row['metadata_json'] ?? ''));
            $ticketType = (string)($row['ticket_type'] ?? self::TYPE_SUPPORT);
            $initialMessage = '';
            foreach ($thread as $message) {
                if (($message['message_type'] ?? '') === 'intake') {
                    $initialMessage = (string)($message['body'] ?? '');
                    break;
                }
            }
            $latestReply = null;
            foreach (array_reverse($thread) as $message) {
                if (($message['message_type'] ?? '') === 'reply') {
                    $latestReply = [
                        'created_at' => $message['created_at'],
                        'username' => $message['author_name'] ?? (($message['author_type'] ?? '') === 'admin' ? 'Staff' : null),
                    ];
                    break;
                }
            }

            $submitterName = self::decryptNullable((string)($row['submitter_name'] ?? ''));
            $submitterEmail = self::decryptNullable((string)($row['submitter_email'] ?? ''));
            $userName = EncryptionService::decrypt((string)($row['username'] ?? ''));
            $userEmail = EncryptionService::decrypt((string)($row['email'] ?? ''));

            if ($submitterName === '') {
                $submitterName = $userName;
            }
            if ($submitterEmail === '') {
                $submitterEmail = $userEmail;
            }

            $target = EncryptionService::decrypt((string)$row['subject']);
        $requestType = 'Support Ticket';
        $typeKey = 'support_ticket';
        $activityType = self::activityRequestTypeForTicketType($ticketType);
            $reason = null;
            $signature = null;

            if ($ticketType === self::TYPE_CONTACT) {
                $requestType = 'Site Request';
                $typeKey = 'site_request';
            } elseif ($ticketType === self::TYPE_ABUSE) {
                $requestType = 'Abuse Report';
                $typeKey = 'abuse_report';
                $reason = strtoupper((string)($metadata['reason'] ?? 'ABUSE'));
                $target = trim((string)($metadata['file_name'] ?? 'Reported File'));
                if (!empty($metadata['short_id'])) {
                    $target .= ' (' . (string)$metadata['short_id'] . ')';
                }
                if ($submitterName === '') {
                    $submitterName = 'Reporter IP';
                }
            } elseif ($ticketType === self::TYPE_DMCA) {
                $requestType = 'DMCA Report';
                $typeKey = 'dmca_report';
                $target = (string)($metadata['infringing_url'] ?? $target);
                $signature = (string)($metadata['signature'] ?? '');
            }

            $items[] = [
                'request_type' => $requestType,
                'type_key' => $typeKey,
                'activity_type' => $activityType,
                'backend' => 'ticket',
                'id' => (int)$row['id'],
                'public_id' => (string)$row['public_id'],
                'created_at' => $row['created_at'],
                'sort_at' => $row['updated_at'],
                'submitter_name' => $submitterName,
                'submitter_email' => $submitterEmail,
                'target' => $target,
                'summary' => EncryptionService::decrypt((string)($row['latest_message'] ?? '')),
                'details' => $initialMessage,
                'status' => (string)$row['status'],
                'latest_reply' => $latestReply,
                'thread' => $thread,
                'priority' => (string)$row['priority'],
                'latest_message' => EncryptionService::decrypt((string)($row['latest_message'] ?? '')),
                'reason' => $reason,
                'signature' => $signature,
                'status_options' => self::adminStatusOptions(),
                'status_labels' => self::adminStatusOptions(),
                'metadata' => $metadata,
            ];
        }

        return $items;
    }

    public static function addAdminReply(int $ticketId, int $adminUserId, string $message, string $statusAfterReply = self::STATUS_WAITING_USER, ?string $emailSubject = null): void
    {
        self::ensureSchema();
        $message = self::normalizeBody($message);
        if ($message === '') {
            throw new \RuntimeException('A reply message is required.');
        }

        $ticket = self::getTicketRowById($ticketId);
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }

        $statusAfterReply = in_array($statusAfterReply, array_keys(self::adminStatusOptions()), true) ? $statusAfterReply : self::STATUS_WAITING_USER;
        $now = date('Y-m-d H:i:s');
        $db = Database::getInstance()->getConnection();

        self::insertMessage($ticketId, 'admin', $adminUserId, 'reply', $message);

        $stmt = $db->prepare("
            UPDATE support_tickets
            SET status = ?, updated_at = CURRENT_TIMESTAMP, last_reply_at = ?, last_staff_reply_at = ?, closed_at = ?, closed_by_admin_id = ?, closed_by_user_id = NULL
            WHERE id = ?
        ");
        $closedAt = $statusAfterReply === self::STATUS_CLOSED ? $now : null;
        $closedByAdminId = $statusAfterReply === self::STATUS_CLOSED ? $adminUserId : null;
        $stmt->execute([$statusAfterReply, $now, $now, $closedAt, $closedByAdminId, $ticketId]);

        $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
        $requestTypeKey = self::activityRequestTypeForTicketType($ticketType);

        self::addEvent($ticketId, 'replied_admin', 'admin', $adminUserId, ['status' => $statusAfterReply]);
        if ($statusAfterReply === self::STATUS_CLOSED) {
            self::addEvent($ticketId, 'closed', 'admin', $adminUserId, ['status' => $statusAfterReply]);
        }
        self::addAdminActivity($requestTypeKey, $ticketId, 'reply', 'Staff reply', $message);
        if ($statusAfterReply === self::STATUS_CLOSED) {
            self::addAdminActivity($requestTypeKey, $ticketId, 'status_change', 'Ticket closed', 'Ticket closed as part of the staff reply.');
        }

        $recipient = self::getTicketRecipientIdentity($ticket);
        $subjectLine = EncryptionService::decrypt((string)$ticket['subject']);

        if ($ticketType === self::TYPE_SUPPORT) {
            if ($recipient['email'] !== '' && self::shouldSendEmail('ticket_notify_user_on_staff_reply')) {
                MailService::sendTemplate($recipient['email'], 'ticket_staff_replied', [
                    '{ticket_id}' => (string)$ticket['public_id'],
                    '{ticket_subject}' => $subjectLine,
                    '{ticket_status}' => self::adminStatusOptions()[$statusAfterReply] ?? ucfirst($statusAfterReply),
                    '{ticket_type}' => 'support',
                    '{ticket_url}' => SeoService::trustedBaseUrl() . '/tickets/' . rawurlencode((string)$ticket['public_id']),
                    '{user_name}' => $recipient['username'],
                    '{user_email}' => $recipient['email'],
                    '{reply_message}' => $message,
                    '{support_inbox_email}' => self::supportInboxEmail(),
                ]);
            }
            if ($recipient['email'] !== '' && $statusAfterReply === self::STATUS_CLOSED && self::shouldSendEmail('ticket_notify_user_on_close')) {
                MailService::sendTemplate($recipient['email'], 'ticket_closed', [
                    '{ticket_id}' => (string)$ticket['public_id'],
                    '{ticket_subject}' => $subjectLine,
                    '{ticket_status}' => 'Closed',
                    '{ticket_type}' => 'support',
                    '{ticket_url}' => SeoService::trustedBaseUrl() . '/tickets/' . rawurlencode((string)$ticket['public_id']),
                    '{user_name}' => $recipient['username'],
                    '{user_email}' => $recipient['email'],
                    '{reply_message}' => '',
                    '{support_inbox_email}' => self::supportInboxEmail(),
                ]);
            }
            if (!empty($ticket['user_id'])) {
                NotificationService::send((int)$ticket['user_id'], 'Ticket reply', 'Staff replied to your support ticket "' . mb_strimwidth($subjectLine, 0, 72, '...') . '".', 'info');
                if ($statusAfterReply === self::STATUS_CLOSED) {
                    NotificationService::send((int)$ticket['user_id'], 'Ticket closed', 'Your support ticket "' . mb_strimwidth($subjectLine, 0, 72, '...') . '" was closed.', 'info');
                }
            }
            return;
        }

        if ($recipient['email'] !== '') {
            $mail = MailService::createFromSettings();
            $mail->send($recipient['email'], trim((string)($emailSubject ?: ('Re: ' . $subjectLine))), $message);
        }
    }

    public static function addAdminNote(int $ticketId, int $adminUserId, string $message): void
    {
        self::ensureSchema();
        $message = self::normalizeBody($message);
        if ($message === '') {
            throw new \RuntimeException('A note is required.');
        }

        $ticket = self::getTicketRowById($ticketId);
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }

        self::insertMessage($ticketId, 'admin', $adminUserId, 'note', $message);
        self::addEvent($ticketId, 'note_added', 'admin', $adminUserId);
        $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
        self::addAdminActivity(self::activityRequestTypeForTicketType($ticketType), $ticketId, 'note', 'Internal note', $message);
    }

    public static function updateStatusByAdmin(int $ticketId, int $adminUserId, string $status): void
    {
        self::ensureSchema();
        if (!in_array($status, array_keys(self::adminStatusOptions()), true)) {
            throw new \RuntimeException('Invalid ticket status.');
        }
        $ticket = self::getTicketRowById($ticketId);
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }

        $db = Database::getInstance()->getConnection();
        $now = date('Y-m-d H:i:s');
        $closedAt = $status === self::STATUS_CLOSED ? $now : null;
        $closedByAdminId = $status === self::STATUS_CLOSED ? $adminUserId : null;
        $stmt = $db->prepare("
            UPDATE support_tickets
            SET status = ?, updated_at = CURRENT_TIMESTAMP, closed_at = ?, closed_by_admin_id = ?, closed_by_user_id = NULL
            WHERE id = ?
        ");
        $stmt->execute([$status, $closedAt, $closedByAdminId, $ticketId]);

        self::addEvent($ticketId, $status === self::STATUS_CLOSED ? 'closed' : 'status_changed', 'admin', $adminUserId, ['status' => $status]);
        $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
        self::addAdminActivity(self::activityRequestTypeForTicketType($ticketType), $ticketId, 'status_change', 'Ticket status updated', 'Ticket status changed to ' . (self::adminStatusOptions()[$status] ?? $status) . '.');

        if ($status === self::STATUS_CLOSED) {
            $user = self::getTicketRecipientIdentity($ticket);
            if ($ticketType === self::TYPE_SUPPORT && $user['email'] !== '' && self::shouldSendEmail('ticket_notify_user_on_close')) {
                MailService::sendTemplate($user['email'], 'ticket_closed', [
                    '{ticket_id}' => (string)$ticket['public_id'],
                    '{ticket_subject}' => EncryptionService::decrypt((string)$ticket['subject']),
                    '{ticket_status}' => 'Closed',
                    '{ticket_type}' => 'support',
                    '{ticket_url}' => SeoService::trustedBaseUrl() . '/tickets/' . rawurlencode((string)$ticket['public_id']),
                    '{user_name}' => $user['username'],
                    '{user_email}' => $user['email'],
                    '{reply_message}' => '',
                    '{support_inbox_email}' => self::supportInboxEmail(),
                ]);
            }
            if ($ticketType === self::TYPE_SUPPORT && !empty($ticket['user_id'])) {
                NotificationService::send((int)$ticket['user_id'], 'Ticket closed', 'Your support ticket "' . mb_strimwidth(EncryptionService::decrypt((string)$ticket['subject']), 0, 72, '...') . '" was closed.', 'info');
            }
        }
    }

    public static function isArchivedStatus(string $status): bool
    {
        return $status === self::STATUS_CLOSED;
    }

    public static function getThread(int $ticketId, bool $includeNotesForAdmin = false): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT m.*, u.username
            FROM support_ticket_messages m
            LEFT JOIN users u ON u.id = m.author_user_id
            WHERE m.ticket_id = ?
            " . ($includeNotesForAdmin ? "" : "AND m.message_type <> 'note'") . "
            ORDER BY m.created_at ASC, m.id ASC
        ");
        $stmt->execute([$ticketId]);

        $messages = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['body'] = EncryptionService::decrypt((string)$row['body']);
            $row['author_name'] = !empty($row['username']) ? EncryptionService::decrypt((string)$row['username']) : null;
            $messages[] = $row;
        }

        return $messages;
    }

    private static function hydrateTicket(array $ticket, bool $includeThread = false): array
    {
        $ticket['subject'] = EncryptionService::decrypt((string)$ticket['subject']);
        if ($includeThread) {
            $ticket['thread'] = self::getThread((int)$ticket['id'], false);
        }
        return $ticket;
    }

    private static function getTicketRowById(int $ticketId): ?array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM support_tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getAdminTicketById(int $ticketId): ?array
    {
        $row = self::getTicketRowById($ticketId);
        if (!$row) {
            return null;
        }

        $row['subject'] = EncryptionService::decrypt((string)$row['subject']);
        $row['submitter_name'] = self::decryptNullable((string)($row['submitter_name'] ?? ''));
        $row['submitter_email'] = self::decryptNullable((string)($row['submitter_email'] ?? ''));
        $row['metadata'] = self::decodeMetadata((string)($row['metadata_json'] ?? ''));
        return $row;
    }

    public static function getAdminTicketByPublicId(string $publicId): ?array
    {
        self::ensureSchema();
        $publicId = trim($publicId);
        if ($publicId === '') {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM support_tickets WHERE public_id = ? LIMIT 1");
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['subject'] = EncryptionService::decrypt((string)$row['subject']);
        $row['submitter_name'] = self::decryptNullable((string)($row['submitter_name'] ?? ''));
        $row['submitter_email'] = self::decryptNullable((string)($row['submitter_email'] ?? ''));
        $row['metadata'] = self::decodeMetadata((string)($row['metadata_json'] ?? ''));
        return $row;
    }

    public static function queueTypeKeyForTicketType(string $ticketType): string
    {
        return self::typeKeyForTicketType($ticketType);
    }

    public static function addAdminQueueActivity(int $ticketId, string $activityType, ?string $subject = null, ?string $body = null): void
    {
        $ticket = self::getTicketRowById($ticketId);
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.');
        }

        $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
        self::addAdminActivity(self::activityRequestTypeForTicketType($ticketType), $ticketId, $activityType, $subject, $body);
    }

    private static function insertMessage(int $ticketId, string $authorType, ?int $authorUserId, string $messageType, string $body): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, message_type, body)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$ticketId, $authorType, $authorUserId, $messageType, EncryptionService::encrypt($body)]);
    }

    private static function addEvent(int $ticketId, string $eventType, string $actorType, ?int $actorUserId = null, array $payload = []): void
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO support_ticket_events (ticket_id, event_type, actor_type, actor_user_id, payload_json)
            VALUES (?, ?, ?, ?, ?)
        ");
        $payloadJson = $payload ? EncryptionService::encrypt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null;
        $stmt->execute([$ticketId, $eventType, $actorType, $actorUserId, $payloadJson]);
    }

    private static function addAdminActivity(string $requestType, int $requestId, string $activityType, ?string $subject = null, ?string $body = null): void
    {
        $db = Database::getInstance()->getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_request_activity (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_type VARCHAR(50) NOT NULL,
                request_id BIGINT UNSIGNED NOT NULL,
                admin_user_id BIGINT UNSIGNED NULL,
                activity_type VARCHAR(32) NOT NULL,
                subject VARCHAR(255) NULL,
                body TEXT NULL,
                metadata_json TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_request_lookup (request_type, request_id, created_at),
                KEY idx_activity_type (activity_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $db->prepare("
            INSERT INTO admin_request_activity (request_type, request_id, admin_user_id, activity_type, subject, body, metadata_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $shouldEncrypt = self::isTicketBackedActivityType($requestType);
        $metadata = $shouldEncrypt ? ['encrypted' => true] : null;
        $stmt->execute([
            $requestType,
            $requestId,
            Auth::id() ? (int)Auth::id() : null,
            $activityType,
            $shouldEncrypt && $subject !== null ? EncryptionService::encrypt($subject) : $subject,
            $shouldEncrypt && $body !== null ? EncryptionService::encrypt($body) : $body,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    private static function isTicketBackedActivityType(string $requestType): bool
    {
        return in_array($requestType, ['support_ticket', 'ticket_site_request', 'ticket_abuse_report', 'ticket_dmca_report'], true);
    }

    private static function notifyAdmins(string $title, string $message, ?string $templateKey = null, ?string $toggleKey = null, array $placeholders = []): void
    {
        foreach (self::adminUsers() as $admin) {
            NotificationService::send((int)$admin['id'], $title, $message, 'info');
        }

        $supportInbox = self::supportInboxEmail();
        if ($supportInbox !== '' && $templateKey !== null && ($toggleKey === null || self::shouldSendEmail($toggleKey))) {
            MailService::sendTemplate($supportInbox, $templateKey, $placeholders);
        }
    }

    private static function supportInboxEmail(): string
    {
        $email = trim((string)Setting::get('ticket_support_inbox_email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $email = trim((string)Setting::get('admin_notification_email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $fallback = trim((string)Setting::get('email_from_address', ''));
        return filter_var($fallback, FILTER_VALIDATE_EMAIL) ? $fallback : '';
    }

    public static function getRateLimitConfig(string $key): array
    {
        $defaults = [
            'support_create_user' => ['max' => 5, 'window' => 3600],
            'support_create_ip' => ['max' => 10, 'window' => 3600],
            'support_reply_user' => ['max' => 20, 'window' => 3600],
            'support_reply_ip' => ['max' => 40, 'window' => 3600],
            'contact_ip' => ['max' => 6, 'window' => 3600],
            'abuse_ip' => ['max' => 12, 'window' => 3600],
            'dmca_ip' => ['max' => 30, 'window' => 3600],
        ];

        if (!isset($defaults[$key])) {
            return ['max' => 1, 'window' => 3600];
        }

        $settingsMap = [
            'support_create_user' => ['ticket_rate_limit_support_create_user', 'ticket_rate_limit_support_create_window'],
            'support_create_ip' => ['ticket_rate_limit_support_create_ip', 'ticket_rate_limit_support_create_window'],
            'support_reply_user' => ['ticket_rate_limit_support_reply_user', 'ticket_rate_limit_support_reply_window'],
            'support_reply_ip' => ['ticket_rate_limit_support_reply_ip', 'ticket_rate_limit_support_reply_window'],
            'contact_ip' => ['ticket_rate_limit_contact_ip', 'ticket_rate_limit_contact_window'],
            'abuse_ip' => ['ticket_rate_limit_abuse_ip', 'ticket_rate_limit_abuse_window'],
            'dmca_ip' => ['ticket_rate_limit_dmca_ip', 'ticket_rate_limit_dmca_window'],
        ];

        [$maxKey, $windowKey] = $settingsMap[$key];
        $default = $defaults[$key];
        $max = max(1, (int)Setting::get($maxKey, (string)$default['max']));
        $windowMinutes = max(1, (int)Setting::get($windowKey, (string)max(1, (int)round($default['window'] / 60))));

        return [
            'max' => $max,
            'window' => $windowMinutes * 60,
        ];
    }

    private static function shouldSendEmail(string $settingKey): bool
    {
        if (Setting::get('ticket_emails_enabled', '1') !== '1') {
            return false;
        }

        return Setting::get($settingKey, '1') === '1';
    }

    private static function adminUsers(): array
    {
        $db = Database::getInstance()->getConnection();
        return $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
    }

    private static function getUserIdentity(int $userId): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT username, email FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['username' => '', 'email' => ''];
        }

        return [
            'username' => EncryptionService::decrypt((string)$row['username']),
            'email' => EncryptionService::decrypt((string)$row['email']),
        ];
    }

    private static function getTicketRecipientIdentity(array $ticket): array
    {
        $user = !empty($ticket['user_id']) ? self::getUserIdentity((int)$ticket['user_id']) : ['username' => '', 'email' => ''];
        $submitterName = self::decryptNullable((string)($ticket['submitter_name'] ?? ''));
        $submitterEmail = self::decryptNullable((string)($ticket['submitter_email'] ?? ''));

        return [
            'username' => $submitterName !== '' ? $submitterName : $user['username'],
            'email' => $submitterEmail !== '' ? $submitterEmail : $user['email'],
        ];
    }

    private static function generatePublicId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function ensureTicketColumns(\PDO $db): void
    {
        $columns = [
            "ALTER TABLE support_tickets ADD COLUMN ticket_type VARCHAR(32) NOT NULL DEFAULT 'support' AFTER public_id",
            "ALTER TABLE support_tickets MODIFY COLUMN user_id BIGINT UNSIGNED NULL",
            "ALTER TABLE support_tickets ADD COLUMN submitter_name TEXT NULL AFTER subject",
            "ALTER TABLE support_tickets ADD COLUMN submitter_email TEXT NULL AFTER submitter_name",
            "ALTER TABLE support_tickets ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'account' AFTER submitter_email",
            "ALTER TABLE support_tickets ADD COLUMN metadata_json TEXT NULL AFTER source",
            "ALTER TABLE support_tickets ADD COLUMN related_file_id BIGINT UNSIGNED NULL AFTER metadata_json",
            "ALTER TABLE support_tickets ADD KEY support_tickets_type_status_updated_idx (ticket_type, status, updated_at)",
        ];

        foreach ($columns as $sql) {
            try {
                $db->exec($sql);
            } catch (\Throwable $e) {
                // Column or key likely already exists; keep booting.
            }
        }
    }

    private static function decodeMetadata(string $encryptedJson): array
    {
        $encryptedJson = trim($encryptedJson);
        if ($encryptedJson === '') {
            return [];
        }
        $json = EncryptionService::decrypt($encryptedJson);
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function decryptNullable(string $value): string
    {
        return $value !== '' ? EncryptionService::decrypt($value) : '';
    }

    private static function normalizeName(string $value): string
    {
        return mb_substr(trim($value), 0, 160);
    }

    private static function normalizeEmail(string $value): string
    {
        $value = mb_substr(trim($value), 0, 190);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private static function requestTypeLabel(string $ticketType): string
    {
        return match ($ticketType) {
            self::TYPE_CONTACT => 'Contact Ticket',
            self::TYPE_ABUSE => 'Abuse Report',
            self::TYPE_DMCA => 'DMCA Report',
            default => 'Support Ticket',
        };
    }

    private static function typeKeyForTicketType(string $ticketType): string
    {
        return match ($ticketType) {
            self::TYPE_CONTACT => 'site_request',
            self::TYPE_ABUSE => 'abuse_report',
            self::TYPE_DMCA => 'dmca_report',
            default => 'support_ticket',
        };
    }

    private static function activityRequestTypeForTicketType(string $ticketType): string
    {
        return match ($ticketType) {
            self::TYPE_CONTACT => 'ticket_site_request',
            self::TYPE_ABUSE => 'ticket_abuse_report',
            self::TYPE_DMCA => 'ticket_dmca_report',
            default => 'support_ticket',
        };
    }

    private static function normalizeSubject(string $subject): string
    {
        return mb_substr(trim($subject), 0, 200);
    }

    private static function normalizeBody(string $body): string
    {
        return mb_substr(trim($body), 0, 20000);
    }
}
