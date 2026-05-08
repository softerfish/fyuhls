<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';

$docGroups = [
    [
        'id' => 'getting-started',
        'title' => 'Getting Started',
        'summary' => 'First-stop guides for setup, orientation, and the core admin controls that shape the whole install.',
        'cards' => [
            [
                'id' => 'dashboard',
                'title' => 'Dashboard',
                'summary' => 'Read site health, queue pressure, and operational alerts before changing anything.',
                'icon' => 'bi-speedometer2',
                'keywords' => 'dashboard analytics stats growth todo activity support bundle overview getting started',
                'include' => 'help/dashboard.php',
            ],
            [
                'id' => 'configuration',
                'title' => 'Config Hub',
                'summary' => 'Site-wide settings for general behavior, security, email, tickets, downloads, uploads, cron, monetization, and SEO.',
                'icon' => 'bi-cpu',
                'keywords' => 'configuration config hub general security email tickets storage monetization seo cron downloads uploads link checker settings',
                'include' => 'help/configuration.php',
            ],
            [
                'id' => 'status',
                'title' => 'System Status',
                'summary' => 'Check writable paths, PHP extensions, logs, queues, and health warnings before troubleshooting deeper.',
                'icon' => 'bi-activity',
                'keywords' => 'status logs ffmpeg gd support diagnostics writable health queue cron',
                'include' => 'help/status.php',
            ],
            [
                'id' => 'support',
                'title' => 'Support Center',
                'summary' => 'Generate sanitized diagnostics, review updater context, and prepare clean escalation bundles.',
                'icon' => 'bi-life-preserver',
                'keywords' => 'support support center sanitized json update updater release diagnostics email bundle',
                'include' => 'help/support.php',
            ],
            [
                'id' => 'resources',
                'title' => 'Resources',
                'summary' => 'Curated operator-facing partners, sponsors, and services worth considering during setup and growth.',
                'icon' => 'bi-stars',
                'keywords' => 'resources sponsors partners proxycheck hosting partnerships supporters affiliates setup',
                'include' => 'help/resources.php',
            ],
        ],
    ],
    [
        'id' => 'daily-operations',
        'title' => 'Daily Operations',
        'summary' => 'The queue-driven work most admins touch every day: tickets, moderation, files, payouts, and live traffic.',
        'cards' => [
            [
                'id' => 'requests',
                'title' => 'Tickets',
                'summary' => 'Unified queue for support tickets, contact submissions, abuse reports, and DMCA notices.',
                'icon' => 'bi-inboxes',
                'keywords' => 'tickets requests support contact abuse dmca queue reply note status moderation stale search filters',
                'include' => 'help/requests.php',
            ],
            [
                'id' => 'files',
                'title' => 'Files',
                'summary' => 'Investigate ownership, filenames, storage placement, and moderation actions on individual files.',
                'icon' => 'bi-file-earmark',
                'keywords' => 'files filename encrypted delete purge server owner moderation',
                'include' => 'help/files.php',
            ],
            [
                'id' => 'live-downloads',
                'title' => 'Live Downloads',
                'summary' => 'See active transfer sessions and concurrency pressure when connection tracking is enabled.',
                'icon' => 'bi-cloud-download',
                'keywords' => 'live downloads active connections current tracking ip user guest',
                'include' => 'help/live_downloads.php',
            ],
            [
                'id' => 'withdrawals',
                'title' => 'Withdrawals',
                'summary' => 'Review payout requests, leave notes, and keep the reward-money trail documented.',
                'icon' => 'bi-cash-coin',
                'keywords' => 'withdrawals rewards payout paid approved rejected note finance',
                'include' => 'help/withdrawals.php',
            ],
            [
                'id' => 'rewards-fraud',
                'title' => 'Rewards Fraud',
                'summary' => 'Review held earnings, investigate risk signals, and decide when to release or suppress reward events.',
                'icon' => 'bi-shield-exclamation',
                'keywords' => 'rewards fraud held flagged risk review queue cloudflare proxycheck intelligence',
                'include' => 'help/rewards_fraud.php',
            ],
        ],
    ],
    [
        'id' => 'users-billing',
        'title' => 'Users & Billing',
        'summary' => 'Package design, subscription state, and account operations that change what users can do and what they pay for.',
        'cards' => [
            [
                'id' => 'users',
                'title' => 'Users',
                'summary' => 'Find accounts, review status, change packages, and handle moderation or access questions.',
                'icon' => 'bi-people',
                'keywords' => 'users accounts admin ban unban delete package search exact',
                'include' => 'help/users.php',
            ],
            [
                'id' => 'packages',
                'title' => 'Packages',
                'summary' => 'Set package limits, user experience rules, reward participation, and checkout-facing plan behavior.',
                'icon' => 'bi-box',
                'keywords' => 'packages upload limits storage wait time expiry ads premium free rewards participation pricing clone',
                'include' => 'help/packages.php',
            ],
            [
                'id' => 'subscriptions',
                'title' => 'Subscriptions',
                'summary' => 'Track paid plan state, renewal windows, and package changes that affect entitlement timing.',
                'icon' => 'bi-arrow-repeat',
                'keywords' => 'subscriptions premium expiry renewals packages status billing',
                'include' => 'help/subscriptions.php',
            ],
        ],
    ],
    [
        'id' => 'content-moderation',
        'title' => 'Content & Moderation',
        'summary' => 'Public-facing copy, complaint handling, and the filtered moderation views that sit behind the main ticket queue.',
        'cards' => [
            [
                'id' => 'site-content',
                'title' => 'Site Content',
                'summary' => 'Manage public copy, previews, locales, revisions, and imports without editing theme files.',
                'icon' => 'bi-pencil-square',
                'keywords' => 'site content markdown homepage faq preview revisions theme helpers import export locale footer',
                'include' => 'help/site_content.php',
            ],
            [
                'id' => 'contacts',
                'title' => 'Contacts',
                'summary' => 'Reference view for the contact intake type inside the unified ticket system.',
                'icon' => 'bi-envelope',
                'keywords' => 'contacts messages replies smtp captcha support inbox ticket type',
                'include' => 'help/contacts.php',
            ],
            [
                'id' => 'abuse',
                'title' => 'Abuse Reports',
                'summary' => 'Reference view for abuse-report intake and file-removal style moderation work.',
                'icon' => 'bi-shield-fill-exclamation',
                'keywords' => 'abuse malware phishing tos reports delete uploader moderation ticket type',
                'include' => 'help/abuse.php',
            ],
            [
                'id' => 'dmca',
                'title' => 'DMCA',
                'summary' => 'Reference view for copyright notices, link review, and removable-file processing.',
                'icon' => 'bi-file-earmark-text',
                'keywords' => 'dmca takedown copyright notices counter notice legal requests ticket type',
                'include' => 'help/dmca.php',
            ],
        ],
    ],
    [
        'id' => 'storage-delivery',
        'title' => 'Storage & Delivery',
        'summary' => 'Everything tied to file placement, delivery methods, CDN or object-storage behavior, and transfer troubleshooting.',
        'cards' => [
            [
                'id' => 'storage',
                'title' => 'Storage Nodes',
                'summary' => 'Manage local and object-storage nodes, delivery methods, CORS, migrations, and provider-specific helpers.',
                'icon' => 'bi-hdd-network',
                'keywords' => 'storage nodes servers b2 wasabi r2 s3 local read only disabled active backblaze bucket cors etag multipart native api app keys bucket picker load my b2 buckets apply fyuhls cors auto fill endpoint region php nginx apache litespeed ppd standard files streaming x-accel-redirect x-sendfile missing_viewer_identity missing_client_ip nginx completion log cloudflare real ip',
                'include' => 'help/file-servers.php',
            ],
            [
                'id' => 'delivery',
                'title' => 'Downloads & Delivery',
                'summary' => 'Understand where delivery is configured and how PHP, CDN, Nginx, Apache, and LiteSpeed paths differ.',
                'icon' => 'bi-cloud-arrow-down',
                'keywords' => 'downloads cdn delivery methods nginx x-sendfile litespeed object storage redirects completion log tracking',
                'include' => 'help/download_delivery.php',
            ],
            [
                'id' => 'file-manager',
                'title' => 'File Manager',
                'summary' => 'Frontend file-management behavior, useful when a support or QA question is really a user workflow question.',
                'icon' => 'bi-folder2-open',
                'keywords' => 'file manager trash restore folders search filters grid list quota uploads sharing user support',
                'include' => 'help/file_manager.php',
            ],
        ],
    ],
    [
        'id' => 'security-infrastructure',
        'title' => 'Security & Infrastructure',
        'summary' => 'Identity protection, email delivery, monitoring, plugins, and the system controls that keep the install trustworthy.',
        'cards' => [
            [
                'id' => 'security-supporting',
                'title' => 'Security',
                'summary' => 'ProxyCheck enforcement, 2FA, encryption keys, captcha, Cloudflare trust, and schema or encryption maintenance.',
                'icon' => 'bi-shield-lock',
                'keywords' => 'security proxycheck vpn enforcement intelligence scope 2fa captcha cloudflare migration encryption schema repair',
                'include' => 'help/security.php',
            ],
            [
                'id' => 'email-supporting',
                'title' => 'Email',
                'summary' => 'SMTP delivery, queue testing, template editing, ticket emails, and provider troubleshooting.',
                'icon' => 'bi-envelope-paper',
                'keywords' => 'email smtp templates ticket emails queue test tools hostinger provider',
                'include' => 'help/email.php',
            ],
            [
                'id' => 'plugins',
                'title' => 'Plugins',
                'summary' => 'Extend or disable optional behavior safely while keeping the install supportable.',
                'icon' => 'bi-puzzle',
                'keywords' => 'plugins modules install activate deactivate webmaster custom',
                'include' => 'help/plugins.php',
            ],
            [
                'id' => 'monitoring',
                'title' => 'Server Monitoring',
                'summary' => 'Watch storage-node availability and uptime trends before they turn into delivery or migration issues.',
                'icon' => 'bi-hdd-rack',
                'keywords' => 'monitoring storage nodes uptime latency failures history',
                'include' => 'help/monitoring.php',
            ],
        ],
    ],
    [
        'id' => 'troubleshooting-reference',
        'title' => 'Troubleshooting & Reference',
        'summary' => 'Fast references for search, API work, and the shared settings tabs that many other pages depend on.',
        'cards' => [
            [
                'id' => 'search',
                'title' => 'Admin Search',
                'summary' => 'Find users, files, and records quickly when you know an email, username, ID, or filename fragment.',
                'icon' => 'bi-search',
                'keywords' => 'search admin search exact username email short id filename',
                'include' => 'help/search.php',
            ],
            [
                'id' => 'api',
                'title' => 'API & Integrations',
                'summary' => 'Token-based auth, multipart uploads, resumable flows, and integration expectations.',
                'icon' => 'bi-code-slash',
                'keywords' => 'api tokens scopes multipart managed upload resumable downloads integrations curl node php',
                'html' => <<<'HTML'
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
HTML,
            ],
            [
                'id' => 'supporting-guides-detail',
                'title' => 'Shared Tab Guides',
                'summary' => 'General, downloads, uploads, SEO, cron, monetization, and other shared config areas referenced by many pages.',
                'icon' => 'bi-journal-text',
                'keywords' => 'security email cron cron jobs settings monetization uploads downloads multipart support api rewards fraud requests archive dmca captcha proxycheck cloudflare templates tickets',
            ],
        ],
    ],
];

function renderDocsModuleStart(string $icon, string $title): void
{
    ob_start();
    ?>
    <div class="d-flex align-items-center">
        <i class="bi <?= htmlspecialchars($icon) ?> text-primary me-2 fs-5"></i>
        <h5 class="mb-0 fw-bold"><?= htmlspecialchars($title) ?></h5>
    </div>
    <?php
    $headerHtml = ob_get_clean();
    renderAdminCardStart(null, ['headerHtml' => $headerHtml, 'bodyClass' => 'pt-0', 'cardClass' => 'page-guide-card overflow-hidden']);
}

function renderDocsModuleEnd(): void
{
    renderAdminCardEnd();
}

ob_start();
?>
<div class="docs-search-wrap position-relative mt-3">
    <input type="text" id="docsSearchInput" class="form-control border-0 shadow-sm" placeholder="Search docs by task, page, setting, or feature...">
</div>
<?php
$docsActions = ob_get_clean();
renderAdminPageHeader('Platform Documentation', 'Task-oriented admin docs, page guides, and operational references that match the current interface.', $docsActions);
?>

<?php renderAdminCardStart('Start Here', ['bodyClass' => 'py-3 px-4', 'cardClass' => 'mb-4']); ?>
<div class="row g-3">
    <div class="col-lg-4">
        <a href="#getting-started" class="text-decoration-none">
            <div class="docs-summary-card h-100">
                <div class="docs-summary-icon bg-primary-subtle text-primary"><i class="bi bi-rocket-takeoff"></i></div>
                <div>
                    <div class="fw-semibold text-dark mb-1">First-Time Setup</div>
                    <div class="small text-muted">Start with Dashboard, Config Hub, System Status, and Support Center.</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4">
        <a href="#daily-operations" class="text-decoration-none">
            <div class="docs-summary-card h-100">
                <div class="docs-summary-icon bg-warning-subtle text-warning-emphasis"><i class="bi bi-kanban"></i></div>
                <div>
                    <div class="fw-semibold text-dark mb-1">Daily Queue Work</div>
                    <div class="small text-muted">Tickets, moderation, withdrawals, and live-download operations.</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-lg-4">
        <a href="#troubleshooting-reference" class="text-decoration-none">
            <div class="docs-summary-card h-100">
                <div class="docs-summary-icon bg-success-subtle text-success-emphasis"><i class="bi bi-tools"></i></div>
                <div>
                    <div class="fw-semibold text-dark mb-1">Cron, Email, and Delivery</div>
                    <div class="small text-muted">Jump into SMTP, storage, CDN, search, and operational troubleshooting.</div>
                </div>
            </div>
        </a>
    </div>
</div>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Common Task Guides', ['bodyClass' => 'py-3 px-4', 'cardClass' => 'mb-4']); ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="docs-task-card">
            <div class="fw-semibold text-dark mb-2">Respond to tickets and complaints</div>
            <ul class="small text-muted mb-0 ps-3">
                <li>Use <a href="#requests">Tickets</a> for support, contact, abuse, and DMCA work.</li>
                <li>Use internal notes for handoff context and close items only after the user-facing work is truly done.</li>
                <li>For DMCA file removals, process matched files from the ticket detail panel so the audit trail stays attached.</li>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="docs-task-card">
            <div class="fw-semibold text-dark mb-2">Test SMTP and email health</div>
            <ul class="small text-muted mb-0 ps-3">
                <li>Use <a href="#email-supporting">Email</a> for SMTP config, connection tests, send tests, and templates.</li>
                <li>Check <a href="#status">System Status</a> if the queue is stuck or cron-driven mail is not moving.</li>
                <li>Use <a href="#support">Support Center</a> when you need a sanitized bundle for escalation.</li>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="docs-task-card">
            <div class="fw-semibold text-dark mb-2">Change package pricing safely</div>
            <ul class="small text-muted mb-0 ps-3">
                <li>Review <a href="#packages">Packages</a> for plan-specific limits and package pricing.</li>
                <li>Review <a href="#subscriptions">Subscriptions</a> if the change could affect active renewals or plan state.</li>
                <li>Keep global payout, affiliate, and ticket settings in <a href="#configuration">Config Hub</a>, not on the package page.</li>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="docs-task-card">
            <div class="fw-semibold text-dark mb-2">Fix delivery or storage issues</div>
            <ul class="small text-muted mb-0 ps-3">
                <li>Check <a href="#delivery">Downloads &amp; Delivery</a> for the transfer path and gating layers.</li>
                <li>Check <a href="#storage">Storage Nodes</a> for node state, provider configuration, delivery method, and CORS.</li>
                <li>Check <a href="#status">System Status</a> and <a href="#monitoring">Server Monitoring</a> before making risky node changes.</li>
            </ul>
        </div>
    </div>
</div>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Browse by Task', ['bodyClass' => 'py-3 px-4', 'cardClass' => 'mb-4']); ?>
<div class="row g-3" id="docsDirectory">
    <?php foreach ($docGroups as $group): ?>
        <div class="col-12">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-2">
                <div>
                    <h5 class="mb-1" id="<?= htmlspecialchars($group['id']) ?>"><?= htmlspecialchars($group['title']) ?></h5>
                    <p class="small text-muted mb-0"><?= htmlspecialchars($group['summary']) ?></p>
                </div>
            </div>
        </div>
        <?php foreach ($group['cards'] as $card): ?>
            <div class="col-md-6 col-xl-4 docs-directory-item" data-keywords="<?= htmlspecialchars($card['keywords']) ?>">
                <a href="#<?= htmlspecialchars($card['id']) ?>" class="text-decoration-none">
                    <div class="docs-directory-card h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi <?= htmlspecialchars($card['icon']) ?> text-primary"></i>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($card['title']) ?></div>
                        </div>
                        <div class="small text-muted"><?= htmlspecialchars($card['summary']) ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Quick Jump', ['bodyClass' => 'py-3 px-4', 'cardClass' => 'mb-4']); ?>
<div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
    <div class="small text-muted">Jump straight to the long-form guide you need.</div>
    <div class="d-flex flex-wrap gap-2 docs-toc-list" id="docsToc">
        <?php foreach ($docGroups as $group): ?>
            <?php foreach ($group['cards'] as $index => $card): ?>
                <a class="docs-toc-item <?= $group === $docGroups[0] && $index === 0 ? 'active btn btn-sm btn-primary' : 'btn btn-sm btn-outline-primary' ?>" href="#<?= htmlspecialchars($card['id']) ?>"><?= htmlspecialchars($card['title']) ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php renderAdminCardEnd(); ?>

<div class="row g-4" id="docsGrid">
    <?php foreach ($docGroups as $group): ?>
        <div class="col-12 docs-group" data-group="<?= htmlspecialchars($group['id']) ?>">
            <?php renderAdminCardStart($group['title'], ['bodyClass' => 'py-3 px-4', 'cardClass' => 'mb-1 border-0 bg-transparent shadow-none']); ?>
                <p class="small text-muted mb-0"><?= htmlspecialchars($group['summary']) ?></p>
            <?php renderAdminCardEnd(); ?>
        </div>
        <?php foreach ($group['cards'] as $card): ?>
            <div class="col-12 doc-module" id="<?= htmlspecialchars($card['id']) ?>" data-keywords="<?= htmlspecialchars($card['keywords']) ?>">
                <?php renderDocsModuleStart($card['icon'], $card['title']); ?>
                    <div class="p-1 border-bottom mb-4 pb-3">
                        <div class="small text-uppercase text-muted fw-semibold mb-2">What This Covers</div>
                        <p class="mb-0 text-muted"><?= htmlspecialchars($card['summary']) ?></p>
                    </div>
                    <?php
                    if (isset($card['include'])) {
                        include $card['include'];
                    } elseif (isset($card['html'])) {
                        echo $card['html'];
                    }
                    ?>
                <?php renderDocsModuleEnd(); ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="col-12 doc-module" id="storage-workflows" data-keywords="storage workflows add node edit node migrate files b2 cors backblaze">
        <?php renderDocsModuleStart('bi-diagram-3', 'Storage Node Workflows'); ?>
            <div class="p-1 border-bottom mb-4 pb-3">
                <div class="small text-uppercase text-muted fw-semibold mb-2">What This Covers</div>
                <p class="mb-0 text-muted">Detailed node creation, editing, and migration references that support the Storage Nodes page.</p>
            </div>
            <div class="row mt-1">
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
        <?php renderDocsModuleEnd(); ?>
    </div>

    <div class="col-12 doc-module" id="supporting-guides-detail" data-keywords="general downloads uploads security email monetization seo cron tickets shared tab guides">
        <?php renderDocsModuleStart('bi-journal-richtext', 'Shared Configuration Tab Guides'); ?>
            <div class="p-1 border-bottom mb-4 pb-3">
                <div class="small text-uppercase text-muted fw-semibold mb-2">What This Covers</div>
                <p class="mb-0 text-muted">Cross-tab references for the shared Config Hub tabs that many admin pages depend on.</p>
            </div>
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
        <?php renderDocsModuleEnd(); ?>
    </div>
</div>

<script>
function filterDocs() {
    const input = document.getElementById('docsSearchInput');
    const filter = input.value.toLowerCase();
    const modules = document.getElementsByClassName('doc-module');
    const directoryItems = document.getElementsByClassName('docs-directory-item');

    for (let i = 0; i < modules.length; i++) {
        const keywords = (modules[i].getAttribute('data-keywords') || '').toLowerCase();
        const titleNode = modules[i].getElementsByTagName('h5')[0];
        const title = titleNode ? titleNode.innerText.toLowerCase() : '';
        modules[i].style.display = (keywords.includes(filter) || title.includes(filter)) ? '' : 'none';
    }

    for (let i = 0; i < directoryItems.length; i++) {
        const keywords = (directoryItems[i].getAttribute('data-keywords') || '').toLowerCase();
        const text = directoryItems[i].innerText.toLowerCase();
        directoryItems[i].style.display = (keywords.includes(filter) || text.includes(filter)) ? '' : 'none';
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
.docs-search-wrap{width:min(100%,420px)}
.docs-summary-card,
.docs-directory-card,
.docs-task-card{
    background:#fff;
    border:1px solid rgba(15,23,42,.08);
    border-radius:.9rem;
    box-shadow:0 10px 24px rgba(15,23,42,.06);
    padding:1rem 1.1rem;
}
.docs-summary-card{
    display:flex;
    gap:.9rem;
    align-items:flex-start;
}
.docs-summary-icon{
    width:2.5rem;
    height:2.5rem;
    border-radius:.75rem;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.1rem;
    flex:0 0 auto;
}
.docs-directory-card:hover,
.docs-summary-card:hover{
    transform:translateY(-1px);
    box-shadow:0 14px 28px rgba(15,23,42,.08);
}
.docs-directory-card,
.docs-summary-card{
    transition:transform .15s ease, box-shadow .15s ease;
}
</style>
