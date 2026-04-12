<?php include 'header.php'; ?>

    <div class="page-header mb-4 flex-wrap gap-3">
        <div>
            <h1>Platform Documentation</h1>
            <p class="text-muted">Reference guides for every main admin page and the major configuration areas behind them.</p>
            <div class="docs-search-wrap position-relative mt-3">
                <input type="text" id="docsSearchInput" class="form-control border-0 shadow-sm" placeholder="Search docs by page, option, or feature...">
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3 px-4">
            <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
                <div>
                    <h6 class="fw-bold text-dark mb-1">Quick Jump</h6>
                    <div class="small text-muted">Jump directly to the admin page or feature guide you need.</div>
                </div>
                <div class="d-flex flex-wrap gap-2 docs-toc-list" id="docsToc">
                    <a class="docs-toc-item active btn btn-sm btn-outline-primary" href="#dashboard">Dashboard</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#packages">Packages</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#users">Users</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#files">Files</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#requests">Requests</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#contacts">Contacts</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#abuse">Abuse</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#dmca">DMCA</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#live-downloads">Live Downloads</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#withdrawals">Withdrawals</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#rewards-fraud">Rewards Fraud</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#subscriptions">Subscriptions</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#plugins">Plugins</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#configuration">Config Hub</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#resources">Resources</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#seo">SEO</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#status">System Status</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#monitoring">Server Monitoring</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#support">Support Center</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#api">API And Integrations</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#search">Admin Search</a>
                    <a class="docs-toc-item btn btn-sm btn-outline-primary" href="#storage">Storage Nodes</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4" id="docsGrid">
                <div class="col-12 doc-module" id="dashboard" data-keywords="dashboard analytics stats growth todo activity support bundle">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-speedometer2 text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Dashboard</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/dashboard.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="packages" data-keywords="packages upload limits storage wait time expiry ads premium free">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-box text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Packages</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/packages.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="users" data-keywords="users accounts admin ban unban delete package search exact">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-people text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Users</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/users.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="files" data-keywords="files filename encrypted delete purge server owner">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-file-earmark text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Files</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/files.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="requests" data-keywords="requests contact abuse dmca inbox reply note status moderation">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-inboxes text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Requests</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/requests.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="contacts" data-keywords="contacts messages replies smtp captcha support inbox">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-envelope text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Contacts</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/contacts.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="abuse" data-keywords="abuse malware phishing tos reports delete uploader moderation">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-shield-fill-exclamation text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Abuse Reports</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/abuse.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="dmca" data-keywords="dmca takedown copyright notices counter notice legal requests">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-file-earmark-text text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">DMCA</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/dmca.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="live-downloads" data-keywords="live downloads active connections current tracking ip user guest">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-cloud-download text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Live Downloads</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/live_downloads.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="withdrawals" data-keywords="withdrawals rewards payout paid approved rejected note">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-cash-coin text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Withdrawals</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/withdrawals.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="rewards-fraud" data-keywords="rewards fraud held flagged risk review queue cloudflare proxycheck intelligence">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-shield-exclamation text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Rewards Fraud</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/rewards_fraud.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="subscriptions" data-keywords="subscriptions premium expiry renewals packages status">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-arrow-repeat text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Subscriptions</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/subscriptions.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="plugins" data-keywords="plugins modules install activate deactivate webmaster custom">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-puzzle text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Plugins</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/plugins.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="configuration" data-keywords="configuration config hub general security email storage monetization seo cron cron jobs downloads uploads">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-cpu text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Config Hub</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/configuration.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="resources" data-keywords="resources sponsors partners proxycheck hosting partnerships supporters themasoftware">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-stars text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Resources</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/resources.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="seo" data-keywords="seo sitemap robots canonical title meta description schema search console indexing file pages social graph">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-graph-up-arrow text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">SEO</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/seo.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="status" data-keywords="status logs ffmpeg gd support diagnostics writable health">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-activity text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">System Status</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/status.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="monitoring" data-keywords="monitoring storage nodes uptime latency failures history">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-hdd-rack text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Server Monitoring</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/monitoring.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="support" data-keywords="support support center sanitized json update updater github release diagnostics email">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-life-preserver text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Support Center</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/support.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="api" data-keywords="api tokens scopes multipart managed upload resumable downloads integrations curl node php">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-code-slash text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">API And Integrations</h5>
                        </div>
                        <div class="card-body pt-0">
                            <div class="p-1">
                                <p class="mb-4">Fyuhls includes a public API for account-bound integrations, desktop tools, and direct upload workflows.</p>
                                <ul class="extra-small text-muted mb-4">
                                    <li class="mb-2"><strong>Authentication:</strong> Personal API tokens are tied to a user account and can be scoped for uploads, file reads, and download-link actions.</li>
                                    <li class="mb-2"><strong>Uploads:</strong> API clients can create multipart upload sessions, request signed part URLs, report completed parts, resume interrupted uploads, and complete the session.</li>
                                    <li class="mb-2"><strong>Managed uploads:</strong> Simpler clients can use the managed-upload shortcut instead of orchestrating multipart directly.</li>
                                    <li class="mb-2"><strong>Bucket CORS still matters:</strong> Direct multipart uploads to B2, Wasabi, R2, and other S3-compatible providers still depend on the bucket allowing the site origin and exposing <code>ETag</code>.</li>
                                    <li class="mb-2"><strong>Download links stay app-controlled:</strong> API clients request a signed link, but Fyuhls still decides whether the final transfer uses CDN, PHP, Nginx, Apache, or LiteSpeed.</li>
                                    <li class="mb-2"><strong>Apache/LiteSpeed note:</strong> If the site requires percent-based payout verification for ordinary downloads, those handoff modes can still fall back to PHP for standard files.</li>
                                    <li><strong>References:</strong> Use the frontend <code>/api</code> page for the live endpoint reference and examples.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="search" data-keywords="search admin search exact username email short id filename">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-search text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Admin Search</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/search.php'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="storage" data-keywords="storage nodes servers b2 wasabi r2 s3 local read only disabled active backblaze bucket cors etag multipart native api app keys bucket picker load my b2 buckets apply fyuhls cors auto fill endpoint region php nginx apache litespeed ppd standard files streaming x-accel-redirect x-sendfile missing_viewer_identity missing_client_ip nginx completion log cloudflare real ip">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-hdd-network text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Storage Nodes</h5>
                        </div>
                        <div class="card-body pt-0">
                            <?php include 'help/file-servers.php'; ?>
                            <div class="row mt-4">
                                <div class="col-md-4 border-end">
                                    <h6 class="fw-bold fs-7 text-uppercase text-muted mb-3">Add Node</h6>
                                    <?php include 'help/file_server_add.php'; ?>
                                </div>
                                <div class="col-md-4 border-end">
                                    <h6 class="fw-bold fs-7 text-uppercase text-muted mb-3">Edit Node</h6>
                                    <?php include 'help/file_server_edit.php'; ?>
                                </div>
                                <div class="col-md-4">
                                    <h6 class="fw-bold fs-7 text-uppercase text-muted mb-3">Migrate Files</h6>
                                    <?php include 'help/file_server_migrate.php'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 doc-module" id="supporting-guides" data-keywords="security email cron cron jobs settings monetization uploads downloads multipart support api rewards fraud requests archive dmca captcha proxycheck cloudflare templates">
                    <div class="card page-guide-card border-0 shadow-sm overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                            <i class="bi bi-journal-text text-primary me-2 fs-5"></i>
                            <h5 class="mb-0 fw-bold">Supporting Tab Guides</h5>
                        </div>
                        <div class="card-body pt-0">
                            <div class="row">
                                <div class="col-md-6 border-end">
                                    <h6 class="fw-bold fs-7 text-uppercase text-muted mb-3">General / Downloads / Uploads</h6>
                                    <?php include 'help/settings.php'; ?>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="fw-bold fs-7 text-uppercase text-muted mb-3">Security / Email / Monetization / SEO / Cron Jobs</h6>
                                <?php include 'help/security.php'; ?>
                                <?php include 'help/email.php'; ?>
                                <?php include 'help/ad-placements.php'; ?>
                                <?php include 'help/seo.php'; ?>
                                <?php include 'help/cron.php'; ?>
                            </div>
                        </div>
                </div>
            </div>
        </div>
    </div>

<script>
function filterDocs() {
    const input = document.getElementById('docsSearchInput');
    const filter = input.value.toLowerCase();
    const modules = document.getElementsByClassName('doc-module');

    for (let i = 0; i < modules.length; i++) {
        const keywords = (modules[i].getAttribute('data-keywords') || '').toLowerCase();
        const title = modules[i].getElementsByTagName('h5')[0].innerText.toLowerCase();
        modules[i].style.display = (keywords.includes(filter) || title.includes(filter)) ? '' : 'none';
    }
}

window.addEventListener('scroll', () => {
    let current = '';
    const sections = document.querySelectorAll('.doc-module');
    const navItems = document.querySelectorAll('.docs-toc-item');

    sections.forEach(section => {
        const sectionTop = section.offsetTop;
        if (pageYOffset >= sectionTop - 150) {
            current = section.getAttribute('id');
        }
    });

    navItems.forEach(item => {
        item.classList.remove('active');
        if ((item.getAttribute('href') || '').includes(current)) {
            item.classList.add('active');
            item.classList.remove('btn-outline-primary');
            item.classList.add('btn-primary');
        } else {
            item.classList.remove('btn-primary');
            item.classList.add('btn-outline-primary');
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const docsSearchInput = document.getElementById('docsSearchInput');
    if (docsSearchInput) {
        docsSearchInput.addEventListener('input', filterDocs);
    }
});
</script>

<?php include 'footer.php'; ?>

<style>
.docs-search-wrap{width:min(100%,380px)}
</style>
