<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "My Tickets - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/account_sidebar_styles.php';
$statusLabels = $statusLabels ?? [];
$selectedTicket = $selectedTicket ?? null;
$tickets = is_array($tickets ?? null) ? $tickets : [];
?>

<style>
    .tickets-shell { margin-top: 1rem; }
    .tickets-main { min-width: 0; }
    .tickets-layout { display: grid; grid-template-columns: minmax(320px, 380px) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .tickets-card { background: white; border: 1px solid var(--border-color); border-radius: 16px; }
    .tickets-card__header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
    .tickets-card__body { padding: 1.25rem; }
    .tickets-card--detail { min-height: 560px; }
    .tickets-list { display: flex; flex-direction: column; gap: 0.75rem; }
    .tickets-list-item { display: block; padding: 1rem; border: 1px solid var(--border-color); border-radius: 12px; text-decoration: none; color: inherit; }
    .tickets-list-item:hover { border-color: var(--primary-color); background: #f8fbff; }
    .tickets-list-item--active { border-color: var(--primary-color); background: #eff6ff; }
    .tickets-list-subject { font-weight: 700; margin-bottom: 0.4rem; }
    .tickets-list-meta { display: flex; justify-content: space-between; gap: 1rem; font-size: 0.8125rem; color: var(--text-muted); }
    .tickets-list-snippet { margin-top: 0.5rem; color: #4b5563; font-size: 0.875rem; }
    .tickets-status { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.65rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #eff6ff; color: #1d4ed8; }
    .tickets-status--waiting-user { background: #fff7ed; color: #c2410c; }
    .tickets-status--waiting-staff { background: #f3f4f6; color: #374151; }
    .tickets-status--closed { background: #ecfdf5; color: #047857; }
    .tickets-compose-form { display: grid; gap: 0.85rem; }
    .tickets-compose-form textarea,
    .tickets-compose-form input[type="text"],
    .tickets-reply-form textarea {
        width: 100%;
        max-width: 100%;
        display: block;
        box-sizing: border-box;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 0.8rem 0.9rem;
    }
    .tickets-thread { display: flex; flex-direction: column; gap: 1rem; }
    .tickets-message { border: 1px solid var(--border-color); border-radius: 14px; padding: 1rem; background: #f8fafc; }
    .tickets-message--admin { background: #eff6ff; border-color: rgba(37, 99, 235, 0.18); }
    .tickets-message-meta { display: flex; justify-content: space-between; gap: 1rem; font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 0.65rem; }
    .tickets-message-body { white-space: pre-wrap; line-height: 1.7; color: var(--text-color); }
    .tickets-thread-empty { color: var(--text-muted); text-align: center; padding: 3rem 1rem; }
    .tickets-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
    .tickets-reply-form { display: grid; gap: 0.75rem; margin-top: 1.5rem; }
    .tickets-empty-list { color: var(--text-muted); text-align: center; padding: 1rem 0.5rem 0; }
    .tickets-empty-list strong { display: block; color: var(--text-color); margin-bottom: 0.35rem; font-size: 0.95rem; }
    .tickets-compose-panel { display: grid; gap: 1rem; }
    .tickets-compose-intro { color: var(--text-muted); font-size: 0.875rem; line-height: 1.6; }
    .tickets-empty-detail {
        min-height: 420px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: var(--text-muted);
        padding: 2rem;
    }
    .tickets-empty-detail-inner {
        max-width: 420px;
        display: grid;
        gap: 0.9rem;
    }
    .tickets-empty-detail-title {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--text-color);
    }
    .tickets-empty-detail-copy {
        margin: 0;
        font-size: 0.95rem;
        line-height: 1.7;
    }
    .tickets-empty-detail-notes {
        margin: 0;
        padding: 0;
        list-style: none;
        display: grid;
        gap: 0.55rem;
        text-align: left;
    }
    .tickets-empty-detail-notes li {
        padding: 0.75rem 0.9rem;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        color: #475569;
        font-size: 0.88rem;
        line-height: 1.55;
    }
    @media (max-width: 1080px) {
        .tickets-layout { grid-template-columns: 1fr; }
        .tickets-card--detail { min-height: 0; }
        .tickets-empty-detail { min-height: 280px; padding: 1.5rem; }
    }
</style>

<div class="fm-container tickets-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>
    <div class="fm-main tickets-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Tickets</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Tickets</span>
                </div>
            </div>
            <div class="toolbar-right">
                <div class="toolbar-controls">
                    <span class="text-muted small">Open support tickets, track staff replies, and close or reopen conversations from one place.</span>
                </div>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-error mb-3"><?= htmlspecialchars((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success mb-3"><?= htmlspecialchars((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="tickets-layout">
            <div class="tickets-card">
                <div class="tickets-card__header">
                    <div>
                        <strong>My Tickets</strong>
                        <div class="small text-muted"><?= count($tickets) ?> total</div>
                    </div>
                </div>
                <div class="tickets-card__body">
                    <div class="tickets-compose-panel">
                        <div class="tickets-compose-intro">Open a support ticket when you need help with uploads, file access, billing, or account issues.</div>
                        <form method="POST" action="/tickets/create" class="tickets-compose-form mb-2">
                            <?= \App\Core\Csrf::field() ?>
                            <div>
                                <label class="form-label fw-semibold">New Support Ticket</label>
                                <input type="text" name="subject" placeholder="Short subject" maxlength="200" required>
                            </div>
                            <div>
                                <textarea name="message" rows="5" placeholder="Describe the issue or question clearly." required></textarea>
                            </div>
                            <button type="submit" class="btn">Open Ticket</button>
                        </form>
                    </div>

                    <?php if (empty($tickets)): ?>
                        <div class="tickets-empty-list">
                            <strong>No tickets yet</strong>
                            <span>Your conversations with staff will show up here once you open one.</span>
                        </div>
                    <?php else: ?>
                        <div class="tickets-list">
                            <?php foreach ($tickets as $ticket): ?>
                                <?php
                                $statusKey = str_replace('_', '-', (string)$ticket['status']);
                                $isActive = $selectedTicket && (string)$selectedTicket['public_id'] === (string)$ticket['public_id'];
                                ?>
                                <a href="/tickets/<?= urlencode((string)$ticket['public_id']) ?>" class="tickets-list-item<?= $isActive ? ' tickets-list-item--active' : '' ?>">
                                    <div class="d-flex justify-content-between gap-3 align-items-start">
                                        <div class="tickets-list-subject"><?= htmlspecialchars((string)$ticket['subject']) ?></div>
                                        <span class="tickets-status tickets-status--<?= htmlspecialchars($statusKey) ?>"><?= htmlspecialchars((string)($statusLabels[$ticket['status']] ?? ucfirst((string)$ticket['status']))) ?></span>
                                    </div>
                                    <div class="tickets-list-meta">
                                        <span>#<?= htmlspecialchars((string)$ticket['public_id']) ?></span>
                                        <span><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$ticket['updated_at']))) ?></span>
                                    </div>
                                    <div class="tickets-list-snippet"><?= htmlspecialchars(mb_strimwidth((string)($ticket['latest_message'] ?? ''), 0, 110, '...')) ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tickets-card tickets-card--detail">
                <div class="tickets-card__header">
                    <?php if ($selectedTicket): ?>
                        <div>
                            <strong><?= htmlspecialchars((string)$selectedTicket['subject']) ?></strong>
                            <div class="small text-muted">Ticket #<?= htmlspecialchars((string)$selectedTicket['public_id']) ?></div>
                        </div>
                        <div class="tickets-actions">
                            <span class="tickets-status tickets-status--<?= htmlspecialchars(str_replace('_', '-', (string)$selectedTicket['status'])) ?>"><?= htmlspecialchars((string)($statusLabels[$selectedTicket['status']] ?? ucfirst((string)$selectedTicket['status']))) ?></span>
                            <?php if ((string)$selectedTicket['status'] !== 'closed'): ?>
                                <form method="POST" action="/tickets/close/<?= urlencode((string)$selectedTicket['public_id']) ?>" class="m-0">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Close Ticket</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <strong>Select a ticket</strong>
                    <?php endif; ?>
                </div>
                <div class="tickets-card__body">
                    <?php if (!$selectedTicket): ?>
                        <div class="tickets-empty-detail">
                            <div class="tickets-empty-detail-inner">
                                <h3 class="tickets-empty-detail-title">Choose a ticket to read or reply</h3>
                                <p class="tickets-empty-detail-copy">Open a new support ticket on the left, or pick an existing conversation when you want to follow up with staff.</p>
                                <ul class="tickets-empty-detail-notes">
                                    <li>Use a clear subject so the right team can pick it up faster.</li>
                                    <li>Include links, filenames, and error details when they help explain the issue.</li>
                                    <li>Closed tickets reopen automatically when you reply again later.</li>
                                </ul>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="tickets-thread">
                            <?php foreach (($selectedTicket['thread'] ?? []) as $message): ?>
                                <div class="tickets-message<?= ($message['author_type'] ?? '') === 'admin' ? ' tickets-message--admin' : '' ?>">
                                    <div class="tickets-message-meta">
                                        <span>
                                            <?php if (($message['author_type'] ?? '') === 'admin'): ?>
                                                Staff<?= !empty($message['author_name']) ? ': ' . htmlspecialchars((string)$message['author_name']) : '' ?>
                                            <?php else: ?>
                                                You
                                            <?php endif; ?>
                                        </span>
                                        <span><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)$message['created_at']))) ?></span>
                                    </div>
                                    <div class="tickets-message-body"><?= htmlspecialchars((string)$message['body']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form method="POST" action="/tickets/reply/<?= urlencode((string)$selectedTicket['public_id']) ?>" class="tickets-reply-form">
                            <?= \App\Core\Csrf::field() ?>
                            <label class="form-label fw-semibold">Reply</label>
                            <textarea name="message" rows="5" placeholder="<?= (string)$selectedTicket['status'] === 'closed' ? 'Replying will reopen this ticket.' : 'Write your reply here.' ?>" required></textarea>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" class="btn"><?= (string)$selectedTicket['status'] === 'closed' ? 'Reply and Reopen' : 'Send Reply' ?></button>
                                <?php if ((string)$selectedTicket['status'] === 'closed'): ?>
                                    <span class="small text-muted align-self-center">Closed tickets reopen automatically when you reply.</span>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
