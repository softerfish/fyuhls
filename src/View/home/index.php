<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = $pageTitle ?? "Dashboard - {$siteName}";
$extraHead = '<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">';
include __DIR__ . '/header.php';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$currentUserId = \App\Core\Auth::id() ?? 0;
$package = $currentUserId ? \App\Model\Package::getUserPackage($currentUserId) : \App\Model\Package::getGuestPackage();
$guestMode = !empty($guestMode);

$packageMaxUpload = !empty($package['max_upload_size']) ? (int) $package['max_upload_size'] : 0;
$effectiveUploadLimit = $packageMaxUpload;

$uploadLimitText = $effectiveUploadLimit > 0
    ? 'Maximum upload size: ' . formatBytes($effectiveUploadLimit, 1)
    : 'Maximum upload size depends on your account and storage policy';
?>

<div class="fm-container<?= $guestMode ? ' guest-upload-shell' : '' ?>" style="margin-top: 1rem;">
    <?php if (!$guestMode): ?>
    <div class="fm-sidebar">
        <div class="sidebar-section">
            <div style="text-align: center; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color);">
                <?php
                $userId = $currentUserId;
                $pkgNameStr = 'Free Plan';
                $expiryStr = 'Lifetime Free Account';
                $userPkg = null;

                if ($userId) {
                    if (\App\Core\Auth::isAdmin()) {
                        $pkgNameStr = 'Admin';
                    } else {
                        $userPkg = \App\Model\Package::getUserPackage($userId);
                        if ($userPkg) {
                            $pkgNameStr = $userPkg['name'] ?? 'Free Plan';
                            if (!empty($userPkg['premium_expiry'])) {
                                $expiryStr = 'Renews on ' . date('M d, Y', strtotime($userPkg['premium_expiry']));
                            }
                        }
                    }
                }
                ?>
                <?php $isPaidPlan = (\App\Core\Auth::isAdmin() || strtolower((string)($userPkg['level_type'] ?? 'free')) === 'paid'); ?>
                <div style="margin-bottom: 0.25rem; font-size: 0.875rem; color: var(--text-color); font-weight: 600;">
                    Current Plan: <span style="color: var(--primary-color);"><?= htmlspecialchars($pkgNameStr) ?></span>
                </div>
                <div style="margin-bottom: <?= $isPaidPlan ? '0.5rem' : '1.25rem' ?>; font-size: 0.75rem; color: var(--text-muted);">
                    <?= htmlspecialchars($expiryStr) ?>
                </div>
                <?php if (!$isPaidPlan): ?>
                    <button class="btn btn-warning" onclick="location.href='/#pricing'" style="width: auto; padding: 0.5rem 1.5rem;">View Plans</button>
                <?php endif; ?>
            </div>
            <h3 style="margin-top: 0;">Account</h3>
            <!-- Antigravity-Sync-Check-1.0 -->
            <ul style="list-style: none; padding: 0.5rem 0; margin: 0;">
                <li onclick="location.href='/'" class="<?= (!isset($isTrash) || !$isTrash) && !str_contains($requestUri, '/settings') && !str_contains($requestUri, '/rewards') && !str_contains($requestUri, '/affiliate') && !str_contains($requestUri, '/recent') && !str_contains($requestUri, '/shared') ? 'active' : '' ?>">All Files</li>
                <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                    <li onclick="location.href='/rewards'" class="<?= str_contains($requestUri, '/rewards') ? 'active' : '' ?>">My Rewards</li>
                    <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                        <li onclick="location.href='/affiliate'" class="<?= str_contains($requestUri, '/affiliate') ? 'active' : '' ?>">Affiliate</li>
                    <?php endif; ?>
                <?php endif; ?>
                <li onclick="location.href='/settings'" class="<?= str_contains($requestUri, '/settings') ? 'active' : '' ?>">Settings</li>
                <li onclick="location.href='/recent'" class="<?= str_contains($requestUri, '/recent') ? 'active' : '' ?>">Recent</li>
                <li onclick="location.href='/shared'" class="<?= str_contains($requestUri, '/shared') ? 'active' : '' ?>">Shared</li>
                <li class="<?= (isset($isTrash) && $isTrash) ? 'active' : '' ?> sidebar-trash-item" style="padding: 0; display: flex; justify-content: space-between; align-items: center; min-height: 40px;">
                    <span onclick="location.href='/trash'" style="flex: 1; padding: 0.6rem 0.75rem; display: block;">Trash</span>
                </li>
            </ul>
        </div>
    </div>
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
        <div class="fm-toolbar">
            <input type="hidden" name="csrf_token" value="<?= \App\Core\Csrf::generate() ?>">
            <input type="hidden" id="currentFolderId" value="<?= $currentFolder ? $currentFolder['id'] : '' ?>">

            <div class="toolbar-left">
                <h2 class="folder-title"><?= htmlspecialchars($pageHeading ?? ($currentFolder ? $currentFolder['name'] : 'All Files')) ?></h2>
                <div class="breadcrumbs" id="breadcrumbs">
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

            <div class="toolbar-right"<?= $guestMode ? ' style="display:none;"' : '' ?>>
                <div class="toolbar-controls" style="display: flex !important; align-items: center !important; gap: 12px !important; flex-wrap: nowrap !important; width: auto !important; min-width: 280px !important; justify-content: flex-end !important; position: relative !important; z-index: 10 !important;">
                    <div class="search-box" style="width: 180px !important; flex-shrink: 0 !important; position: relative !important;">
                        <span class="search-icon" aria-hidden="true">&#128269;</span>
                        <input type="text" id="fmSearch" placeholder="Search files..." style="width: 100% !important; box-sizing: border-box !important;">
                    </div>
                    <button class="btn" id="viewToggle" title="Toggle Grid/List" style="width: 80px !important; height: 38px !important; display: flex !important; align-items: center !important; justify-content: center !important; flex-shrink: 0 !important; background: #f1f5f9 !important; border: 1px solid #cbd5e1 !important; border-radius: 8px !important; font-size: 0.8rem !important; cursor: pointer !important; position: relative !important; z-index: 20 !important;">Grid</button>
                </div>
                <?php if ($currentFolder): ?>
                    <button class="btn btn-white" onclick="location.href='<?= $currentFolder['parent_id'] ? '/folder/' . $currentFolder['parent_id'] : '/' ?>'">Up One Level</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="upload-actions-top">
            <?php if (!isset($isTrash)): ?>
                <?php if (!$guestMode && (\App\Core\Auth::isAdmin() || !empty($package['allow_remote_upload']))): ?>
                    <button class="btn btn-primary" id="remoteUploadBtn">Remote URL Upload</button>
                <?php endif; ?>
                <?php if (!$guestMode): ?>
                    <button class="btn btn-primary" id="newFolderBtn">New Folder</button>
                <?php endif; ?>
            <?php else: ?>
                <?php if (\App\Model\Setting::get('user_can_empty_trash', '1') === '1'): ?>
                    <button class="btn btn-danger empty-trash-btn">Empty Trash Bin</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (!isset($isTrash)): ?>
        <div class="drop-zone" id="dropZone">
            <div class="dz-message">
                <div class="dz-icon" aria-hidden="true">&#128228;</div>
                <p>Drag & Drop files here or <span>browse</span></p>
                <small><?= htmlspecialchars($uploadLimitText) ?></small>
            </div>
            <input type="file" id="fileInput" multiple style="display: none;">
        </div>
        <?php endif; ?>

        <div class="fm-filter-bar"<?= $guestMode ? ' style="display:none;"' : '' ?>>
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
                        <option value="largest">Largest first</option>
                    </select>
                </label>
            </div>
            <div class="fm-filter-summary">
                <div class="fm-filter-chips" id="fmFilterChips"></div>
                <div class="fm-filter-results" id="fmFilterResults">Showing all items</div>
            </div>
        </div>

        <div class="file-grid" id="fileGrid">
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
                        <div class="file-info">
                            <div class="file-name" title="<?= htmlspecialchars($folder['name']) ?>">
                                <?= htmlspecialchars($folder['name']) ?>
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
                                <span class="file-date" style="display:none;"><?= date('Y-m-d H:i:s', strtotime($folder['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($files as $file): ?>
                    <?php $fileId = $file['id']; ?>
                    <div class="file-item"
                         data-id="<?= $fileId ?>"
                         data-kind="file"
                         data-parent-id="<?= $file['folder_id'] === null ? '' : (int)$file['folder_id'] ?>"
                         data-status="<?= htmlspecialchars($file['status'] ?? 'active') ?>"
                         data-public="<?= !empty($file['is_public']) ? '1' : '0' ?>"
                         data-size="<?= (int)$file['file_size'] ?>"
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
                        <div class="file-preview" onclick="window.open('/file/<?= $file['short_id'] ?>', '_blank')">
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
                        <div class="file-info">
                            <div class="file-name" title="<?= htmlspecialchars($file['filename']) ?>">
                                <?= htmlspecialchars($file['filename']) ?>
                                <?php \App\Core\View::hook('after_file_name', ['file' => $file]); ?>
                            </div>
                            <div class="file-meta">
                                <span class="file-size-raw"><?= \App\Service\FileProcessor::formatSize($file['file_size']) ?></span>
                                <span class="file-date" style="display:none;"><?= date('Y-m-d H:i:s', strtotime($file['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="selectionToolbar" class="selection-toolbar">
            <div class="selection-count"><span id="selectedCount">0</span> items selected</div>
            <div class="selection-actions">
                <button class="btn btn-sm btn-white" id="bulkDownloadBtn">Download Selected</button>
                <button class="btn btn-sm btn-white" id="bulkMoveBtn">Move</button>
                <button class="btn btn-sm btn-white" id="bulkTrashBtn">Move to Trash</button>
                <button class="btn btn-sm btn-danger" id="bulkDeleteBtn">Delete Permanently</button>
                <button class="btn btn-sm btn-white" id="clearSelectionBtn">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="itemDropdown" class="context-menu item-dropdown" style="display:none;">
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

<div id="sidebarContextMenu" class="context-menu" style="display:none;">
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
        <p>Select destination folder:</p>
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
<script src=\"/assets/js/filemanager.js?v=" . time() . "\"></script>
";
include __DIR__ . '/footer.php';
?>
