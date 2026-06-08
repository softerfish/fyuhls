<?php

namespace App\Service;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use PDO;
use App\Service\Database\SchemaService;

class StaffActivityService
{
    private static bool $runtimeSchemaReady = false;
    private const INTEGRITY_METADATA_KEY = '_audit_integrity';
    private const INTEGRITY_VERSION = 2;
    private const HIGH_RISK_ACTIONS = [
        'user_deleted',
        'user_banned',
        'user_unbanned',
        'user_role_changed',
        'user_2fa_disabled',
        'demo_admin_assigned',
        'demo_admin_cleared',
        'subscription_created',
        'subscription_updated',
        'withdrawal_updated',
        'manual_credit',
        'config_updated',
        'package_updated',
        'package_created',
        'package_cloned',
        'package_deleted',
        'coupon_created',
        'coupon_updated',
        'save_bonus_offer',
        'delete_bonus_offer',
        'approve_bonus_award',
        'reject_bonus_award',
        'rewards_fraud_review',
        'rewards_fraud_trust_updated',
    ];
    private const RESTRICTED_ACTIONS = [
        'user_created',
        'user_updated',
        'user_deleted',
        'user_banned',
        'user_unbanned',
        'user_role_changed',
        'demo_admin_assigned',
        'demo_admin_cleared',
        'manual_credit',
        'subscription_created',
        'subscription_updated',
        'withdrawal_updated',
        'withdrawal_note_updated',
        'config_updated',
        'save_bonus_offer',
        'delete_bonus_offer',
        'approve_bonus_award',
        'reject_bonus_award',
        'load_example_ppd_tiers',
        'update_ppd_tiers',
        'package_created',
        'package_updated',
        'package_cloned',
        'package_deleted',
        'coupon_created',
        'coupon_updated',
        'user_2fa_disabled',
        'file_moderated_delete',
        'file_deleted',
        'report_reviewed',
        'rewards_fraud_review',
        'rewards_fraud_trust_updated',
    ];

    public static function ensureSchema(): void
    {
        if (self::$runtimeSchemaReady) {
            return;
        }

        SchemaService::ensureTables(['admin_activity_log'], false);

        self::$runtimeSchemaReady = true;
    }

    private static function decodeMetadataPayload(?string $rawMetadata): array
    {
        $rawMetadata = (string)$rawMetadata;
        if ($rawMetadata === '') {
            return [];
        }

        $decodedPayload = EncryptionService::decrypt($rawMetadata);
        $decoded = json_decode((string)$decodedPayload, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $legacyDecoded = json_decode($rawMetadata, true);
        return is_array($legacyDecoded) ? $legacyDecoded : [];
    }

    private static function auditIntegritySecret(): string
    {
        $secret = SecurityService::getSecureAppKey();
        if ($secret !== null && $secret !== '') {
            return $secret;
        }

        return trim((string)Config::get('security.encryption_key', ''));
    }

    private static function auditAnchorPath(): string
    {
        $configured = trim((string)Config::get('admin.audit_integrity_anchor_path', ''));
        if ($configured !== '') {
            return $configured;
        }

        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $root . '/storage/cache/admin_activity.anchor.json';
    }

    private static function writeAuditAnchor(?int $rowId, ?string $signature): void
    {
        if (($rowId ?? 0) <= 0 || trim((string)$signature) === '') {
            return;
        }

        $path = self::auditAnchorPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode([
            'row_id' => (int)$rowId,
            'signature' => (string)$signature,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tempPath, $payload, LOCK_EX) === false) {
            @unlink($tempPath);
            return;
        }

        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
        }
    }

    private static function readAuditAnchor(): ?array
    {
        $path = self::auditAnchorPath();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $rowId = (int)($decoded['row_id'] ?? 0);
        $signature = trim((string)($decoded['signature'] ?? ''));
        if ($rowId <= 0 || $signature === '') {
            return null;
        }

        return [
            'row_id' => $rowId,
            'signature' => $signature,
        ];
    }

    private static function normalizedMetadataForSignature(array $metadata): array
    {
        unset($metadata[self::INTEGRITY_METADATA_KEY]);
        ksort($metadata);
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = self::normalizedMetadataForSignature($value);
            }
        }

        return $metadata;
    }

    private static function rowSignaturePayload(
        string $action,
        ?string $itemType,
        ?int $itemId,
        ?string $details,
        array $metadata,
        ?int $targetUserId,
        int $actorUserId,
        ?string $actorRole,
        ?int $previousRowId = null,
        ?string $previousSignature = null
    ): string {
        $details = $details !== null && $details !== '' ? $details : null;

        return json_encode([
            'action' => $action,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'details' => $details,
            'metadata' => self::normalizedMetadataForSignature($metadata),
            'target_user_id' => $targetUserId,
            'actor_user_id' => $actorUserId,
            'actor_role' => $actorRole,
            'previous_row_id' => $previousRowId,
            'previous_signature' => $previousSignature,
        ], JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function signActivityRow(
        string $action,
        ?string $itemType,
        ?int $itemId,
        ?string $details,
        array $metadata,
        ?int $targetUserId,
        int $actorUserId,
        ?string $actorRole,
        ?int $previousRowId = null,
        ?string $previousSignature = null
    ): ?string {
        $secret = self::auditIntegritySecret();
        if ($secret === '') {
            return null;
        }

        return hash_hmac(
            'sha256',
            self::rowSignaturePayload($action, $itemType, $itemId, $details, $metadata, $targetUserId, $actorUserId, $actorRole, $previousRowId, $previousSignature),
            $secret
        );
    }

    private static function latestSignedRowDescriptor(PDO $db): ?array
    {
        $stmt = $db->query("
            SELECT id, action, item_type, item_id, target_user_id, admin_id, actor_role, details, metadata_json
            FROM admin_activity_log
            ORDER BY id DESC
            LIMIT 50
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $metadata = self::decodeMetadataPayload($row['metadata_json'] ?? null);
            $integrity = is_array($metadata[self::INTEGRITY_METADATA_KEY] ?? null) ? $metadata[self::INTEGRITY_METADATA_KEY] : null;
            if (!is_array($integrity)) {
                continue;
            }
            $signature = trim((string)($integrity['signature'] ?? ''));
            $version = (int)($integrity['version'] ?? 0);
            if ($version !== self::INTEGRITY_VERSION || $signature === '') {
                continue;
            }

            return [
                'row_id' => (int)($row['id'] ?? 0),
                'signature' => $signature,
            ];
        }

        return null;
    }

    private static function metadataWithIntegrity(
        PDO $db,
        string $action,
        ?string $itemType,
        ?int $itemId,
        ?string $details,
        array $metadata,
        ?int $targetUserId,
        int $actorUserId,
        ?string $actorRole
    ): array {
        $previous = self::latestSignedRowDescriptor($db);
        $previousRowId = (int)($previous['row_id'] ?? 0);
        $previousSignature = trim((string)($previous['signature'] ?? ''));
        $signature = self::signActivityRow(
            $action,
            $itemType,
            $itemId,
            $details,
            $metadata,
            $targetUserId,
            $actorUserId,
            $actorRole,
            $previousRowId > 0 ? $previousRowId : null,
            $previousSignature !== '' ? $previousSignature : null
        );
        if ($signature === null) {
            return $metadata;
        }

        $metadata[self::INTEGRITY_METADATA_KEY] = [
            'version' => self::INTEGRITY_VERSION,
            'signature' => $signature,
            'previous_row_id' => $previousRowId > 0 ? $previousRowId : null,
            'previous_signature' => $previousSignature !== '' ? $previousSignature : null,
        ];

        return $metadata;
    }

    private static function loadStoredRowById(PDO $db, int $rowId): ?array
    {
        if ($rowId <= 0) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT id, action, item_type, item_id, target_user_id, admin_id, actor_role, details, metadata_json
            FROM admin_activity_log
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function verifiedStoredRowSignature(PDO $db, array $row, array &$cache, ?array &$rowMetadata = null): ?string
    {
        $rowId = (int)($row['id'] ?? 0);
        if ($rowId > 0 && array_key_exists($rowId, $cache)) {
            $cached = $cache[$rowId];
            if ($cached === false) {
                return null;
            }
            return is_string($cached) ? $cached : null;
        }

        $metadata = self::decodeMetadataPayload($row['metadata_json'] ?? null);
        $rowMetadata = $metadata;
        $integrity = $metadata[self::INTEGRITY_METADATA_KEY] ?? null;
        unset($metadata[self::INTEGRITY_METADATA_KEY]);

        if (!is_array($integrity)) {
            if ($rowId > 0) {
                $cache[$rowId] = false;
            }
            return null;
        }

        $signature = trim((string)($integrity['signature'] ?? ''));
        $version = (int)($integrity['version'] ?? 0);
        $previousRowId = (int)($integrity['previous_row_id'] ?? 0);
        $previousSignature = trim((string)($integrity['previous_signature'] ?? ''));
        if ($version !== self::INTEGRITY_VERSION || $signature === '') {
            if ($rowId > 0) {
                $cache[$rowId] = false;
            }
            return null;
        }

        $expected = self::signActivityRow(
            (string)($row['action'] ?? ''),
            isset($row['item_type']) ? (string)$row['item_type'] : null,
            isset($row['item_id']) ? (int)$row['item_id'] : null,
            isset($row['details']) ? EncryptionService::decrypt((string)$row['details']) : null,
            $metadata,
            isset($row['target_user_id']) ? (int)$row['target_user_id'] : null,
            (int)($row['admin_id'] ?? 0),
            isset($row['actor_role']) ? (string)$row['actor_role'] : null,
            $previousRowId > 0 ? $previousRowId : null,
            $previousSignature !== '' ? $previousSignature : null
        );
        if ($expected === null || !hash_equals($expected, $signature)) {
            if ($rowId > 0) {
                $cache[$rowId] = false;
            }
            return null;
        }

        if ($previousRowId > 0) {
            $previousRow = self::loadStoredRowById($db, $previousRowId);
            if (!is_array($previousRow)) {
                if ($rowId > 0) {
                    $cache[$rowId] = false;
                }
                return null;
            }

            $previousMetadata = [];
            $verifiedPreviousSignature = self::verifiedStoredRowSignature($db, $previousRow, $cache, $previousMetadata);
            if ($verifiedPreviousSignature === null || !hash_equals($verifiedPreviousSignature, $previousSignature)) {
                if ($rowId > 0) {
                    $cache[$rowId] = false;
                }
                return null;
            }
        }

        if ($rowId > 0) {
            $cache[$rowId] = $signature;
        }

        return $signature;
    }

    private static function signedChainContainsAnchor(PDO $db, array $latestRow, array $anchor, array &$cache): bool
    {
        $anchorRowId = (int)($anchor['row_id'] ?? 0);
        $anchorSignature = trim((string)($anchor['signature'] ?? ''));
        if ($anchorRowId <= 0 || $anchorSignature === '') {
            return false;
        }

        if (!array_key_exists('id', $latestRow)) {
            $latestRowId = (int)($latestRow['row_id'] ?? 0);
            $latestRow = $latestRowId > 0 ? (self::loadStoredRowById($db, $latestRowId) ?? []) : [];
        }
        if ($latestRow === []) {
            return false;
        }

        $currentRow = $latestRow;
        while (is_array($currentRow)) {
            $currentMetadata = [];
            $verifiedSignature = self::verifiedStoredRowSignature($db, $currentRow, $cache, $currentMetadata);
            if ($verifiedSignature === null) {
                return false;
            }

            $currentRowId = (int)($currentRow['id'] ?? 0);
            if ($currentRowId === $anchorRowId) {
                return hash_equals($verifiedSignature, $anchorSignature);
            }

            $integrity = isset($currentMetadata[self::INTEGRITY_METADATA_KEY]) && is_array($currentMetadata[self::INTEGRITY_METADATA_KEY])
                ? $currentMetadata[self::INTEGRITY_METADATA_KEY]
                : [];
            $previousRowId = (int)($integrity['previous_row_id'] ?? 0);
            if ($previousRowId <= 0) {
                return false;
            }

            $currentRow = self::loadStoredRowById($db, $previousRowId);
        }

        return false;
    }

    private static function integrityStatusForRow(PDO $db, array $row, array &$metadata, array &$cache): string
    {
        $integrity = $metadata[self::INTEGRITY_METADATA_KEY] ?? null;
        unset($metadata[self::INTEGRITY_METADATA_KEY]);

        if (!is_array($integrity)) {
            return 'legacy';
        }

        $signature = trim((string)($integrity['signature'] ?? ''));
        $version = (int)($integrity['version'] ?? 0);
        if ($version === 1) {
            return 'legacy';
        }
        if ($version !== self::INTEGRITY_VERSION || $signature === '') {
            return 'tampered';
        }

        $rawRow = [
            'id' => $row['id'] ?? null,
            'action' => $row['action'] ?? null,
            'item_type' => $row['item_type'] ?? null,
            'item_id' => $row['item_id'] ?? null,
            'target_user_id' => $row['target_user_id'] ?? null,
            'admin_id' => $row['admin_id'] ?? null,
            'actor_role' => $row['actor_role'] ?? null,
            'details' => EncryptionService::encrypt((string)($row['details'] ?? '')),
            'metadata_json' => EncryptionService::encrypt(json_encode(array_merge($metadata, [
                self::INTEGRITY_METADATA_KEY => $integrity,
            ]), JSON_UNESCAPED_SLASHES)),
        ];

        $verifiedSignature = self::verifiedStoredRowSignature($db, $rawRow, $cache);
        return $verifiedSignature !== null && hash_equals($verifiedSignature, $signature) ? 'verified' : 'tampered';
    }

    private static function integrityWarningsForCurrentLogState(PDO $db): array
    {
        $warnings = [];
        $anchor = self::readAuditAnchor();
        $latestSigned = self::latestSignedRowDescriptor($db);
        $cache = [];

        if ($anchor !== null) {
            if ($latestSigned === null) {
                $warnings[] = 'The signed staff-activity history no longer matches its last trusted anchor. Review the audit log storage before trusting an empty or rewritten history.';
            } elseif (
                (int)($latestSigned['row_id'] ?? 0) !== (int)($anchor['row_id'] ?? 0)
                || !hash_equals((string)($latestSigned['signature'] ?? ''), (string)($anchor['signature'] ?? ''))
            ) {
                if (self::signedChainContainsAnchor($db, $latestSigned, $anchor, $cache)) {
                    self::writeAuditAnchor((int)($latestSigned['row_id'] ?? 0), (string)($latestSigned['signature'] ?? ''));
                } else {
                    $warnings[] = 'The signed staff-activity history no longer matches its last trusted anchor. One or more audit rows may have been removed or replaced outside the normal admin workflow.';
                }
            }
        } elseif ($latestSigned !== null) {
            self::writeAuditAnchor((int)$latestSigned['row_id'], (string)$latestSigned['signature']);
        }

        return $warnings;
    }

    private static function restrictedCapabilityForAction(string $action): ?string
    {
        return match ($action) {
            'user_created',
            'user_updated',
            'user_deleted',
            'user_banned',
            'user_unbanned' => 'users.manage',
            'user_role_changed',
            'demo_admin_assigned',
            'demo_admin_cleared' => 'staff.manage_permissions',
            'manual_credit' => 'earnings.credit_manual',
            'subscription_created',
            'subscription_updated' => 'subscriptions.manage',
            'withdrawal_updated',
            'withdrawal_note_updated' => 'withdrawals.manage',
            'config_updated' => 'configuration.manage',
            'save_bonus_offer',
            'delete_bonus_offer',
            'load_example_ppd_tiers',
            'update_ppd_tiers' => 'configuration.manage',
            'approve_bonus_award',
            'reject_bonus_award' => 'bonus_awards.review',
            'package_created',
            'package_updated',
            'package_cloned',
            'package_deleted' => 'packages.manage',
            'coupon_created',
            'coupon_updated' => 'coupons.manage',
            'user_2fa_disabled' => 'users.2fa_reset',
            'file_moderated_delete',
            'file_deleted',
            'report_reviewed' => 'files.moderate',
            'rewards_fraud_review',
            'rewards_fraud_trust_updated' => 'rewards_fraud.manage',
            default => null,
        };
    }

    private static function restrictedCapabilityForActivity(array $row): ?string
    {
        return self::restrictedCapabilityForAction((string)($row['action'] ?? ''));
    }

    private static function viewerCanSeeActivity(array $row): bool
    {
        $requiredCapability = self::restrictedCapabilityForActivity($row);
        return $requiredCapability === null || Auth::hasCapability($requiredCapability);
    }

    private static function isMeaningfulItemType(?string $itemType): bool
    {
        $itemType = trim((string)$itemType);
        if ($itemType === '') {
            return false;
        }

        if (ctype_digit($itemType)) {
            return false;
        }

        return true;
    }

    public static function log(
        string $action,
        ?string $itemType = null,
        ?int $itemId = null,
        ?string $details = null,
        array $metadata = [],
        ?int $targetUserId = null,
        ?int $actorUserId = null,
        ?string $actorRole = null
    ): void {
        self::ensureSchema();

        $actorUserId = $actorUserId ?? Auth::id();
        $actorRole = $actorRole ?? Auth::role();
        if ($actorUserId === null || $actorUserId <= 0) {
            return;
        }

        self::logWithConnection(
            Database::getInstance()->getConnection(),
            $action,
            $itemType,
            $itemId,
            $details,
            $metadata,
            $targetUserId,
            $actorUserId,
            $actorRole
        );
    }

    public static function logWithConnection(
        PDO $db,
        string $action,
        ?string $itemType = null,
        ?int $itemId = null,
        ?string $details = null,
        array $metadata = [],
        ?int $targetUserId = null,
        ?int $actorUserId = null,
        ?string $actorRole = null
    ): void {
        self::ensureSchema();

        $actorUserId = $actorUserId ?? Auth::id();
        $actorRole = $actorRole ?? Auth::role();
        if ($actorUserId === null || $actorUserId <= 0) {
            return;
        }

        $ip = SecurityService::getClientIp();
        $encDetails = $details !== null && $details !== '' ? EncryptionService::encrypt($details) : null;
        $encIp = $ip !== '' ? EncryptionService::encrypt($ip) : null;
        $metadata = self::metadataWithIntegrity(
            $db,
            $action,
            $itemType,
            $itemId,
            $details,
            $metadata,
            $targetUserId,
            (int)$actorUserId,
            $actorRole
        );

        $metadataJson = $metadata !== []
            ? EncryptionService::encrypt(json_encode($metadata, JSON_UNESCAPED_SLASHES))
            : null;

        $stmt = $db->prepare("
            INSERT INTO admin_activity_log
                (admin_id, actor_role, action, item_type, item_id, target_user_id, details, metadata_json, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $actorUserId,
            $actorRole,
            $action,
            $itemType,
            $itemId,
            $targetUserId,
            $encDetails,
            $metadataJson,
            $encIp,
        ]);

        $insertedRowId = (int)$db->lastInsertId();
        $integrity = is_array($metadata[self::INTEGRITY_METADATA_KEY] ?? null) ? $metadata[self::INTEGRITY_METADATA_KEY] : null;
        $signature = trim((string)($integrity['signature'] ?? ''));
        if ($insertedRowId > 0 && $signature !== '') {
            self::writeAuditAnchor($insertedRowId, $signature);
        }
    }

    public static function recent(int $limit = 200): array
    {
        return self::search([], $limit);
    }

    public static function searchPaginated(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(250, $perPage));
        $page = max(1, $page);
        $query = mb_strtolower(trim((string)($filters['query'] ?? '')));

        if ($query === '') {
            $total = self::countMatchingRows($filters);
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;
            $items = self::loadMatchingRowsPage($filters, $offset, $perPage);
        } else {
            [$items, $total] = self::loadMatchingRowsForQuery($filters, $query, $page, $perPage);
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'integrity_warnings' => self::integrityWarningsForCurrentLogState(Database::getInstance()->getConnection()),
        ];
    }

    public static function search(array $filters = [], int $limit = 200): array
    {
        return self::searchPaginated($filters, 1, $limit)['items'];
    }

    public static function activityForTargetUser(int $targetUserId, int $limit = 25): array
    {
        self::ensureSchema();
        if ($targetUserId <= 0) {
            return [];
        }
        if (!Auth::hasCapability('staff.activity.view')) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT
                l.*,
                u.username,
                tu.username AS target_username
            FROM admin_activity_log l
            LEFT JOIN users u ON u.id = l.admin_id
            LEFT JOIN users tu ON tu.id = l.target_user_id
            WHERE l.target_user_id = ? OR (l.item_type = 'user' AND l.item_id = ?)
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $targetUserId, PDO::PARAM_INT);
        $stmt->bindValue(2, $targetUserId, PDO::PARAM_INT);
        $stmt->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return self::decodeAndFilterRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public static function activityForItem(string $itemType, int $itemId, int $limit = 25): array
    {
        self::ensureSchema();
        $itemType = trim($itemType);
        if ($itemType === '' || $itemId <= 0) {
            return [];
        }
        if (!Auth::hasCapability('staff.activity.view')) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT
                l.*,
                u.username,
                tu.username AS target_username
            FROM admin_activity_log l
            LEFT JOIN users u ON u.id = l.admin_id
            LEFT JOIN users tu ON tu.id = l.target_user_id
            WHERE l.item_type = ? AND l.item_id = ?
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $itemType, PDO::PARAM_STR);
        $stmt->bindValue(2, $itemId, PDO::PARAM_INT);
        $stmt->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return self::decodeAndFilterRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function countMatchingRows(array $filters = []): int
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        [$where, $params] = self::buildSqlFilters($filters);
        $sql = "SELECT COUNT(*) FROM admin_activity_log l";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private static function loadMatchingRowsPage(array $filters = [], int $offset = 0, int $limit = 50): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        [$where, $params] = self::buildSqlFilters($filters);
        $sql = self::baseActivitySelectSql($where) . ' LIMIT ? OFFSET ?';
        $stmt = $db->prepare($sql);
        $index = 1;
        foreach ($params as $param) {
            $stmt->bindValue($index++, $param);
        }
        $stmt->bindValue($index++, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue($index, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return self::decodeAndFilterRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private static function loadMatchingRowsForQuery(array $filters, string $query, int $page, int $perPage): array
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();
        [$where, $params] = self::buildSqlFilters($filters);
        $sql = self::baseActivitySelectSql($where) . ' LIMIT ? OFFSET ?';
        $batchSize = max(100, min(500, $perPage * 4));
        $offset = 0;
        $matchOffset = max(0, ($page - 1) * $perPage);
        $items = [];
        $total = 0;

        do {
            $stmt = $db->prepare($sql);
            $index = 1;
            foreach ($params as $param) {
                $stmt->bindValue($index++, $param);
            }
            $stmt->bindValue($index++, $batchSize, PDO::PARAM_INT);
            $stmt->bindValue($index, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $decodedRows = self::decodeAndFilterRows($rows);
            foreach ($decodedRows as $row) {
                if (!self::rowMatchesSearchQuery($row, $query)) {
                    continue;
                }
                if ($total >= $matchOffset && count($items) < $perPage) {
                    $items[] = $row;
                }
                $total++;
            }
            $offset += count($rows);
        } while (count($rows) === $batchSize);

        return [$items, $total];
    }

    private static function buildSqlFilters(array $filters = []): array
    {
        self::ensureSchema();
        $where = [];
        $params = [];

        $actorId = (int)($filters['actor_id'] ?? 0);
        if ($actorId > 0) {
            $where[] = 'l.admin_id = ?';
            $params[] = $actorId;
        }

        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'l.action = ?';
            $params[] = $action;
        }

        $itemType = trim((string)($filters['item_type'] ?? ''));
        if ($itemType !== '') {
            $where[] = 'l.item_type = ?';
            $params[] = $itemType;
        }

        if (($filters['risk'] ?? '') === 'high') {
            $placeholders = implode(',', array_fill(0, count(self::HIGH_RISK_ACTIONS), '?'));
            $where[] = "l.action IN ($placeholders)";
            array_push($params, ...self::HIGH_RISK_ACTIONS);
        }

        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'l.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string)($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'l.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $blockedActions = self::viewerBlockedActions();
        if ($blockedActions !== []) {
            $placeholders = implode(',', array_fill(0, count($blockedActions), '?'));
            $where[] = "l.action NOT IN ($placeholders)";
            array_push($params, ...$blockedActions);
        }

        return [$where, $params];
    }

    private static function baseActivitySelectSql(array $where): string
    {
        $sql = "
            SELECT
                l.*,
                u.username,
                tu.username AS target_username
            FROM admin_activity_log l
            LEFT JOIN users u ON u.id = l.admin_id
            LEFT JOIN users tu ON tu.id = l.target_user_id
        ";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.created_at DESC, l.id DESC';
        return $sql;
    }

    private static function viewerBlockedActions(): array
    {
        $blocked = [];
        foreach (self::RESTRICTED_ACTIONS as $action) {
            $requiredCapability = self::restrictedCapabilityForAction($action);
            if ($requiredCapability !== null && !Auth::hasCapability($requiredCapability)) {
                $blocked[] = $action;
            }
        }

        return array_values(array_unique($blocked));
    }

    private static function rowMatchesSearchQuery(array $row, string $query): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string)($row['action'] ?? ''),
            (string)($row['item_type'] ?? ''),
            (string)($row['username'] ?? ''),
            (string)($row['target_username'] ?? ''),
            (string)($row['details'] ?? ''),
            (string)($row['actor_role'] ?? ''),
            (string)($row['item_id'] ?? ''),
            (string)($row['target_user_id'] ?? ''),
        ])));

        return str_contains($haystack, $query);
    }

    private static function decodeAndFilterRows(array $rows): array
    {
        $db = Database::getInstance()->getConnection();
        $integrityCache = [];
        $visibleRows = [];
        foreach ($rows as $row) {
            $row['username'] = EncryptionService::decrypt((string)($row['username'] ?? ''));
            $row['target_username'] = EncryptionService::decrypt((string)($row['target_username'] ?? ''));
            $row['details'] = EncryptionService::decrypt((string)($row['details'] ?? ''));
            $row['metadata'] = self::decodeMetadataPayload($row['metadata_json'] ?? null);
            $row['integrity_status'] = self::integrityStatusForRow($db, $row, $row['metadata'], $integrityCache);
            if (!self::viewerCanSeeActivity($row)) {
                continue;
            }
            $row = self::sanitizeVisibleRowForViewer($row);
            $visibleRows[] = $row;
        }

        return $visibleRows;
    }

    private static function sanitizeVisibleRowForViewer(array $row): array
    {
        if (Auth::isSuperAdmin()) {
            return $row;
        }

        $action = (string)($row['action'] ?? '');
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $sensitiveKeys = self::sensitiveMetadataKeysForAction($action);

        foreach ($sensitiveKeys as $key) {
            if (array_key_exists($key, $metadata)) {
                $metadata[$key] = '[redacted for delegated staff view]';
            }
        }

        $row['metadata'] = $metadata;
        return $row;
    }

    private static function sensitiveMetadataKeysForAction(string $action): array
    {
        return match ($action) {
            'manual_credit' => ['reason'],
            'withdrawal_updated', 'withdrawal_note_updated' => ['reason_note'],
            'subscription_created' => ['admin_note'],
            'subscription_updated' => ['admin_note'],
            default => [],
        };
    }

    public static function actorOptions(): array
    {
        self::ensureSchema();

        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT l.admin_id, l.action, u.username
            FROM admin_activity_log l
            LEFT JOIN users u ON u.id = l.admin_id
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT 1000
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $actors = [];
        foreach ($rows as $row) {
            if (!self::viewerCanSeeActivity($row)) {
                continue;
            }
            $adminId = (int)($row['admin_id'] ?? 0);
            if ($adminId <= 0 || isset($actors[$adminId])) {
                continue;
            }
            $row['username'] = EncryptionService::decrypt((string)($row['username'] ?? ''));
            $actors[$adminId] = [
                'admin_id' => $adminId,
                'username' => $row['username'],
            ];
        }

        return array_values($actors);
    }

    public static function actionOptions(): array
    {
        self::ensureSchema();

        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT DISTINCT action
            FROM admin_activity_log
            WHERE action IS NOT NULL AND action <> ''
            ORDER BY action ASC
            LIMIT 200
        ");
        $actions = $stmt ? array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []))) : [];
        return array_values(array_filter($actions, static function (string $action): bool {
            $requiredCapability = self::restrictedCapabilityForAction($action);
            return $requiredCapability === null || Auth::hasCapability($requiredCapability);
        }));
    }

    public static function itemTypeOptions(): array
    {
        self::ensureSchema();

        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("
            SELECT DISTINCT item_type, action
            FROM admin_activity_log
            WHERE item_type IS NOT NULL AND item_type <> ''
            ORDER BY item_type ASC, action ASC
            LIMIT 100
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $itemTypes = [];
        foreach ($rows as $row) {
            if (!self::viewerCanSeeActivity($row)) {
                continue;
            }
            $itemType = trim((string)($row['item_type'] ?? ''));
            if (self::isMeaningfulItemType($itemType)) {
                $itemTypes[$itemType] = $itemType;
            }
        }
        return array_values($itemTypes);
    }
}
