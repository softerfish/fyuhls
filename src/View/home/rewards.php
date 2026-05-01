<?php 
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls')); 
$title = "My Rewards - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .rewards-shell { margin-top: 1rem; }
    .rewards-toolbar-note {
        font-size: 0.8125rem;
        color: var(--text-muted);
        line-height: 1.55;
    }
    .rewards-hero-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    .rewards-hero-actions {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 1rem;
        align-items: center;
    }
    .rewards-hero-actions > .btn {
        white-space: nowrap;
        justify-self: end;
    }
    .rewards-hero-buttons { display: flex; justify-content: flex-end; gap: 0.5rem; flex-wrap: wrap; }
    .rewards-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
    }
    .reward-card {
        background: white;
        padding: 1.5rem;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        text-align: center;
    }
    .reward-card .label { color: var(--text-muted); font-size: 0.875rem; margin-bottom: 0.5rem; }
    .reward-card .value { font-size: 1.75rem; font-weight: 800; color: var(--primary-color); }
    
    .earnings-table {
        width: 100%;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        border-collapse: collapse;
        overflow: hidden;
    }
    .earnings-table th { background: #f8fafc; text-align: left; padding: 1rem; border-bottom: 1px solid var(--border-color); font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); }
    .earnings-table td { padding: 1rem; border-bottom: 1px solid var(--border-color); font-size: 0.875rem; }
    
    .badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-paid { background: #dcfce7; color: #166534; }

    .chart-container {
        background: white;
        padding: 1.5rem;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        margin-bottom: 2.5rem;
        height: 300px;
    }
    .rewards-balance-warn { color: #f59e0b; }
    .rewards-balance-meta { font-size: 0.75rem; font-weight: normal; color: var(--text-muted); }
    .rewards-section-header { margin-bottom: 1rem; font-weight: 600; }
    .rewards-section-header--spaced { margin-top: 3rem; }
    .rewards-section-subtle { font-style: italic; font-weight: normal; font-size: 0.875rem; }
    .rewards-referral-box { background: #f8fafc; border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; }
    .rewards-referral-title { margin-top: 0; font-size: 1rem; }
    .rewards-referral-copy { font-size: 0.8125rem; color: var(--text-muted); }
    .rewards-referral-row { display: flex; gap: 0.5rem; }
    .rewards-referral-input { flex: 1; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 6px; }
    .rewards-copy-btn { width: auto; }
    .rewards-empty-cell { text-align: center; color: var(--text-muted); padding: 3rem; }
    .rewards-mini-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .rewards-mini-card { background: #fff; border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem; }
    .rewards-mini-label { color: var(--text-muted); font-size: 0.78rem; margin-bottom: 0.35rem; }
    .rewards-mini-value { font-weight: 800; font-size: 1.15rem; color: var(--text-color); }
    .rewards-export-link { justify-self: end; white-space: nowrap; }
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
    @media (max-width: 900px) {
        .rewards-hero-actions {
            grid-template-columns: 1fr;
        }
        .rewards-hero-actions > .btn {
            justify-self: start;
        }
    }
    .rewards-glossary {
        margin-top: 3rem;
        padding: 1.5rem;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 12px;
    }
    .rewards-glossary-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .rewards-glossary-list {
        margin: 0;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 0.25rem 2rem;
    }
    .rewards-glossary-list dt {
        font-weight: 600;
        font-size: 0.8125rem;
        color: var(--text-color);
        margin-top: 0.75rem;
    }
    .rewards-glossary-list dd {
        margin: 0;
        font-size: 0.8125rem;
        color: var(--text-muted);
        line-height: 1.5;
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

include __DIR__ . '/header.php'; 
include __DIR__ . '/partials/account_sidebar_styles.php';
?>

<div class="fm-container rewards-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>
    <div class="fm-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Rewards & Earnings</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Rewards</span>
                </div>
            </div>
        </div>

        <div class="rewards-hero-card">
            <div class="rewards-hero-actions">
                <span class="rewards-toolbar-note">Track cleared earnings, held activity, payout requests, and the recent reward performance this install is actually crediting.</span>
                <div class="rewards-hero-buttons">
                    <a class="btn btn-white rewards-export-link" href="/rewards/export.csv">Export CSV</a>
                    <button class="btn btn-primary" id="showWithdrawModalBtn" type="button">Request Payout</button>
                </div>
            </div>
        </div>
        <?php $db = \App\Core\Database::getInstance()->getConnection(); ?>

        <div class="rewards-stats">
            <div class="reward-card">
                <div class="label">Available Balance</div>
                <div class="value" id="availableBalanceDisplay">$<?= number_format($availableBalance, 2) ?></div>
            </div>
            <div class="reward-card">
                <div class="label">Total Paid Out</div>
                <div class="value">$<?= number_format($totalPaid, 2) ?></div>
            </div>
            <div class="reward-card">
                <div class="label">Pending Processing</div>
                <div class="value rewards-balance-warn"><?= number_format($pendingRewards) ?> <small class="rewards-balance-meta">downloads</small></div>
            </div>
            <div class="reward-card">
                <div class="label">Counted Downloads</div>
                <div class="value"><?= number_format($countedDownloads ?? 0) ?></div>
            </div>
            <div class="reward-card">
                <div class="label">Rejected Downloads</div>
                <div class="value rewards-balance-warn"><?= number_format($rejectedDownloads ?? 0) ?></div>
            </div>
            <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                <div class="reward-card">
                    <div class="label">Earning Referrals</div>
                    <div class="value"><?= $referralCount ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php $amountsByStatus = is_array($amountsByStatus ?? null) ? $amountsByStatus : []; ?>
        <div class="rewards-mini-grid">
            <div class="rewards-mini-card"><div class="rewards-mini-label">Pending Amount</div><div class="rewards-mini-value">$<?= number_format((float)($amountsByStatus['pending'] ?? 0), 4) ?></div></div>
            <div class="rewards-mini-card"><div class="rewards-mini-label">Held Amount</div><div class="rewards-mini-value">$<?= number_format((float)($amountsByStatus['held'] ?? 0), 4) ?></div></div>
            <div class="rewards-mini-card"><div class="rewards-mini-label">Cleared Amount</div><div class="rewards-mini-value">$<?= number_format((float)($amountsByStatus['cleared'] ?? 0), 4) ?></div></div>
            <div class="rewards-mini-card"><div class="rewards-mini-label">Cancelled Amount</div><div class="rewards-mini-value">$<?= number_format((float)($amountsByStatus['cancelled'] ?? 0), 4) ?></div></div>
        </div>

        <!-- Analytics Chart -->
        <div class="card-header rewards-section-header">Performance (Last 7 Days)</div>
        <div class="chart-container">
            <canvas id="earningsChart"></canvas>
        </div>

        <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
            <div class="rewards-referral-box">
                <h3 class="rewards-referral-title">Your Referral Link</h3>
                <p class="rewards-referral-copy">Share this link to attribute signups to your account. When those referred users later earn under PPD, PPS, or Hybrid, your referral commission follows the live affiliate settings for this install.</p>
                <div class="rewards-referral-row">
                    <?php 
                    $user = \App\Core\Auth::user();
                    $refCode = trim((string)($user['public_id'] ?? ''));
                    $refLink = $refCode !== ''
                        ? \App\Service\SeoService::trustedBaseUrl() . '/?ref=' . rawurlencode($refCode)
                        : '';
                    ?>
                    <input type="text" value="<?= htmlspecialchars($refLink !== '' ? $refLink : 'Referral link unavailable. Please contact support if this persists.') ?>" readonly class="rewards-referral-input">
                    <button class="btn rewards-copy-btn" data-copy-previous data-copy-success="Copied!" <?= $refLink === '' ? 'disabled' : '' ?>>Copy</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-header rewards-section-header">Recent Earnings History - <span class="rewards-section-subtle">a 7 day window into your downloads</span></div>
        <table class="earnings-table">
            <thead>
                <tr>
                    <th>Last Activity</th>
                    <th>File</th>
                    <th>Downloads</th>
                    <th>Rejected</th>
                    <th>Conversion</th>
                    <th class="text-end">Total Earned</th>
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
                            <td class="text-end"><strong>$<?= number_format($row['total_amount'], 4) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="card-header rewards-section-header rewards-section-header--spaced">Country / Tier Performance</div>
        <table class="earnings-table">
            <thead><tr><th>Country</th><th>Network</th><th>Downloads</th><th class="text-end">Earnings</th></tr></thead>
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

        <div class="card-header rewards-section-header rewards-section-header--spaced">Why Was This Not Counted?</div>
        <table class="earnings-table">
            <thead><tr><th>Date</th><th>File</th><th>Status</th><th>Reason</th></tr></thead>
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

        <div class="card-header rewards-section-header rewards-section-header--spaced">Payout History</div>
        <table class="earnings-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Admin Note</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmtW = $db->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC");
                $stmtW->execute([\App\Core\Auth::id()]);
                $withdrawals = $stmtW->fetchAll();
                
                if (empty($withdrawals)): ?>
                    <tr><td colspan="5" class="rewards-empty-cell">No payout requests yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($w['created_at'])) ?></td>
                            <td><strong>$<?= number_format($w['amount'], 2) ?></strong></td>
                            <td><?= strtoupper($w['method']) ?></td>
                            <td>
                                <span class="badge <?= $w['status'] === 'pending' ? 'badge-pending' : ($w['status'] === 'paid' ? 'badge-paid' : 'badge-danger') ?>">
                                    <?= strtoupper($w['status']) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars(\App\Service\EncryptionService::decrypt($w['admin_note'] ?? '') ?: '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="rewards-glossary">
            <div class="rewards-glossary-title">What do these terms mean?</div>
            <dl class="rewards-glossary-list">
                <dt>Available Balance</dt>
                <dd>Cleared earnings you can withdraw right now.</dd>

                <dt>Pending Processing</dt>
                <dd>Downloads waiting to be evaluated by the fraud and eligibility checks. These haven't been counted or rejected yet.</dd>

                <dt>Counted Downloads</dt>
                <dd>Downloads that passed all checks and earned you money.</dd>

                <dt>Rejected Downloads</dt>
                <dd>Downloads that were flagged or filtered out (duplicates, suspicious traffic, self-downloads, etc.) and did not count toward earnings.</dd>

                <dt>Conversion</dt>
                <dd>The percentage of a file's total downloads that were actually counted as qualifying. Higher conversion means more of your traffic is earning.</dd>

                <dt>Pending Amount</dt>
                <dd>Earnings from downloads still being reviewed. These will move to cleared or cancelled once processed.</dd>

                <dt>Held Amount</dt>
                <dd>Earnings temporarily held for manual review before being released.</dd>

                <dt>Cleared Amount</dt>
                <dd>Earnings that have been approved and added to your available balance.</dd>

                <dt>Cancelled Amount</dt>
                <dd>Earnings that were revoked after review (fraud, policy violation, etc.).</dd>
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
        const label = document.getElementById('detailsLabel');
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
        .catch(err => {
            alert("A server error occurred. Please try again.");
            btn.disabled = false;
            btn.innerText = "Submit Request";
        });
    });

    // Chart initialization
    const ctx = document.getElementById('earningsChart').getContext('2d');
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
</script>

<?php include __DIR__ . '/footer.php'; ?>
