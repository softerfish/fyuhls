<?php
include __DIR__ . '/../header.php';
include __DIR__ . '/../partials/shell_helpers.php';
ob_start();
?>
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
<?php
$configurationActions = ob_get_clean();
renderAdminPageHeader('System Configuration', 'Manage site-wide infrastructure and enterprise settings.', $configurationActions);
?>

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
    <div class="config-soft-callout config-soft-callout--success shadow-sm mb-4">
        <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($successMessage ?? 'Configuration updated successfully.') ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="config-soft-callout config-soft-callout--danger shadow-sm mb-4">
        <h6 class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i> Configuration Error</h6>
        <ul class="mb-0 small">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (($demoMode ?? '0') === '1'): ?>
    <div class="config-soft-callout config-soft-callout--danger shadow-sm mb-4">
        <i class="bi bi-eye-slash-fill me-2"></i> Demo mode is active. The designated demo admin account is redacted and read-only, while other admin accounts keep normal access. Disable demo mode here when you are ready to leave it.
    </div>
<?php endif; ?>

<?php
$lastCronTimestamp = (int)\App\Model\Setting::get('last_cron_run_timestamp', 0);
$cronOffline = !($lastCronTimestamp > 0 && (time() - $lastCronTimestamp) < 1860);
?>

<?php
ob_start();
?>
    <div class="config-tab-groups px-3 py-3" id="configTabs">
        <div class="config-tab-group">
            <div class="config-tab-group__label">Site</div>
            <ul class="nav nav-tabs card-header-tabs m-0 border-0 config-tab-group__nav">
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'general' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=general">
                        <i class="bi bi-gear me-2"></i> General
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'seo' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=seo">
                        <i class="bi bi-graph-up-arrow me-2"></i> SEO
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'link_checker' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=link_checker">
                        <i class="bi bi-link-45deg me-2"></i> Link Checker
                    </a>
                </li>
            </ul>
        </div>

        <div class="config-tab-group">
            <div class="config-tab-group__label">Security</div>
            <ul class="nav nav-tabs card-header-tabs m-0 border-0 config-tab-group__nav">
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'security' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=security">
                        <i class="bi bi-shield-lock me-2"></i> Security
                        <?php if (!empty($securityNoticeCount)): ?>
                            <span class="badge bg-warning text-dark ms-2"><?= (int)$securityNoticeCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </div>

        <div class="config-tab-group">
            <div class="config-tab-group__label">Storage &amp; Delivery</div>
            <ul class="nav nav-tabs card-header-tabs m-0 border-0 config-tab-group__nav">
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'storage' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=storage">
                        <i class="bi bi-hdd-network me-2"></i> Storage Servers
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
            </ul>
        </div>

        <div class="config-tab-group">
            <div class="config-tab-group__label">Revenue</div>
            <ul class="nav nav-tabs card-header-tabs m-0 border-0 config-tab-group__nav">
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'monetization' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=monetization">
                        <i class="bi bi-megaphone me-2"></i> Monetization
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'email' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=email">
                        <i class="bi bi-envelope-paper me-2"></i> Email
                    </a>
                </li>
            </ul>
        </div>

        <div class="config-tab-group">
            <div class="config-tab-group__label">System</div>
            <ul class="nav nav-tabs card-header-tabs m-0 border-0 config-tab-group__nav">
                <li class="nav-item">
                    <a class="nav-link border-0 py-3 px-4 <?= $activeTab === 'tickets' ? 'active fw-bold border-bottom border-primary border-3' : 'text-muted' ?>" href="?tab=tickets">
                        <i class="bi bi-life-preserver me-2"></i> Tickets
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
    </div>
<?php
$configTabsHeader = ob_get_clean();
renderAdminCardStart(null, ['headerHtml' => $configTabsHeader, 'bodyClass' => 'card-body p-4']);
?>
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
<?php renderAdminCardEnd(); ?>

<style>
html {
    scroll-behavior: smooth;
}
.config-tab-groups {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.config-tab-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.config-tab-group__label {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    padding: 0 1rem;
}
.config-tab-group__nav {
    gap: 0.25rem;
    flex-wrap: wrap;
}
.nav-tabs .nav-link.active {
    background: transparent !important;
    color: var(--bs-primary) !important;
    border-bottom: 3px solid var(--bs-primary) !important;
}
.nav-tabs .nav-link:hover {
    background: rgba(0,0,0,0.02);
}
.config-section-shell {
    display: grid;
    grid-template-columns: minmax(200px, 240px) minmax(0, 1fr);
    gap: 1.5rem;
    align-items: start;
}
.config-section-nav {
    position: sticky;
    top: 1rem;
    align-self: start;
    border-right: 1px solid #e5e7eb;
    padding-right: 1rem;
}
.config-section-nav .nav-link {
    border: 0;
    border-radius: 8px;
    color: #475569;
    font-weight: 600;
    margin-bottom: 0.4rem;
    padding: 0.8rem 0.9rem;
    background: transparent;
}
.config-section-nav .nav-link:hover {
    background: rgba(37, 99, 235, 0.05);
    color: #1d4ed8;
}
.config-section-nav .nav-link.active {
    background: rgba(37, 99, 235, 0.10);
    color: #1d4ed8;
}
.config-section-nav__eyebrow {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 0.9rem;
    padding: 0 0.25rem;
}
.config-section-content {
    min-width: 0;
}
.config-section-content [id] {
    scroll-margin-top: 1.1rem;
}
.config-section-intro {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.config-section-intro__title {
    font-size: 1.15rem;
    font-weight: 800;
    margin: 0 0 0.25rem;
}
.config-section-intro__text {
    margin: 0;
    color: #64748b;
    font-size: 0.95rem;
}
.config-summary-chips {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
    margin: 0;
    padding: 0;
    list-style: none;
}
.config-summary-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.7rem;
    border-radius: 999px;
    border: 1px solid #e5e7eb;
    background: #f8fafc;
    color: #334155;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
}
.config-summary-chip--info {
    border-color: #dbeafe;
    background: #eff6ff;
    color: #1d4ed8;
}
.config-summary-chip--warning {
    border-color: #fde68a;
    background: #fffbeb;
    color: #b45309;
}
.config-summary-chip--success {
    border-color: #bbf7d0;
    background: #f0fdf4;
    color: #15803d;
}
.config-summary-chip--danger {
    border-color: #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}
.config-help-panel {
    border: 1px solid #dbeafe;
    background: #f8fbff;
    border-radius: 10px;
    margin-bottom: 1.25rem;
    overflow: hidden;
}
.config-help-panel summary {
    list-style: none;
    cursor: pointer;
    padding: 0.95rem 1rem;
    font-weight: 700;
    color: #1e3a8a;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.config-help-panel summary::-webkit-details-marker {
    display: none;
}
.config-help-panel summary::after {
    content: '+';
    font-size: 1rem;
    line-height: 1;
}
.config-help-panel[open] summary::after {
    content: '-';
}
.config-help-panel__body {
    padding: 0 1rem 1rem;
    color: #475569;
    font-size: 0.9rem;
}
.config-help-panel__body p:last-child,
.config-help-panel__body ul:last-child,
.config-help-panel__body div:last-child {
    margin-bottom: 0;
}
.config-section-card {
    margin-bottom: 1.25rem !important;
}
.config-section-card .card-header,
.config-section-card .card-body,
.config-section-card .card-footer {
    padding-left: 1.25rem;
    padding-right: 1.25rem;
}
.config-section-card .card-header {
    font-weight: 700;
}
.config-section-card .table thead th {
    font-size: 0.72rem;
    letter-spacing: 0.04em;
}
.config-section-card .table th,
.config-section-card .table td {
    padding-top: 0.9rem;
    padding-bottom: 0.9rem;
}
.config-form-note {
    display: block;
    margin-top: 0.45rem;
    color: #64748b;
    font-size: 0.82rem;
    line-height: 1.45;
}
.config-soft-callout {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #f8fafc;
    padding: 1rem 1.1rem;
}
.config-soft-callout--info {
    border-color: #dbeafe;
    background: #f8fbff;
    color: #1e3a8a;
}
.config-soft-callout--warning {
    border-color: #fde68a;
    background: #fffbeb;
    color: #92400e;
}
.config-soft-callout--success {
    border-color: #bbf7d0;
    background: #f0fdf4;
    color: #166534;
}
.config-soft-callout--danger {
    border-color: #fecaca;
    background: #fef2f2;
    color: #991b1b;
}
.config-soft-callout p:last-child,
.config-soft-callout div:last-child,
.config-soft-callout ul:last-child {
    margin-bottom: 0;
}
.config-danger-zone,
.config-utility-zone {
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    padding: 1rem 1.1rem;
    margin-top: 1rem;
}
.config-danger-zone {
    border-color: #fecaca;
    background: linear-gradient(180deg, #fff7f7 0%, #fef2f2 100%);
}
.config-utility-zone {
    border-color: #dbeafe;
    background: linear-gradient(180deg, #fbfdff 0%, #eff6ff 100%);
}
.config-danger-zone__title,
.config-utility-zone__title {
    font-size: 0.82rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin: 0 0 0.45rem;
}
.config-danger-zone__title { color: #b91c1c; }
.config-utility-zone__title { color: #1d4ed8; }
.config-danger-zone__text,
.config-utility-zone__text {
    color: #475569;
    font-size: 0.88rem;
    margin: 0 0 0.75rem;
}
.config-danger-zone__actions,
.config-utility-zone__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    align-items: center;
}
.config-sticky-save {
    position: sticky;
    bottom: 0;
    z-index: 20;
    margin-top: 1.5rem;
    padding: 1rem 1.25rem;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(10px);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 -4px 20px rgba(15, 23, 42, 0.06);
}
.config-sticky-save__text {
    color: #64748b;
    font-size: 0.88rem;
    margin: 0;
}
.config-sticky-save .btn {
    white-space: nowrap;
}
.config-status-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.config-status-card {
    min-height: 160px;
}
@media (min-width: 1200px) {
    .config-tab-groups {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem 1.25rem;
    }
}
@media (max-width: 991.98px) {
    .config-section-shell {
        grid-template-columns: 1fr;
    }
    .config-section-nav {
        position: static;
        border-right: 0;
        border-bottom: 1px solid #e5e7eb;
        padding-right: 0;
        padding-bottom: 1rem;
        margin-bottom: 0.25rem;
    }
    .config-section-intro,
    .config-sticky-save {
        flex-direction: column;
        align-items: stretch;
    }
    .config-summary-chips {
        justify-content: flex-start;
    }
    .config-status-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sectionShells = document.querySelectorAll('.config-section-shell');
    sectionShells.forEach(function(shell) {
        const navLinks = Array.from(shell.querySelectorAll('.config-section-nav a.nav-link[href^="#"]'));
        if (!navLinks.length) {
            return;
        }

        const targets = navLinks
            .map(function(link) {
                const selector = link.getAttribute('href');
                if (!selector) {
                    return null;
                }
                const target = shell.querySelector(selector);
                return target ? { link: link, target: target } : null;
            })
            .filter(Boolean);

        if (!targets.length) {
            return;
        }

        const setActiveLink = function(activeLink) {
            navLinks.forEach(function(link) {
                link.classList.toggle('active', link === activeLink);
            });
        };

        navLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                setActiveLink(link);
            });
        });

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(function(entries) {
                const visible = entries
                    .filter(function(entry) { return entry.isIntersecting; })
                    .sort(function(a, b) { return b.intersectionRatio - a.intersectionRatio; });

                if (visible.length) {
                    const match = targets.find(function(item) {
                        return item.target === visible[0].target;
                    });
                    if (match) {
                        setActiveLink(match.link);
                    }
                }
            }, {
                root: null,
                rootMargin: '-20% 0px -60% 0px',
                threshold: [0.15, 0.4, 0.7]
            });

            targets.forEach(function(item) {
                observer.observe(item.target);
            });
        }
    });
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>
