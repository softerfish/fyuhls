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
$paymentMethodLabelMap = [
    'paypal' => 'PayPal',
    'stripe' => 'Stripe / Bank',
    'bitcoin' => 'Bitcoin / Crypto',
    'wire' => 'Bank Wire Transfer',
];
$paymentMethodLabel = $paymentMethodRaw !== '' ? ($paymentMethodLabelMap[$paymentMethodRaw] ?? ucwords(str_replace(['_', '-'], ' ', $paymentMethodRaw))) : 'Not set';
$availableBalance = (float)($availableBalance ?? 0);
$pendingAmount = (float)($amountsByStatus['pending'] ?? 0);
$heldAmount = (float)($amountsByStatus['held'] ?? 0);
$clearedAmount = (float)($amountsByStatus['cleared'] ?? 0);
$cancelledAmount = (float)($amountsByStatus['cancelled'] ?? 0);
$flaggedAmount = (float)($amountsByStatus['flagged_review'] ?? 0);
$reversedAmount = (float)($amountsByStatus['reversed'] ?? 0);
$payoutReady = $availableBalance >= 1;
$payoutReadinessTitle = $payoutReady ? 'Ready for payout' : 'Keep earning';
$payoutReadinessBody = $payoutReady
    ? 'You have enough cleared balance to submit a payout request right now.'
    : 'You need at least $1.00 in cleared balance before requesting a payout.';
$performanceSummary = ((int)($countedDownloads ?? 0) > 0)
    ? 'Use the performance sections below to see what qualified, what was filtered, and where your earnings came from.'
    : 'Once your files start generating qualifying traffic, performance and earnings detail will appear here.';

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
                        <button class="btn btn-primary" id="showWithdrawModalBtn" type="button">Request Payout</button>
                        <a class="btn btn-secondary" href="/settings">Update Payout Settings</a>
                        <a class="btn btn-white" href="/rewards/export.csv">Export CSV</a>
                        <a class="btn btn-white" href="/affiliate">Open Creator Rewards Guide</a>
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
                        <div class="rewards-summary-copy"><?= $paymentMethodRaw !== '' ? 'Keep your saved payout details up to date before you request a payout.' : 'Add payout details before submitting your first payout request.' ?></div>
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
                <div class="rewards-stat-copy">Temporarily waiting on review before moving to cleared or cancelled.</div>
            </div>
            <div class="rewards-stat-card">
                <div class="rewards-stat-label">Pending review</div>
                <div class="rewards-stat-value rewards-alert"><?= number_format((int)($pendingRewards ?? 0)) ?></div>
                <div class="rewards-stat-copy">Downloads still being evaluated by fraud and eligibility checks.</div>
            </div>
            <div class="rewards-stat-card">
                <div class="rewards-stat-label">Counted downloads</div>
                <div class="rewards-stat-value"><?= number_format((int)($countedDownloads ?? 0)) ?></div>
                <div class="rewards-stat-copy">Qualified downloads that earned under the current rules.</div>
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
                    <button class="btn btn-primary" type="button" id="showWithdrawModalBtnSecondary">Request Payout</button>
                    <a class="btn btn-secondary" href="/settings">Manage payout details</a>
                </div>
                <div class="rewards-pill-list">
                    <span class="rewards-pill">Minimum payout request: $1.00</span>
                    <span class="rewards-pill"><?= $paymentMethodRaw !== '' ? 'Saved payout details are on file.' : 'No saved payout details yet.' ?></span>
                    <span class="rewards-pill"><?= $payoutReady ? 'A payout request can be submitted now.' : 'More cleared balance is needed before payout.' ?></span>
                </div>
            </section>

            <section class="rewards-panel">
                <div class="rewards-panel-header">
                    <h2>How to read this page</h2>
                    <p><?= htmlspecialchars($performanceSummary) ?></p>
                </div>
                <div class="rewards-pill-list">
                    <span class="rewards-pill">Pending and held earnings are not withdrawable yet.</span>
                    <span class="rewards-pill">Cleared earnings increase your available balance.</span>
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
                    <p>Use this chart to compare daily qualifying earnings with daily download volume. A widening gap usually means more traffic is being filtered or held for review.</p>
                </div>
                <div class="rewards-chart-frame">
                    <canvas id="earningsChart"></canvas>
                </div>
            </div>
        </section>

        <section class="rewards-section">
            <div class="rewards-table-panel">
                <div class="rewards-section-heading">
                    <h2>Recent earnings history</h2>
                    <p>This view shows which files generated recent qualifying traffic, how much of that traffic counted, and how much each file earned across the last activity window.</p>
                </div>
                <div class="earnings-table-wrap">
                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Last activity</th>
                                <th>File</th>
                                <th>Downloads</th>
                                <th>Rejected</th>
                                <th>Conversion</th>
                                <th class="text-end">Total earned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentEarnings)): ?>
                                <tr><td colspan="6" class="rewards-empty-cell">No earnings yet. Once your files start generating eligible traffic under the current reward rules, activity will appear here.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentEarnings as $row): ?>
                                    <?php
                                    $fileDownloads = max(0, (int)($row['file_downloads'] ?? 0));
                                    $counted = (int)($row['counted_downloads'] ?? $row['total_downloads'] ?? 0);
                                    $rejected = (int)($row['rejected_downloads'] ?? 0);
                                    $conversion = $fileDownloads > 0 ? round(($counted / $fileDownloads) * 100, 1) . '%' : 'n/a';
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
                    <h2>Country and network performance</h2>
                    <p>See where qualifying earnings are coming from so you can spot which country groups and traffic types are driving the strongest results.</p>
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
                                    <?php
                                    $reasons = json_decode((string)($row['risk_reasons_json'] ?? ''), true);
                                    $reasonText = is_array($reasons) && !empty($reasons)
                                        ? implode(', ', array_map('strval', $reasons))
                                        : 'No rejection reason was recorded for this download.';
                                    $statusText = (($row['risk_level'] ?? '') === 'not_counted')
                                        ? 'not_counted'
                                        : (string)($row['status'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                        <td><?= htmlspecialchars(\App\Service\EncryptionService::decrypt($row['filename'] ?? 'Unknown File')) ?></td>
                                        <td><?= htmlspecialchars($statusText) ?></td>
                                        <td><?= htmlspecialchars($reasonText) ?></td>
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
                                        'cancelled', 'denied', 'reversed' => 'badge-cancelled',
                                        default => 'badge-neutral',
                                    };
                                    ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($w['created_at'])) ?></td>
                                        <td><strong>$<?= number_format((float)$w['amount'], 2) ?></strong></td>
                                        <td><?= strtoupper((string)$w['method']) ?></td>
                                        <td><span class="badge <?= $statusClass ?>"><?= strtoupper((string)$w['status']) ?></span></td>
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

                <dt>Counted downloads</dt>
                <dd>Downloads that passed all checks and earned money under the current reward rules.</dd>

                <dt>Rejected downloads</dt>
                <dd>Downloads that were flagged or filtered out and did not count toward earnings.</dd>

                <dt>Conversion</dt>
                <dd>The percentage of a file&apos;s total downloads that were actually counted as qualifying.</dd>

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
        <p class="rewards-modal-copy">Withdraw your cleared earnings to your preferred payment method.</p>

        <form id="withdrawForm">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group rewards-modal-field">
                <label class="form-label">Amount to Withdraw ($)</label>
                <input type="number" name="amount" step="0.01" min="1" max="<?= $availableBalance ?>" class="form-control" value="<?= $availableBalance ?>" required>
                <small class="text-muted">Available: $<?= number_format($availableBalance, 2) ?></small>
            </div>

            <div class="form-group rewards-modal-field">
                <label class="form-label">Payment Method</label>
                <select name="method" id="withdrawMethod" class="form-control" required>
                    <?php
                    $supportedMethods = array_filter(array_map('trim', explode(',', \App\Model\Setting::get('supported_withdrawal_methods', 'paypal,bitcoin', 'rewards'))));
                    $methods = [
                        'paypal' => 'PayPal',
                        'stripe' => 'Stripe / Bank',
                        'bitcoin' => 'Bitcoin / Crypto',
                        'wire' => 'Bank Wire Transfer'
                    ];
                    ?>
                    <?php foreach ($supportedMethods as $m): ?>
                        <?php if (isset($methods[$m])): ?>
                            <option value="<?= $m ?>" <?= (($defaultWithdrawalMethod ?? '') === $m) ? 'selected' : '' ?>><?= $methods[$m] ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group rewards-modal-field--last">
                <label class="form-label" id="detailsLabel">Payment Details</label>
                <textarea name="details" id="withdrawDetails" class="form-control" rows="3" placeholder="Enter your PayPal email address..." required><?= htmlspecialchars((string)($defaultWithdrawalDetails ?? '')) ?></textarea>
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
        if (bal < 1) {
            alert("Minimum withdrawal amount is $1.00");
            return;
        }
        document.getElementById('withdrawModal').style.display = 'flex';
    }

    function hideWithdrawModal() {
        document.getElementById('withdrawModal').style.display = 'none';
    }

    function updateDetailsHint(method) {
        const textarea = document.getElementById('withdrawDetails');
        switch(method) {
            case 'paypal':
                textarea.placeholder = "Enter your PayPal email address...";
                break;
            case 'bitcoin':
                textarea.placeholder = "Enter your Bitcoin wallet address (BTC)...";
                break;
            case 'stripe':
                textarea.placeholder = "Enter your Bank Account / IBAN or Stripe email...";
                break;
            case 'wire':
                textarea.placeholder = "Enter full SWIFT/BIC and IBAN details...";
                break;
        }
    }

    document.getElementById('showWithdrawModalBtn')?.addEventListener('click', showWithdrawModal);
    document.getElementById('showWithdrawModalBtnSecondary')?.addEventListener('click', showWithdrawModal);
    document.getElementById('hideWithdrawModalBtn')?.addEventListener('click', hideWithdrawModal);
    document.getElementById('withdrawMethod')?.addEventListener('change', function(event) {
        updateDetailsHint(event.target.value);
    });
    updateDetailsHint(document.getElementById('withdrawMethod')?.value || 'paypal');

    document.getElementById('withdrawForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('withdrawBtn');
        btn.disabled = true;
        btn.innerText = "Processing...";

        fetch('/rewards/withdraw', {
            method: 'POST',
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
