<?php include 'header.php'; ?>

<div class="page-header">
    <div>
        <h1>Application Logs</h1>
        <p class="text-muted mb-0">
            <?= !empty($demoAdmin)
                ? 'Raw application logs are hidden for the demo admin account.'
                : 'Review the most recent raw application log lines, then clear the file only when you intentionally want a clean troubleshooting window.' ?>
        </p>
    </div>
    <?php if (empty($demoAdmin)): ?>
        <form method="POST" action="/admin/logs/clear" data-confirm-message="Clear all logs?">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="redirect" value="/admin/logs">
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-trash me-2"></i> Clear Logs
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="p-3 border-bottom bg-light small text-muted">
            Showing the newest 200 log lines. Current size: <strong><?= htmlspecialchars((string)($logSizeReadable ?? '0 B')) ?></strong> / <?= htmlspecialchars((string)($logMaxReadable ?? '25 MB')) ?> cap. Use System Status and Support Center for sanitized troubleshooting when sharing diagnostics.
        </div>
        <pre class="logs-console mb-0 p-4 bg-dark text-light"><?= htmlspecialchars((string)$logContent) ?></pre>
    </div>
</div>

<style>
.logs-console{min-height:60vh;overflow-x:auto;white-space:pre-wrap;word-break:break-word}
</style>

<?php include 'footer.php'; ?>
