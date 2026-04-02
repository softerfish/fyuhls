<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">System Configuration</h1>
        <p class="text-muted small">Manage site-wide infrastructure and enterprise settings.</p>
    </div>
    <div class="quick-actions">
        <button type="button" class="btn btn-sm btn-outline-dark shadow-sm me-2" data-bs-toggle="modal" data-bs-target="#pageGuideModal">
            <i class="bi bi-question-circle me-1"></i> Page Guide
        </button>
        <a href="/admin/configuration?tab=security&sec_tab=health" class="btn btn-sm btn-outline-danger shadow-sm me-2">
            <i class="bi bi-heart-pulse me-1"></i> System Health
        </a>
        <?php if (empty($demoAdmin)): ?>
            <a href="/admin/diagnostics/export" class="btn btn-sm btn-outline-dark shadow-sm">
                <i class="bi bi-file-earmark-arrow-down me-1"></i> Export Diagnostics
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Page Guide Modal -->
<div class="modal fade" id="pageGuideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-cpu me-2 text-primary"></i> Configuration Hub Guide</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <?php include __DIR__ . '/../help/configuration.php'; ?>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close Guide</button>
                <a href="/admin/docs#configuration" class="btn btn-primary px-4">View Full System Docs</a>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($saved)): ?>
    <div class="alert alert-success shadow-sm mb-4">
        <i class="bi bi-check-circle-fill me-2"></i> Configuration updated successfully.
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger shadow-sm mb-4">
        <h6 class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i> Configuration Error</h6>
        <ul class="mb-0 small">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (($demoMode ?? '0') === '1'): ?>
    <div class="alert alert-danger shadow-sm mb-4">
        <i class="bi bi-eye-slash-fill me-2"></i> Demo mode is active. All write actions are blocked for every account, including admins. Disable it directly in the database when you are ready to leave demo mode.
    </div>
<?php endif; ?>

<?php
$lastCronTimestamp = (int)\App\Model\Setting::get('last_cron_run_timestamp', 0);
$cronOffline = !($lastCronTimestamp > 0 && (time() - $lastCronTimestamp) < 1860);
?>

<!-- Enterprise Tab Navigation -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white p-0 border-bottom">
        <ul class="nav nav-tabs card-header-tabs m-0 px-3 border-0" id="configTabs">
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'general' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=general">
                    <i class="bi bi-gear me-2"></i> General
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'security' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=security">
                    <i class="bi bi-shield-lock me-2"></i> Security
                    <?php if (!empty($securityNoticeCount)): ?>
                        <span class="badge bg-warning text-dark ms-2"><?= (int)$securityNoticeCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'email' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=email">
                    <i class="bi bi-envelope-paper me-2"></i> Email
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'storage' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=storage">
                    <i class="bi bi-hdd-network me-2"></i> Storage Servers
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'monetization' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=monetization">
                    <i class="bi bi-megaphone me-2"></i> Monetization
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'seo' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=seo">
                    <i class="bi bi-graph-up-arrow me-2"></i> SEO
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'downloads' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=downloads">
                    <i class="bi bi-download me-2"></i> Downloads
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'uploads' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=uploads">
                    <i class="bi bi-upload me-2"></i> Uploads
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'cron' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=cron">
                    <i class="bi bi-clock-history me-2"></i> Cron Jobs
                    <?php if ($cronOffline): ?>
                        <span class="badge bg-danger ms-2" title="Cron Jobs Offline">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="card-body p-4">
        <?php 
        $activeTab = $activeTab ?? 'general';
        $tabFile = __DIR__ . "/tabs/{$activeTab}.php";
        if (file_exists($tabFile)) {
            include $tabFile;
        } else {
            echo "<div class='text-center py-5 text-muted'>
                    <i class='bi bi-tools' style='font-size: 3rem;'></i>
                    <p class='mt-3'>The <strong>" . ucfirst($activeTab) . "</strong> module is being migrated to the unified hub.<br>Please use the legacy sidebar links for now.</p>
                  </div>";
        }
        ?>
    </div>
</div>

<style>
.nav-tabs .nav-link.active {
    background: transparent !important;
    color: var(--bs-primary) !important;
    border-bottom: 3px solid var(--bs-primary) !important;
}
.nav-tabs .nav-link:hover {
    background: rgba(0,0,0,0.02);
}
</style>

<?php include __DIR__ . '/../footer.php'; ?>
