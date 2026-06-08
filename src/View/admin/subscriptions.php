<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';
$csrfToken = \App\Core\Csrf::generate();
renderAdminPageHeader('Premium Subscriptions', '', '<a href="/admin/subscription/create" class="btn btn-primary">Add Subscription</a>');
?>

<?php renderAdminCardStart('Managed Subscriptions (Payments & Status)'); ?>
        <?php if (empty($subscriptions)): ?>
            <p class="subscriptions-empty-state">No subscriptions found in the system yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Package</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Gateway</th>
                        <th>Expires At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subscriptions as $sub): ?>
                        <tr>
                            <td><?= date('Y-m-d', strtotime($sub['created_at'])) ?></td>
                            <td><strong><?= htmlspecialchars($sub['username']) ?></strong></td>
                            <td><?= htmlspecialchars($sub['package_name']) ?></td>
                            <td>
                                <?= $sub['amount'] ?> <?= $sub['currency'] ?>
                                <?php if (!empty($sub['coupon_code']) && (float)($sub['discount_amount'] ?? 0) > 0): ?>
                                    <div class="subscriptions-meta">Coupon <?= htmlspecialchars((string)$sub['coupon_code']) ?> saved <?= number_format((float)$sub['discount_amount'], 2) ?> <?= htmlspecialchars((string)$sub['currency']) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php $statusClass = $sub['status'] === 'active' ? 'subscriptions-status-active' : 'subscriptions-status-inactive'; ?>
                            <td><span class="subscriptions-status-badge badge <?= $statusClass ?>"><?= strtoupper($sub['status']) ?></span></td>
                            <td><?= strtoupper($sub['gateway']) ?></td>
                            <td>
                                <?= date('Y-m-d', strtotime($sub['expires_at'])) ?>
                                <div class="subscriptions-meta"><?= htmlspecialchars(\App\Service\PaymentService::formatTermLabel((int)($sub['term_days'] ?? 30))) ?> &middot; <?= !empty($sub['auto_renew']) ? 'Auto-renew on' : 'Auto-renew off' ?></div>
                                <?php if (($sub['gateway_sync']['status'] ?? '') === 'failed'): ?>
                                    <div class="subscriptions-sync subscriptions-sync--failed">Remote gateway sync failed. Billing may need manual review.</div>
                                <?php elseif (in_array((string)($sub['gateway_sync']['status'] ?? ''), ['pending', 'processing'], true)): ?>
                                    <div class="subscriptions-sync subscriptions-sync--pending">Remote gateway sync is still pending.</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((string)($sub['gateway'] ?? '') === 'manual'): ?>
                                    <form method="POST" action="/admin/subscription/update-status" class="subscriptions-action-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="subscription_id" value="<?= (int)$sub['id'] ?>">
                                        <label class="subscriptions-action-label" for="subscription-status-<?= (int)$sub['id'] ?>">Status</label>
                                        <select
                                            id="subscription-status-<?= (int)$sub['id'] ?>"
                                            name="status"
                                            class="form-select form-select-sm subscriptions-action-select"
                                        >
                                            <?php foreach (['active' => 'Active', 'pending' => 'Pending', 'cancelled' => 'Cancelled', 'expired' => 'Expired'] as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= ((string)($sub['status'] ?? '') === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea
                                            name="admin_note"
                                            class="form-control form-control-sm subscriptions-action-note"
                                            rows="2"
                                            placeholder="Why are you changing this manual subscription?"
                                            required
                                        ></textarea>
                                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">Save Status</button>
                                    </form>
                                    <div class="subscriptions-meta">Manual subscriptions can be cancelled, expired, or reactivated here. Fyuhls will resync the user package immediately.</div>
                                <?php else: ?>
                                    <span class="subscriptions-meta">Review in billing history only</span>
                                    <?php if (($sub['gateway_sync']['status'] ?? '') === 'failed' && !empty($sub['gateway_sync']['last_error'])): ?>
                                        <div class="subscriptions-sync-detail"><?= htmlspecialchars((string)$sub['gateway_sync']['last_error']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
<?php renderAdminCardEnd(); ?>

<style>
.subscriptions-empty-state{text-align:center;color:#64748b;padding:2rem}
.subscriptions-status-badge{padding:.25rem .5rem;border-radius:4px;font-size:.75rem}
.subscriptions-status-active{background:#dcfce7;color:#166534}
.subscriptions-status-inactive{background:#fee2e2;color:#991b1b}
.subscriptions-meta{font-size:.75rem;color:#166534;margin-top:.25rem}
.subscriptions-sync{font-size:.75rem;margin-top:.4rem;padding:.35rem .45rem;border-radius:6px}
.subscriptions-sync--failed{background:#fef2f2;color:#991b1b}
.subscriptions-sync--pending{background:#fffbeb;color:#92400e}
.subscriptions-sync-detail{font-size:.75rem;color:#991b1b;margin-top:.35rem}
.subscriptions-action-form{display:grid;gap:.45rem}
.subscriptions-action-label{font-size:.75rem;font-weight:700;color:#334155}
.subscriptions-action-select,.subscriptions-action-note{font-size:.85rem}
</style>

<?php include 'footer.php'; ?>
