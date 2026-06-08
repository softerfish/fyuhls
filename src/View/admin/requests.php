<?php
$statusOptions = [
    'support_ticket' => ['open' => 'Open', 'waiting_user' => 'Waiting on User', 'waiting_staff' => 'Waiting on Staff', 'closed' => 'Closed'],
    'site_request' => ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'closed' => 'Closed'],
    'dmca_report' => ['pending' => 'Pending', 'investigating' => 'Investigating', 'resolved' => 'Resolved', 'rejected' => 'Rejected'],
    'abuse_report' => ['pending' => 'Pending', 'reviewed' => 'Reviewed', 'action_taken' => 'Action Taken', 'dismissed' => 'Dismissed'],
];
$statusLabels = [
    'support_ticket' => ['open' => 'Open', 'waiting_user' => 'Waiting on User', 'waiting_staff' => 'Waiting on Staff', 'closed' => 'Closed'],
    'site_request' => ['new' => 'Open', 'read' => 'Waiting on Staff', 'replied' => 'Waiting on User', 'archived' => 'Closed', 'closed' => 'Closed'],
    'dmca_report' => ['pending' => 'Open', 'investigating' => 'Waiting on Staff', 'accepted' => 'Closed', 'resolved' => 'Closed', 'rejected' => 'Closed'],
    'abuse_report' => ['pending' => 'Open', 'reviewed' => 'Waiting on Staff', 'action_taken' => 'Closed', 'ignored' => 'Closed', 'dismissed' => 'Closed'],
];

$renderLinkList = static function (?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '<span class="text-muted">No links submitted.</span>';
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $html = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $safeLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        if (filter_var($line, FILTER_VALIDATE_URL)) {
            $href = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $html[] = '<div><a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $safeLine . '</a></div>';
        } else {
            $html[] = '<div>' . $safeLine . '</div>';
        }
    }

    return $html ? implode('', $html) : '<span class="text-muted">No links submitted.</span>';
};

$typeLinks = [
    'all' => 'All',
    'archived' => 'Archive',
    'support_ticket' => 'Support',
    'site_request' => 'Contact',
    'dmca_report' => 'DMCA',
    'abuse_report' => 'Abuse',
];
$typeLinks = is_array($availableTypeLinks ?? null) && !empty($availableTypeLinks) ? $availableTypeLinks : $typeLinks;

$statusChipClass = static function (string $typeKey, string $status): string {
    return match ($typeKey) {
        'support_ticket' => match ($status) {
            'open', 'waiting_staff' => 'requests-status-chip requests-status-chip--open',
            'waiting_user' => 'requests-status-chip requests-status-chip--waiting',
            'closed' => 'requests-status-chip requests-status-chip--closed',
            default => 'requests-status-chip',
        },
        'site_request' => match ($status) {
            'new', 'read', 'open', 'waiting_staff' => 'requests-status-chip requests-status-chip--open',
            'replied', 'waiting_user' => 'requests-status-chip requests-status-chip--waiting',
            'archived', 'closed' => 'requests-status-chip requests-status-chip--closed',
            default => 'requests-status-chip',
        },
        'dmca_report', 'abuse_report' => match ($status) {
            'pending', 'reviewed', 'investigating', 'open', 'waiting_staff' => 'requests-status-chip requests-status-chip--open',
            'waiting_user' => 'requests-status-chip requests-status-chip--waiting',
            'accepted', 'resolved', 'rejected', 'action_taken', 'dismissed', 'ignored', 'closed' => 'requests-status-chip requests-status-chip--closed',
            default => 'requests-status-chip',
        },
        default => 'requests-status-chip',
    };
};

$priorityLabel = static function (string $priority): string {
    return $priority === 'high' ? 'High Priority' : 'Normal';
};

$priorityClass = static function (string $priority): string {
    return $priority === 'high' ? 'requests-priority-chip requests-priority-chip--high' : 'requests-priority-chip requests-priority-chip--normal';
};

$typeBadgeClass = static function (string $typeKey): string {
    return match ($typeKey) {
        'support_ticket' => 'bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        'abuse_report' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        'dmca_report' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        default => 'bg-info-subtle text-info-emphasis border border-info-subtle',
    };
};

$staleLabel = static function (int $days): string {
    if ($days >= 14) {
        return $days . 'd stale';
    }
    if ($days >= 7) {
        return $days . 'd old';
    }
    if ($days >= 3) {
        return $days . 'd aging';
    }
    return 'Fresh';
};

$staleClass = static function (int $days): string {
    if ($days >= 14) {
        return 'requests-stale-chip requests-stale-chip--danger';
    }
    if ($days >= 7) {
        return 'requests-stale-chip requests-stale-chip--warning';
    }
    if ($days >= 3) {
        return 'requests-stale-chip requests-stale-chip--info';
    }
    return 'requests-stale-chip requests-stale-chip--fresh';
};

include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';
$requestBasePath = $requestBasePath ?? '/admin/requests';
$requestsLockedType = $requestsLockedType ?? null;
$returnTo = $returnTo ?? ($_SERVER['REQUEST_URI'] ?? $requestBasePath);
$requestsPageTitle = $requestsPageTitle ?? 'Tickets';
$requestsPageIntro = $requestsPageIntro ?? 'One queue for support tickets, contact submissions, abuse reports, and DMCA notices.';
$requestPagination = is_array($requestPagination ?? null) ? $requestPagination : [];
$requestPage = max(1, (int)($requestPagination['page'] ?? 1));
$requestPerPage = max(1, (int)($requestPagination['per_page'] ?? max(1, count($items ?? []))));
$requestTotal = max(0, (int)($requestPagination['total'] ?? count($items ?? [])));
$requestTotalPages = max(1, (int)($requestPagination['total_pages'] ?? 1));
$requestPageStart = $requestTotal > 0 ? (($requestPage - 1) * $requestPerPage) + 1 : 0;
$requestPageEnd = $requestTotal > 0 ? min($requestTotal, $requestPageStart + count($items ?? []) - 1) : 0;
$requestQueueUrl = static function (int $page) use ($requestBasePath, $requestsLockedType, $filterType, $filterStatus, $filterPriority, $filterStale, $searchQuery): string {
    $params = [
        'type' => $requestsLockedType !== null ? (string)$requestsLockedType : (string)$filterType,
    ];
    if ($filterStatus !== '') {
        $params['status'] = $filterStatus;
    }
    if ($filterPriority !== '') {
        $params['priority'] = $filterPriority;
    }
    if ($filterStale !== '') {
        $params['stale'] = $filterStale;
    }
    if ($searchQuery !== '') {
        $params['q'] = $searchQuery;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }

    return $requestBasePath . '?' . http_build_query($params);
};
?>

<?php renderAdminPageHeader($requestsPageTitle, $requestsPageIntro); ?>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-2">
        <?php renderAdminStatCard('Open Tickets', number_format((int)($summary['open_total'] ?? 0)), 'bg-primary text-white', 'h2 mb-0'); ?>
    </div>
    <div class="col-md-6 col-xl-2">
        <?php renderAdminStatCard('Needs Staff Reply', number_format((int)($summary['needs_staff_action'] ?? 0)), 'bg-danger text-white', 'h2 mb-0'); ?>
    </div>
    <div class="col-md-6 col-xl-2">
        <?php renderAdminStatCard('Waiting on User', number_format((int)($summary['waiting_on_user'] ?? 0)), 'bg-warning text-dark', 'h2 mb-0'); ?>
    </div>
    <div class="col-md-6 col-xl-2">
        <?php renderAdminStatCard('High Priority Tickets', number_format((int)($summary['high_priority'] ?? 0)), 'bg-dark text-white', 'h2 mb-0'); ?>
    </div>
    <div class="col-md-6 col-xl-2">
        <?php renderAdminStatCard('Open 3+ Days', number_format((int)($summary['stale_over_3d'] ?? 0)), 'bg-secondary text-white', 'h2 mb-0'); ?>
    </div>
    <div class="col-md-6 col-xl-2">
        <?php renderAdminStatCard('Open 7+ Days', number_format((int)($summary['stale_over_7d'] ?? 0)), 'bg-danger-subtle text-danger-emphasis', 'h2 mb-0'); ?>
    </div>
</div>

<?php renderAdminCardStart(null, ['cardClass' => 'card border-0 shadow-sm mb-4', 'bodyClass' => 'card-body']); ?>
    <div class="d-flex flex-column gap-3">
        <?php if ($requestsLockedType === null): ?>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php foreach ($typeLinks as $typeKey => $label): ?>
                    <?php
                    $count = match ($typeKey) {
                        'support_ticket' => (int)($typeCounts['support_ticket'] ?? 0),
                        'site_request' => (int)($typeCounts['site_request'] ?? 0),
                        'abuse_report' => (int)($typeCounts['abuse_report'] ?? 0),
                        'dmca_report' => (int)($typeCounts['dmca_report'] ?? 0),
                        'all' => (int)($summary['open_total'] ?? 0),
                        'archived' => 0,
                        default => 0,
                    };
                    $url = $requestBasePath . '?type=' . urlencode($typeKey)
                        . ($filterStatus !== '' ? '&status=' . urlencode($filterStatus) : '')
                        . ($filterPriority !== '' ? '&priority=' . urlencode($filterPriority) : '')
                        . ($filterStale !== '' ? '&stale=' . urlencode($filterStale) : '')
                        . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '');
                    ?>
                    <a href="<?= htmlspecialchars($url) ?>" class="btn btn-sm <?= $filterType === $typeKey ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <?= htmlspecialchars($label) ?>
                        <?php if ($typeKey !== 'archived'): ?>
                            <span class="badge ms-2 <?= $filterType === $typeKey ? 'bg-white text-primary' : 'bg-light text-dark border' ?>"><?= number_format($count) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="<?= htmlspecialchars($requestBasePath) ?>" class="row g-2 align-items-end">
            <?php if ($requestsLockedType !== null): ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars((string)$requestsLockedType) ?>">
            <?php else: ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars((string)$filterType) ?>">
            <?php endif; ?>
            <div class="col-lg-4">
                <label class="form-label fw-semibold small mb-1">Search Queue</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Ticket ID, subject, user, email..." value="<?= htmlspecialchars((string)$searchQuery) ?>">
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <?php foreach (($allStatusOptions ?? []) as $statusKey): ?>
                        <option value="<?= htmlspecialchars((string)$statusKey) ?>" <?= $filterStatus === (string)$statusKey ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$statusKey))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">Priority</label>
                <select name="priority" class="form-select form-select-sm">
                    <option value="">All priorities</option>
                    <option value="normal" <?= $filterPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="high" <?= $filterPriority === 'high' ? 'selected' : '' ?>>High</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-2">
                <label class="form-label fw-semibold small mb-1">Staleness</label>
                <select name="stale" class="form-select form-select-sm">
                    <option value="">Any age</option>
                    <option value="3d" <?= $filterStale === '3d' ? 'selected' : '' ?>>3+ days</option>
                    <option value="7d" <?= $filterStale === '7d' ? 'selected' : '' ?>>7+ days</option>
                    <option value="14d" <?= $filterStale === '14d' ? 'selected' : '' ?>>14+ days</option>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">Apply</button>
                <a href="<?= htmlspecialchars($requestBasePath . '?type=' . urlencode((string)$filterType)) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart(null, ['cardClass' => 'card border-0 shadow-sm']); ?>
    <div class="requests-pagination">
        <div class="requests-pagination__copy">
            <?php if ($requestTotal > 0): ?>
                Showing <?= number_format($requestPageStart) ?>-<?= number_format($requestPageEnd) ?> of <?= number_format($requestTotal) ?> tickets
            <?php else: ?>
                No tickets to show
            <?php endif; ?>
        </div>
        <div class="requests-pagination__actions">
            <a class="btn btn-sm btn-outline-secondary <?= $requestPage <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars($requestQueueUrl(max(1, $requestPage - 1))) ?>" aria-disabled="<?= $requestPage <= 1 ? 'true' : 'false' ?>">Previous</a>
            <span class="requests-pagination__page">Page <?= number_format($requestPage) ?> of <?= number_format($requestTotalPages) ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $requestPage >= $requestTotalPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars($requestQueueUrl(min($requestTotalPages, $requestPage + 1))) ?>" aria-disabled="<?= $requestPage >= $requestTotalPages ? 'true' : 'false' ?>">Next</a>
        </div>
    </div>
    <?php if (empty($items)): ?>
        <div class="text-center text-muted py-5 mb-0">
            <?= !empty($showArchived) ? 'No archived tickets matched this view.' : 'No tickets matched this queue filter.' ?>
        </div>
    <?php else: ?>
        <div class="requests-list">
            <?php foreach ($items as $item): ?>
                <?php
                $detailId = 'request-detail-' . $item['type_key'] . '-' . (int)$item['id'];
                $replyId = 'reply-collapse-' . $item['type_key'] . '-' . (int)$item['id'];
                $days = (int)($item['stale_days'] ?? 0);
                $typeKey = (string)($item['type_key'] ?? '');
                $backend = (string)($item['backend'] ?? 'legacy');
                $itemStatusOptions = is_array($item['status_options'] ?? null) && !empty($item['status_options']) ? $item['status_options'] : ($statusOptions[$typeKey] ?? []);
                $itemStatusLabels = is_array($item['status_labels'] ?? null) && !empty($item['status_labels']) ? $item['status_labels'] : ($statusLabels[$typeKey] ?? []);
                $label = (string)($itemStatusLabels[$item['status']] ?? $item['status']);
                $assignedStaffName = trim((string)($item['assigned_staff_username'] ?? ''));
                $hiddenFromOthers = !empty($item['hidden_from_others']);
                $typeAssignableStaff = is_array($assignableStaffByType[$typeKey] ?? null) ? $assignableStaffByType[$typeKey] : [];
                $canReassignHiddenTicket = !empty($item['can_reassign_hidden_ticket']);
                ?>
                <div class="requests-item border-bottom">
                    <div class="requests-item__summary">
                        <div class="requests-item__meta">
                            <span class="badge rounded-pill <?= $typeBadgeClass($typeKey) ?>"><?= htmlspecialchars((string)$item['request_type']) ?></span>
                            <span class="<?= $statusChipClass($typeKey, (string)$item['status']) ?>"><?= htmlspecialchars($label) ?></span>
                            <span class="<?= $priorityClass((string)($item['priority'] ?? 'normal')) ?>"><?= htmlspecialchars($priorityLabel((string)($item['priority'] ?? 'normal'))) ?></span>
                            <span class="<?= $staleClass($days) ?>"><?= htmlspecialchars($staleLabel($days)) ?></span>
                            <?php if (!empty($item['public_id'])): ?>
                                <code class="requests-public-id">#<?= htmlspecialchars((string)$item['public_id']) ?></code>
                            <?php endif; ?>
                            <?php if ($assignedStaffName !== ''): ?>
                                <span class="requests-assignee-chip">Assigned: <?= htmlspecialchars($assignedStaffName) ?></span>
                            <?php endif; ?>
                            <?php if ($hiddenFromOthers): ?>
                                <span class="requests-hidden-chip">Hidden</span>
                            <?php endif; ?>
                        </div>

                        <div class="requests-item__header">
                            <div class="requests-item__from">
                                <div class="fw-bold"><?= htmlspecialchars((string)$item['submitter_name']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars((string)$item['submitter_email']) ?></div>
                            </div>
                            <div class="requests-item__dates text-end small text-muted">
                                <div>Opened <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$item['created_at']))) ?></div>
                                <div>Latest <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)($item['last_touch_at'] ?? $item['created_at'])))) ?></div>
                            </div>
                        </div>

                        <div class="requests-item__subject">
                            <div class="fw-semibold"><?= htmlspecialchars((string)$item['target']) ?></div>
                            <div class="small text-muted mt-1">
                                <?= htmlspecialchars(mb_strimwidth((string)($item['summary'] ?? ''), 0, 190, '...')) ?>
                            </div>
                        </div>

                        <div class="requests-item__footer">
                            <div class="small text-muted">
                                <?php if (!empty($item['latest_reply'])): ?>
                                    Last reply <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$item['latest_reply']['created_at']))) ?>
                                    <?php if (!empty($item['latest_reply']['username'])): ?>
                                        by <?= htmlspecialchars((string)$item['latest_reply']['username']) ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    No reply activity yet
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($detailId) ?>">Open Ticket</button>
                                <?php if (in_array($typeKey, ['support_ticket', 'site_request', 'dmca_report'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($replyId) ?>">Reply</button>
                                <?php endif; ?>
                                <?php if ($typeKey === 'abuse_report'): ?>
                                    <form method="POST" action="/admin/abuse-reports/action" data-confirm-message="Permanently delete this file?">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="report_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="request_backend" value="<?= htmlspecialchars($backend) ?>">
                                        <?php if (!empty($item['public_id'])): ?>
                                            <input type="hidden" name="request_public_id" value="<?= htmlspecialchars((string)$item['public_id']) ?>">
                                        <?php endif; ?>
                                        <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)$returnTo) ?>">
                                        <input type="hidden" name="action" value="delete_file">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete File</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="collapse requests-item__detail" id="<?= htmlspecialchars($detailId) ?>">
                        <div class="p-3 p-lg-4 bg-light-subtle">
                            <div class="row g-4">
                                <div class="col-xl-7">
                                    <div class="requests-panel mb-4">
                                        <div class="requests-panel__title">Ticket Details</div>
                                        <div class="small text-muted mb-3">Submitted <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$item['created_at']))) ?></div>
                                        <div class="mb-3">
                                            <div class="fw-semibold mb-2">Target</div>
                                            <div class="requests-target-detail">
                                                <?php if ($typeKey === 'dmca_report'): ?>
                                                    <?= $renderLinkList((string)$item['target']) ?>
                                                <?php else: ?>
                                                    <?= htmlspecialchars((string)$item['target']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($typeKey === 'abuse_report'): ?>
                                            <div class="mb-3"><strong>Reason:</strong> <?= htmlspecialchars((string)($item['reason'] ?? '')) ?></div>
                                        <?php endif; ?>
                                        <div class="requests-message-block"><?= htmlspecialchars((string)($item['details'] ?? $item['summary'] ?? '')) ?></div>
                                        <?php if ($typeKey === 'dmca_report' && !empty($item['signature'])): ?>
                                            <div class="mt-3">
                                                <div class="fw-semibold mb-2">Signature</div>
                                                <div class="requests-message-block"><?= htmlspecialchars((string)$item['signature']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($item['thread'])): ?>
                                        <div class="requests-panel mb-4">
                                            <div class="requests-panel__title">Conversation</div>
                                            <div class="requests-thread">
                                                <?php foreach (($item['thread'] ?? []) as $message): ?>
                                                    <?php
                                                    $messageType = (string)($message['message_type'] ?? '');
                                                    $authorType = (string)($message['author_type'] ?? '');
                                                    $bubbleClass = $messageType === 'note'
                                                        ? 'requests-thread__item requests-thread__item--note'
                                                        : ($authorType === 'admin'
                                                            ? 'requests-thread__item requests-thread__item--staff'
                                                            : 'requests-thread__item requests-thread__item--user');
                                                    ?>
                                                    <div class="<?= $bubbleClass ?>">
                                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                                            <div>
                                                                <div class="fw-semibold small">
                                                                    <?php if ($messageType === 'note'): ?>
                                                                        Internal Note
                                                                    <?php elseif ($authorType === 'admin'): ?>
                                                                        Staff<?= !empty($message['author_name']) ? ': ' . htmlspecialchars((string)$message['author_name']) : '' ?>
                                                                    <?php else: ?>
                                                                        User
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="requests-prewrap small mt-2"><?= htmlspecialchars((string)($message['body'] ?? '')) ?></div>
                                                            </div>
                                                            <div class="small text-muted text-end"><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$message['created_at']))) ?></div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($typeKey === 'dmca_report'): ?>
                                        <?php $targetFiles = is_array($item['target_files'] ?? null) ? $item['target_files'] : []; ?>
                                        <div class="requests-panel">
                                            <div class="requests-panel__title">DMCA File Removal</div>
                                            <div class="small text-muted mb-3">Process selected files, or remove every matched file from this DMCA notice in one step.</div>
                                            <?php if (empty($targetFiles)): ?>
                                                <div class="small text-muted">No DMCA target URLs were submitted.</div>
                                            <?php else: ?>
                                                <form method="POST" action="/admin/requests/dmca-process" class="dmca-process-form">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                                    <input type="hidden" name="request_backend" value="<?= htmlspecialchars($backend) ?>">
                                                    <?php if (!empty($item['public_id'])): ?>
                                                        <input type="hidden" name="request_public_id" value="<?= htmlspecialchars((string)$item['public_id']) ?>">
                                                    <?php endif; ?>
                                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)$returnTo) ?>">
                                                    <div class="dmca-process-feedback mb-3" hidden></div>
                                                    <div class="list-group list-group-flush border rounded mb-3">
                                                        <?php foreach ($targetFiles as $targetFile): ?>
                                                            <?php
                                                            $matched = !empty($targetFile['matched']);
                                                            $fileId = (int)($targetFile['file_id'] ?? 0);
                                                            $fileStatus = (string)($targetFile['status'] ?? '');
                                                            $alreadyRemoved = in_array($fileStatus, ['deleted', 'pending_purge', 'failed', 'abandoned', 'quarantined'], true);
                                                            $disabled = !$matched || $alreadyRemoved;
                                                            ?>
                                                            <label class="list-group-item d-flex align-items-start gap-3 <?= $disabled ? 'bg-light' : '' ?>"<?= $fileId > 0 ? ' data-dmca-file-id="' . $fileId . '"' : '' ?>>
                                                                <input class="form-check-input mt-1" type="checkbox" name="file_ids[]" value="<?= $fileId ?>" <?= $disabled ? 'disabled' : '' ?>>
                                                                <div class="flex-grow-1 min-w-0">
                                                                    <div class="small">
                                                                        <a href="<?= htmlspecialchars((string)$targetFile['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$targetFile['url']) ?></a>
                                                                    </div>
                                                                    <?php if ($matched): ?>
                                                                        <div class="small mt-1">
                                                                            <strong><?= htmlspecialchars((string)($targetFile['filename'] ?? ('File #' . $fileId))) ?></strong>
                                                                            <?php if (!empty($targetFile['short_id'])): ?>
                                                                                <span class="text-muted">(<?= htmlspecialchars((string)$targetFile['short_id']) ?>)</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div class="small text-muted dmca-file-status"><?= $alreadyRemoved ? 'Already removed or pending removal.' : 'Ready to mark for removal.' ?></div>
                                                                    <?php else: ?>
                                                                        <div class="small text-danger mt-1">No local file match was found for this URL.</div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <button type="submit" name="process_mode" value="selected" class="btn btn-sm btn-outline-danger">Process Selected Files</button>
                                                        <button type="submit" name="process_mode" value="all" class="btn btn-sm btn-danger">Process All Files for Removal</button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-xl-5">
                                    <div class="requests-panel mb-4">
                                        <div class="requests-panel__title">Workflow</div>
                                        <div class="small text-muted mb-3">Use quick status buttons for triage, then reply or leave an internal note without leaving the queue.</div>
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <?php foreach ($itemStatusOptions as $value => $optionLabel): ?>
                                                <?php
                                                $isSelected = ((string)$item['status'] === (string)$value)
                                                    || ((string)$item['status'] === 'archived' && (string)$value === 'closed')
                                                    || ((string)$item['status'] === 'accepted' && (string)$value === 'resolved')
                                                    || ((string)$item['status'] === 'ignored' && (string)$value === 'dismissed');
                                                ?>
                                                <form method="POST" action="/admin/requests/status" class="m-0">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <input type="hidden" name="request_type" value="<?= htmlspecialchars($typeKey) ?>">
                                                    <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                                    <input type="hidden" name="request_backend" value="<?= htmlspecialchars($backend) ?>">
                                                    <?php if (!empty($item['public_id'])): ?>
                                                        <input type="hidden" name="request_public_id" value="<?= htmlspecialchars((string)$item['public_id']) ?>">
                                                    <?php endif; ?>
                                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)$returnTo) ?>">
                                                    <input type="hidden" name="status" value="<?= htmlspecialchars((string)$value) ?>">
                                                    <button type="submit" class="btn btn-sm <?= $isSelected ? 'btn-primary' : 'btn-outline-primary' ?>"><?= htmlspecialchars((string)$optionLabel) ?></button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?php if ($backend === 'ticket'): ?>
                                                Closed tickets move into the Archive view.
                                            <?php elseif ($typeKey === 'site_request'): ?>
                                                Closed tickets move into the Archive view.
                                            <?php elseif ($typeKey === 'support_ticket'): ?>
                                                Closed tickets move into the Archive view and user replies will reopen them.
                                            <?php elseif ($typeKey === 'dmca_report'): ?>
                                                Resolved and rejected notices move into the Archive view.
                                            <?php elseif ($typeKey === 'abuse_report'): ?>
                                                Action taken and dismissed abuse reports move into the Archive view.
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($backend === 'ticket'): ?>
                                        <form method="POST" action="/admin/requests/assign" class="requests-panel mb-4">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="request_type" value="<?= htmlspecialchars($typeKey) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                            <input type="hidden" name="request_backend" value="<?= htmlspecialchars($backend) ?>">
                                            <?php if (!empty($item['public_id'])): ?>
                                                <input type="hidden" name="request_public_id" value="<?= htmlspecialchars((string)$item['public_id']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)$returnTo) ?>">
                                            <div class="requests-panel__title">Assignment</div>
                                            <div class="small text-muted mb-3">Assign this ticket to a specific admin or moderator who can work this queue.</div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Assigned Staff</label>
                                                <select name="assigned_staff_user_id" class="form-select form-select-sm">
                                                    <option value="">Unassigned</option>
                                                    <?php foreach ($typeAssignableStaff as $staffOption): ?>
                                                        <option value="<?= (int)$staffOption['id'] ?>" <?= !empty($item['assigned_staff_user_id']) && (int)$item['assigned_staff_user_id'] === (int)$staffOption['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars((string)$staffOption['name']) ?> (<?= htmlspecialchars(ucfirst((string)$staffOption['role'])) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <?php if ($hiddenFromOthers && !$canReassignHiddenTicket): ?>
                                                <div class="small text-muted">Hidden tickets can only be reassigned by the admin who hid them or the protected super admin.</div>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Save Assignment</button>
                                            <?php endif; ?>
                                        </form>

                                        <form method="POST" action="/admin/requests/visibility" class="requests-panel mb-4">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="request_type" value="<?= htmlspecialchars($typeKey) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                            <input type="hidden" name="request_backend" value="<?= htmlspecialchars($backend) ?>">
                                            <?php if (!empty($item['public_id'])): ?>
                                                <input type="hidden" name="request_public_id" value="<?= htmlspecialchars((string)$item['public_id']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)$returnTo) ?>">
                                            <input type="hidden" name="hidden_from_others" value="<?= $hiddenFromOthers ? '0' : '1' ?>">
                                            <div class="requests-panel__title">Visibility</div>
                                            <div class="small text-muted mb-3">
                                                <?php if ($hiddenFromOthers): ?>
                                                    Hidden tickets are only visible to the protected super admin, the admin who hid them, and the assigned staff member.
                                                <?php else: ?>
                                                    Visible tickets can be seen by any eligible staff member for this queue.
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($hiddenFromOthers && !empty($item['hidden_by_admin_username'])): ?>
                                                <div class="small text-muted mb-3">Hidden by <?= htmlspecialchars((string)$item['hidden_by_admin_username']) ?>.</div>
                                            <?php endif; ?>
                                            <?php if (!empty($canHideTicketVisibility)): ?>
                                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                    <?= $hiddenFromOthers ? 'Make Visible to Staff' : 'Hide from Other Staff' ?>
                                                </button>
                                            <?php else: ?>
                                                <div class="small text-muted">Only admins can change hidden visibility. Moderators can still assign tickets.</div>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (in_array($typeKey, ['support_ticket', 'site_request', 'dmca_report'], true)): ?>
                                        <div class="collapse mb-4" id="<?= htmlspecialchars($replyId) ?>">
                                            <form method="POST" action="/admin/requests/reply" class="requests-panel">
                                                <?= \App\Core\Csrf::field() ?>
                                                <input type="hidden" name="request_type" value="<?= htmlspecialchars($typeKey) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                                <input type="hidden" name="request_backend" value="<?= htmlspecialchars($backend) ?>">
                                                <?php if (!empty($item['public_id'])): ?>
                                                    <input type="hidden" name="request_public_id" value="<?= htmlspecialchars((string)$item['public_id']) ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)$returnTo) ?>">
                                                <div class="requests-panel__title">Reply to Ticket</div>
                                                <?php if ($backend !== 'ticket' && $typeKey !== 'support_ticket'): ?>
                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Reply Subject</label>
                                                        <input type="text" name="reply_subject" class="form-control form-control-sm" value="<?= htmlspecialchars($typeKey === 'dmca_report' ? 'Re: DMCA Notice' : 'Re: ' . (string)$item['target']) ?>" required>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Reply Message</label>
                                                    <textarea name="reply_message" class="form-control form-control-sm" rows="6" required></textarea>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Status After Reply</label>
                                                    <select name="status_after_reply" class="form-select form-select-sm">
                                                        <?php foreach ($itemStatusOptions as $value => $optionLabel): ?>
                                                            <?php $defaultSelected = ($backend === 'ticket' && (string)$value === 'waiting_user') || ($backend !== 'ticket' && $typeKey === 'support_ticket' && (string)$value === 'waiting_user') || ($backend !== 'ticket' && $typeKey === 'site_request' && (string)$value === 'replied') || ($backend !== 'ticket' && $typeKey === 'dmca_report' && (string)$value === 'investigating'); ?>
                                                            <option value="<?= htmlspecialchars((string)$value) ?>" <?= $defaultSelected ? 'selected' : '' ?>><?= htmlspecialchars((string)$optionLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <button type="submit" class="btn btn-success btn-sm">Send Reply</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" action="/admin/requests/note" class="requests-panel mb-4">
                                        <?= \App\Core\Csrf::field() ?>
                                        <input type="hidden" name="request_type" value="<?= htmlspecialchars($typeKey) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="request_backend" value="<?= htmlspecialchars($backend) ?>">
                                        <?php if (!empty($item['public_id'])): ?>
                                            <input type="hidden" name="request_public_id" value="<?= htmlspecialchars((string)$item['public_id']) ?>">
                                        <?php endif; ?>
                                        <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)$returnTo) ?>">
                                        <div class="requests-panel__title">Internal Note</div>
                                        <textarea name="note" class="form-control form-control-sm mb-3" rows="4" placeholder="Add an internal note for this ticket..." required></textarea>
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">Save Note</button>
                                    </form>

                                    <div class="requests-panel requests-activity-section" data-request-activity-section="<?= htmlspecialchars($typeKey) ?>-<?= (int)$item['id'] ?>"<?= empty($item['activities']) ? ' hidden' : '' ?>>
                                        <div class="requests-panel__title">Activity</div>
                                        <div class="list-group list-group-flush border rounded requests-activity-list">
                                            <?php foreach (($item['activities'] ?? []) as $activity): ?>
                                                <div class="list-group-item">
                                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                                        <div>
                                                            <div class="fw-semibold text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', (string)$activity['activity_type'])) ?></div>
                                                            <?php if (!empty($activity['subject'])): ?>
                                                                <div><?= htmlspecialchars((string)$activity['subject']) ?></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($activity['body'])): ?>
                                                                <div class="requests-prewrap small text-muted mt-1"><?= htmlspecialchars((string)$activity['body']) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="small text-muted text-end">
                                                            <div><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$activity['created_at']))) ?></div>
                                                            <?php if (!empty($activity['username'])): ?>
                                                                <div><?= htmlspecialchars((string)$activity['username']) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="requests-pagination requests-pagination--bottom">
            <div class="requests-pagination__copy">
                Showing <?= number_format($requestPageStart) ?>-<?= number_format($requestPageEnd) ?> of <?= number_format($requestTotal) ?> tickets
            </div>
            <div class="requests-pagination__actions">
                <a class="btn btn-sm btn-outline-secondary <?= $requestPage <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars($requestQueueUrl(max(1, $requestPage - 1))) ?>" aria-disabled="<?= $requestPage <= 1 ? 'true' : 'false' ?>">Previous</a>
                <span class="requests-pagination__page">Page <?= number_format($requestPage) ?> of <?= number_format($requestTotalPages) ?></span>
                <a class="btn btn-sm btn-outline-secondary <?= $requestPage >= $requestTotalPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars($requestQueueUrl(min($requestTotalPages, $requestPage + 1))) ?>" aria-disabled="<?= $requestPage >= $requestTotalPages ? 'true' : 'false' ?>">Next</a>
            </div>
        </div>
    <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<style>
.requests-list { display: flex; flex-direction: column; }
.requests-pagination { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid rgba(15, 23, 42, 0.08); flex-wrap: wrap; }
.requests-pagination--bottom { border-top: 1px solid rgba(15, 23, 42, 0.08); border-bottom: 0; }
.requests-pagination__copy { color: #64748b; font-size: 0.92rem; font-weight: 600; }
.requests-pagination__actions { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.requests-pagination__actions .btn[aria-disabled="true"] { pointer-events: none; opacity: 0.55; }
.requests-pagination__page { color: #475569; font-size: 0.88rem; font-weight: 700; }
.requests-item__summary { padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: 0.9rem; }
.requests-item__meta { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
.requests-item__header { display: flex; justify-content: space-between; gap: 1rem; align-items: start; }
.requests-item__subject { min-width: 0; }
.requests-item__footer { display: flex; justify-content: space-between; gap: 1rem; align-items: center; flex-wrap: wrap; }
.requests-status-chip,
.requests-priority-chip,
.requests-stale-chip,
.requests-public-id,
.requests-assignee-chip,
.requests-hidden-chip {
    font-size: 0.72rem;
    font-weight: 700;
    border-radius: 999px;
    padding: 0.3rem 0.65rem;
}
.requests-status-chip--open { background: rgba(37, 99, 235, 0.1); color: #1d4ed8; }
.requests-status-chip--waiting { background: rgba(245, 158, 11, 0.12); color: #b45309; }
.requests-status-chip--closed { background: rgba(100, 116, 139, 0.14); color: #475569; }
.requests-priority-chip--high { background: rgba(220, 38, 38, 0.12); color: #b91c1c; }
.requests-priority-chip--normal { background: rgba(15, 23, 42, 0.06); color: #475569; }
.requests-stale-chip--fresh { background: rgba(34, 197, 94, 0.12); color: #15803d; }
.requests-stale-chip--info { background: rgba(59, 130, 246, 0.10); color: #1d4ed8; }
.requests-stale-chip--warning { background: rgba(245, 158, 11, 0.12); color: #b45309; }
.requests-stale-chip--danger { background: rgba(220, 38, 38, 0.12); color: #b91c1c; }
.requests-public-id { background: rgba(15, 23, 42, 0.06); color: #334155; }
.requests-assignee-chip { background: rgba(16, 185, 129, 0.12); color: #047857; }
.requests-hidden-chip { background: rgba(107, 33, 168, 0.12); color: #7c3aed; }
.requests-item__detail { border-top: 1px solid rgba(15, 23, 42, 0.08); }
.requests-panel { background: #fff; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 10px; padding: 1rem; }
.requests-panel__title { font-weight: 800; margin-bottom: 0.85rem; }
.requests-prewrap { white-space: pre-wrap; }
.requests-message-block { white-space: pre-wrap; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.9rem 1rem; }
.requests-thread { display: flex; flex-direction: column; gap: 0.75rem; }
.requests-thread__item { border-radius: 12px; padding: 0.9rem 1rem; border: 1px solid #e2e8f0; }
.requests-thread__item--user { background: #f8fafc; }
.requests-thread__item--staff { background: #eff6ff; border-color: #bfdbfe; }
.requests-thread__item--note { background: #fff7ed; border-color: #fed7aa; }
.requests-target-detail { word-break: break-word; }
@media (max-width: 991.98px) {
    .requests-item__header,
    .requests-item__footer { flex-direction: column; align-items: start; }
    .requests-item__dates { text-align: left !important; }
}
</style>

<script>
document.addEventListener('submit', async function(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('dmca-process-form')) {
        return;
    }

    event.preventDefault();

    const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
    if (!submitter) {
        form.submit();
        return;
    }

    const feedback = form.querySelector('.dmca-process-feedback');
    const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
    const processMode = submitter.value || 'selected';
    const formData = new FormData(form);
    formData.set('process_mode', processMode);
    const requestId = String(formData.get('request_id') || '');

    if (feedback instanceof HTMLElement) {
        feedback.hidden = true;
        feedback.className = 'dmca-process-feedback mb-3';
        feedback.textContent = '';
    }

    buttons.forEach((button) => {
        button.disabled = true;
    });

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
            credentials: 'same-origin',
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || !payload || !payload.success) {
            throw new Error(payload?.message || 'The DMCA removal request failed.');
        }

        const handledIds = Array.isArray(payload.handled_file_ids) ? payload.handled_file_ids.map((value) => String(value)) : [];
        handledIds.forEach((fileId) => {
            const row = Array.from(form.querySelectorAll('[data-dmca-file-id]')).find((candidate) => {
                return candidate.getAttribute('data-dmca-file-id') === fileId;
            });
            if (!(row instanceof HTMLElement)) {
                return;
            }

            row.classList.add('bg-light');
            const checkbox = row.querySelector('input[type="checkbox"][name="file_ids[]"]');
            if (checkbox instanceof HTMLInputElement) {
                checkbox.checked = false;
                checkbox.disabled = true;
            }

            const status = row.querySelector('.dmca-file-status');
            if (status instanceof HTMLElement) {
                status.textContent = 'Already removed or pending removal.';
            }
        });

        if (payload.activity && requestId !== '') {
            const activitySection = document.querySelector('[data-request-activity-section="dmca_report-' + requestId + '"]');
            if (activitySection instanceof HTMLElement) {
                activitySection.hidden = false;
                let activityList = activitySection.querySelector('.requests-activity-list');
                if (!(activityList instanceof HTMLElement)) {
                    activityList = document.createElement('div');
                    activityList.className = 'list-group list-group-flush border rounded requests-activity-list';
                    activitySection.appendChild(activityList);
                }

                const item = document.createElement('div');
                item.className = 'list-group-item';

                const row = document.createElement('div');
                row.className = 'd-flex justify-content-between align-items-start gap-3';

                const left = document.createElement('div');
                const type = document.createElement('div');
                type.className = 'fw-semibold text-capitalize';
                type.textContent = payload.activity.activity_label || payload.activity.activity_type || 'status';
                left.appendChild(type);

                if (payload.activity.subject) {
                    const subject = document.createElement('div');
                    subject.textContent = payload.activity.subject;
                    left.appendChild(subject);
                }

                if (payload.activity.body) {
                    const body = document.createElement('div');
                    body.className = 'requests-prewrap small text-muted mt-1';
                    body.textContent = payload.activity.body;
                    left.appendChild(body);
                }

                const right = document.createElement('div');
                right.className = 'small text-muted text-end';

                if (payload.activity.created_at_display) {
                    const created = document.createElement('div');
                    created.textContent = payload.activity.created_at_display;
                    right.appendChild(created);
                }

                if (payload.activity.username) {
                    const username = document.createElement('div');
                    username.textContent = payload.activity.username;
                    right.appendChild(username);
                }

                row.appendChild(left);
                row.appendChild(right);
                item.appendChild(row);
                activityList.prepend(item);
            }
        }

        if (feedback instanceof HTMLElement) {
            feedback.hidden = false;
            feedback.classList.add('alert', 'alert-success');
            feedback.textContent = payload.message || 'DMCA file removal processed.';
        }
    } catch (error) {
        if (feedback instanceof HTMLElement) {
            feedback.hidden = false;
            feedback.classList.add('alert', 'alert-danger');
            feedback.textContent = error instanceof Error ? error.message : 'The DMCA removal request failed.';
        }
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
});
</script>

<?php include 'footer.php'; ?>
