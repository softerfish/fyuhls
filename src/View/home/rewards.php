<?php 
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls')); 
$title = "My Rewards - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .rewards-toolbar-note {
        font-size: 0.8125rem;
        color: var(--text-muted);
        max-width: 720px;
        line-height: 1.55;
        text-align: right;
    }
    .rewards-toolbar-actions {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        justify-content: flex-end;
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
    @media (max-width: 1200px) {
        .rewards-toolbar-actions {
            justify-content: flex-start;
        }
        .rewards-toolbar-note {
            max-width: none;
            text-align: left;
        }
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

include __DIR__ . '/header.php'; 
?>

<div class="fm-container" style="margin-top: 1rem;">
    <div class="fm-sidebar">
        <div class="sidebar-section">
            <div style="text-align: center; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color);">
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
                <div style="margin-bottom: 0.25rem; font-size: 0.875rem; color: var(--text-color); font-weight: 600;">
                    Current Plan: <span style="color: var(--primary-color);"><?= htmlspecialchars($pkgNameStr) ?></span>
                </div>
                <div style="margin-bottom: <?= $isPaidPlan ? '0.5rem' : '1.25rem' ?>; font-size: 0.75rem; color: var(--text-muted);">
                    <?= htmlspecialchars($expiryStr) ?>
                </div>
                <?php if (!$isPaidPlan): ?>
                    <button class="btn btn-warning" onclick="location.href='/#pricing'" style="width: auto; padding: 0.5rem 1.5rem;">View Plans</button>
                <?php endif; ?>
            </div>
            <h3 style="margin-top: 0;">Account</h3>
            <ul style="list-style: none; padding: 0.5rem 0; margin: 0;">
                <li onclick="location.href='/'">All Files</li>
                <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
                    <li onclick="location.href='/rewards'" class="active">My Rewards</li>
                    <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                        <li onclick="location.href='/affiliate'">Affiliate</li>
                    <?php endif; ?>
                <?php endif; ?>
                <li onclick="location.href='/settings'">Settings</li>
                <li onclick="location.href='/recent'">Recent</li>
                <li onclick="location.href='/shared'">Shared</li>
                <li class="sidebar-trash-item" style="padding: 0; display: flex; justify-content: space-between; align-items: center; min-height: 40px;">
                    <span onclick="location.href='/trash'" style="flex: 1; padding: 0.6rem 0.75rem; display: block;">Trash</span>
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

            <div class="toolbar-right">
                <div class="rewards-toolbar-actions">
                    <span class="rewards-toolbar-note">Track cleared earnings, held activity, payout requests, and the recent reward performance this install is actually crediting.</span>
                    <button class="btn btn-primary" onclick="showWithdrawModal()">Request Payout</button>
                </div>
            </div>
        </div>

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
                <div class="value" style="color: #f59e0b;"><?= number_format($pendingRewards) ?> <small style="font-size: 0.75rem; font-weight: normal; color: var(--text-muted);">downloads</small></div>
            </div>
            <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
                <?php
                $db = \App\Core\Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE referrer_id = ?");
                $stmt->execute([\App\Core\Auth::id()]);
                $referralCount = $stmt->fetchColumn();
                ?>
                <div class="reward-card">
                    <div class="label">Active Referrals</div>
                    <div class="value"><?= $referralCount ?></div>
                </div>
            <?php else: ?>
                <?php $db = \App\Core\Database::getInstance()->getConnection(); ?>
            <?php endif; ?>
        </div>

        <!-- Analytics Chart -->
        <div class="card-header" style="margin-bottom: 1rem; font-weight: 600;">Performance (Last 7 Days)</div>
        <div class="chart-container">
            <canvas id="earningsChart"></canvas>
        </div>

        <?php if (\App\Service\FeatureService::affiliateEnabled()): ?>
            <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem;">
                <h3 style="margin-top: 0; font-size: 1rem;">Your Referral Link</h3>
                <p style="font-size: 0.8125rem; color: var(--text-muted);">Share this link to earn commission from premium sales.</p>
                <div style="display: flex; gap: 0.5rem;">
                    <?php 
                    $user = \App\Core\Auth::user();
                    $refLink = \App\Service\SeoService::trustedBaseUrl() . '/?ref=' . (int) $user['id'];
                    ?>
                    <input type="text" value="<?= htmlspecialchars($refLink) ?>" readonly style="flex: 1; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 6px;">
                    <button class="btn" style="width: auto;" onclick="navigator.clipboard.writeText(this.previousElementSibling.value); alert('Copied!')">Copy</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-header" style="margin-bottom: 1rem; font-weight: 600;">Recent Earnings History - <span style="font-style: italic; font-weight: normal; font-size: 0.875rem;">a 7 day window into your downloads</span></div>
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
                    <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 3rem;">No earnings yet. Once your files start generating eligible traffic under the current reward rules, activity will appear here.</td></tr>
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

        <div class="card-header" style="margin-bottom: 1rem; font-weight: 600; margin-top: 3rem;">Payout History</div>
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
                    <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 3rem;">No payout requests yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($w['created_at'])) ?></td>
                            <td><strong>$<?= number_format($w['amount'], 2) ?></strong></td>
                            <td><?= strtoupper($w['method']) ?></td>
                            <td>
                                <span class="badge" style="background: <?= $w['status'] === 'pending' ? '#fef3c7; color: #92400e;' : ($w['status'] === 'paid' ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;') ?>">
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
<div id="withdrawModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: white; padding: 2.5rem; border-radius: 16px; width: 450px; max-width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
        <h3 style="margin-top: 0; margin-bottom: 0.5rem; font-size: 1.5rem;">Request Payout</h3>
        <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1.5rem;">Withdraw your cleared earnings to your preferred payment method.</p>
        
        <form id="withdrawForm">
            <?= \App\Core\Csrf::field() ?>
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label">Amount to Withdraw ($)</label>
                <input type="number" name="amount" step="0.01" min="1" max="<?= $availableBalance ?>" class="form-control" value="<?= $availableBalance ?>" required>
                <small class="text-muted">Available: $<?= number_format($availableBalance, 2) ?></small>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label">Payment Method</label>
                <select name="method" class="form-control" required onchange="updateDetailsHint(this.value)">
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

            <div class="form-group" style="margin-bottom: 2rem;">
                <label class="form-label" id="detailsLabel">Payment Details</label>
                <textarea name="details" class="form-control" rows="3" placeholder="Enter your PayPal email address..." required></textarea>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" class="btn" onclick="hideWithdrawModal()" style="background: #f1f5f9; width: auto;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="withdrawBtn" style="width: auto;">Submit Request</button>
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

    document.getElementById('withdrawForm').onsubmit = function(e) {
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
    };

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
