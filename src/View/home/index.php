<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = $pageTitle ?? "Dashboard - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . filemtime(BASE_PATH . '/public/assets/css/filemanager.css') . '">
<style>
    .home-header { padding: 0.85rem 1.75rem; }
    .home-nav { gap: 1.25rem; }
    .home-nav-link { font-size: 0.95rem; color: #475569; }
    .home-nav-link:hover { color: var(--text-color); }
    .home-logout-btn { background: #e9eef6; color: #0f172a; }
    .home-notification-link { opacity: 0.82; transition: opacity .15s ease; }
    .home-notification-link:hover { opacity: 1; }
    .dashboard-shell { margin-top: 1rem; }
    .dashboard-workspace-header {
        display: flex;
        justify-content: space-between;
        gap: 1.25rem;
        align-items: flex-start;
        margin-bottom: 1.25rem;
        padding: 1.15rem 1.25rem;
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .dashboard-workspace-heading { flex: 1 1 auto; min-width: 0; }
    .dashboard-workspace-title-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.45rem; }
    .dashboard-workspace-title { margin: 0; font-size: 1.45rem; line-height: 1.2; font-weight: 800; color: #0f172a; }
    .dashboard-workspace-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.34rem 0.72rem;
        background: #eef4ff;
        color: var(--primary-color);
        font-size: 0.76rem;
        font-weight: 700;
    }
    .dashboard-workspace-copy { margin: 0.35rem 0 0; color: var(--text-muted); font-size: 0.92rem; line-height: 1.6; max-width: 760px; }
    .dashboard-breadcrumbs { margin-top: 0.65rem; }
    .dashboard-workspace-stats { display: flex; align-items: stretch; gap: 0.75rem; flex-wrap: wrap; justify-content: flex-end; min-width: min(100%, 430px); }
    .dashboard-workspace-stat { min-width: 112px; padding: 0.85rem 0.95rem; border-radius: 14px; background: #f8fafc; border: 1px solid #e2e8f0; }
    .dashboard-workspace-stat-label { display: block; font-size: 0.7rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.35rem; }
    .dashboard-workspace-stat-value { display: block; font-size: 1rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
    .dashboard-workspace-stat-meta { display: block; margin-top: 0.3rem; color: var(--text-muted); font-size: 0.76rem; }
    .dashboard-main-actions { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .dashboard-main-actions-left, .dashboard-main-actions-right { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .dashboard-primary-upload { min-width: 154px; justify-content: center; }
    .dashboard-upload-card, .dashboard-file-panel {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 6px 20px rgba(15, 23, 42, 0.04);
    }
    .dashboard-upload-card { padding: 1rem 1.1rem 1.15rem; margin-bottom: 1.25rem; }
    .dashboard-file-panel { padding: 1rem; }
    .dashboard-upload-card.is-collapsed {
        padding-bottom: 1rem;
    }
    .dashboard-upload-card-head, .dashboard-file-panel-head, .dashboard-filter-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .dashboard-upload-card-head { margin-bottom: 0.85rem; }
    .dashboard-file-panel-head { margin-bottom: 0.9rem; }
    .dashboard-upload-card-title, .dashboard-filter-title, .dashboard-file-panel-title { margin: 0; font-size: 0.98rem; font-weight: 800; color: #0f172a; }
    .dashboard-upload-card-copy, .dashboard-filter-copy, .dashboard-file-panel-copy { margin: 0.2rem 0 0; color: var(--text-muted); font-size: 0.82rem; line-height: 1.45; }
    .dashboard-upload-hint { display: inline-flex; align-items: center; padding: 0.35rem 0.6rem; border-radius: 999px; background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; font-size: 0.76rem; font-weight: 600; white-space: nowrap; }
    .dashboard-upload-head-tools { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-left: auto; }
    .dashboard-upload-toggle {
        border: 1px solid var(--border-color);
        border-radius: 999px;
        background: #fff;
        color: #475569;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.46rem 0.82rem;
        cursor: pointer;
    }
    .dashboard-drop-zone { margin-bottom: 0; padding: 1.55rem 1.5rem; min-height: 0; }
    .dashboard-drop-zone .dz-message p { font-size: 0.98rem; margin-bottom: 0.35rem; }
    .dashboard-drop-zone .dz-message small { display: block; font-size: 0.8rem; color: var(--text-muted); line-height: 1.45; }
    .dashboard-upload-card.is-collapsed .dashboard-drop-zone {
        display: none;
    }
    .dashboard-filter-card { border-radius: 16px; }
    .dashboard-filter-head { margin-bottom: 0.95rem; }
    .dashboard-filter-tools { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-left: auto; }
    .dashboard-filter-search { width: min(250px, 100%); flex: 1 1 210px; }
    .dashboard-filter-actions { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .dashboard-filter-clear {
        border: 1px solid var(--border-color);
        border-radius: 999px;
        background: #fff;
        color: #475569;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.48rem 0.85rem;
        cursor: pointer;
    }
    .dashboard-filter-reset {
        border: 1px solid #c7d2fe;
        border-radius: 999px;
        background: #eef2ff;
        color: #3730a3;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.48rem 0.85rem;
        cursor: pointer;
    }
    .dashboard-file-panel-meta { color: var(--text-muted); font-size: 0.8rem; font-weight: 600; }
    .trash-history-section {
        margin-top: 2rem;
        border-top: 1px solid #e5e7eb;
        padding-top: 1.6rem;
    }
    .trash-history-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
    .trash-history-title { margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--text-color); }
    .trash-history-copy { margin: 0.25rem 0 0; color: var(--text-muted); font-size: 0.875rem; max-width: 780px; line-height: 1.55; }
    .trash-history-controls {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 0.9rem;
    }
    .trash-history-results {
        color: var(--text-muted);
        font-size: 0.82rem;
        font-weight: 600;
    }
    .trash-history-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: #fff;
    }
    .trash-history-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
    }
    .trash-history-table th {
        background: #f8fafc;
        text-align: left;
        padding: 0.9rem 1rem;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
    }
    .trash-history-table th.nowrap {
        white-space: nowrap;
    }
    .trash-history-table td {
        padding: 0.95rem 1rem;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.875rem;
        vertical-align: top;
    }
    .trash-history-table tbody tr:last-child td { border-bottom: none; }
    .trash-history-file { font-weight: 700; color: var(--text-color); line-height: 1.45; overflow-wrap: anywhere; }
    .trash-history-meta { color: var(--text-muted); font-size: 0.8rem; line-height: 1.5; }
    .trash-history-reason { color: var(--text-color); line-height: 1.55; overflow-wrap: anywhere; }
    .trash-history-empty { border: 1px dashed var(--border-color); border-radius: 12px; padding: 1rem; color: var(--text-muted); font-size: 0.875rem; background: #f8fafc; }
    .trash-history-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 0.95rem;
    }
    .trash-history-page-meta {
        color: var(--text-muted);
        font-size: 0.82rem;
        font-weight: 600;
    }
    .trash-history-page-links {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
    }
    .trash-history-page-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2.25rem;
        height: 2.25rem;
        padding: 0 0.8rem;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        background: #fff;
        color: #334155;
        text-decoration: none;
        font-size: 0.82rem;
        font-weight: 700;
    }
    .trash-history-page-link.is-current {
        border-color: #c7d2fe;
        background: #eef2ff;
        color: #3730a3;
    }
    .trash-history-page-link.is-disabled {
        opacity: 0.45;
        pointer-events: none;
    }
    .bulk-links-modal { width: min(940px, calc(100vw - 2rem)) !important; max-width: min(940px, calc(100vw - 2rem)) !important; max-height: calc(100vh - 2rem); overflow-y: auto; overscroll-behavior: contain; margin: 1rem auto !important; }
    .bulk-links-summary { margin: 0.45rem 0 1rem; color: var(--text-muted); font-size: 0.92rem; line-height: 1.5; }
    .bulk-links-summary strong { color: var(--text-color); }
    .bulk-tools-grid { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.1rem; min-height: 400px; align-items: stretch; }
    .bulk-tools-tabs { display: flex; flex-direction: column; gap: 0.5rem; }
    .bulk-tools-tab { border: 1px solid var(--border-color); background: #fff; border-radius: 10px; padding: 0.75rem 0.9rem; text-align: left; cursor: pointer; color: var(--text-color); font-weight: 600; line-height: 1.35; transition: border-color .15s ease, background-color .15s ease, color .15s ease, box-shadow .15s ease; }
    .bulk-tools-tab small { display: block; margin-top: 0.2rem; color: var(--text-muted); font-size: 0.76rem; font-weight: 500; }
    .bulk-tools-tab.is-active { border-color: var(--primary-color); color: var(--primary-color); background: #eff6ff; box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.08); }
    .bulk-tools-panel { display: flex; flex-direction: column; min-width: 0; min-height: 0; border: 1px solid var(--border-color); border-radius: 12px; background: #fbfdff; padding: 0.85rem; }
    .bulk-tools-panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 0.65rem; }
    .bulk-tools-panel-title { margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-color); }
    .bulk-tools-panel-copy { margin: 0.15rem 0 0; font-size: 0.82rem; color: var(--text-muted); }
    .bulk-tools-format-badge { display: inline-flex; align-items: center; justify-content: center; min-height: 32px; padding: 0.35rem 0.65rem; border-radius: 999px; background: #eff6ff; color: var(--primary-color); font-size: 0.78rem; font-weight: 700; white-space: nowrap; }
    .bulk-output { min-height: 320px; width: 100%; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.82rem; line-height: 1.5; background: #fff; overflow: auto; }
    .bulk-tools-actions { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-top: 0.85rem; flex-wrap: wrap; }
    .bulk-tools-actions-note { color: var(--text-muted); font-size: 0.78rem; }
    .bulk-tools-actions-group { display: flex; justify-content: flex-end; gap: 0.5rem; flex-wrap: wrap; }
    #massRenameModal { overflow-y: auto; padding: 1rem 0; box-sizing: border-box; }
    .mass-rename-modal { width: min(680px, calc(100vw - 2rem)) !important; max-width: min(680px, calc(100vw - 2rem)) !important; max-height: calc(100dvh - 2rem); overscroll-behavior: contain; margin: 0 auto !important; display: flex; flex-direction: column; overflow: hidden; box-sizing: border-box; }
    .mass-rename-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; padding-right: 0.2rem; }
    .mass-rename-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.8rem; }
    .mass-rename-grid .form-group--full { grid-column: 1 / -1; }
    .mass-rename-preview { max-height: 240px; overflow: auto; margin-top: 1rem; border: 1px solid var(--border-color); border-radius: 8px; }
    .mass-rename-preview table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .mass-rename-preview th, .mass-rename-preview td { padding: 0.55rem 0.65rem; border-bottom: 1px solid var(--border-color); text-align: left; }
    .mass-rename-preview th { background: #f8fafc; color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase; }
    .mass-rename-preview td { overflow-wrap: anywhere; }
    .mass-rename-modal .modal-footer { position: sticky; bottom: 0; background: linear-gradient(180deg, rgba(255,255,255,0.92) 0%, #ffffff 28%); padding-top: 0.85rem; margin-top: 1rem; flex: 0 0 auto; }
    @media (max-width: 900px) {
        .bulk-tools-grid, .mass-rename-grid { grid-template-columns: 1fr; }
        .bulk-tools-tabs { flex-direction: row; overflow-x: auto; }
        .bulk-tools-tab { white-space: nowrap; }
        .bulk-tools-tab small { white-space: normal; }
        .bulk-tools-panel-head, .bulk-tools-actions { flex-direction: column; align-items: stretch; }
        .bulk-tools-actions-group { justify-content: stretch; }
        .bulk-tools-actions-group .btn { width: 100%; }
        .dashboard-workspace-header,
        .dashboard-filter-head,
        .dashboard-main-actions,
        .dashboard-upload-card-head,
        .dashboard-file-panel-head { flex-direction: column; align-items: stretch; }
        .dashboard-workspace-stats,
        .dashboard-main-actions-right,
        .dashboard-filter-tools { justify-content: flex-start; min-width: 0; }
        .dashboard-filter-search { width: 100%; }
    }
</style>';
include __DIR__ . '/header.php';
include __DIR__ . '/partials/account_sidebar_styles.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$currentUserId = \App\Core\Auth::id() ?? 0;
$package = $currentUserId ? \App\Model\Package::getUserPackage($currentUserId) : \App\Model\Package::getGuestPackage();
$guestMode = !empty($guestMode);

$packageMaxUpload = !empty($package['max_upload_size']) ? (int) $package['max_upload_size'] : 0;
$effectiveUploadLimit = $packageMaxUpload;
$activeFileCount = is_array($files ?? null) ? count($files) : 0;
$activeFolderCount = is_array($folders ?? null) ? count($folders) : 0;
$openTicketCount = 0;
$recentUploadTimestamp = null;
if (!$guestMode && !empty($files)) {
    foreach ($files as $dashboardFile) {
        if (!empty($dashboardFile['created_at'])) {
            $createdAt = strtotime((string)$dashboardFile['created_at']);
            if ($createdAt && ($recentUploadTimestamp === null || $createdAt > $recentUploadTimestamp)) {
                $recentUploadTimestamp = $createdAt;
            }
        }
    }
}
$shouldCompactUploadArea = !$guestMode && empty($isTrash) && ($activeFileCount + $activeFolderCount) > 0;
$topUnreadNotificationCount = 0;
if ($currentUserId > 0 && !$guestMode) {
    try {
        $topUnreadNotificationCount = count(\App\Service\NotificationService::getUnread($currentUserId));
    } catch (\Throwable $e) {
        $topUnreadNotificationCount = 0;
    }

    try {
        $topTickets = \App\Service\TicketService::getUserTickets($currentUserId);
        foreach ($topTickets as $ticket) {
            if (($ticket['status'] ?? '') !== 'closed') {
                $openTicketCount++;
            }
        }
    } catch (\Throwable $e) {
        $openTicketCount = 0;
    }
}
$recentUploadLabel = $recentUploadTimestamp ? date('M j, Y', $recentUploadTimestamp) : 'No uploads yet';
$workspaceModeLabel = !empty($isTrash) ? 'Trash' : ($guestMode ? 'Guest Upload' : '');

$uploadLimitText = $effectiveUploadLimit > 0
    ? 'Maximum upload size: ' . formatBytes($effectiveUploadLimit, 1)
    : 'Maximum upload size: Unlimited for this plan';
?>

<div class="fm-container dashboard-shell<?= $guestMode ? ' guest-upload-shell' : '' ?>">
    <?php if (!$guestMode): ?>
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>
    <?php endif; ?>
    <div class="fm-main">
        <?php if ($guestMode): ?>
        <div class="guest-upload-intro">
            <div>
                <h2>Upload without an account</h2>
                <p>Your files will be uploaded using the current guest package limits. Create an account later if you want a personal dashboard, folders, or reward features.</p>
            </div>
            <div class="guest-upload-intro-meta">
                <span><?= htmlspecialchars($uploadLimitText) ?></span>
                <?php if (!empty($package['allow_remote_upload'])): ?>
                    <span>Remote URL upload available</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= \App\Core\Csrf::generate() ?>">
        <input type="hidden" id="currentFolderId" value="<?= $currentFolder ? $currentFolder['id'] : '' ?>">

        <section class="dashboard-workspace-header">
            <div class="dashboard-workspace-heading">
                <div class="dashboard-workspace-title-row">
                    <h2 class="dashboard-workspace-title"><?= htmlspecialchars($pageHeading ?? ($currentFolder ? $currentFolder['name'] : 'All Files')) ?></h2>
                    <?php if ($workspaceModeLabel !== ''): ?>
                        <span class="dashboard-workspace-badge"><?= htmlspecialchars($workspaceModeLabel) ?></span>
                    <?php endif; ?>
                </div>
                <p class="dashboard-workspace-copy">
                    <?php if (!empty($isTrash)): ?>
                        Review deleted items, restore what still matters, and clear old files only when you are sure they are no longer needed.
                    <?php elseif ($guestMode): ?>
                        Upload quickly without an account, or create one later if you want folders, history, and account-side tools.
                    <?php elseif ($currentFolder): ?>
                        Work inside this folder, upload new files, and keep the structure tidy without leaving the page.
                    <?php else: ?>
                        Upload, organize, and share your files from one workspace. Start with upload, then use filters once the list gets busy.
                    <?php endif; ?>
                </p>
                <div class="breadcrumbs dashboard-breadcrumbs" id="breadcrumbs">
                    <a href="/">Home</a>
                    <?php if (isset($breadcrumbPath) && is_array($breadcrumbPath)): ?>
                        <?php foreach ($breadcrumbPath as $crumb): ?>
                            <span class="crumb-sep">/</span>
                            <a href="<?= $crumb['url'] ?>"><?= htmlspecialchars($crumb['name']) ?></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($pageHeading) && !$currentFolder): ?>
                        <span class="crumb-sep">/</span>
                        <span><?= htmlspecialchars($pageHeading) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="dashboard-workspace-stats<?= $guestMode ? ' dashboard-hidden' : '' ?>">
                <div class="dashboard-workspace-stat">
                    <span class="dashboard-workspace-stat-label">Visible Files</span>
                    <span class="dashboard-workspace-stat-value"><?= number_format($activeFileCount) ?></span>
                    <span class="dashboard-workspace-stat-meta">In this view</span>
                </div>
                <div class="dashboard-workspace-stat">
                    <span class="dashboard-workspace-stat-label">Folders</span>
                    <span class="dashboard-workspace-stat-value"><?= number_format($activeFolderCount) ?></span>
                    <span class="dashboard-workspace-stat-meta">Ready to organize</span>
                </div>
                <div class="dashboard-workspace-stat">
                    <span class="dashboard-workspace-stat-label">Open Tickets</span>
                    <span class="dashboard-workspace-stat-value"><?= number_format($openTicketCount) ?></span>
                    <span class="dashboard-workspace-stat-meta"><?= $topUnreadNotificationCount > 0 ? number_format($topUnreadNotificationCount) . ' unread notifications' : 'Nothing waiting on you' ?></span>
                </div>
                <div class="dashboard-workspace-stat">
                    <span class="dashboard-workspace-stat-label">Latest Upload</span>
                    <span class="dashboard-workspace-stat-value"><?= htmlspecialchars($recentUploadLabel) ?></span>
                    <span class="dashboard-workspace-stat-meta"><?= $activeFileCount > 0 ? 'Most recent file added' : 'Upload to get started' ?></span>
                </div>
            </div>
        </section>

        <div class="dashboard-main-actions<?= $guestMode ? ' dashboard-hidden' : '' ?>">
            <div class="dashboard-main-actions-left">
                <?php if (!isset($isTrash)): ?>
                    <button class="btn btn-primary dashboard-primary-upload" id="uploadBtn">Upload Files</button>
                    <?php if (\App\Core\Auth::isAdmin() || !empty($package['allow_remote_upload'])): ?>
                        <button class="btn btn-white" id="remoteUploadBtn">Remote URL</button>
                    <?php endif; ?>
                    <button class="btn btn-white" id="newFolderBtn">New Folder</button>
                <?php elseif (\App\Model\Setting::get('user_can_empty_trash', '1') === '1'): ?>
                    <button class="btn btn-danger empty-trash-btn">Empty Trash Bin</button>
                <?php endif; ?>
            </div>
            <div class="dashboard-main-actions-right">
                <?php if ($currentFolder && !$guestMode): ?>
                    <button class="btn btn-white" data-nav-url="<?= $currentFolder['parent_id'] ? '/folder/' . $currentFolder['parent_id'] : '/' ?>">Up One Level</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!isset($isTrash)): ?>
        <section class="dashboard-upload-card<?= $shouldCompactUploadArea ? ' is-collapsed' : '' ?>" id="dashboardUploadCard">
            <div class="dashboard-upload-card-head">
                <div>
                    <h3 class="dashboard-upload-card-title">Upload area</h3>
                    <p class="dashboard-upload-card-copy">Use the main upload button for everyday work, or expand the drop area when you want the larger target.</p>
                </div>
                <div class="dashboard-upload-head-tools">
                    <span class="dashboard-upload-hint"><?= htmlspecialchars($uploadLimitText) ?></span>
                    <?php if ($shouldCompactUploadArea): ?>
                        <button type="button" class="dashboard-upload-toggle" id="toggleUploadAreaBtn">Show drag and drop area</button>
                    <?php endif; ?>
                </div>
            </div>
        <div class="drop-zone dashboard-drop-zone" id="dropZone">
            <div class="dz-message">
                <div class="dz-icon" aria-hidden="true">&#128228;</div>
                <p>Drag and drop files here or <span>browse</span></p>
                <small>Quick drop target for adding files without leaving the workspace.</small>
            </div>
            <input type="file" id="fileInput" multiple class="dashboard-hidden">
        </div>
        </section>
        <?php endif; ?>

        <div class="fm-filter-bar dashboard-filter-card<?= $guestMode ? ' dashboard-hidden' : '' ?>">
            <div class="dashboard-filter-head">
                <div>
                    <h3 class="dashboard-filter-title">Find and narrow items</h3>
                    <p class="dashboard-filter-copy">Search by name, trim the list by type or status, and change sorting without losing your place.</p>
                </div>
                <div class="dashboard-filter-tools">
                    <div class="search-box dashboard-search-box dashboard-filter-search">
                        <span class="search-icon" aria-hidden="true">&#128269;</span>
                        <input type="text" id="fmSearch" placeholder="Search files and folders..." class="dashboard-search-input">
                    </div>
                    <div class="dashboard-filter-actions">
                        <button type="button" class="dashboard-filter-clear" id="fmClearFiltersBtn">Clear filters</button>
                        <button type="button" class="dashboard-filter-reset" id="fmResetWorkspaceBtn">Reset workspace</button>
                    </div>
                </div>
            </div>
            <div class="fm-filter-group">
                <label class="fm-filter">
                    <span>Type</span>
                    <select id="fmTypeFilter">
                        <option value="all">All items</option>
                        <option value="folder">Folders</option>
                        <option value="image">Images</option>
                        <option value="video">Videos</option>
                        <option value="audio">Audio</option>
                        <option value="document">Documents</option>
                        <option value="archive">Archives</option>
                        <option value="other">Other files</option>
                    </select>
                </label>
                <label class="fm-filter">
                    <span>Visibility</span>
                    <select id="fmVisibilityFilter">
                        <option value="all">All visibility</option>
                        <option value="public">Public files</option>
                        <option value="private">Private files</option>
                    </select>
                </label>
                <label class="fm-filter">
                    <span>Status</span>
                    <select id="fmStatusFilter">
                        <option value="all">All statuses</option>
                        <option value="active">Active</option>
                        <option value="processing">Processing</option>
                        <option value="ready">Ready</option>
                    </select>
                </label>
                <label class="fm-filter">
                    <span>Sort</span>
                    <select id="fmSort">
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="name">Name A-Z</option>
                        <option value="name_desc">Name Z-A</option>
                        <option value="largest">Largest first</option>
                        <option value="smallest">Smallest first</option>
                        <option value="downloads">Downloads</option>
                        <option value="downloads_asc">Fewest downloads</option>
                        <option value="public">Public first</option>
                        <option value="private">Private first</option>
                    </select>
                </label>
            </div>
            <div class="fm-filter-summary">
                <div class="fm-filter-chips" id="fmFilterChips"></div>
                <div class="fm-filter-results" id="fmFilterResults">Showing all items</div>
            </div>
        </div>

        <section class="dashboard-file-panel">
            <div class="dashboard-file-panel-head">
                <div>
                    <h3 class="dashboard-file-panel-title"><?= !empty($isTrash) ? 'Deleted items' : 'Files and folders' ?></h3>
                    <p class="dashboard-file-panel-copy">
                        <?= !empty($isTrash)
                            ? 'Review what is waiting in trash before restoring or deleting it permanently.'
                            : 'Folders stay at the top so the structure is easier to scan before you drop into the files themselves.' ?>
                    </p>
                </div>
                <div class="dashboard-main-actions-right">
                    <div class="dashboard-file-panel-meta">
                        <?= number_format($activeFileCount + $activeFolderCount) ?> visible item<?= ($activeFileCount + $activeFolderCount) === 1 ? '' : 's' ?>
                    </div>
                    <button class="btn dashboard-view-toggle<?= $guestMode ? ' dashboard-hidden' : '' ?>" id="viewToggle" title="Toggle Grid/List">Grid View</button>
                </div>
            </div>
        <div class="file-grid" id="fileGrid">
            <div class="fm-list-header">
                <div class="fm-list-select"><input type="checkbox" id="listSelectAll" aria-label="Select all visible items"></div>
                <div class="fm-list-name"><button type="button" class="fm-list-sort-btn" data-list-sort="name" data-list-sort-alt="name_desc">Folder/File</button></div>
                <div class="fm-list-size"><button type="button" class="fm-list-sort-btn" data-list-sort="largest" data-list-sort-alt="smallest">Size</button></div>
                <div class="fm-list-upload"><button type="button" class="fm-list-sort-btn" data-list-sort="newest" data-list-sort-alt="oldest">Uploaded</button></div>
                <div class="fm-list-downloads"><button type="button" class="fm-list-sort-btn" data-list-sort="downloads" data-list-sort-alt="downloads_asc">DLs</button></div>
                <div class="fm-list-public"><button type="button" class="fm-list-sort-btn" data-list-sort="public" data-list-sort-alt="private">Public</button></div>
                <div class="fm-list-actions">Edit</div>
            </div>
            <?php if (empty($files) && empty($folders)): ?>
                <div class="empty-state">
                    <div class="empty-icon" aria-hidden="true">&#128194;</div>
                    <p>
                        <?php if (!empty($isTrash)): ?>
                            Trash is empty.
                        <?php elseif ($guestMode): ?>
                            Choose a file above to start your guest upload.
                        <?php elseif (!empty($isShared)): ?>
                            No shared files yet.
                        <?php elseif (($pageHeading ?? '') === 'Recent Files'): ?>
                            No recent files yet.
                        <?php else: ?>
                            No files or folders here. Start by uploading something!
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($folders as $folder): ?>
                    <?php $folderId = $folder['id']; ?>
                    <div class="file-item folder-item"
                         data-id="<?= $folderId ?>"
                         data-kind="folder"
                         data-parent-id="<?= $folder['parent_id'] === null ? '' : (int)$folder['parent_id'] ?>"
                         data-status="<?= htmlspecialchars($folder['status'] ?? 'active') ?>"
                         data-size="<?= (int)($folder['total_size'] ?? 0) ?>"
                         data-downloads="0"
                         data-created-at="<?= htmlspecialchars($folder['created_at']) ?>"
                         draggable="true">
                        <div class="file-hover-controls">
                            <div class="file-select">
                                <input type="checkbox" class="item-checkbox" data-id="<?= $folderId ?>" data-type="folder">
                            </div>
                            <div class="file-options-trigger" data-id="<?= $folderId ?>" data-type="folder" data-name="<?= htmlspecialchars($folder['name']) ?>">
                                <span class="trigger-icon" aria-hidden="true">&#9662;</span>
                            </div>
                        </div>
                        <div class="file-preview">
                            <div class="file-icon" aria-hidden="true">&#128193;</div>
                        </div>
                        <div class="file-info" data-nav-url="/folder/<?= $folderId ?>">
                            <div class="file-name" title="<?= htmlspecialchars($folder['name']) ?>">
                                <?= htmlspecialchars($folder['name']) ?>
                                <span class="folder-count-badge"><?= (int)($folder['file_count'] ?? 0) ?></span>
                            </div>
                            <div class="file-meta">
                                <span class="file-stats">
                                    <?php
                                    $stats = [];
                                    if ($folder['total_size'] > 0) {
                                        $stats[] = \App\Service\FileProcessor::formatSize($folder['total_size'], 1);
                                    }
                                    if ($folder['file_count'] > 0) {
                                        $stats[] = $folder['file_count'] . ' ' . ($folder['file_count'] == 1 ? 'file' : 'files');
                                    }
                                    echo implode(' &middot; ', $stats) ?: 'Empty';
                                    ?>
                                </span>
                                <span class="file-date dashboard-date-hidden"><?= date('Y-m-d H:i:s', strtotime($folder['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="file-list-cell file-list-size"><?= !empty($folder['total_size']) ? \App\Service\FileProcessor::formatSize($folder['total_size'], 1) : '' ?></div>
                        <div class="file-list-cell file-list-upload"><?= date('Y-m-d', strtotime($folder['created_at'])) ?></div>
                        <div class="file-list-cell file-list-downloads"></div>
                        <div class="file-list-cell file-list-public"></div>
                        <div class="file-list-actions">
                            <button class="fm-row-action rename-item" type="button" title="Rename" aria-label="Rename folder">&#9998;</button>
                            <button class="fm-row-action fm-row-action-danger delete-folder" type="button" title="Delete" aria-label="Delete folder">&times;</button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($files as $file): ?>
                    <?php
                    $fileId = $file['id'];
                    $downloadCount = (int)($file['downloads'] ?? $file['download_count'] ?? 0);
                    ?>
                    <div class="file-item"
                         data-id="<?= $fileId ?>"
                         data-kind="file"
                         data-parent-id="<?= $file['folder_id'] === null ? '' : (int)$file['folder_id'] ?>"
                         data-status="<?= htmlspecialchars($file['status'] ?? 'active') ?>"
                         data-public="<?= !empty($file['is_public']) ? '1' : '0' ?>"
                         data-size="<?= (int)$file['file_size'] ?>"
                         data-downloads="<?= $downloadCount ?>"
                         data-mime="<?= htmlspecialchars($file['mime_type']) ?>"
                         data-short-id="<?= htmlspecialchars($file['short_id']) ?>"
                         data-created-at="<?= htmlspecialchars($file['created_at']) ?>"
                         draggable="true">
                        <div class="file-hover-controls">
                            <div class="file-select">
                                <input type="checkbox" class="item-checkbox" data-id="<?= $fileId ?>" data-type="file">
                            </div>
                            <div class="file-options-trigger" data-id="<?= $fileId ?>" data-type="file" data-name="<?= htmlspecialchars($file['filename']) ?>">
                                <span class="trigger-icon" aria-hidden="true">&#9662;</span>
                            </div>
                        </div>
                        <div class="file-preview" data-nav-url="/file/<?= $file['short_id'] ?>" data-nav-target="_blank">
                            <?php
                            $thumbUrl = null;
                            if (strpos($file['mime_type'], 'image/') === 0 || strpos($file['mime_type'], 'video/') === 0) {
                                $thumbPath = 'thumbnails/' . date('Y/m', strtotime($file['created_at'])) . '/' . $file['file_hash'] . '.jpg';
                                $provider = \App\Core\StorageManager::getProvider($file['storage_provider']);
                                $thumbUrl = $provider->getUrl($thumbPath);
                            }
                            ?>
                            <?php if ($thumbUrl): ?>
                                <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="thumb">
                            <?php else: ?>
                                <div class="file-icon"><?= getFileIcon($file['mime_type']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="file-info" data-nav-url="/file/<?= htmlspecialchars($file['short_id']) ?>" data-nav-target="_blank">
                            <div class="file-name" title="<?= htmlspecialchars($file['filename']) ?>">
                                <?= htmlspecialchars($file['filename']) ?>
                                <?php \App\Core\View::hook('after_file_name', ['file' => $file]); ?>
                            </div>
                            <div class="file-meta">
                                <span class="file-size-raw"><?= \App\Service\FileProcessor::formatSize($file['file_size']) ?></span>
                                <span class="file-date dashboard-date-hidden"><?= date('Y-m-d H:i:s', strtotime($file['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="file-list-cell file-list-size"><?= \App\Service\FileProcessor::formatSize($file['file_size']) ?></div>
                        <div class="file-list-cell file-list-upload"><?= date('Y-m-d', strtotime($file['created_at'])) ?></div>
                        <div class="file-list-cell file-list-downloads"><?= $downloadCount > 0 ? $downloadCount : '' ?></div>
                        <div class="file-list-cell file-list-public">
                            <button class="fm-switch-indicator fm-public-toggle <?= !empty($file['is_public']) ? 'is-on' : '' ?>"
                                    type="button"
                                    data-visibility-toggle
                                    aria-label="<?= !empty($file['is_public']) ? 'Make private' : 'Make public' ?>"
                                    title="<?= !empty($file['is_public']) ? 'Public' : 'Private' ?>"></button>
                        </div>
                        <div class="file-list-actions">
                            <button class="fm-row-action rename-item" type="button" title="Rename" aria-label="Rename file">&#9998;</button>
                            <button class="fm-row-action fm-row-action-danger delete-file" type="button" title="Delete" aria-label="Delete file">&times;</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        </section>

        <?php if (!empty($isTrash)): ?>
        <?php $fileDeletionHistory = is_array($fileDeletionHistory ?? null) ? $fileDeletionHistory : []; ?>
        <?php $deletionHistoryScope = 'admin'; ?>
        <?php $deletionHistoryPage = max(1, (int)($deletionHistoryPage ?? 1)); ?>
        <?php $deletionHistoryPages = max(1, (int)($deletionHistoryPages ?? 1)); ?>
        <?php $deletionHistoryTotal = max(0, (int)($deletionHistoryTotal ?? count($fileDeletionHistory))); ?>
        <?php
        $buildDeletionHistoryUrl = static function (int $page): string {
            $page = max(1, $page);
            $params = [];
            if ($page > 1) {
                $params['deletion_page'] = $page;
            }
            return '/trash' . (!empty($params) ? ('?' . http_build_query($params)) : '');
        };
        $historyRangeStart = $deletionHistoryTotal > 0 ? (($deletionHistoryPage - 1) * (int)($deletionHistoryPerPage ?? 20)) + 1 : 0;
        $historyRangeEnd = $deletionHistoryTotal > 0 ? min($deletionHistoryTotal, $historyRangeStart + count($fileDeletionHistory) - 1) : 0;
        ?>
        <section class="trash-history-section" aria-labelledby="trashHistoryTitle">
            <div class="trash-history-header">
                <div>
                    <h3 class="trash-history-title" id="trashHistoryTitle">Admin Removed File History</h3>
                    <p class="trash-history-copy">Trash above shows items you can still restore. This history is only for files permanently removed by staff actions such as abuse review, DMCA handling, or moderation.</p>
                </div>
            </div>
            <div class="trash-history-controls">
                <div class="trash-history-results">
                    <?php if ($deletionHistoryTotal > 0): ?>
                        Showing <?= number_format($historyRangeStart) ?>-<?= number_format($historyRangeEnd) ?> of <?= number_format($deletionHistoryTotal) ?>
                    <?php else: ?>
                        No deleted history in this view
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($fileDeletionHistory)): ?>
                <div class="trash-history-table-wrap">
                    <table class="trash-history-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Removed</th>
                                <th class="nowrap">Removed by</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                    <?php foreach ($fileDeletionHistory as $entry): ?>
                        <?php
                        $actorLabel = trim((string)($entry['deleted_by_label'] ?? ''));
                        if ($actorLabel === '') {
                            $deletedByRole = strtolower(trim((string)($entry['deleted_by_role'] ?? 'system')));
                            $actorLabel = $deletedByRole === 'admin' ? 'Administrator' : ($deletedByRole === 'user' ? 'You' : 'System');
                        }
                        $reason = trim((string)($entry['delete_reason'] ?? ''));
                        $deletedAt = !empty($entry['deleted_at']) ? strtotime((string)$entry['deleted_at']) : false;
                        ?>
                            <tr>
                                <td>
                                    <div class="trash-history-file" title="<?= htmlspecialchars((string)($entry['original_filename'] ?? 'Deleted file')) ?>">
                                        <?= htmlspecialchars((string)($entry['original_filename'] ?? 'Deleted file')) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="trash-history-meta"><?= htmlspecialchars($deletedAt ? date('M d, Y H:i', $deletedAt) : 'Unknown date') ?></div>
                                </td>
                                <td>
                                    <div class="trash-history-meta"><?= htmlspecialchars($actorLabel) ?></div>
                                </td>
                                <td>
                                    <div class="trash-history-reason">
                                        <?= $reason !== '' ? htmlspecialchars($reason) : 'No reason was recorded for this deletion.' ?>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($deletionHistoryPages > 1): ?>
                    <div class="trash-history-pagination">
                        <div class="trash-history-page-meta">Page <?= number_format($deletionHistoryPage) ?> of <?= number_format($deletionHistoryPages) ?></div>
                        <div class="trash-history-page-links">
                            <a href="<?= htmlspecialchars($buildDeletionHistoryUrl(1)) ?>" class="trash-history-page-link<?= $deletionHistoryPage <= 1 ? ' is-disabled' : '' ?>">First</a>
                            <a href="<?= htmlspecialchars($buildDeletionHistoryUrl(max(1, $deletionHistoryPage - 1))) ?>" class="trash-history-page-link<?= $deletionHistoryPage <= 1 ? ' is-disabled' : '' ?>">Previous</a>
                            <span class="trash-history-page-link is-current"><?= number_format($deletionHistoryPage) ?></span>
                            <a href="<?= htmlspecialchars($buildDeletionHistoryUrl(min($deletionHistoryPages, $deletionHistoryPage + 1))) ?>" class="trash-history-page-link<?= $deletionHistoryPage >= $deletionHistoryPages ? ' is-disabled' : '' ?>">Next</a>
                            <a href="<?= htmlspecialchars($buildDeletionHistoryUrl($deletionHistoryPages)) ?>" class="trash-history-page-link<?= $deletionHistoryPage >= $deletionHistoryPages ? ' is-disabled' : '' ?>">Last</a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="trash-history-empty">No permanently deleted file history has been recorded yet.</div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <div id="selectionToolbar" class="selection-toolbar">
            <div class="selection-count"><span id="selectedCount">0</span> items selected</div>
            <div class="selection-actions">
                <button class="btn btn-sm btn-white" id="bulkDownloadBtn">Download Selected</button>
                <button class="btn btn-sm btn-white" id="bulkLinksBtn">Links</button>
                <button class="btn btn-sm btn-white" id="bulkMoveBtn">Move</button>
                <button class="btn btn-sm btn-white" id="bulkCopyBtn">Copy</button>
                <button class="btn btn-sm btn-white" id="massRenameBtn">Rename</button>
                <button class="btn btn-sm btn-white" id="bulkMakePublicBtn">Make Public</button>
                <button class="btn btn-sm btn-white" id="bulkMakePrivateBtn">Make Private</button>
                <button class="btn btn-sm btn-white" id="bulkTrashBtn">Move to Trash</button>
                <button class="btn btn-sm btn-danger" id="bulkDeleteBtn">Delete Permanently</button>
                <button class="btn btn-sm btn-white" id="selectAllBtn">Select All</button>
                <button class="btn btn-sm btn-white" id="clearSelectionBtn">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="itemDropdown" class="context-menu item-dropdown dashboard-menu-hidden">
    <ul>
        <li id="dropDownload"><span class="icon" aria-hidden="true">&#11015;</span> Download</li>
        <li id="dropShare"><span class="icon" aria-hidden="true">&#128279;</span> Share</li>
        <li id="dropRename"><span class="icon" aria-hidden="true">&#9998;</span> Rename</li>
        <li id="dropMove"><span class="icon" aria-hidden="true">&#8644;</span> Move</li>
        <li id="dropCopy"><span class="icon" aria-hidden="true">&#128203;</span> Create Copy</li>
        <li class="separator"></li>
        <li id="dropTrash" class="text-danger"><span class="icon" aria-hidden="true">&#128465;</span> Move to Trash</li>
    </ul>
</div>

<div id="sidebarContextMenu" class="context-menu dashboard-menu-hidden">
    <ul>
        <li id="ctxEmptyTrash" class="text-danger"><span class="icon" aria-hidden="true">&#128465;</span> Empty Trash</li>
    </ul>
</div>

<div class="progress-container" id="progressContainer">
    <div class="progress-info">
        <div class="progress-heading">
            <span id="progressText" class="progress-title">Uploads idle</span>
            <span id="progressPercent" class="progress-subtitle">0%</span>
        </div>
        <button id="cancelUploadBtn" class="btn btn-sm btn-danger upload-cancel-all">Cancel All</button>
    </div>
    <div class="progress-bar">
        <div class="progress-fill" id="progressFill"></div>
    </div>
    <div class="upload-queue-summary" id="uploadQueueSummary">
        <span id="uploadQueueStats">No active uploads</span>
    </div>
    <div class="upload-queue" id="uploadQueueList" aria-live="polite"></div>
</div>

<div id="bulkLinksModal" class="modal">
    <div class="modal-content share-modal-content bulk-links-modal">
        <h3>Bulk Links</h3>
        <p id="bulkLinksDescription">Generate links for the selected public files and folders.</p>
        <p class="bulk-links-summary" id="bulkLinksSummary">
            You are exporting links for <strong id="bulkLinksSelectionCount">0 items</strong>.
        </p>
        <div class="bulk-tools-grid">
            <div class="bulk-tools-tabs">
                <button type="button" class="bulk-tools-tab is-active" data-link-format="plain">Plain links<small>Files only, one URL per line</small></button>
                <button type="button" class="bulk-tools-tab" data-link-format="page">Download page links<small>Public file or folder pages</small></button>
                <button type="button" class="bulk-tools-tab" data-link-format="html">HTML links<small>Ready for websites and blogs</small></button>
                <button type="button" class="bulk-tools-tab" data-link-format="bbcode">BBCode links<small>Ready for forums</small></button>
                <button type="button" class="bulk-tools-tab" data-link-format="thumbs">Image thumbnails<small>Embed code for image files</small></button>
                <button type="button" class="bulk-tools-tab" data-link-format="grouped">Grouped by folder<small>Organized by parent folder</small></button>
            </div>
            <div class="bulk-tools-panel">
                <div class="bulk-tools-panel-head">
                    <div>
                        <p class="bulk-tools-panel-title">Generated output</p>
                        <p class="bulk-tools-panel-copy">Review the result below before copying or exporting it.</p>
                    </div>
                    <span class="bulk-tools-format-badge" id="bulkLinksFormatLabel">Plain links</span>
                </div>
                <textarea id="bulkLinksOutput" class="form-control bulk-output" readonly></textarea>
                <div class="bulk-tools-actions">
                    <div class="bulk-tools-actions-note" id="bulkLinksActionsNote">Copy everything or export the current format as a text file.</div>
                    <div class="bulk-tools-actions-group">
                        <button class="btn btn-white" type="button" id="copyBulkLinksBtn">Copy All</button>
                        <button class="btn btn-white" type="button" id="exportBulkLinksBtn">Export .txt</button>
                        <button class="btn" type="button" id="closeBulkLinksBtn">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="massRenameModal" class="modal">
    <div class="modal-content share-modal-content mass-rename-modal">
        <h3>Mass Rename</h3>
        <p>Preview filename changes before applying them to the selected items.</p>
        <div class="mass-rename-scroll">
            <div class="mass-rename-grid">
                <label class="form-group">
                    <span class="form-label">Find</span>
                    <input type="text" class="form-control" id="renameFind">
                </label>
                <label class="form-group">
                    <span class="form-label">Replace</span>
                    <input type="text" class="form-control" id="renameReplace">
                </label>
                <label class="form-group">
                    <span class="form-label">Add prefix</span>
                    <input type="text" class="form-control" id="renamePrefix">
                </label>
                <label class="form-group">
                    <span class="form-label">Add suffix</span>
                    <input type="text" class="form-control" id="renameSuffix">
                </label>
                <label class="form-group">
                    <span class="form-label">Remove text</span>
                    <input type="text" class="form-control" id="renameRemove">
                </label>
                <label class="form-group">
                    <span class="form-label">Convert separators</span>
                    <select class="form-control" id="renameSeparator">
                        <option value="">No conversion</option>
                        <option value="spaces">Dots/underscores to spaces</option>
                        <option value="dots">Spaces/underscores to dots</option>
                        <option value="underscores">Spaces/dots to underscores</option>
                    </select>
                </label>
                <label class="form-group">
                    <span class="form-label">Sequential numbering</span>
                    <select class="form-control" id="renameSequence">
                        <option value="">Off</option>
                        <option value="prefix">Number prefix</option>
                        <option value="suffix">Number suffix</option>
                    </select>
                </label>
                <label class="form-group">
                    <span class="form-label">Start number</span>
                    <input type="number" class="form-control" id="renameStart" value="1" min="0">
                </label>
                <label class="form-group form-group--full">
                    <span class="form-label">
                        <input type="checkbox" id="renameRegex" <?= \App\Core\Auth::isAdmin() ? '' : 'disabled' ?>> Regex-lite find/replace<?= \App\Core\Auth::isAdmin() ? '' : ' (admin only)' ?>
                    </span>
                </label>
            </div>
            <div class="mass-rename-preview" id="massRenamePreview"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" id="closeMassRenameBtn" type="button">Close</button>
            <button class="btn btn-white" id="previewMassRenameBtn" type="button">Preview</button>
            <button class="btn btn-primary" id="applyMassRenameBtn" type="button" disabled>Apply</button>
        </div>
    </div>
</div>

<div id="shareModal" class="modal">
    <div class="modal-content share-modal-content">
        <h3>Share File</h3>
        <p id="shareModalDescription">Prepare a shareable link for this file.</p>
        <div class="share-modal-body">
            <label class="share-field">
                <span>Public page link</span>
                <div class="share-input-row">
                    <input type="text" id="sharePageUrl" readonly>
                    <button class="btn btn-primary" id="copySharePageBtn" type="button">Copy</button>
                </div>
                <small id="sharePageHint" class="share-field-hint"></small>
            </label>
            <label class="share-field">
                <span>Direct download link</span>
                <div class="share-input-row">
                    <input type="text" id="shareDownloadUrl" readonly>
                    <button class="btn btn-white" id="copyShareDownloadBtn" type="button">Copy</button>
                </div>
            </label>
            <div class="share-meta" id="shareMeta"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" id="closeShareModalBtn" type="button">Close</button>
        </div>
    </div>
</div>

<div id="mobileActionSheet" class="modal">
    <div class="modal-content mobile-action-sheet">
        <div class="mobile-action-sheet-header">
            <h3 id="mobileActionTitle">Item actions</h3>
            <button class="btn btn-white" id="closeMobileActionSheetBtn" type="button">Close</button>
        </div>
        <div class="mobile-action-list">
            <button class="btn btn-white mobile-action-btn" data-action="download" id="mobileActionDownload" type="button">Download</button>
            <button class="btn btn-white mobile-action-btn" data-action="share" id="mobileActionShare" type="button">Share</button>
            <button class="btn btn-white mobile-action-btn" data-action="rename" id="mobileActionRename" type="button">Rename</button>
            <button class="btn btn-white mobile-action-btn" data-action="move" id="mobileActionMove" type="button">Move</button>
            <button class="btn btn-white mobile-action-btn" data-action="copy" id="mobileActionCopy" type="button">Create Copy</button>
            <button class="btn btn-danger mobile-action-btn" data-action="trash" id="mobileActionTrash" type="button">Move to Trash</button>
        </div>
    </div>
</div>

<div id="toastStack" class="toast-stack" aria-live="polite"></div>

<div id="moveModal" class="modal">
    <div class="modal-content">
        <h3>Move Items</h3>
        <p id="moveModalDescription">Select destination folder:</p>
        <div id="folderTree" class="folder-tree">
            <div class="folder-tree-item" data-id="root">
                &#128193; Home (Root)
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" id="cancelMoveBtn">Cancel</button>
            <button class="btn btn-primary" id="confirmMoveBtn">Move Here</button>
        </div>
    </div>
</div>

<?php
function getFileIcon($mime)
{
    if (strpos($mime, 'image/') === 0) {
        return '&#128247;';
    }
    if (strpos($mime, 'video/') === 0) {
        return '&#127909;';
    }
    if (strpos($mime, 'audio/') === 0) {
        return '&#127925;';
    }
    if (strpos($mime, 'application/pdf') === 0) {
        return '&#128462;';
    }
    if (strpos($mime, 'zip') !== false || strpos($mime, 'rar') !== false) {
        return '&#128230;';
    }
    return '&#128196;';
}

function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>

<?php
$uploadConfig = [
    'concurrent' => \App\Model\Setting::get('upload_concurrent', '0') === '1',
    'concurrentLimit' => (int) \App\Model\Setting::get('upload_concurrent_limit', '2'),
    'hidePopup' => \App\Model\Setting::get('upload_hide_popup', '0') === '1',
    'chunkingEnabled' => \App\Model\Setting::get('upload_chunking_enabled', '1') === '1',
    // Two parallel part uploads is a safer default for S3-compatible providers on shared-hosting installs.
    'partConcurrency' => 2,
    'maxPartRetries' => 3,
];

if ($guestMode) {
    $extraHead .= '
<style>
    .guest-upload-shell {
        max-width: 980px;
        margin-left: auto;
        margin-right: auto;
    }
    .guest-upload-intro {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        margin-bottom: 1.25rem;
        padding: 1.25rem 1.5rem;
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
    }
    .guest-upload-intro h2 {
        margin: 0 0 0.35rem;
    }
    .guest-upload-intro p {
        margin: 0;
        color: var(--text-muted);
        max-width: 640px;
    }
    .guest-upload-intro-meta {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.5rem;
    }
    .guest-upload-intro-meta span {
        white-space: nowrap;
        padding: 0.4rem 0.8rem;
        border-radius: 999px;
        background: #eff6ff;
        color: var(--primary-color);
        font-size: 0.85rem;
        font-weight: 600;
    }
    @media (max-width: 768px) {
        .guest-upload-intro {
            flex-direction: column;
        }
        .guest-upload-intro-meta {
            justify-content: flex-start;
        }
    }
</style>';
}

$extraBottom = "
<script>window.UPLOAD_CONFIG = " . json_encode($uploadConfig) . ";</script>
<script>window.FILE_MANAGER_CONFIG = " . json_encode([
    'baseUrl' => rtrim(\App\Service\SeoService::trustedBaseUrl(), '/'),
    'isAdmin' => \App\Core\Auth::isAdmin(),
    'isTrash' => !empty($isTrash),
    'guestMode' => $guestMode,
]) . ";</script>
<script src=\"/assets/js/filemanager.js?v=" . filemtime(BASE_PATH . '/public/assets/js/filemanager.js') . "\"></script>
";
include __DIR__ . '/footer.php';
?>
