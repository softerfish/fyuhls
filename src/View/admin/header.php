<?php 
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')); 
$appVersion = '0.1';
$versionPath = defined('BASE_PATH') ? BASE_PATH . '/config/version.php' : realpath(__DIR__ . '/../../../config/version.php');
if ($versionPath && file_exists($versionPath)) {
    $versionConfig = require $versionPath;
    if (is_array($versionConfig) && !empty($versionConfig['version'])) {
        $appVersion = (string)$versionConfig['version'];
    }
}

// Fetch Alert Counts for Sidebar
$db = \App\Core\Database::getInstance()->getConnection();
$badgeCounts = [
    'contacts' => (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn(),
    'abuse'    => (int)$db->query("SELECT COUNT(*) FROM abuse_reports WHERE status = 'pending'")->fetchColumn(),
    'dmca'     => (int)$db->query("SELECT COUNT(*) FROM dmca_reports WHERE status = 'pending'")->fetchColumn(),
    'withdrawals' => \App\Service\FeatureService::rewardsEnabled() ? (int)$db->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn() : 0,
];
$badgeCounts['requests'] = $badgeCounts['contacts'] + $badgeCounts['abuse'] + $badgeCounts['dmca'];

$updateAvailable = false;
try {
    $updateStatus = (new \App\Service\UpdateService())->getStatus(false);
    $updateAvailable = !empty($updateStatus['update_available']);
} catch (\Throwable $e) {
    $updateAvailable = false;
}

$lastCronTimestamp = (int)\App\Model\Setting::get('last_cron_run_timestamp', 0);
$cronOffline = !($lastCronTimestamp > 0 && (time() - $lastCronTimestamp) < 1860);

// Help Tip Logic
$currentUri = $_SERVER['REQUEST_URI'];
$helpFile = '';
$uriPath = strtok($currentUri, '?');
$ppdMinDownloadPercent = max(0, (int)\App\Model\Setting::get('ppd_min_download_percent', '0'));
$showApacheLikePpdFallbackBanner = false;
$apacheLikeDeliveryCount = 0;
$showNginxHealthBanner = false;
$nginxHealthSummary = [
    'skipped_total' => 0,
    'missing_viewer_identity' => 0,
    'missing_client_ip' => 0,
    'last_issue_at' => null,
];
if ($uriPath === '/admin' && $ppdMinDownloadPercent > 0) {
    try {
        $apacheFallbackStmt = $db->query("SELECT COUNT(*) FROM file_servers WHERE delivery_method IN ('apache', 'litespeed') AND status IN ('active', 'read-only')");
        $apacheLikeDeliveryCount = (int)$apacheFallbackStmt->fetchColumn();
        $showApacheLikePpdFallbackBanner = $apacheLikeDeliveryCount > 0;
    } catch (\Throwable $e) {
        $showApacheLikePpdFallbackBanner = false;
        $apacheLikeDeliveryCount = 0;
    }
}
if ($uriPath === '/admin') {
    try {
        $nginxHealthSummary = (new \App\Service\NginxDownloadLogService())->getHealthSummary(24);
        $showNginxHealthBanner = !empty($nginxHealthSummary['has_warning']);
    } catch (\Throwable $e) {
        $showNginxHealthBanner = false;
    }
}

if ($uriPath === '/admin') $helpFile = 'dashboard';
elseif (str_contains($uriPath, '/admin/packages') || str_contains($uriPath, '/admin/package/edit')) $helpFile = 'packages';
elseif (str_contains($uriPath, '/admin/users')) $helpFile = 'users';
elseif (str_contains($uriPath, '/admin/files')) $helpFile = 'files';
elseif (str_contains($uriPath, '/admin/withdrawals') && \App\Service\FeatureService::rewardsEnabled()) $helpFile = 'withdrawals';
elseif (str_contains($uriPath, '/admin/rewards-fraud') && \App\Service\FeatureService::rewardsEnabled()) $helpFile = 'rewards_fraud';
elseif (str_contains($uriPath, '/admin/plugins')) $helpFile = 'plugins';
elseif (str_contains($uriPath, '/admin/resources')) $helpFile = 'resources';
elseif (str_contains($uriPath, '/admin/search')) $helpFile = 'search';
elseif (str_contains($uriPath, '/admin/file-server/migrate')) $helpFile = 'file_server_migrate';
elseif (str_contains($uriPath, '/admin/file-server/add')) $helpFile = 'file_server_add';
elseif (str_contains($uriPath, '/admin/file-server/edit')) $helpFile = 'file_server_edit';
elseif (str_contains($uriPath, '/admin/requests') || str_contains($uriPath, '/admin/contacts') || str_contains($uriPath, '/admin/abuse-reports') || str_contains($uriPath, '/admin/dmca')) $helpFile = 'requests';
elseif (str_contains($uriPath, '/admin/downloads/current')) $helpFile = 'live_downloads';
elseif (str_contains($uriPath, '/admin/docs')) $helpFile = 'docs';
elseif (str_contains($uriPath, '/admin/server-monitoring')) $helpFile = 'monitoring';
elseif (str_contains($uriPath, '/admin/subscriptions')) $helpFile = 'subscriptions';
elseif (str_contains($uriPath, '/admin/support')) $helpFile = 'support';
elseif (str_contains($uriPath, '/admin/status')) $helpFile = 'status';
elseif (str_contains($uriPath, '/admin/logs')) $helpFile = 'status';
elseif (str_contains($uriPath, '/admin/configuration')) {
    $tab = $_GET['tab'] ?? 'general';
    $tabMap = [
        'general' => 'settings',
        'security' => 'security',
        'email' => 'email',
        'storage' => 'file-servers',
        'monetization' => 'ad-placements',
        'seo' => 'seo',
        'cron' => 'cron',
        'downloads' => 'settings',
        'uploads' => 'settings'
    ];
    $helpFile = $tabMap[$tab] ?? 'configuration';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= htmlspecialchars($siteName) ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Keep custom CSS for component overrides (cards, etc) -->
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-body-tertiary sidebar collapse vh-100 sticky-top">
            <div class="position-sticky pt-3 sidebar-sticky text-center">
                <a href="/admin/support" class="text-decoration-none text-dark">
                    <h3 class="mb-4 mt-2 fw-bold">fyuhls</h3>
                </a>
                <div class="small text-muted mb-3">Version <?= htmlspecialchars($appVersion) ?></div>

                <!-- Global Search Widget -->
                <div class="px-3 mb-4">
                    <form action="/admin/search" method="GET">
                        <input type="text" name="q" class="form-control form-control-sm bg-white border shadow-sm rounded" placeholder="Partial ID, email, username, file..." style="font-size: 0.75rem; height: 32px;">
                    </form>
                </div>

                <div class="px-3 mb-4 text-center">
                    <a href="https://www.buymeacoffee.com/softerfish" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-blue.png" alt="Buy Me A Coffee" style="height: 45px !important;width: 170px !important;" ></a>
                </div>
                <ul class="nav flex-column mb-auto text-start">
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'support' ? 'active' : '' ?>" href="/admin/support">
                            <i class="bi bi-bug me-2"></i> Bug Report
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'dashboard' ? 'active' : '' ?>" href="/admin">
                            <i class="bi bi-speedometer2 me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'packages' ? 'active' : '' ?>" href="/admin/packages">
                            <i class="bi bi-box me-2"></i> Packages
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'users' ? 'active' : '' ?>" href="/admin/users">
                            <i class="bi bi-people me-2"></i> Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'files' ? 'active' : '' ?>" href="/admin/files">
                            <i class="bi bi-file-earmark me-2"></i> Files
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($currentUri, '/admin/downloads/current') ? 'active' : '' ?>" href="/admin/downloads/current">
                            <i class="bi bi-cloud-download me-2"></i> Live Downloads
                        </a>
                    </li>
                    <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $helpFile === 'withdrawals' ? 'active' : '' ?>" href="/admin/withdrawals">
                                <i class="bi bi-cash me-2"></i> Withdrawals
                                <?php if ($badgeCounts['withdrawals'] > 0): ?>
                                    <span class="badge bg-danger rounded-pill float-end"><?= $badgeCounts['withdrawals'] ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $helpFile === 'rewards_fraud' ? 'active' : '' ?>" href="/admin/rewards-fraud">
                                <i class="bi bi-shield-exclamation me-2"></i> Rewards Fraud
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'plugins' ? 'active' : '' ?>" href="/admin/plugins">
                            <i class="bi bi-puzzle me-2"></i> Plugins
                        </a>
                    </li>
                    <li class="nav-item mt-3">
                        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase small">
                            <span>Reports & Requests</span>
                        </h6>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($helpFile === 'contacts') ? 'active' : '' ?>" href="/admin/requests">
                            <i class="bi bi-inboxes me-2"></i> Requests
                            <?php if ($badgeCounts['requests'] > 0): ?>
                                <span class="badge bg-danger rounded-pill float-end"><?= $badgeCounts['requests'] ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item mt-3">
                        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted text-uppercase small">
                            <span>System Infrastructure</span>
                        </h6>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_contains($currentUri, '/admin/configuration') ? 'active' : '' ?>" href="/admin/configuration">
                            <i class="bi bi-cpu me-2"></i> Config Hub
                            <?php if ($cronOffline): ?>
                                <span class="badge bg-danger rounded-pill float-end" title="Cron Jobs Offline">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                </span>
                            <?php elseif (\App\Model\Setting::get('db_drift_detected', '0') === '1'): ?>
                                <span class="badge bg-danger p-1 border border-light rounded-circle float-end mt-1" title="Drift Detected"></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'resources' ? 'active' : '' ?>" href="/admin/resources">
                            <i class="bi bi-stars me-2"></i> Resources
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $helpFile === 'status' ? 'active' : '' ?>" href="/admin/status">
                            <i class="bi bi-activity p-1 bg-danger text-white rounded me-2" style="font-size: 0.75rem;"></i> System Status
                            <?php if ($updateAvailable): ?>
                                <span class="badge bg-warning text-dark rounded-pill float-end">Update</span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>

                <hr>
                <ul class="nav flex-column mb-2">
                    <li class="nav-item">
                        <a class="nav-link" href="/">
                            <i class="bi bi-box-arrow-left me-2"></i> Back to Site
                        </a>
                    </li>
                    <li class="nav-item">
                        <form action="/logout" method="POST" class="m-0">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="nav-link text-danger btn btn-link w-100 text-start text-decoration-none">
                                <i class="bi bi-box-arrow-right me-2"></i> Logout
                            </button>
                        </form>
                    </li>
                    <li class="nav-item mt-4">
                        <hr class="mx-3 opacity-25">
                        <a class="nav-link <?= str_contains($currentUri, '/admin/docs') ? 'active' : '' ?>" href="/admin/docs">
                            <i class="bi bi-book me-2"></i> Documentation
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 main-content">
            
            <?php foreach (['success' => 'success', 'error' => 'danger', 'info' => 'info', 'warning' => 'warning'] as $key => $class): ?>
                <?php if (isset($_SESSION[$key])): ?>
                    <div class="alert alert-<?= $class ?> alert-dismissible fade show" role="alert">
                        <?= $_SESSION[$key] ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION[$key]); ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php 
            $dbDrift = \App\Model\Setting::get('db_drift_detected', '0');
            if ($dbDrift === '1'): 
            ?>
                <div class="alert alert-danger d-flex align-items-center justify-content-between mb-4 shadow-sm" role="alert">
                    <div class="d-flex align-items-center">
                        <div>
                            <h6 class="alert-heading fw-bold mb-1">Database Schema Drift Detected!</h6>
                            Your database schema is out of sync with the application code. This can lead to system errors or data loss.
                            <br><small class="opacity-75">Last Error: <?= htmlspecialchars(\App\Model\Setting::get('db_drift_error', 'Unknown Error')) ?></small>
                        </div>
                    </div>
                    <a href="/admin/configuration?tab=security&sec_tab=health" class="btn btn-dark btn-sm px-3 fw-bold">
                        <i class="bi bi-tools me-1"></i> Repair Schema
                    </a>
                </div>
            <?php endif; ?>
            
            <?php 
            clearstatcache();
            $root = defined('BASE_PATH') ? BASE_PATH : realpath(__DIR__ . '/../../..');
            
            $installPath = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'install.php';
            if (file_exists($installPath)): 
            ?>
                <div class="alert alert-danger d-flex align-items-center justify-content-between mb-2" role="alert">
                    <div class="d-flex align-items-center">
                        <div>
                            <strong>Security Warning:</strong> The <code>install.php</code> file is still present on your server. Delete it immediately to prevent unauthorized re-installation.
                        </div>
                    </div>
                    <form action="/admin/delete-setup-file" method="POST" style="margin: 0;" onsubmit="return confirm('Permanently delete install.php?')">
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="type" value="install">
                        <button type="submit" class="btn btn-dark btn-sm px-3 fw-bold">
                            <i class="bi bi-trash-fill me-1"></i> Delete Now
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php 
            $postInstallCheckPath = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'post_install_check.php';
            if (file_exists($postInstallCheckPath)): 
            ?>
                <div class="alert alert-warning d-flex align-items-center justify-content-between mb-2" role="alert">
                    <div class="d-flex align-items-center">
                        <div>
                            <strong>Security Suggestion:</strong> The <code>post_install_check.php</code> file is still present. Delete it once you are done verifying the installation.
                        </div>
                    </div>
                    <form action="/admin/delete-setup-file" method="POST" style="margin: 0;" onsubmit="return confirm('Permanently delete post_install_check.php?')">
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="type" value="post_install_check">
                        <button type="submit" class="btn btn-dark btn-sm px-3 fw-bold">
                            <i class="bi bi-trash-fill me-1"></i> Delete Now
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php 
            $schemaPath = $root . DIRECTORY_SEPARATOR . 'database';
            if (is_dir($schemaPath)):
            ?>
                <div class="alert alert-warning d-flex align-items-center justify-content-between mb-4" role="alert">
                    <div class="d-flex align-items-center">
                        <div>
                            <strong>Security Suggestion:</strong> The <code>database/</code> setup folder is still present. It contains the installer schema file and should be removed after installation.
                        </div>
                    </div>
                    <form action="/admin/delete-setup-file" method="POST" style="margin: 0;" onsubmit="return confirm('Permanently delete the database setup folder?')">
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="type" value="schema">
                        <button type="submit" class="btn btn-dark btn-sm px-3 fw-bold">
                            <i class="bi bi-trash-fill me-1"></i> Delete Now
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php 
            $cfLastSync = \App\Model\Setting::get('cloudflare_last_sync', '0');
            if (empty($cfLastSync) || $cfLastSync === '0'): 
            ?>
                <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
                    <div>
                        <strong>Security Action Required:</strong> Cloudflare IP ranges have not been synced yet. Your site is currently vulnerable to IP spoofing. 
                        Please sync your <a href="/admin/configuration?tab=security&sec_tab=cloudflare" class="alert-link">Security Settings</a> to protect your server.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($showApacheLikePpdFallbackBanner): ?>
                <div class="alert alert-warning d-flex align-items-center justify-content-between mb-4" role="alert">
                    <div class="me-3">
                        <strong>Apache/LiteSpeed payout notice:</strong> One or more storage servers are using <code>Apache Handoff</code> or <code>LiteSpeed Handoff</code> while
                        <code>ppd_min_download_percent</code> is set to <strong><?= (int)$ppdMinDownloadPercent ?>%</strong>.
                        Standard file downloads that need percent-based payout verification, such as normal <code>.zip</code> downloads,
                        will automatically fall back to <strong>App-Controlled (PHP)</strong> so users are only credited after the configured threshold is actually reached.
                    </div>
                    <div class="d-flex gap-2 flex-shrink-0">
                        <a href="/admin/configuration?tab=storage" class="btn btn-dark btn-sm px-3 fw-bold">
                            <i class="bi bi-hdd-network me-1"></i> Review Storage
                        </a>
                        <a href="/admin/docs#storage" class="btn btn-outline-dark btn-sm px-3 fw-bold">
                            <i class="bi bi-book me-1"></i> Read Guide
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($showNginxHealthBanner): ?>
                <div class="alert alert-danger d-flex align-items-center justify-content-between mb-4" role="alert">
                    <div class="me-3">
                        <strong>Nginx payout health warning:</strong>
                        Fyuhls skipped <strong><?= (int)$nginxHealthSummary['skipped_total'] ?></strong> Nginx completion event<?= (int)$nginxHealthSummary['skipped_total'] === 1 ? '' : 's' ?> in the last 24 hours because the
                        completion log could not safely prove downloader identity or client IP.
                        <?php if ((int)$nginxHealthSummary['missing_viewer_identity'] > 0): ?>
                            <span class="d-block mt-1">Missing viewer identity: <strong><?= (int)$nginxHealthSummary['missing_viewer_identity'] ?></strong>. Check that your Nginx completion log includes a numeric <code>viewer_user_id</code>.</span>
                        <?php endif; ?>
                        <?php if ((int)$nginxHealthSummary['missing_client_ip'] > 0): ?>
                            <span class="d-block mt-1">Missing client IP: <strong><?= (int)$nginxHealthSummary['missing_client_ip'] ?></strong>. Check Nginx real-IP restoration if you are behind Cloudflare or another proxy.</span>
                        <?php endif; ?>
                        <?php if (!empty($nginxHealthSummary['last_issue_at'])): ?>
                            <small class="d-block mt-2 opacity-75">Most recent skipped event: <?= htmlspecialchars((string)$nginxHealthSummary['last_issue_at']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 flex-shrink-0">
                        <a href="/admin/docs#storage" class="btn btn-dark btn-sm px-3 fw-bold">
                            <i class="bi bi-shield-check me-1"></i> Fix Nginx Setup
                        </a>
                        <a href="/admin/configuration?tab=downloads" class="btn btn-outline-dark btn-sm px-3 fw-bold">
                            <i class="bi bi-sliders me-1"></i> Review Downloads
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Dynamic Help Notice -->
            <?php if ($helpFile): ?>
                <div class="card bg-light border-0 shadow-sm mb-4">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <span class="fw-bold small text-uppercase">Page Guide: <?= ucwords(str_replace(['-', '_'], ' ', $helpFile)) ?></span>
                            </div>
                            <button class="btn btn-sm btn-outline-primary py-0" type="button" data-bs-toggle="collapse" data-bs-target="#pageGuideContent">
                                View Guide
                            </button>
                        </div>
                        <div class="collapse mt-3" id="pageGuideContent">
                            <hr class="opacity-10">
                            <?php 
                            $guidePath = __DIR__ . "/help/{$helpFile}.php";
                            if (file_exists($guidePath)) {
                                include $guidePath;
                            } else {
                                echo "<p class='small text-muted text-center py-3'>Full documentation for this page is coming soon. <br>Technical Key: <code>{$helpFile}</code></p>";
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
