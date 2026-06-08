<?php

namespace App\Service;

use App\Core\Auth;
use App\Core\Database;
use App\Model\Setting;
use App\Service\Database\SchemaService;
use PDO;

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
    private static $afterMutationStepHandler = null;
    private static bool $skipSchemaForTests = false;
    private static bool $runtimeSchemaReady = false;
    private static bool $runtimeReadSchemaReady = false;

    public static function setSkipSchemaForTests(bool $skip): void
    {
        self::$skipSchemaForTests = $skip;
        self::$runtimeSchemaReady = false;
        self::$runtimeReadSchemaReady = false;
    }

    public static function ensureSchema(): void
    {
        if (self::$skipSchemaForTests || self::$runtimeSchemaReady) {
            return;
        }

        SchemaService::ensureTables([
            'support_tickets',
            'support_ticket_messages',
            'support_ticket_events',
        ], true);
        self::$runtimeSchemaReady = true;
        self::$runtimeReadSchemaReady = true;
    }

    public static function ensureReadSchema(): void
    {
        if (self::$skipSchemaForTests || self::$runtimeSchemaReady || self::$runtimeReadSchemaReady) {
            return;
        }

        $requiredTables = [
            'support_tickets',
            'support_ticket_messages',
            'support_ticket_events',
        ];
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (?, ?, ?)
        ");
        $stmt->execute($requiredTables);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
            $found[(string)$tableName] = true;
        }

        $missing = array_values(array_filter($requiredTables, static fn(string $tableName): bool => empty($found[$tableName])));
        if ($missing !== []) {
            throw new \RuntimeException('Ticket schema is unavailable. Missing table(s): ' . implode(', ', $missing) . '.');
        }

        self::$runtimeReadSchemaReady = true;
    }

    public static function setAfterMutationStepHandlerForTests(?callable $handler): void
    {
        self::$afterMutationStepHandler = $handler;
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

    private static function runTicketWriteTransaction(callable $callback): mixed
    {
        $db = Database::getInstance()->getConnection();
        $startedTransaction = !$db->inTransaction();

        if ($startedTransaction) {
            $db->beginTransaction();
        }

        try {
            $result = $callback($db);
            if ($startedTransaction) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function fireMutationStep(string $step, array $context = []): void
    {
        if (!is_callable(self::$afterMutationStepHandler)) {
            return;
        }

        (self::$afterMutationStepHandler)([
            'step' => $step,
            'context' => $context,
        ]);
    }

    public static function createSupportTicket(int $userId, string $subject, string $body, ?string $ipAddress = null): string
    {
        self::ensureSchema();

        $subject = self::normalizeSubject($subject);
        $body = self::normalizeBody($body);
        if ($subject === '' || $body === '') {
            throw new \RuntimeException('A subject and message are required.');
        }

        $publicId = self::generatePublicId();
        $now = date('Y-m-d H:i:s');
        $user = self::getUserIdentity($userId);
        $encSubject = EncryptionService::encrypt($subject);
        $encName = $user['username'] !== '' ? EncryptionService::encrypt($user['username']) : null;
        $encEmail = $user['email'] !== '' ? EncryptionService::encrypt($user['email']) : null;
        $encIp = $ipAddress !== null && $ipAddress !== '' ? EncryptionService::encrypt($ipAddress) : null;
        $ticketId = 0;
        self::runTicketWriteTransaction(static function (PDO $db) use (&$ticketId, $publicId, $userId, $now, $encSubject, $encName, $encEmail, $encIp, $body): void {
            $stmt = $db->prepare("
                INSERT INTO support_tickets (public_id, ticket_type, user_id, status, priority, subject, submitter_name, submitter_email, source, ip_address, last_reply_at, last_user_reply_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$publicId, self::TYPE_SUPPORT, $userId, self::STATUS_OPEN, self::PRIORITY_NORMAL, $encSubject, $encName, $encEmail, 'account', $encIp, $now, $now]);
            $ticketId = (int)$db->lastInsertId();
            self::fireMutationStep('create_support_ticket.after_ticket_insert', ['ticket_id' => $ticketId]);

            self::insertMessageWithConnection($db, $ticketId, 'user', $userId, 'intake', $body);
            self::fireMutationStep('create_support_ticket.after_message', ['ticket_id' => $ticketId]);
            self::addEventWithConnection($db, $ticketId, 'opened', 'user', $userId, ['status' => self::STATUS_OPEN]);
            self::addAdminActivityWithConnection($db, 'support_ticket', $ticketId, 'opened', 'Ticket opened', $body);
        });

        self::notifyStaff(
            'New support ticket',
            ($user['username'] !== '' ? $user['username'] : 'A user') . ' opened ticket ' . $publicId . '.',
            ['requests.manage'],
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

        $publicId = self::generatePublicId();
        $now = date('Y-m-d H:i:s');
        $priority = in_array($type, [self::TYPE_ABUSE, self::TYPE_DMCA], true) ? self::PRIORITY_HIGH : self::PRIORITY_NORMAL;

        $encSubject = EncryptionService::encrypt($subject);
        $encName = $name !== '' ? EncryptionService::encrypt($name) : null;
        $encEmail = $email !== '' ? EncryptionService::encrypt($email) : null;
        $encIp = $ipAddress !== '' ? EncryptionService::encrypt($ipAddress) : null;
        $encMetadata = !empty($metadata) ? EncryptionService::encrypt(json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : null;

        $ticketId = 0;
        self::runTicketWriteTransaction(static function (PDO $db) use (&$ticketId, $publicId, $type, $userId, $priority, $encSubject, $encName, $encEmail, $source, $encMetadata, $relatedFileId, $encIp, $now, $body): void {
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
            self::fireMutationStep('create_external_ticket.after_ticket_insert', ['ticket_id' => $ticketId, 'ticket_type' => $type]);
            self::insertMessageWithConnection($db, $ticketId, $userId ? 'user' : 'system', $userId, 'intake', $body);
            self::fireMutationStep('create_external_ticket.after_message', ['ticket_id' => $ticketId, 'ticket_type' => $type]);
            self::addEventWithConnection($db, $ticketId, 'opened', $userId ? 'user' : 'system', $userId, ['status' => self::STATUS_OPEN, 'ticket_type' => $type]);
            self::addAdminActivityWithConnection($db, self::activityRequestTypeForTicketType($type), $ticketId, 'opened', self::requestTypeLabel($type) . ' opened', $body);
        });

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

        $notifyCapabilities = match ($type) {
            self::TYPE_ABUSE => ['abuse.manage', 'requests.manage'],
            self::TYPE_DMCA => ['dmca.manage', 'requests.manage'],
            default => ['requests.manage'],
        };

        self::notifyStaff(
            $notifyConfig['title'],
            $notifyConfig['message'],
            $notifyCapabilities,
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
        self::ensureReadSchema();
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
        self::ensureReadSchema();
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

        $ticket = null;
        $ticketId = 0;
        $now = date('Y-m-d H:i:s');
        $wasClosed = false;
        $status = self::STATUS_OPEN;
        self::runTicketWriteTransaction(static function (PDO $db) use (&$ticket, &$ticketId, &$wasClosed, $publicId, $userId, $body, $now, $status): void {
            $stmt = $db->prepare("SELECT * FROM support_tickets WHERE public_id = ? AND user_id = ? AND ticket_type = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$publicId, $userId, self::TYPE_SUPPORT]);
            $ticket = $stmt->fetch();
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }

            $ticketId = (int)$ticket['id'];
            $wasClosed = (string)$ticket['status'] === self::STATUS_CLOSED;
            self::insertMessageWithConnection($db, $ticketId, 'user', $userId, 'reply', $body);
            self::fireMutationStep('add_user_reply.after_message', ['ticket_id' => $ticketId]);

            $update = $db->prepare("
                UPDATE support_tickets
                SET status = ?, updated_at = CURRENT_TIMESTAMP, last_reply_at = ?, last_user_reply_at = ?, closed_at = NULL, closed_by_user_id = NULL, closed_by_admin_id = NULL
                WHERE id = ?
            ");
            $update->execute([$status, $now, $now, $ticketId]);
            self::fireMutationStep('add_user_reply.after_ticket_update', ['ticket_id' => $ticketId]);

            if ($wasClosed) {
                self::addEventWithConnection($db, $ticketId, 'reopened', 'user', $userId, ['status' => $status]);
                self::addAdminActivityWithConnection($db, 'support_ticket', $ticketId, 'reopened', 'Ticket reopened by user', $body);
            }
            self::addEventWithConnection($db, $ticketId, 'replied_user', 'user', $userId, ['status' => $status]);
            self::addAdminActivityWithConnection($db, 'support_ticket', $ticketId, 'reply', 'User reply', $body);
        });

        $user = self::getUserIdentity($userId);
        self::notifyStaff(
            'User replied to a ticket',
            ($user['username'] !== '' ? $user['username'] : 'A user') . ' replied to ticket ' . $publicId . '.',
            ['requests.manage'],
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
            ],
            [
                'hidden_from_others' => !empty($ticket['hidden_from_others']),
                'hidden_by_admin_user_id' => (int)($ticket['hidden_by_admin_user_id'] ?? 0),
                'assigned_staff_user_id' => (int)($ticket['assigned_staff_user_id'] ?? 0),
            ]
        );
    }

    public static function closeTicketByUser(string $publicId, int $userId): void
    {
        self::ensureSchema();
        $now = date('Y-m-d H:i:s');
        self::runTicketWriteTransaction(static function (PDO $db) use ($publicId, $userId, $now): void {
            $stmt = $db->prepare("SELECT id, status FROM support_tickets WHERE public_id = ? AND user_id = ? AND ticket_type = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$publicId, $userId, self::TYPE_SUPPORT]);
            $ticket = $stmt->fetch();
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }
            if ((string)$ticket['status'] === self::STATUS_CLOSED) {
                return;
            }

            $update = $db->prepare("
                UPDATE support_tickets
                SET status = ?, updated_at = CURRENT_TIMESTAMP, closed_at = ?, closed_by_user_id = ?, closed_by_admin_id = NULL
                WHERE id = ?
            ");
            $update->execute([self::STATUS_CLOSED, $now, $userId, (int)$ticket['id']]);
            self::fireMutationStep('close_ticket_by_user.after_ticket_update', ['ticket_id' => (int)$ticket['id']]);
            self::addEventWithConnection($db, (int)$ticket['id'], 'closed', 'user', $userId, ['status' => self::STATUS_CLOSED]);
            self::addAdminActivityWithConnection($db, 'support_ticket', (int)$ticket['id'], 'status_change', 'Ticket closed by user', 'Ticket status changed to closed.');
        });
    }

    public static function getAdminSupportItems(
        string|array|null $queueType = null,
        int $limit = 1000,
        bool $includeThread = true,
        ?bool $archived = null,
        string $status = '',
        string $priority = '',
        ?string $staleBefore = null,
        bool $includeInitialMessage = false
    ): array
    {
        self::ensureReadSchema();
        $db = Database::getInstance()->getConnection();

        $where = [];
        $params = [];
        $queueTypes = is_array($queueType) ? $queueType : [$queueType];
        if (is_array($queueType) && $queueTypes === []) {
            return [];
        }
        $ticketTypes = [];
        foreach ($queueTypes as $candidateQueueType) {
            $ticketType = self::ticketTypeForQueueType(is_string($candidateQueueType) ? $candidateQueueType : null);
            if ($ticketType !== null) {
                $ticketTypes[$ticketType] = true;
            }
        }
        $ticketTypes = array_keys($ticketTypes);
        if ($ticketTypes !== []) {
            $where[] = 't.ticket_type IN (' . implode(', ', array_fill(0, count($ticketTypes), '?')) . ')';
            foreach ($ticketTypes as $ticketType) {
                $params[] = $ticketType;
            }
        }

        if (!Auth::isSuperAdmin()) {
            $viewerId = (int)(Auth::id() ?? 0);
            if ($viewerId > 0) {
                $where[] = '(t.hidden_from_others = 0 OR t.hidden_by_admin_user_id = ? OR t.assigned_staff_user_id = ?)';
                $params[] = $viewerId;
                $params[] = $viewerId;
            } else {
                $where[] = 't.hidden_from_others = 0';
            }
        }
        if ($archived === true) {
            $where[] = 't.status = ?';
            $params[] = self::STATUS_CLOSED;
        } elseif ($archived === false) {
            $where[] = 't.status <> ?';
            $params[] = self::STATUS_CLOSED;
        }
        if ($status !== '') {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        if (in_array($priority, [self::PRIORITY_NORMAL, self::PRIORITY_HIGH], true)) {
            $where[] = 't.priority = ?';
            $params[] = $priority;
        }
        if ($staleBefore !== null && $staleBefore !== '') {
            $where[] = 't.updated_at <= ?';
            $params[] = $staleBefore;
        }

        $initialMessageSelect = $includeInitialMessage
            ? "(
                   SELECT m.body
                   FROM support_ticket_messages m
                   WHERE m.ticket_id = t.id
                   AND m.message_type = 'intake'
                   ORDER BY m.created_at ASC, m.id ASC
                   LIMIT 1
               )"
            : 'NULL';

        $sql = "
            SELECT t.*,
                   u.username,
                   u.email,
                   assigned_user.username AS assigned_staff_username,
                   assigned_user.role AS assigned_staff_role,
                   hidden_user.username AS hidden_by_admin_username,
                   (
                       SELECT m.body
                       FROM support_ticket_messages m
                       WHERE m.ticket_id = t.id
                       AND m.message_type IN ('intake','reply')
                       ORDER BY m.created_at DESC, m.id DESC
                       LIMIT 1
                   ) AS latest_message,
                   {$initialMessageSelect} AS initial_message
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN users assigned_user ON assigned_user.id = t.assigned_staff_user_id
            LEFT JOIN users hidden_user ON hidden_user.id = t.hidden_by_admin_user_id
            " . ($where !== [] ? 'WHERE ' . implode(' AND ', $where) : '') . "
            ORDER BY t.updated_at DESC, t.id DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex, max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $thread = $includeThread ? self::getThread((int)$row['id'], true) : [];
            $metadata = self::decodeMetadata((string)($row['metadata_json'] ?? ''));
            $ticketType = (string)($row['ticket_type'] ?? self::TYPE_SUPPORT);
            $initialMessage = EncryptionService::decrypt((string)($row['initial_message'] ?? ''));
            if ($includeThread) {
                foreach ($thread as $message) {
                    if (($message['message_type'] ?? '') === 'intake') {
                        $initialMessage = (string)($message['body'] ?? '');
                        break;
                    }
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
            $assignedStaffUsername = EncryptionService::decrypt((string)($row['assigned_staff_username'] ?? ''));
            $hiddenByAdminUsername = EncryptionService::decrypt((string)($row['hidden_by_admin_username'] ?? ''));

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
                'thread' => $includeThread ? $thread : [],
                'priority' => (string)$row['priority'],
                'latest_message' => EncryptionService::decrypt((string)($row['latest_message'] ?? '')),
                'reason' => $reason,
                'signature' => $signature,
                'status_options' => self::adminStatusOptions(),
                'status_labels' => self::adminStatusOptions(),
                'metadata' => $metadata,
                'assigned_staff_user_id' => !empty($row['assigned_staff_user_id']) ? (int)$row['assigned_staff_user_id'] : null,
                'assigned_staff_username' => $assignedStaffUsername !== '' ? $assignedStaffUsername : null,
                'assigned_staff_role' => !empty($row['assigned_staff_role']) ? (string)$row['assigned_staff_role'] : null,
                'hidden_from_others' => !empty($row['hidden_from_others']),
                'hidden_by_admin_user_id' => !empty($row['hidden_by_admin_user_id']) ? (int)$row['hidden_by_admin_user_id'] : null,
                'hidden_by_admin_username' => $hiddenByAdminUsername !== '' ? $hiddenByAdminUsername : null,
            ];
        }

        return $items;
    }

    public static function updateAssignment(int $ticketId, int $actorUserId, ?int $assignedStaffUserId): void
    {
        self::ensureSchema();
        self::runTicketWriteTransaction(static function (PDO $db) use ($ticketId, $actorUserId, $assignedStaffUserId): void {
            $ticket = self::getTicketRowByIdWithConnection($db, $ticketId, true);
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }

            $stmt = $db->prepare("
                UPDATE support_tickets
                SET assigned_staff_user_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$assignedStaffUserId, $ticketId]);
            self::fireMutationStep('update_assignment.after_ticket_update', ['ticket_id' => $ticketId]);

            self::addEventWithConnection($db, $ticketId, 'assignment_changed', 'admin', $actorUserId, [
                'assigned_staff_user_id' => $assignedStaffUserId,
            ]);

            $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
            $label = $assignedStaffUserId !== null ? 'Ticket assigned' : 'Ticket unassigned';
            $body = $assignedStaffUserId !== null
                ? 'Ticket ownership was assigned to staff user #' . $assignedStaffUserId . '.'
                : 'Ticket ownership was cleared.';
            self::addAdminActivityWithConnection($db, self::activityRequestTypeForTicketType($ticketType), $ticketId, 'assignment', $label, $body);
        });
    }

    public static function updateVisibility(int $ticketId, int $actorUserId, bool $hidden): void
    {
        self::ensureSchema();
        self::runTicketWriteTransaction(static function (PDO $db) use ($ticketId, $actorUserId, $hidden): void {
            $ticket = self::getTicketRowByIdWithConnection($db, $ticketId, true);
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }

            $stmt = $db->prepare("
                UPDATE support_tickets
                SET hidden_from_others = ?, hidden_by_admin_user_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $hidden ? 1 : 0,
                $hidden ? $actorUserId : null,
                $ticketId,
            ]);
            self::fireMutationStep('update_visibility.after_ticket_update', ['ticket_id' => $ticketId]);

            self::addEventWithConnection($db, $ticketId, $hidden ? 'hidden_from_queue' : 'shown_in_queue', 'admin', $actorUserId, [
                'hidden_from_others' => $hidden ? 1 : 0,
            ]);

            $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
            self::addAdminActivityWithConnection(
                $db,
                self::activityRequestTypeForTicketType($ticketType),
                $ticketId,
                $hidden ? 'hidden' : 'unhidden',
                $hidden ? 'Ticket hidden from other staff' : 'Ticket visible to staff again',
                $hidden
                    ? 'This ticket is now hidden from other staff except the assigned staff member, the admin who hid it, and the protected super admin.'
                    : 'This ticket is visible to eligible staff again.'
            );
        });
    }

    public static function addAdminReply(int $ticketId, int $adminUserId, string $message, string $statusAfterReply = self::STATUS_WAITING_USER, ?string $emailSubject = null): void
    {
        self::ensureSchema();
        $message = self::normalizeBody($message);
        if ($message === '') {
            throw new \RuntimeException('A reply message is required.');
        }

        $statusAfterReply = in_array($statusAfterReply, array_keys(self::adminStatusOptions()), true) ? $statusAfterReply : self::STATUS_WAITING_USER;
        $now = date('Y-m-d H:i:s');
        $ticket = null;
        $recipient = [];
        $subjectLine = '';
        $ticketType = self::TYPE_SUPPORT;
        $publicId = '';
        self::runTicketWriteTransaction(static function (PDO $db) use (&$ticket, &$recipient, &$subjectLine, &$ticketType, &$publicId, $ticketId, $adminUserId, $message, $statusAfterReply, $now): void {
            $ticket = self::getTicketRowByIdWithConnection($db, $ticketId, true);
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }

            $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
            $publicId = (string)($ticket['public_id'] ?? '');
            $recipient = self::getTicketRecipientIdentity($ticket);
            $subjectLine = EncryptionService::decrypt((string)$ticket['subject']);

            self::insertMessageWithConnection($db, $ticketId, 'admin', $adminUserId, 'reply', $message);
            self::fireMutationStep('add_admin_reply.after_message', ['ticket_id' => $ticketId]);

            $stmt = $db->prepare("
                UPDATE support_tickets
                SET status = ?, updated_at = CURRENT_TIMESTAMP, last_reply_at = ?, last_staff_reply_at = ?, closed_at = ?, closed_by_admin_id = ?, closed_by_user_id = NULL
                WHERE id = ?
            ");
            $closedAt = $statusAfterReply === self::STATUS_CLOSED ? $now : null;
            $closedByAdminId = $statusAfterReply === self::STATUS_CLOSED ? $adminUserId : null;
            $stmt->execute([$statusAfterReply, $now, $now, $closedAt, $closedByAdminId, $ticketId]);
            self::fireMutationStep('add_admin_reply.after_ticket_update', ['ticket_id' => $ticketId]);

            $requestTypeKey = self::activityRequestTypeForTicketType($ticketType);
            self::addEventWithConnection($db, $ticketId, 'replied_admin', 'admin', $adminUserId, ['status' => $statusAfterReply]);
            if ($statusAfterReply === self::STATUS_CLOSED) {
                self::addEventWithConnection($db, $ticketId, 'closed', 'admin', $adminUserId, ['status' => $statusAfterReply]);
            }
            self::addAdminActivityWithConnection($db, $requestTypeKey, $ticketId, 'reply', 'Staff reply', $message);
            if ($statusAfterReply === self::STATUS_CLOSED) {
                self::addAdminActivityWithConnection($db, $requestTypeKey, $ticketId, 'status_change', 'Ticket closed', 'Ticket closed as part of the staff reply.');
            }
        });

        if ($ticketType === self::TYPE_SUPPORT) {
            if ($recipient['email'] !== '' && self::shouldSendEmail('ticket_notify_user_on_staff_reply')) {
                MailService::sendTemplate($recipient['email'], 'ticket_staff_replied', [
                    '{ticket_id}' => $publicId,
                    '{ticket_subject}' => $subjectLine,
                    '{ticket_status}' => self::adminStatusOptions()[$statusAfterReply] ?? ucfirst($statusAfterReply),
                    '{ticket_type}' => 'support',
                    '{ticket_url}' => SeoService::trustedBaseUrl() . '/tickets/' . rawurlencode($publicId),
                    '{user_name}' => $recipient['username'],
                    '{user_email}' => $recipient['email'],
                    '{reply_message}' => $message,
                    '{support_inbox_email}' => self::supportInboxEmail(),
                ]);
            }
            if ($recipient['email'] !== '' && $statusAfterReply === self::STATUS_CLOSED && self::shouldSendEmail('ticket_notify_user_on_close')) {
                MailService::sendTemplate($recipient['email'], 'ticket_closed', [
                    '{ticket_id}' => $publicId,
                    '{ticket_subject}' => $subjectLine,
                    '{ticket_status}' => 'Closed',
                    '{ticket_type}' => 'support',
                    '{ticket_url}' => SeoService::trustedBaseUrl() . '/tickets/' . rawurlencode($publicId),
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
            try {
                $mail = MailService::createFromSettings();
                $mail->send($recipient['email'], trim((string)($emailSubject ?: ('Re: ' . $subjectLine))), $message);
            } catch (\Throwable $e) {
                \App\Core\Logger::warning('ticket external reply email failed after the reply was already committed', [
                    'ticket_id' => $ticketId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public static function addAdminNote(int $ticketId, int $adminUserId, string $message): void
    {
        self::ensureSchema();
        $message = self::normalizeBody($message);
        if ($message === '') {
            throw new \RuntimeException('A note is required.');
        }

        self::runTicketWriteTransaction(static function (PDO $db) use ($ticketId, $adminUserId, $message): void {
            $ticket = self::getTicketRowByIdWithConnection($db, $ticketId, true);
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }

            self::insertMessageWithConnection($db, $ticketId, 'admin', $adminUserId, 'note', $message);
            self::fireMutationStep('add_admin_note.after_message', ['ticket_id' => $ticketId]);
            self::addEventWithConnection($db, $ticketId, 'note_added', 'admin', $adminUserId);
            $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
            self::addAdminActivityWithConnection($db, self::activityRequestTypeForTicketType($ticketType), $ticketId, 'note', 'Internal note', $message);
        });
    }

    public static function updateStatusByAdmin(int $ticketId, int $adminUserId, string $status): void
    {
        self::ensureSchema();
        if (!in_array($status, array_keys(self::adminStatusOptions()), true)) {
            throw new \RuntimeException('Invalid ticket status.');
        }
        $now = date('Y-m-d H:i:s');
        $ticket = null;
        $ticketType = self::TYPE_SUPPORT;
        $user = [];
        $publicId = '';
        $subjectLine = '';
        self::runTicketWriteTransaction(static function (PDO $db) use (&$ticket, &$ticketType, &$user, &$publicId, &$subjectLine, $ticketId, $adminUserId, $status, $now): void {
            $ticket = self::getTicketRowByIdWithConnection($db, $ticketId, true);
            if (!$ticket) {
                throw new \RuntimeException('Ticket not found.');
            }

            $ticketType = (string)($ticket['ticket_type'] ?? self::TYPE_SUPPORT);
            $user = self::getTicketRecipientIdentity($ticket);
            $publicId = (string)($ticket['public_id'] ?? '');
            $subjectLine = EncryptionService::decrypt((string)$ticket['subject']);

            $closedAt = $status === self::STATUS_CLOSED ? $now : null;
            $closedByAdminId = $status === self::STATUS_CLOSED ? $adminUserId : null;
            $stmt = $db->prepare("
                UPDATE support_tickets
                SET status = ?, updated_at = CURRENT_TIMESTAMP, closed_at = ?, closed_by_admin_id = ?, closed_by_user_id = NULL
                WHERE id = ?
            ");
            $stmt->execute([$status, $closedAt, $closedByAdminId, $ticketId]);
            self::fireMutationStep('update_status_by_admin.after_ticket_update', ['ticket_id' => $ticketId]);

            self::addEventWithConnection($db, $ticketId, $status === self::STATUS_CLOSED ? 'closed' : 'status_changed', 'admin', $adminUserId, ['status' => $status]);
            self::addAdminActivityWithConnection($db, self::activityRequestTypeForTicketType($ticketType), $ticketId, 'status_change', 'Ticket status updated', 'Ticket status changed to ' . (self::adminStatusOptions()[$status] ?? $status) . '.');
        });

        if ($status === self::STATUS_CLOSED) {
            if ($ticketType === self::TYPE_SUPPORT && $user['email'] !== '' && self::shouldSendEmail('ticket_notify_user_on_close')) {
                MailService::sendTemplate($user['email'], 'ticket_closed', [
                    '{ticket_id}' => $publicId,
                    '{ticket_subject}' => $subjectLine,
                    '{ticket_status}' => 'Closed',
                    '{ticket_type}' => 'support',
                    '{ticket_url}' => SeoService::trustedBaseUrl() . '/tickets/' . rawurlencode($publicId),
                    '{user_name}' => $user['username'],
                    '{user_email}' => $user['email'],
                    '{reply_message}' => '',
                    '{support_inbox_email}' => self::supportInboxEmail(),
                ]);
            }
            if ($ticketType === self::TYPE_SUPPORT && !empty($ticket['user_id'])) {
                NotificationService::send((int)$ticket['user_id'], 'Ticket closed', 'Your support ticket "' . mb_strimwidth($subjectLine, 0, 72, '...') . '" was closed.', 'info');
            }
        }
    }

    public static function isArchivedStatus(string $status): bool
    {
        return $status === self::STATUS_CLOSED;
    }

    public static function getThread(int $ticketId, bool $includeNotesForAdmin = false): array
    {
        return self::getThreads([$ticketId], $includeNotesForAdmin)[$ticketId] ?? [];
    }

    public static function getThreads(array $ticketIds, bool $includeNotesForAdmin = false): array
    {
        $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn(int $ticketId): bool => $ticketId > 0)));
        if ($ticketIds === []) {
            return [];
        }

        self::ensureReadSchema();
        $db = Database::getInstance()->getConnection();
        $placeholders = implode(', ', array_fill(0, count($ticketIds), '?'));
        $stmt = $db->prepare("
            SELECT m.*, u.username
            FROM support_ticket_messages m
            LEFT JOIN users u ON u.id = m.author_user_id
            WHERE m.ticket_id IN ({$placeholders})
            " . ($includeNotesForAdmin ? "" : "AND m.message_type <> 'note'") . "
            ORDER BY m.ticket_id ASC, m.created_at ASC, m.id ASC
        ");
        foreach ($ticketIds as $index => $ticketId) {
            $stmt->bindValue($index + 1, $ticketId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $threads = array_fill_keys($ticketIds, []);
        foreach ($stmt->fetchAll() as $row) {
            $row['body'] = EncryptionService::decrypt((string)$row['body']);
            $row['author_name'] = !empty($row['username']) ? EncryptionService::decrypt((string)$row['username']) : null;
            $threads[(int)$row['ticket_id']][] = $row;
        }

        return $threads;
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
        return self::getTicketRowByIdWithConnection($db, $ticketId, false);
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

    private static function getTicketRowByIdWithConnection(PDO $db, int $ticketId, bool $forUpdate): ?array
    {
        $sql = Database::appendForUpdateClause($db, 'SELECT * FROM support_tickets WHERE id = ? LIMIT 1', $forUpdate);
        $stmt = $db->prepare($sql);
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function insertMessage(int $ticketId, string $authorType, ?int $authorUserId, string $messageType, string $body): void
    {
        $db = Database::getInstance()->getConnection();
        self::insertMessageWithConnection($db, $ticketId, $authorType, $authorUserId, $messageType, $body);
    }

    private static function insertMessageWithConnection(PDO $db, int $ticketId, string $authorType, ?int $authorUserId, string $messageType, string $body): void
    {
        $stmt = $db->prepare("
            INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, message_type, body)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$ticketId, $authorType, $authorUserId, $messageType, EncryptionService::encrypt($body)]);
    }

    private static function addEvent(int $ticketId, string $eventType, string $actorType, ?int $actorUserId = null, array $payload = []): void
    {
        $db = Database::getInstance()->getConnection();
        self::addEventWithConnection($db, $ticketId, $eventType, $actorType, $actorUserId, $payload);
    }

    private static function addEventWithConnection(PDO $db, int $ticketId, string $eventType, string $actorType, ?int $actorUserId = null, array $payload = []): void
    {
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
        self::addAdminActivityWithConnection($db, $requestType, $requestId, $activityType, $subject, $body);
    }

    private static function addAdminActivityWithConnection(PDO $db, string $requestType, int $requestId, string $activityType, ?string $subject = null, ?string $body = null): void
    {
        SchemaService::ensureTables(['admin_request_activity'], false);
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

    private static function notifyStaff(string $title, string $message, array $capabilities, ?string $templateKey = null, ?string $toggleKey = null, array $placeholders = [], ?array $ticketVisibility = null): void
    {
        foreach (self::staffRecipients($capabilities, $ticketVisibility) as $staffUser) {
            NotificationService::send((int)$staffUser['id'], $title, $message, 'info');
        }

        $supportInbox = self::supportInboxEmail();
        if (
            $supportInbox !== ''
            && $templateKey !== null
            && ($toggleKey === null || self::shouldSendEmail($toggleKey))
            && !self::ticketVisibilityRestrictsRecipients($ticketVisibility)
        ) {
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

    private static function staffRecipients(array $capabilities, ?array $ticketVisibility = null): array
    {
        $db = Database::getInstance()->getConnection();
        $rows = $db->query("SELECT id, role FROM users WHERE role IN ('admin', 'moderator') AND status = 'active'")->fetchAll();
        $allowed = [];
        foreach ($rows as $row) {
            $userId = (int)($row['id'] ?? 0);
            $role = (string)($row['role'] ?? 'user');
            if ($userId <= 0) {
                continue;
            }
            if (!self::staffUserCanSeeTicketVisibility($userId, $ticketVisibility)) {
                continue;
            }
            foreach ($capabilities as $capability) {
                if (StaffPermissionService::userHasCapability($userId, $role, $capability)) {
                    $allowed[] = ['id' => $userId];
                    break;
                }
            }
        }
        return $allowed;
    }

    private static function ticketVisibilityRestrictsRecipients(?array $ticketVisibility): bool
    {
        return !empty($ticketVisibility['hidden_from_others']);
    }

    private static function staffUserCanSeeTicketVisibility(int $staffUserId, ?array $ticketVisibility): bool
    {
        if (!self::ticketVisibilityRestrictsRecipients($ticketVisibility)) {
            return true;
        }

        if ($staffUserId <= 0) {
            return false;
        }

        $hiddenByAdminUserId = (int)($ticketVisibility['hidden_by_admin_user_id'] ?? 0);
        $assignedStaffUserId = (int)($ticketVisibility['assigned_staff_user_id'] ?? 0);

        if ($hiddenByAdminUserId > 0 && $staffUserId === $hiddenByAdminUserId) {
            return true;
        }

        if ($assignedStaffUserId > 0 && $staffUserId === $assignedStaffUserId) {
            return true;
        }

        return self::staffUserIsSuperAdmin($staffUserId);
    }

    private static function staffUserIsSuperAdmin(int $staffUserId): bool
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT is_super_admin FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$staffUserId]);
        return (int)$stmt->fetchColumn() === 1;
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

    private static function ticketTypeForQueueType(?string $queueType): ?string
    {
        return match ($queueType) {
            'support_ticket' => self::TYPE_SUPPORT,
            'site_request' => self::TYPE_CONTACT,
            'abuse_report' => self::TYPE_ABUSE,
            'dmca_report' => self::TYPE_DMCA,
            default => null,
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
        return mb_substr(MailService::normalizeHeaderValue($subject), 0, 200);
    }

    private static function normalizeBody(string $body): string
    {
        return mb_substr(trim($body), 0, 20000);
    }
}
