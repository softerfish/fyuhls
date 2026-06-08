<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$billingOptions = is_array($billingOptions ?? null) ? $billingOptions : [];
$selectedBillingOption = is_array($selectedBillingOption ?? null) ? $selectedBillingOption : ($billingOptions[0] ?? []);
$storageBytes = (float)($package['max_storage_bytes'] ?? 0);
$storageSummary = $storageBytes > 0
    ? round($storageBytes / 1024 / 1024 / 1024, 0) . 'GB Storage'
    : 'Unlimited Storage';
$termDays = max(1, (int)($selectedBillingOption['term_days'] ?? ($termDays ?? 30)));
$termLabel = (string)($termLabel ?? \App\Service\PaymentService::formatTermLabel($termDays));
$renewalEnabled = !empty($renewalEnabled);
$autoRenewSelected = isset($autoRenewSelected) ? (bool)$autoRenewSelected : ($renewalEnabled && empty($zeroDollarCoupon));
$autoRenewAvailable = $renewalEnabled && (!empty($stripeEnabled) || !empty($paypalEnabled)) && empty($zeroDollarCoupon);
$packagePrice = (float)($selectedBillingOption['price'] ?? ($package['price'] ?? 0));
$originalAmount = (float)($couponPreview['original_amount'] ?? $packagePrice);
$firstChargeAmount = (float)($couponPreview['final_amount'] ?? $packagePrice);
$couponData = is_array($couponPreview['coupon'] ?? null) ? $couponPreview['coupon'] : null;
$packageFeatures = [];
$packageFeatures[] = $storageSummary;
$packageFeatures[] = !empty($package['allow_direct_links']) ? 'Direct Links' : 'Protected Download Pages';
$packageFeatures[] = !empty($package['show_ads']) ? 'Download Pages May Show Ads' : 'No Download-Page Ads';
if (\App\Service\FeatureService::rewardsEnabled() && !empty($package['ppd_enabled'])) {
    $packageFeatures[] = 'PPD Rewards';
}
if (!empty($package['allow_remote_upload'])) {
    $packageFeatures[] = 'Remote URL Upload';
}
$packageFeatureSummary = implode(', ', $packageFeatures);
$recurringPrice = $packagePrice;
$recurringMessage = 'Renews at the standard package price after the current term.';

if ($couponData && !empty($couponPreview['valid'])) {
    $discountType = (string)($couponData['discount_type'] ?? 'amount');
    if ($discountType === 'percent') {
        $percent = max(0.0, (float)($couponData['discount_value'] ?? 0));
        $discount = round($packagePrice * ($percent / 100), 2);
        $cap = (float)($couponData['percent_cap_amount'] ?? 0);
        if ($cap > 0) {
            $discount = min($discount, $cap);
        }
        $recurringPrice = max(0.0, round($packagePrice - $discount, 2));
    } else {
        $recurringPrice = max(0.0, round($packagePrice - (float)($couponData['discount_value'] ?? 0), 2));
    }

    $durationType = (string)($couponData['duration_type'] ?? 'once');
    if ($durationType === 'forever') {
        $recurringMessage = 'This coupon continues on qualifying renewals while the subscription stays active.';
    } elseif ($durationType === 'cycles') {
        $cycleCount = max(1, (int)($couponData['duration_cycles'] ?? 1));
        $recurringMessage = $cycleCount === 1
            ? 'This coupon continues for the next qualifying renewal cycle.'
            : ('This coupon continues for the first ' . $cycleCount . ' qualifying renewal cycles.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?= htmlspecialchars($siteName) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .checkout-container {
            max-width: 900px;
            margin: 4rem auto;
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 2rem;
            padding: 0 2rem;
        }
        .order-summary {
            background: #f8fafc;
            padding: 2rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        .payment-methods {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .method-card {
            border: 2px solid var(--border-color);
            border-radius: 10px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .method-card:hover,
        .method-card.active {
            border-color: var(--primary-color);
            background: #f0f7ff;
        }
        .method-card input {
            position: absolute;
            opacity: 0;
        }
        .method-card.method-card-disabled {
            opacity: 0.55;
            cursor: not-allowed;
            background: #f8fafc;
        }
        .method-icon {
            font-size: 1.5rem;
            width: 40px;
            text-align: center;
        }
        .method-info h4 { margin: 0; }
        .method-info p { margin: 0; font-size: 0.8125rem; color: var(--text-muted); }
        .gateway-note {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 1rem;
            border-radius: 10px;
        }
        .cancel-note {
            background: #fff7ed;
            border: 1px solid #fdba74;
            color: #9a3412;
            padding: 0.9rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        .checkout-error-note {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 0.9rem 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 1.25rem;
            font-weight: 800;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px dashed var(--border-color);
        }
        .checkout-header {
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }
        .checkout-brand {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
            text-decoration: none;
        }
        .checkout-submit-wrap { margin-top: 2rem; }
        .checkout-summary-row {
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
        }
        .checkout-summary-copy { font-size: 0.8125rem; color: var(--text-muted); }
        .checkout-security-note {
            margin-top: 2rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: center;
        }
        .coupon-panel {
            margin-top: 1.5rem;
            padding: 1rem;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: #fff;
        }
        .coupon-panel label {
            display: block;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .coupon-panel input {
            width: 100%;
        }
        .coupon-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.75rem;
            align-items: end;
        }
        .coupon-row .btn {
            white-space: nowrap;
        }
        .coupon-panel small {
            display: block;
            margin-top: 0.5rem;
            color: var(--text-muted);
        }
        .coupon-status {
            margin-top: 0.75rem;
            padding: 0.8rem 0.9rem;
            border-radius: 10px;
            font-size: 0.875rem;
        }
        .coupon-status-valid {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #166534;
        }
        .coupon-status-invalid {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }
        .discount-row {
            display: flex;
            justify-content: space-between;
            margin-top: 0.75rem;
            color: #166534;
            font-weight: 700;
        }
        .summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #1d4ed8;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            padding: 0.35rem 0.65rem;
            margin-top: 0.75rem;
        }
        .renewal-panel {
            margin-top: 1.5rem;
            padding: 1rem;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: #fff;
        }
        .renewal-panel-copy {
            font-size: 0.8125rem;
            color: var(--text-muted);
            margin-top: 0.4rem;
        }
        .renewal-note {
            margin-top: 0.75rem;
            padding: 0.8rem 0.9rem;
            border-radius: 10px;
            font-size: 0.875rem;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
        }
        @media (max-width: 800px) {
            .checkout-container {
                grid-template-columns: 1fr;
                padding: 0 1rem;
            }
            .coupon-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="checkout-header">
        <a href="/" class="checkout-brand"><?= htmlspecialchars($siteName) ?></a>
    </header>

    <div class="checkout-container">
        <div>
            <h2>Select Payment Method</h2>
            <?php if (!empty($cancelledGateway)): ?>
                <div class="cancel-note">
                    The <?= htmlspecialchars(ucfirst((string)$cancelledGateway)) ?> checkout was cancelled. You can try again whenever you're ready.
                </div>
            <?php endif; ?>
            <?php if (!empty($checkoutError)): ?>
                <div class="checkout-error-note">
                    <?= htmlspecialchars((string)$checkoutError) ?>
                </div>
            <?php endif; ?>

            <form id="checkoutForm" method="POST" action="/checkout/process">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
                <input type="hidden" name="billing_option_id" id="billingOptionInput" value="<?= isset($selectedBillingOption['id']) ? (int)$selectedBillingOption['id'] : '' ?>">
                <?php if (!empty($zeroDollarCoupon)): ?>
                    <input type="hidden" name="gateway" value="coupon">
                <?php endif; ?>

                <?php if (count($billingOptions) > 1): ?>
                    <div class="coupon-panel" style="margin-bottom:1rem">
                        <label for="billingOptionSelect">Billing option</label>
                        <select id="billingOptionSelect" class="form-select">
                            <?php foreach ($billingOptions as $option): ?>
                                <option
                                    value="<?= isset($option['id']) ? (int)$option['id'] : '' ?>"
                                    data-price="<?= htmlspecialchars(number_format((float)$option['price'], 2, '.', '')) ?>"
                                    data-term-label="<?= htmlspecialchars(\App\Service\PaymentService::formatTermLabel((int)$option['term_days'])) ?>"
                                    data-renewal-enabled="<?= !empty($option['renewal_enabled']) ? '1' : '0' ?>"
                                    <?= isset($selectedBillingOption['id']) && (int)$selectedBillingOption['id'] === (int)($option['id'] ?? 0) ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars((string)$option['option_label']) ?> - $<?= number_format((float)$option['price'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Pick the billing length you want for this premium plan before you apply a coupon or start checkout.</small>
                    </div>
                <?php endif; ?>

                <div class="payment-methods">
                    <?php if (!empty($zeroDollarCoupon)): ?>
                        <div class="gateway-note">
                            This coupon covers the full premium charge. Fyuhls will apply the upgrade directly without sending you through Stripe or PayPal.
                        </div>
                    <?php else: ?>
                        <?php if (!empty($stripeEnabled)): ?>
                            <label class="method-card active" data-gateway-card="stripe">
                                <input type="radio" name="gateway" value="stripe" checked>
                                <div class="method-icon">Card</div>
                                <div class="method-info">
                                    <h4>Credit / Debit Card</h4>
                                    <p>Secure payment via Stripe Checkout</p>
                                </div>
                            </label>
                        <?php endif; ?>

                        <?php if (!empty($paypalEnabled)): ?>
                            <label class="method-card<?= empty($stripeEnabled) ? ' active' : '' ?>" data-gateway-card="paypal">
                                <input type="radio" name="gateway" value="paypal" <?= empty($stripeEnabled) ? 'checked' : '' ?>>
                                <div class="method-icon">PP</div>
                                <div class="method-info">
                                    <h4>PayPal</h4>
                                    <p>Approve payment through PayPal<?= $renewalEnabled ? ' for one-time or recurring premium purchases' : '' ?></p>
                                </div>
                            </label>
                        <?php endif; ?>

                        <?php if (empty($stripeEnabled) && empty($paypalEnabled)): ?>
                            <div class="gateway-note">
                                Checkout is temporarily unavailable right now. Please try again later.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($renewalEnabled): ?>
                    <div class="renewal-panel">
                        <label class="form-check-label d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input mt-0" name="auto_renew" id="autoRenewToggle" value="1" <?= $autoRenewSelected ? 'checked' : '' ?> <?= !$autoRenewAvailable ? 'disabled' : '' ?>>
                            Turn on auto-renew for this <?= htmlspecialchars($termLabel) ?> plan
                        </label>
                        <div class="renewal-panel-copy">
                            <?php if ($autoRenewAvailable): ?>
                                When this is on, Fyuhls will keep the premium package active by creating a recurring Stripe or PayPal subscription for the package term.
                            <?php else: ?>
                                Auto-renew is configured for this package, but it currently needs a configured recurring gateway and a paid first charge to start.
                            <?php endif; ?>
                        </div>
                        <div class="renewal-note" id="autoRenewGatewayNote" hidden>Auto-renew works with Stripe and PayPal. Recurring coupon discounts currently need Stripe, or you can use PayPal as a one-time discounted term.</div>
                    </div>
                <?php endif; ?>

                <div class="coupon-panel">
                    <label for="coupon_code">Coupon code</label>
                    <div class="coupon-row">
                        <input type="text" id="coupon_code" name="coupon_code" class="form-control" maxlength="64" value="<?= htmlspecialchars((string)($couponCode ?? '')) ?>" placeholder="Enter a premium coupon code">
                        <button type="submit" class="btn btn-outline-secondary" formaction="/checkout/preview" formmethod="post">Apply Coupon</button>
                    </div>
                    <small>Coupons apply at the moment checkout starts. If a code is limited or no longer matches your account, you'll see that before payment begins.</small>
                    <?php if (!empty($couponPreview['code'] ?? '')): ?>
                        <?php if (!empty($couponPreview['valid'])): ?>
                            <div class="coupon-status coupon-status-valid">
                                <strong><?= htmlspecialchars((string)$couponPreview['code']) ?></strong> is ready to use for this <?= htmlspecialchars((string)($couponPreview['purchase_kind'] ?? 'premium')) ?> purchase.
                            </div>
                        <?php elseif (!empty($couponPreview['message'])): ?>
                            <div class="coupon-status coupon-status-invalid">
                                <?= htmlspecialchars((string)$couponPreview['message']) ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="checkout-submit-wrap">
                    <button type="submit" class="btn btn-lg" <?= empty($zeroDollarCoupon) && empty($stripeEnabled) && empty($paypalEnabled) ? 'disabled' : '' ?>>Complete Purchase</button>
                </div>
            </form>
        </div>

        <div class="order-summary">
            <h3>Order Summary</h3>
            <div class="checkout-summary-row">
                <span><?= htmlspecialchars((string)$package['name']) ?> Plan</span>
                <span>$<?= number_format($originalAmount, 2) ?></span>
            </div>
            <p class="checkout-summary-copy">
                Includes: <?= htmlspecialchars($packageFeatureSummary) ?>.
            </p>
            <div class="checkout-summary-row">
                <span>Term</span>
                <span><?= htmlspecialchars($termLabel) ?></span>
            </div>
            <div class="checkout-summary-row">
                <span>Renewal</span>
                <span id="renewalSummaryText"><?= $renewalEnabled && $autoRenewSelected ? htmlspecialchars('Auto-renews every ' . $termLabel) : 'One-time purchase' ?></span>
            </div>

            <?php if (!empty($couponPreview['valid'])): ?>
                <div class="summary-pill">Coupon <?= htmlspecialchars((string)$couponPreview['code']) ?></div>
                <div class="discount-row">
                    <span>Discount</span>
                    <span>- $<?= number_format((float)($couponPreview['discount_amount'] ?? 0), 2) ?></span>
                </div>
            <?php endif; ?>

            <div class="total-row">
                <span id="totalLabel">Today</span>
                <span>$<?= number_format($firstChargeAmount, 2) ?></span>
            </div>
            <div class="checkout-summary-copy" id="renewalPriceText">
                <?php if ($renewalEnabled && $autoRenewSelected): ?>
                    <?= htmlspecialchars($recurringPrice > 0 ? ('$' . number_format($recurringPrice, 2) . ' every ' . $termLabel . '. ' . $recurringMessage) : $recurringMessage) ?>
                <?php else: ?>
                    Access remains active for this <?= htmlspecialchars($termLabel) ?> term with no automatic renewal.
                <?php endif; ?>
            </div>

            <div class="checkout-security-note">
                Secure 256-bit encrypted payment flow
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.method-card').forEach(card => {
            card.addEventListener('click', () => {
                const input = card.querySelector('input[type="radio"]');
                if (card.classList.contains('method-card-disabled') || !input) {
                    return;
                }
                input.checked = true;
                document.querySelectorAll('.method-card').forEach(c => c.classList.remove('active'));
                card.classList.add('active');
            });
        });

        (function () {
            const autoRenewToggle = document.getElementById('autoRenewToggle');
            const billingOptionSelect = document.getElementById('billingOptionSelect');
            const billingOptionInput = document.getElementById('billingOptionInput');
            const renewalSummaryText = document.getElementById('renewalSummaryText');
            const renewalPriceText = document.getElementById('renewalPriceText');
            const totalLabel = document.getElementById('totalLabel');
            const paypalCard = document.querySelector('[data-gateway-card="paypal"]');
            const stripeCard = document.querySelector('[data-gateway-card="stripe"]');
            const paypalInput = paypalCard ? paypalCard.querySelector('input[name="gateway"]') : null;
            const stripeInput = stripeCard ? stripeCard.querySelector('input[name="gateway"]') : null;
            const gatewayNote = document.getElementById('autoRenewGatewayNote');
            const termLabel = <?= json_encode($termLabel) ?>;
            const recurringText = <?= json_encode($recurringPrice > 0 ? ('$' . number_format($recurringPrice, 2) . ' every ' . $termLabel . '. ' . $recurringMessage) : $recurringMessage) ?>;
            const oneTimeText = <?= json_encode('Access remains active for this ' . $termLabel . ' term with no automatic renewal.') ?>;
            const couponPresent = <?= !empty($couponPreview['valid']) ? 'true' : 'false' ?>;

            function refreshRenewalUi() {
                if (!autoRenewToggle) {
                    return;
                }
                const autoRenewOn = !!autoRenewToggle.checked;
                if (renewalSummaryText) {
                    renewalSummaryText.textContent = autoRenewOn ? ('Auto-renews every ' + termLabel) : 'One-time purchase';
                }
                if (renewalPriceText) {
                    renewalPriceText.textContent = autoRenewOn ? recurringText : oneTimeText;
                }
                if (totalLabel) {
                    totalLabel.textContent = autoRenewOn ? 'Today' : 'Total';
                }
                if (paypalCard && paypalInput) {
                    const paypalDisabled = autoRenewOn && couponPresent;
                    paypalCard.classList.toggle('method-card-disabled', paypalDisabled);
                    paypalInput.disabled = paypalDisabled;
                    if (paypalDisabled && paypalInput.checked && stripeInput) {
                        stripeInput.checked = true;
                        stripeCard && stripeCard.classList.add('active');
                        paypalCard.classList.remove('active');
                    }
                }
                if (gatewayNote) {
                    gatewayNote.hidden = !(autoRenewOn && couponPresent);
                }
            }

            if (autoRenewToggle) {
                autoRenewToggle.addEventListener('change', refreshRenewalUi);
                refreshRenewalUi();
            }

            if (billingOptionSelect && billingOptionInput) {
                billingOptionSelect.addEventListener('change', function () {
                    billingOptionInput.value = this.value || '';
                    const targetUrl = new URL(window.location.href);
                    if (this.value) {
                        targetUrl.searchParams.set('option', this.value);
                    } else {
                        targetUrl.searchParams.delete('option');
                    }
                    window.location.href = targetUrl.toString();
                });
            }
        })();
    </script>
</body>
</html>
