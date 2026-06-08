document.addEventListener('DOMContentLoaded', () => {
    console.log('File Manager v6 Loaded (Advanced)');
    const fileManagerConfig = window.FILE_MANAGER_CONFIG || {};

    // 1. Core Elements
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const fileGrid = document.getElementById('fileGrid');
    const selectionToolbar = document.getElementById('selectionToolbar');
    const selectedCountSpan = document.getElementById('selectedCount');
    const fmSearch = document.getElementById('fmSearch');
    const fmTypeFilter = document.getElementById('fmTypeFilter');
    const fmVisibilityFilter = document.getElementById('fmVisibilityFilter');
    const fmStatusFilter = document.getElementById('fmStatusFilter');
    const fmSort = document.getElementById('fmSort');
    const fmFilterChips = document.getElementById('fmFilterChips');
    const fmFilterResults = document.getElementById('fmFilterResults');
    const fmClearFiltersBtn = document.getElementById('fmClearFiltersBtn');
    const fmResetWorkspaceBtn = document.getElementById('fmResetWorkspaceBtn');
    const viewToggle = document.getElementById('viewToggle');
    const dashboardUploadCard = document.getElementById('dashboardUploadCard');
    const toggleUploadAreaBtn = document.getElementById('toggleUploadAreaBtn');
    const dashboardFilterCard = document.getElementById('dashboardFilterCard');
    const toggleFilterCardBtn = document.getElementById('toggleFilterCardBtn');
    const listSelectAll = document.getElementById('listSelectAll');
    const listSortButtons = document.querySelectorAll('[data-list-sort]');
    const bulkMakePublicBtn = document.getElementById('bulkMakePublicBtn');
    const bulkMakePrivateBtn = document.getElementById('bulkMakePrivateBtn');
    const bulkDownloadBtn = document.getElementById('bulkDownloadBtn');
    const bulkMoveBtn = document.getElementById('bulkMoveBtn');
    const bulkCopyBtn = document.getElementById('bulkCopyBtn');
    const bulkTrashBtn = document.getElementById('bulkTrashBtn');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const clearSelectionBtn = document.getElementById('clearSelectionBtn');
    const bulkLinksBtn = document.getElementById('bulkLinksBtn');
    const bulkLinksModal = document.getElementById('bulkLinksModal');
    const bulkLinksOutput = document.getElementById('bulkLinksOutput');
    const bulkLinksSummary = document.getElementById('bulkLinksSummary');
    const bulkLinksSelectionCount = document.getElementById('bulkLinksSelectionCount');
    const bulkLinksFormatLabel = document.getElementById('bulkLinksFormatLabel');
    const bulkLinksActionsNote = document.getElementById('bulkLinksActionsNote');
    const closeBulkLinksBtn = document.getElementById('closeBulkLinksBtn');
    const copyBulkLinksBtn = document.getElementById('copyBulkLinksBtn');
    const exportBulkLinksBtn = document.getElementById('exportBulkLinksBtn');
    const massRenameBtn = document.getElementById('massRenameBtn');
    const massRenameModal = document.getElementById('massRenameModal');
    const massRenamePreview = document.getElementById('massRenamePreview');
    const previewMassRenameBtn = document.getElementById('previewMassRenameBtn');
    const applyMassRenameBtn = document.getElementById('applyMassRenameBtn');
    const closeMassRenameBtn = document.getElementById('closeMassRenameBtn');
    let csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
    const isTrashView = fileManagerConfig.isTrash === true;
    const isGuestMode = fileManagerConfig.guestMode === true;
    const replaceEnabled = fileManagerConfig.replaceEnabled === true;
    const liveUploadInsertEnabled = fileManagerConfig.liveUploadInsertEnabled === true;
    const folderIndexCache = new Map();
    let folderIndexLoaded = false;
    let pendingReplaceTarget = null;

    const originalFetch = window.fetch;
    window.fetch = async function(...args) {
        const response = await originalFetch.apply(this, args);
        // globally catch rotated CSRF tokens from any backend request so long-lived pages don't get stale Mismatches
        const newToken = response.headers.get('X-CSRF-Token');
        if (newToken) {
            csrfToken = newToken;
            document.querySelectorAll('input[name="csrf_token"]').forEach(el => el.value = newToken);
        }
        return response;
    };
    const progressContainer = document.getElementById('progressContainer');
    const progressFill = document.getElementById('progressFill');
    const progressPercent = document.getElementById('progressPercent');
    const progressText = document.getElementById('progressText');
    const uploadQueueList = document.getElementById('uploadQueueList');
    const uploadQueueStats = document.getElementById('uploadQueueStats');
    const uploadBtn = document.getElementById('uploadBtn');
    const workspaceHeading = document.querySelector('.dashboard-workspace-heading');
    const workspaceTitle = document.querySelector('.dashboard-workspace-title');
    const workspaceCopy = document.querySelector('.dashboard-workspace-copy');
    const breadcrumbs = document.getElementById('breadcrumbs');
    const filePanelMeta = document.querySelector('.dashboard-file-panel-meta');
    const mainActionsRight = document.querySelector('.dashboard-main-actions > .dashboard-main-actions-right');

    let selectedItems = []; // Array of {id: string, type: 'file'|'folder'}
    let selectionAnchor = null;
    let bulkLinksRenderToken = 0;
    const contextMenu = document.getElementById('contextMenu');
    const sidebarContextMenu = document.getElementById('sidebarContextMenu');
    const actionModal = document.getElementById('actionModal');
    const toastStack = document.getElementById('toastStack');
    const shareModal = document.getElementById('shareModal');
    const shareModalDescription = document.getElementById('shareModalDescription');
    const sharePageUrl = document.getElementById('sharePageUrl');
    const sharePageHint = document.getElementById('sharePageHint');
    const shareDownloadUrl = document.getElementById('shareDownloadUrl');
    const shareMeta = document.getElementById('shareMeta');
    const copySharePageBtn = document.getElementById('copySharePageBtn');
    const copyShareDownloadBtn = document.getElementById('copyShareDownloadBtn');
    const closeShareModalBtn = document.getElementById('closeShareModalBtn');
    const mobileActionSheet = document.getElementById('mobileActionSheet');
    const mobileActionTitle = document.getElementById('mobileActionTitle');
    const closeMobileActionSheetBtn = document.getElementById('closeMobileActionSheetBtn');

    function getPageStateKey() {
        return 'fm_state:' + window.location.pathname;
    }

    function savePageState(extra = {}) {
        const state = {
            search: fmSearch?.value || '',
            typeFilter: fmTypeFilter?.value || 'all',
            visibilityFilter: fmVisibilityFilter?.value || 'all',
            statusFilter: fmStatusFilter?.value || 'all',
            sort: fmSort?.value || 'newest',
            filterCardCollapsed: dashboardFilterCard ? dashboardFilterCard.classList.contains('is-collapsed') : true,
            scrollY: window.scrollY,
            selectedItems,
            ...extra,
        };

        sessionStorage.setItem(getPageStateKey(), JSON.stringify(state));
    }

    function restorePageState() {
        const raw = sessionStorage.getItem(getPageStateKey());
        if (!raw) return;

        sessionStorage.removeItem(getPageStateKey());

        try {
            const state = JSON.parse(raw);
            if (fmSearch) fmSearch.value = state.search || '';
            if (fmTypeFilter) fmTypeFilter.value = state.typeFilter || 'all';
            if (fmVisibilityFilter) fmVisibilityFilter.value = state.visibilityFilter || 'all';
            if (fmStatusFilter) fmStatusFilter.value = state.statusFilter || 'all';
            if (fmSort) fmSort.value = state.sort || 'newest';
            if (dashboardFilterCard) {
                const collapsed = state.filterCardCollapsed !== undefined ? Boolean(state.filterCardCollapsed) : true;
                setFilterCardCollapsed(collapsed);
            }
            applySearchFilter();

            if (Array.isArray(state.selectedItems)) {
                selectedItems = state.selectedItems.filter(item =>
                    findItemElement(item)
                );
                updateSelectionUI();
            }

            if (typeof state.scrollY === 'number') {
                window.scrollTo(0, state.scrollY);
            }
        } catch (err) {
            console.error('Failed to restore file manager state:', err);
        }
    }

    function reloadWithState(extra = {}) {
        savePageState(extra);
        window.location.reload();
    }

    function itemMatchesType(item, typeFilter) {
        if (typeFilter === 'all') return true;
        if (typeFilter === 'folder') return item.dataset.kind === 'folder';
        if (item.dataset.kind === 'folder') return false;

        const mime = String(item.dataset.mime || '').toLowerCase();
        if (typeFilter === 'image') return mime.startsWith('image/');
        if (typeFilter === 'video') return mime.startsWith('video/');
        if (typeFilter === 'audio') return mime.startsWith('audio/');
        if (typeFilter === 'document') return mime.includes('pdf') || mime.includes('text') || mime.includes('document') || mime.includes('sheet') || mime.includes('presentation');
        if (typeFilter === 'archive') return mime.includes('zip') || mime.includes('rar') || mime.includes('tar') || mime.includes('7z');
        return !mime.startsWith('image/') && !mime.startsWith('video/') && !mime.startsWith('audio/');
    }

    function getActiveFilterChips() {
        const chips = [];
        if (fmSearch?.value) chips.push(`Search: ${fmSearch.value}`);
        if (fmTypeFilter?.value && fmTypeFilter.value !== 'all') chips.push(`Type: ${fmTypeFilter.options[fmTypeFilter.selectedIndex].text}`);
        if (fmVisibilityFilter?.value && fmVisibilityFilter.value !== 'all') chips.push(`Visibility: ${fmVisibilityFilter.options[fmVisibilityFilter.selectedIndex].text}`);
        if (fmStatusFilter?.value && fmStatusFilter.value !== 'all') chips.push(`Status: ${fmStatusFilter.options[fmStatusFilter.selectedIndex].text}`);
        if (fmSort?.value && fmSort.value !== 'newest') chips.push(`Sort: ${fmSort.options[fmSort.selectedIndex].text}`);
        return chips;
    }

    function renderFilterChips() {
        if (!fmFilterChips) {
            return;
        }

        const chips = getActiveFilterChips();
        fmFilterChips.innerHTML = chips.length > 0
            ? chips.map(chip => `<span class="fm-chip">${escapeHtml(chip)}</span>`).join('')
            : '<span class="fm-chip muted">No filters applied</span>';
    }

    function hasActiveFilters() {
        return getActiveFilterChips().length > 0;
    }

    function setFilterCardCollapsed(collapsed) {
        if (!dashboardFilterCard) {
            return;
        }

        dashboardFilterCard.classList.toggle('is-collapsed', collapsed);
        if (toggleFilterCardBtn) {
            toggleFilterCardBtn.textContent = collapsed ? 'Show filters' : 'Hide filters';
            toggleFilterCardBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

    function applySearchFilter() {
        const normalized = String(fmSearch?.value || '').toLowerCase();
        const typeFilter = fmTypeFilter?.value || 'all';
        const visibilityFilter = fmVisibilityFilter?.value || 'all';
        const statusFilter = fmStatusFilter?.value || 'all';
        const sort = fmSort?.value || 'newest';
        const items = Array.from(document.querySelectorAll('.file-item'));
        let visibleCount = 0;

        items.forEach(item => {
            const name = item.querySelector('.file-name')?.innerText.toLowerCase() || '';
            const itemStatus = String(item.dataset.status || 'active');
            const isPublic = item.dataset.public === '1';
            const matchesSearch = name.includes(normalized);
            const matchesType = itemMatchesType(item, typeFilter);
            const matchesVisibility = visibilityFilter === 'all'
                || item.dataset.kind === 'folder'
                || (visibilityFilter === 'public' && isPublic)
                || (visibilityFilter === 'private' && !isPublic);
            const matchesStatus = statusFilter === 'all' || itemStatus === statusFilter;
            const visible = item.dataset.pendingRemoval !== '1' && matchesSearch && matchesType && matchesVisibility && matchesStatus;
            item.style.display = visible ? '' : 'none';
            if (visible) visibleCount++;
        });

        const sorted = items.slice().sort((left, right) => {
            const leftIsFolder = left.dataset.kind === 'folder';
            const rightIsFolder = right.dataset.kind === 'folder';
            if (leftIsFolder !== rightIsFolder) {
                return leftIsFolder ? -1 : 1;
            }

            if (sort === 'name' || sort === 'name_desc') {
                const comparison = (left.querySelector('.file-name')?.innerText || '').localeCompare(right.querySelector('.file-name')?.innerText || '');
                return sort === 'name_desc' ? -comparison : comparison;
            }
            if (sort === 'largest' || sort === 'smallest') {
                const comparison = Number(right.dataset.size || 0) - Number(left.dataset.size || 0);
                return sort === 'smallest' ? -comparison : comparison;
            }
            if (sort === 'downloads' || sort === 'downloads_asc') {
                const comparison = Number(right.dataset.downloads || 0) - Number(left.dataset.downloads || 0);
                return sort === 'downloads_asc' ? -comparison : comparison;
            }
            if (sort === 'public' || sort === 'private') {
                const comparison = Number(right.dataset.public || 0) - Number(left.dataset.public || 0);
                return sort === 'private' ? -comparison : comparison;
            }

            const leftDate = new Date(left.dataset.createdAt || 0).getTime();
            const rightDate = new Date(right.dataset.createdAt || 0).getTime();
            return sort === 'oldest' ? leftDate - rightDate : rightDate - leftDate;
        });

        sorted.forEach(item => fileGrid?.appendChild(item));
        renderFilterChips();
        if (fmFilterResults) {
            fmFilterResults.textContent = visibleCount === items.length
                ? `Showing all ${visibleCount} item${visibleCount === 1 ? '' : 's'}`
                : `Showing ${visibleCount} of ${items.length} item${items.length === 1 ? '' : 's'}`;
        }
        if (dashboardFilterCard && hasActiveFilters()) {
            setFilterCardCollapsed(false);
        }
        updateListSortButtons(sort);
        updateSelectionUI();
    }

    function updateListSortButtons(sort) {
        listSortButtons.forEach(button => {
            const primarySort = button.getAttribute('data-list-sort');
            const altSort = button.getAttribute('data-list-sort-alt');
            const isActive = sort === primarySort || sort === altSort;
            const isAlt = sort === altSort;
            button.classList.toggle('is-active', isActive);
            button.classList.toggle('is-alt', isActive && isAlt);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatBytes(bytes) {
        const value = Number(bytes || 0);
        if (!value) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const power = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        const size = value / Math.pow(1024, power);
        return `${size.toFixed(power === 0 ? 0 : 1)} ${units[power]}`;
    }

    function formatDashboardDate(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value).slice(0, 10);
        }

        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function formatDashboardDateTime(value) {
        if (!value) {
            return '';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    }

    function fileIconForMime(mime) {
        const normalized = String(mime || '').toLowerCase();
        if (normalized.startsWith('image/')) return '&#128247;';
        if (normalized.startsWith('video/')) return '&#127909;';
        if (normalized.startsWith('audio/')) return '&#127925;';
        if (normalized.includes('pdf')) return '&#128462;';
        if (normalized.includes('zip') || normalized.includes('rar') || normalized.includes('tar') || normalized.includes('7z')) return '&#128230;';
        return '&#128196;';
    }

    function describeItemFromElement(element) {
        if (!element) {
            return null;
        }

        return {
            id: element.getAttribute('data-id'),
            type: itemTypeFromElement(element),
            parentId: element.getAttribute('data-parent-id') || 'root',
            name: element.querySelector('.file-name')?.innerText || '',
            shortId: element.dataset.shortId || '',
            mime: element.dataset.mime || '',
            url: element.querySelector('.file-info')?.dataset.navUrl || element.querySelector('.file-preview')?.dataset.navUrl || '',
            isPublic: element.dataset.public === '1',
        };
    }

    function itemTypeFromElement(element) {
        return element?.dataset?.kind || (element?.classList?.contains('folder-item') ? 'folder' : 'file');
    }

    function sameItem(left, right) {
        return String(left?.id ?? '') === String(right?.id ?? '') && String(left?.type ?? '') === String(right?.type ?? '');
    }

    function findItemElement(item) {
        return Array.from(document.querySelectorAll('.file-item')).find(element =>
            String(element.getAttribute('data-id')) === String(item?.id ?? '') && itemTypeFromElement(element) === item?.type
        ) || null;
    }

    function currentViewCanDisplayFile(file) {
        if (!file || isTrashView || !fileGrid) {
            return false;
        }

        const currentFolder = currentFolderId();
        const fileFolder = file.folder_id === null || file.folder_id === undefined || file.folder_id === ''
            ? null
            : Number(file.folder_id);

        return Number(currentFolder || 0) === Number(fileFolder || 0);
    }

    function currentViewCanDisplayFolder(parentId) {
        if (isTrashView || !fileGrid) {
            return false;
        }

        return Number(currentFolderId() || 0) === Number(parentId || 0);
    }

    function hasBlockingUploads() {
        return Array.from(uploadTaskRegistry.values()).some(task =>
            ['queued', 'starting', 'uploading', 'finalizing', 'paused'].includes(task.status)
        );
    }

    function folderRoute(folderId = null) {
        return folderId ? `/folder/${encodeURIComponent(folderId)}` : '/';
    }

    function workspaceHeadingText(folder) {
        return folder ? String(folder.name || 'Folder') : 'All Files';
    }

    function workspaceCopyText(folder) {
        return folder
            ? 'Work inside this folder, upload new files, and keep the structure tidy without leaving the page.'
            : 'Upload, organize, and share your files from one workspace. Start with upload, then use filters once the list gets busy.';
    }

    function setWorkspaceItems(folders = [], files = []) {
        if (!fileGrid) {
            return;
        }

        fileGrid.querySelectorAll('.file-item, .empty-state').forEach(node => node.remove());

        if (folders.length === 0 && files.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.innerHTML = `
                <div class="empty-icon" aria-hidden="true">&#128194;</div>
                <p>No files or folders here. Start by uploading something!</p>
            `;
            fileGrid.appendChild(empty);
        } else {
            folders.forEach(folder => fileGrid.appendChild(buildFolderItemElement(folder)));
            files.forEach(file => fileGrid.appendChild(buildFileItemElement(file)));
        }

        syncWorkspaceSummaryFromDom();
    }

    function formatWorkspaceSummaryDate(value) {
        if (!value) {
            return 'No uploads yet';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return 'No uploads yet';
        }

        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    }

    function syncWorkspaceSummaryFromDom() {
        const items = Array.from(document.querySelectorAll('.file-item'))
            .filter(item => item.dataset.pendingRemoval !== '1');
        const folders = items.filter(item => item.dataset.kind === 'folder');
        const files = items.filter(item => item.dataset.kind === 'file');

        if (filePanelMeta) {
            const total = items.length;
            filePanelMeta.textContent = `${total.toLocaleString()} visible item${total === 1 ? '' : 's'}`;
        }

        const statValues = document.querySelectorAll('.dashboard-workspace-stat-value');
        const statMetas = document.querySelectorAll('.dashboard-workspace-stat-meta');
        if (statValues.length >= 4) {
            statValues[0].textContent = files.length.toLocaleString();
            statValues[1].textContent = folders.length.toLocaleString();

            if (files.length > 0) {
                const latestTimestamp = files.reduce((latest, item) => {
                    const next = Date.parse(String(item.dataset.createdAt || ''));
                    return Number.isFinite(next) && next > latest ? next : latest;
                }, 0);
                statValues[3].textContent = latestTimestamp > 0
                    ? formatWorkspaceSummaryDate(new Date(latestTimestamp).toISOString())
                    : 'No uploads yet';
                if (statMetas.length >= 4) {
                    statMetas[3].textContent = 'Most recent file added';
                }
            } else {
                statValues[3].textContent = 'No uploads yet';
                if (statMetas.length >= 4) {
                    statMetas[3].textContent = 'Upload to get started';
                }
            }
        }
    }

    function updateWorkspaceBreadcrumbs(folder, allFolders = []) {
        if (!breadcrumbs) {
            return;
        }

        const folderMap = new Map(allFolders.map(item => [String(item.id), item]));
        const crumbs = [];
        let current = folder;
        while (current) {
            crumbs.unshift(current);
            const parentId = current.parent_id === null || current.parent_id === undefined || current.parent_id === ''
                ? null
                : String(current.parent_id);
            current = parentId ? folderMap.get(parentId) || null : null;
        }

        const parts = ['<a href="/" data-nav-url="/">Home</a>'];
        crumbs.forEach((crumb, index) => {
            const isLast = index === crumbs.length - 1;
            parts.push('<span class="crumb-sep">/</span>');
            if (isLast) {
                parts.push(`<span>${escapeHtml(crumb.name || 'Folder')}</span>`);
            } else {
                parts.push(`<a href="${escapeHtml(folderRoute(crumb.id))}" data-nav-url="${escapeHtml(folderRoute(crumb.id))}">${escapeHtml(crumb.name || 'Folder')}</a>`);
            }
        });

        breadcrumbs.innerHTML = parts.join('');
    }

    function updateWorkspaceActions(folder) {
        if (!mainActionsRight) {
            return;
        }

        mainActionsRight.innerHTML = '';
        if (folder && !isGuestMode) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-white';
            button.setAttribute('data-nav-url', folder.parent_id ? folderRoute(folder.parent_id) : '/');
            button.textContent = 'Up One Level';
            mainActionsRight.appendChild(button);
        }
    }

    async function softNavigateFolder(folderId = null, options = {}) {
        if (isTrashView || isGuestMode) {
            window.location.assign(folderRoute(folderId));
            return;
        }

        const folderParam = folderId ? encodeURIComponent(String(folderId)) : 'root';
        if (workspaceHeading) {
            workspaceHeading.setAttribute('aria-busy', 'true');
        }

        try {
            const [foldersPayload, filesPayload, allFolders] = await Promise.all([
                apiJson(`/api/v1/folders?parent_id=${folderParam}`),
                apiJson(`/api/v1/files?folder_id=${folderParam}`),
                fetch('/folders/json', { credentials: 'same-origin' }).then(r => r.json()).catch(() => []),
            ]);

            const folders = Array.isArray(foldersPayload?.folders) ? foldersPayload.folders : [];
            const files = Array.isArray(filesPayload?.files) ? filesPayload.files : [];
            const folderList = Array.isArray(allFolders) ? allFolders : [];
            const folder = folderId ? (folderList.find(item => String(item.id) === String(folderId)) || null) : null;

            if (workspaceTitle) {
                workspaceTitle.textContent = workspaceHeadingText(folder);
            }
            if (workspaceCopy) {
                workspaceCopy.textContent = workspaceCopyText(folder);
            }
            if (document.getElementById('currentFolderId')) {
                document.getElementById('currentFolderId').value = folderId ? String(folderId) : '';
            }

            updateWorkspaceBreadcrumbs(folder, folderList);
            updateWorkspaceActions(folder);
            clearSelection();
            setWorkspaceItems(folders, files);
            applySearchFilter();
            if (options.pushHistory !== false) {
                history.pushState({ folderId: folderId ? String(folderId) : null }, '', folderRoute(folderId));
            }
        } catch (err) {
            console.error('Soft folder navigation failed:', err);
            showToast('Could not open that folder in place. Falling back to a full page load.', [], 5000);
            window.location.assign(folderRoute(folderId));
        } finally {
            if (workspaceHeading) {
                workspaceHeading.removeAttribute('aria-busy');
            }
        }
    }

    function openFolderView(folderId) {
        const url = '/folder/' + encodeURIComponent(folderId);
        if (!hasBlockingUploads()) {
            window.location.assign(url);
            return;
        }
        softNavigateFolder(folderId);
    }

    window.__fmHasBlockingUploads = hasBlockingUploads;
    window.__fmOpenFolderView = openFolderView;
    window.__fmOpenWorkspaceUrl = (url) => {
        if (url === '/') {
            softNavigateFolder(null);
            return;
        }
        const match = /^\/folder\/(\d+)(?:[/?#]|$)/.exec(String(url || ''));
        if (match) {
            softNavigateFolder(match[1]);
            return;
        }
        window.location.assign(url);
    };

    window.addEventListener('popstate', (event) => {
        const stateFolderId = event.state && Object.prototype.hasOwnProperty.call(event.state, 'folderId')
            ? event.state.folderId
            : null;
        if (!hasBlockingUploads()) {
            return;
        }
        softNavigateFolder(stateFolderId, { pushHistory: false });
    });

    function removeEmptyStateCard() {
        fileGrid?.querySelector('.empty-state')?.remove();
    }

    function buildFolderItemElement(folder) {
        const folderId = String(folder.id);
        const parentId = folder.parent_id === null || folder.parent_id === undefined ? '' : String(folder.parent_id);
        const name = String(folder.name || 'New Folder');
        const createdAt = String(folder.created_at || new Date().toISOString());
        const fileCount = Number(folder.file_count || 0);

        const item = document.createElement('div');
        item.className = 'file-item folder-item';
        item.setAttribute('data-id', folderId);
        item.setAttribute('data-kind', 'folder');
        item.setAttribute('data-parent-id', parentId);
        item.setAttribute('data-status', String(folder.status || 'active'));
        item.setAttribute('data-size', String(folder.total_size || 0));
        item.setAttribute('data-downloads', '0');
        item.setAttribute('data-created-at', createdAt);
        item.setAttribute('draggable', 'true');

        item.innerHTML = `
            <div class="file-hover-controls">
                <div class="file-select">
                    <input type="checkbox" class="item-checkbox" data-id="${escapeHtml(folderId)}" data-type="folder">
                </div>
                <div class="file-options-trigger" data-id="${escapeHtml(folderId)}" data-type="folder" data-name="${escapeHtml(name)}">
                    <span class="trigger-icon" aria-hidden="true">&#9662;</span>
                </div>
            </div>
            <div class="file-preview">
                <div class="file-icon" aria-hidden="true">&#128193;</div>
            </div>
            <div class="file-info" data-nav-url="/folder/${encodeURIComponent(folderId)}">
                <div class="file-name" title="${escapeHtml(name)}">
                    ${escapeHtml(name)}
                    <span class="folder-count-badge">${fileCount}</span>
                </div>
                <div class="file-meta">
                    <span class="file-stats">${fileCount > 0 ? `${fileCount} ${fileCount === 1 ? 'file' : 'files'}` : 'Empty'}</span>
                    <span class="file-date dashboard-date-hidden">${escapeHtml(formatDashboardDateTime(createdAt))}</span>
                </div>
            </div>
            <div class="file-list-cell file-list-size"></div>
            <div class="file-list-cell file-list-upload">${escapeHtml(formatDashboardDate(createdAt))}</div>
            <div class="file-list-cell file-list-downloads"></div>
            <div class="file-list-cell file-list-public"></div>
            <div class="file-list-actions">
                <button class="fm-row-action rename-item" type="button" title="Rename" aria-label="Rename folder">&#9998;</button>
                <button class="fm-row-action fm-row-action-danger delete-folder" type="button" title="Move to Trash" aria-label="Move folder to trash">&times;</button>
            </div>
        `;

        return item;
    }

    function upsertFolderInView(folder) {
        if (!folder || !currentViewCanDisplayFolder(folder.parent_id)) {
            return false;
        }

        removeEmptyStateCard();
        const existing = findItemElement({ id: folder.id, type: 'folder' });
        const element = buildFolderItemElement(folder);
        if (existing) {
            existing.replaceWith(element);
        } else {
            fileGrid.appendChild(element);
        }
        applySearchFilter();
        syncWorkspaceSummaryFromDom();
        return true;
    }

    function buildFileItemElement(file) {
        const fileId = String(file.id);
        const shortId = String(file.short_id || '');
        const filename = String(file.filename || 'Untitled file');
        const mimeType = String(file.mime_type || 'application/octet-stream');
        const isPublic = Number(file.is_public || 0) === 1;
        const fileSize = Number(file.file_size || 0);
        const downloadCount = Number(file.downloads || 0);
        const createdAt = String(file.created_at || new Date().toISOString());
        const folderId = file.folder_id === null || file.folder_id === undefined ? '' : String(file.folder_id);

        const item = document.createElement('div');
        item.className = 'file-item';
        item.setAttribute('data-id', fileId);
        item.setAttribute('data-kind', 'file');
        item.setAttribute('data-parent-id', folderId);
        item.setAttribute('data-status', String(file.status || 'active'));
        item.setAttribute('data-public', isPublic ? '1' : '0');
        item.setAttribute('data-size', String(fileSize));
        item.setAttribute('data-downloads', String(downloadCount));
        item.setAttribute('data-mime', mimeType);
        item.setAttribute('data-short-id', shortId);
        item.setAttribute('data-created-at', createdAt);
        item.setAttribute('draggable', 'true');

        item.innerHTML = `
            <div class="file-hover-controls">
                <div class="file-select">
                    <input type="checkbox" class="item-checkbox" data-id="${escapeHtml(fileId)}" data-type="file">
                </div>
                <div class="file-options-trigger" data-id="${escapeHtml(fileId)}" data-type="file" data-name="${escapeHtml(filename)}">
                    <span class="trigger-icon" aria-hidden="true">&#9662;</span>
                </div>
            </div>
            <div class="file-preview" data-nav-url="/file/${encodeURIComponent(shortId)}" data-nav-target="_blank">
                <div class="file-icon">${fileIconForMime(mimeType)}</div>
            </div>
            <div class="file-info" data-nav-url="/file/${encodeURIComponent(shortId)}" data-nav-target="_blank">
                <div class="file-name" title="${escapeHtml(filename)}">${escapeHtml(filename)}</div>
                <div class="file-meta">
                    <span class="file-size-raw">${escapeHtml(formatBytes(fileSize))}</span>
                    <span class="file-date dashboard-date-hidden">${escapeHtml(formatDashboardDateTime(createdAt))}</span>
                </div>
            </div>
            <div class="file-list-cell file-list-size">${escapeHtml(formatBytes(fileSize))}</div>
            <div class="file-list-cell file-list-upload">${escapeHtml(formatDashboardDate(createdAt))}</div>
            <div class="file-list-cell file-list-downloads">${downloadCount > 0 ? escapeHtml(String(downloadCount)) : ''}</div>
            <div class="file-list-cell file-list-public">
                <button class="fm-switch-indicator fm-public-toggle ${isPublic ? 'is-on' : ''}"
                        type="button"
                        data-visibility-toggle
                        aria-label="${isPublic ? 'Make private' : 'Make public'}"
                        title="${isPublic ? 'Public' : 'Private'}"></button>
            </div>
            <div class="file-list-actions">
                <button class="fm-row-action rename-item" type="button" title="Rename" aria-label="Rename file">&#9998;</button>
                <button class="fm-row-action fm-row-action-danger delete-file" type="button" title="Move to Trash" aria-label="Move file to trash">&times;</button>
            </div>
        `;

        return item;
    }

    async function upsertCompletedFileInView(fileId) {
        if (!fileId || !fileGrid || isGuestMode || isTrashView || !liveUploadInsertEnabled) {
            return false;
        }

        const payload = await apiJson(`/api/v1/files/${encodeURIComponent(fileId)}`);
        const file = payload?.file;
        if (!currentViewCanDisplayFile(file)) {
            return false;
        }

        removeEmptyStateCard();
        const existing = findItemElement({ id: file.id, type: 'file' });
        const element = buildFileItemElement(file);

        if (existing) {
            existing.replaceWith(element);
        } else {
            fileGrid.appendChild(element);
        }

        applySearchFilter();
        syncWorkspaceSummaryFromDom();
        return true;
    }

    function selectionContains(id, type) {
        return selectedItems.some(item => sameItem(item, { id, type }));
    }

    function rememberSelectionAnchor(item) {
        if (!item?.id || !item?.type) {
            selectionAnchor = null;
            return;
        }
        selectionAnchor = { id: String(item.id), type: String(item.type) };
    }

    function visibleFileItems() {
        return Array.from(document.querySelectorAll('.file-item'))
            .filter(item => item.style.display !== 'none' && item.dataset.pendingRemoval !== '1');
    }

    function selectionRangeFor(currentItem) {
        const items = visibleFileItems();
        const anchor = selectionAnchor && findItemElement(selectionAnchor)
            ? selectionAnchor
            : (selectedItems.length > 0 ? selectedItems[selectedItems.length - 1] : null);
        const anchorIndex = anchor
            ? items.findIndex(item => sameItem({ id: item.getAttribute('data-id'), type: itemTypeFromElement(item) }, anchor))
            : -1;
        const currentIndex = items.findIndex(item => sameItem({ id: item.getAttribute('data-id'), type: itemTypeFromElement(item) }, currentItem));

        if (currentIndex === -1) {
            return [currentItem];
        }
        if (anchorIndex === -1) {
            return [currentItem];
        }

        const start = Math.min(anchorIndex, currentIndex);
        const end = Math.max(anchorIndex, currentIndex);
        return items.slice(start, end + 1).map(item => ({
            id: item.getAttribute('data-id'),
            type: itemTypeFromElement(item),
        }));
    }

    function setSelectionRange(currentItem, additive = false) {
        const range = selectionRangeFor(currentItem);
        if (additive) {
            const merged = [...selectedItems];
            range.forEach(item => {
                if (!merged.some(existing => sameItem(existing, item))) {
                    merged.push(item);
                }
            });
            selectedItems = merged;
        } else {
            selectedItems = range;
        }
        rememberSelectionAnchor(currentItem);
        updateSelectionUI();
    }

    function clearSelection() {
        if (selectedItems.length === 0) {
            return;
        }
        selectedItems = [];
        selectionAnchor = null;
        updateSelectionUI();
    }

    function collectSnapshot(items) {
        return items.map(item => {
            const element = findItemElement(item);
            return describeItemFromElement(element) || item;
        });
    }

    function removeItemsFromView(items) {
        items.forEach(item => {
            const element = findItemElement(item);
            if (element) {
                element.dataset.pendingRemoval = '1';
                element.style.display = 'none';
            }
        });
        selectedItems = selectedItems.filter(item => !items.some(removed => sameItem(removed, item)));
        updateSelectionUI();
        applySearchFilter();
    }

    function updatePublicSwitchForItem(item, isPublic) {
        if (!item) return;
        item.setAttribute('data-public', isPublic ? '1' : '0');
        const toggle = item.querySelector('[data-visibility-toggle]');
        if (toggle) {
            toggle.classList.toggle('is-on', isPublic);
            toggle.setAttribute('aria-label', isPublic ? 'Make private' : 'Make public');
            toggle.setAttribute('title', isPublic ? 'Public' : 'Private');
        }
    }

    function showToast(message, actions = [], duration = 5000) {
        if (!toastStack) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'fm-toast';
        toast.innerHTML = `
            <div class="fm-toast-copy">${escapeHtml(message)}</div>
            <div class="fm-toast-actions"></div>
        `;

        const actionWrap = toast.querySelector('.fm-toast-actions');
        actions.forEach(action => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `fm-toast-btn${action.danger ? ' danger' : ''}`;
            button.textContent = action.label;
            button.addEventListener('click', () => {
                action.onClick?.();
                toast.remove();
            });
            actionWrap.appendChild(button);
        });

        toastStack.appendChild(toast);
        const timeout = setTimeout(() => toast.remove(), duration);
        toast.addEventListener('mouseenter', () => clearTimeout(timeout), { once: true });
    }

    function queueFlashToast(message, duration = 5000) {
        try {
            sessionStorage.setItem('fyuhls.flash.toast', JSON.stringify({ message, duration }));
        } catch (err) {
        }
    }

    function consumeFlashToast() {
        try {
            const raw = sessionStorage.getItem('fyuhls.flash.toast');
            if (!raw) {
                return;
            }
            sessionStorage.removeItem('fyuhls.flash.toast');
            const payload = JSON.parse(raw);
            if (payload && typeof payload.message === 'string' && payload.message.trim() !== '') {
                showToast(payload.message, [], Number(payload.duration || 5000));
            }
        } catch (err) {
        }
    }

    async function copyText(value, label = 'Link') {
        try {
            await navigator.clipboard.writeText(value);
            showToast(`${label} copied.`);
        } catch (err) {
            alert(`Failed to copy ${label.toLowerCase()}.`);
        }
    }

    let activeSheetItem = null;

    function closeMobileSheet() {
        if (mobileActionSheet) {
            mobileActionSheet.style.display = 'none';
        }
        activeSheetItem = null;
    }

    function openMobileSheet(itemId, itemType, itemName) {
        if (!mobileActionSheet || window.innerWidth > 768) {
            return false;
        }

        activeSheetItem = { id: itemId, type: itemType, name: itemName };
        if (mobileActionTitle) {
            mobileActionTitle.textContent = itemName || 'Item actions';
        }
        const downloadButton = document.getElementById('mobileActionDownload');
        const shareButton = document.getElementById('mobileActionShare');
        const replaceButton = document.getElementById('mobileActionReplace');
        if (downloadButton) downloadButton.style.display = itemType === 'file' ? '' : 'none';
        if (shareButton) shareButton.style.display = itemType === 'file' ? '' : 'none';
        if (replaceButton) replaceButton.style.display = (replaceEnabled && itemType === 'file') ? '' : 'none';
        mobileActionSheet.style.display = 'block';
        return true;
    }

    async function openShareModalForItem(fileId) {
        const element = findItemElement({ id: fileId, type: 'file' });
        if (!element || element.dataset.kind !== 'file') {
            alert('Sharing is available for files only.');
            return;
        }

        const shortId = element.dataset.shortId;
        const baseUrl = String(fileManagerConfig.baseUrl || window.location.origin).replace(/\/$/, '');
        const pageUrl = `${baseUrl}/file/${encodeURIComponent(shortId)}`;
        const downloadUrl = await requestDownloadLink(fileId);
        const isPublic = element.dataset.public === '1';
        const visibility = isPublic ? 'Public file page is available.' : 'Private file. Only you or authorized users can open its page.';

        if (shareModalDescription) {
            shareModalDescription.textContent = `Share "${element.querySelector('.file-name')?.innerText || 'file'}"`;
        }
        if (sharePageUrl) {
            sharePageUrl.value = isPublic ? pageUrl : 'This file page is private';
            sharePageUrl.readOnly = true;
        }
        if (sharePageHint) {
            sharePageHint.textContent = isPublic
                ? 'Anyone with this page link can view the public file page.'
                : 'Switch the file to public before sharing its page link.';
        }
        if (copySharePageBtn) {
            copySharePageBtn.disabled = !isPublic;
        }
        if (shareDownloadUrl) shareDownloadUrl.value = downloadUrl;
        if (shareMeta) shareMeta.textContent = visibility;
        if (shareModal) shareModal.style.display = 'block';
    }

    async function requestDownloadLink(fileId) {
        const response = await fetch(`/api/v1/downloads/${encodeURIComponent(fileId)}/link`, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
            },
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.url) {
            throw new Error(payload.error || 'Failed to prepare download.');
        }

        return payload.url;
    }

    async function openDownloadById(fileId) {
        const url = await requestDownloadLink(fileId);
        const link = document.createElement('a');
        link.href = url;
        link.rel = 'noopener';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    async function downloadSelectedFiles(files) {
        for (const file of files) {
            try {
                await openDownloadById(file.id);
            } catch (err) {
                alert(err.message || `Failed to download file ${file.id}`);
                break;
            }
            await new Promise(resolve => setTimeout(resolve, 250));
        }
    }

    // 1.5 Helper: Custom Modal (Replaces prompt/confirm)
    function showActionModal(title, description, defaultValue = '', showInput = false) {
        return new Promise((resolve) => {
            const modalTitle = document.getElementById('modalTitle');
            const modalDesc = document.getElementById('modalDescription');
            const modalInput = document.getElementById('modalInput');
            const modalInputContainer = document.getElementById('modalInputContainer');
            const confirmBtn = document.getElementById('modalConfirmBtn');
            const cancelBtn = document.getElementById('modalCancelBtn');

            modalTitle.innerText = title;
            modalDesc.innerText = description;
            modalInput.value = defaultValue;
            modalInputContainer.style.display = showInput ? 'block' : 'none';
            actionModal.style.display = 'flex';

            const close = (val) => {
                actionModal.style.display = 'none';
                confirmBtn.onclick = null;
                cancelBtn.onclick = null;
                resolve(val);
            };

            confirmBtn.onclick = () => close(showInput ? modalInput.value : true);
            cancelBtn.onclick = () => close(null);
        });
    }

    // --- shared item action handler (used by context menu, dropdown, and mobile sheet) ---
    async function performItemAction(action, id, type, name, itemEl) {
        if (action === 'download') {
            openDownloadById(id).catch(err => alert(err.message || 'Download failed'));
            return;
        }

        if (action === 'share') {
            hideItemDropdown();
            openShareModalForItem(id).catch(err => alert(err.message || 'Failed to prepare share link'));
            return;
        }

        if (action === 'replace') {
            if (!replaceEnabled) {
                showToast('File replacement is currently disabled.');
                return;
            }
            if (type !== 'file') {
                return;
            }
            pendingReplaceTarget = { id, type, name };
            if (fileInput) {
                fileInput.value = '';
                fileInput.removeAttribute('multiple');
                fileInput.click();
            }
            return;
        }

        if (action === 'rename') {
            const currentItem = itemEl || findItemElement({ id, type });
            const currentName = currentItem?.querySelector('.file-name')?.innerText || name;
            const newName = await showActionModal('Rename ' + type, 'Enter a new name:', currentName, true);
            if (newName && newName !== currentName) {
                const fd = new FormData();
                fd.append(type === 'file' ? 'id' : 'folder_id', id);
                fd.append('name', newName);
                fd.append('csrf_token', csrfToken);
                fetch(type === 'file' ? '/file/rename' : '/folder/rename', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            // update in-place instead of full reload
                            const nameEl = currentItem?.querySelector('.file-name');
                            if (nameEl) {
                                nameEl.textContent = newName;
                                nameEl.title = newName;
                            }
                            showToast(`Renamed to "${newName}"`);
                        } else {
                            alert(data.error || 'Failed to rename');
                        }
                    })
                    .catch(() => alert('Rename failed'));
            }
            return;
        }

        if (action === 'move') {
            document.getElementById('bulkMoveBtn')?.click();
            return;
        }

        if (action === 'copy') {
            showFolderTreeModal('Copy to...', (targetId) => {
                performBulkCopy([{ id, type }], targetId);
            });
            return;
        }

        if (action === 'trash') {
            performBulkTrash([{ id, type }]);
            return;
        }
    }

    // 1.6 Context Menu Controller
    function showContextMenu(e, item) {
        e.preventDefault();
        const id = item.getAttribute('data-id');
        const type = itemTypeFromElement(item);
        const name = item.querySelector('.file-name').innerText;

        // auto-select if not selected already
        if (!selectionContains(id, type)) {
            selectedItems = [{ id, type, name }];
            updateSelectionUI();
        }

        contextMenu.style.display = 'block';
        contextMenu.style.visibility = 'hidden';

        const menuWidth = contextMenu.offsetWidth;
        const menuHeight = contextMenu.offsetHeight;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        let left = e.clientX;
        let top = e.clientY;

        if (left + menuWidth > viewportWidth) left -= menuWidth;
        if (top + menuHeight > viewportHeight) top -= menuHeight;

        left = Math.max(10, Math.min(left, viewportWidth - menuWidth - 10));
        top  = Math.max(10, Math.min(top,  viewportHeight - menuHeight - 10));

        contextMenu.style.left = left + 'px';
        contextMenu.style.top  = top  + 'px';
        contextMenu.style.visibility = 'visible';
        contextMenu.style.display = 'block';

        // re-wire context menu items after cloning to clear stale listeners
        ['ctxDownload', 'ctxReplace', 'ctxRename', 'ctxMove', 'ctxCopy', 'ctxTrash'].forEach(cid => {
            const el = document.getElementById(cid);
            if (!el) return;
            const fresh = el.cloneNode(true);
            el.parentNode.replaceChild(fresh, el);
        });

        const ctxDownload = document.getElementById('ctxDownload');
        if (ctxDownload) {
            ctxDownload.style.display = (type === 'file') ? 'flex' : 'none';
            ctxDownload.onclick = () => performItemAction('download', id, type, name, item);
        }
        const ctxReplace = document.getElementById('ctxReplace');
        if (ctxReplace) {
            ctxReplace.style.display = (replaceEnabled && type === 'file') ? 'flex' : 'none';
            ctxReplace.onclick = () => performItemAction('replace', id, type, name, item);
        }
        const ctxRename = document.getElementById('ctxRename');
        if (ctxRename) ctxRename.onclick = () => performItemAction('rename', id, type, name, item);
        const ctxMove   = document.getElementById('ctxMove');
        if (ctxMove)   ctxMove.onclick   = () => performItemAction('move',   id, type, name, item);
        const ctxCopy   = document.getElementById('ctxCopy');
        if (ctxCopy)   ctxCopy.onclick   = () => performItemAction('copy',   id, type, name, item);
        const ctxTrash  = document.getElementById('ctxTrash');
        if (ctxTrash)  ctxTrash.onclick  = () => performItemAction('trash',  id, type, name, item);

        const ctxProps = document.getElementById('ctxProps');
        if (ctxProps) {
            ctxProps.onclick = () => {
                const size = item.querySelector('.file-size-raw')?.innerText || 'Unknown';
                const date = item.querySelector('.file-date')?.title || item.querySelector('.file-date')?.innerText || 'Unknown';
                const info = `Name: ${name}\nType: ${type.toUpperCase()}\nSize: ${size}\nCreated: ${date}`;
                showActionModal(type.charAt(0).toUpperCase() + type.slice(1) + ' Properties', info);
            };
        }
    }


    document.body.addEventListener('click', (e) => {
        if (contextMenu) contextMenu.style.display = 'none';
        if (sidebarContextMenu) sidebarContextMenu.style.display = 'none';

        const dropdown = document.getElementById('itemDropdown');
        if (dropdown && !e.target.closest('.file-options-trigger') && !e.target.closest('.item-dropdown')) {
            dropdown.style.display = 'none';
        }
    });

    function hideItemDropdown() {
        const dropdown = document.getElementById('itemDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
    }

    window.addEventListener('click', (event) => {
        if (event.target === shareModal && shareModal) {
            shareModal.style.display = 'none';
        }
        if (event.target === bulkLinksModal && bulkLinksModal) {
            bulkLinksModal.style.display = 'none';
        }
        if (event.target === massRenameModal && massRenameModal) {
            massRenameModal.style.display = 'none';
        }
        if (event.target === mobileActionSheet && mobileActionSheet) {
            closeMobileSheet();
        }
    });

    // 1.7 Sidebar Context Menu (Empty Trash)
    function showSidebarContextMenu(e) {
        e.preventDefault();
        if (!sidebarContextMenu) return;

        sidebarContextMenu.style.display = 'block';
        sidebarContextMenu.style.visibility = 'hidden';

        const menuWidth = sidebarContextMenu.offsetWidth;
        const menuHeight = sidebarContextMenu.offsetHeight;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        let left = e.clientX;
        let top = e.clientY;

        if (left + menuWidth > viewportWidth) left -= menuWidth;
        if (top + menuHeight > viewportHeight) top -= menuHeight;

        sidebarContextMenu.style.left = left + 'px';
        sidebarContextMenu.style.top = top + 'px';
        sidebarContextMenu.style.visibility = 'visible';

        document.getElementById('ctxEmptyTrash').onclick = async () => {
            sidebarContextMenu.style.display = 'none';
            if (await showActionModal('Empty Trash', 'Are you sure you want to PERMANENTLY delete ALL items in the trash?')) {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fetch('/trash/empty', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success || data.status === 'success') reloadWithState();
                        else alert(data.error || 'Failed to empty trash');
                    });
            }
        };
    }

    // Add contextmenu listener for .sidebar-trash-item
    document.addEventListener('contextmenu', (e) => {
        const trashItem = e.target.closest('.sidebar-trash-item');
        if (trashItem) {
            showSidebarContextMenu(e);
        }
    });

    // 1.7 Item Dropdown Controller
    const itemDropdown = document.getElementById('itemDropdown');

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('.file-options-trigger');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();

            const id = trigger.getAttribute('data-id');
            const type = trigger.getAttribute('data-type');
            const name = trigger.getAttribute('data-name');
            const item = trigger.closest('.file-item');

            // Select this item exclusively if it wasnt already selected?
            // Or just use it for the dropdown? Usually dropdown act on the single item.
            // Let's match context menu behavior: auto-select if not selected.
            if (!selectionContains(id, type)) {
                selectedItems = [{ id, type, name }];
                updateSelectionUI();
            }

            if (openMobileSheet(id, type, name)) {
                return;
            }

            // Position and show dropdown
            itemDropdown.style.display = 'block';
            itemDropdown.style.visibility = 'hidden'; // Hide while measuring

            const rect = trigger.getBoundingClientRect();
            const menuWidth = itemDropdown.offsetWidth || 180;
            const menuHeight = itemDropdown.offsetHeight;
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;

            // Default: position below the trigger, aligned to the right edge of trigger
            let top = rect.bottom + 5;
            let left = rect.right - menuWidth;

            // Boundary checks - vertical (flip up)
            if (top + menuHeight > viewportHeight) {
                top = rect.top - menuHeight - 5;
            }

            // Boundary checks - horizontal (keep in bounds)
            if (left < 10) left = 10;
            if (left + menuWidth > viewportWidth - 10) left = viewportWidth - menuWidth - 10;

            // Vertical safety
            if (top < 10) top = 10;

            itemDropdown.style.left = left + 'px';
            itemDropdown.style.top = top + 'px';
            itemDropdown.style.visibility = 'visible';

            // Connect actions
            setupDropdownActions(id, type, name, item);
        }
    });

    function setupDropdownActions(id, type, name, item) {
        const dropDownload = document.getElementById('dropDownload');
        if (dropDownload) {
            dropDownload.style.display = (type === 'file') ? 'flex' : 'none';
            dropDownload.onclick = () => performItemAction('download', id, type, name, item);
        }

        const dropShare = document.getElementById('dropShare');
        if (dropShare) {
            dropShare.style.display = (type === 'file') ? 'flex' : 'none';
            dropShare.onclick = () => performItemAction('share', id, type, name, item);
        }

        const dropReplace = document.getElementById('dropReplace');
        if (dropReplace) {
            dropReplace.style.display = (replaceEnabled && type === 'file') ? 'flex' : 'none';
            dropReplace.onclick = () => performItemAction('replace', id, type, name, item);
        }

        const dropRename = document.getElementById('dropRename');
        if (dropRename) dropRename.onclick = () => performItemAction('rename', id, type, name, item);

        const dropMove = document.getElementById('dropMove');
        if (dropMove) dropMove.onclick = () => performItemAction('move', id, type, name, item);

        const dropCopy = document.getElementById('dropCopy');
        if (dropCopy) dropCopy.onclick = () => performItemAction('copy', id, type, name, item);

        const dropTrash = document.getElementById('dropTrash');
        if (dropTrash) dropTrash.onclick = () => performItemAction('trash', id, type, name, item);
    }


    copySharePageBtn?.addEventListener('click', () => {
        if (sharePageUrl?.value) {
            copyText(sharePageUrl.value, 'Public page link');
        }
    });

    copyShareDownloadBtn?.addEventListener('click', () => {
        if (shareDownloadUrl?.value) {
            copyText(shareDownloadUrl.value, 'Direct download link');
        }
    });

    closeShareModalBtn?.addEventListener('click', () => {
        if (shareModal) {
            shareModal.style.display = 'none';
        }
    });

    bulkLinksBtn?.addEventListener('click', openBulkLinksModal);
    closeBulkLinksBtn?.addEventListener('click', () => {
        if (bulkLinksModal) bulkLinksModal.style.display = 'none';
    });
    copyBulkLinksBtn?.addEventListener('click', () => {
        if (bulkLinksOutput?.value) copyText(bulkLinksOutput.value, 'Bulk links');
    });
    exportBulkLinksBtn?.addEventListener('click', () => {
        const blob = new Blob([bulkLinksOutput?.value || ''], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'bulk-links.txt';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    });
    document.querySelectorAll('[data-link-format]').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('[data-link-format]').forEach(button => button.classList.remove('is-active'));
            tab.classList.add('is-active');
            renderBulkLinks(tab.dataset.linkFormat || 'plain').catch(() => {
                bulkLinksOutput.value = '';
                alert('Could not generate bulk links.');
            });
        });
    });

    massRenameBtn?.addEventListener('click', () => {
        if (selectedItems.length === 0) {
            showToast('Select files or folders to rename.');
            return;
        }
        if (massRenamePreview) massRenamePreview.innerHTML = '';
        if (applyMassRenameBtn) applyMassRenameBtn.disabled = true;
        if (massRenameModal) massRenameModal.style.display = 'block';
    });
    closeMassRenameBtn?.addEventListener('click', () => {
        if (massRenameModal) massRenameModal.style.display = 'none';
    });
    previewMassRenameBtn?.addEventListener('click', () => {
        previewMassRename().catch(err => alert(err.message || 'Could not preview rename.'));
    });
    applyMassRenameBtn?.addEventListener('click', () => {
        applyMassRename().catch(err => alert(err.message || 'Could not apply rename.'));
    });

    closeMobileActionSheetBtn?.addEventListener('click', () => {
        closeMobileSheet();
    });

    document.querySelectorAll('.mobile-action-btn').forEach(button => {
        button.addEventListener('click', () => {
            if (!activeSheetItem) {
                return;
            }

            const item = { ...activeSheetItem };
            closeMobileSheet();
            performItemAction(button.dataset.action, item.id, item.type, item.name);
        });
    });


    // 2. Service Worker
    if ('serviceWorker' in navigator) {
        const swUrl = '/sw.js?v=7';
        navigator.serviceWorker.getRegistration('/').then((registration) => {
            const currentScript = registration?.active?.scriptURL || registration?.waiting?.scriptURL || registration?.installing?.scriptURL || '';
            if (registration && !currentScript.includes('v=7')) {
                registration.unregister().finally(() => {
                    navigator.serviceWorker.register(swUrl).then((freshRegistration) => {
                        freshRegistration.update?.();
                    }).catch((err) => {
                        console.warn('Service worker re-registration failed:', err);
                    });
                });
                return;
            }

            navigator.serviceWorker.register(swUrl).then((freshRegistration) => {
                freshRegistration.update?.();
            }).catch((err) => {
                console.warn('Service worker registration failed:', err);
            });
        }).catch((err) => {
            console.warn('Service worker lookup failed:', err);
        });
    }

    // 3. Selection Logic
    function updateSelectionUI() {
        if (selectedItems.length > 0) {
            selectionToolbar.style.display = 'flex';
            selectedCountSpan.innerText = selectedItems.length;
        } else {
            selectionToolbar.style.display = 'none';
        }

        // Update visual state of items
        document.querySelectorAll('.file-item').forEach(item => {
            const id = item.getAttribute('data-id');
            const type = itemTypeFromElement(item);
            const isSelected = selectionContains(id, type);
            item.classList.toggle('selected', isSelected);
            const cb = item.querySelector('.item-checkbox');
            if (cb) cb.checked = isSelected;
        });

        if (listSelectAll) {
            const visibleItems = Array.from(document.querySelectorAll('.file-item'))
                .filter(item => item.style.display !== 'none');
            const selectedVisibleCount = visibleItems.filter(item =>
                selectionContains(item.getAttribute('data-id'), itemTypeFromElement(item))
            ).length;
            listSelectAll.checked = visibleItems.length > 0 && selectedVisibleCount === visibleItems.length;
            listSelectAll.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < visibleItems.length;
            listSelectAll.disabled = visibleItems.length === 0;
        }

        if (bulkMakePublicBtn && bulkMakePrivateBtn) {
            const selectedFileElements = selectedItems
                .filter(item => item.type === 'file')
                .map(item => findItemElement(item))
                .filter(Boolean);
            const publicCount = selectedFileElements.filter(item => item.dataset.public === '1').length;
            const privateCount = selectedFileElements.length - publicCount;

            bulkMakePublicBtn.style.display = privateCount > 0 ? '' : 'none';
            bulkMakePrivateBtn.style.display = publicCount > 0 ? '' : 'none';
        }

        const toolbarActionState = [
            [bulkDownloadBtn, !isTrashView && !isGuestMode],
            [bulkLinksBtn, !isTrashView && !isGuestMode],
            [bulkCopyBtn, !isTrashView && !isGuestMode],
            [massRenameBtn, !isTrashView && !isGuestMode],
            [bulkMakePublicBtn, !isTrashView && !isGuestMode && bulkMakePublicBtn?.style.display !== 'none'],
            [bulkMakePrivateBtn, !isTrashView && !isGuestMode && bulkMakePrivateBtn?.style.display !== 'none'],
            [bulkTrashBtn, !isTrashView && !isGuestMode],
            [bulkDeleteBtn, !isGuestMode],
            [bulkMoveBtn, !isGuestMode],
            [selectAllBtn, true],
            [clearSelectionBtn, true],
        ];

        toolbarActionState.forEach(([button, visible]) => {
            if (button) {
                button.style.display = visible ? '' : 'none';
            }
        });
    }

    function baseUrl() {
        return String(fileManagerConfig.baseUrl || window.location.origin).replace(/\/$/, '');
    }

    function absoluteUrl(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        return baseUrl() + (path.startsWith('/') ? path : '/' + path);
    }

    function cssEscape(value) {
        if (window.CSS?.escape) {
            return CSS.escape(String(value));
        }

        return String(value).replace(/["\\]/g, '\\$&');
    }

    function selectedItemSnapshots() {
        return selectedItems
            .map(item => describeItemFromElement(findItemElement(item)))
            .filter(Boolean);
    }

    function thumbnailUrlForItem(item) {
        const element = findItemElement(item);
        const img = element?.querySelector('.file-preview img');
        return img?.src || '';
    }

    function bulkLinkFormatMeta(format) {
        switch (format) {
            case 'page':
                return {
                    label: 'Download page links',
                    note: 'These point to the public file or folder pages.',
                };
            case 'html':
                return {
                    label: 'HTML links',
                    note: 'These are ready to paste into HTML content.',
                };
            case 'bbcode':
                return {
                    label: 'BBCode links',
                    note: 'These are ready to paste into forum posts.',
                };
            case 'thumbs':
                return {
                    label: 'Image thumbnails',
                    note: 'Only image files with thumbnails generate embed code here.',
                };
            case 'grouped':
                return {
                    label: 'Grouped by folder',
                    note: 'Selected items are grouped under their parent folders.',
                };
            default:
                return {
                    label: 'Plain links',
                    note: 'Files use the normal /file/ link and eligible visitors auto-start. Folders are skipped in this format.',
                };
        }
    }

    async function renderBulkLinks(format = 'plain') {
        if (!bulkLinksOutput) return;
        const renderToken = ++bulkLinksRenderToken;
        const items = selectedItemSnapshots();
        const lines = [];
        const formatMeta = bulkLinkFormatMeta(format);

        if (bulkLinksFormatLabel) {
            bulkLinksFormatLabel.textContent = formatMeta.label;
        }
        if (bulkLinksActionsNote) {
            bulkLinksActionsNote.textContent = formatMeta.note;
        }
        bulkLinksOutput.value = 'Generating links...';

        if (format === 'grouped') {
            const groups = new Map();
            items.forEach(item => {
                const key = item.parentId || 'root';
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(item);
            });

            groups.forEach((group, parentId) => {
                const parentName = parentId === 'root'
                    ? 'Home'
                    : (document.querySelector(`.folder-item[data-id="${cssEscape(parentId)}"] .file-name`)?.innerText || `Folder ${parentId}`);
                lines.push(`[${parentName}]`);
                group.forEach(item => lines.push(`${item.name} - ${absoluteUrl(item.url)}`));
                lines.push('');
            });
        } else {
            for (const item of items) {
                let url = absoluteUrl(item.url);
                const safeName = item.name;
                if (format === 'plain' && item.type === 'file') {
                    url = absoluteUrl(item.url);
                } else if (format === 'plain' && item.type !== 'file') {
                    if (renderToken !== bulkLinksRenderToken) {
                        return;
                    }
                    continue;
                }
                if (format === 'html') {
                    lines.push(`<a href="${escapeHtml(url)}" target="_blank" rel="noopener">${escapeHtml(safeName)}</a>`);
                } else if (format === 'bbcode') {
                    lines.push(`[url=${url}]${safeName}[/url]`);
                } else if (format === 'thumbs') {
                    const thumb = thumbnailUrlForItem(item);
                    if (item.type === 'file' && thumb && String(item.mime || '').startsWith('image/')) {
                        lines.push(`<a href="${escapeHtml(url)}" target="_blank" rel="noopener"><img src="${escapeHtml(thumb)}" alt="${escapeHtml(safeName)}"></a>`);
                        lines.push(`[url=${url}][img]${thumb}[/img][/url]`);
                    }
                } else {
                    lines.push(url);
                }
                if (renderToken !== bulkLinksRenderToken) {
                    return;
                }
            }
        }

        if (renderToken !== bulkLinksRenderToken) {
            return;
        }
        bulkLinksOutput.value = lines.join('\n').trim();
    }

    function openBulkLinksModal() {
        if (selectedItems.length === 0) {
            showToast('Select at least one file or folder first.');
            return;
        }
        const itemLabel = selectedItems.length === 1 ? '1 item' : `${selectedItems.length} items`;
        if (bulkLinksSelectionCount) {
            bulkLinksSelectionCount.textContent = itemLabel;
        }
        if (bulkLinksSummary) {
            bulkLinksSummary.innerHTML = `You are exporting links for <strong>${escapeHtml(itemLabel)}</strong>. Choose a format on the left, then copy or export the result.`;
        }
        document.querySelectorAll('[data-link-format]').forEach(tab => tab.classList.remove('is-active'));
        document.querySelector('[data-link-format="plain"]')?.classList.add('is-active');
        renderBulkLinks('plain').catch(() => {
            bulkLinksOutput.value = '';
            alert('Could not generate bulk links.');
        });
        if (bulkLinksModal) bulkLinksModal.style.display = 'block';
    }

    function selectedRenameItems() {
        return selectedItemSnapshots().map(item => ({ id: item.id, type: item.type }));
    }

    function massRenamePayload(action) {
        const fd = new FormData();
        selectedRenameItems().forEach((it, idx) => {
            fd.append(`ids[${idx}][id]`, it.id);
            fd.append(`ids[${idx}][type]`, it.type);
        });
        fd.append('find', document.getElementById('renameFind')?.value || '');
        fd.append('replace', document.getElementById('renameReplace')?.value || '');
        fd.append('prefix', document.getElementById('renamePrefix')?.value || '');
        fd.append('suffix', document.getElementById('renameSuffix')?.value || '');
        fd.append('remove', document.getElementById('renameRemove')?.value || '');
        fd.append('separator', document.getElementById('renameSeparator')?.value || '');
        fd.append('sequence', document.getElementById('renameSequence')?.value || '');
        fd.append('start', document.getElementById('renameStart')?.value || '1');
        fd.append('regex', document.getElementById('renameRegex')?.checked ? '1' : '0');
        fd.append('action', action);
        fd.append('csrf_token', csrfToken);
        return fd;
    }

    async function previewMassRename() {
        if (selectedItems.length === 0) {
            showToast('Select at least one item to rename.');
            return;
        }
        const response = await fetch('/bulk/rename', { method: 'POST', body: massRenamePayload('preview') });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== 'success') {
            alert(payload.error || 'Could not preview rename.');
            return;
        }

        const rows = payload.preview || [];
        massRenamePreview.innerHTML = rows.length
            ? `<table><thead><tr><th>Current</th><th>New</th></tr></thead><tbody>${rows.map(row => `<tr><td>${escapeHtml(row.old_name)}</td><td>${escapeHtml(row.new_name)}</td></tr>`).join('')}</tbody></table>`
            : '<div class="rewards-empty-cell">No rename changes to apply.</div>';
        if (applyMassRenameBtn) applyMassRenameBtn.disabled = rows.length === 0;
    }

    async function applyMassRename() {
        const confirmed = window.confirm('Apply these rename changes now?');
        if (!confirmed) return;

        const response = await fetch('/bulk/rename', { method: 'POST', body: massRenamePayload('apply') });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== 'success') {
            alert(payload.error || 'Could not apply rename.');
            return;
        }
        showToast(`Renamed ${payload.updated || 0} item${payload.updated === 1 ? '' : 's'}.`);
        reloadWithState();
    }

    // Bulk Download Listener
    bulkDownloadBtn?.addEventListener('click', () => {
        const files = selectedItems.filter(i => i.type === 'file');
        if (files.length === 0) {
            alert('Please select at least one file to download.');
            return;
        }

        if (files.length === 1) {
            downloadSelectedFiles(files);
            return;
        }

        const confirmed = window.confirm(`Download ${files.length} files individually? Your browser may prompt for multiple downloads.`);
        if (confirmed) {
            downloadSelectedFiles(files);
        }
    });

    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('item-checkbox') && e.shiftKey) {
            e.preventDefault();
            const item = e.target.closest('.file-item');
            if (!item) {
                return;
            }
            const currentItem = {
                id: item.getAttribute('data-id'),
                type: e.target.getAttribute('data-type'),
            };
            setSelectionRange(currentItem, e.ctrlKey || e.metaKey);
        }
    });

    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('item-checkbox')) {
            const item = e.target.closest('.file-item');
            const id = item.getAttribute('data-id');
            const type = e.target.getAttribute('data-type');
            const currentItem = { id, type };
            if (e.target.checked) {
                if (!selectionContains(id, type)) {
                    selectedItems.push(currentItem);
                }
                rememberSelectionAnchor(currentItem);
            } else {
                selectedItems = selectedItems.filter(i => !sameItem(i, currentItem));
                if (selectionAnchor && sameItem(selectionAnchor, currentItem)) {
                    selectionAnchor = selectedItems.length > 0 ? { ...selectedItems[selectedItems.length - 1] } : null;
                }
            }
            updateSelectionUI();
        }
    });

    clearSelectionBtn?.addEventListener('click', () => {
        clearSelection();
    });

    fileGrid?.addEventListener('click', (e) => {
        if (selectedItems.length === 0) {
            return;
        }
        if (e.target !== fileGrid) {
            return;
        }
        clearSelection();
    });

    // select all - mirrors the Ctrl+A keyboard shortcut
    selectAllBtn?.addEventListener('click', () => {
        selectedItems = [];
        document.querySelectorAll('.file-item').forEach(item => {
                if (item.style.display === 'none') return;
                const id   = item.getAttribute('data-id');
                const type = itemTypeFromElement(item);
                selectedItems.push({ id, type });
            });
        rememberSelectionAnchor(selectedItems[0] || null);
        updateSelectionUI();
    });

    listSelectAll?.addEventListener('change', () => {
        if (listSelectAll.checked) {
            selectedItems = [];
            document.querySelectorAll('.file-item').forEach(item => {
                if (item.style.display === 'none') return;
                const id = item.getAttribute('data-id');
                const type = itemTypeFromElement(item);
                selectedItems.push({ id, type });
            });
            rememberSelectionAnchor(selectedItems[0] || null);
        } else {
            const visibleItems = Array.from(document.querySelectorAll('.file-item'))
                .filter(item => item.style.display !== 'none')
                .map(item => ({ id: item.getAttribute('data-id'), type: itemTypeFromElement(item) }));
            selectedItems = selectedItems.filter(item => !visibleItems.some(visibleItem => sameItem(visibleItem, item)));
            if (selectionAnchor && !selectionContains(selectionAnchor.id, selectionAnchor.type)) {
                selectionAnchor = selectedItems.length > 0 ? { ...selectedItems[selectedItems.length - 1] } : null;
            }
        }
        updateSelectionUI();
    });

    // bulk visibility - Make Public
    bulkMakePublicBtn?.addEventListener('click', async () => {
        const files = selectedItems.filter(i => i.type === 'file');
        if (files.length === 0) {
            showToast('Select at least one file to change visibility.');
            return;
        }
        const fd = new FormData();
        files.forEach((it, idx) => {
            fd.append(`ids[${idx}][id]`, it.id);
            fd.append(`ids[${idx}][type]`, it.type);
        });
        fd.append('visibility', 'public');
        fd.append('csrf_token', csrfToken);
        fetch('/bulk/visibility', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    files.forEach(it => {
                        updatePublicSwitchForItem(findItemElement({ id: it.id, type: 'file' }), true);
                    });
                    showToast(`${data.updated} file${data.updated === 1 ? '' : 's'} set to public.`);
                    applySearchFilter();
                } else {
                    alert(data.error || 'Failed to update visibility');
                }
            })
            .catch(() => alert('Network error updating visibility'));
    });

    // bulk visibility - Make Private
    bulkMakePrivateBtn?.addEventListener('click', async () => {
        const files = selectedItems.filter(i => i.type === 'file');
        if (files.length === 0) {
            showToast('Select at least one file to change visibility.');
            return;
        }
        const fd = new FormData();
        files.forEach((it, idx) => {
            fd.append(`ids[${idx}][id]`, it.id);
            fd.append(`ids[${idx}][type]`, it.type);
        });
        fd.append('visibility', 'private');
        fd.append('csrf_token', csrfToken);
        fetch('/bulk/visibility', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    files.forEach(it => {
                        updatePublicSwitchForItem(findItemElement({ id: it.id, type: 'file' }), false);
                    });
                    showToast(`${data.updated} file${data.updated === 1 ? '' : 's'} set to private.`);
                    applySearchFilter();
                } else {
                    alert(data.error || 'Failed to update visibility');
                }
            })
            .catch(() => alert('Network error updating visibility'));
    });

    // 4. Drag and Drop (External Uploads)
    if (dropZone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault(); e.stopPropagation();
            }, false);
        });

        dropZone.addEventListener('drop', async (e) => {
            const dt = e.dataTransfer;
            if ((dt?.items?.length || dt?.files?.length)) {
                const uploadItems = await extractDroppedUploadItems(dt);
                if (uploadItems.length > 0) {
                    await handleFiles(uploadItems);
                }
            }
        });

        dropZone.addEventListener('click', () => {
            pendingReplaceTarget = null;
            fileInput?.setAttribute('multiple', 'multiple');
            fileInput?.click();
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                const replaceOptions = pendingReplaceTarget
                    ? { replaceFileId: pendingReplaceTarget.id }
                    : {};
                handleFiles(fileInput.files, replaceOptions);
            }
            pendingReplaceTarget = null;
            fileInput.setAttribute('multiple', 'multiple');
        });
    }

    if (uploadBtn) {
        uploadBtn.addEventListener('click', () => {
            pendingReplaceTarget = null;
            fileInput?.setAttribute('multiple', 'multiple');
            fileInput?.click();
        });
    }

    const syncUploadAreaToggle = () => {
        if (!dashboardUploadCard || !toggleUploadAreaBtn) {
            return;
        }
        const collapsed = dashboardUploadCard.classList.contains('is-collapsed');
        toggleUploadAreaBtn.textContent = collapsed ? 'Show drag and drop area' : 'Hide drag and drop area';
        toggleUploadAreaBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    };

    syncUploadAreaToggle();

    toggleUploadAreaBtn?.addEventListener('click', () => {
        if (!dashboardUploadCard) return;
        const nextCollapsed = !dashboardUploadCard.classList.contains('is-collapsed');
        dashboardUploadCard.classList.toggle('is-collapsed', nextCollapsed);
        syncUploadAreaToggle();
    });

    window.addEventListener('dragenter', (event) => {
        if (!dashboardUploadCard || !dashboardUploadCard.classList.contains('is-collapsed')) {
            return;
        }
        const transfer = event.dataTransfer;
        if (!transfer || !Array.from(transfer.types || []).includes('Files')) {
            return;
        }
        dashboardUploadCard.classList.remove('is-collapsed');
        syncUploadAreaToggle();
    });

    setFilterCardCollapsed(true);
    toggleFilterCardBtn?.addEventListener('click', () => {
        if (!dashboardFilterCard) {
            return;
        }
        const nextCollapsed = !dashboardFilterCard.classList.contains('is-collapsed');
        setFilterCardCollapsed(nextCollapsed);
        savePageState();
    });
    if (toggleFilterCardBtn) {
        toggleFilterCardBtn.dataset.bound = '1';
    }

    // 5. Drag and Drop (Internal Movement - Use Delegation)
    fileGrid?.addEventListener('dragover', (e) => {
        const folder = e.target.closest('.folder-item');
        if (folder) {
            e.preventDefault();
            document.querySelectorAll('.folder-item').forEach(f => f.classList.remove('drag-over'));
            folder.classList.add('drag-over');
        }
    });

    fileGrid?.addEventListener('dragleave', (e) => {
        const folder = e.target.closest('.folder-item');
        if (folder) folder.classList.remove('drag-over');
    });

    fileGrid?.addEventListener('drop', (e) => {
        const folder = e.target.closest('.folder-item');
        if (folder) {
            e.preventDefault();
            folder.classList.remove('drag-over');
            const data = e.dataTransfer.getData('text/plain'); // Changed to text/plain
            if (data) {
                try {
                    const items = JSON.parse(data);
                    const targetId = folder.getAttribute('data-id');
                    if (items.some(i => i.id === targetId && i.type === 'folder')) return;
                    performBulkMove(items, targetId);
                } catch (err) {
                    console.error('Drop parse error:', err);
                }
            }
        }
    });

    document.querySelectorAll('.file-item').forEach(item => {
        item.addEventListener('dragstart', (e) => {
            const id = item.getAttribute('data-id');
            const type = itemTypeFromElement(item);

            if (!selectionContains(id, type)) {
                selectedItems = [{ id, type }];
                updateSelectionUI();
            }

            e.dataTransfer.setData('text/plain', JSON.stringify(selectedItems)); // Changed to text/plain
            e.dataTransfer.dropEffect = 'move'; // Added dropEffect
            e.dataTransfer.effectAllowed = 'move';
            item.style.opacity = '0.5';
            console.log('Drag started with data:', JSON.stringify(selectedItems)); // Added logging
        });

        item.addEventListener('dragend', () => {
            item.style.opacity = '1';
            document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
        });

        item.addEventListener('contextmenu', (e) => showContextMenu(e, item));

        // inline double-click rename on the file name label
        const nameEl = item.querySelector('.file-name');
        if (nameEl) {
            nameEl.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                const id   = item.getAttribute('data-id');
                const type = itemTypeFromElement(item);
                const currentName = nameEl.textContent.trim();

                const input = document.createElement('input');
                input.type  = 'text';
                input.value = currentName;
                input.className = 'fm-inline-rename';
                nameEl.replaceWith(input);
                input.focus();
                input.select();

                function commitRename() {
                    const newName = input.value.trim();
                    nameEl.textContent = newName || currentName;
                    nameEl.title = newName || currentName;
                    input.replaceWith(nameEl);

                    if (!newName || newName === currentName) return;

                    const fd = new FormData();
                    fd.append(type === 'file' ? 'id' : 'folder_id', id);
                    fd.append('name', newName);
                    fd.append('csrf_token', csrfToken);
                    fetch(type === 'file' ? '/file/rename' : '/folder/rename', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            if (data.status !== 'success') {
                                nameEl.textContent = currentName;
                                nameEl.title = currentName;
                                alert(data.error || 'Rename failed');
                            } else {
                                showToast(`Renamed to "${newName}"`);
                            }
                        })
                        .catch(() => {
                            nameEl.textContent = currentName;
                            nameEl.title = currentName;
                        });
                }

                input.addEventListener('blur', commitRename, { once: true });
                input.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter')  { ev.preventDefault(); input.blur(); }
                    if (ev.key === 'Escape') {
                        ev.preventDefault();
                        input.removeEventListener('blur', commitRename);
                        nameEl.textContent = currentName;
                        nameEl.title = currentName;
                        input.replaceWith(nameEl);
                    }
                });
            });
        }
    });


    document.querySelectorAll('.sidebar-trash-item').forEach(item => {
        item.addEventListener('contextmenu', (e) => showSidebarContextMenu(e));
    });

    // Sidebar Drop Targets (Delegation)
    const sidebar = document.querySelector('.fm-sidebar');
    sidebar?.addEventListener('dragover', (e) => {
        const li = e.target.closest('li');
        if (li) {
            const text = li.innerText.toLowerCase();
            if (text.includes('trash') || text.includes('all files')) {
                e.preventDefault();
                document.querySelectorAll('.fm-sidebar li').forEach(el => el.classList.remove('drag-over'));
                li.classList.add('drag-over');
            }
        }
    });

    sidebar?.addEventListener('drop', (e) => {
        const li = e.target.closest('li');
        if (li) {
            li.classList.remove('drag-over');
            const text = li.innerText.toLowerCase();
            const data = e.dataTransfer.getData('text/plain'); // Changed to text/plain
            if (data) {
                try {
                    const items = JSON.parse(data);
                    console.log('Sidebar drop items:', items);
                    if (text.includes('trash')) performBulkTrash(items);
                    else if (text.includes('all files')) performBulkMove(items, 'root');
                } catch (err) {
                    console.error('Sidebar drop parse error:', err);
                }
            }
        }
    });

    sidebar?.addEventListener('dragleave', (e) => {
        const li = e.target.closest('li');
        if (li) li.classList.remove('drag-over');
    });

    // 5.5 Keyboard Shortcuts
    window.addEventListener('keydown', (e) => {
        if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
            e.preventDefault();
            fmSearch?.focus();
            fmSearch?.select();
            return;
        }

        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

        if (e.key === 'Delete') {
            if (selectedItems.length > 0) {
                if (e.shiftKey) {
                    document.getElementById('bulkDeleteBtn')?.click();
                } else {
                    performBulkTrash(selectedItems);
                }
            }
        } else if (e.key === 'Escape') {
            selectedItems = [];
            updateSelectionUI();
            closeMobileSheet();
            if (shareModal) shareModal.style.display = 'none';
        } else if ((e.key === 'm' || e.key === 'M') && selectedItems.length > 0) {
            e.preventDefault();
            document.getElementById('bulkMoveBtn')?.click();
        } else if ((e.key === 'r' || e.key === 'R') && selectedItems.length === 1) {
            e.preventDefault();
            const selected = selectedItems[0];
            const itemEl = findItemElement(selected);
            performItemAction('rename', selected.id, selected.type, selected.name || '', itemEl);
        } else if (e.key === 'a' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            selectedItems = [];
            document.querySelectorAll('.file-item').forEach(item => {
                if (item.style.display === 'none') return;
                const id = item.getAttribute('data-id');
                const type = itemTypeFromElement(item);
                selectedItems.push({ id, type });
            });
            updateSelectionUI();
        }
    });

    // 5.6 Action Modal Helpers (Copy, Move, Tree)
    function showFolderTreeModal(title, onConfirm) {
        const moveModalTitle = moveModal?.querySelector('h3');
        const moveModalDescription = document.getElementById('moveModalDescription');
        const confirmMoveBtn = document.getElementById('confirmMoveBtn');
        const isCopyAction = String(title || '').toLowerCase().includes('copy');
        if (moveModalTitle) {
            moveModalTitle.innerText = title;
        }
        if (moveModalDescription) {
            moveModalDescription.innerText = isCopyAction
                ? 'Select destination folder for the copied items:'
                : 'Select destination folder:';
        }
        if (confirmMoveBtn) {
            confirmMoveBtn.innerText = isCopyAction ? 'Copy Here' : 'Move Here';
        }
        moveModal.style.display = 'block';
        loadFolderTree();

        // One-time handler for confirm button
        document.getElementById('confirmMoveBtn').onclick = () => {
            onConfirm(selectedTreeFolder);
            moveModal.style.display = 'none';
        };
    }

    function performBulkCopy(items, targetId) {
        const fd = new FormData();
        items.forEach((it, idx) => {
            fd.append(`ids[${idx}][id]`, it.id);
            fd.append(`ids[${idx}][type]`, it.type);
        });
        fd.append('target_folder_id', targetId);
        fd.append('csrf_token', csrfToken);

        fetch('/bulk/copy', { method: 'POST', body: fd })
            .then(r => r.json()).then(data => {
                if (data.status === 'success') reloadWithState();
                else alert(data.error || 'Failed to copy items');
            });
    }

    // 6. Bulk Action Requests
    async function performBulkTrash(items) {
        if (!await showActionModal('Move to Trash', `Move ${items.length} items to trash?`)) return;
        const snapshot = collectSnapshot(items);

        const fd = new FormData();
        items.forEach((it, idx) => {
            fd.append(`ids[${idx}][id]`, it.id);
            fd.append(`ids[${idx}][type]`, it.type);
        });
        fd.append('csrf_token', csrfToken);

        fetch('/bulk/trash', { method: 'POST', body: fd })
            .then(r => r.json()).then(data => {
                if (data.status === 'success') {
                    removeItemsFromView(snapshot);
                    const refreshTimeout = setTimeout(() => reloadWithState(), 5200);
                    showToast(`${items.length} item${items.length === 1 ? '' : 's'} moved to trash.`, [
                        {
                            label: 'Undo',
                            onClick: () => {
                                clearTimeout(refreshTimeout);
                                const restoreFd = new FormData();
                                snapshot.forEach((it, idx) => {
                                    restoreFd.append(`ids[${idx}][id]`, it.id);
                                    restoreFd.append(`ids[${idx}][type]`, it.type);
                                });
                                restoreFd.append('csrf_token', csrfToken);
                                fetch('/bulk/restore', { method: 'POST', body: restoreFd })
                                    .then(resp => resp.json())
                                    .then(resp => {
                                        if (resp.status === 'success') reloadWithState();
                                        else alert(resp.error || 'Failed to restore items');
                                    });
                            }
                        }
                    ]);
                }
                else alert(data.error || 'Failed to move items to trash');
            }).catch(err => {
                console.error('Trash fetch error:', err);
                alert('Network error or server failed to respond properly.');
            });
    }

    function performBulkMove(items, targetId) {
        if (!targetId) return;
        const snapshot = collectSnapshot(items);
        const fd = new FormData();
        items.forEach((it, idx) => {
            fd.append(`ids[${idx}][id]`, it.id);
            fd.append(`ids[${idx}][type]`, it.type);
        });
        fd.append('target_folder_id', targetId);
        fd.append('csrf_token', csrfToken);

        fetch('/bulk/move', { method: 'POST', body: fd })
            .then(r => r.json()).then(data => {
                if (data.status === 'success') {
                    removeItemsFromView(snapshot);
                    const refreshTimeout = setTimeout(() => reloadWithState(), 5200);
                    showToast(`${items.length} item${items.length === 1 ? '' : 's'} moved.`, [
                        {
                            label: 'Undo',
                            onClick: () => {
                                clearTimeout(refreshTimeout);
                                const groupedByParent = new Map();
                                snapshot.forEach(item => {
                                    const key = item.parentId || 'root';
                                    if (!groupedByParent.has(key)) {
                                        groupedByParent.set(key, []);
                                    }
                                    groupedByParent.get(key).push(item);
                                });

                                const restores = Array.from(groupedByParent.entries()).map(([parentId, group]) => {
                                    const moveFd = new FormData();
                                    group.forEach((it, idx) => {
                                        moveFd.append(`ids[${idx}][id]`, it.id);
                                        moveFd.append(`ids[${idx}][type]`, it.type);
                                    });
                                    moveFd.append('target_folder_id', parentId);
                                    moveFd.append('csrf_token', csrfToken);
                                    return fetch('/bulk/move', { method: 'POST', body: moveFd }).then(resp => resp.json());
                                });

                                Promise.all(restores).then(() => reloadWithState());
                            }
                        }
                    ]);
                }
                else alert(data.error || 'Failed to move items');
            }).catch(err => {
                console.error('Move fetch error:', err);
                alert('Network error or server failed to respond properly.');
            });
    }

    bulkDeleteBtn?.addEventListener('click', async () => {
        if (!await showActionModal('Permanent Delete', `PERMANENTLY delete ${selectedItems.length} items? This cannot be undone.`)) return;
        const fd = new FormData();
        selectedItems.forEach((it, idx) => {
            fd.append(`ids[${idx}][id]`, it.id);
            fd.append(`ids[${idx}][type]`, it.type);
        });
        fd.append('csrf_token', csrfToken);

        fetch('/bulk/delete', { method: 'POST', body: fd })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (err) {
                    console.error('Server returned non-JSON:', text);
                    throw new Error('Server returned an invalid response. Check logs.');
                }
            })
            .then(data => {
                if (data.status === 'success') reloadWithState();
                else alert(data.error || 'Failed to delete items');
            })
            .catch(err => {
                console.error('Delete fetch error:', err);
                alert(err.message || 'Network error occurred.');
            });
    });

    bulkTrashBtn?.addEventListener('click', async () => {
        if (selectedItems.length === 0) {
            return;
        }
        await performBulkTrash(selectedItems);
    });

    // 7. Search & View Toggle
    fmSearch?.addEventListener('input', () => applySearchFilter());
    fmTypeFilter?.addEventListener('change', () => applySearchFilter());
    fmVisibilityFilter?.addEventListener('change', () => applySearchFilter());
    fmStatusFilter?.addEventListener('change', () => applySearchFilter());
    fmSort?.addEventListener('change', () => applySearchFilter());
    fmClearFiltersBtn?.addEventListener('click', () => {
        if (fmSearch) fmSearch.value = '';
        if (fmTypeFilter) fmTypeFilter.value = 'all';
        if (fmVisibilityFilter) fmVisibilityFilter.value = 'all';
        if (fmStatusFilter) fmStatusFilter.value = 'all';
        if (fmSort) fmSort.value = 'newest';
        setFilterCardCollapsed(true);
        applySearchFilter();
    });
    fmResetWorkspaceBtn?.addEventListener('click', () => {
        if (fmSearch) fmSearch.value = '';
        if (fmTypeFilter) fmTypeFilter.value = 'all';
        if (fmVisibilityFilter) fmVisibilityFilter.value = 'all';
        if (fmStatusFilter) fmStatusFilter.value = 'all';
        if (fmSort) fmSort.value = 'newest';
        setFilterCardCollapsed(true);
        setFileManagerView('list');
        applySearchFilter();
        clearSelection();
    });
    listSortButtons.forEach(button => {
        button.addEventListener('click', () => {
            if (!fmSort) {
                return;
            }

            const primarySort = button.getAttribute('data-list-sort') || 'newest';
            const altSort = button.getAttribute('data-list-sort-alt') || '';
            fmSort.value = altSort && fmSort.value === primarySort ? altSort : primarySort;
            applySearchFilter();
        });
    });

    function setFileManagerView(mode) {
        if (!fileGrid) {
            return;
        }

        const normalizedMode = mode === 'list' ? 'list' : 'grid';
        fileGrid.classList.toggle('list-view', normalizedMode === 'list');

        try {
            localStorage.setItem('fm_view', normalizedMode);
        } catch (e) {
        }

        if (viewToggle) {
            const nextMode = normalizedMode === 'list' ? 'grid' : 'list';
            viewToggle.innerText = nextMode === 'grid' ? 'Grid View' : 'List View';
            viewToggle.setAttribute('data-current-view', normalizedMode);
            viewToggle.setAttribute('aria-label', `Switch to ${nextMode} view`);
            viewToggle.setAttribute('title', `Switch to ${nextMode} view`);
        }
    }

    viewToggle?.addEventListener('click', () => {
        const currentMode = fileGrid?.classList.contains('list-view') ? 'list' : 'grid';
        setFileManagerView(currentMode === 'list' ? 'grid' : 'list');
    });

    // Restore view preference
    let savedView = 'list';
    try {
        const storedView = localStorage.getItem('fm_view');
        savedView = storedView === 'grid' ? 'grid' : 'list';
    } catch (e) {
    }
    setFileManagerView(savedView);

    restorePageState();
    consumeFlashToast();
    applySearchFilter();

    // 8. Move Modal Navigation
    const moveModal = document.getElementById('moveModal');
    const folderTree = document.getElementById('folderTree');

    bulkMoveBtn?.addEventListener('click', () => {
        if (selectedItems.length === 0) return;
        showFolderTreeModal('Move to...', (targetId) => {
            performBulkMove(selectedItems, targetId);
        });
    });

    bulkCopyBtn?.addEventListener('click', () => {
        if (selectedItems.length === 0) return;
        showFolderTreeModal('Copy to...', (targetId) => {
            performBulkCopy(selectedItems, targetId);
        });
    });

    document.getElementById('cancelMoveBtn')?.addEventListener('click', () => {
        moveModal.style.display = 'none';
    });

    let selectedTreeFolder = 'root';

    function loadFolderTree() {
        folderTree.innerHTML = '<div class="folder-tree-item selected" data-id="root">&#128193; Home (Root)</div>';
        selectedTreeFolder = 'root';

        fetch('/folders/json')
            .then(r => r.json())
            .then(folders => {
                folders.forEach(f => {
                    // Don't show folders that are currently selected for moving (prevent infinite loop)
                    if (selectedItems.some(si => si.id == f.id && si.type === 'folder')) return;

                    const div = document.createElement('div');
                    div.className = 'folder-tree-item';
                    div.setAttribute('data-id', f.id);
                    div.innerHTML = '&#128193; ' + escapeHtml(f.name);
                    folderTree.appendChild(div);
                });
            });
    }

    folderTree?.addEventListener('click', (e) => {
        const item = e.target.closest('.folder-tree-item');
        if (item) {
            document.querySelectorAll('.folder-tree-item').forEach(i => i.classList.remove('selected'));
            item.classList.add('selected');
            selectedTreeFolder = item.getAttribute('data-id');
        }
    });

    // The confirmMoveBtn click handler is now set dynamically in showFolderTreeModal
    // document.getElementById('confirmMoveBtn')?.addEventListener('click', () => {
    //     performBulkMove(selectedItems, selectedTreeFolder);
    // });

    // 9. Original Single-Action Handlers (Still useful)
    document.addEventListener('click', async (e) => {
        // Folder Navigation
        const folderItem = e.target.closest('.folder-item');
        if (folderItem && !e.target.closest('.file-hover-controls') && !e.target.closest('.file-select') && !e.target.closest('.file-list-actions')) {
            const id = String(folderItem.getAttribute('data-id') || '').trim();
            if (!/^\d+$/.test(id)) {
                return;
            }
            openFolderView(id);
            return;
        }

        const renameItem = e.target.closest('.rename-item');
        if (renameItem) {
            e.preventDefault();
            e.stopPropagation();
            const item = renameItem.closest('.file-item');
            if (!item) return;
            const id = item.getAttribute('data-id');
            const type = itemTypeFromElement(item);
            const name = item.querySelector('.file-name')?.innerText.trim() || '';
            await performItemAction('rename', id, type, name, item);
            return;
        }

        const visibilityToggle = e.target.closest('[data-visibility-toggle]');
        if (visibilityToggle) {
            e.preventDefault();
            e.stopPropagation();
            const item = visibilityToggle.closest('.file-item');
            if (!item || item.dataset.kind !== 'file') return;
            const id = item.getAttribute('data-id');
            const makePublic = item.dataset.public !== '1';
            const fd = new FormData();
            fd.append('ids[0][id]', id);
            fd.append('ids[0][type]', 'file');
            fd.append('visibility', makePublic ? 'public' : 'private');
            fd.append('csrf_token', csrfToken);
            fetch('/bulk/visibility', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        updatePublicSwitchForItem(item, makePublic);
                        showToast(`File set to ${makePublic ? 'public' : 'private'}.`);
                        applySearchFilter();
                    } else {
                        alert(data.error || 'Failed to update visibility');
                    }
                })
                .catch(() => alert('Network error updating visibility'));
            return;
        }

        // Trash Individual
        const delFile = e.target.closest('.delete-file');
        const delFolder = e.target.closest('.delete-folder');
        if (delFile || delFolder) {
            e.stopPropagation();
            const type = delFile ? 'file' : 'folder';
            const id = e.target.closest('.file-item').getAttribute('data-id');
            await performBulkTrash([{ id, type }]);
        }

        // New Folder
        const newFolderBtn = e.target.closest('#newFolderBtn');
        if (newFolderBtn) {
            const name = await showActionModal('New Folder', 'Enter folder name:', 'New Folder', true);
            if (name) {
                const fd = new FormData();
                fd.append('name', name);
                const cur = document.getElementById('currentFolderId')?.value;
                if (cur) fd.append('parent_id', cur);
                fd.append('csrf_token', csrfToken);
                fetch('/folder/create', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            reloadWithState();
                            return;
                        }
                        alert(data.error || 'Failed to create folder');
                    })
                    .catch(() => alert('Failed to create folder'));
            }
        }

        // Remote URL Upload
        const remoteUploadBtn = e.target.closest('#remoteUploadBtn');
        if (remoteUploadBtn) {
            const url = await showActionModal('Remote Upload', 'Enter File URL:', '', true);
            if (url) {
                const fd = new FormData();
                fd.append('url', url);
                const cur = document.getElementById('currentFolderId')?.value;
                if (cur) fd.append('folder_id', cur);
                fd.append('csrf_token', csrfToken); // Add CSRF

                const controller = new AbortController();
                activeXhrs['remote_sync'] = controller;

                // Show progress bar
                updateGlobalProgress(50, "Remote URL...");
                if (progressContainer) progressContainer.style.display = showUploadPopup ? 'block' : 'none';

                fetch('/upload/remote', {
                    method: 'POST',
                    body: fd,
                    signal: controller.signal
                })
                .then(r => r.json())
                .then(data => {
                    delete activeXhrs['remote_sync'];
                    if (progressContainer) progressContainer.style.display = 'none';
                    if (data.success || data.status === 'success') {
                        alert(data.message || 'Remote upload started!');
                        reloadWithState();
                    } else {
                        alert(data.error || 'Failed to start remote upload');
                    }
                })
                .catch(err => {
                    delete activeXhrs['remote_sync'];
                    if (progressContainer) progressContainer.style.display = 'none';
                    if (err.name !== 'AbortError') {
                        alert('Remote upload error or aborted.');
                    }
                });
            }
        }

        // Empty Trash (Handles both sidebar and toolbar)
        const emptyTrashBtn = e.target.closest('.empty-trash-btn');
        if (emptyTrashBtn) {
            if (await showActionModal('Empty Trash', 'Are you sure you want to PERMANENTLY delete ALL items in the trash?')) {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fetch('/trash/empty', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success || data.status === 'success') reloadWithState();
                        else alert(data.error || 'Failed to empty trash');
                    });
            }
        }
    });

    // 10. Multipart Upload Logic
    let uploadQueue = [];
    let activeUploads = 0;
    let failedUploads = 0;
    let cancelRequested = false;
    let activeXhrs = {};
    const activeUploadsMap = new Map();
    const uploadTaskRegistry = new Map();
    const uploadStateKey = 'fyuhls.multipart.uploads';
    const showUploadPopup = window.UPLOAD_CONFIG?.hidePopup !== true;
    const cfg = {
        concurrent: window.UPLOAD_CONFIG?.concurrent ? (window.UPLOAD_CONFIG?.concurrentLimit || 2) : 1,
        partConcurrency: window.UPLOAD_CONFIG?.partConcurrency || 2,
        maxPartRetries: window.UPLOAD_CONFIG?.maxPartRetries || 3,
    };

    function getPartConcurrency(session) {
        const provider = String(session?.storage_provider || '').toLowerCase();
        // Backblaze B2 is more reliable with sequential browser part uploads.
        if (provider === 'b2' || provider === 'backblaze' || provider === 'local') {
            return 1;
        }

        return Math.max(1, cfg.partConcurrency || 1);
    }

    function setTaskStatus(task, status, detail = '') {
        task.status = status;
        task.statusDetail = detail;
        renderUploadTask(task);
        updateUploadPanel();
    }

    function progressTextForTask(task) {
        if (task.status === 'completed') {
            return 'Ready';
        }
        if (task.status === 'failed') {
            return task.statusDetail || 'Failed';
        }
        if (task.status === 'paused') {
            return 'Paused';
        }
        if (task.status === 'canceled') {
            return 'Canceled';
        }
        if (task.status === 'finalizing') {
            return 'Finalizing';
        }
        if (task.status === 'queued') {
            return 'Queued';
        }
        return task.statusDetail || 'Uploading';
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function getDedupedVisualDurationMs(fileSize) {
        const bytesPerWindow = 500 * 1024 * 1024;
        const windows = Math.max(1, Number(fileSize || 0) / bytesPerWindow);
        return Math.max(1800, Math.round(windows * 7000));
    }

    async function runDedupedUploadPresentation(task) {
        const totalBytes = Number(task.progress.totalBytes || task.file.size || 0);
        const durationMs = getDedupedVisualDurationMs(totalBytes);
        const startedAt = performance.now();

        while (true) {
            if (task.canceled || task.paused) {
                return false;
            }

            const elapsed = performance.now() - startedAt;
            const ratio = Math.min(1, elapsed / durationMs);

            if (ratio < 0.18) {
                setTaskStatus(task, 'starting', 'Preparing upload');
            } else if (ratio < 0.38) {
                setTaskStatus(task, 'starting', 'Checking file');
            } else if (ratio < 0.9) {
                setTaskStatus(task, 'uploading', 'Uploading parts');
            } else {
                setTaskStatus(task, 'finalizing', 'Completing upload');
            }

            task.progress.completedBytes = 0;
            task.progress.loadedBytes = Math.min(totalBytes, Math.round(totalBytes * ratio));
            renderUploadTask(task);
            updateGlobalProgress();

            if (ratio >= 1) {
                break;
            }

            await sleep(150);
        }

        task.progress.completedBytes = totalBytes;
        task.progress.loadedBytes = totalBytes;
        setTaskStatus(task, 'completed', 'Ready');
        if (task.replaceFileId) {
            queueFlashToast('Replaced file in place.');
        }
        updateGlobalProgress();
        return true;
    }

    function ensureTaskRow(task) {
        if (!uploadQueueList) {
            return null;
        }

        let row = uploadQueueList.querySelector(`[data-task-id="${task.id}"]`);
        if (row) {
            return row;
        }

        row = document.createElement('div');
        row.className = 'upload-task-row';
        row.setAttribute('data-task-id', task.id);
        row.innerHTML = `
            <div class="upload-task-header">
                <div class="upload-task-file">
                    <div class="upload-task-name"></div>
                    <div class="upload-task-meta"></div>
                </div>
                <div class="upload-task-actions">
                    <button type="button" class="upload-task-btn" data-upload-action="pause">Pause</button>
                    <button type="button" class="upload-task-btn" data-upload-action="resume">Resume</button>
                    <button type="button" class="upload-task-btn" data-upload-action="retry">Retry</button>
                    <button type="button" class="upload-task-btn danger" data-upload-action="cancel">Cancel</button>
                </div>
            </div>
            <div class="upload-task-progress">
                <div class="upload-task-progress-fill"></div>
            </div>
        `;
        uploadQueueList.prepend(row);
        return row;
    }

    function renderUploadTask(task) {
        const row = ensureTaskRow(task);
        if (!row) {
            return;
        }

        const percent = task.progress?.totalBytes > 0
            ? Math.min(100, Math.round(((task.progress.loadedBytes || 0) / task.progress.totalBytes) * 100))
            : 0;
        row.querySelector('.upload-task-name').textContent = task.file.name;
        row.querySelector('.upload-task-meta').textContent = `${progressTextForTask(task)} • ${formatBytes(task.progress.loadedBytes || 0)} / ${formatBytes(task.progress.totalBytes || task.file.size)} • ${percent}%`;
        row.querySelector('.upload-task-progress-fill').style.width = `${percent}%`;
        row.setAttribute('data-status', task.status || 'queued');

        const pauseBtn = row.querySelector('[data-upload-action="pause"]');
        const resumeBtn = row.querySelector('[data-upload-action="resume"]');
        const retryBtn = row.querySelector('[data-upload-action="retry"]');
        const cancelBtn = row.querySelector('[data-upload-action="cancel"]');
        const active = ['queued', 'starting', 'uploading', 'finalizing'].includes(task.status);
        const paused = task.status === 'paused';
        const failed = task.status === 'failed';
        const completed = task.status === 'completed';

        pauseBtn.hidden = !active || task.status === 'finalizing';
        resumeBtn.hidden = !paused;
        retryBtn.hidden = !failed;
        cancelBtn.hidden = completed || task.status === 'canceled';
    }

    function updateUploadPanel() {
        const tasks = Array.from(uploadTaskRegistry.values());
        if (!progressContainer || tasks.length === 0) {
            if (progressContainer) {
                progressContainer.style.display = 'none';
            }
            return;
        }

        progressContainer.style.display = showUploadPopup ? 'block' : 'none';

        let loadedBytes = 0;
        let totalBytes = 0;
        let activeCount = 0;
        let queuedCount = 0;
        let failedCount = 0;
        let pausedCount = 0;

        tasks.forEach(task => {
            totalBytes += Number(task.progress?.totalBytes || task.file.size || 0);
            loadedBytes += Number(task.progress?.loadedBytes || 0);

            if (['starting', 'uploading', 'finalizing'].includes(task.status)) activeCount++;
            if (task.status === 'queued') queuedCount++;
            if (task.status === 'failed') failedCount++;
            if (task.status === 'paused') pausedCount++;
        });

        const overallPercent = totalBytes > 0 ? Math.min(100, Math.round((loadedBytes / totalBytes) * 100)) : 0;
        if (progressFill) progressFill.style.width = `${overallPercent}%`;
        if (progressPercent) progressPercent.innerText = `${overallPercent}%`;
        if (progressText) {
            progressText.innerText = activeCount > 0
                ? `${activeCount} upload${activeCount === 1 ? '' : 's'} running`
                : pausedCount > 0
                    ? `${pausedCount} upload${pausedCount === 1 ? '' : 's'} paused`
                    : failedCount > 0
                        ? `${failedCount} upload${failedCount === 1 ? '' : 's'} need attention`
                        : 'Uploads ready';
        }
        if (uploadQueueStats) {
            uploadQueueStats.innerText = `${tasks.length} total • ${queuedCount} queued • ${failedCount} failed • ${pausedCount} paused`;
        }
    }

    function registerTask(task) {
        uploadTaskRegistry.set(task.id, task);
        renderUploadTask(task);
        updateUploadPanel();
    }

    function dropTask(taskId) {
        uploadTaskRegistry.delete(taskId);
        uploadQueueList?.querySelector(`[data-task-id="${taskId}"]`)?.remove();
        updateUploadPanel();
    }

    function enqueueTask(task, prioritize = false) {
        task.canceled = false;
        task.paused = false;
        if (!uploadTaskRegistry.has(task.id)) {
            registerTask(task);
        } else {
            renderUploadTask(task);
        }

        setTaskStatus(task, 'queued', 'Waiting for a slot');
        if (!uploadQueue.some(candidate => candidate.id === task.id) && !activeUploadsMap.has(task.id)) {
            if (prioritize) {
                uploadQueue.unshift(task);
            } else {
                uploadQueue.push(task);
            }
        }
        processQueue();
    }

    function pauseTask(taskId) {
        const task = uploadTaskRegistry.get(taskId);
        if (!task || !activeUploadsMap.has(taskId)) {
            return;
        }

        task.paused = true;
        task.xhrs.forEach(xhr => {
            try { xhr.abort(); } catch (err) {}
        });
        setTaskStatus(task, 'paused', 'Paused');
    }

    function resumeTask(taskId) {
        const task = uploadTaskRegistry.get(taskId);
        if (!task || task.status !== 'paused') {
            return;
        }
        enqueueTask(task, true);
    }

    function retryTask(taskId) {
        const task = uploadTaskRegistry.get(taskId);
        if (!task || task.status !== 'failed') {
            return;
        }
        enqueueTask(task, true);
    }

    async function cancelTask(taskId, abortRemote = true) {
        const task = uploadTaskRegistry.get(taskId);
        if (!task) {
            return;
        }

        task.canceled = true;
        task.paused = false;
        uploadQueue = uploadQueue.filter(candidate => candidate.id !== taskId);
        task.xhrs.forEach(xhr => {
            try { xhr.abort(); } catch (err) {}
        });

        if (abortRemote && task.sessionId) {
            try {
                await fetch(`/api/v1/uploads/sessions/${encodeURIComponent(task.sessionId)}/abort`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: '{}',
                });
            } catch (err) {
            }
        }

        clearTaskState(task.id);
        setTaskStatus(task, 'canceled', 'Canceled');
        setTimeout(() => dropTask(task.id), 800);
    }

    uploadQueueList?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-upload-action]');
        const row = event.target.closest('.upload-task-row');
        if (!button || !row) {
            return;
        }

        const taskId = row.getAttribute('data-task-id');
        const action = button.getAttribute('data-upload-action');
        if (action === 'pause') {
            pauseTask(taskId);
        } else if (action === 'resume') {
            resumeTask(taskId);
        } else if (action === 'retry') {
            retryTask(taskId);
        } else if (action === 'cancel') {
            cancelTask(taskId);
        }
    });

    function currentFolderId() {
        const cur = document.getElementById('currentFolderId')?.value;
        return cur ? Number(cur) : null;
    }

    function folderCacheKey(parentId, name) {
        return `${parentId === null || parentId === undefined || parentId === '' ? 'root' : String(parentId)}::${String(name)}`;
    }

    async function ensureFolderIndexLoaded() {
        if (folderIndexLoaded || isGuestMode) {
            return;
        }

        const folders = await fetch('/folders/json', { credentials: 'same-origin' }).then(r => r.json()).catch(() => []);
        folderIndexCache.clear();
        if (Array.isArray(folders)) {
            folders.forEach(folder => {
                const name = String(folder?.name || '').trim();
                if (name === '') {
                    return;
                }
                const parentId = folder?.parent_id ?? null;
                folderIndexCache.set(folderCacheKey(parentId, name), Number(folder.id));
            });
        }
        folderIndexLoaded = true;
    }

    async function ensureFolderExists(name, parentId) {
        const normalized = String(name || '').trim();
        if (normalized === '') {
            return parentId ?? null;
        }

        await ensureFolderIndexLoaded();
        const cacheKey = folderCacheKey(parentId ?? null, normalized);
        if (folderIndexCache.has(cacheKey)) {
            return folderIndexCache.get(cacheKey);
        }

        const fd = new FormData();
        fd.append('name', normalized);
        if (parentId !== null && parentId !== undefined && parentId !== '') {
            fd.append('parent_id', String(parentId));
        }
        fd.append('csrf_token', csrfToken);

        const response = await fetch('/folder/create', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== 'success' || !payload.folder_id) {
            throw new Error(payload.error || `Could not create folder "${normalized}".`);
        }

        const folderId = Number(payload.folder_id);
        folderIndexCache.set(cacheKey, folderId);
        upsertFolderInView({
            id: folderId,
            parent_id: parentId ?? null,
            name: normalized,
            status: 'active',
            created_at: new Date().toISOString(),
            file_count: 0,
            total_size: 0,
        });
        return folderId;
    }

    async function ensureFolderPath(relativeSegments = [], baseParentId = currentFolderId()) {
        if (!Array.isArray(relativeSegments) || relativeSegments.length === 0) {
            return baseParentId ?? null;
        }
        if (isGuestMode) {
            throw new Error('Folder uploads require a signed-in account.');
        }

        let parentId = baseParentId ?? null;
        for (const segment of relativeSegments) {
            parentId = await ensureFolderExists(segment, parentId);
        }
        return parentId;
    }

    function isSkippableUploadError(error) {
        const message = String(error?.message || '').toLowerCase();
        return message.includes('file type') && message.includes('not allowed');
    }

    function readEntryFile(entry) {
        return new Promise((resolve, reject) => {
            entry.file(resolve, reject);
        });
    }

    function readDirectoryEntries(reader) {
        return new Promise((resolve, reject) => {
            const allEntries = [];
            const readBatch = () => {
                reader.readEntries(entries => {
                    if (!entries || entries.length === 0) {
                        resolve(allEntries);
                        return;
                    }
                    allEntries.push(...entries);
                    readBatch();
                }, reject);
            };
            readBatch();
        });
    }

    async function collectDroppedEntryFiles(entry, parentSegments = []) {
        if (!entry) {
            return [];
        }

        if (entry.isFile) {
            const file = await readEntryFile(entry);
            return [{ file, relativeFolderSegments: parentSegments }];
        }

        if (!entry.isDirectory) {
            return [];
        }

        const reader = entry.createReader();
        const entries = await readDirectoryEntries(reader);
        if (entries.length === 0) {
            return [{
                file: null,
                folderOnly: true,
                relativeFolderSegments: [...parentSegments, entry.name],
            }];
        }
        const nested = await Promise.all(entries.map(child =>
            collectDroppedEntryFiles(child, [...parentSegments, entry.name])
        ));
        return nested.flat();
    }

    async function normalizeUploadItems(input) {
        if (!input) {
            return [];
        }

        if (Array.isArray(input)) {
            return input;
        }

        return Array.from(input).map(file => {
            const relativePath = String(file.webkitRelativePath || '');
            const segments = relativePath ? relativePath.split('/').filter(Boolean).slice(0, -1) : [];
            return { file, folderOnly: false, relativeFolderSegments: segments };
        });
    }

    async function extractDroppedUploadItems(dataTransfer) {
        const items = Array.from(dataTransfer?.items || []);
        const entryItems = items
            .map(item => (typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null))
            .filter(Boolean);

        if (entryItems.length === 0) {
            return normalizeUploadItems(dataTransfer?.files || []);
        }

        const nested = await Promise.all(entryItems.map(entry => collectDroppedEntryFiles(entry, [])));
        const files = nested.flat();
        return files.length > 0 ? files : normalizeUploadItems(dataTransfer?.files || []);
    }

    function readUploadState() {
        try {
            const raw = localStorage.getItem(uploadStateKey);
            const parsed = raw ? JSON.parse(raw) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (err) {
            return {};
        }
    }

    function writeUploadState(state) {
        localStorage.setItem(uploadStateKey, JSON.stringify(state));
    }

    async function abortSavedUploadState(taskId) {
        const state = readUploadState();
        const item = state[taskId];
        if (!item) {
            renderResumeNotice();
            return;
        }

        try {
            if (item.sessionId) {
                await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(item.sessionId)}/abort`, {
                    method: 'POST',
                });
            }
        } catch (err) {
            console.warn('Could not abort saved multipart session:', err);
        } finally {
            delete state[taskId];
            writeUploadState(state);
            renderResumeNotice();
        }
    }

    async function abortAllSavedUploadStates() {
        const state = readUploadState();
        const entries = Object.entries(state).filter(([, item]) => item?.sessionId);
        if (entries.length === 0) {
            renderResumeNotice();
            return;
        }

        await Promise.all(entries.map(async ([taskId, item]) => {
            try {
                await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(item.sessionId)}/abort`, {
                    method: 'POST',
                });
            } catch (err) {
                console.warn('Could not abort saved multipart session:', err);
            }
        }));

        writeUploadState({});
        renderResumeNotice();
    }

    function getInterruptedUploadStates() {
        const savedStates = Object.values(readUploadState()).filter(item => item.sessionId);
        return savedStates.filter(item => {
            const liveTask = uploadTaskRegistry.get(item.id);
            if (!liveTask) {
                return true;
            }

            return ['completed', 'canceled'].includes(liveTask.status);
        });
    }

    function renderResumeNotice() {
        const existing = document.getElementById('resumeNotice');
        if (existing) existing.remove();

        const resumable = getInterruptedUploadStates();
        if (!dropZone || resumable.length === 0) return;

        const wrap = document.createElement('div');
        wrap.id = 'resumeNotice';
        wrap.className = 'resume-notice';
        const extraCount = Math.max(0, resumable.length - 3);
        wrap.innerHTML = `
            <div style="display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap;">
                <div>
                    <div class="resume-notice-title">Interrupted uploads found</div>
                    <div class="resume-notice-copy">Select the same file again to resume its multipart session, or cancel it here if you want to discard it.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger" data-resume-notice-action="cancel-all">Clear All</button>
            </div>
            <div class="resume-notice-list">${resumable.slice(0, 3).map(item => `
                <span style="display:inline-flex; align-items:center; gap:0.5rem;">
                    <span>${escapeHtml(item.name)}</span>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-resume-notice-action="cancel-item" data-upload-task-id="${escapeHtml(item.id)}">Cancel</button>
                </span>
            `).join('')}</div>
            ${extraCount > 0 ? `<div class="resume-notice-copy" style="margin-top:0.75rem;">${extraCount} more interrupted upload${extraCount === 1 ? '' : 's'} hidden from this summary.</div>` : ''}
        `;
        dropZone.parentNode.insertBefore(wrap, dropZone);
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-resume-notice-action]');
        if (!button) {
            return;
        }

        const action = button.getAttribute('data-resume-notice-action');
        if (action === 'cancel-item') {
            const taskId = button.getAttribute('data-upload-task-id');
            if (!taskId) {
                return;
            }

            button.disabled = true;
            button.textContent = 'Canceling...';
            await abortSavedUploadState(taskId);
            return;
        }

        if (action === 'cancel-all') {
            if (!confirm('Cancel all interrupted uploads and discard their saved multipart sessions?')) {
                return;
            }

            button.disabled = true;
            button.textContent = 'Clearing...';
            await abortAllSavedUploadStates();
        }
    });

    function saveTaskState(task, extra = {}) {
        const state = readUploadState();
        state[task.id] = {
            id: task.id,
            name: task.file.name,
            size: task.file.size,
            type: task.file.type || 'application/octet-stream',
            sessionId: task.sessionId || null,
            folderId: task.folderIdOverride ?? currentFolderId(),
            replaceFileId: task.replaceFileId || null,
            ...extra,
        };
        writeUploadState(state);
    }

    function bufferToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(byte => byte.toString(16).padStart(2, '0'))
            .join('');
    }

    async function getTaskChecksum(task) {
        if (task.checksumSha256) {
            return task.checksumSha256;
        }
        if (task.checksumPromise) {
            return task.checksumPromise;
        }
        if (!window.crypto?.subtle || typeof task.file?.arrayBuffer !== 'function') {
            return null;
        }

        task.checksumPromise = (async () => {
            const buffer = await task.file.arrayBuffer();
            const digest = await window.crypto.subtle.digest('SHA-256', buffer);
            const checksum = bufferToHex(digest);
            task.checksumSha256 = checksum;
            return checksum;
        })();

        try {
            return await task.checksumPromise;
        } finally {
            delete task.checksumPromise;
        }
    }

    function findResumableState(file, options = {}) {
        const folderId = options.folderIdOverride ?? currentFolderId();
        const replaceFileId = options.replaceFileId || null;
        const saved = Object.values(readUploadState());
        return saved.find(item =>
            item.name === file.name &&
            Number(item.size) === file.size &&
            Number(item.folderId || 0) === Number(folderId || 0) &&
            Number(item.replaceFileId || 0) === Number(replaceFileId || 0) &&
            item.sessionId
        ) || null;
    }

    function clearTaskState(taskId) {
        const state = readUploadState();
        delete state[taskId];
        writeUploadState(state);
        renderResumeNotice();
    }

    async function apiJson(url, options = {}) {
        const headers = {
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken,
            ...(options.headers || {}),
        };

        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers,
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.error || `Request failed (${response.status})`);
        }

        return payload;
    }

    async function loadSessionById(sessionId) {
        if (!sessionId) {
            return null;
        }

        const payload = await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(sessionId)}`);
        const session = payload?.session;
        if (!session || ['completed', 'aborted', 'expired', 'failed'].includes(session.status)) {
            return null;
        }

        return session;
    }

    async function createTaskSession(task, options = {}) {
        const createPayload = {
            filename: task.file.name,
            size: task.file.size,
            mime_type: task.file.type || 'application/octet-stream',
        };

        if (task.replaceFileId) {
            createPayload.replace_file_id = task.replaceFileId;
        }

        if (options.includeChecksum) {
            try {
                setTaskStatus(task, 'starting', options.statusDetail || 'Preparing upload');
                const checksum = await getTaskChecksum(task);
                if (checksum) {
                    createPayload.checksum_sha256 = checksum;
                }
            } catch (err) {
                console.warn('Could not calculate pre-upload checksum:', err);
            }
        }

        const folderId = task.folderIdOverride ?? currentFolderId();
        if (folderId) {
            createPayload.folder_id = folderId;
        }

        const created = await apiJson('/api/v1/uploads/sessions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(createPayload),
        });

        task.sessionId = created.session.public_id;
        saveTaskState(task, { sessionId: task.sessionId });
        return created.session;
    }

    async function ensureTaskSessionPrepared(task, options = {}) {
        if (task.sessionId) {
            const activeSession = await loadSessionById(task.sessionId);
            if (activeSession) {
                return activeSession;
            }
            task.sessionId = null;
            saveTaskState(task, { sessionId: null });
        }

        const resumableState = findResumableState(task.file, { replaceFileId: task.replaceFileId });
        if (resumableState) {
            const existing = await loadSessionById(resumableState.sessionId);
            if (existing) {
                task.id = resumableState.id;
                task.sessionId = existing.public_id;
                saveTaskState(task, { sessionId: task.sessionId });
                return existing;
            }
        }

        return createTaskSession(task, options);
    }

    async function handleFiles(files, options = {}) {
        if (!progressContainer) return;
        if (window.UPLOAD_CONFIG?.chunkingEnabled === false) {
            showToast('Chunked browser uploads are currently disabled by the administrator.');
            return;
        }
        const uploadItems = await normalizeUploadItems(files);
        if (options.replaceFileId && uploadItems.filter(item => item.file).length !== 1) {
            showToast('Choose exactly one replacement file.');
            return;
        }
        cancelRequested = false;
        progressContainer.style.display = showUploadPopup ? 'block' : 'none';
        updateGlobalProgress();
        let skippedCount = 0;

        for (const item of uploadItems) {
            const file = item.file;
            const relativeFolderSegments = Array.isArray(item.relativeFolderSegments) ? item.relativeFolderSegments : [];
            let folderIdOverride = currentFolderId();

            try {
                folderIdOverride = await ensureFolderPath(relativeFolderSegments, currentFolderId());
            } catch (err) {
                skippedCount++;
                showToast(
                    `${file.name}: ${err.message || 'Could not prepare destination folders.'}`,
                    [],
                    7000
                );
                continue;
            }

            if (item.folderOnly || !file) {
                continue;
            }

            const resumable = findResumableState(file, { ...options, folderIdOverride });
            if (resumable) {
                const resume = await showActionModal(
                    'Resume Upload',
                    `Resume the interrupted upload for "${file.name}" instead of starting over?`
                );
                if (resume === null) {
                    continue;
                }
            }

            const task = {
                id: self.crypto.randomUUID ? self.crypto.randomUUID() : Math.random().toString(36).slice(2),
                file,
                sessionId: null,
                canceled: false,
                paused: false,
                status: 'queued',
                statusDetail: 'Waiting for a slot',
                controllers: [],
                xhrs: [],
                progress: {
                    loadedBytes: 0,
                    totalBytes: file.size,
                    completedBytes: 0,
                },
                replaceFileId: options.replaceFileId || null,
                folderIdOverride,
            };
            saveTaskState(task);
            registerTask(task);

            try {
                setTaskStatus(task, 'starting', 'Preparing upload');
                await ensureTaskSessionPrepared(task, { includeChecksum: false });
                enqueueTask(task);
            } catch (err) {
                if (isSkippableUploadError(err)) {
                    skippedCount++;
                    clearTaskState(task.id);
                    dropTask(task.id);
                    showToast(`${task.file.name}: skipped because this file type is not allowed.`, [], 7000);
                } else {
                    failedUploads++;
                    setTaskStatus(task, 'failed', err.message || 'Upload failed');
                    showToast(
                        `${task.file.name}: ${err.message || 'Upload failed.'}`,
                        [],
                        7000
                    );
                }
            }
        }

        renderResumeNotice();
        updateUploadPanel();

        if (skippedCount > 0) {
            showToast(
                `${skippedCount} file${skippedCount === 1 ? '' : 's'} skipped while preparing this upload.`,
                [],
                7000
            );
        }

        processQueue();
    }

    function processQueue() {
        while (!cancelRequested && activeUploads < cfg.concurrent && uploadQueue.length > 0) {
            const task = uploadQueue.shift();
            activeUploads++;
            activeUploadsMap.set(task.id, task);
            setTaskStatus(task, 'starting', 'Preparing upload');
            startUploadProcess(task)
                .catch(err => {
                    if (task.paused) {
                        setTaskStatus(task, 'paused', 'Paused');
                    } else if (!task.canceled) {
                        failedUploads++;
                        setTaskStatus(task, 'failed', err.message || 'Upload failed');
                        showToast(
                            `${task.file.name}: ${err.message || 'Upload failed.'}`,
                            [],
                            7000
                        );
                    }
                })
                .finally(() => {
                    activeUploads--;
                    activeUploadsMap.delete(task.id);
                    updateUploadPanel();
                    processQueue();
                });
        }

        if (activeUploads === 0 && uploadQueue.length === 0) {
            setTimeout(() => {
                const remaining = Array.from(uploadTaskRegistry.values());
                const hasPendingAttention = remaining.some(task => ['paused', 'failed', 'queued', 'starting', 'uploading', 'finalizing'].includes(task.status));
                if (cancelRequested) {
                    cancelRequested = false;
                    failedUploads = 0;
                    return;
                }

                if (failedUploads > 0) {
                    showToast(
                        `${failedUploads} file(s) failed to upload. Check the notices above for details.`,
                        [],
                        7000
                    );
                }
                failedUploads = 0;
                updateUploadPanel();
            }, 600);
        }
    }

    async function startUploadProcess(task) {
        const file = task.file;
        setTaskStatus(task, 'starting', 'Checking upload session');
        const session = await ensureTaskSessionPrepared(task, {
            includeChecksum: true,
            statusDetail: 'Preparing upload',
        });
        const partSize = Number(session.part_size_bytes || file.size);

        const totalParts = Math.max(1, Math.ceil(file.size / partSize));
        const uploadedParts = new Set((session.parts || [])
            .filter(part => ['uploaded', 'verified'].includes(part.status) && part.etag)
            .map(part => Number(part.part_number)));

        task.progress.completedBytes = (session.parts || [])
            .filter(part => uploadedParts.has(Number(part.part_number)))
            .reduce((sum, part) => sum + Number(part.part_size || 0), 0);
        task.progress.loadedBytes = task.progress.completedBytes;
        setTaskStatus(task, 'uploading', uploadedParts.size > 0 ? 'Resuming multipart upload' : 'Uploading parts');
        updateGlobalProgress();

        const missingPartNumbers = [];
        for (let index = 1; index <= totalParts; index++) {
            if (!uploadedParts.has(index)) {
                missingPartNumbers.push(index);
            }
        }

        const signedParts = new Map();
        if (missingPartNumbers.length > 0) {
            const signed = await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(task.sessionId)}/parts/sign`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    part_numbers: missingPartNumbers,
                    expires_in: 7200,
                }),
            });

            if (!Array.isArray(signed.parts)) {
                throw new Error('Upload service returned an invalid multipart signing response.');
            }

            signed.parts.forEach(part => signedParts.set(part.part_number, part.url));
        }

        const partProgress = new Map();
        let nextIndex = 0;

        const refreshProgress = () => {
            let loaded = task.progress.completedBytes;
            partProgress.forEach(value => {
                loaded += value;
            });
            task.progress.loadedBytes = Math.min(task.progress.totalBytes, loaded);
            renderUploadTask(task);
            updateGlobalProgress();
        };

        const uploadOnePart = async () => {
            while (!task.canceled && nextIndex < missingPartNumbers.length) {
                const partNumber = missingPartNumbers[nextIndex];
                nextIndex++;
                const signedUrl = signedParts.get(partNumber);
                if (!signedUrl) {
                    throw new Error(`Missing signed URL for part ${partNumber}.`);
                }

                const start = (partNumber - 1) * partSize;
                const end = Math.min(start + partSize, file.size);
                const blob = file.slice(start, end);

                const etag = await uploadPartWithRetry(task, signedUrl, blob, partNumber, partProgress, refreshProgress);

                await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(task.sessionId)}/parts/report`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        part_number: partNumber,
                        etag,
                        part_size: blob.size,
                    }),
                });

                partProgress.delete(partNumber);
                task.progress.completedBytes += blob.size;
                refreshProgress();
            }
        };

        if (missingPartNumbers.length > 0) {
            const workerCount = Math.min(getPartConcurrency(session), missingPartNumbers.length);
            const workers = Array.from({ length: workerCount }, () => uploadOnePart());
            await Promise.all(workers);
        }

        if (task.canceled) {
            return;
        }

        if (task.paused) {
            throw new Error('Upload paused.');
        }

        const completionPayload = {};
        try {
            setTaskStatus(task, 'finalizing', 'Hashing upload');
            const checksum = await getTaskChecksum(task);
            if (checksum) {
                completionPayload.checksum_sha256 = checksum;
            }
        } catch (err) {
            console.warn('Could not calculate upload checksum before completion:', err);
        }

        setTaskStatus(task, 'finalizing', 'Completing upload');
        const completed = await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(task.sessionId)}/complete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(completionPayload),
        });

        clearTaskState(task.id);
        task.progress.loadedBytes = task.progress.totalBytes;
        setTaskStatus(task, 'completed', 'Ready');
        try {
            await upsertCompletedFileInView(completed.file_id);
        } catch (err) {
            console.warn('Could not update file manager in place after upload completion:', err);
        }
        if (task.replaceFileId) {
            queueFlashToast('Replaced file in place.');
        }
        updateGlobalProgress();
        setTimeout(() => dropTask(task.id), 1500);
    }

    async function uploadPartWithRetry(task, signedUrl, blob, partNumber, partProgress, refreshProgress) {
        let lastError = null;

        for (let attempt = 1; attempt <= cfg.maxPartRetries; attempt++) {
            if (task.canceled || task.paused) {
                throw new Error(task.paused ? 'Upload paused.' : 'Upload canceled.');
            }

            try {
                return await uploadPart(task, signedUrl, blob, partNumber, partProgress, refreshProgress);
            } catch (err) {
                lastError = err;
                partProgress.delete(partNumber);
                refreshProgress();
                if (task.paused || task.canceled) {
                    throw new Error(task.paused ? 'Upload paused.' : 'Upload canceled.');
                }
                if (attempt < cfg.maxPartRetries) {
                    await new Promise(resolve => setTimeout(resolve, attempt * 1000));
                }
            }
        }

        throw lastError || new Error(`Part ${partNumber} failed to upload.`);
    }

    function uploadPart(task, signedUrl, blob, partNumber, partProgress, refreshProgress) {
        const usesAppUploadEndpoint = typeof signedUrl === 'string' && signedUrl.startsWith('/api/v1/uploads/sessions/');
        if (usesAppUploadEndpoint) {
            return uploadAppPart(task, signedUrl, blob, partNumber, partProgress, refreshProgress);
        }

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            task.xhrs.push(xhr);
            xhr.open('PUT', signedUrl, true);
            xhr.timeout = 5 * 60 * 1000;

            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) {
                    return;
                }
                partProgress.set(partNumber, event.loaded);
                refreshProgress();
            };

            xhr.onload = () => {
                task.xhrs = task.xhrs.filter(active => active !== xhr);
                if (xhr.status >= 200 && xhr.status < 300) {
                    const etag = xhr.getResponseHeader('ETag');
                    if (!etag) {
                        reject(new Error(`Part ${partNumber} uploaded, but the storage response did not expose an ETag header. Check the bucket CORS rule and expose ETag.`));
                        return;
                    }
                    resolve(etag.replace(/"/g, ''));
                    return;
                }

                reject(new Error(`Part ${partNumber} failed (${xhr.status}).`));
            };

            xhr.onerror = () => {
                task.xhrs = task.xhrs.filter(active => active !== xhr);
                const hint = xhr.status === 0
                    ? ' This usually means the storage endpoint, bucket CORS, or this site\'s CSP blocked the browser request.'
                    : '';
                reject(new Error(`Network error while uploading part ${partNumber}.${hint}`));
            };

            xhr.ontimeout = () => {
                task.xhrs = task.xhrs.filter(active => active !== xhr);
                reject(new Error(`Part ${partNumber} timed out while uploading to object storage.`));
            };

            xhr.onabort = () => {
                task.xhrs = task.xhrs.filter(active => active !== xhr);
                reject(new Error('Upload canceled.'));
            };

            xhr.send(blob);
        });
    }

    async function uploadAppPart(task, signedUrl, blob, partNumber, partProgress, refreshProgress) {
        if (task.canceled || task.paused) {
            throw new Error(task.paused ? 'Upload paused.' : 'Upload canceled.');
        }

        const response = await fetch(signedUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Content-Type': 'application/octet-stream',
                'Accept': 'application/json',
            },
            body: blob,
        });

        let payload = {};
        try {
            payload = await response.json();
        } catch (err) {}

        if (!response.ok) {
            throw new Error(payload.error || `Part ${partNumber} failed (${response.status}).`);
        }

        const etag = typeof payload.etag === 'string' ? payload.etag : '';
        if (!etag) {
            throw new Error(`Part ${partNumber} uploaded, but the local upload endpoint did not return an ETag.`);
        }

        partProgress.set(partNumber, blob.size);
        refreshProgress();
        return etag.replace(/"/g, '');
    }

    function updateGlobalProgress() {
        updateUploadPanel();
    }

    // Cancel Upload Click Handler
    document.getElementById('cancelUploadBtn')?.addEventListener('click', async () => {
        const cancelableTasks = Array.from(uploadTaskRegistry.values()).filter(task =>
            !['completed', 'canceled'].includes(task.status)
        );
        if (activeUploadsMap.size === 0 && uploadQueue.length === 0 && cancelableTasks.length === 0) return;

        if (confirm('Are you sure you want to cancel all uploads?')) {
            cancelRequested = true;
            uploadQueue = [];

            await Promise.all(cancelableTasks.map(task => cancelTask(task.id)));

            Object.keys(activeXhrs).forEach(key => {
                try {
                    activeXhrs[key].abort?.();
                } catch (e) {
                    console.error('Abort fail:', e);
                }
                delete activeXhrs[key];
            });

            failedUploads = 0;
            cancelRequested = false;

            if (progressFill) progressFill.style.width = '0%';
            if (progressPercent) progressPercent.innerText = '0%';
            if (progressText) progressText.innerText = 'Upload canceled';
            if (fileInput) fileInput.value = '';
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (!hasBlockingUploads()) {
            return;
        }

        event.preventDefault();
        event.returnValue = 'Uploads are still running. Leaving this page will interrupt them.';
    });

    async function restorePendingUploads() {
        const saved = Object.values(readUploadState());
        if (saved.length === 0) {
            renderResumeNotice();
            return;
        }

        for (const item of saved) {
            if (!item.sessionId) {
                clearTaskState(item.id);
                continue;
            }

            try {
                const payload = await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(item.sessionId)}`);
                const session = payload.session;
                if (!session || ['completed', 'aborted', 'expired', 'failed'].includes(session.status)) {
                    clearTaskState(item.id);
                    continue;
                }
            } catch (err) {
                clearTaskState(item.id);
            }
        }

        renderResumeNotice();
    }

    restorePendingUploads();
});
