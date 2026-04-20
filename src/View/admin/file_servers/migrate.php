<?php include dirname(__DIR__) . '/header.php'; ?>
<?php
$selectedFromServer = (int)($migrationForm['from_server'] ?? 0);
$selectedToServer = (int)($migrationForm['to_server'] ?? 0);
$selectedBatchLimit = max(1, (int)($migrationForm['batch_limit'] ?? 50));
?>

<div class="page-header">
    <h1>Migrate Files Between Servers</h1>
    <a href="/admin/configuration?tab=storage" class="btn">&larr; Back to Servers</a>
</div>

<?php if (isset($results['error'])): ?>
    <div class="alert alert-error"><?= htmlspecialchars($results['error']) ?></div>
<?php endif; ?>

<?php if (isset($results['success'])): ?>
    <div class="alert alert-success">
        <strong>Batch Complete!</strong><br>
        ✅ Successfully moved: <?= $results['success'] ?> files<br>
        ❌ Failed: <?= $results['failed'] ?><br>
        ⏳ Remaining on source: <?= $results['remaining'] ?> files
        
        <?php if ($results['remaining'] > 0): ?>
            <div class="migrate-next-batch">
                <button type="submit" form="migrateForm" class="btn btn-primary">Process Next Batch</button>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Configure Migration Task</div>
    <div class="card-body">
        <form method="POST" id="migrateForm">
            <?= \App\Core\Csrf::field() ?>
            <div class="migrate-server-grid">
                <div class="form-group">
                    <label>Source Server (Move FROM)</label>
                    <select name="from_server">
                        <?php foreach ($servers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int)$s['id'] === $selectedFromServer ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?> (<?= strtoupper($s['server_type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Destination Server (Move TO)</label>
                    <select name="to_server">
                        <?php foreach ($servers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int)$s['id'] === $selectedToServer ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?> (<?= strtoupper($s['server_type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Batch Limit (Files per click)</label>
                <input type="number" name="batch_limit" value="<?= $selectedBatchLimit ?>">
                <small>Higher limits may cause timeouts on slow storage servers.</small>
            </div>

            <div class="migrate-warning-box">
                <h3 class="migrate-warning-title">Warning</h3>
                <p class="migrate-warning-copy">This process moves the data physically between storage backends and updates all database records. Ensure your servers have adequate bandwidth and timeout settings.</p>
            </div>

            <button type="submit" class="migrate-submit btn btn-primary btn-lg">Start Migration Batch</button>
        </form>
    </div>
</div>

<style>
.migrate-next-batch{margin-top:1rem}
.migrate-server-grid{display:grid;grid-template-columns:1fr 1fr;gap:2rem}
.migrate-warning-box{background:#fffbeb;padding:1.5rem;border-radius:8px;border:1px solid #fde68a;margin-bottom:2rem}
.migrate-warning-title{margin-top:0;color:#92400e;font-size:1rem}
.migrate-warning-copy{font-size:.875rem;color:#92400e;margin-bottom:0}
.migrate-submit{width:auto}
</style>

<?php include dirname(__DIR__) . '/footer.php'; ?>
