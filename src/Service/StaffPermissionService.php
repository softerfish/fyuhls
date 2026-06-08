<?php

namespace App\Service;

use App\Core\Auth;
use App\Core\Database;
use App\Service\Database\SchemaService;
use PDO;

class StaffPermissionService
{
    private static bool $runtimeSchemaReady = false;

    private const SUPER_ADMIN_ONLY_CAPABILITIES = [
        'plugins.manage',
    ];

    public static function definitions(): array
    {
        return [
            'dashboard.view' => ['label' => 'Admin dashboard', 'group' => 'General'],
            'docs.view' => ['label' => 'In-app docs', 'group' => 'General'],
            'resources.view' => ['label' => 'Resources', 'group' => 'General'],
            'staff.activity.view' => ['label' => 'Staff activity tracker', 'group' => 'General'],

            'users.manage' => ['label' => 'Manage users', 'group' => 'Staff & Accounts'],
            'users.2fa_reset' => ['label' => 'Disable user two-factor authentication', 'group' => 'Staff & Accounts'],
            'staff.manage_permissions' => ['label' => 'Create/edit admins and moderators', 'group' => 'Staff & Accounts'],
            'staff.edit_super_admin' => ['label' => 'Edit protected super admin account', 'group' => 'Staff & Accounts'],
            'packages.manage' => ['label' => 'Manage packages', 'group' => 'Staff & Accounts'],
            'subscriptions.manage' => ['label' => 'Manage subscriptions', 'group' => 'Staff & Accounts'],
            'coupons.manage' => ['label' => 'Manage coupons', 'group' => 'Staff & Accounts'],
            'withdrawals.manage' => ['label' => 'Manage withdrawals', 'group' => 'Staff & Accounts'],
            'earnings.credit_manual' => ['label' => 'Issue manual account credit', 'group' => 'Staff & Accounts'],
            'bonus_awards.review' => ['label' => 'Review bonus awards', 'group' => 'Staff & Accounts'],
            'rewards_fraud.manage' => ['label' => 'Review rewards fraud', 'group' => 'Staff & Accounts'],

            'files.moderate' => ['label' => 'Moderate files', 'group' => 'Moderation'],
            'abuse.manage' => ['label' => 'Manage abuse reports', 'group' => 'Moderation'],
            'dmca.manage' => ['label' => 'Manage DMCA reports', 'group' => 'Moderation'],
            'requests.manage' => ['label' => 'Manage shared request inbox', 'group' => 'Moderation'],
            'downloads.live' => ['label' => 'View live downloads', 'group' => 'Moderation'],
            'investigations.view' => ['label' => 'View uploader and file investigations', 'group' => 'Moderation'],

            'configuration.manage' => ['label' => 'Manage Config Hub', 'group' => 'Infrastructure'],
            'site_content.manage' => ['label' => 'Manage Site Content', 'group' => 'Infrastructure'],
            'plugins.manage' => ['label' => 'Manage plugins', 'group' => 'Infrastructure'],
            'status.view' => ['label' => 'View system status', 'group' => 'Infrastructure'],
            'file_servers.manage' => ['label' => 'Manage file servers', 'group' => 'Infrastructure'],
            'support.manage' => ['label' => 'Use support center tools', 'group' => 'Infrastructure'],
        ];
    }

    public static function groupedDefinitions(): array
    {
        $grouped = [];
        foreach (self::definitions() as $capability => $definition) {
            $group = (string)($definition['group'] ?? 'Other');
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][$capability] = $definition;
        }

        return $grouped;
    }

    public static function defaultCapabilitiesForRole(string $role): array
    {
        $role = strtolower(trim($role));
        $all = array_keys(self::definitions());
        $adminDefaults = array_fill_keys($all, true);
        $adminDefaults['staff.edit_super_admin'] = false;
        foreach (self::SUPER_ADMIN_ONLY_CAPABILITIES as $capability) {
            $adminDefaults[$capability] = false;
        }

        return match ($role) {
            'admin' => $adminDefaults,
            'moderator' => [
                'dashboard.view' => true,
                'staff.activity.view' => true,
                'files.moderate' => true,
                'abuse.manage' => true,
                'dmca.manage' => true,
            ],
            default => [],
        };
    }

    public static function roleSupportsCapabilities(string $role): bool
    {
        return in_array(strtolower(trim($role)), ['admin', 'moderator'], true);
    }

    public static function roleOptions(): array
    {
        return [
            'user' => 'User',
            'moderator' => 'Moderator',
            'admin' => 'Admin',
        ];
    }

    public static function ensureSchema(): void
    {
        if (self::$runtimeSchemaReady) {
            return;
        }

        SchemaService::ensureTables(['staff_permissions', 'users'], false);
        self::$runtimeSchemaReady = true;
    }

    public static function getCapabilityMapForUser(int $userId, ?string $role = null): array
    {
        self::ensureSchema();

        if ($userId <= 0) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        if ($role === null || $role === '') {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $role = (string)$stmt->fetchColumn();
        }

        $capabilities = self::defaultCapabilitiesForRole((string)$role);
        if ($capabilities === []) {
            return [];
        }

        $stmt = $db->prepare("SELECT capability, is_allowed FROM staff_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $capability = (string)($row['capability'] ?? '');
            if ($capability === '' || !array_key_exists($capability, self::definitions())) {
                continue;
            }
            $capabilities[$capability] = (int)($row['is_allowed'] ?? 0) === 1;
        }

        return $capabilities;
    }

    public static function getOverridesForUser(int $userId): array
    {
        self::ensureSchema();
        if ($userId <= 0) {
            return [];
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT capability, is_allowed FROM staff_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);

        $overrides = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $capability = (string)($row['capability'] ?? '');
            if ($capability === '') {
                continue;
            }
            $overrides[$capability] = (int)($row['is_allowed'] ?? 0) === 1;
        }

        return $overrides;
    }

    public static function userHasCapability(int $userId, string $role, string $capability): bool
    {
        $capability = trim($capability);
        if ($capability === '' || !array_key_exists($capability, self::definitions())) {
            return false;
        }

        if (self::isSuperAdminOnlyCapability($capability)) {
            return false;
        }

        $capabilities = self::getCapabilityMapForUser($userId, $role);
        return !empty($capabilities[$capability]);
    }

    public static function currentUserHasCapability(string $capability): bool
    {
        $userId = Auth::id();
        $role = Auth::role();
        if ($userId === null || $role === null) {
            return false;
        }

        return self::userHasCapability($userId, $role, $capability);
    }

    public static function syncOverridesForUser(int $userId, string $role, array $submittedCapabilities, int $updatedBy): void
    {
        self::ensureSchema();
        $db = Database::getInstance()->getConnection();

        if (!self::roleSupportsCapabilities($role)) {
            $stmt = $db->prepare("DELETE FROM staff_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            return;
        }

        $defaults = self::defaultCapabilitiesForRole($role);
        $submitted = array_fill_keys(array_keys(self::definitions()), false);
        foreach ($submittedCapabilities as $capability => $isAllowed) {
            if (!array_key_exists($capability, self::definitions())) {
                continue;
            }
            $submitted[$capability] = (bool)$isAllowed;
        }

        $db->prepare("DELETE FROM staff_permissions WHERE user_id = ?")->execute([$userId]);

        $insert = $db->prepare("
            INSERT INTO staff_permissions (user_id, capability, is_allowed, updated_by)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($submitted as $capability => $isAllowed) {
            $defaultAllowed = !empty($defaults[$capability]);
            if ($isAllowed === $defaultAllowed) {
                continue;
            }
            $insert->execute([$userId, $capability, $isAllowed ? 1 : 0, $updatedBy > 0 ? $updatedBy : null]);
        }
    }

    public static function normalizeSubmittedCapabilities(array $raw): array
    {
        $normalized = [];
        foreach (array_keys(self::definitions()) as $capability) {
            $normalized[$capability] = !empty($raw[$capability]);
        }
        return $normalized;
    }

    public static function constrainGrantedCapabilitiesForActor(
        string $targetRole,
        array $submittedCapabilities,
        ?int $actorUserId,
        ?string $actorRole,
        bool $actorIsSuperAdmin,
        bool $useRoleDefaultsWhenEmpty = false
    ): array {
        if (!self::roleSupportsCapabilities($targetRole)) {
            return [];
        }

        $submittedMap = self::normalizeSubmittedCapabilities($submittedCapabilities);
        $hasExplicitSubmission = self::hasAnyExplicitCapabilitySelection($submittedCapabilities);
        $desired = ($useRoleDefaultsWhenEmpty && !$hasExplicitSubmission)
            ? self::defaultCapabilitiesForRole($targetRole)
            : $submittedMap;

        if ($actorIsSuperAdmin) {
            return $desired;
        }

        $grantable = [];
        if ($actorUserId !== null && $actorUserId > 0 && $actorRole !== null && $actorRole !== '') {
            $grantable = self::getCapabilityMapForUser($actorUserId, $actorRole);
        }

        $constrained = [];
        foreach (array_keys(self::definitions()) as $capability) {
            $constrained[$capability] = !self::isSuperAdminOnlyCapability($capability)
                && !empty($desired[$capability])
                && !empty($grantable[$capability]);
        }

        return $constrained;
    }

    public static function isSuperAdminOnlyCapability(string $capability): bool
    {
        return in_array(trim($capability), self::SUPER_ADMIN_ONLY_CAPABILITIES, true);
    }

    private static function hasAnyExplicitCapabilitySelection(array $submittedCapabilities): bool
    {
        foreach ($submittedCapabilities as $value) {
            if (!empty($value)) {
                return true;
            }
        }

        return false;
    }
}
