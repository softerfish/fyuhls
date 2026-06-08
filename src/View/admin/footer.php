        </main>
    </div>
</div>

<style>
/* Reset some default bootstrap spacing that conflicts with our custom admin.css cards */
.main-content .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 1rem;
    margin-bottom: 2rem;
    border-bottom: 1px solid #e2e8f0;
}
.main-content .page-header h1 {
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0;
}
</style>

<div id="adminActionModal" class="admin-action-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="adminActionModalTitle" hidden>
    <div class="admin-action-modal__card" role="document">
        <div class="admin-action-modal__header">
            <span id="adminActionModalIcon" class="admin-action-modal__icon" aria-hidden="true">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </span>
            <h2 id="adminActionModalTitle" class="admin-action-modal__title">Confirm Action</h2>
        </div>
        <div class="admin-action-modal__body">
            <p id="adminActionModalMessage" class="admin-action-modal__message"></p>
            <div id="adminActionModalInputWrap" class="admin-action-modal__input-wrap is-hidden">
                <label id="adminActionModalInputLabel" class="admin-action-modal__label" for="adminActionModalInput">Type to confirm</label>
                <input id="adminActionModalInput" class="admin-action-modal__input" type="text" autocomplete="off">
                <span id="adminActionModalHint" class="admin-action-modal__hint"></span>
            </div>
        </div>
        <div class="admin-action-modal__footer">
            <button type="button" id="adminActionModalCancel" class="admin-action-modal__cancel">Cancel</button>
            <button type="button" id="adminActionModalConfirm" class="admin-action-modal__confirm">Confirm</button>
        </div>
    </div>
</div>

<script>
window.adminConfirm = window.adminConfirm || (function() {
    const modal = document.getElementById('adminActionModal');
    const title = document.getElementById('adminActionModalTitle');
    const message = document.getElementById('adminActionModalMessage');
    const icon = document.getElementById('adminActionModalIcon');
    const inputWrap = document.getElementById('adminActionModalInputWrap');
    const input = document.getElementById('adminActionModalInput');
    const inputLabel = document.getElementById('adminActionModalInputLabel');
    const hint = document.getElementById('adminActionModalHint');
    const cancelButton = document.getElementById('adminActionModalCancel');
    const confirmButton = document.getElementById('adminActionModalConfirm');
    let resolver = null;
    let expected = '';
    let previousFocus = null;

    function setOpen(open) {
        modal.hidden = !open;
        modal.classList.toggle('is-open', open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('admin-action-modal-open', open);
    }

    function updateConfirmState() {
        confirmButton.disabled = expected !== '' && input.value !== expected;
    }

    function close(result) {
        if (!resolver) {
            return;
        }
        const resolve = resolver;
        resolver = null;
        setOpen(false);
        input.value = '';
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
        resolve(Boolean(result));
    }

    input.addEventListener('input', updateConfirmState);
    cancelButton.addEventListener('click', function() { close(false); });
    confirmButton.addEventListener('click', function() { close(true); });
    modal.addEventListener('click', function(event) {
        if (event.target === modal && cancelButton.style.display !== 'none') {
            close(false);
        }
    });
    document.addEventListener('keydown', function(event) {
        if (!resolver) {
            return;
        }
        if (event.key === 'Escape' && cancelButton.style.display !== 'none') {
            event.preventDefault();
            close(false);
        }
        if (event.key === 'Enter' && document.activeElement === input && !confirmButton.disabled) {
            event.preventDefault();
            close(true);
        }
    });

    return function(messageText, options = {}) {
        if (!modal || !title || !message || !confirmButton || !cancelButton) {
            return Promise.resolve(false);
        }
        if (resolver) {
            close(false);
        }

        previousFocus = document.activeElement;
        expected = String(options.expected || '');
        title.textContent = options.title || (options.alertOnly ? 'Notice' : 'Confirm Action');
        message.textContent = String(messageText || '');
        confirmButton.textContent = options.confirmLabel || (options.alertOnly ? 'OK' : 'Confirm');
        cancelButton.textContent = options.cancelLabel || 'Cancel';
        cancelButton.style.display = options.alertOnly ? 'none' : '';
        confirmButton.classList.toggle('admin-action-modal__confirm--info', Boolean(options.alertOnly || options.info));
        icon.classList.toggle('admin-action-modal__icon--info', Boolean(options.alertOnly || options.info));
        icon.innerHTML = options.alertOnly || options.info
            ? '<i class="bi bi-info-circle-fill"></i>'
            : '<i class="bi bi-exclamation-triangle-fill"></i>';

        inputWrap.classList.toggle('is-hidden', expected === '');
        inputLabel.textContent = options.inputLabel || 'Type to confirm';
        hint.textContent = expected ? 'Type "' + expected + '" to continue.' : '';
        updateConfirmState();
        setOpen(true);

        window.setTimeout(function() {
            if (expected) {
                input.focus();
            } else {
                confirmButton.focus();
            }
        }, 30);

        return new Promise(function(resolve) {
            resolver = resolve;
        });
    };
})();

window.adminAlert = window.adminAlert || function(messageText, options = {}) {
    return window.adminConfirm(messageText, Object.assign({}, options, {
        alertOnly: true,
        info: true,
        confirmLabel: options.confirmLabel || 'OK'
    }));
};

document.addEventListener('click', function(event) {
    const navTarget = event.target.closest('[data-nav-url]');
    if (navTarget) {
        const url = navTarget.getAttribute('data-nav-url');
        if (url) {
            window.location.href = url;
        }
        return;
    }

    const alertTarget = event.target.closest('[data-alert-message]');
    if (alertTarget) {
        event.preventDefault();
        window.adminAlert(alertTarget.getAttribute('data-alert-message') || '');
    }
});

const adminConfirmedForms = new WeakSet();
document.addEventListener('submit', function(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    if (adminConfirmedForms.has(form)) {
        adminConfirmedForms.delete(form);
        return;
    }

    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    const promptMessage = submitter?.getAttribute('data-confirm-prompt') || form.getAttribute('data-confirm-prompt');
    if (promptMessage) {
        const expected = submitter?.getAttribute('data-confirm-expected') || form.getAttribute('data-confirm-expected') || '';
        event.preventDefault();
        window.adminConfirm(promptMessage, {
            title: submitter?.getAttribute('data-confirm-title') || form.getAttribute('data-confirm-title') || 'Confirm Destructive Action',
            confirmLabel: submitter?.getAttribute('data-confirm-label') || form.getAttribute('data-confirm-label') || 'Confirm',
            expected: expected
        }).then(function(confirmed) {
            if (!confirmed) {
                return;
            }
            adminConfirmedForms.add(form);
            window.setTimeout(function() { adminConfirmedForms.delete(form); }, 1000);
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter || undefined);
            } else {
                form.submit();
            }
        });
        return;
    }

    const confirmMessage = submitter?.getAttribute('data-confirm-message') || form.getAttribute('data-confirm-message');
    if (confirmMessage) {
        event.preventDefault();
        window.adminConfirm(confirmMessage, {
            title: submitter?.getAttribute('data-confirm-title') || form.getAttribute('data-confirm-title') || 'Confirm Action',
            confirmLabel: submitter?.getAttribute('data-confirm-label') || form.getAttribute('data-confirm-label') || 'Confirm'
        }).then(function(confirmed) {
            if (!confirmed) {
                return;
            }
            adminConfirmedForms.add(form);
            window.setTimeout(function() { adminConfirmedForms.delete(form); }, 1000);
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter || undefined);
            } else {
                form.submit();
            }
        });
    }
});
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
