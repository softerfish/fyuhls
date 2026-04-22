<?php 
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls')); 
$title = "My Rewards - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .rewards-shell { margin-top: 1rem; }
    .rewards-plan-card { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
    .rewards-plan-current { margin-bottom: 0.25rem; font-size: 0.875rem; color: var(--text-color); font-weight: 600; }
    .rewards-plan-name { color: var(--primary-color); }
    .rewards-plan-expiry { font-size: 0.75rem; color: var(--text-muted); }
    .rewards-plan-limit { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem; }
    .rewards-plan-expiry--tight { margin-bottom: 0.5rem; }
    .rewards-plan-expiry--wide { margin-bottom: 1.25rem; }
    .rewards-plan-button { width: auto; padding: 0.5rem 1.5rem; }
    .rewards-account-title { margin-top: 0; }
    .rewards-nav { list-style: none; padding: 0.5rem 0; margin: 0; }
    .rewards-trash-item { padding: 0; display: flex; justify-content: space-between; align-items: center; min-height: 40px; }
    .rewards-trash-link { flex: 1; padding: 0.6rem 0.75rem; display: block; }
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
    .rewards-modal { display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; }
    .rewards-modal-card { background: white; padding: 2.5rem; border-radius: 16px; width: 450px; max-width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
    .rewards-modal-title { margin-top: 0; margin-bottom: 0.5rem; font-size: 1.5rem; }
    .rewards-modal-copy { color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem; }
    .rewards-modal-field { margin-bottom: 1.5rem; }
    .rewards-modal-field--last { margin-bottom: 2rem; }
    .rewards-modal-row { display: flex; gap: 1rem; justify-content: flex-end; }
    .rewards-modal-cancel, .rewards-modal-submit { width: auto; }
    .rewards-modal-cancel { background: #f1f5f9; }
    @media (max-width: 900px) {
        .rewards-hero-actions {
            grid-template-columns: 1fr;
        }
        .rewards-hero-actions > .btn {
            justify-self: start;
        }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

include __DIR__ . '/header.php'; 
?>

<div class="fm-container rewards-shell">
    <div class="fm-sidebar">
        <div class="sidebar-section">
            <div class="rewards-plan-card">
                <?php 
                $userId = \App\Core\Auth::id();
                $pkgNameStr = 'Free Plan';
                $expiryStr = 'Lifetime Free Account';
                $userPkg = null;
                
                if ($userId) {
                    if (\App\Core\Auth::isAdmin()) {
                        $pkgNameStr = 'Admin';
                    } else {
                        $userPkg = \App\Model\Package::getUserPackage($userId);
                        if ($userPkg) {
                            $pkgNameStr = $userPkg['name'] ?? 'Free Plan';
                            if (!empty($userPkg['premium_expiry'])) {
                                $expiryStr = 'Renews on ' . date('M d, Y', strtotime($userPkg['premium_expiry']));
                            }
                        }
                    }
                }
                $isPaidPlan = (\App\Core\Auth::isAdmin() || strtolower((string)($userPkg['level_type'] ?? 'free')) === 'paid');
                ?>
                <div class="rewards-plan-current">
                    Current Plan: <span class="rewards-plan-name"><?= htmlspecialchars($pkgNameStr) ?></span>
                </div>
                <div class="rewards-plan-expiry <?= $isPaidPlan ? 'rewards-plan-expiry--tight' : 'rewards-plan-expiry--wide' ?>">
                    <?= htmlspecialchars($expiryStr) ?>
                </div>
                <?php if (!empty($dailyDownloadLimitSummary['label']) && array_key_exists('value', $dailyDownloadLimitSummary)): ?>
                    <div class="rewards-plan-limit">
                        <?= htmlspecialchars($dailyDownloadLimitSummary['label']) ?>: <?= htmlspecialchars($dailyDownloadLimitSummary['value']) ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isPaidPlan): ?>
                    <button class="btn btn-warning rewards-plan-button" data-nav-url="/#pricing">View Plans</button>
                <?php endif; ?>
            </div>
            <h3 class="rewards-account-title">Account</h3>
            <ul class="rewards-nav">
                <li data-nav-url="/">All Files</li>
                <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                    <li data-nav-url="/rewards" class="active">My Rewards</li>
                    <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                        <li data-nav-url="/affiliate">Affiliate</li>
                    <?php endif; ?>
                <?php endif; ?>
                <li data-nav-url="/settings">Settings</li>
                <li data-nav-url="/recent">Recent</li>
                <li data-nav-url="/shared">Shared</li>
                <li class="sidebar-trash-item rewards-trash-item">
                    <span data-nav-url="/trash" class="rewards-trash-link">Trash</span>
                </li>
            </ul>
        </div>
    </div>
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
                <button class="btn btn-primary" id="showWithdrawModalBtn" type="button">Request Payout</button>
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
            <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                <div class="reward-card">
                    <div class="label">Earning Referrals</div>
                    <div class="value"><?= $referralCount ?></div>
                </div>
            <?php endif; ?>
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
                    <th class="text-end">Total Earned</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentEarnings)): ?>
                    <tr><td colspan="4" class="rewards-empty-cell">No earnings yet. Once your files start generating eligible traffic under the current reward rules, activity will appear here.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentEarnings as $row): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($row['last_activity'])) ?></td>
                            <td><?= htmlspecialchars(\App\Service\EncryptionService::decrypt($row['filename'] ?? 'Unknown File')) ?></td>
                            <td><?= number_format($row['total_downloads']) ?></td>
                            <td class="text-end"><strong>$<?= number_format($row['total_amount'], 4) ?></strong></td>
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
                            <option value="<?= $m ?>"><?= $methods[$m] ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group rewards-modal-field--last">
                <label class="form-label" id="detailsLabel">Payment Details</label>
                <textarea name="details" class="form-control" rows="3" placeholder="Enter your PayPal email address..." required></textarea>
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
        const textarea = document.querySelector('textarea[name="details"]');
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
