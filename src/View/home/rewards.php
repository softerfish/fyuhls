<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "My Rewards - {$siteName}";
$currentUser = \App\Core\Auth::user();
$currentModel = strtolower((string)($userModel ?? ($currentUser['monetization_model'] ?? 'ppd')));
$modelLabelMap = [
    'ppd' => 'Pay Per Download',
    'pps' => 'Pay Per Sale',
    'mixed' => 'Hybrid',
];
$currentModelLabel = $modelLabelMap[$currentModel] ?? 'Not selected';
$paymentMethodRaw = strtolower(trim((string)($defaultWithdrawalMethod ?? '')));
$paymentMethodLabel = $paymentMethodRaw !== '' ? \App\Service\PayoutProcessorService::label($paymentMethodRaw) : 'Not set';
$paymentDestinationLabel = $paymentMethodRaw !== '' ? \App\Service\PayoutProcessorService::destinationLabel($paymentMethodRaw) : 'Payout destination';
$availableBalance = (float)($availableBalance ?? 0);
$pendingAmount = (float)($amountsByStatus['pending'] ?? 0);
$heldAmount = (float)($amountsByStatus['held'] ?? 0);
$clearedAmount = (float)($amountsByStatus['cleared'] ?? 0);
$cancelledAmount = (float)($amountsByStatus['cancelled'] ?? 0);
$flaggedAmount = (float)($amountsByStatus['flagged_review'] ?? 0);
$reversedAmount = (float)($amountsByStatus['reversed'] ?? 0);
$bonusSummary = is_array($bonusSummary ?? null) ? $bonusSummary : [];
$bonusHistory = is_array($bonusHistory ?? null) ? $bonusHistory : [];
$activePromotions = is_array($activePromotions ?? null) ? $activePromotions : [];
$recentRewardActivity = is_array($recentRewardActivity ?? null) ? $recentRewardActivity : [];
$availableBonusBalance = (float)($bonusSummary['cleared_bonus_value'] ?? $bonusSummary['available_bonus_balance'] ?? 0);
$pendingBonusReview = (float)($bonusSummary['pending_bonus_review'] ?? 0);
$creditedBonusTotal = (float)($bonusSummary['credited_bonus_total'] ?? 0);
$paidBonusTotal = (float)($bonusSummary['paid_bonus_total'] ?? 0);
$minimumWithdrawalAmount = max(0, round((float)($minimumWithdrawalAmount ?? 1), 2));
$supportedWithdrawalMethods = array_values(array_filter(array_map('trim', (array)($supportedWithdrawalMethods ?? []))));
$withdrawalMethodsAvailable = !empty($supportedWithdrawalMethods);
$savedMethodSupported = $paymentMethodRaw !== '' && in_array($paymentMethodRaw, $supportedWithdrawalMethods, true);
$savedPayoutConfigured = $savedMethodSupported && trim((string)($defaultWithdrawalDetails ?? '')) !== '';
$hasOpenWithdrawal = !empty($hasOpenWithdrawal);
$payoutReady = $withdrawalMethodsAvailable && $savedPayoutConfigured && $availableBalance >= $minimumWithdrawalAmount && !$hasOpenWithdrawal;
$payoutRequestAvailable = $payoutReady;
$trend = is_array($trend ?? null) ? $trend : [];
$currentDownloadsTrend = (int)($trend['current_downloads'] ?? 0);
$previousDownloadsTrend = (int)($trend['previous_downloads'] ?? 0);
$downloadsDelta = (int)($trend['downloads_delta'] ?? 0);
$currentEarningsTrend = (float)($trend['current_earnings'] ?? 0);
$previousEarningsTrend = (float)($trend['previous_earnings'] ?? 0);
$earningsDelta = (float)($trend['earnings_delta'] ?? 0);
$payoutReadinessTitle = $hasOpenWithdrawal ? 'Awaiting current payout' : ($payoutReady ? 'Ready for payout' : 'Keep earning');
$payoutReadinessBody = $hasOpenWithdrawal
    ? 'A payout request is already waiting to be processed. Another request can be submitted after that one is approved, paid, or rejected.'
    : ($payoutReady
        ? 'You have enough cleared balance to submit a payout request right now.'
        : (($paymentMethodRaw !== '' && !$savedMethodSupported)
            ? 'Your saved payout processor is no longer enabled. Update it in Settings before requesting a payout.'
            : (!$savedPayoutConfigured
                ? 'Save a supported payout processor and destination in Settings before requesting a payout.'
                : (!$withdrawalMethodsAvailable
                    ? 'Payout requests are temporarily unavailable because no payout processors are enabled right now.'
                    : 'You need at least $' . number_format($minimumWithdrawalAmount, 2) . ' in cleared balance before requesting a payout.'))));
$performanceSummary = ((int)($countedDownloads ?? 0) > 0)
    ? 'Use the performance sections below to see what has cleared, what was filtered, and where your earnings came from.'
    : 'Once your files start generating qualifying traffic, performance and earnings detail will appear here.';
$resolvedDownloads = max(0, (int)($countedDownloads ?? 0) + (int)($rejectedDownloads ?? 0));
$acceptanceRate = $resolvedDownloads > 0 ? round(((int)($countedDownloads ?? 0) / $resolvedDownloads) * 100, 1) : null;
$hasAnyRewardActivity = (
    ($pendingRewards ?? 0) > 0
    || $pendingAmount > 0
    || $heldAmount > 0
    || $flaggedAmount > 0
    || $clearedAmount > 0
    || ($countedDownloads ?? 0) > 0
    || ($rejectedDownloads ?? 0) > 0
    || ($totalPaid ?? 0) > 0
);
$payoutChecklist = [
    [
        'label' => 'Withdrawal methods enabled',
        'done' => $withdrawalMethodsAvailable,
        'detail' => $withdrawalMethodsAvailable ? 'The admin has payout processors enabled right now.' : 'Payout requests stay closed until an admin enables at least one payout processor.',
    ],
    [
        'label' => 'Saved payout destination',
        'done' => $savedPayoutConfigured,
        'detail' => $savedPayoutConfigured
            ? 'Your saved payout processor and destination are ready.'
            : ($paymentMethodRaw !== '' && !$savedMethodSupported
                ? 'Your saved payout processor is no longer enabled. Update it in Settings before requesting payout.'
                : 'Add and save payout details in Settings before requesting payout.'),
    ],
    [
        'label' => 'Minimum reached',
        'done' => $availableBalance >= $minimumWithdrawalAmount,
        'detail' => $availableBalance >= $minimumWithdrawalAmount
            ? 'Your cleared balance is at or above the minimum request amount.'
            : 'You need $' . number_format(max(0, $minimumWithdrawalAmount - $availableBalance), 2) . ' more in cleared balance to reach the minimum.',
    ],
    [
        'label' => 'No payout already waiting',
        'done' => !$hasOpenWithdrawal,
        'detail' => !$hasOpenWithdrawal ? 'You can submit a new payout when you are ready.' : 'Another request is already in progress, so a second request cannot be submitted yet.',
    ],
];
$timelineSteps = [
    [
        'title' => 'Recorded',
        'copy' => 'Traffic or a bonus hit is logged and queued for reward checks.',
        'state' => (($pendingRewards ?? 0) > 0 || $pendingAmount > 0) ? 'active' : ($hasAnyRewardActivity ? 'complete' : 'upcoming'),
    ],
    [
        'title' => 'Under review',
        'copy' => 'Fraud, quality, hold, or admin review decides whether it clears or gets filtered out.',
        'state' => ($heldAmount > 0 || $flaggedAmount > 0 || ($pendingRewards ?? 0) > 0) ? 'active' : (($countedDownloads ?? 0) > 0 || ($rejectedDownloads ?? 0) > 0 ? 'complete' : 'upcoming'),
    ],
    [
        'title' => 'Cleared',
        'copy' => 'Approved rewards move into your cleared balance and count toward payout readiness.',
        'state' => $clearedAmount > 0 ? 'active' : 'upcoming',
    ],
    [
        'title' => 'Paid or rejected',
        'copy' => 'Cleared balance can be requested for payout, while filtered traffic shows up in the rejected views below.',
        'state' => (($totalPaid ?? 0) > 0 || ($rejectedDownloads ?? 0) > 0) ? 'active' : 'upcoming',
    ],
];
$heldGuidance = $heldAmount > 0 || $pendingBonusReview > 0 || $flaggedAmount > 0
    ? 'Held or flagged activity usually needs a little more time for hold checks or manual review before it either clears or gets removed.'
    : 'When future earnings land in held or flagged review, they will show here until the review pipeline finishes.';
$earnMoreTips = [];
if ($acceptanceRate !== null && $acceptanceRate < 60) {
    $earnMoreTips[] = 'A lot of recent traffic is being filtered. Check the rejection reasons table to see whether duplicate windows, proof checks, or traffic quality filters are doing most of the blocking.';
}
if ($currentDownloadsTrend > 0 && $currentEarningsTrend <= 0.01) {
    $earnMoreTips[] = 'You are getting some cleared download volume without much cleared earnings value. Focus on files and audiences that match stronger reward tiers.';
}
foreach ((array)$recentEarnings as $candidateRow) {
    if (((float)($candidateRow['total_amount'] ?? 0) > 0) || ((int)($candidateRow['counted_downloads'] ?? 0) > 0)) {
        $topFileName = \App\Service\EncryptionService::decrypt((string)($candidateRow['filename'] ?? '')) ?: 'your top recent file';
        break;
    }
}
if (!empty($topFileName ?? '')) {
    $earnMoreTips[] = 'Your most recent earning activity is coming from ' . $topFileName . '. That is a good candidate to promote or refresh first.';
}
if (!empty($activePromotions)) {
    $earnMoreTips[] = 'You have active promotions running right now. Bonus progress below shows the fastest extra rewards currently available to your account.';
}
if ($earnMoreTips === []) {
    $earnMoreTips[] = 'Keep publishing files that match your strongest audience and check this page weekly to see which files are turning cleared traffic into real earnings.';
}
$withdrawalStatusLabels = [
    'pending' => 'Waiting for review',
    'approved' => 'Approved and queued',
    'paid' => 'Paid',
    'rejected' => 'Declined',
    'cancelled' => 'Canceled',
    'reversed' => 'Reversed',
];

$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .rewards-shell { margin-top: 1rem; }
    .rewards-main { min-width: 0; }
    .rewards-toolbar-note { font-size: 0.8125rem; color: var(--text-muted); line-height: 1.55; }
    .rewards-hero-card,
    .rewards-panel,
    .rewards-table-panel,
    .rewards-chart-panel,
    .rewards-glossary {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
    }
    .rewards-hero-card {
        padding: 1.5rem;
        margin-bottom: 1.75rem;
    }
    .rewards-hero-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.3fr) minmax(300px, 0.9fr);
        gap: 1.5rem;
        align-items: stretch;
    }
    .rewards-hero-copy {
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-width: 0;
    }
    .rewards-hero-copy h1 {
        margin: 0 0 0.85rem;
        font-size: 2.15rem;
        font-weight: 800;
        color: var(--text-color);
    }
    .rewards-hero-copy p {
        margin: 0;
        font-size: 1rem;
        color: var(--text-muted);
        line-height: 1.7;
        max-width: 720px;
    }
    .rewards-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1.4rem;
    }
    .rewards-summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }
    .rewards-summary-card {
        background: #f8fbff;
        border: 1px solid #dbe6f3;
        border-radius: 16px;
        padding: 1rem;
        min-width: 0;
    }
    .rewards-summary-label {
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0.55rem;
    }
    .rewards-summary-value {
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1.15;
        color: var(--text-color);
    }
    .rewards-summary-copy {
        margin-top: 0.35rem;
        font-size: 0.9rem;
        color: var(--text-muted);
        line-height: 1.45;
    }
    .rewards-stat-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 1.75rem;
    }
    .rewards-stat-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1rem 1.1rem;
        min-width: 0;
    }
    .rewards-stat-label {
        color: var(--text-muted);
        font-size: 0.78rem;
        margin-bottom: 0.45rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 700;
    }
    .rewards-stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--text-color);
        line-height: 1.15;
    }
    .rewards-stat-copy {
        margin-top: 0.35rem;
        font-size: 0.85rem;
        color: var(--text-muted);
        line-height: 1.45;
    }
    .rewards-alert {
        color: #b45309;
    }
    .rewards-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }
    .rewards-panel {
        padding: 1.35rem;
    }
    .rewards-panel--soft {
        background: #f8fafc;
    }
    .rewards-panel-header {
        margin-bottom: 1rem;
    }
    .rewards-panel-header h2 {
        margin: 0;
        font-size: 1.35rem;
        color: var(--text-color);
    }
    .rewards-panel-header p {
        margin: 0.45rem 0 0;
        color: var(--text-muted);
        line-height: 1.6;
    }
    .rewards-payout-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
        margin-bottom: 1rem;
    }
    .rewards-payout-tile {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.95rem;
    }
    .rewards-payout-tile-label {
        font-size: 0.76rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 0.35rem;
    }
    .rewards-payout-tile-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text-color);
    }
    .rewards-payout-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1rem;
    }
    .rewards-pill-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        margin-top: 1rem;
    }
    .rewards-pill {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        border-radius: 9999px;
        padding: 0.55rem 0.9rem;
        font-size: 0.84rem;
        line-height: 1.35;
    }
    .rewards-referral-box {
        background: #f8fafc;
        border: 1px solid var(--border-color);
        padding: 1.1rem;
        border-radius: 12px;
        margin-top: 1rem;
    }
    .rewards-referral-title {
        margin: 0 0 0.45rem;
        font-size: 1rem;
    }
    .rewards-referral-copy {
        font-size: 0.875rem;
        color: var(--text-muted);
        line-height: 1.55;
        margin: 0 0 0.85rem;
    }
    .rewards-referral-row { display: flex; gap: 0.5rem; }
    .rewards-referral-input { flex: 1; padding: 0.65rem 0.75rem; border: 1px solid var(--border-color); border-radius: 8px; }
    .rewards-copy-btn { width: auto; }
    .rewards-section {
        margin-top: 2.4rem;
    }
    .rewards-section-heading {
        margin-bottom: 1rem;
    }
    .rewards-section-heading h2 {
        margin: 0;
        font-size: 1.4rem;
        color: var(--text-color);
    }
    .rewards-section-heading p {
        margin: 0.45rem 0 0;
        color: var(--text-muted);
        line-height: 1.6;
        max-width: 780px;
    }
    .rewards-breakdown-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
    }
    .rewards-stage-grid,
    .rewards-tip-grid,
    .rewards-promo-grid,
    .rewards-trend-grid {
        display: grid;
        gap: 1rem;
    }
    .rewards-stage-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    .rewards-trend-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        margin-top: 1rem;
    }
    .rewards-tip-grid {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    }
    .rewards-stage-card,
    .rewards-tip-card,
    .rewards-promo-card,
    .rewards-trend-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1rem 1.1rem;
        min-width: 0;
    }
    .rewards-stage-card[data-state="active"] {
        border-color: #bfdbfe;
        background: #f8fbff;
    }
    .rewards-stage-card[data-state="complete"] {
        border-color: #c7f9d4;
        background: #f7fcf8;
    }
    .rewards-stage-card[data-state="upcoming"] {
        background: #fcfcfd;
    }
    .rewards-stage-kicker,
    .rewards-tip-kicker,
    .rewards-trend-kicker {
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0.45rem;
    }
    .rewards-stage-title,
    .rewards-tip-title,
    .rewards-trend-title {
        font-size: 1rem;
        font-weight: 800;
        color: var(--text-color);
        margin: 0 0 0.35rem;
    }
    .rewards-stage-copy,
    .rewards-tip-copy,
    .rewards-trend-copy {
        margin: 0;
        font-size: 0.9rem;
        color: var(--text-muted);
        line-height: 1.55;
    }
    .rewards-checklist {
        display: grid;
        gap: 0.85rem;
        margin-top: 1rem;
    }
    .rewards-checklist-item {
        display: grid;
        grid-template-columns: 20px minmax(0, 1fr);
        gap: 0.85rem;
        align-items: start;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 0.9rem;
    }
    .rewards-check-icon {
        width: 20px;
        height: 20px;
        border-radius: 9999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        font-weight: 800;
        background: #e5e7eb;
        color: #475569;
        margin-top: 0.05rem;
    }
    .rewards-check-icon.is-done {
        background: #dcfce7;
        color: #166534;
    }
    .rewards-check-title {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--text-color);
    }
    .rewards-check-copy {
        margin: 0.2rem 0 0;
        font-size: 0.85rem;
        line-height: 1.5;
        color: var(--text-muted);
    }
    .rewards-trend-value {
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--text-color);
        line-height: 1.15;
    }
    .rewards-trend-delta {
        margin-top: 0.3rem;
        font-size: 0.84rem;
        font-weight: 700;
    }
    .rewards-trend-delta.is-up { color: #166534; }
    .rewards-trend-delta.is-down { color: #b45309; }
    .rewards-trend-delta.is-flat { color: #64748b; }
    .rewards-promo-grid {
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        margin-top: 1rem;
    }
    .rewards-promo-top {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        align-items: start;
    }
    .rewards-promo-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: var(--text-color);
    }
    .rewards-promo-copy {
        margin: 0.35rem 0 0;
        color: var(--text-muted);
        font-size: 0.88rem;
        line-height: 1.55;
    }
    .rewards-promo-meta {
        margin-top: 0.9rem;
        display: grid;
        gap: 0.35rem;
        font-size: 0.84rem;
        color: var(--text-muted);
    }
    .rewards-progress {
        margin-top: 0.95rem;
    }
    .rewards-progress-bar {
        height: 10px;
        background: #e2e8f0;
        border-radius: 9999px;
        overflow: hidden;
    }
    .rewards-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
        border-radius: 9999px;
    }
    .rewards-progress-label {
        margin-top: 0.4rem;
        font-size: 0.83rem;
        color: var(--text-muted);
    }
    .rewards-breakdown-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1rem 1.1rem;
        min-width: 0;
    }
    .rewards-breakdown-label {
        color: var(--text-muted);
        font-size: 0.78rem;
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 700;
    }
    .rewards-breakdown-value {
        font-size: 1.2rem;
        font-weight: 800;
        color: var(--text-color);
    }
    .rewards-breakdown-copy {
        margin-top: 0.3rem;
        font-size: 0.84rem;
        color: var(--text-muted);
        line-height: 1.45;
    }
    .rewards-chart-panel,
    .rewards-table-panel,
    .rewards-glossary {
        padding: 1.35rem;
    }
    .rewards-chart-frame {
        height: 320px;
        margin-top: 1rem;
    }
    .earnings-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        margin-top: 1rem;
    }
    .earnings-table {
        width: 100%;
        background: white;
        border-collapse: collapse;
        min-width: 760px;
    }
    .earnings-table th {
        background: #f8fafc;
        text-align: left;
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-muted);
    }
    .earnings-table td {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.875rem;
        vertical-align: top;
    }
    .earnings-table tbody tr:last-child td {
        border-bottom: none;
    }
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-paid { background: #dcfce7; color: #166534; }
    .badge-approved,
    .badge-cleared { background: #dbeafe; color: #1d4ed8; }
    .badge-cancelled,
    .badge-denied,
    .badge-reversed { background: #fee2e2; color: #991b1b; }
    .badge-neutral { background: #e5e7eb; color: #374151; }
    .rewards-empty-cell {
        text-align: center;
        color: var(--text-muted);
        padding: 2.5rem 1rem;
    }
    .rewards-glossary {
        margin-top: 2.4rem;
        background: #f8fafc;
    }
    .rewards-glossary-title {
        font-size: 0.875rem;
        font-weight: 700;
        color: var(--text-muted);
        margin-bottom: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .rewards-glossary-list {
        margin: 0;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 0.35rem 1.8rem;
    }
    .rewards-glossary-list dt {
        font-weight: 700;
        font-size: 0.84rem;
        color: var(--text-color);
        margin-top: 0.75rem;
    }
    .rewards-glossary-list dd {
        margin: 0;
        font-size: 0.82rem;
        color: var(--text-muted);
        line-height: 1.55;
    }
    .rewards-modal { display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; }
    .rewards-modal-card { background: white; padding: 2.5rem; border-radius: 16px; width: 450px; max-width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
    .rewards-modal-title { margin-top: 0; margin-bottom: 0.5rem; font-size: 1.5rem; }
    .rewards-modal-copy { color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem; }
    .rewards-modal-field { margin-bottom: 1.5rem; }
    .rewards-modal-field--last { margin-bottom: 2rem; }
    .rewards-modal-row { display: flex; gap: 1rem; justify-content: flex-end; }
    .rewards-modal-cancel, .rewards-modal-submit { width: auto; }
    .rewards-modal-cancel { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; opacity: 1; }
    .rewards-modal-cancel:hover { background: #e2e8f0; color: #0f172a; }
    @media (max-width: 1180px) {
        .rewards-hero-grid,
        .rewards-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (min-width: 1320px) {
        .rewards-stat-strip--five {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }
    }
    @media (max-width: 900px) {
        .rewards-summary-grid,
        .rewards-payout-grid {
            grid-template-columns: 1fr;
        }
        .rewards-stage-grid,
        .rewards-tip-grid,
        .rewards-promo-grid,
        .rewards-trend-grid {
            grid-template-columns: 1fr;
        }
        .rewards-hero-copy h1 {
            font-size: 1.85rem;
        }
        .rewards-referral-row,
        .rewards-modal-row,
        .rewards-hero-actions {
            flex-direction: column;
        }
        .rewards-modal-row > .btn,
        .rewards-referral-row > .btn {
            width: 100%;
        }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

include __DIR__ . '/header.php';
include __DIR__ . '/partials/account_sidebar_styles.php';
?>

<div class="fm-container rewards-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>
    <div class="fm-main rewards-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Rewards & Earnings</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Rewards</span>
                </div>
            </div>
            <div class="toolbar-right">
                <div class="toolbar-controls">
                    <span class="rewards-toolbar-note">Use this area to see what qualified, what is still being reviewed, and when your balance is ready for payout.</span>
                </div>
            </div>
        </div>

        <div class="rewards-hero-card">
            <div class="rewards-hero-grid">
                <div class="rewards-hero-copy">
                    <h1>Your earnings dashboard</h1>
                    <p>Track available balance, holds, payout requests, and earning performance from one place. Review the sections below when you want to understand what counted, what was filtered, and what still needs to clear.</p>
                    <div class="rewards-hero-actions">
                        <button class="btn btn-primary" id="showWithdrawModalBtn" type="button" <?= !$payoutRequestAvailable ? 'disabled aria-disabled="true"' : '' ?>>Request Payout</button>
                        <a class="btn btn-secondary" href="/settings">Update Payout Settings</a>
                        <a class="btn btn-white" href="/rewards/export.csv">Export CSV</a>
                        <a class="btn btn-white" href="/affiliate">Open Creator Rewards Guide</a>
                        <?php if (!empty($activePromotions)): ?>
                            <a class="btn btn-white" href="/promotions">View Promotions</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rewards-summary-grid">
                    <div class="rewards-summary-card">
                        <div class="rewards-summary-label">Available to withdraw</div>
                        <div class="rewards-summary-value">$<?= number_format($availableBalance, 2) ?></div>
                        <div class="rewards-summary-copy"><?= $payoutReadinessBody ?></div>
                    </div>
                    <div class="rewards-summary-card">
                        <div class="rewards-summary-label">Current model</div>
                        <div class="rewards-summary-value"><?= htmlspecialchars($currentModelLabel) ?></div>
                        <div class="rewards-summary-copy">Your reward model controls how qualifying traffic turns into earnings.</div>
                    </div>
                    <div class="rewards-summary-card">
                        <div class="rewards-summary-label">Payment method</div>
                        <div class="rewards-summary-value"><?= htmlspecialchars($paymentMethodLabel) ?></div>
                        <div class="rewards-summary-copy"><?= $savedPayoutConfigured ? 'This saved payout processor and saved payout destination will be used when you request a payout.' : ($paymentMethodRaw !== '' && !$savedMethodSupported ? 'Your saved payout processor is no longer enabled. Choose another payout processor in Settings before requesting payout.' : 'Add and save payout destination details before submitting your first payout request.') ?></div>
                    </div>
                    <div class="rewards-summary-card">
                        <div class="rewards-summary-label">Total paid</div>
                        <div class="rewards-summary-value">$<?= number_format((float)($totalPaid ?? 0), 2) ?></div>
                        <div class="rewards-summary-copy">Use payout history below to review past requests and staff notes.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rewards-stat-strip<?= \App\Service\FeatureService::affiliateEnabled() ? ' rewards-stat-strip--five' : '' ?>">
            <div class="rewards-stat-card">
                <div class="rewards-stat-label">Held earnings</div>
                <div class="rewards-stat-value">$<?= number_format($heldAmount, 4) ?></div>
                <div class="rewards-stat-copy">Temporarily waiting on hold periods or review before moving to cleared status or being reversed/cancelled.</div>
            </div>
            <div class="rewards-stat-card">
                <div class="rewards-stat-label">Pending review</div>
                <div class="rewards-stat-value rewards-alert"><?= number_format((int)($pendingRewards ?? 0)) ?></div>
                <div class="rewards-stat-copy">Downloads still being evaluated by fraud and eligibility checks.</div>
            </div>
            <div class="rewards-stat-card">
                <div class="rewards-stat-label">Cleared downloads</div>
                <div class="rewards-stat-value"><?= number_format((int)($countedDownloads ?? 0)) ?></div>
                <div class="rewards-stat-copy">Qualified downloads that have already cleared into earnings.</div>
            </div>
            <div class="rewards-stat-card">
                <div class="rewards-stat-label">Rejected downloads</div>
                <div class="rewards-stat-value rewards-alert"><?= number_format((int)($rejectedDownloads ?? 0)) ?></div>
                <div class="rewards-stat-copy">Filtered traffic that did not count toward earnings.</div>
            </div>
            <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                <div class="rewards-stat-card">
                    <div class="rewards-stat-label">Earning referrals</div>
                    <div class="rewards-stat-value"><?= number_format((int)($referralCount ?? 0)) ?></div>
                    <div class="rewards-stat-copy">Referred users with cleared or paid reward earnings.</div>
                </div>
            <?php endif; ?>
        </div>

        <section class="rewards-section">
            <div class="rewards-section-heading">
                <h2>What happens next</h2>
                <p>Rewards move through a few predictable stages before money becomes available. This gives you a quick read on where your current activity is sitting right now.</p>
            </div>
            <div class="rewards-stage-grid">
                <?php foreach ($timelineSteps as $step): ?>
                    <div class="rewards-stage-card" data-state="<?= htmlspecialchars($step['state']) ?>">
                        <div class="rewards-stage-kicker"><?= htmlspecialchars(strtoupper($step['state'])) ?></div>
                        <h3 class="rewards-stage-title"><?= htmlspecialchars($step['title']) ?></h3>
                        <p class="rewards-stage-copy"><?= htmlspecialchars($step['copy']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-section-heading">
                <h2>Money & payout</h2>
                <p>Use this section when the question is "what can I withdraw, what is still settling, and what do I need to do next?"</p>
            </div>
        </section>

        <div class="rewards-grid">
            <section class="rewards-panel">
                <div class="rewards-panel-header">
                    <h2>Payout readiness checklist</h2>
                    <p>These are the exact things the payout flow checks before a request can be submitted.</p>
                </div>
                <div class="rewards-checklist">
                    <?php foreach ($payoutChecklist as $item): ?>
                        <div class="rewards-checklist-item">
                            <span class="rewards-check-icon <?= $item['done'] ? 'is-done' : '' ?>"><?= $item['done'] ? 'OK' : '!' ?></span>
                            <div>
                                <p class="rewards-check-title"><?= htmlspecialchars($item['label']) ?></p>
                                <p class="rewards-check-copy"><?= htmlspecialchars($item['detail']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="rewards-panel rewards-panel--soft">
                <div class="rewards-panel-header">
                    <h2>Held and review guidance</h2>
                    <p><?= htmlspecialchars($heldGuidance) ?></p>
                </div>
                <div class="rewards-pill-list">
                    <span class="rewards-pill">Pending amount: $<?= number_format($pendingAmount, 4) ?></span>
                    <span class="rewards-pill">Held amount: $<?= number_format($heldAmount, 4) ?></span>
                    <?php if ($flaggedAmount > 0): ?>
                        <span class="rewards-pill">Flagged review amount: $<?= number_format($flaggedAmount, 4) ?></span>
                    <?php endif; ?>
                    <?php if ($pendingBonusReview > 0): ?>
                        <span class="rewards-pill">Bonus review waiting: $<?= number_format($pendingBonusReview, 2) ?></span>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <section class="rewards-section">
            <div class="rewards-section-heading">
                <h2>Bonuses</h2>
                <p>Bonus offers feed into the same withdrawable balance once they are approved or auto-credited. Use this area to see what is still waiting on review, what has already been added, and which promotion each bonus came from.</p>
            </div>
            <div class="rewards-breakdown-grid">
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Cleared bonus value</div>
                    <div class="rewards-breakdown-value">$<?= number_format($availableBonusBalance, 2) ?></div>
                    <div class="rewards-breakdown-copy">Bonus value that has reached your cleared rewards balance. Payout requests draw from the combined balance, so this is not a separate remaining bonus wallet.</div>
                </div>
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Pending bonus review</div>
                    <div class="rewards-breakdown-value">$<?= number_format($pendingBonusReview, 2) ?></div>
                    <div class="rewards-breakdown-copy">Bonuses you earned that still need admin approval.</div>
                </div>
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Lifetime positive bonuses credited</div>
                    <div class="rewards-breakdown-value">$<?= number_format($creditedBonusTotal, 2) ?></div>
                    <div class="rewards-breakdown-copy">Total positive bonus value that has reached your main rewards balance before any later reversals.</div>
                </div>
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Bonus payout flow</div>
                    <div class="rewards-breakdown-value">Same balance</div>
                    <div class="rewards-breakdown-copy">Approved bonuses use the same cleared balance and payout requests as the rest of your rewards. They are not paid out through a separate bonus-only wallet.</div>
                </div>
                <?php if (($recoveryHoldBalance ?? 0) > 0): ?>
                    <div class="rewards-breakdown-card">
                        <div class="rewards-breakdown-label">Recovery hold</div>
                        <div class="rewards-breakdown-value rewards-alert">$<?= number_format((float)$recoveryHoldBalance, 2) ?></div>
                        <div class="rewards-breakdown-copy">A later bonus or rewards adjustment reduced cleared earnings after earlier payouts. Future cleared earnings will offset this amount before more balance becomes available.</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($activePromotions)): ?>
        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Active promotions</h2>
                    <p>These are the bonus offers currently visible to your account, how far along you are, and whether they credit automatically or wait for approval.</p>
                </div>
                <div class="rewards-promo-grid">
                    <?php foreach ($activePromotions as $promo): ?>
                        <div class="rewards-promo-card">
                            <div class="rewards-promo-top">
                                <div>
                                    <h3 class="rewards-promo-title"><?= htmlspecialchars((string)$promo['public_title']) ?></h3>
                                    <p class="rewards-promo-copy"><?= htmlspecialchars((string)($promo['public_description'] ?? '')) ?></p>
                                </div>
                                <span class="badge <?= (string)($promo['award_mode'] ?? '') === 'auto_credit' ? 'badge-approved' : 'badge-pending' ?>"><?= htmlspecialchars((string)$promo['award_mode_label']) ?></span>
                            </div>
                            <div class="rewards-promo-meta">
                                <div><strong>Reward:</strong> <?= htmlspecialchars((string)$promo['reward_preview']) ?></div>
                                <div><strong>Goal:</strong> <?= htmlspecialchars((string)$promo['goal_summary']) ?></div>
                                <div><strong>Schedule:</strong> <?= htmlspecialchars((string)$promo['schedule_label']) ?></div>
                            </div>
                            <div class="rewards-progress">
                                <div class="rewards-progress-bar">
                                    <div class="rewards-progress-fill" style="width: <?= (int)($promo['progress_percent'] ?? 0) ?>%;"></div>
                                </div>
                                <div class="rewards-progress-label"><?= htmlspecialchars((string)($promo['progress_cycle_label'] ?? $promo['progress_label'])) ?> (<?= (int)($promo['progress_percent'] ?? 0) ?>%)</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <div class="rewards-grid">
            <section class="rewards-panel">
                <div class="rewards-panel-header">
                    <h2>Payout status</h2>
                    <p>Use this panel to see whether your balance is ready, whether your payout details are in place, and what to do next.</p>
                </div>
                <div class="rewards-payout-grid">
                    <div class="rewards-payout-tile">
                        <div class="rewards-payout-tile-label">Available balance</div>
                        <div class="rewards-payout-tile-value">$<?= number_format($availableBalance, 2) ?></div>
                    </div>
                    <?php if (($recoveryHoldBalance ?? 0) > 0): ?>
                        <div class="rewards-payout-tile">
                            <div class="rewards-payout-tile-label">Recovery hold</div>
                            <div class="rewards-payout-tile-value rewards-alert">$<?= number_format((float)$recoveryHoldBalance, 2) ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="rewards-payout-tile">
                        <div class="rewards-payout-tile-label">Readiness</div>
                        <div class="rewards-payout-tile-value"><?= htmlspecialchars($payoutReadinessTitle) ?></div>
                    </div>
                    <div class="rewards-payout-tile">
                        <div class="rewards-payout-tile-label">Payout method</div>
                        <div class="rewards-payout-tile-value"><?= htmlspecialchars($paymentMethodLabel) ?></div>
                    </div>
                    <div class="rewards-payout-tile">
                        <div class="rewards-payout-tile-label">Cleared balance</div>
                        <div class="rewards-payout-tile-value">$<?= number_format($clearedAmount, 4) ?></div>
                    </div>
                </div>
                    <div class="rewards-payout-actions">
                    <button class="btn btn-primary" type="button" id="showWithdrawModalBtnSecondary" <?= !$payoutRequestAvailable ? 'disabled aria-disabled="true"' : '' ?>>Request Payout</button>
                        <a class="btn btn-secondary" href="/settings">Manage payout details</a>
                    </div>
                    <div class="rewards-pill-list">
                        <span class="rewards-pill">Minimum payout request: $<?= number_format($minimumWithdrawalAmount, 2) ?></span>
                        <span class="rewards-pill"><?= $savedPayoutConfigured ? 'Saved payout destination is on file.' : ($paymentMethodRaw !== '' && !$savedMethodSupported ? 'Your saved payout processor is no longer enabled.' : 'No saved payout destination yet.') ?></span>
                        <span class="rewards-pill"><?= $hasOpenWithdrawal ? 'A payout request is already waiting to be processed.' : ($payoutReady ? 'A payout request can be submitted now.' : 'More cleared balance is needed before payout.') ?></span>
                        <?php if (!$withdrawalMethodsAvailable): ?>
                            <span class="rewards-pill">Payout requests are temporarily unavailable because the admin has not enabled any payout processors.</span>
                        <?php endif; ?>
                    </div>
            </section>

            <section class="rewards-panel">
                <div class="rewards-panel-header">
                    <h2>How to read this page</h2>
                    <p><?= htmlspecialchars($performanceSummary) ?></p>
                </div>
                <div class="rewards-pill-list">
                    <span class="rewards-pill">Pending and held earnings are not withdrawable yet.</span>
                    <span class="rewards-pill">Cleared earnings are the source of your available balance, after open payout requests and any recovery hold are accounted for.</span>
                    <span class="rewards-pill">Cancelled or reversed earnings were removed after review.</span>
                    <span class="rewards-pill">Use the guide page when you want a refresher on models and qualification rules.</span>
                </div>

                <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                    <div class="rewards-referral-box">
                        <h3 class="rewards-referral-title">Your referral link</h3>
                        <p class="rewards-referral-copy">Share this link to credit signups to your account. Referral commissions are tracked separately from your creator reward model.</p>
                        <div class="rewards-referral-row">
                            <?php
                            $refCode = trim((string)($currentUser['public_id'] ?? ''));
                            $refLink = $refCode !== ''
                                ? \App\Service\SeoService::trustedBaseUrl() . '/?ref=' . rawurlencode($refCode)
                                : '';
                            ?>
                            <input type="text" value="<?= htmlspecialchars($refLink !== '' ? $refLink : 'Referral link unavailable. Please contact support if this persists.') ?>" readonly class="rewards-referral-input">
                            <button class="btn rewards-copy-btn" data-copy-previous data-copy-success="Copied!" <?= $refLink === '' ? 'disabled' : '' ?>>Copy</button>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <section class="rewards-section">
            <div class="rewards-section-heading">
                <h2>Performance & growth</h2>
                <p>Use this section when the question is "what is working, where is cleared traffic coming from, and what should I focus on next?"</p>
            </div>
            <div class="rewards-trend-grid">
                <div class="rewards-trend-card">
                    <div class="rewards-trend-kicker">Cleared earnings trend</div>
                    <div class="rewards-trend-value">$<?= number_format($currentEarningsTrend, 2) ?></div>
                    <div class="rewards-trend-copy">Cleared earnings from the last 7 days.</div>
                    <?php
                    $earningsDirection = $earningsDelta > 0 ? 'is-up' : ($earningsDelta < 0 ? 'is-down' : 'is-flat');
                    $earningsSign = $earningsDelta > 0 ? '+' : '';
                    ?>
                    <div class="rewards-trend-delta <?= $earningsDirection ?>"><?= $earningsSign ?>$<?= number_format($earningsDelta, 2) ?> vs the previous 7 days</div>
                </div>
                <div class="rewards-trend-card">
                    <div class="rewards-trend-kicker">Cleared download trend</div>
                    <div class="rewards-trend-value"><?= number_format($currentDownloadsTrend) ?></div>
                    <div class="rewards-trend-copy">Cleared downloads from the last 7 days.</div>
                    <?php
                    $downloadsDirection = $downloadsDelta > 0 ? 'is-up' : ($downloadsDelta < 0 ? 'is-down' : 'is-flat');
                    $downloadsSign = $downloadsDelta > 0 ? '+' : '';
                    ?>
                    <div class="rewards-trend-delta <?= $downloadsDirection ?>"><?= $downloadsSign . number_format($downloadsDelta) ?> vs the previous 7 days</div>
                </div>
                <div class="rewards-trend-card">
                    <div class="rewards-trend-kicker">Acceptance rate</div>
                    <div class="rewards-trend-value"><?= $acceptanceRate !== null ? number_format($acceptanceRate, 1) . '%' : 'n/a' ?></div>
                    <div class="rewards-trend-copy">Resolved traffic that turned into cleared qualifying rewards instead of being rejected.</div>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-section-heading">
                <h2>How to earn more</h2>
                <p>These suggestions are based on the current reward data on this page, not generic marketing advice.</p>
            </div>
            <div class="rewards-tip-grid">
                <?php foreach ($earnMoreTips as $index => $tip): ?>
                    <div class="rewards-tip-card">
                        <div class="rewards-tip-kicker">Focus <?= $index + 1 ?></div>
                        <p class="rewards-tip-copy"><?= htmlspecialchars($tip) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-section-heading">
                <h2>Earnings breakdown</h2>
                <p>These totals show how your earnings are moving through review, hold, cleared, and removed states before they reach your available balance.</p>
            </div>
            <div class="rewards-breakdown-grid">
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Pending amount</div>
                    <div class="rewards-breakdown-value">$<?= number_format($pendingAmount, 4) ?></div>
                    <div class="rewards-breakdown-copy">Still under review and not yet counted in available balance.</div>
                </div>
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Held amount</div>
                    <div class="rewards-breakdown-value">$<?= number_format($heldAmount, 4) ?></div>
                    <div class="rewards-breakdown-copy">Temporarily held while staff or fraud checks finish.</div>
                </div>
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Cleared amount</div>
                    <div class="rewards-breakdown-value">$<?= number_format($clearedAmount, 4) ?></div>
                    <div class="rewards-breakdown-copy">Approved and available for payout requests.</div>
                </div>
                <div class="rewards-breakdown-card">
                    <div class="rewards-breakdown-label">Cancelled amount</div>
                    <div class="rewards-breakdown-value">$<?= number_format($cancelledAmount, 4) ?></div>
                    <div class="rewards-breakdown-copy">Removed after review and no longer payable.</div>
                </div>
                <?php if ($flaggedAmount > 0): ?>
                    <div class="rewards-breakdown-card">
                        <div class="rewards-breakdown-label">Flagged review amount</div>
                        <div class="rewards-breakdown-value">$<?= number_format($flaggedAmount, 4) ?></div>
                        <div class="rewards-breakdown-copy">Queued for extra review before final status is decided.</div>
                    </div>
                <?php endif; ?>
                <?php if ($reversedAmount > 0): ?>
                    <div class="rewards-breakdown-card">
                        <div class="rewards-breakdown-label">Reversed amount</div>
                        <div class="rewards-breakdown-value">$<?= number_format($reversedAmount, 4) ?></div>
                        <div class="rewards-breakdown-copy">Later removed after a reversal or post-review correction.</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-chart-panel">
                <div class="rewards-section-heading">
                    <h2>Performance (last 7 days)</h2>
                    <p>Use this chart to compare daily cleared earnings with daily cleared downloads so you can spot which days turned into real approved performance.</p>
                </div>
                <div class="rewards-chart-frame">
                    <canvas id="earningsChart"></canvas>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Recent rewards activity</h2>
                    <p>Track the latest reward changes hitting your account, including clears, holds, bonus credits, and any later removals after review or file deletion.</p>
                </div>
                <div class="earnings-table-wrap">
                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Activity</th>
                                <th>Status</th>
                                <th class="text-end">Amount</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentRewardActivity)): ?>
                                <tr><td colspan="5" class="rewards-empty-cell">No reward activity yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentRewardActivity as $activityRow): ?>
                                    <?php
                                    $activityType = strtolower((string)($activityRow['type'] ?? ''));
                                    $activityStatus = strtolower((string)($activityRow['status'] ?? ''));
                                    $signedAmount = (float)($activityRow['amount'] ?? 0);
                                    $isCompensatingRemoval = $signedAmount < 0 && in_array($activityStatus, ['cleared', 'paid'], true);
                                    $activityBadgeClass = match ($activityStatus) {
                                        'pending', 'held', 'flagged_review' => 'badge-pending',
                                        'cleared', 'paid' => $isCompensatingRemoval ? 'badge-cancelled' : 'badge-cleared',
                                        'reversed', 'cancelled' => 'badge-cancelled',
                                        default => 'badge-neutral',
                                    };
                                    $activityStatusLabel = match ($activityStatus) {
                                        'flagged_review' => 'Under review',
                                        'cleared' => $isCompensatingRemoval ? 'Removed later' : 'Added to balance',
                                        'paid' => $isCompensatingRemoval ? 'Recovered after payout' : 'Already paid out',
                                        'reversed' => 'Removed later',
                                        'cancelled' => 'Removed before clearing',
                                        default => ucwords(str_replace('_', ' ', $activityStatus)),
                                    };
                                    $activityFileName = \App\Service\EncryptionService::decrypt((string)($activityRow['filename'] ?? ''));
                                    $activityTitle = match ($activityType) {
                                        'download_reward' => $activityFileName !== '' ? ('File reward: ' . $activityFileName) : 'File reward',
                                        'pps_reward' => 'Sale referral reward',
                                        'referral' => 'Referral reward',
                                        'bonus' => 'Bonus reward',
                                        'aggregate_summary' => 'Balance adjustment',
                                        default => ucwords(str_replace('_', ' ', $activityType)),
                                    };
                                    $signedAmount = in_array($activityStatus, ['reversed', 'cancelled'], true)
                                        ? -abs((float)($activityRow['amount'] ?? 0))
                                        : $signedAmount;
                                    $activityNote = trim((string)($activityRow['review_note'] ?? ''));
                                    if ($activityNote === '') {
                                        $activityNote = trim((string)($activityRow['description'] ?? ''));
                                    }
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime((string)($activityRow['created_at'] ?? 'now'))) ?></td>
                                        <td><?= htmlspecialchars($activityTitle) ?></td>
                                        <td><span class="badge <?= $activityBadgeClass ?>"><?= htmlspecialchars($activityStatusLabel) ?></span></td>
                                        <td class="text-end"><strong class="<?= $signedAmount < 0 ? 'rewards-alert' : '' ?>"><?= $signedAmount < 0 ? '- ' : '' ?>$<?= number_format(abs($signedAmount), 4) ?></strong></td>
                                        <td class="small text-muted"><?= htmlspecialchars($activityNote) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Recent earnings history</h2>
                    <p>This view shows which files produced recent cleared reward activity, how much traffic was accepted or rejected, and how much each file earned across the last activity window.</p>
                </div>
                <div class="earnings-table-wrap">
                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Last activity</th>
                                <th>File</th>
                                <th>Cleared</th>
                                <th>Rejected</th>
                                <th>Acceptance</th>
                                <th class="text-end">Total earned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentEarnings)): ?>
                                <tr><td colspan="6" class="rewards-empty-cell">No earnings yet. Once your files start generating eligible traffic under the current reward rules, activity will appear here.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentEarnings as $row): ?>
                                    <?php
                                    $counted = (int)($row['counted_downloads'] ?? $row['total_downloads'] ?? 0);
                                    $rejected = (int)($row['rejected_downloads'] ?? 0);
                                    $resolvedTotal = $counted + $rejected;
                                    $conversion = $resolvedTotal > 0 ? round(($counted / $resolvedTotal) * 100, 1) . '%' : 'n/a';
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($row['last_activity'])) ?></td>
                                        <td><?= htmlspecialchars(\App\Service\EncryptionService::decrypt($row['filename'] ?? 'Unknown File')) ?></td>
                                        <td><?= number_format($counted) ?></td>
                                        <td><?= number_format($rejected) ?></td>
                                        <td><?= htmlspecialchars($conversion) ?></td>
                                        <td class="text-end"><strong>$<?= number_format((float)$row['total_amount'], 4) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Recent country and network performance</h2>
                    <p>See where recent qualifying earnings are coming from so you can spot which country groups and traffic types are driving the strongest current results. Older cleared history may be rolled up into daily summaries instead of staying in this raw breakdown.</p>
                </div>
                <div class="earnings-table-wrap">
                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Country</th>
                                <th>Network</th>
                                <th>Downloads</th>
                                <th class="text-end">Earnings</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($countryTierRows)): ?>
                                <tr><td colspan="4" class="rewards-empty-cell">No country or tier data yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($countryTierRows as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['country_code'] ?: 'Unknown') ?></td>
                                        <td><?= htmlspecialchars($row['network_type'] ?: 'Standard') ?></td>
                                        <td><?= number_format((int)$row['downloads']) ?></td>
                                        <td class="text-end"><strong>$<?= number_format((float)$row['earnings'], 4) ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Why traffic did not count</h2>
                    <p>Use this table to understand reviewed or rejected download activity, including stored rejection reasons when the system recorded them.</p>
                </div>
                <div class="earnings-table-wrap">
                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>File</th>
                                <th>Status</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($downloadExplanations)): ?>
                                <tr><td colspan="4" class="rewards-empty-cell">No rejected or reviewed download explanations yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($downloadExplanations as $row): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                        <td><?= htmlspecialchars(\App\Service\EncryptionService::decrypt($row['filename'] ?? 'Unknown File')) ?></td>
                                        <td><?= htmlspecialchars((string)($row['display_status'] ?? 'Rejected')) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)($row['display_reason'] ?? 'No reason recorded')) ?></strong>
                                            <div class="small text-muted"><?= htmlspecialchars((string)($row['display_reason_detail'] ?? '')) ?></div>
                                            <?php if (!empty($row['display_reason_list']) && count((array)$row['display_reason_list']) > 1): ?>
                                                <div class="small text-muted">Also matched: <?= htmlspecialchars(implode(', ', (array)$row['display_reason_list'])) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Bonus history</h2>
                    <p>Track every bonus offer you have earned, whether it is still waiting on review, already credited into your balance, or rejected after review.</p>
                </div>
                <div class="earnings-table-wrap">
                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Offer</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bonusHistory)): ?>
                                <tr><td colspan="5" class="rewards-empty-cell">No bonus history yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($bonusHistory as $bonusRow): ?>
                                    <?php
                                    $bonusStatus = strtolower((string)($bonusRow['status'] ?? ''));
                                    $bonusBadgeClass = match ($bonusStatus) {
                                        'pending_review' => 'badge-pending',
                                        'credited' => 'badge-cleared',
                                        'reversed' => 'badge-cancelled',
                                        'rejected', 'expired' => 'badge-cancelled',
                                        default => 'badge-neutral',
                                    };
                                    $bonusDateValue = (string)($bonusRow['earned_at'] ?? '');
                                    if (in_array($bonusStatus, ['reversed', 'rejected'], true) && !empty($bonusRow['reviewed_at'])) {
                                        $bonusDateValue = (string)$bonusRow['reviewed_at'];
                                    }
                                    $bonusStatusLabel = match ($bonusStatus) {
                                        'pending_review' => 'Waiting for approval',
                                        'credited' => 'Added to balance',
                                        'reversed' => 'Removed later',
                                        'rejected' => 'Rejected',
                                        'expired' => 'Expired',
                                        default => ucwords(str_replace('_', ' ', (string)$bonusRow['status'])),
                                    };
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($bonusDateValue)) ?></td>
                                        <td><?= htmlspecialchars((string)$bonusRow['public_title']) ?></td>
                                        <td><span class="badge <?= $bonusBadgeClass ?>"><?= htmlspecialchars($bonusStatusLabel) ?></span></td>
                                        <td><strong>$<?= number_format((float)$bonusRow['amount'], 2) ?></strong></td>
                                        <td class="small text-muted"><?= htmlspecialchars((string)($bonusRow['note'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Payout history</h2>
                    <p>Review past payout requests, current status, and any admin note left on the request so you can track what was approved, paid, or denied.</p>
                </div>
                <div class="earnings-table-wrap">
                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Admin note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($withdrawals ?? [])): ?>
                                <tr><td colspan="5" class="rewards-empty-cell">No payout requests yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($withdrawals as $w): ?>
                                    <?php
                                    $withdrawStatus = strtolower((string)($w['status'] ?? ''));
                                    $statusClass = match ($withdrawStatus) {
                                        'pending' => 'badge-pending',
                                        'paid' => 'badge-paid',
                                        'approved' => 'badge-approved',
                                        'cancelled', 'rejected', 'reversed' => 'badge-cancelled',
                                        default => 'badge-neutral',
                                    };
                                    $statusLabel = $withdrawalStatusLabels[$withdrawStatus] ?? ucwords(str_replace('_', ' ', (string)($w['status'] ?? '')));
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($w['created_at'])) ?></td>
                                        <td><strong>$<?= number_format((float)$w['amount'], 2) ?></strong></td>
                                        <td><?= htmlspecialchars(\App\Service\PayoutProcessorService::label((string)($w['method'] ?? ''))) ?></td>
                                        <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                                        <td class="small text-muted"><?= htmlspecialchars(\App\Service\EncryptionService::decrypt($w['admin_note'] ?? '') ?: '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="rewards-glossary">
            <div class="rewards-glossary-title">What these terms mean</div>
            <dl class="rewards-glossary-list">
                <dt>Available balance</dt>
                <dd>Cleared earnings you can withdraw right now.</dd>

                <dt>Pending review</dt>
                <dd>Downloads waiting to be evaluated by fraud and eligibility checks. These have not been counted or rejected yet.</dd>

                <dt>Cleared downloads</dt>
                <dd>Downloads that passed review and have already cleared into earnings.</dd>

                <dt>Rejected downloads</dt>
                <dd>Downloads that were flagged or filtered out and did not count toward earnings.</dd>

                <dt>Acceptance</dt>
                <dd>The percentage of resolved file traffic that turned into cleared qualifying earnings instead of being rejected.</dd>

                <dt>Pending amount</dt>
                <dd>Earnings from downloads still being reviewed. These will move to cleared or cancelled once processed.</dd>

                <dt>Held amount</dt>
                <dd>Earnings temporarily held for manual review before they are released or cancelled.</dd>

                <dt>Cleared amount</dt>
                <dd>Earnings that have been approved and added to your available balance.</dd>

                <dt>Cancelled amount</dt>
                <dd>Earnings that were revoked after review and are no longer payable.</dd>
            </dl>
        </div>
    </div>
</div>

<!-- Withdrawal Modal -->
<div id="withdrawModal" class="rewards-modal">
    <div class="rewards-modal-card">
        <h3 class="rewards-modal-title">Request Payout</h3>
        <p class="rewards-modal-copy">This payout request uses your saved payout processor and saved payout destination. Update them in Settings first if anything needs to change.</p>

        <form id="withdrawForm" method="POST" action="/rewards/withdraw">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group rewards-modal-field">
                <label class="form-label">Amount to Withdraw ($)</label>
                <input type="number" name="amount" step="0.01" min="<?= number_format($minimumWithdrawalAmount, 2, '.', '') ?>" max="<?= $availableBalance ?>" class="form-control" value="<?= $availableBalance ?>" required>
                <small class="text-muted">Available: $<?= number_format($availableBalance, 2) ?></small>
            </div>

            <div class="form-group rewards-modal-field">
                <label class="form-label">Saved Payment Method</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($paymentMethodLabel) ?>" readonly>
            </div>

            <div class="form-group rewards-modal-field--last">
                <label class="form-label"><?= htmlspecialchars($paymentDestinationLabel) ?></label>
                <textarea id="withdrawDetails" class="form-control" rows="3" readonly><?= htmlspecialchars((string)($defaultWithdrawalDetails ?? '')) ?></textarea>
                <small class="text-muted">Need to update this destination? Use <a href="/settings">Settings</a> before submitting the payout request.</small>
            </div>

            <div class="form-group rewards-modal-field">
                <label class="form-label">Current Password</label>
                <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                <small class="text-muted">Required before a payout request is submitted.</small>
            </div>

            <div class="rewards-modal-row">
                <button type="button" class="btn rewards-modal-cancel" id="hideWithdrawModalBtn">Cancel</button>
                <button type="submit" class="btn btn-primary rewards-modal-submit" id="withdrawBtn">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showWithdrawModal() {
        const bal = parseFloat(document.querySelector('input[name="amount"]').max);
        const minWithdrawalAmount = <?= json_encode(number_format($minimumWithdrawalAmount, 2, '.', '')) ?>;
        const withdrawalMethodsAvailable = <?= $withdrawalMethodsAvailable ? 'true' : 'false' ?>;
        const savedPayoutConfigured = <?= $savedPayoutConfigured ? 'true' : 'false' ?>;
        const hasOpenWithdrawal = <?= $hasOpenWithdrawal ? 'true' : 'false' ?>;
        if (!withdrawalMethodsAvailable) {
            alert("Payout requests are temporarily unavailable because no payout processors are enabled right now.");
            return;
        }
        if (!savedPayoutConfigured) {
            alert("Saved payout details are required before requesting a payout. Please update your payout settings first.");
            return;
        }
        if (hasOpenWithdrawal) {
            alert("A payout request is already waiting to be processed. Please wait for it to be approved, paid, or rejected before submitting another one.");
            return;
        }
        if (bal < minWithdrawalAmount) {
            alert("Minimum withdrawal amount is $" + Number(minWithdrawalAmount).toFixed(2));
            return;
        }
        document.getElementById('withdrawModal').style.display = 'flex';
    }

    function hideWithdrawModal() {
        document.getElementById('withdrawModal').style.display = 'none';
    }

    document.getElementById('showWithdrawModalBtn')?.addEventListener('click', showWithdrawModal);
    document.getElementById('showWithdrawModalBtnSecondary')?.addEventListener('click', showWithdrawModal);
    document.getElementById('hideWithdrawModalBtn')?.addEventListener('click', hideWithdrawModal);

    document.getElementById('withdrawForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('withdrawBtn');
        btn.disabled = true;
        btn.innerText = "Processing...";

        fetch(this.action, {
            method: this.method || 'POST',
            body: new FormData(this)
        })
        .then(r => r.json())
        .then(res => {
            alert(res.message);
            if (res.status === 'success') {
                location.reload();
            } else {
                btn.disabled = false;
                btn.innerText = "Submit Request";
            }
        })
        .catch(() => {
            alert("A server error occurred. Please try again.");
            btn.disabled = false;
            btn.innerText = "Submit Request";
        });
    });

    const chartCanvas = document.getElementById('earningsChart');
    if (chartCanvas) {
        const ctx = chartCanvas.getContext('2d');
        const analyticsData = <?= json_encode($analytics) ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: analyticsData.map(d => d.day),
                datasets: [{
                    label: 'Daily Earnings ($)',
                    data: analyticsData.map(d => d.earnings),
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Daily Downloads',
                    data: analyticsData.map(d => d.downloads),
                    borderColor: '#10b981',
                    backgroundColor: 'transparent',
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Earnings ($)' } },
                    y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Downloads' } }
                }
            }
        });
    }
</script>

<?php include __DIR__ . '/footer.php'; ?>
