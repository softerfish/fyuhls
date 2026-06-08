<?php
include __DIR__ . '/header.php';
include __DIR__ . '/partials/shell_helpers.php';
renderAdminPageHeader('Rewards Fraud', 'Work the review queue first, then tune the scoring rules when the traffic pattern changes.');

$reviewQueue = $reviewQueuePage['items'] ?? [];
$queueTotal = (int)($reviewQueuePage['total'] ?? 0);
$queueSqlTotal = (int)($reviewQueuePage['sql_total'] ?? $queueTotal);
$queuePageNumber = (int)($reviewQueuePage['page'] ?? 1);
$queuePerPage = (int)($reviewQueuePage['per_page'] ?? 50);
$queuePages = (int)($reviewQueuePage['pages'] ?? 1);
$nameSearchActive = !empty($reviewQueuePage['name_search_active']);
$nameSearchCapped = !empty($reviewQueuePage['name_search_capped']);
$nameSearchCap = (int)($reviewQueuePage['name_search_cap'] ?? 0);
$recentRewardActivity = $recentRewardActivity ?? [];
$recentRewardWindowDays = (int)($recentRewardWindowDays ?? 14);
$reviewCaseContext = $reviewCaseContext ?? [];
$reviewUploaderStats = $reviewCaseContext['uploader_stats'] ?? [];
$reviewFileStats = $reviewCaseContext['file_stats'] ?? [];
$reviewSessionDetails = $reviewCaseContext['session_details'] ?? [];
$reviewSignalCounts = $reviewCaseContext['signal_counts'] ?? [];
$reviewDownloaderMeta = $reviewCaseContext['downloader_meta'] ?? [];
$reviewRecentReferrers = $reviewCaseContext['recent_referrers'] ?? [];
$reviewTrustControls = $reviewCaseContext['trust_controls'] ?? [];
$reviewRecommendations = $reviewCaseContext['recommendations'] ?? [];
$reviewClusters = $reviewClusters ?? ['uploader' => [], 'file' => [], 'referrer' => [], 'network' => []];
$trustTierOptions = $trustTierOptions ?? [];
$canViewInvestigations = \App\Core\Auth::hasCapability('investigations.view');
$canManageFraudSettings = !empty($canManageFraudSettings);
$queueStart = $queueTotal > 0 ? (($queuePageNumber - 1) * $queuePerPage) + 1 : 0;
$queueEnd = min($queueTotal, $queuePageNumber * $queuePerPage);

$queueFilters = $queueFilters ?? [];
$reviewFilterOptions = $reviewFilterOptions ?? ['countries' => [], 'networks' => []];
$activeInvestigationCopy = null;
if (!empty($queueFilters['uploader_id'])) {
    $activeInvestigationCopy = 'Investigating uploader #' . (int)$queueFilters['uploader_id'] . ' in the review queue below.';
} elseif (!empty($queueFilters['file_id'])) {
    $activeInvestigationCopy = 'Investigating file #' . (int)$queueFilters['file_id'] . ' in the review queue below.';
}
$viewMode = (isset($_GET['view']) && $_GET['view'] === 'full') ? 'full' : 'triage';
$isTriageMode = $viewMode !== 'full';
$notePresets = [
    'Looks normal after review',
    'Repeat visitor pattern needs caution',
    'Proxy or VPN pattern detected',
    'Suspicious cluster, reversed',
    'Needs more traffic before decision',
];

$queryParams = static function (array $overrides = []) use ($queueFilters, $queuePerPage, $queuePageNumber, $viewMode): string {
    $params = [
        'q' => (string)($queueFilters['query'] ?? ''),
        'uploader_name' => (string)($queueFilters['uploader_name'] ?? ''),
        'file_name' => (string)($queueFilters['file_name'] ?? ''),
        'status' => (string)($queueFilters['status'] ?? ''),
        'risk_band' => (string)($queueFilters['risk_band'] ?? ''),
        'country' => (string)($queueFilters['country_code'] ?? ''),
        'network' => (string)($queueFilters['network_type'] ?? ''),
        'uploader_id' => (int)($queueFilters['uploader_id'] ?? 0),
        'file_id' => (int)($queueFilters['file_id'] ?? 0),
        'sort' => (string)($queueFilters['sort'] ?? 'risk_desc'),
        'per_page' => $queuePerPage,
        'page' => $queuePageNumber,
        'view' => $viewMode,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    $params = array_filter($params, static function ($value, $key) {
        if ($key === 'page') {
            return (int)$value > 1;
        }
        return !($value === '' || $value === 0 || $value === '0' || $value === null);
    }, ARRAY_FILTER_USE_BOTH);

    return http_build_query($params);
};

$returnTo = '/admin/rewards-fraud' . (($qs = $queryParams()) !== '' ? '?' . $qs : '');
?>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Awaiting Review</div><div class="fs-4 fw-bold">$<?= number_format((float)($overview['held_earnings'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Needs Closer Review</div><div class="fs-4 fw-bold text-danger">$<?= number_format((float)($overview['flagged_earnings'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Good Auto-Clear Candidates</div><div class="fs-4 fw-bold text-success">$<?= number_format((float)($overview['likely_safe_queue_amount'] ?? 0), 2) ?></div><div class="small text-muted mt-1">Lower-risk traffic that should usually clear without much hand-holding.</div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Likely Abuse to Check</div><div class="fs-4 fw-bold text-danger">$<?= number_format((float)($overview['likely_fraud_queue_amount'] ?? 0), 2) ?></div><div class="small text-muted mt-1">High-risk or restricted traffic that probably needs a quick decision.</div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Cleared Today</div><div class="fs-4 fw-bold text-success">$<?= number_format((float)($overview['cleared_today'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Reversed Today</div><div class="fs-4 fw-bold">$<?= number_format((float)($overview['reversed_today'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Rows the Rules Can Handle</div><div class="fs-4 fw-bold"><?= (int)($overview['auto_action_candidates'] ?? 0) ?></div><div class="small text-muted mt-1">Queue rows that already fit your current auto-clear or auto-reverse rules.</div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">High-Risk Uploaders</div><div class="fs-4 fw-bold"><?= (int)($overview['high_risk_uploaders'] ?? 0) ?></div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Blocked Uploaders</div><div class="fs-4 fw-bold"><?= (int)($overview['blocked_uploaders'] ?? 0) ?></div><div class="small text-muted mt-1">Accounts prevented from earning until staff re-enable them.</div></div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Rows Waiting on Staff</div><div class="fs-4 fw-bold"><?= (int)($overview['review_queue'] ?? 0) ?></div></div></div>
    </div>
</div>

<div class="alert alert-light border mb-4 fraud-automation-note" role="note">
    <div class="fw-semibold mb-1">How automatic handling works</div>
    <div class="small text-muted mb-0">
        Low-risk auto-clear candidates and hard-fraud auto-reverse candidates are swept separately. That keeps obviously safe traffic from getting buried behind a large high-risk backlog, and the summary cards above only count actions that your current automation settings actually allow.
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <?php renderAdminCardStart('Review Patterns: Uploaders and Files'); ?>
            <div class="small text-muted mb-3">Busy sites should clear or reverse patterns, not babysit one row at a time. Start with the clusters that are driving the most queue weight, then drop into row detail only when something still looks ambiguous.</div>
            <div class="fraud-cluster-grid">
                <div class="fraud-cluster-panel">
                    <div class="fraud-cluster-heading">Top uploader clusters</div>
                    <?php if (empty($reviewClusters['uploader'])): ?>
                        <div class="small text-muted">No uploader clusters are active in the current queue.</div>
                    <?php else: ?>
                        <?php foreach ($reviewClusters['uploader'] as $cluster): ?>
                            <div class="fraud-cluster-item">
                                <div class="fraud-cluster-main">
                                    <div class="fw-semibold"><?= htmlspecialchars((string)($cluster['label'] ?? 'Unknown uploader')) ?></div>
                                    <div class="small text-muted"><?= (int)($cluster['queue_count'] ?? 0) ?> queue rows | $<?= number_format((float)($cluster['total_amount'] ?? 0), 4) ?> | avg risk <?= number_format((float)($cluster['avg_risk'] ?? 0), 1) ?></div>
                                    <div class="fraud-cluster-recommendation tone-<?= htmlspecialchars((string)($cluster['recommendation']['tone'] ?? 'secondary')) ?>"><?= htmlspecialchars((string)($cluster['recommendation']['label'] ?? 'Review as a group')) ?></div>
                                </div>
                                <form method="POST" action="/admin/rewards-fraud/review" class="fraud-cluster-form">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                    <input type="hidden" name="cluster_type" value="uploader">
                                    <input type="hidden" name="cluster_key" value="<?= (int)($cluster['user_id'] ?? 0) ?>">
                                    <input type="hidden" name="review_action" value="recommended" class="fraud-action-input">
                                    <button type="submit" class="btn btn-sm btn-primary fraud-action-btn" data-action="recommended">Apply recommendation</button>
                                    <button type="submit" class="btn btn-sm btn-outline-success fraud-action-btn" data-action="clear">Clear</button>
                                    <button type="submit" class="btn btn-sm btn-outline-warning fraud-action-btn" data-action="hold">Hold</button>
                                    <button type="submit" class="btn btn-sm btn-outline-danger fraud-action-btn" data-action="reverse">Reverse</button>
                                    <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['uploader_id' => (int)($cluster['user_id'] ?? 0), 'file_id' => 0, 'page' => 1])) ?>#review-queue" class="btn btn-sm btn-link px-0">Inspect queue</a>
                                    <?php if ($canViewInvestigations): ?>
                                        <a href="/admin/investigations/uploader/<?= (int)($cluster['user_id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">Uploader details</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="fraud-cluster-panel">
                    <div class="fraud-cluster-heading">Top file clusters</div>
                    <?php if (empty($reviewClusters['file'])): ?>
                        <div class="small text-muted">No file clusters are active in the current queue.</div>
                    <?php else: ?>
                        <?php foreach ($reviewClusters['file'] as $cluster): ?>
                            <div class="fraud-cluster-item">
                                <div class="fraud-cluster-main">
                                    <div class="fw-semibold"><?= htmlspecialchars((string)($cluster['label'] ?? 'Unknown file')) ?></div>
                                    <div class="small text-muted"><?= (int)($cluster['queue_count'] ?? 0) ?> queue rows | $<?= number_format((float)($cluster['total_amount'] ?? 0), 4) ?> | avg risk <?= number_format((float)($cluster['avg_risk'] ?? 0), 1) ?></div>
                                    <div class="fraud-cluster-recommendation tone-<?= htmlspecialchars((string)($cluster['recommendation']['tone'] ?? 'secondary')) ?>"><?= htmlspecialchars((string)($cluster['recommendation']['label'] ?? 'Review as a group')) ?></div>
                                </div>
                                <form method="POST" action="/admin/rewards-fraud/review" class="fraud-cluster-form">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                    <input type="hidden" name="cluster_type" value="file">
                                    <input type="hidden" name="cluster_key" value="<?= (int)($cluster['file_id'] ?? 0) ?>">
                                    <input type="hidden" name="review_action" value="recommended" class="fraud-action-input">
                                    <button type="submit" class="btn btn-sm btn-primary fraud-action-btn" data-action="recommended">Apply recommendation</button>
                                    <button type="submit" class="btn btn-sm btn-outline-success fraud-action-btn" data-action="clear">Clear</button>
                                    <button type="submit" class="btn btn-sm btn-outline-warning fraud-action-btn" data-action="hold">Hold</button>
                                    <button type="submit" class="btn btn-sm btn-outline-danger fraud-action-btn" data-action="reverse">Reverse</button>
                                    <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['file_id' => (int)($cluster['file_id'] ?? 0), 'uploader_id' => 0, 'page' => 1])) ?>#review-queue" class="btn btn-sm btn-link px-0">Inspect queue</a>
                                    <?php if ($canViewInvestigations): ?>
                                        <a href="/admin/investigations/file/<?= (int)($cluster['file_id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">File details</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>
    <div class="col-xl-6">
        <?php renderAdminCardStart('Review Patterns: Referrers and Networks'); ?>
            <div class="small text-muted mb-3">These are the queue funnels and network pockets that are consuming reviewer time. If a single page, ASN, or traffic class keeps surfacing, handle that pattern once instead of touching every child row.</div>
            <div class="fraud-cluster-grid">
                <div class="fraud-cluster-panel">
                    <div class="fraud-cluster-heading">Top referrer funnels</div>
                    <?php if (empty($reviewClusters['referrer'])): ?>
                        <div class="small text-muted">No referrer funnels are active in the current queue.</div>
                    <?php else: ?>
                        <?php foreach ($reviewClusters['referrer'] as $cluster): ?>
                            <div class="fraud-cluster-item">
                                <div class="fraud-cluster-main">
                                    <div class="fw-semibold fraud-cluster-url"><a href="<?= htmlspecialchars((string)($cluster['download_page_referrer_url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)($cluster['label'] ?? 'Unknown referrer')) ?></a></div>
                                    <div class="small text-muted"><?= (int)($cluster['queue_count'] ?? 0) ?> queue rows | $<?= number_format((float)($cluster['total_amount'] ?? 0), 4) ?> | avg risk <?= number_format((float)($cluster['avg_risk'] ?? 0), 1) ?></div>
                                    <div class="fraud-cluster-recommendation tone-<?= htmlspecialchars((string)($cluster['recommendation']['tone'] ?? 'secondary')) ?>"><?= htmlspecialchars((string)($cluster['recommendation']['label'] ?? 'Review as a group')) ?></div>
                                </div>
                                <form method="POST" action="/admin/rewards-fraud/review" class="fraud-cluster-form">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                    <input type="hidden" name="cluster_type" value="referrer">
                                    <input type="hidden" name="cluster_key" value="<?= htmlspecialchars((string)($cluster['download_page_referrer_url'] ?? '')) ?>">
                                    <input type="hidden" name="review_action" value="recommended" class="fraud-action-input">
                                    <button type="submit" class="btn btn-sm btn-primary fraud-action-btn" data-action="recommended">Apply recommendation</button>
                                    <button type="submit" class="btn btn-sm btn-outline-success fraud-action-btn" data-action="clear">Clear</button>
                                    <button type="submit" class="btn btn-sm btn-outline-warning fraud-action-btn" data-action="hold">Hold</button>
                                    <button type="submit" class="btn btn-sm btn-outline-danger fraud-action-btn" data-action="reverse">Reverse</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="fraud-cluster-panel">
                    <div class="fraud-cluster-heading">Top network clusters</div>
                    <?php if (empty($reviewClusters['network'])): ?>
                        <div class="small text-muted">No network clusters are active in the current queue.</div>
                    <?php else: ?>
                        <?php foreach ($reviewClusters['network'] as $cluster): ?>
                            <div class="fraud-cluster-item">
                                <div class="fraud-cluster-main">
                                    <div class="fw-semibold"><?= htmlspecialchars((string)($cluster['label'] ?? 'Unknown network')) ?></div>
                                    <div class="small text-muted"><?= (int)($cluster['queue_count'] ?? 0) ?> queue rows | $<?= number_format((float)($cluster['total_amount'] ?? 0), 4) ?> | avg risk <?= number_format((float)($cluster['avg_risk'] ?? 0), 1) ?></div>
                                    <div class="fraud-cluster-recommendation tone-<?= htmlspecialchars((string)($cluster['recommendation']['tone'] ?? 'secondary')) ?>"><?= htmlspecialchars((string)($cluster['recommendation']['label'] ?? 'Review as a group')) ?></div>
                                </div>
                                <form method="POST" action="/admin/rewards-fraud/review" class="fraud-cluster-form">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                    <input type="hidden" name="cluster_type" value="network">
                                    <input type="hidden" name="cluster_key" value="<?= htmlspecialchars((string)($cluster['cluster_key'] ?? '')) ?>">
                                    <input type="hidden" name="review_action" value="recommended" class="fraud-action-input">
                                    <button type="submit" class="btn btn-sm btn-primary fraud-action-btn" data-action="recommended">Apply recommendation</button>
                                    <button type="submit" class="btn btn-sm btn-outline-success fraud-action-btn" data-action="clear">Clear</button>
                                    <button type="submit" class="btn btn-sm btn-outline-warning fraud-action-btn" data-action="hold">Hold</button>
                                    <button type="submit" class="btn btn-sm btn-outline-danger fraud-action-btn" data-action="reverse">Reverse</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>

<div id="review-queue">
<?php renderAdminCardStart('Rows Waiting on Review'); ?>
    <div class="fraud-queue-toolbar">
        <div>
            <h3 class="fraud-queue-title">Queue-first review</h3>
            <p class="fraud-queue-copy">This queue only shows reward rows that are still waiting on a decision. Narrow the work slice, handle obvious cases in bulk, and expand a row when you need more context before clearing or reversing it.</p>
        </div>
        <div class="fraud-queue-summary">
            <?php if ($queueTotal > 0): ?>
                Showing <?= $queueStart ?>-<?= $queueEnd ?> of <?= $queueTotal ?>
            <?php else: ?>
                No review rows match this view
            <?php endif; ?>
        </div>
    </div>

    <?php if ($activeInvestigationCopy !== null): ?>
        <div class="alert alert-info border-0 shadow-sm small mb-3">
            <?= htmlspecialchars($activeInvestigationCopy) ?>
        </div>
    <?php endif; ?>

    <?php if ($nameSearchActive && $nameSearchCapped): ?>
        <div class="alert alert-info border-0 shadow-sm small mb-3">
            Username and file-name search is being matched against the first <?= number_format($nameSearchCap) ?> queue rows after the normal filters and sort are applied. Refine the queue filters if you need to search deeper into a very large queue.
        </div>
    <?php endif; ?>

    <?php if ($queueTotal === 0): ?>
        <div class="alert alert-light border small mb-3">
            <strong>Nothing is waiting for fraud review right now.</strong> That does not mean there was no recent reward activity. Downloads that already cleared, were paid, were reversed, or never entered the review states will show in the recent activity section below instead of this queue.
        </div>
    <?php endif; ?>

    <div class="fraud-shortcuts-notice">
        <div>
            <div class="fraud-shortcuts-title">Keyboard shortcuts</div>
            <div class="fraud-shortcuts-copy">Use the queue without fighting the mouse: move with <kbd>J</kbd>/<kbd>K</kbd>, expand with <kbd>X</kbd>, select with <kbd>Shift</kbd> + <kbd>A</kbd>, clear with <kbd>C</kbd>, hold with <kbd>H</kbd>, reverse with <kbd>R</kbd>.</div>
        </div>
        <div class="fraud-view-toggle" role="group" aria-label="Queue view mode">
            <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['view' => 'triage', 'page' => 1])) ?>#review-queue" class="btn btn-sm <?= $isTriageMode ? 'btn-primary' : 'btn-outline-secondary' ?>">Triage mode</a>
            <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['view' => 'full', 'page' => 1])) ?>#review-queue" class="btn btn-sm <?= !$isTriageMode ? 'btn-primary' : 'btn-outline-secondary' ?>">Full detail</a>
        </div>
    </div>

    <div class="fraud-quick-filters">
        <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['status' => 'flagged_review', 'risk_band' => 'high', 'sort' => 'risk_desc', 'page' => 1])) ?>#review-queue" class="fraud-quick-filter">Likely abuse</a>
        <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['status' => 'held', 'risk_band' => 'low', 'sort' => 'amount_desc', 'page' => 1])) ?>#review-queue" class="fraud-quick-filter">Likely safe</a>
        <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['status' => 'held', 'risk_band' => 'medium', 'sort' => 'oldest', 'page' => 1])) ?>#review-queue" class="fraud-quick-filter">Needs judgment</a>
        <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['sort' => 'amount_desc', 'page' => 1])) ?>#review-queue" class="fraud-quick-filter">Largest money first</a>
        <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['sort' => 'oldest', 'page' => 1])) ?>#review-queue" class="fraud-quick-filter">Oldest first</a>
        <a href="/admin/rewards-fraud?<?= htmlspecialchars($queryParams(['network' => 'hosting', 'page' => 1])) ?>#review-queue" class="fraud-quick-filter">Hosting traffic</a>
    </div>

    <form method="GET" action="/admin/rewards-fraud" class="fraud-filter-grid">
        <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
        <div class="fraud-filter-span-2">
            <label class="form-label fw-semibold">Quick search</label>
            <input type="search" name="q" class="form-control" value="<?= htmlspecialchars((string)($queueFilters['query'] ?? '')) ?>" placeholder="Earning ID, uploader ID, file ID, ASN, note, or reason text">
        </div>
        <div>
            <label class="form-label fw-semibold">Uploader username</label>
            <input type="search" name="uploader_name" class="form-control" value="<?= htmlspecialchars((string)($queueFilters['uploader_name'] ?? '')) ?>" placeholder="Contains match in decrypted username">
        </div>
        <div>
            <label class="form-label fw-semibold">File name</label>
            <input type="search" name="file_name" class="form-control" value="<?= htmlspecialchars((string)($queueFilters['file_name'] ?? '')) ?>" placeholder="Contains match in decrypted file name">
        </div>
        <div>
            <label class="form-label fw-semibold">Status</label>
            <select name="status" class="form-select">
                <option value="">All review states</option>
                <option value="held" <?= (($queueFilters['status'] ?? '') === 'held') ? 'selected' : '' ?>>Awaiting review</option>
                <option value="flagged_review" <?= (($queueFilters['status'] ?? '') === 'flagged_review') ? 'selected' : '' ?>>Needs closer review</option>
            </select>
        </div>
        <div>
            <label class="form-label fw-semibold">Risk band</label>
            <select name="risk_band" class="form-select">
                <option value="">Any risk</option>
                <option value="high" <?= (($queueFilters['risk_band'] ?? '') === 'high') ? 'selected' : '' ?>>High</option>
                <option value="medium" <?= (($queueFilters['risk_band'] ?? '') === 'medium') ? 'selected' : '' ?>>Medium</option>
                <option value="low" <?= (($queueFilters['risk_band'] ?? '') === 'low') ? 'selected' : '' ?>>Low</option>
            </select>
        </div>
        <div>
            <label class="form-label fw-semibold">Country</label>
            <select name="country" class="form-select">
                <option value="">All countries</option>
                <?php foreach (($reviewFilterOptions['countries'] ?? []) as $country): ?>
                    <?php $code = (string)($country['country_code'] ?? ''); ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= (($queueFilters['country_code'] ?? '') === $code) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($code) ?> (<?= (int)($country['total'] ?? 0) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label fw-semibold">Network type</label>
            <select name="network" class="form-select">
                <option value="">All networks</option>
                <?php foreach (($reviewFilterOptions['networks'] ?? []) as $network): ?>
                    <?php $networkValue = (string)($network['network_type'] ?? ''); ?>
                    <option value="<?= htmlspecialchars($networkValue) ?>" <?= (($queueFilters['network_type'] ?? '') === $networkValue) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($networkValue) ?> (<?= (int)($network['total'] ?? 0) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label fw-semibold">Uploader ID</label>
            <input type="number" min="1" name="uploader_id" class="form-control" value="<?= (int)($queueFilters['uploader_id'] ?? 0) ?: '' ?>" placeholder="User ID">
        </div>
        <div>
            <label class="form-label fw-semibold">File ID</label>
            <input type="number" min="1" name="file_id" class="form-control" value="<?= (int)($queueFilters['file_id'] ?? 0) ?: '' ?>" placeholder="File ID">
        </div>
        <div>
            <label class="form-label fw-semibold">Sort</label>
            <select name="sort" class="form-select">
                <option value="risk_desc" <?= (($queueFilters['sort'] ?? 'risk_desc') === 'risk_desc') ? 'selected' : '' ?>>Highest risk first</option>
                <option value="newest" <?= (($queueFilters['sort'] ?? '') === 'newest') ? 'selected' : '' ?>>Newest first</option>
                <option value="oldest" <?= (($queueFilters['sort'] ?? '') === 'oldest') ? 'selected' : '' ?>>Oldest first</option>
                <option value="amount_desc" <?= (($queueFilters['sort'] ?? '') === 'amount_desc') ? 'selected' : '' ?>>Largest amount first</option>
                <option value="amount_asc" <?= (($queueFilters['sort'] ?? '') === 'amount_asc') ? 'selected' : '' ?>>Smallest amount first</option>
            </select>
        </div>
        <div>
            <label class="form-label fw-semibold">Rows</label>
            <select name="per_page" class="form-select">
                <?php foreach ([25, 50, 100] as $size): ?>
                    <option value="<?= $size ?>" <?= $queuePerPage === $size ? 'selected' : '' ?>><?= $size ?> / page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fraud-filter-actions">
            <button type="submit" class="btn btn-primary">Apply filters</button>
            <a href="/admin/rewards-fraud?<?= htmlspecialchars(http_build_query(['view' => $viewMode])) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>

    <form method="POST" action="/admin/rewards-fraud/review" id="bulkReviewForm" class="fraud-bulk-bar">
        <?= \App\Core\Csrf::field() ?>
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
        <div class="fraud-bulk-copy">Use bulk review for obvious same-pattern cases. Leave uncertain cases waiting and open the row context first.</div>
        <div class="fraud-bulk-controls">
            <select class="form-select" name="review_action">
                <option value="recommended">Apply recommended action</option>
                <option value="clear">Clear selected</option>
                <option value="hold">Leave selected for review</option>
                <option value="reverse">Reverse selected</option>
            </select>
            <input type="text" class="form-control" name="review_note" placeholder="Optional shared review note">
            <button type="submit" class="btn btn-primary">Apply to selected</button>
        </div>
        <div class="fraud-note-presets">
            <?php foreach ($notePresets as $preset): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary fraud-note-preset" data-target-form="bulkReviewForm" data-note="<?= htmlspecialchars($preset) ?>"><?= htmlspecialchars($preset) ?></button>
            <?php endforeach; ?>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table align-middle fraud-queue-table">
            <thead>
                <tr>
                    <th class="fraud-select-col"><input type="checkbox" id="fraudSelectAll"></th>
                    <th>Uploader</th>
                    <th>File</th>
                    <th>Amount</th>
                    <th>Age</th>
                    <th>Status</th>
                    <th>Risk</th>
                    <th>Reasons</th>
                    <?php if (!$isTriageMode): ?>
                        <th>Signals</th>
                    <?php endif; ?>
                    <th class="fraud-actions-col">Review</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reviewQueue)): ?>
                    <tr><td colspan="<?= $isTriageMode ? '9' : '10' ?>" class="text-muted">No reward rows are currently waiting for review in this view.</td></tr>
                <?php else: ?>
                    <?php foreach ($reviewQueue as $row): ?>
                        <?php
                        $reasons = $row['risk_reasons'] ?? [];
                        $rowId = (int)($row['id'] ?? 0);
                        $detailId = 'fraud-detail-' . $rowId;
                        $riskScore = (int)($row['risk_score'] ?? 0);
                        $status = (string)($row['status'] ?? '');
                        $sessionId = (int)($row['session_id'] ?? 0);
                        $uploaderStats = $reviewUploaderStats[(int)($row['user_id'] ?? 0)] ?? null;
                        $fileStats = $reviewFileStats[(int)($row['file_id'] ?? 0)] ?? null;
                        $sessionDetail = $sessionId > 0 ? ($reviewSessionDetails[$sessionId] ?? null) : null;
                        $signalCounts = $sessionId > 0 ? ($reviewSignalCounts[$sessionId] ?? null) : null;
                        $downloader = ($sessionDetail && !empty($sessionDetail['downloader_user_id'])) ? ($reviewDownloaderMeta[(int)$sessionDetail['downloader_user_id']] ?? null) : null;
                        $referrers = $reviewRecentReferrers[(int)($row['file_id'] ?? 0)] ?? [];
                        $trustControl = $reviewTrustControls[(int)($row['user_id'] ?? 0)] ?? null;
                        $recommendation = $reviewRecommendations[$rowId] ?? null;
                        $recommendedAction = match (strtolower((string)($recommendation['tone'] ?? 'secondary'))) {
                            'success' => 'clear',
                            'danger' => 'reverse',
                            default => 'hold',
                        };
                        $recommendedLabel = match ($recommendedAction) {
                            'clear' => 'Clear recommended',
                            'reverse' => 'Reverse recommended',
                            default => 'Keep held',
                        };
                        $primaryReason = (string)($reasons[0] ?? 'Held for manual review.');
                        $rowFormId = 'fraud-row-form-' . $rowId;
                        ?>
                        <tr class="fraud-queue-row" data-row-index="<?= $rowId ?>" data-form-id="<?= htmlspecialchars($rowFormId) ?>" data-detail-id="<?= htmlspecialchars($detailId) ?>">
                            <td class="fraud-select-col">
                                <input type="checkbox" name="earning_ids[]" value="<?= $rowId ?>" form="bulkReviewForm" class="fraud-select-item">
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['username'] ?? ('User #' . (int)$row['user_id'])) ?></div>
                                <div class="small text-muted">User #<?= (int)($row['user_id'] ?? 0) ?></div>
                                <?php if (!empty($trustControl['trust_tier']) && $trustControl['trust_tier'] !== 'normal'): ?>
                                    <div class="small mt-1"><span class="badge bg-dark-subtle text-dark"><?= htmlspecialchars(ucfirst((string)$trustControl['trust_tier'])) ?></span></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['filename'] ?? ('File #' . (int)$row['file_id'])) ?></div>
                                <div class="small text-muted">File #<?= (int)($row['file_id'] ?? 0) ?><?php if (!empty($row['session_id'])): ?> | Session #<?= (int)$row['session_id'] ?><?php endif; ?></div>
                            </td>
                            <td>
                                <strong>$<?= number_format((float)($row['amount'] ?? 0), 4) ?></strong>
                            </td>
                            <td>
                                <div><?= htmlspecialchars(date('M d, Y', strtotime((string)($row['created_at'] ?? 'now')))) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars(date('H:i', strtotime((string)($row['created_at'] ?? 'now')))) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-<?= $status === 'flagged_review' ? 'danger' : 'warning text-dark' ?>"><?= htmlspecialchars($status === 'flagged_review' ? 'needs closer review' : 'awaiting review') ?></span>
                            </td>
                            <td>
                                <div class="fw-bold"><?= $riskScore ?></div>
                                <div class="small text-muted"><?= $riskScore >= (int)($reviewQueuePage['flag_threshold'] ?? 50) ? 'High' : ($riskScore >= (int)($reviewQueuePage['review_threshold'] ?? 25) ? 'Medium' : 'Low') ?></div>
                            </td>
                            <td>
                                <div class="small fw-semibold"><?= htmlspecialchars($primaryReason) ?></div>
                                <?php if ($recommendation): ?>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars((string)($recommendation['label'] ?? 'Needs review')) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if (!$isTriageMode): ?>
                            <td>
                                <div><?= htmlspecialchars((string)($row['country_code'] ?: '--')) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars((string)($row['network_type'] ?: 'unknown')) ?></div>
                            </td>
                            <?php endif; ?>
                            <td class="fraud-actions-col">
                                <?php if ($recommendation): ?>
                                    <div class="fraud-inline-recommendation tone-<?= htmlspecialchars((string)($recommendation['tone'] ?? 'secondary')) ?>">
                                        <strong><?= htmlspecialchars((string)($recommendation['label'] ?? 'Needs review')) ?>:</strong>
                                        <?= htmlspecialchars((string)($recommendation['detail'] ?? '')) ?>
                                    </div>
                                <?php endif; ?>
                                <form method="POST" action="/admin/rewards-fraud/review" class="fraud-row-form" id="<?= htmlspecialchars($rowFormId) ?>">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                    <input type="hidden" name="earning_id" value="<?= $rowId ?>">
                                    <input type="hidden" name="review_action" value="<?= htmlspecialchars($recommendedAction) ?>" class="fraud-action-input">
                                    <input type="text" class="form-control form-control-sm mb-2" name="review_note" placeholder="Optional review note">
                                    <div class="fraud-row-actions">
                                        <button type="submit" class="btn btn-sm btn-primary fraud-action-btn" data-action="<?= htmlspecialchars($recommendedAction) ?>"><?= htmlspecialchars($recommendedLabel) ?></button>
                                        <button type="submit" class="btn btn-sm btn-outline-success fraud-action-btn" data-action="clear">Clear</button>
                                        <button type="submit" class="btn btn-sm btn-outline-warning fraud-action-btn" data-action="hold">Hold</button>
                                        <button type="submit" class="btn btn-sm btn-outline-danger fraud-action-btn" data-action="reverse">Reverse</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary fraud-detail-toggle" data-target="<?= htmlspecialchars($detailId) ?>">View context</button>
                                    </div>
                                    <div class="fraud-note-presets fraud-note-presets--row">
                                        <?php foreach ($notePresets as $preset): ?>
                                            <button type="button" class="btn btn-xs btn-outline-secondary fraud-note-preset" data-target-form="<?= htmlspecialchars($rowFormId) ?>" data-note="<?= htmlspecialchars($preset) ?>"><?= htmlspecialchars($preset) ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <tr id="<?= htmlspecialchars($detailId) ?>" class="fraud-detail-row" hidden>
                            <td colspan="<?= $isTriageMode ? '9' : '10' ?>">
                                <div class="fraud-detail-grid">
                                    <div>
                                        <div class="fraud-detail-label">Queue context</div>
                                        <ul class="fraud-detail-list">
                                            <li><strong>Earning ID:</strong> <?= $rowId ?></li>
                                            <li><strong>Type:</strong> <?= htmlspecialchars((string)($row['type'] ?? 'download_reward')) ?></li>
                                            <li><strong>Created:</strong> <?= htmlspecialchars((string)($row['created_at'] ?? '')) ?></li>
                                            <li><strong>Hold until:</strong> <?= !empty($row['hold_until']) ? htmlspecialchars((string)$row['hold_until']) : 'Not set' ?></li>
                                            <li><strong>ASN:</strong> <?= htmlspecialchars((string)($row['asn'] ?: 'Unknown')) ?></li>
                                            <li><strong>Trust tier:</strong> <?= htmlspecialchars(ucfirst((string)($trustControl['trust_tier'] ?? 'normal'))) ?></li>
                                        </ul>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">Full risk reasons</div>
                                        <?php if (empty($reasons)): ?>
                                            <div class="small text-muted">No explicit reasons were stored. This row is waiting on a manual decision.</div>
                                        <?php else: ?>
                                            <ul class="fraud-detail-list">
                                                <?php foreach ($reasons as $reason): ?>
                                                    <li><?= htmlspecialchars((string)$reason) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">Recommended next move</div>
                                        <?php if (!$recommendation): ?>
                                            <div class="small text-muted">No recommendation was generated for this row.</div>
                                        <?php else: ?>
                                            <div class="fraud-recommendation-card tone-<?= htmlspecialchars((string)($recommendation['tone'] ?? 'secondary')) ?>">
                                                <div class="fw-semibold mb-1"><?= htmlspecialchars((string)($recommendation['label'] ?? 'Needs review')) ?></div>
                                                <div class="small"><?= htmlspecialchars((string)($recommendation['detail'] ?? '')) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">Previous review note</div>
                                        <div class="small text-muted"><?= !empty($row['review_note']) ? nl2br(htmlspecialchars((string)$row['review_note'])) : 'No review note recorded yet.' ?></div>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">Uploader pattern (30d)</div>
                                        <?php if ($canViewInvestigations): ?>
                                            <div class="mb-2"><a href="/admin/investigations/uploader/<?= (int)($row['user_id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">Open uploader investigation</a></div>
                                        <?php endif; ?>
                                        <?php if (!$uploaderStats): ?>
                                            <div class="small text-muted">No broader uploader pattern has been aggregated yet.</div>
                                        <?php else: ?>
                                            <ul class="fraud-detail-list">
                                                <li><strong>Total reward rows:</strong> <?= (int)($uploaderStats['total_rows'] ?? 0) ?></li>
                                                <li><strong>Awaiting / closer review:</strong> <?= (int)($uploaderStats['held_count'] ?? 0) ?> / <?= (int)($uploaderStats['flagged_count'] ?? 0) ?></li>
                                                <li><strong>Cleared / reversed / paid:</strong> <?= (int)($uploaderStats['cleared_count'] ?? 0) ?> / <?= (int)($uploaderStats['reversed_count'] ?? 0) ?> / <?= (int)($uploaderStats['paid_count'] ?? 0) ?></li>
                                                <li><strong>Rewarded files:</strong> <?= (int)($uploaderStats['file_count'] ?? 0) ?></li>
                                                <li><strong>Total amount:</strong> $<?= number_format((float)($uploaderStats['total_amount'] ?? 0), 4) ?></li>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">File pattern (30d)</div>
                                        <?php if ($canViewInvestigations): ?>
                                            <div class="mb-2"><a href="/admin/investigations/file/<?= (int)($row['file_id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">Open file investigation</a></div>
                                        <?php endif; ?>
                                        <?php if (!$fileStats): ?>
                                            <div class="small text-muted">No broader file-level reward pattern has been aggregated yet.</div>
                                        <?php else: ?>
                                            <ul class="fraud-detail-list">
                                                <li><strong>Total reward rows:</strong> <?= (int)($fileStats['total_rows'] ?? 0) ?></li>
                                                <li><strong>Awaiting / closer review:</strong> <?= (int)($fileStats['held_count'] ?? 0) ?> / <?= (int)($fileStats['flagged_count'] ?? 0) ?></li>
                                                <li><strong>Cleared / reversed / paid:</strong> <?= (int)($fileStats['cleared_count'] ?? 0) ?> / <?= (int)($fileStats['reversed_count'] ?? 0) ?> / <?= (int)($fileStats['paid_count'] ?? 0) ?></li>
                                                <li><strong>Distinct uploaders:</strong> <?= (int)($fileStats['uploader_count'] ?? 0) ?></li>
                                                <li><strong>Total amount:</strong> $<?= number_format((float)($fileStats['total_amount'] ?? 0), 4) ?></li>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">Session proof and network</div>
                                        <?php if (!$sessionDetail): ?>
                                            <div class="small text-muted">No linked download session is available for this earning.</div>
                                        <?php else: ?>
                                            <ul class="fraud-detail-list">
                                                <li><strong>Completion:</strong> <?= number_format((float)($sessionDetail['percent_complete'] ?? 0), 2) ?>% (<?= number_format((int)($sessionDetail['bytes_sent'] ?? 0)) ?> / <?= number_format((int)($sessionDetail['bytes_expected'] ?? 0)) ?> bytes)</li>
                                                <li><strong>Watch:</strong> <?= (int)($sessionDetail['watch_seconds'] ?? 0) ?>s / <?= number_format((float)($sessionDetail['watch_percent'] ?? 0), 2) ?>%</li>
                                                <li><strong>Proxy intel:</strong> <?= (int)($sessionDetail['proxy_intel_risk_score'] ?? 0) ?><?= !empty($sessionDetail['proxy_intel_type']) ? ' (' . htmlspecialchars((string)$sessionDetail['proxy_intel_type']) . ')' : '' ?></li>
                                                <li><strong>Cloudflare score:</strong> <?= (int)($sessionDetail['cloudflare_risk_score'] ?? 0) ?></li>
                                                <li><strong>Delivery mode:</strong> <?= htmlspecialchars((string)($sessionDetail['delivery_mode'] ?? 'php_proxy')) ?></li>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">Repeat signal counts (24h)</div>
                                        <?php if (!$signalCounts): ?>
                                            <div class="small text-muted">No repeat-signature counts are available for this session yet.</div>
                                        <?php else: ?>
                                            <ul class="fraud-detail-list">
                                                <li><strong>Same visitor cookie:</strong> <?= (int)($signalCounts['same_cookie'] ?? 0) ?> other sessions</li>
                                                <li><strong>Same IP hash:</strong> <?= (int)($signalCounts['same_ip'] ?? 0) ?> other sessions</li>
                                                <li><strong>Same browser signature:</strong> <?= (int)($signalCounts['same_ua'] ?? 0) ?> other sessions</li>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fraud-detail-label">Downloader account</div>
                                        <?php if (!$downloader): ?>
                                            <div class="small text-muted">Guest or no downloader account was linked to this earning.</div>
                                        <?php else: ?>
                                            <ul class="fraud-detail-list">
                                                <li><strong>Downloader:</strong> <?= htmlspecialchars((string)($downloader['username'] ?? ('User #' . (int)$downloader['id']))) ?></li>
                                                <li><strong>Email verified:</strong> <?= !empty($downloader['email_verified']) ? 'Yes' : 'No' ?></li>
                                                <li><strong>Account created:</strong> <?= !empty($downloader['created_at']) ? htmlspecialchars((string)$downloader['created_at']) : 'Unknown' ?></li>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fraud-detail-span-3">
                                        <div class="fraud-detail-label">Last 5 unique referring pages for this file</div>
                                        <?php if (empty($referrers)): ?>
                                            <div class="small text-muted">No recent referring pages were captured for rewarded sessions on this file.</div>
                                        <?php else: ?>
                                            <div class="fraud-referrer-list">
                                                <?php foreach ($referrers as $referrer): ?>
                                                    <div class="fraud-referrer-item">
                                                        <div class="fraud-referrer-url">
                                                            <a href="<?= htmlspecialchars((string)$referrer['download_page_referrer_url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$referrer['download_page_referrer_url']) ?></a>
                                                            <?php if (!empty($referrer['download_page_referrer_internal'])): ?>
                                                                <span class="badge bg-secondary ms-2">Internal</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <?= (int)($referrer['session_count'] ?? 0) ?> sessions |
                                                            held <?= (int)($referrer['held_count'] ?? 0) ?> |
                                                            cleared <?= (int)($referrer['cleared_count'] ?? 0) ?> |
                                                            reversed <?= (int)($referrer['reversed_count'] ?? 0) ?> |
                                                            last seen <?= htmlspecialchars((string)($referrer['last_seen'] ?? '')) ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($queuePages > 1): ?>
        <div class="fraud-pagination">
            <a class="btn btn-outline-secondary btn-sm <?= $queuePageNumber <= 1 ? 'disabled' : '' ?>" href="<?= $queuePageNumber <= 1 ? '#' : '/admin/rewards-fraud?' . $queryParams(['page' => 1]) ?>">First</a>
            <a class="btn btn-outline-secondary btn-sm <?= $queuePageNumber <= 1 ? 'disabled' : '' ?>" href="<?= $queuePageNumber <= 1 ? '#' : '/admin/rewards-fraud?' . $queryParams(['page' => $queuePageNumber - 1]) ?>">Previous</a>
            <span class="fraud-pagination-copy">Page <?= $queuePageNumber ?> of <?= $queuePages ?></span>
            <a class="btn btn-outline-secondary btn-sm <?= $queuePageNumber >= $queuePages ? 'disabled' : '' ?>" href="<?= $queuePageNumber >= $queuePages ? '#' : '/admin/rewards-fraud?' . $queryParams(['page' => $queuePageNumber + 1]) ?>">Next</a>
            <a class="btn btn-outline-secondary btn-sm <?= $queuePageNumber >= $queuePages ? 'disabled' : '' ?>" href="<?= $queuePageNumber >= $queuePages ? '#' : '/admin/rewards-fraud?' . $queryParams(['page' => $queuePages]) ?>">Last</a>
        </div>
    <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<?php renderAdminCardStart('Recent Reward Activity'); ?>
            <div class="small text-muted mb-3">Use this to confirm that rewarded traffic has been flowing even when the live review queue is empty. It covers the last <?= $recentRewardWindowDays ?> days across awaiting review, closer review, cleared, reversed, paid, and cancelled earnings.</div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Created</th>
                    <th>Uploader</th>
                    <th>File</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Risk</th>
                    <th>Signals</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentRewardActivity)): ?>
                    <tr><td colspan="7" class="text-muted">No reward earnings were created in the last <?= $recentRewardWindowDays ?> days.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentRewardActivity as $row): ?>
                        <?php
                        $recentStatus = (string)($row['status'] ?? '');
                        $recentBadge = match ($recentStatus) {
                            'held' => 'warning text-dark',
                            'flagged_review', 'reversed', 'cancelled' => 'danger',
                            'cleared', 'paid' => 'success',
                            default => 'secondary',
                        };
                        ?>
                        <tr>
                            <td>
                                <div><?= htmlspecialchars(date('M d, Y', strtotime((string)($row['created_at'] ?? 'now')))) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars(date('H:i', strtotime((string)($row['created_at'] ?? 'now')))) ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['username'] ?? ('User #' . (int)$row['user_id'])) ?></div>
                                <div class="small text-muted">User #<?= (int)($row['user_id'] ?? 0) ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['filename'] ?? ('File #' . (int)$row['file_id'])) ?></div>
                                <div class="small text-muted">File #<?= (int)($row['file_id'] ?? 0) ?></div>
                            </td>
                            <td><strong>$<?= number_format((float)($row['amount'] ?? 0), 4) ?></strong></td>
                            <td><span class="badge bg-<?= $recentBadge ?>"><?= htmlspecialchars($recentStatus) ?></span></td>
                            <td><?= (int)($row['risk_score'] ?? 0) ?></td>
                            <td>
                                <div><?= htmlspecialchars((string)($row['country_code'] ?: '--')) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars((string)($row['network_type'] ?: 'unknown')) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php renderAdminCardEnd(); ?>

<div class="row g-4 mt-1">
    <div class="col-xl-6">
        <?php renderAdminCardStart('Uploader Risk Scores'); ?>
            <div class="small text-muted mb-3">Use this to find repeat uploaders who are driving the queue. When one account is responsible for a large review cluster, handle that pattern before working one row at a time.</div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Uploader</th>
                            <th>Trust Tier</th>
                            <th>Risk Score</th>
                            <th>Held</th>
                            <th>Flagged</th>
                            <th>Signals</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($uploaderScores)): ?>
                            <tr><td colspan="6" class="text-muted">No uploader risk data has been aggregated yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($uploaderScores as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($row['username'] ?? ('User #' . (int)$row['user_id'])) ?></div>
                                        <div class="small text-muted">User #<?= (int)($row['user_id'] ?? 0) ?></div>
                                    </td>
                                    <td>
                                        <form method="POST" action="/admin/rewards-fraud/trust" class="fraud-trust-form">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)($row['user_id'] ?? 0) ?>">
                                            <select class="form-select form-select-sm" name="trust_tier">
                                                <?php foreach ($trustTierOptions as $tierValue => $tierLabel): ?>
                                                    <option value="<?= htmlspecialchars((string)$tierValue) ?>" <?= (($row['trust_tier'] ?? 'normal') === $tierValue) ? 'selected' : '' ?>><?= htmlspecialchars((string)$tierLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" class="form-control form-control-sm mt-2" name="trust_note" placeholder="Optional trust note" value="<?= htmlspecialchars((string)($row['review_note'] ?? '')) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary mt-2">Save tier</button>
                                        </form>
                                    </td>
                                    <td><strong><?= (int)($row['risk_score'] ?? 0) ?></strong></td>
                                    <td><?= (int)($row['held_count'] ?? 0) ?></td>
                                    <td><?= (int)($row['flagged_count'] ?? 0) ?></td>
                                    <td class="small text-muted"><?= (int)($row['suspicious_file_count'] ?? 0) ?> suspicious files, <?= (int)($row['suspicious_network_count'] ?? 0) ?> suspicious networks</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php renderAdminCardEnd(); ?>
</div>
    </div>
    <div class="col-xl-6">
        <?php renderAdminCardStart('Network Insights'); ?>
            <div class="small text-muted mb-3">Clusters here can explain why the queue is growing. If one ASN, country group, or network class keeps surfacing, handle that pattern in settings before manually reviewing every earning.</div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>ASN</th>
                            <th>Country</th>
                            <th>Network</th>
                            <th>Sessions</th>
                            <th>Held</th>
                            <th>Flagged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($networkInsights)): ?>
                            <tr><td colspan="6" class="text-muted">No network clusters have been summarized yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($networkInsights as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($row['asn'] ?? 'Unknown')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['country_code'] ?? '--')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['network_type'] ?? 'unknown')) ?></td>
                                    <td><?= (int)($row['session_count'] ?? 0) ?></td>
                                    <td><?= (int)($row['held_count'] ?? 0) ?></td>
                                    <td><?= (int)($row['flagged_count'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>

<details class="fraud-secondary-panel mt-4">
    <summary>
        <span>
            <span class="fraud-secondary-title">Protection rules and signal health</span>
            <span class="fraud-secondary-copy">Expand to tune scoring rules, review Cloudflare and proxy intelligence health, and adjust how new traffic gets held or cleared.</span>
        </span>
        <span class="fraud-secondary-chevron" aria-hidden="true"></span>
    </summary>
    <div class="row g-4 mt-1">
        <div class="col-lg-4">
            <?php renderAdminCardStart('Intelligence Health'); ?>
                <div class="small text-muted mb-3">Cloudflare is the strongest built-in source for visitor network context. If this looks weak, review Config Hub &gt; Security &gt; Cloudflare before trusting country and network scoring.</div>
                <div class="alert alert-info border-0 shadow-sm small mb-3">
                    <strong>Want stronger fraud signals?</strong> Turn on <strong>Intelligence mode</strong> in <a href="/admin/configuration?tab=security&sec_tab=identity">Config Hub &gt; Security &gt; Identity &amp; VPN</a>. That lets fyuhls query ProxyCheck, attach proxy or VPN intelligence to reward sessions, and use it in fraud scoring without blocking the visitor.
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>Trust Cloudflare Headers</span>
                    <strong><?= !empty($cloudflareHealth['trust_cloudflare']) ? 'On' : 'Off' ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>Trusted Proxy Ranges</span>
                    <strong><?= (int)($cloudflareHealth['trusted_proxy_count'] ?? 0) ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>Cloudflare Header Seen</span>
                    <strong><?= !empty($cloudflareHealth['cf_header_seen']) ? 'Yes' : 'No' ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span>Detected Visitor IP</span>
                    <strong><?= htmlspecialchars((string)($cloudflareHealth['real_ip_source'] ?? 'Unknown')) ?></strong>
                </div>
            <?php renderAdminCardEnd(); ?>
        </div>
        <div class="col-lg-8">
            <?php renderAdminCardStart('Protection Settings'); ?>
                <?php if (!$canManageFraudSettings): ?>
                    <div class="alert alert-light border small mb-3">
                        Rewards reviewers can inspect the current fraud rules here, but only staff with full Configuration access can change them.
                    </div>
                <?php endif; ?>
                <form method="POST" action="/admin/rewards-fraud/save">
                    <?= \App\Core\Csrf::field() ?>
                    <fieldset <?= $canManageFraudSettings ? '' : 'disabled' ?>>
                    <?php
                    $switches = [
                        'rewards_fraud_enabled' => 'Enable Rewards Fraud Protection',
                        'rewards_verified_completion_required' => 'Require Verified Completion for Reward Credit',
                        'rewards_auto_clear_low_risk' => 'Auto-Clear Low-Risk Earnings',
                        'rewards_auto_reverse_high_risk' => 'Auto-Reverse Hard Fraud Cases',
                        'rewards_use_cloudflare_intel' => 'Use Cloudflare Integration Data',
                        'rewards_use_proxy_intel' => 'Use Proxy Intelligence Data',
                        'rewards_use_ip_hash' => 'Use IP Hash',
                        'rewards_use_ua_hash' => 'Use User-Agent Hash',
                        'rewards_use_cookie_hash' => 'Use Visitor Cookie Hash',
                        'rewards_use_accept_language_hash' => 'Use Accept-Language Hash',
                        'rewards_use_timezone_offset' => 'Use Timezone Offset',
                        'rewards_use_platform_screen' => 'Use Platform + Screen Bucket',
                        'rewards_use_asn_network' => 'Use ASN + Network Classification',
                        'rewards_ppd_guests_only' => 'PPD Guests Only',
                        'rewards_require_downloader_verification' => 'Require Downloader Email Verification',
                        'rewards_block_linked_downloader_accounts' => 'Block Linked Downloader Accounts',
                        'rewards_hold_new_account_downloads' => 'Hold New Downloader Accounts',
                    ];
                    $switchDescriptions = [
                        'rewards_fraud_enabled' => 'Master switch for the rewards fraud review and scoring system.',
                        'rewards_verified_completion_required' => 'Only count rewarded traffic when the app has strong proof that the download or playback actually completed enough to qualify.',
                        'rewards_auto_clear_low_risk' => 'Allows low-risk earnings to clear automatically instead of waiting for manual review every time.',
                        'rewards_auto_reverse_high_risk' => 'Lets clearly abusive traffic reverse itself automatically instead of piling up in the manual queue.',
                        'rewards_use_cloudflare_intel' => 'Uses Cloudflare-restored visitor and network context when Cloudflare is configured correctly.',
                        'rewards_use_proxy_intel' => 'Uses proxy or VPN intelligence, such as ProxyCheck lookups, as part of the fraud score.',
                        'rewards_use_ip_hash' => 'Uses a privacy-safe IP-derived signal to cluster repeat traffic without relying on raw IPs alone.',
                        'rewards_use_ua_hash' => 'Uses browser user-agent patterns to spot repeated clients across different sessions or IPs.',
                        'rewards_use_cookie_hash' => 'Uses the first-party visitor cookie to detect repeat visitors even when the IP changes.',
                        'rewards_use_accept_language_hash' => 'Adds browser language settings as a lightweight consistency signal.',
                        'rewards_use_timezone_offset' => 'Adds the browser timezone offset as another soft fingerprinting signal.',
                        'rewards_use_platform_screen' => 'Uses basic platform and screen-size buckets to strengthen browser-level clustering.',
                        'rewards_use_asn_network' => 'Uses network owner and network type data, such as ISP, datacenter, or hosting classification.',
                        'rewards_ppd_guests_only' => 'Only let guest downloads count toward pay-per-download rewards.',
                        'rewards_require_downloader_verification' => 'Require the downloader account to have a verified email before rewarded traffic can count.',
                        'rewards_block_linked_downloader_accounts' => 'Block or heavily penalize downloader accounts that look linked to the uploader.',
                        'rewards_hold_new_account_downloads' => 'Put very new downloader accounts into a held state instead of trusting them immediately.',
                    ];
                    $numberDescriptions = [
                        'rewards_hold_days' => 'How many days earnings should stay on hold before they are eligible to clear.',
                        'rewards_min_downloader_account_age_days' => 'Minimum age, in days, a downloader account should be before it is treated as lower risk.',
                        'rewards_review_threshold' => 'Risk score where traffic should move into manual review instead of clearing normally.',
                        'rewards_flag_threshold' => 'Higher risk score where traffic should be treated as strongly suspicious and flagged.',
                        'rewards_auto_reverse_threshold' => 'Risk score where the system should stop waiting for humans and auto-reverse the reward instead.',
                        'rewards_fraud_event_retention_days' => 'How long detailed fraud-session and event records should be kept before cleanup.',
                        'rewards_fraud_trim_mb' => 'Approximate size limit where older fraud event detail should begin trimming to protect storage and database growth.',
                    ];
                    ?>
                    <div class="row">
                        <?php foreach ($switches as $key => $label): ?>
                            <div class="col-md-6 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>" value="1" <?= (($settings[$key] ?? '0') === '1') ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                                </div>
                                <div class="small text-muted mt-1"><?= htmlspecialchars($switchDescriptions[$key] ?? '') ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Hold Period (Days)</label>
                            <input type="number" class="form-control" name="rewards_hold_days" value="<?= htmlspecialchars($settings['rewards_hold_days'] ?? '7') ?>" min="0">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_hold_days']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Min Downloader Account Age</label>
                            <input type="number" class="form-control" name="rewards_min_downloader_account_age_days" value="<?= htmlspecialchars($settings['rewards_min_downloader_account_age_days'] ?? '0') ?>" min="0">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_min_downloader_account_age_days']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Review Threshold</label>
                            <input type="number" class="form-control" name="rewards_review_threshold" value="<?= htmlspecialchars($settings['rewards_review_threshold'] ?? '25') ?>" min="0">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_review_threshold']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Flag Threshold</label>
                            <input type="number" class="form-control" name="rewards_flag_threshold" value="<?= htmlspecialchars($settings['rewards_flag_threshold'] ?? '50') ?>" min="1">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_flag_threshold']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Auto-Reverse Threshold</label>
                            <input type="number" class="form-control" name="rewards_auto_reverse_threshold" value="<?= htmlspecialchars($settings['rewards_auto_reverse_threshold'] ?? '85') ?>" min="1">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_auto_reverse_threshold']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Event Retention (Days)</label>
                            <input type="number" class="form-control" name="rewards_fraud_event_retention_days" value="<?= htmlspecialchars($settings['rewards_fraud_event_retention_days'] ?? '30') ?>" min="7">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_fraud_event_retention_days']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Trim Threshold (MB)</label>
                            <input type="number" class="form-control" name="rewards_fraud_trim_mb" value="<?= htmlspecialchars($settings['rewards_fraud_trim_mb'] ?? '1024') ?>" min="64">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_fraud_trim_mb']) ?></div>
                        </div>
                    </div>

                    <div class="alert alert-light border small mt-3">
                        <div class="fw-bold mb-1">What to look for</div>
                        <div>Repeated visitor cookies across many IPs usually indicates replay automation.</div>
                        <div>Premium-country traffic from hosting or proxy-classified networks should usually be held before payout.</div>
                        <div>If Cloudflare intelligence looks weak, review Config Hub &gt; Security &gt; Cloudflare before trusting country and network insights.</div>
                    </div>

                    <?php if ($canManageFraudSettings): ?>
                        <button type="submit" class="btn btn-primary mt-3">Save Rewards Fraud Settings</button>
                    <?php endif; ?>
                    </fieldset>
                </form>
            <?php renderAdminCardEnd(); ?>
        </div>
    </div>
</details>

<style>
.fraud-shortcuts-notice{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;padding:1rem 1.1rem;border:1px solid #dbe3f0;border-radius:14px;background:#f8fbff;margin-bottom:1rem;flex-wrap:wrap}
.fraud-shortcuts-title{font-size:.92rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#1e3a8a;margin-bottom:.35rem}
.fraud-shortcuts-copy{color:#475569;max-width:72ch}
.fraud-shortcuts-copy kbd{display:inline-block;padding:.1rem .35rem;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:6px;background:#fff;font-size:.78rem;font-weight:700;color:#0f172a}
.fraud-view-toggle{display:flex;gap:.5rem;flex-wrap:wrap}
.fraud-quick-filters{display:flex;flex-wrap:wrap;gap:.6rem;margin:0 0 1rem}
.fraud-quick-filter{display:inline-flex;align-items:center;padding:.45rem .7rem;border:1px solid #dbe3f0;border-radius:999px;background:#fff;color:#1e3a8a;font-size:.88rem;font-weight:700;text-decoration:none}
.fraud-quick-filter:hover{background:#eff6ff;color:#1d4ed8}
.fraud-queue-toolbar{display:flex;justify-content:space-between;gap:1rem;align-items:flex-end;margin-bottom:1rem;flex-wrap:wrap}
.fraud-queue-title{font-size:1.1rem;font-weight:800;margin:0 0 .35rem}
.fraud-queue-copy{margin:0;color:#64748b;max-width:72ch}
.fraud-queue-summary{font-size:.95rem;color:#475569;font-weight:600}
.fraud-filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin-bottom:1rem}
.fraud-filter-span-2{grid-column:span 2}
.fraud-filter-actions{display:flex;gap:.75rem;align-items:flex-end}
.fraud-bulk-bar{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:.9rem 1rem;border:1px solid #dbe3f0;border-radius:14px;background:#f8fbff;margin-bottom:1rem;flex-wrap:wrap;position:sticky;top:1rem;z-index:20;box-shadow:0 12px 25px rgba(15,23,42,.08)}
.fraud-bulk-copy{color:#64748b;max-width:48ch}
.fraud-bulk-controls{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
.fraud-bulk-controls .form-select,.fraud-bulk-controls .form-control{min-width:180px}
.fraud-note-presets{display:flex;flex-wrap:wrap;gap:.45rem}
.fraud-note-presets--row{margin-top:.55rem}
.fraud-cluster-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}
.fraud-cluster-panel{border:1px solid #dbe3f0;border-radius:14px;background:#f8fbff;padding:1rem}
.fraud-cluster-heading{font-size:.92rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#64748b;margin-bottom:.75rem}
.fraud-cluster-item{display:flex;flex-direction:column;gap:.85rem;padding:.9rem 0;border-top:1px solid #e5edf8}
.fraud-cluster-item:first-child{border-top:none;padding-top:0}
.fraud-cluster-main{min-width:0}
.fraud-cluster-main .fw-semibold{overflow-wrap:anywhere}
.fraud-cluster-url a{word-break:break-all}
.fraud-cluster-form{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.fraud-cluster-form .btn{min-width:110px}
.fraud-cluster-recommendation,.fraud-inline-recommendation,.fraud-recommendation-card{margin-top:.45rem;padding:.5rem .65rem;border-radius:12px;font-size:.88rem}
.fraud-cluster-recommendation{display:inline-flex;align-self:flex-start;max-width:100%}
.fraud-inline-recommendation{margin-bottom:.5rem}
.tone-success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}
.tone-warning{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}
.tone-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.tone-secondary{background:#f8fafc;color:#475569;border:1px solid #dbe3f0}
.fraud-trust-form{min-width:190px}
.fraud-queue-table th{white-space:nowrap}
.fraud-select-col{width:42px}
.fraud-actions-col{min-width:320px}
.fraud-row-form{min-width:220px}
.fraud-row-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.45rem}
.fraud-queue-row.is-active td{outline:2px solid #60a5fa;outline-offset:-2px;background:#f8fbff}
.fraud-detail-row td{background:#f8fbff;border-top:none}
.fraud-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;padding:.25rem 0}
.fraud-detail-span-3{grid-column:span 3}
.fraud-detail-label{font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:.4rem}
.fraud-detail-list{margin:0;padding-left:1rem}
.fraud-referrer-list{display:flex;flex-direction:column;gap:.75rem}
.fraud-referrer-item{padding:.75rem .85rem;border:1px solid #dbe3f0;border-radius:12px;background:#fff}
.fraud-referrer-url a{word-break:break-all}
.fraud-pagination{display:flex;justify-content:flex-end;align-items:center;gap:.5rem;margin-top:1rem;flex-wrap:wrap}
.fraud-pagination-copy{padding:0 .5rem;color:#475569;font-weight:600}
.fraud-secondary-panel{margin-top:1.5rem;border:1px solid #dbe3f0;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.04)}
.fraud-secondary-panel[open]{padding:1rem 1rem 1.25rem}
.fraud-secondary-panel summary{cursor:pointer;list-style:none;padding:1rem 1.1rem;font-weight:800;display:flex;align-items:center;justify-content:space-between;gap:1rem}
.fraud-secondary-panel summary::-webkit-details-marker{display:none}
.fraud-secondary-title{display:block}
.fraud-secondary-copy{display:block;margin-top:.3rem;font-size:.92rem;font-weight:500;color:#64748b}
.fraud-secondary-chevron{flex:0 0 auto;width:.75rem;height:.75rem;border-right:2px solid #1e3a8a;border-bottom:2px solid #1e3a8a;transform:rotate(45deg);transition:transform .2s ease}
.fraud-secondary-panel[open] .fraud-secondary-chevron{transform:rotate(225deg)}
@media (max-width: 1200px){
    .fraud-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .fraud-filter-span-2{grid-column:span 2}
    .fraud-cluster-grid{grid-template-columns:1fr}
    .fraud-detail-grid{grid-template-columns:1fr}
    .fraud-detail-span-3{grid-column:span 1}
}
@media (max-width: 768px){
    .fraud-filter-grid{grid-template-columns:1fr}
    .fraud-filter-span-2{grid-column:span 1}
    .fraud-filter-actions,.fraud-bulk-controls,.fraud-view-toggle{flex-direction:column;align-items:stretch}
    .fraud-cluster-form{flex-direction:column;align-items:stretch}
    .fraud-cluster-form .btn,.fraud-row-actions .btn{max-width:none;min-width:0;width:100%}
    .fraud-row-actions{grid-template-columns:1fr}
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const queueRows = Array.from(document.querySelectorAll('.fraud-queue-row'));
    let activeIndex = queueRows.length > 0 ? 0 : -1;

    const setActiveRow = function (index) {
        if (queueRows.length === 0) {
            activeIndex = -1;
            return;
        }
        activeIndex = Math.max(0, Math.min(index, queueRows.length - 1));
        queueRows.forEach(function (row, idx) {
            row.classList.toggle('is-active', idx === activeIndex);
        });
        queueRows[activeIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    if (activeIndex >= 0) {
        setActiveRow(0);
    }

    const selectAll = document.getElementById('fraudSelectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.fraud-select-item').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });
    }

    document.querySelectorAll('.fraud-action-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const action = button.getAttribute('data-action');
            const form = button.closest('form');
            const input = form ? form.querySelector('.fraud-action-input') : null;
            if (input && action) {
                input.value = action;
            }
        });
    });

    document.querySelectorAll('.fraud-note-preset').forEach(function (button) {
        button.addEventListener('click', function () {
            const formId = button.getAttribute('data-target-form');
            const note = button.getAttribute('data-note') || '';
            const form = formId ? document.getElementById(formId) : button.closest('form');
            const input = form ? form.querySelector('input[name=\"review_note\"]') : null;
            if (!input) {
                return;
            }
            input.value = note;
            input.focus();
        });
    });

    queueRows.forEach(function (row, index) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('button, a, input, select, textarea, label')) {
                return;
            }
            setActiveRow(index);
        });
    });

    document.querySelectorAll('.fraud-detail-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const targetId = button.getAttribute('data-target');
            const row = targetId ? document.getElementById(targetId) : null;
            if (!row) {
                return;
            }
            const isHidden = row.hasAttribute('hidden');
            if (isHidden) {
                row.removeAttribute('hidden');
                button.textContent = 'Hide context';
            } else {
                row.setAttribute('hidden', 'hidden');
                button.textContent = 'View context';
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) {
            return;
        }
        if (activeIndex < 0) {
            return;
        }

        const activeRow = queueRows[activeIndex];
        const formId = activeRow.getAttribute('data-form-id');
        const form = formId ? document.getElementById(formId) : null;
        const detailToggle = form ? form.querySelector('.fraud-detail-toggle') : null;
        const checkbox = activeRow.querySelector('.fraud-select-item');

        if (event.shiftKey && event.key.toLowerCase() === 'a') {
            event.preventDefault();
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
            }
            return;
        }

        switch (event.key.toLowerCase()) {
            case 'j':
                event.preventDefault();
                setActiveRow(activeIndex + 1);
                break;
            case 'k':
                event.preventDefault();
                setActiveRow(activeIndex - 1);
                break;
            case 'x':
                event.preventDefault();
                detailToggle?.click();
                break;
            case 'c':
                event.preventDefault();
                form?.querySelector('.fraud-action-btn[data-action=\"clear\"]')?.click();
                break;
            case 'h':
                event.preventDefault();
                form?.querySelector('.fraud-action-btn[data-action=\"hold\"]')?.click();
                break;
            case 'r':
                event.preventDefault();
                form?.querySelector('.fraud-action-btn[data-action=\"reverse\"]')?.click();
                break;
        }
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
