<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Payments - {$siteName}";
$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .payments-shell { margin-top: 1rem; }
    .payments-toolbar-note { font-size: 0.8125rem; color: var(--text-muted); line-height: 1.55; }
    .payments-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .payments-summary-card,
    .payments-panel {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 12px;
    }
    .payments-summary-card { padding: 1.25rem; }
    .payments-summary-label { color: var(--text-muted); font-size: 0.8125rem; margin-bottom: 0.4rem; }
    .payments-summary-value { font-size: 1.6rem; font-weight: 800; color: var(--text-color); }
    .payments-section { margin-bottom: 2rem; }
    .payments-panel-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
    }
    .payments-panel-title { margin: 0; font-size: 1rem; }
    .payments-panel-copy { color: var(--text-muted); font-size: 0.8125rem; }
    .payments-table {
        width: 100%;
        border-collapse: collapse;
    }
    .payments-table th {
        text-align: left;
        padding: 0.9rem 1.25rem;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-muted);
        background: #f8fafc;
        border-bottom: 1px solid var(--border-color);
    }
    .payments-table td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        vertical-align: top;
        font-size: 0.875rem;
    }
    .payments-table tr:last-child td { border-bottom: 0; }
    .payments-empty { padding: 2rem 1.25rem; color: var(--text-muted); text-align: center; }
    .payments-status {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.55rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .payments-status--completed,
    .payments-status--active { background: #dcfce7; color: #166534; }
    .payments-status--pending { background: #fef3c7; color: #92400e; }
    .payments-status--failed,
    .payments-status--denied,
    .payments-status--cancelled,
    .payments-status--refunded,
    .payments-status--expired { background: #fee2e2; color: #b91c1c; }
    .payments-meta { color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem; }
    .payments-reference {
        font-family: monospace;
        font-size: 0.8rem;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 0.35rem 0.5rem;
        display: inline-block;
    }
    .payments-current-plan {
        margin-bottom: 2rem;
        padding: 1.25rem;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 12px;
    }
    .payments-current-plan-title { margin: 0 0 0.35rem; font-size: 1rem; }
    .payments-current-plan-copy { margin: 0; color: var(--text-muted); font-size: 0.875rem; }
    @media (max-width: 900px) {
        .payments-table thead { display: none; }
        .payments-table,
        .payments-table tbody,
        .payments-table tr,
        .payments-table td { display: block; width: 100%; }
        .payments-table tr { border-bottom: 1px solid var(--border-color); }
        .payments-table td {
            padding: 0.7rem 1rem;
            border-bottom: 0;
        }
        .payments-table td::before {
            content: attr(data-label);
            display: block;
            font-size: 0.72rem;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            font-weight: 700;
        }
    }
</style>';

include __DIR__ . '/header.php';
include __DIR__ . '/partials/account_sidebar_styles.php';

$transactions = is_array($transactions ?? null) ? $transactions : [];
$subscriptions = is_array($subscriptions ?? null) ? $subscriptions : [];
$summary = is_array($summary ?? null) ? $summary : ['transaction_count' => 0, 'completed_total' => 0, 'refunded_total' => 0];
$currentPackage = is_array($currentPackage ?? null) ? $currentPackage : null;
$currentUser = is_array($currentUser ?? null) ? $currentUser : null;

$formatStatusClass = static function (string $status): string {
    $status = strtolower(trim($status));
    return match ($status) {
        'completed', 'active' => 'payments-status payments-status--completed',
        'pending' => 'payments-status payments-status--pending',
        'failed', 'denied', 'cancelled', 'refunded', 'expired' => 'payments-status payments-status--failed',
        default => 'payments-status',
    };
};

$formatMoney = static fn($amount, $currency = 'USD') => strtoupper((string)$currency) . ' ' . number_format((float)$amount, 2);
?>

<div class="fm-container payments-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>

    <div class="fm-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Payments & Billing</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Payments</span>
                </div>
            </div>
            <div class="toolbar-right">
                <div class="toolbar-controls">
                    <span class="payments-toolbar-note">Review completed purchases, pending transactions, refunds, and the subscription history attached to your account.</span>
                </div>
            </div>
        </div>

        <div class="payments-summary-grid">
            <div class="payments-summary-card">
                <div class="payments-summary-label">Current Package</div>
                <div class="payments-summary-value"><?= htmlspecialchars((string)($currentPackage['name'] ?? 'Free Plan')) ?></div>
            </div>
            <div class="payments-summary-card">
                <div class="payments-summary-label">Completed Purchases</div>
                <div class="payments-summary-value"><?= htmlspecialchars($formatMoney($summary['completed_total'] ?? 0, 'USD')) ?></div>
            </div>
            <div class="payments-summary-card">
                <div class="payments-summary-label">Refunded Total</div>
                <div class="payments-summary-value"><?= htmlspecialchars($formatMoney($summary['refunded_total'] ?? 0, 'USD')) ?></div>
            </div>
            <div class="payments-summary-card">
                <div class="payments-summary-label">Transactions</div>
                <div class="payments-summary-value"><?= number_format((int)($summary['transaction_count'] ?? 0)) ?></div>
            </div>
        </div>

        <div class="payments-current-plan">
            <h3 class="payments-current-plan-title">Account billing status</h3>
            <p class="payments-current-plan-copy">
                <?php if (!empty($currentUser['premium_expiry'])): ?>
                    Your current premium access is active until <?= htmlspecialchars(date('M d, Y', strtotime((string)$currentUser['premium_expiry']))) ?>.
                <?php else: ?>
                    Your account is currently on the free plan. Any successful upgrade or renewal will appear in the tables below.
                <?php endif; ?>
            </p>
        </div>

        <section class="payments-section payments-panel">
            <div class="payments-panel-header">
                <div>
                    <h3 class="payments-panel-title">Purchase History</h3>
                    <div class="payments-panel-copy">Every package transaction the app has recorded for this account.</div>
                </div>
            </div>

            <?php if ($transactions === []): ?>
                <div class="payments-empty">No payment transactions have been recorded for this account yet.</div>
            <?php else: ?>
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Package</th>
                            <th>Gateway</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php $transactionStatus = strtolower((string)($transaction['status'] ?? 'pending')); ?>
                        <tr>
                            <td data-label="Date">
                                <?= htmlspecialchars(date('M d, Y g:i A', strtotime((string)$transaction['created_at']))) ?>
                            </td>
                            <td data-label="Package">
                                <strong><?= htmlspecialchars((string)($transaction['package_name'] ?? 'Package')) ?></strong>
                            </td>
                            <td data-label="Gateway">
                                <?= htmlspecialchars(strtoupper((string)($transaction['gateway'] ?? ''))) ?>
                            </td>
                            <td data-label="Amount">
                                <?= htmlspecialchars($formatMoney($transaction['amount'] ?? 0, (string)($transaction['currency'] ?? 'USD'))) ?>
                            </td>
                            <td data-label="Status">
                                <span class="<?= $formatStatusClass($transactionStatus) ?>"><?= htmlspecialchars($transactionStatus) ?></span>
                            </td>
                            <td data-label="Reference">
                                <span class="payments-reference"><?= htmlspecialchars((string)($transaction['gateway_reference'] ?? '')) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="payments-section payments-panel">
            <div class="payments-panel-header">
                <div>
                    <h3 class="payments-panel-title">Subscription History</h3>
                    <div class="payments-panel-copy">Premium access records created from successful purchases and later status changes.</div>
                </div>
            </div>

            <?php if ($subscriptions === []): ?>
                <div class="payments-empty">No subscription records have been created for this account yet.</div>
            <?php else: ?>
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th>Started</th>
                            <th>Package</th>
                            <th>Status</th>
                            <th>Billing</th>
                            <th>Expires</th>
                            <th>Gateway</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($subscriptions as $subscription): ?>
                        <?php $subscriptionStatus = strtolower((string)($subscription['status'] ?? 'pending')); ?>
                        <tr>
                            <td data-label="Started">
                                <?= htmlspecialchars(date('M d, Y g:i A', strtotime((string)$subscription['created_at']))) ?>
                            </td>
                            <td data-label="Package">
                                <strong><?= htmlspecialchars((string)($subscription['package_name'] ?? 'Package')) ?></strong>
                                <div class="payments-meta"><?= htmlspecialchars($formatMoney($subscription['amount'] ?? 0, (string)($subscription['currency'] ?? 'USD'))) ?></div>
                            </td>
                            <td data-label="Status">
                                <span class="<?= $formatStatusClass($subscriptionStatus) ?>"><?= htmlspecialchars($subscriptionStatus) ?></span>
                            </td>
                            <td data-label="Billing">
                                <?= htmlspecialchars(ucfirst((string)($subscription['billing_period'] ?? 'monthly'))) ?>
                            </td>
                            <td data-label="Expires">
                                <?= htmlspecialchars(date('M d, Y g:i A', strtotime((string)$subscription['expires_at']))) ?>
                            </td>
                            <td data-label="Gateway">
                                <?= htmlspecialchars(strtoupper((string)($subscription['gateway'] ?? ''))) ?>
                                <div class="payments-meta"><?= htmlspecialchars((string)($subscription['gateway_reference'] ?? '')) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
