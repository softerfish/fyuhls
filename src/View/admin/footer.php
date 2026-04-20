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

<script>
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
        alert(alertTarget.getAttribute('data-alert-message') || '');
    }
});

document.addEventListener('submit', function(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    const promptMessage = submitter?.getAttribute('data-confirm-prompt') || form.getAttribute('data-confirm-prompt');
    if (promptMessage) {
        const expected = submitter?.getAttribute('data-confirm-expected') || form.getAttribute('data-confirm-expected') || '';
        const response = prompt(promptMessage);
        if (response !== expected) {
            event.preventDefault();
        }
        return;
    }

    const confirmMessage = submitter?.getAttribute('data-confirm-message') || form.getAttribute('data-confirm-message');
    if (confirmMessage && !confirm(confirmMessage)) {
        event.preventDefault();
    }
});
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
