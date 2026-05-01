<?php
$statusOptions = [
    'site_request' => ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'closed' => 'Closed'],
    'dmca_report' => ['pending' => 'Pending', 'investigating' => 'Investigating', 'resolved' => 'Resolved', 'rejected' => 'Rejected'],
    'abuse_report' => ['pending' => 'Pending', 'reviewed' => 'Reviewed', 'action_taken' => 'Action Taken', 'dismissed' => 'Dismissed'],
];
$statusLabels = [
    'site_request' => ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'archived' => 'Closed'],
    'dmca_report' => ['pending' => 'Pending', 'investigating' => 'Investigating', 'accepted' => 'Resolved', 'rejected' => 'Rejected'],
    'abuse_report' => ['pending' => 'Pending', 'reviewed' => 'Reviewed', 'action_taken' => 'Action Taken', 'ignored' => 'Dismissed'],
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
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';
?>

<?php renderAdminPageHeader('Requests', 'One inbox for site requests, abuse reports, and DMCA submissions.'); ?>

<?php renderAdminCardStart(null, ['cardClass' => 'card border-0 shadow-sm mb-4', 'bodyClass' => 'card-body d-flex flex-wrap gap-2 align-items-center']); ?>
        <?php
        $typeLinks = [
            'all' => 'All',
            'archived' => 'Archive',
            'site_request' => 'Contact',
            'dmca_report' => 'DMCA',
            'abuse_report' => 'Abuse',
        ];
        foreach ($typeLinks as $typeKey => $label):
            $url = '/admin/requests?type=' . urlencode($typeKey);
        ?>
            <a href="<?= htmlspecialchars($url) ?>" class="btn btn-sm <?= $filterType === $typeKey ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>

        <form method="GET" action="/admin/requests" class="ms-auto d-flex gap-2 align-items-center">
            <input type="hidden" name="type" value="<?= htmlspecialchars((string)$filterType) ?>">
            <input type="text" name="status" class="requests-filter-input form-control form-control-sm" placeholder="Filter status" value="<?= htmlspecialchars((string)$filterStatus) ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
            <?php if (($filterStatus ?? '') !== ''): ?>
                <a href="/admin/requests?type=<?= urlencode((string)$filterType) ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart(null, ['cardClass' => 'card border-0 shadow-sm']); ?>
        <?php if (empty($items)): ?>
            <p class="text-center text-muted py-5 mb-0"><?= !empty($showArchived) ? 'No archived requests yet.' : 'No submitted requests yet.' ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Request</th>
                            <th>From</th>
                            <th>Target</th>
                            <th>Summary</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $detailId = 'request-detail-' . $item['type_key'] . '-' . (int)$item['id'];
                            $replyId = 'reply-collapse-' . $item['type_key'] . '-' . (int)$item['id'];
                            $badgeClass = match ($item['type_key']) {
                                'abuse_report' => 'bg-danger',
                                'dmca_report' => 'bg-warning text-dark',
                                default => 'bg-info text-dark',
                            };
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$item['created_at']))) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars((string)$item['request_type']) ?></span></td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$item['submitter_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars((string)$item['submitter_email']) ?></small>
                                </td>
                                <td class="requests-target-cell">
                                    <?php if (($item['type_key'] ?? '') === 'dmca_report'): ?>
                                        <?= $renderLinkList((string)$item['target']) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars((string)$item['target']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="requests-summary-cell">
                                    <?= htmlspecialchars(mb_strimwidth((string)$item['summary'], 0, 110, '...')) ?>
                                    <?php if (!empty($item['latest_reply'])): ?>
                                        <div class="small text-success mt-1">
                                            Last reply <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$item['latest_reply']['created_at']))) ?>
                                            <?php if (!empty($item['latest_reply']['username'])): ?>
                                                by <?= htmlspecialchars((string)$item['latest_reply']['username']) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string)($statusLabels[$item['type_key']][$item['status']] ?? $item['status'])) ?></span></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($detailId) ?>">Open</button>
                                        <?php if (in_array(($item['type_key'] ?? ''), ['site_request', 'dmca_report'], true)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($replyId) ?>">Reply</button>
                                        <?php endif; ?>
                                        <?php if (($item['type_key'] ?? '') === 'abuse_report'): ?>
                                            <form method="POST" action="/admin/abuse-reports/action" data-confirm-message="Permanently delete this file?">
                                                <?= \App\Core\Csrf::field() ?>
                                                <input type="hidden" name="report_id" value="<?= (int)$item['id'] ?>">
                                                <input type="hidden" name="action" value="delete_file">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete File</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr class="collapse" id="<?= htmlspecialchars($detailId) ?>">
                                <td colspan="7" class="bg-light">
                                    <div class="p-3 bg-white border rounded">
                                        <div class="row g-3">
                                            <div class="col-lg-7">
                                                <h6 class="fw-bold mb-2">Request Details</h6>
                                                <div class="small text-muted mb-2">Submitted <?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$item['created_at']))) ?></div>
                                                <div class="mb-2">
                                                    <strong>Target:</strong>
                                                    <div class="mt-2">
                                                        <?php if (($item['type_key'] ?? '') === 'dmca_report'): ?>
                                                            <?= $renderLinkList((string)$item['target']) ?>
                                                        <?php else: ?>
                                                            <?= htmlspecialchars((string)$item['target']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (($item['type_key'] ?? '') === 'abuse_report'): ?>
                                                    <p class="mb-2"><strong>Reason:</strong> <?= htmlspecialchars((string)($item['reason'] ?? '')) ?></p>
                                                <?php endif; ?>
                                                <div class="requests-prewrap border rounded p-3 bg-light-subtle"><?= htmlspecialchars((string)($item['details'] ?? $item['summary'])) ?></div>
                                                <?php if (($item['type_key'] ?? '') === 'dmca_report' && !empty($item['signature'])): ?>
                                                    <div class="mt-3">
                                                        <strong>Signature</strong>
                                                        <div class="border rounded p-3 bg-light-subtle mt-2"><?= htmlspecialchars((string)$item['signature']) ?></div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (($item['type_key'] ?? '') === 'dmca_report'): ?>
                                                    <?php $targetFiles = is_array($item['target_files'] ?? null) ? $item['target_files'] : []; ?>
                                                    <div class="mt-3 border rounded p-3">
                                                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                                                            <div>
                                                                <h6 class="fw-bold mb-1">Process Removal of Files</h6>
                                                                <div class="small text-muted">Process selected files, or remove every matched file from this DMCA request in one step.</div>
                                                            </div>
                                                        </div>
                                                        <?php if (empty($targetFiles)): ?>
                                                            <div class="small text-muted">No DMCA target URLs were submitted.</div>
                                                        <?php else: ?>
                                                            <form method="POST" action="/admin/requests/dmca-process" class="dmca-process-form">
                                                                <?= \App\Core\Csrf::field() ?>
                                                                <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
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
                                                                            <input
                                                                                class="form-check-input mt-1"
                                                                                type="checkbox"
                                                                                name="file_ids[]"
                                                                                value="<?= $fileId ?>"
                                                                                <?= $disabled ? 'disabled' : '' ?>
                                                                            >
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
                                                                                    <div class="small text-muted dmca-file-status">
                                                                                        <?= $alreadyRemoved ? 'Already removed or pending removal.' : 'Ready to mark for removal.' ?>
                                                                                    </div>
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
                                            <div class="col-lg-5">
                                                <h6 class="fw-bold mb-2">Workflow</h6>
                                                <div class="border rounded p-3 mb-3">
                                                    <label class="form-label fw-semibold d-block">Update Status</label>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <?php foreach (($statusOptions[$item['type_key']] ?? []) as $value => $label): ?>
                                                            <?php
                                                            $isSelected = ((string)$item['status'] === (string)$value)
                                                                || ((string)$item['status'] === 'archived' && (string)$value === 'closed')
                                                                || ((string)$item['status'] === 'accepted' && (string)$value === 'resolved')
                                                                || ((string)$item['status'] === 'ignored' && (string)$value === 'dismissed');
                                                            ?>
                                                            <form method="POST" action="/admin/requests/status" class="m-0">
                                                                <?= \App\Core\Csrf::field() ?>
                                                                <input type="hidden" name="request_type" value="<?= htmlspecialchars((string)$item['type_key']) ?>">
                                                                <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                                                <input type="hidden" name="status" value="<?= htmlspecialchars((string)$value) ?>">
                                                                <button type="submit" class="btn btn-sm <?= $isSelected ? 'btn-primary' : 'btn-outline-primary' ?>"><?= htmlspecialchars((string)$label) ?></button>
                                                            </form>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="small text-muted mt-2">
                                                        <?php if (($item['type_key'] ?? '') === 'site_request'): ?>
                                                            Closed requests move into the Archive tab.
                                                        <?php elseif (($item['type_key'] ?? '') === 'dmca_report'): ?>
                                                            Resolved and rejected DMCA reports move into the Archive tab.
                                                        <?php elseif (($item['type_key'] ?? '') === 'abuse_report'): ?>
                                                            Action taken and dismissed abuse reports move into the Archive tab.
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <?php if (in_array(($item['type_key'] ?? ''), ['site_request', 'dmca_report'], true)): ?>
                                                    <div class="collapse mb-3" id="<?= htmlspecialchars($replyId) ?>">
                                                        <form method="POST" action="/admin/requests/reply" class="border rounded p-3">
                                                            <?= \App\Core\Csrf::field() ?>
                                                            <input type="hidden" name="request_type" value="<?= htmlspecialchars((string)$item['type_key']) ?>">
                                                            <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                                            <div class="mb-2">
                                                                <label class="form-label fw-semibold">Reply Subject</label>
                                                                <input type="text" name="reply_subject" class="form-control form-control-sm" value="<?= htmlspecialchars(((string)$item['type_key']) === 'dmca_report' ? 'Re: DMCA Notice' : 'Re: ' . (string)$item['target']) ?>" required>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label fw-semibold">Reply Message</label>
                                                                <textarea name="reply_message" class="form-control form-control-sm" rows="5" required></textarea>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label fw-semibold">Status After Reply</label>
                                                                <select name="status_after_reply" class="form-select form-select-sm">
                                                                    <?php foreach (($statusOptions[$item['type_key']] ?? []) as $value => $label): ?>
                                                                        <option value="<?= htmlspecialchars((string)$value) ?>"><?= htmlspecialchars((string)$label) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <button type="submit" class="btn btn-success btn-sm">Send Reply</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>

                                                <form method="POST" action="/admin/requests/note" class="border rounded p-3">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <input type="hidden" name="request_type" value="<?= htmlspecialchars((string)$item['type_key']) ?>">
                                                    <input type="hidden" name="request_id" value="<?= (int)$item['id'] ?>">
                                                    <label class="form-label fw-semibold">Internal Note</label>
                                                    <textarea name="note" class="form-control form-control-sm mb-2" rows="3" placeholder="Add an internal note for this request..." required></textarea>
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Save Note</button>
                                                </form>
                                            </div>
                                        </div>

                                        <div class="mt-4 requests-activity-section" data-request-activity-section="<?= htmlspecialchars((string)$item['type_key']) ?>-<?= (int)$item['id'] ?>"<?= empty($item['activities']) ? ' hidden' : '' ?>>
                                                <h6 class="fw-bold mb-3">Activity</h6>
                                                <div class="list-group list-group-flush border rounded requests-activity-list">
                                                    <?php foreach ($item['activities'] as $activity): ?>
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
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<style>
.requests-filter-input{max-width:180px}
.requests-target-cell{max-width:280px;word-break:break-word}
.requests-summary-cell{max-width:320px;word-break:break-word}
.requests-prewrap{white-space:pre-wrap}
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
