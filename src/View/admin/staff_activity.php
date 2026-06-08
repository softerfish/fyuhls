<?php
include __DIR__ . '/header.php';
include __DIR__ . '/partials/shell_helpers.php';

$filters = is_array($activityFilters ?? null) ? $activityFilters : [];
$actors = is_array($activityActors ?? null) ? $activityActors : [];
$actions = is_array($activityActions ?? null) ? $activityActions : [];
$itemTypes = is_array($activityItemTypes ?? null) ? $activityItemTypes : [];
$activities = is_array($activities ?? null) ? $activities : [];
$pagination = is_array($activityPagination ?? null) ? $activityPagination : [];
$integrityWarnings = is_array($pagination['integrity_warnings'] ?? null) ? $pagination['integrity_warnings'] : [];
$currentPage = max(1, (int)($pagination['page'] ?? 1));
$perPage = max(1, (int)($pagination['per_page'] ?? max(1, count($activities))));
$totalItems = max(0, (int)($pagination['total'] ?? count($activities)));
$totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
$pageStart = $totalItems > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
$pageEnd = $totalItems > 0 ? min($totalItems, $pageStart + count($activities) - 1) : 0;

$riskActions = [
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
    'save_bonus_offer',
    'delete_bonus_offer',
    'approve_bonus_award',
    'reject_bonus_award',
    'rewards_fraud_review',
    'rewards_fraud_trust_updated',
];

$actionCategories = [
    'user_created' => 'Accounts',
    'user_updated' => 'Accounts',
    'user_deleted' => 'Accounts',
    'user_banned' => 'Accounts',
    'user_unbanned' => 'Accounts',
    'user_role_changed' => 'Accounts',
    'user_2fa_disabled' => 'Security',
    'demo_admin_assigned' => 'Security',
    'demo_admin_cleared' => 'Security',
    'withdrawal_updated' => 'Rewards & Withdrawals',
    'manual_credit' => 'Rewards & Withdrawals',
    'subscription_created' => 'Rewards & Withdrawals',
    'subscription_updated' => 'Rewards & Withdrawals',
    'approve_bonus_award' => 'Rewards & Withdrawals',
    'reject_bonus_award' => 'Rewards & Withdrawals',
    'rewards_fraud_review' => 'Rewards & Withdrawals',
    'rewards_fraud_trust_updated' => 'Rewards & Withdrawals',
    'package_created' => 'Packages & Pricing',
    'package_updated' => 'Packages & Pricing',
    'package_cloned' => 'Packages & Pricing',
    'package_deleted' => 'Packages & Pricing',
    'save_bonus_offer' => 'Config & Security',
    'delete_bonus_offer' => 'Config & Security',
    'load_example_ppd_tiers' => 'Config & Security',
    'update_ppd_tiers' => 'Config & Security',
    'file_moderated_delete' => 'Files & Moderation',
    'file_deleted' => 'Files & Moderation',
    'report_reviewed' => 'Files & Moderation',
    'config_updated' => 'Config & Security',
];

$formatAction = static function (?string $action): string {
    $action = trim((string)$action);
    return $action === '' ? 'Activity' : ucwords(str_replace('_', ' ', $action));
};

$activityCategory = static function (array $activity) use ($actionCategories): string {
    $action = (string)($activity['action'] ?? '');
    if (isset($actionCategories[$action])) {
        return $actionCategories[$action];
    }

    $itemType = strtolower((string)($activity['item_type'] ?? ''));
    return match ($itemType) {
        'user' => 'Accounts',
        'package' => 'Packages & Pricing',
        'withdrawal', 'earning', 'bonus_offer', 'bonus_award' => 'Rewards & Withdrawals',
        'setting', 'config' => 'Config & Security',
        'file', 'report', 'ticket' => 'Files & Moderation',
        default => 'General',
    };
};

$isHighRisk = static function (array $activity) use ($riskActions): bool {
    return in_array((string)($activity['action'] ?? ''), $riskActions, true);
};

$integrityLabel = static function (array $activity): ?string {
    return match ((string)($activity['integrity_status'] ?? '')) {
        'tampered' => 'Tamper warning',
        'legacy' => 'Legacy entry',
        default => null,
    };
};

$integritySummary = static function (array $activity): ?string {
    return match ((string)($activity['integrity_status'] ?? '')) {
        'tampered' => 'This audit row no longer matches its signed contents. Review the database history before trusting this event.',
        'legacy' => 'This older audit row predates signed integrity protection and cannot be verified automatically.',
        default => null,
    };
};

$relativeTime = static function (string $timestamp): string {
    $time = strtotime($timestamp);
    if (!$time) {
        return 'Unknown time';
    }
    $delta = time() - $time;
    if ($delta < 60) {
        return 'Just now';
    }
    if ($delta < 3600) {
        return floor($delta / 60) . 'm ago';
    }
    if ($delta < 86400) {
        return floor($delta / 3600) . 'h ago';
    }
    if ($delta < 604800) {
        return floor($delta / 86400) . 'd ago';
    }
    return date('M d', $time);
};

$humanizeSettingKey = static function (?string $key): string {
    $key = trim((string)$key);
    if ($key === '') {
        return 'Setting';
    }

    $label = str_replace('_', ' ', $key);
    $label = preg_replace('/\s+/', ' ', $label ?? '') ?: $key;
    $label = ucwords($label);

    $replacements = [
        'Api' => 'API',
        'Url' => 'URL',
        'Urls' => 'URLs',
        'Id' => 'ID',
        'Ids' => 'IDs',
        'Ip' => 'IP',
        'Ppd' => 'PPD',
        'Pps' => 'PPS',
        'Smtp' => 'SMTP',
        'Seo' => 'SEO',
        'Cdn' => 'CDN',
        'Dmca' => 'DMCA',
        'Asn' => 'ASN',
        'Vpn' => 'VPN',
        '2Fa' => '2FA',
    ];

    return strtr($label, $replacements);
};

$presentAuditValue = static function ($value): string {
    if (is_bool($value)) {
        return $value ? 'Enabled' : 'Disabled';
    }

    if ($value === null) {
        return 'Not set';
    }

    $value = trim((string)$value);
    if ($value === '') {
        return 'Blank';
    }

    return match (strtolower($value)) {
        '1', '[enabled]', 'enabled', 'true', 'yes', 'on' => 'Enabled',
        '0', '[disabled]', 'disabled', 'false', 'no', 'off' => 'Disabled',
        '[configured secret]', '********' => 'Updated secret value',
        '[not configured]' => 'Not configured',
        default => $value,
    };
};

$buildConfigChangeRows = static function (array $metadata) use ($humanizeSettingKey, $presentAuditValue): array {
    $rows = [];
    $before = is_array($metadata['before'] ?? null) ? $metadata['before'] : [];
    $after = is_array($metadata['after'] ?? null) ? $metadata['after'] : [];
    $changedKeys = is_array($metadata['changed_keys'] ?? null) ? $metadata['changed_keys'] : array_keys($after);

    foreach ($changedKeys as $key) {
        $key = (string)$key;
        $rows[] = [
            'label' => $humanizeSettingKey($key),
            'value' => $presentAuditValue($before[$key] ?? null) . ' -> ' . $presentAuditValue($after[$key] ?? null),
        ];
    }

    return $rows;
};

$targetLabel = static function (array $activity) use ($humanizeSettingKey): string {
    $action = (string)($activity['action'] ?? '');
    if ($action === 'update_setting') {
        return $humanizeSettingKey((string)($activity['item_type'] ?? ''));
    }

    $itemType = trim((string)($activity['item_type'] ?? ''));
    $itemId = (int)($activity['item_id'] ?? 0);
    $targetUserId = (int)($activity['target_user_id'] ?? 0);
    $targetUsername = trim((string)($activity['target_username'] ?? ''));

    $parts = [];
    if ($itemType !== '') {
        $parts[] = ucwords(str_replace('_', ' ', $itemType));
    }
    if ($itemId > 0) {
        $parts[] = '#' . $itemId;
    }
    if ($targetUserId > 0) {
        $parts[] = $targetUsername !== '' ? $targetUsername . ' (user #' . $targetUserId . ')' : 'user #' . $targetUserId;
    }

    return $parts !== [] ? implode(' ', $parts) : 'General admin action';
};

$targetLink = static function (array $activity): ?string {
    return \App\Service\AdminActivityNavigationService::destinationForTarget($activity);
};

$summarizeActivity = static function (array $activity) use ($formatAction, $targetLabel, $presentAuditValue, $humanizeSettingKey): string {
    $action = (string)($activity['action'] ?? '');
    $actor = trim((string)($activity['username'] ?? ''));
    $actor = $actor !== '' ? $actor : 'Staff user #' . (int)($activity['admin_id'] ?? 0);
    $target = $targetLabel($activity);
    $details = trim((string)($activity['details'] ?? ''));
    $metadata = is_array($activity['metadata'] ?? null) ? $activity['metadata'] : [];

    return match ($action) {
        'update_setting' => $actor . ' updated ' . $target
            . ($details !== '' ? ' to ' . $presentAuditValue($details) . '.' : '.'),
        'config_updated' => $actor . ' updated '
            . (!empty($metadata['section']) ? $humanizeSettingKey((string)$metadata['section']) : $target)
            . ' settings'
            . (!empty($metadata['changed_keys']) ? ' (' . count((array)$metadata['changed_keys']) . ' change' . (count((array)$metadata['changed_keys']) === 1 ? '' : 's') . ').' : '.'),
        'user_updated' => $actor . ' updated ' . $target . '.',
        'user_created' => $actor . ' created ' . $target . '.',
        'user_deleted' => $actor . ' deleted ' . $target . '.',
        'user_banned' => $actor . ' banned ' . $target . '.',
        'user_unbanned' => $actor . ' restored ' . $target . '.',
        'user_role_changed' => $actor . ' changed role for ' . $target . (isset($metadata['role']) ? ' to ' . $metadata['role'] . '.' : '.'),
        'user_2fa_disabled' => $actor . ' disabled 2FA for ' . $target . '.',
        'withdrawal_updated' => $actor . ' updated ' . $target
            . (isset($metadata['after']['status']) ? ' to ' . $metadata['after']['status'] . '.' : '.'),
        'manual_credit' => $actor . ' manually credited ' . $target
            . (isset($metadata['amount']) ? ' for $' . $metadata['amount'] . '.' : '.'),
        'package_created' => $actor . ' created ' . $target . '.',
        'package_updated' => $actor . ' updated ' . $target . '.',
        'package_cloned' => $actor . ' cloned ' . $target . '.',
        'rewards_fraud_review' => $actor . ' reviewed ' . $target . '.',
        default => $details !== '' ? $details : ($actor . ' performed ' . strtolower($formatAction($action)) . '.'),
    };
};

$detailRows = static function (array $activity) use ($buildConfigChangeRows, $humanizeSettingKey, $presentAuditValue): array {
    $rows = [];
    $action = (string)($activity['action'] ?? '');
    $metadata = is_array($activity['metadata'] ?? null) ? $activity['metadata'] : [];

    if ($action === 'update_setting') {
        $rows[] = [
            'label' => 'Setting',
            'value' => $humanizeSettingKey((string)($activity['item_type'] ?? '')),
        ];
        if (!empty($activity['details'])) {
            $rows[] = [
                'label' => 'New value',
                'value' => $presentAuditValue((string)$activity['details']),
            ];
        }
    } elseif ($action === 'config_updated') {
        if (!empty($metadata['section'])) {
            $rows[] = [
                'label' => 'Section',
                'value' => $humanizeSettingKey((string)$metadata['section']),
            ];
        }
        $rows = array_merge($rows, $buildConfigChangeRows($metadata));
    }

    foreach ($metadata as $key => $value) {
        if ($action === 'config_updated' && in_array($key, ['section', 'changed_keys', 'before', 'after'], true)) {
            continue;
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        } else {
            $value = (string)$value;
        }
        $rows[] = [
            'label' => ucwords(str_replace('_', ' ', (string)$key)),
            'value' => $presentAuditValue($value),
        ];
    }

    if (!empty($activity['details']) && $action !== 'update_setting') {
        array_unshift($rows, [
            'label' => 'Details',
            'value' => $presentAuditValue((string)$activity['details']),
        ]);
    }

    if (!empty($activity['target_user_id']) && !empty($activity['target_username'])) {
        $rows[] = [
            'label' => 'Target user',
            'value' => (string)$activity['target_username'] . ' (#' . (int)$activity['target_user_id'] . ')',
        ];
    }

    return $rows;
};

$summaryCards = [
    'total' => $totalItems,
    'high_risk' => count(array_filter($activities, $isHighRisk)),
    'accounts' => count(array_filter($activities, static fn(array $activity): bool => $activityCategory($activity) === 'Accounts')),
    'financial' => count(array_filter($activities, static fn(array $activity): bool => $activityCategory($activity) === 'Rewards & Withdrawals')),
];

$pageUrl = static function (int $page) use ($filters): string {
    $params = [];
    foreach ($filters as $key => $value) {
        if ($value === '' || $value === 0 || $value === '0' || $value === null) {
            continue;
        }
        $params[$key === 'query' ? 'q' : $key] = $value;
    }
    $params['page'] = max(1, $page);

    return '/admin/staff-activity?' . http_build_query($params);
};
?>

<?php renderAdminPageHeader('Staff Activity'); ?>

<?php renderAdminCardStart('Activity Filters'); ?>
    <p class="text-muted mb-4">Use filters to answer the practical questions fast: who changed something, what kind of action it was, whether it was high-risk, and which record it touched.</p>

    <form method="GET" action="/admin/staff-activity" class="staff-activity-filters">
        <div class="staff-activity-filter-grid">
            <div>
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= htmlspecialchars((string)($filters['query'] ?? '')) ?>" placeholder="Actor, action, target, details, or ID">
            </div>
            <div>
                <label class="form-label">Actor</label>
                <select name="actor_id" class="form-select">
                    <option value="">All staff</option>
                    <?php foreach ($actors as $actor): ?>
                        <?php $actorId = (int)($actor['admin_id'] ?? 0); ?>
                        <option value="<?= $actorId ?>" <?= $actorId === (int)($filters['actor_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)($actor['username'] ?? ('User #' . $actorId))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Action</label>
                <select name="action" class="form-select">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?= htmlspecialchars($action) ?>" <?= (string)($filters['action'] ?? '') === $action ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $action))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Target type</label>
                <select name="item_type" class="form-select">
                    <option value="">All targets</option>
                    <?php foreach ($itemTypes as $itemType): ?>
                        <option value="<?= htmlspecialchars($itemType) ?>" <?= (string)($filters['item_type'] ?? '') === $itemType ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $itemType))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Risk</label>
                <select name="risk" class="form-select">
                    <option value="">Everything</option>
                    <option value="high" <?= (string)($filters['risk'] ?? '') === 'high' ? 'selected' : '' ?>>High-risk only</option>
                </select>
            </div>
            <div>
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars((string)($filters['date_from'] ?? '')) ?>">
            </div>
            <div>
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars((string)($filters['date_to'] ?? '')) ?>">
            </div>
        </div>
        <div class="staff-activity-filter-actions">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="/admin/staff-activity" class="btn btn-outline-secondary">Clear</a>
        </div>
    </form>

    <div class="staff-activity-summary-grid">
        <div class="staff-activity-summary-card">
            <div class="staff-activity-summary-label">Visible events</div>
            <div class="staff-activity-summary-value"><?= number_format($summaryCards['total']) ?></div>
        </div>
        <div class="staff-activity-summary-card staff-activity-summary-card--risk">
            <div class="staff-activity-summary-label">High-risk actions</div>
            <div class="staff-activity-summary-value"><?= number_format($summaryCards['high_risk']) ?></div>
        </div>
        <div class="staff-activity-summary-card">
            <div class="staff-activity-summary-label">Account actions</div>
            <div class="staff-activity-summary-value"><?= number_format($summaryCards['accounts']) ?></div>
        </div>
        <div class="staff-activity-summary-card">
            <div class="staff-activity-summary-label">Rewards & payouts</div>
            <div class="staff-activity-summary-value"><?= number_format($summaryCards['financial']) ?></div>
        </div>
    </div>

    <?php if ($integrityWarnings !== []): ?>
        <?php foreach ($integrityWarnings as $warning): ?>
            <div class="staff-activity-integrity-note staff-activity-integrity-note--tampered mt-3">
                <?= htmlspecialchars((string)$warning) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Recent Staff Actions'); ?>
    <p class="text-muted mb-4">This page is for investigation and accountability. Use it to trace who changed something, when it happened, and whether the action touched money, security, packages, or user access.</p>

    <?php if (empty($activities)): ?>
        <p class="text-muted mb-0">No staff activity matched the current filters.</p>
    <?php else: ?>
        <style>
            .staff-activity-filters { display: grid; gap: 1rem; }
            .staff-activity-filter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }
            .staff-activity-filter-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
            .staff-activity-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-top: 1rem; }
            .staff-activity-summary-card { border: 1px solid #e2e8f0; background: #fff; border-radius: 14px; padding: 1rem; }
            .staff-activity-summary-card--risk { background: #fff7ed; border-color: #fdba74; }
            .staff-activity-summary-label { font-size: 0.78rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; }
            .staff-activity-summary-value { font-size: 1.5rem; font-weight: 800; color: #0f172a; }
            .staff-activity-pagination { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
            .staff-activity-pagination-copy { color: #64748b; font-size: 0.92rem; }
            .staff-activity-pagination-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; }
            .staff-activity-pagination-actions .btn[aria-disabled="true"] { pointer-events: none; opacity: 0.55; }
            .staff-activity-list { display: grid; gap: 1rem; }
            .staff-activity-row { border: 1px solid #e2e8f0; background: #fff; border-radius: 16px; padding: 1rem 1.1rem; }
            .staff-activity-row--risk { border-color: #fdba74; background: #fffaf5; }
            .staff-activity-row-top { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(220px, 0.8fr); gap: 1rem; align-items: start; }
            .staff-activity-meta { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
            .staff-activity-pill { display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 999px; padding: 0.28rem 0.7rem; font-size: 0.78rem; font-weight: 700; background: #f1f5f9; color: #334155; }
            .staff-activity-pill--risk { background: #ffedd5; color: #9a3412; }
            .staff-activity-pill--role { background: #ede9fe; color: #5b21b6; }
            .staff-activity-summary-text { margin: 0; font-size: 1rem; color: #0f172a; line-height: 1.6; }
            .staff-activity-subtext { margin-top: 0.45rem; color: #64748b; font-size: 0.88rem; line-height: 1.55; }
            .staff-activity-side { display: grid; gap: 0.65rem; }
            .staff-activity-side-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.8rem 0.9rem; background: #f8fafc; }
            .staff-activity-side-label { color: #64748b; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; }
            .staff-activity-side-value { color: #0f172a; font-weight: 700; word-break: break-word; }
            .staff-activity-side-value a { color: inherit; text-decoration: none; }
            .staff-activity-side-value a:hover { text-decoration: underline; }
            .staff-activity-details { margin-top: 0.95rem; }
            .staff-activity-details summary { cursor: pointer; color: #1d4ed8; font-weight: 700; }
            .staff-activity-details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; margin-top: 0.85rem; }
            .staff-activity-detail-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.75rem 0.85rem; background: #fff; }
            .staff-activity-detail-label { color: #64748b; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; }
            .staff-activity-detail-value { color: #0f172a; white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.86rem; }
            .staff-activity-integrity-note { margin-top: 0.75rem; padding: 0.85rem 1rem; border-radius: 12px; font-size: 0.9rem; line-height: 1.45; }
            .staff-activity-integrity-note--tampered { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }
            .staff-activity-integrity-note--legacy { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
            .staff-activity-pill--warning { background: #fff1f2; color: #9f1239; border-color: #fecdd3; }
            .staff-activity-pill--legacy { background: #fffbeb; color: #92400e; border-color: #fde68a; }
            @media (max-width: 1200px) {
                .staff-activity-filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
                .staff-activity-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 900px) {
                .staff-activity-filter-grid,
                .staff-activity-summary-grid,
                .staff-activity-row-top,
                .staff-activity-details-grid { grid-template-columns: 1fr; }
            }
        </style>

        <div class="staff-activity-pagination">
            <div class="staff-activity-pagination-copy">
                Showing <?= number_format($pageStart) ?> to <?= number_format($pageEnd) ?> of <?= number_format($totalItems) ?> visible events.
                Page <?= number_format($currentPage) ?> of <?= number_format($totalPages) ?>.
            </div>
            <div class="staff-activity-pagination-actions">
                <?php $canGoBack = $currentPage > 1; ?>
                <?php $canGoForward = $currentPage < $totalPages; ?>
                <a class="btn btn-outline-secondary<?= $canGoBack ? '' : ' disabled' ?>" href="<?= $canGoBack ? htmlspecialchars($pageUrl(1)) : '#' ?>" aria-disabled="<?= $canGoBack ? 'false' : 'true' ?>">First</a>
                <a class="btn btn-outline-secondary<?= $canGoBack ? '' : ' disabled' ?>" href="<?= $canGoBack ? htmlspecialchars($pageUrl($currentPage - 1)) : '#' ?>" aria-disabled="<?= $canGoBack ? 'false' : 'true' ?>">Previous</a>
                <a class="btn btn-outline-secondary<?= $canGoForward ? '' : ' disabled' ?>" href="<?= $canGoForward ? htmlspecialchars($pageUrl($currentPage + 1)) : '#' ?>" aria-disabled="<?= $canGoForward ? 'false' : 'true' ?>">Next</a>
                <a class="btn btn-outline-secondary<?= $canGoForward ? '' : ' disabled' ?>" href="<?= $canGoForward ? htmlspecialchars($pageUrl($totalPages)) : '#' ?>" aria-disabled="<?= $canGoForward ? 'false' : 'true' ?>">Last</a>
            </div>
        </div>

        <div class="staff-activity-list">
            <?php foreach ($activities as $activity): ?>
                <?php
                $category = $activityCategory($activity);
                $highRisk = $isHighRisk($activity);
                $summary = $summarizeActivity($activity);
                $target = $targetLabel($activity);
                $targetHref = $targetLink($activity);
                $details = $detailRows($activity);
                $actorName = trim((string)($activity['username'] ?? ''));
                $actorDisplay = $actorName !== '' ? $actorName : 'User #' . (int)($activity['admin_id'] ?? 0);
                $timestamp = (string)($activity['created_at'] ?? '');
                $integrityStatus = (string)($activity['integrity_status'] ?? '');
                $integrityTag = $integrityLabel($activity);
                $integrityNote = $integritySummary($activity);
                ?>
                <div class="staff-activity-row<?= $highRisk ? ' staff-activity-row--risk' : '' ?>">
                    <div class="staff-activity-row-top">
                        <div>
                            <div class="staff-activity-meta">
                                <span class="staff-activity-pill"><?= htmlspecialchars($category) ?></span>
                                <span class="staff-activity-pill staff-activity-pill--role"><?= htmlspecialchars(ucfirst((string)($activity['actor_role'] ?? 'staff'))) ?></span>
                                <?php if ($highRisk): ?>
                                    <span class="staff-activity-pill staff-activity-pill--risk">High-risk</span>
                                <?php endif; ?>
                                <?php if ($integrityTag !== null): ?>
                                    <span class="staff-activity-pill <?= $integrityStatus === 'tampered' ? 'staff-activity-pill--warning' : 'staff-activity-pill--legacy' ?>"><?= htmlspecialchars($integrityTag) ?></span>
                                <?php endif; ?>
                                <span class="staff-activity-pill"><?= htmlspecialchars($formatAction((string)($activity['action'] ?? ''))) ?></span>
                            </div>
                            <p class="staff-activity-summary-text"><?= htmlspecialchars($summary) ?></p>
                            <div class="staff-activity-subtext">
                                <?= htmlspecialchars($actorDisplay) ?> | <?= htmlspecialchars($relativeTime($timestamp)) ?> | <?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($timestamp ?: 'now'))) ?>
                            </div>
                            <?php if ($integrityNote !== null): ?>
                                <div class="staff-activity-integrity-note <?= $integrityStatus === 'tampered' ? 'staff-activity-integrity-note--tampered' : 'staff-activity-integrity-note--legacy' ?>">
                                    <?= htmlspecialchars($integrityNote) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="staff-activity-side">
                            <div class="staff-activity-side-card">
                                <div class="staff-activity-side-label">Target</div>
                                <div class="staff-activity-side-value">
                                    <?php if ($targetHref !== null): ?>
                                        <a href="<?= htmlspecialchars($targetHref) ?>"><?= htmlspecialchars($target) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($target) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="staff-activity-side-card">
                                <div class="staff-activity-side-label">Actor</div>
                                <div class="staff-activity-side-value"><?= htmlspecialchars($actorDisplay) ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($details !== []): ?>
                        <details class="staff-activity-details">
                            <summary>Show details</summary>
                            <div class="staff-activity-details-grid">
                                <?php foreach ($details as $detail): ?>
                                    <div class="staff-activity-detail-card">
                                        <div class="staff-activity-detail-label"><?= htmlspecialchars((string)$detail['label']) ?></div>
                                        <div class="staff-activity-detail-value"><?= htmlspecialchars((string)$detail['value']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="staff-activity-pagination mt-4">
            <div class="staff-activity-pagination-copy">
                Page <?= number_format($currentPage) ?> of <?= number_format($totalPages) ?>.
            </div>
            <div class="staff-activity-pagination-actions">
                <a class="btn btn-outline-secondary<?= $canGoBack ? '' : ' disabled' ?>" href="<?= $canGoBack ? htmlspecialchars($pageUrl(1)) : '#' ?>" aria-disabled="<?= $canGoBack ? 'false' : 'true' ?>">First</a>
                <a class="btn btn-outline-secondary<?= $canGoBack ? '' : ' disabled' ?>" href="<?= $canGoBack ? htmlspecialchars($pageUrl($currentPage - 1)) : '#' ?>" aria-disabled="<?= $canGoBack ? 'false' : 'true' ?>">Previous</a>
                <a class="btn btn-outline-secondary<?= $canGoForward ? '' : ' disabled' ?>" href="<?= $canGoForward ? htmlspecialchars($pageUrl($currentPage + 1)) : '#' ?>" aria-disabled="<?= $canGoForward ? 'false' : 'true' ?>">Next</a>
                <a class="btn btn-outline-secondary<?= $canGoForward ? '' : ' disabled' ?>" href="<?= $canGoForward ? htmlspecialchars($pageUrl($totalPages)) : '#' ?>" aria-disabled="<?= $canGoForward ? 'false' : 'true' ?>">Last</a>
            </div>
        </div>
    <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<?php include __DIR__ . '/footer.php'; ?>
