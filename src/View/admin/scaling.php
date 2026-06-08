<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';

$summary = is_array($summary ?? null) ? $summary : [];
$settings = is_array($settings ?? null) ? $settings : [];
$recommendations = is_array($recommendations ?? null) ? $recommendations : [];
$goodPractices = is_array($goodPractices ?? null) ? $goodPractices : [];
$recommendedProfile = is_array($recommendedProfile ?? null) ? $recommendedProfile : [];
$throughputHelpers = is_array($throughputHelpers ?? null) ? $throughputHelpers : [];
$verificationFeatures = is_array($verificationFeatures ?? null) ? $verificationFeatures : [];
$conflicts = is_array($conflicts ?? null) ? $conflicts : [];
$currentBehavior = is_array($currentBehavior ?? null) ? $currentBehavior : [];
$scenarioMatrix = is_array($scenarioMatrix ?? null) ? $scenarioMatrix : [];
$quickActions = is_array($quickActions ?? null) ? $quickActions : [];
$whatIWouldDo = is_array($whatIWouldDo ?? null) ? $whatIWouldDo : [];
$servers = is_array($servers ?? null) ? $servers : [];
$loadWarnings = is_array($loadWarnings ?? null) ? $loadWarnings : [];
$packagesWithSpeedLimit = (int)($packagesWithSpeedLimit ?? 0);
$packagesWithConcurrentLimit = (int)($packagesWithConcurrentLimit ?? 0);
$verdictClass = (string)($verdictClass ?? 'info');
$verdictLabel = (string)($verdictLabel ?? 'Balanced');
$verdictSummary = (string)($verdictSummary ?? '');
$canViewPolicyDetails = !empty($canViewPolicyDetails);

$summaryCards = [
    ['label' => 'Active Servers', 'value' => (int)($summary['active_servers'] ?? 0), 'meta' => 'Storage nodes currently in service'],
    ['label' => 'Object Storage', 'value' => (int)($summary['object_servers'] ?? 0), 'meta' => 'Wasabi, R2, B2, S3-style nodes'],
    ['label' => 'Nginx Handoff', 'value' => (int)($summary['nginx_servers'] ?? 0), 'meta' => 'Accelerated nodes using app authorization + Nginx delivery'],
    ['label' => 'PHP Delivery', 'value' => (int)($summary['php_servers'] ?? 0), 'meta' => 'Nodes that can fall back to app-controlled transfers'],
];

$verdictCardClass = match ($verdictClass) {
    'danger' => 'scaling-hero--danger',
    'warning' => 'scaling-hero--warning',
    'success' => 'scaling-hero--success',
    default => 'scaling-hero--info',
};

ob_start();
$viewerCanConfig = \App\Core\Auth::hasCapability('configuration.manage');
$viewerCanPackages = \App\Core\Auth::hasCapability('packages.manage');
?>
<div class="d-flex flex-wrap gap-2">
    <?php if ($viewerCanConfig): ?>
        <a href="/admin/configuration?tab=storage" class="btn btn-sm btn-outline-secondary">Storage Settings</a>
        <a href="/admin/configuration?tab=downloads" class="btn btn-sm btn-outline-secondary">Download Settings</a>
        <a href="/admin/configuration?tab=monetization" class="btn btn-sm btn-outline-secondary">Monetization</a>
    <?php endif; ?>
    <?php if ($viewerCanPackages): ?>
        <a href="/admin/packages" class="btn btn-sm btn-outline-secondary">Packages</a>
    <?php endif; ?>
</div>
<?php
$pageActions = ob_get_clean();

renderAdminPageHeader(
    'Scaling Guide',
    'Operator-facing guidance for high-concurrency download traffic without exposing raw infrastructure details.',
    $pageActions
);
?>

<style>
    .scaling-summary-grid,
    .scaling-two-col,
    .scaling-three-col,
    .scaling-server-grid,
    .scaling-action-grid {
        display:grid;
        gap:1rem;
    }
    .scaling-summary-grid { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); margin-bottom:1.25rem; }
    .scaling-two-col { grid-template-columns: 1.25fr .75fr; align-items:start; }
    .scaling-three-col { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .scaling-server-grid { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .scaling-action-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .scaling-card {
        background:#fff;
        border:1px solid rgba(15,23,42,.08);
        border-radius:16px;
        box-shadow:0 10px 24px rgba(15,23,42,.05);
    }
    .scaling-summary-card,
    .scaling-section,
    .scaling-server-card,
    .scaling-action-card,
    .scaling-hero {
        padding:1rem 1.1rem;
    }
    .scaling-hero {
        margin-bottom:1.25rem;
        border-width:1px;
        border-style:solid;
    }
    .scaling-hero--success { background:#f0fdf4; border-color:#bbf7d0; }
    .scaling-hero--warning { background:#fffbeb; border-color:#fde68a; }
    .scaling-hero--danger { background:#fff7f7; border-color:#fecaca; }
    .scaling-hero--info { background:#f8fbff; border-color:#bfdbfe; }
    .scaling-hero-top {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:1rem;
        margin-bottom:.55rem;
    }
    .scaling-hero-title {
        margin:0;
        font-size:1.15rem;
        font-weight:800;
        color:#0f172a;
    }
    .scaling-hero-copy,
    .scaling-section-copy,
    .scaling-action-copy,
    .scaling-state-copy {
        margin:0;
        color:#475569;
        font-size:.92rem;
        line-height:1.55;
    }
    .scaling-summary-label {
        font-size:.74rem;
        text-transform:uppercase;
        letter-spacing:.05em;
        color:#64748b;
        font-weight:800;
        margin-bottom:.35rem;
    }
    .scaling-summary-value { font-size:1.45rem; font-weight:800; color:#0f172a; line-height:1.15; }
    .scaling-summary-meta { color:#64748b; font-size:.84rem; margin-top:.3rem; }
    .scaling-section { margin-bottom:1rem; }
    .scaling-section:last-child { margin-bottom:0; }
    .scaling-section-title { margin:0 0 .3rem; font-size:1rem; font-weight:800; color:#0f172a; }
    .scaling-chip {
        display:inline-flex;
        align-items:center;
        border-radius:999px;
        padding:.2rem .55rem;
        font-size:.72rem;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .scaling-chip--danger { background:#fee2e2; color:#991b1b; }
    .scaling-chip--warning { background:#fef3c7; color:#92400e; }
    .scaling-chip--success { background:#dcfce7; color:#166534; }
    .scaling-chip--info { background:#dbeafe; color:#1d4ed8; }
    .scaling-state-list,
    .scaling-list,
    .scaling-behavior-list {
        display:grid;
        gap:.9rem;
        margin-top:1rem;
    }
    .scaling-state-item,
    .scaling-recommendation,
    .scaling-behavior-item {
        border:1px solid #e2e8f0;
        border-radius:14px;
        padding:1rem;
        background:#fff;
    }
    .scaling-state-top,
    .scaling-recommendation-top,
    .scaling-behavior-top {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:1rem;
        margin-bottom:.4rem;
    }
    .scaling-state-title,
    .scaling-recommendation-title,
    .scaling-behavior-title {
        margin:0;
        font-size:.95rem;
        font-weight:800;
        color:#0f172a;
    }
    .scaling-state-badge {
        border-radius:999px;
        padding:.28rem .6rem;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        color:#334155;
        font-size:.75rem;
        font-weight:800;
        white-space:nowrap;
    }
    .scaling-state-actions {
        margin-top:.8rem;
    }
    .scaling-recommendation--danger { border-color:#fecaca; background:#fff7f7; }
    .scaling-recommendation--warning { border-color:#fde68a; background:#fffbeb; }
    .scaling-recommendation--info { border-color:#bfdbfe; background:#f8fbff; }
    .scaling-recommendation--load-warning { border-color:#f59e0b; background:#fffbeb; }
    .scaling-pills {
        display:flex;
        flex-wrap:wrap;
        gap:.55rem;
        margin-top:.9rem;
    }
    .scaling-pill {
        border-radius:999px;
        padding:.45rem .75rem;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        color:#334155;
        font-size:.82rem;
        font-weight:700;
    }
    a.scaling-pill {
        text-decoration:none;
    }
    a.scaling-pill:hover {
        border-color:#cbd5e1;
        background:#fff;
        color:#0f172a;
    }
    .scaling-table {
        width:100%;
        border-collapse:separate;
        border-spacing:0;
        margin-top:1rem;
    }
    .scaling-table th,
    .scaling-table td {
        padding:.8rem .85rem;
        border-bottom:1px solid #e2e8f0;
        vertical-align:top;
        text-align:left;
        font-size:.9rem;
    }
    .scaling-table th {
        font-size:.76rem;
        text-transform:uppercase;
        letter-spacing:.05em;
        color:#64748b;
        font-weight:800;
    }
    .scaling-table tr:last-child td { border-bottom:none; }
    .scaling-scale-good { color:#166534; font-weight:800; }
    .scaling-scale-mixed { color:#92400e; font-weight:800; }
    .scaling-scale-heavy { color:#991b1b; font-weight:800; }
    .scaling-scale-neutral { color:#475569; font-weight:800; }
    .scaling-list-plain {
        margin:1rem 0 0;
        padding-left:1.1rem;
        color:#334155;
    }
    .scaling-list-plain li { margin-bottom:.45rem; }
    .scaling-list-plain li:last-child { margin-bottom:0; }
    .scaling-server-name { margin:0 0 .3rem; font-weight:800; color:#0f172a; }
    .scaling-server-meta { color:#64748b; font-size:.84rem; margin-bottom:.75rem; }
    .scaling-server-tags { display:flex; flex-wrap:wrap; gap:.45rem; }
    .scaling-tag {
        display:inline-flex;
        align-items:center;
        border-radius:999px;
        padding:.28rem .58rem;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        color:#475569;
        font-size:.74rem;
        font-weight:700;
    }
    .scaling-action-title {
        margin:0 0 .35rem;
        font-size:.92rem;
        font-weight:800;
        color:#0f172a;
    }
    @media (max-width: 991px) {
        .scaling-two-col { grid-template-columns: 1fr; }
    }
</style>

<div class="scaling-card scaling-hero <?= htmlspecialchars($verdictCardClass) ?>">
    <div class="scaling-hero-top">
        <div>
            <div class="scaling-summary-label">Current Scaling Profile</div>
            <h2 class="scaling-hero-title"><?= htmlspecialchars($verdictLabel) ?></h2>
        </div>
        <?php
        $heroChipClass = match ($verdictClass) {
            'danger' => 'scaling-chip--danger',
            'warning' => 'scaling-chip--warning',
            'success' => 'scaling-chip--success',
            default => 'scaling-chip--info',
        };
        ?>
        <span class="scaling-chip <?= htmlspecialchars($heroChipClass) ?>"><?= htmlspecialchars($verdictLabel) ?></span>
    </div>
    <p class="scaling-hero-copy"><?= htmlspecialchars($verdictSummary) ?></p>
    <div class="scaling-pills">
        <?php if ($canViewPolicyDetails && \App\Core\Auth::hasCapability('configuration.manage')): ?>
            <a class="scaling-pill" href="/admin/configuration?tab=monetization">PPD Min Percent: <?= (int)($settings['ppd_min_download_percent'] ?? 0) ?>%</a>
            <a class="scaling-pill" href="<?= (\App\Service\FeatureService::rewardsEnabled() && \App\Core\Auth::hasCapability('rewards_fraud.manage')) ? '/admin/rewards-fraud' : '/admin/configuration?tab=monetization' ?>">Verified Completion: <?= !empty($settings['rewards_verified_completion_required']) ? 'On' : 'Off' ?></a>
            <a class="scaling-pill" href="/admin/configuration?tab=downloads">Track Current Downloads: <?= !empty($settings['track_current_downloads']) ? 'On' : 'Off' ?></a>
            <a class="scaling-pill" href="/admin/configuration?tab=downloads">CDN Redirects: <?= !empty($settings['cdn_download_redirects_enabled']) ? 'On' : 'Off' ?></a>
            <a class="scaling-pill" href="/admin/configuration?tab=downloads">Streaming Support: <?= !empty($settings['streaming_support_enabled']) ? 'On' : 'Off' ?></a>
        <?php elseif ($canViewPolicyDetails): ?>
            <div class="scaling-pill">PPD Min Percent: <?= (int)($settings['ppd_min_download_percent'] ?? 0) ?>%</div>
            <div class="scaling-pill">Verified Completion: <?= !empty($settings['rewards_verified_completion_required']) ? 'On' : 'Off' ?></div>
            <div class="scaling-pill">Track Current Downloads: <?= !empty($settings['track_current_downloads']) ? 'On' : 'Off' ?></div>
            <div class="scaling-pill">CDN Redirects: <?= !empty($settings['cdn_download_redirects_enabled']) ? 'On' : 'Off' ?></div>
            <div class="scaling-pill">Streaming Support: <?= !empty($settings['streaming_support_enabled']) ? 'On' : 'Off' ?></div>
        <?php else: ?>
            <div class="scaling-pill">Object Storage: <?= (int)($summary['object_servers'] ?? 0) ?></div>
            <div class="scaling-pill">Nginx Handoff: <?= (int)($summary['nginx_servers'] ?? 0) ?></div>
            <div class="scaling-pill">Apache/LiteSpeed: <?= (int)($summary['apache_like_servers'] ?? 0) ?></div>
            <div class="scaling-pill">PHP Delivery Nodes: <?= (int)($summary['php_servers'] ?? 0) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="scaling-summary-grid">
    <?php foreach ($summaryCards as $card): ?>
        <div class="scaling-card scaling-summary-card">
            <div class="scaling-summary-label"><?= htmlspecialchars($card['label']) ?></div>
            <div class="scaling-summary-value"><?= htmlspecialchars((string)$card['value']) ?></div>
            <div class="scaling-summary-meta"><?= htmlspecialchars($card['meta']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($loadWarnings !== []): ?>
    <div class="scaling-card scaling-section mb-3">
        <h3 class="scaling-section-title">Data Load Warnings</h3>
        <p class="scaling-section-copy">This page stays intentionally high-level, but these sections could not be loaded cleanly. Treat any empty-state conclusions below as incomplete until the warnings are resolved.</p>
        <div class="scaling-state-list">
            <?php foreach ($loadWarnings as $warning): ?>
                <div class="scaling-recommendation scaling-recommendation--load-warning">
                    <div class="scaling-recommendation-top">
                        <h4 class="scaling-recommendation-title">Scaling data unavailable</h4>
                        <span class="scaling-chip scaling-chip--warning">Warning</span>
                    </div>
                    <p class="scaling-state-copy"><?= htmlspecialchars((string)$warning) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="scaling-two-col">
    <div>
        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">Current Behavior</h3>
            <p class="scaling-section-copy"><?= $canViewPolicyDetails ? 'This section answers the practical question first: who is probably doing most of the work for your downloads right now, and when Fyuhls has to stay involved longer.' : 'This reduced view focuses on delivery shape: whether storage, Nginx, or Fyuhls itself is likely doing most of the work.' ?></p>
            <div class="scaling-behavior-list">
                <?php foreach ($currentBehavior as $item): ?>
                    <div class="scaling-behavior-item">
                        <div class="scaling-behavior-top">
                            <h4 class="scaling-behavior-title"><?= htmlspecialchars((string)$item['title']) ?></h4>
                            <span class="scaling-state-badge"><?= htmlspecialchars((string)$item['path']) ?></span>
                        </div>
                        <p class="scaling-state-copy"><?= htmlspecialchars((string)$item['summary']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">Install-Specific Recommendations</h3>
            <?php if ($recommendations === []): ?>
                <p class="scaling-section-copy">This install is not showing a major scaling anti-pattern right now. That is a good place to be.</p>
            <?php else: ?>
                <div class="scaling-list">
                    <?php foreach ($recommendations as $item): ?>
                        <?php
                        $severity = (string)($item['severity'] ?? 'info');
                        $severityClass = match ($severity) {
                            'danger' => 'scaling-recommendation--danger',
                            'warning' => 'scaling-recommendation--warning',
                            default => 'scaling-recommendation--info',
                        };
                        $chipClass = match ($severity) {
                            'danger' => 'scaling-chip--danger',
                            'warning' => 'scaling-chip--warning',
                            default => 'scaling-chip--info',
                        };
                        ?>
                        <div class="scaling-recommendation <?= htmlspecialchars($severityClass) ?>">
                            <div class="scaling-recommendation-top">
                                <h4 class="scaling-recommendation-title"><?= htmlspecialchars((string)$item['title']) ?></h4>
                                <span class="scaling-chip <?= htmlspecialchars($chipClass) ?>"><?= htmlspecialchars(strtoupper($severity)) ?></span>
                            </div>
                            <p class="scaling-state-copy"><?= htmlspecialchars((string)$item['body']) ?></p>
                            <?php if (!empty($item['action_href']) && !empty($item['action_label'])): ?>
                                <div class="mt-3">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars((string)$item['action_href']) ?>"><?= htmlspecialchars((string)$item['action_label']) ?></a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">Common Download Situations</h3>
            <p class="scaling-section-copy"><?= $canViewPolicyDetails ? 'This section explains, in plain language, who is likely doing the work in each common download situation and whether that puts lighter or heavier load on your app server.' : 'This section explains the common storage and delivery situations this account is allowed to inspect.' ?></p>
            <table class="scaling-table">
                <thead>
                    <tr>
                        <th>Situation</th>
                        <th>Who handles most of the file</th>
                        <th>Load impact</th>
                        <th>What this means</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scenarioMatrix as $row): ?>
                        <?php
                        $scale = strtolower((string)($row['scale'] ?? 'neutral'));
                        $scaleClass = 'scaling-scale-neutral';
                        if (str_contains($scale, 'good')) {
                            $scaleClass = 'scaling-scale-good';
                        } elseif (str_contains($scale, 'mixed') || str_contains($scale, 'okay')) {
                            $scaleClass = 'scaling-scale-mixed';
                        } elseif (str_contains($scale, 'heavy') || str_contains($scale, 'heavier')) {
                            $scaleClass = 'scaling-scale-heavy';
                        }
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)$row['scenario']) ?></strong></td>
                            <td><?= htmlspecialchars((string)$row['path']) ?></td>
                            <td class="<?= htmlspecialchars($scaleClass) ?>"><?= htmlspecialchars((string)$row['scale']) ?></td>
                            <td><?= htmlspecialchars((string)$row['why']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">Throughput Helpers</h3>
            <p class="scaling-section-copy">These are the settings and infrastructure choices that help Fyuhls approve the request and then step out of the way, so storage or Nginx can handle more of the file delivery.</p>
            <div class="scaling-state-list">
                <?php foreach ($throughputHelpers as $item): ?>
                    <div class="scaling-state-item">
                        <div class="scaling-state-top">
                            <h4 class="scaling-state-title"><?= htmlspecialchars((string)$item['title']) ?></h4>
                            <span class="scaling-state-badge"><?= htmlspecialchars((string)$item['state']) ?></span>
                        </div>
                        <p class="scaling-state-copy"><?= htmlspecialchars((string)$item['impact']) ?></p>
                        <?php if (!empty($item['action_href']) && !empty($item['action_label'])): ?>
                            <div class="scaling-state-actions">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars((string)$item['action_href']) ?>"><?= htmlspecialchars((string)$item['action_label']) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">Verification-Heavy Features</h3>
            <?php if (!$canViewPolicyDetails): ?>
                <p class="scaling-section-copy">Monetization, reward-proof, and package-enforcement details are intentionally hidden from this storage-focused view.</p>
            <?php else: ?>
                <p class="scaling-section-copy">These are not "bad" settings. They just add more work during a live download because Fyuhls has to watch more closely or enforce more rules before it lets the transfer finish.</p>
                <div class="scaling-state-list">
                    <?php foreach ($verificationFeatures as $item): ?>
                        <div class="scaling-state-item">
                            <div class="scaling-state-top">
                                <h4 class="scaling-state-title"><?= htmlspecialchars((string)$item['title']) ?></h4>
                                <span class="scaling-state-badge"><?= htmlspecialchars((string)$item['state']) ?></span>
                            </div>
                            <p class="scaling-state-copy"><?= htmlspecialchars((string)$item['impact']) ?></p>
                            <?php if (!empty($item['action_href']) && !empty($item['action_label'])): ?>
                                <div class="scaling-state-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars((string)$item['action_href']) ?>"><?= htmlspecialchars((string)$item['action_label']) ?></a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">Scaling Conflicts</h3>
            <?php if ($conflicts === []): ?>
                <p class="scaling-section-copy">No obvious high-scale conflict is showing up right now. That does not mean the install is perfect, just that it is not advertising an obvious mismatch.</p>
            <?php else: ?>
                <div class="scaling-list">
                    <?php foreach ($conflicts as $item): ?>
                        <?php
                        $severity = (string)($item['severity'] ?? 'info');
                        $severityClass = match ($severity) {
                            'danger' => 'scaling-recommendation--danger',
                            'warning' => 'scaling-recommendation--warning',
                            default => 'scaling-recommendation--info',
                        };
                        $chipClass = match ($severity) {
                            'danger' => 'scaling-chip--danger',
                            'warning' => 'scaling-chip--warning',
                            default => 'scaling-chip--info',
                        };
                        ?>
                        <div class="scaling-recommendation <?= htmlspecialchars($severityClass) ?>">
                            <div class="scaling-recommendation-top">
                                <h4 class="scaling-recommendation-title"><?= htmlspecialchars((string)$item['title']) ?></h4>
                                <span class="scaling-chip <?= htmlspecialchars($chipClass) ?>"><?= htmlspecialchars(strtoupper($severity)) ?></span>
                            </div>
                            <p class="scaling-state-copy"><?= htmlspecialchars((string)$item['body']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="scaling-three-col mt-3">
    <div class="scaling-card scaling-section">
        <h3 class="scaling-section-title">What Already Helps</h3>
        <?php if ($goodPractices === []): ?>
            <p class="scaling-section-copy">Nothing special is lighting up here yet. That does not mean the install is bad, only that it is not currently leaning hard into the lighter delivery profile.</p>
        <?php else: ?>
            <ul class="scaling-list-plain">
                <?php foreach ($goodPractices as $item): ?>
                    <li><strong><?= htmlspecialchars((string)$item['title']) ?>:</strong> <?= htmlspecialchars((string)$item['body']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="scaling-card scaling-section">
        <h3 class="scaling-section-title">Package Pressure</h3>
        <?php if (!$canViewPolicyDetails): ?>
            <p class="scaling-section-copy">Package-enforcement details are intentionally hidden from this storage-focused view.</p>
        <?php else: ?>
            <ul class="scaling-list-plain">
                <li><strong>Speed-limited packages:</strong> <?= $packagesWithSpeedLimit ?></li>
                <li><strong>Concurrent-download-limited packages:</strong> <?= $packagesWithConcurrentLimit ?></li>
            </ul>
            <p class="scaling-section-copy mt-3">These are valid product choices. They just increase the chance that more traffic keeps Fyuhls involved instead of letting the file backend do most of the work.</p>
        <?php endif; ?>
    </div>

    <?php if ($canViewPolicyDetails): ?>
        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">If This Were My Install</h3>
            <ul class="scaling-list-plain">
                <?php foreach ($whatIWouldDo as $line): ?>
                    <li><?= htmlspecialchars((string)$line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="scaling-card scaling-section">
            <h3 class="scaling-section-title">Operator Notes</h3>
            <ul class="scaling-list-plain">
                <li>Use this page to see whether storage, Nginx, or Fyuhls itself is doing most of the delivery work.</li>
                <li>For deeper reward-policy or package-enforcement tradeoffs, a fuller admin role is needed.</li>
                <li>Keep using high-level summaries here instead of exposing raw infrastructure details to lower-privilege accounts.</li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<div class="scaling-card scaling-section mt-3">
    <h3 class="scaling-section-title">Quick Actions</h3>
    <?php if ($quickActions === []): ?>
        <p class="scaling-section-copy">No direct follow-up actions are available from this account on this page right now.</p>
    <?php else: ?>
        <p class="scaling-section-copy">Use these when you already know what kind of tradeoff you want to change.</p>
        <div class="scaling-action-grid mt-3">
            <?php foreach ($quickActions as $item): ?>
                <div class="scaling-card scaling-action-card">
                    <h4 class="scaling-action-title"><?= htmlspecialchars((string)$item['label']) ?></h4>
                    <p class="scaling-action-copy"><?= htmlspecialchars((string)$item['copy']) ?></p>
                    <div class="mt-3">
                        <a href="<?= htmlspecialchars((string)$item['href']) ?>" class="btn btn-sm btn-outline-secondary">Open</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canViewPolicyDetails): ?>
    <div class="scaling-card scaling-section mt-3">
        <h3 class="scaling-section-title">Recommended High-Scale Profile</h3>
        <ul class="scaling-list-plain">
            <?php foreach ($recommendedProfile as $line): ?>
                <li><?= htmlspecialchars((string)$line) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="scaling-card scaling-section mt-3">
    <h3 class="scaling-section-title">Storage Delivery Snapshot</h3>
    <p class="scaling-section-copy">This snapshot stays intentionally high-level. It helps operators reason about scale without exposing raw endpoints, bucket names, or internal host details.</p>
    <?php if ($servers === []): ?>
        <p class="scaling-section-copy mt-3">No storage servers were found.</p>
    <?php else: ?>
        <div class="scaling-server-grid mt-3">
            <?php foreach ($servers as $server): ?>
                <?php
                $status = (string)($server['status'] ?? 'unknown');
                $serverType = strtolower((string)($server['server_type'] ?? 'local'));
                $providerPreset = strtolower((string)($server['config']['provider_preset'] ?? ''));
                $isObject = in_array($serverType, ['s3', 'wasabi', 'backblaze', 'b2', 'r2'], true)
                    || in_array($providerPreset, ['r2', 'b2', 'backblaze', 'wasabi', 's3'], true);
                ?>
                <div class="scaling-card scaling-server-card">
                    <h4 class="scaling-server-name">Node #<?= (int)($server['id'] ?? 0) ?></h4>
                    <div class="scaling-server-meta">Status: <?= htmlspecialchars($status) ?></div>
                    <div class="scaling-server-tags">
                        <span class="scaling-tag"><?= $isObject ? 'Object storage' : 'Local / filesystem' ?></span>
                        <span class="scaling-tag">Delivery: <?= htmlspecialchars(strtoupper((string)($server['delivery_method'] ?? 'php'))) ?></span>
                        <?php if ($providerPreset !== ''): ?>
                            <span class="scaling-tag">Provider class: <?= htmlspecialchars(strtoupper($providerPreset)) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
