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
    const viewToggle = document.getElementById('viewToggle');
    let csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

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
    const pageStateKey = 'fm_state:' + window.location.pathname;

    let selectedItems = []; // Array of {id: string, type: 'file'|'folder'}
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

    function savePageState(extra = {}) {
        const state = {
            search: fmSearch?.value || '',
            typeFilter: fmTypeFilter?.value || 'all',
            visibilityFilter: fmVisibilityFilter?.value || 'all',
            statusFilter: fmStatusFilter?.value || 'all',
            sort: fmSort?.value || 'newest',
            scrollY: window.scrollY,
            selectedItems,
            ...extra,
        };

        sessionStorage.setItem(pageStateKey, JSON.stringify(state));
    }

    function restorePageState() {
        const raw = sessionStorage.getItem(pageStateKey);
        if (!raw) return;

        sessionStorage.removeItem(pageStateKey);

        try {
            const state = JSON.parse(raw);
            if (fmSearch) fmSearch.value = state.search || '';
            if (fmTypeFilter) fmTypeFilter.value = state.typeFilter || 'all';
            if (fmVisibilityFilter) fmVisibilityFilter.value = state.visibilityFilter || 'all';
            if (fmStatusFilter) fmStatusFilter.value = state.statusFilter || 'all';
            if (fmSort) fmSort.value = state.sort || 'newest';
            applySearchFilter();

            if (Array.isArray(state.selectedItems)) {
                selectedItems = state.selectedItems.filter(item =>
                    document.querySelector(`.file-item[data-id="${item.id}"]`)
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
            if (sort === 'name') {
                return (left.querySelector('.file-name')?.innerText || '').localeCompare(right.querySelector('.file-name')?.innerText || '');
            }
            if (sort === 'largest') {
                return Number(right.dataset.size || 0) - Number(left.dataset.size || 0);
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

    function describeItemFromElement(element) {
        if (!element) {
            return null;
        }

        return {
            id: element.getAttribute('data-id'),
            type: element.classList.contains('folder-item') ? 'folder' : 'file',
            parentId: element.getAttribute('data-parent-id') || 'root',
            name: element.querySelector('.file-name')?.innerText || '',
        };
    }

    function collectSnapshot(items) {
        return items.map(item => {
            const element = document.querySelector(`.file-item[data-id="${item.id}"]`);
            return describeItemFromElement(element) || item;
        });
    }

    function removeItemsFromView(items) {
        items.forEach(item => {
            const element = document.querySelector(`.file-item[data-id="${item.id}"]`);
            if (element) {
                element.dataset.pendingRemoval = '1';
                element.style.display = 'none';
            }
        });
        selectedItems = selectedItems.filter(item => !items.some(removed => removed.id === item.id));
        updateSelectionUI();
        applySearchFilter();
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
        if (downloadButton) downloadButton.style.display = itemType === 'file' ? '' : 'none';
        if (shareButton) shareButton.style.display = itemType === 'file' ? '' : 'none';
        mobileActionSheet.style.display = 'block';
        return true;
    }

    async function openShareModalForItem(fileId) {
        const element = document.querySelector(`.file-item[data-id="${fileId}"]`);
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

        if (action === 'rename') {
            const currentItem = itemEl || document.querySelector(`.file-item[data-id="${id}"]`);
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
        const type = item.classList.contains('folder-item') ? 'folder' : 'file';
        const name = item.querySelector('.file-name').innerText;

        // auto-select if not selected already
        if (!selectedItems.some(i => i.id === id)) {
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
        ['ctxDownload', 'ctxRename', 'ctxMove', 'ctxCopy', 'ctxTrash'].forEach(cid => {
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
            if (!selectedItems.some(i => i.id === id)) {
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
            const isSelected = selectedItems.some(i => i.id === id);
            item.classList.toggle('selected', isSelected);
            const cb = item.querySelector('.item-checkbox');
            if (cb) cb.checked = isSelected;
        });
    }

    // Bulk Download Listener
    document.getElementById('bulkDownloadBtn')?.addEventListener('click', () => {
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

    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('item-checkbox')) {
            const item = e.target.closest('.file-item');
            const id = item.getAttribute('data-id');
            const type = e.target.getAttribute('data-type');

            if (e.target.checked) {
                if (!selectedItems.some(i => i.id === id)) {
                    selectedItems.push({ id, type });
                }
            } else {
                selectedItems = selectedItems.filter(i => i.id !== id);
            }
            updateSelectionUI();
        }
    });

    document.getElementById('clearSelectionBtn')?.addEventListener('click', () => {
        selectedItems = [];
        updateSelectionUI();
    });

    // select all - mirrors the Ctrl+A keyboard shortcut
    document.getElementById('selectAllBtn')?.addEventListener('click', () => {
        selectedItems = [];
        document.querySelectorAll('.file-item').forEach(item => {
            if (item.style.display === 'none') return;
            const id   = item.getAttribute('data-id');
            const type = item.classList.contains('folder-item') ? 'folder' : 'file';
            selectedItems.push({ id, type });
        });
        updateSelectionUI();
    });

    // bulk visibility - Make Public
    document.getElementById('bulkMakePublicBtn')?.addEventListener('click', async () => {
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
                        document.querySelector(`.file-item[data-id="${it.id}"]`)?.setAttribute('data-public', '1');
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
    document.getElementById('bulkMakePrivateBtn')?.addEventListener('click', async () => {
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
                        document.querySelector(`.file-item[data-id="${it.id}"]`)?.setAttribute('data-public', '0');
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

        dropZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (dt.files.length > 0) {
                handleFiles(dt.files);
            }
        });

        dropZone.addEventListener('click', () => fileInput?.click());
    }

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                handleFiles(fileInput.files);
            }
        });
    }

    if (uploadBtn) {
        uploadBtn.addEventListener('click', () => fileInput?.click());
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
            const type = item.classList.contains('folder-item') ? 'folder' : 'file';

            if (!selectedItems.some(i => i.id === id)) {
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
                const type = item.classList.contains('folder-item') ? 'folder' : 'file';
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
            const itemEl = document.querySelector(`.file-item[data-id="${selected.id}"]`);
            performItemAction('rename', selected.id, selected.type, selected.name || '', itemEl);
        } else if (e.key === 'a' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            selectedItems = [];
            document.querySelectorAll('.file-item').forEach(item => {
                if (item.style.display === 'none') return;
                const id = item.getAttribute('data-id');
                const type = item.classList.contains('folder-item') ? 'folder' : 'file';
                selectedItems.push({ id, type });
            });
            updateSelectionUI();
        }
    });

    // 5.6 Action Modal Helpers (Copy, Move, Tree)
    function showFolderTreeModal(title, onConfirm) {
        const moveModalTitle = moveModal?.querySelector('h3');
        if (moveModalTitle) {
            moveModalTitle.innerText = title;
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

    document.getElementById('bulkDeleteBtn')?.addEventListener('click', async () => {
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

    document.getElementById('bulkTrashBtn')?.addEventListener('click', async () => {
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
    let savedView = 'grid';
    try {
        savedView = localStorage.getItem('fm_view') === 'list' ? 'list' : 'grid';
    } catch (e) {
    }
    setFileManagerView(savedView);

    restorePageState();
    applySearchFilter();

    // 8. Move Modal Navigation
    const moveModal = document.getElementById('moveModal');
    const folderTree = document.getElementById('folderTree');

    document.getElementById('bulkMoveBtn')?.addEventListener('click', () => {
        if (selectedItems.length === 0) return;
        showFolderTreeModal('Move to...', (targetId) => {
            performBulkMove(selectedItems, targetId);
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
        if (folderItem && !e.target.closest('.file-hover-controls') && !e.target.closest('.file-select')) {
            const id = folderItem.getAttribute('data-id');
            location.href = '/folder/' + id;
            return;
        }

        // Delete Individual
        const delFile = e.target.closest('.delete-file');
        const delFolder = e.target.closest('.delete-folder');
        if (delFile || delFolder) {
            e.stopPropagation();
            const type = delFile ? 'file' : 'folder';
            const id = e.target.closest('.file-item').getAttribute('data-id');
            if (await showActionModal('Delete Item', `Delete this ${type}?`)) {
                const fd = new FormData();
                fd.append(type === 'file' ? 'id' : 'folder_id', id);
                fd.append('csrf_token', csrfToken);
                if (type === 'file' && window.FILE_MANAGER_CONFIG?.isAdmin) {
                    const deleteReason = await showActionModal(
                        'Delete Reason',
                        'Enter a reason for deleting this file.',
                        '',
                        true
                    );
                    if (deleteReason === null) {
                        return;
                    }
                    if (!String(deleteReason).trim()) {
                        alert('A delete reason is required for admin file deletions.');
                        return;
                    }
                    fd.append('delete_reason', String(deleteReason).trim());
                }
                fetch(type === 'file' ? '/file/delete' : '/folder/delete', { method: 'POST', body: fd })
                    .then(async r => {
                        const text = await r.text();
                        try {
                            return JSON.parse(text);
                        } catch (err) {
                            throw new Error('Server returned invalid response');
                        }
                    })
                    .then(data => {
                        if (data.status === 'success') reloadWithState();
                        else alert(data.error || data.message || 'Action failed');
                    })
                    .catch(err => {
                        console.error('Individual action failed:', err);
                        alert(err.message);
                    });
            }
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

    function renderResumeNotice() {
        const existing = document.getElementById('resumeNotice');
        if (existing) existing.remove();

        const resumable = Object.values(readUploadState()).filter(item => item.sessionId);
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
            folderId: currentFolderId(),
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

    function findResumableState(file) {
        const folderId = currentFolderId();
        const saved = Object.values(readUploadState());
        return saved.find(item =>
            item.name === file.name &&
            Number(item.size) === file.size &&
            Number(item.folderId || 0) === Number(folderId || 0) &&
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

    async function handleFiles(files) {
        if (!progressContainer) return;
        if (window.UPLOAD_CONFIG?.chunkingEnabled === false) {
            showToast('Chunked browser uploads are currently disabled by the administrator.');
            return;
        }
        cancelRequested = false;
        progressContainer.style.display = showUploadPopup ? 'block' : 'none';
        updateGlobalProgress();

        for (const file of [...files]) {
            const resumable = findResumableState(file);
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
            };
            saveTaskState(task);
            registerTask(task);
            uploadQueue.push(task);
        }

        renderResumeNotice();
        updateUploadPanel();

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

                if (failedUploads === 0 && !hasPendingAttention && remaining.length > 0) {
                    reloadWithState({ selectedItems: [] });
                } else {
                    if (failedUploads > 0) {
                        showToast(
                            `${failedUploads} file(s) failed to upload. Check the notices above for details.`,
                            [],
                            7000
                        );
                    }
                    failedUploads = 0;
                    updateUploadPanel();
                }
            }, 600);
        }
    }

    async function startUploadProcess(task) {
        const file = task.file;
        setTaskStatus(task, 'starting', 'Checking upload session');
        const resumableState = findResumableState(file);
        let session;
        let partSize;

        if (resumableState) {
            const existing = await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(resumableState.sessionId)}`);
            if (existing.session && !['completed', 'aborted', 'expired', 'failed'].includes(existing.session.status)) {
                task.id = resumableState.id;
                task.sessionId = existing.session.public_id;
                session = existing.session;
                partSize = Number(existing.session.part_size_bytes || file.size);
            }
        }

        if (!session) {
            const createPayload = {
                filename: file.name,
                size: file.size,
                mime_type: file.type || 'application/octet-stream',
            };

            try {
                setTaskStatus(task, 'starting', 'Checking duplicates');
                const checksum = await getTaskChecksum(task);
                if (checksum) {
                    createPayload.checksum_sha256 = checksum;
                }
            } catch (err) {
                console.warn('Could not calculate pre-upload checksum:', err);
            }

            const folderId = currentFolderId();
            if (folderId) {
                createPayload.folder_id = folderId;
            }

            const created = await apiJson('/api/v1/uploads/sessions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(createPayload),
            });

            task.sessionId = created.session.public_id;
            session = created.session;
            partSize = Number(created.part_size_bytes || file.size);

            if (created.upload_skipped || session.status === 'completed') {
                clearTaskState(task.id);
                const presented = await runDedupedUploadPresentation(task);
                if (presented) {
                    setTimeout(() => dropTask(task.id), 1500);
                }
                return;
            }
        }

        saveTaskState(task, { sessionId: task.sessionId });

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
        await apiJson(`/api/v1/uploads/sessions/${encodeURIComponent(task.sessionId)}/complete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(completionPayload),
        });

        clearTaskState(task.id);
        task.progress.loadedBytes = task.progress.totalBytes;
        setTaskStatus(task, 'completed', 'Ready');
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
        const hasActiveUploads = Array.from(uploadTaskRegistry.values()).some(task =>
            ['queued', 'starting', 'uploading', 'finalizing', 'paused'].includes(task.status)
        );
        if (!hasActiveUploads) {
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


