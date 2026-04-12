<?php $siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls')); ?>
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
        .method-card:hover {
            border-color: var(--primary-color);
            background: #f0f7ff;
        }
        .method-card.active {
            border-color: var(--primary-color);
            background: #f0f7ff;
        }
        .method-card input {
            position: absolute;
            opacity: 0;
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
                    The <?= htmlspecialchars(ucfirst($cancelledGateway)) ?> checkout was cancelled. You can try again whenever you're ready.
                </div>
            <?php endif; ?>
            <form id="checkoutForm" method="POST" action="/checkout/process">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="package_id" value="<?= $package['id'] ?>">

                <div class="payment-methods">
                    <?php if (!empty($stripeEnabled)): ?>
                        <label class="method-card active">
                            <input type="radio" name="gateway" value="stripe" checked>
                            <div class="method-icon">Card</div>
                            <div class="method-info">
                                <h4>Credit / Debit Card</h4>
                                <p>Secure payment via Stripe Checkout</p>
                            </div>
                        </label>
                    <?php endif; ?>

                    <?php if (!empty($paypalEnabled)): ?>
                        <label class="method-card<?= empty($stripeEnabled) ? ' active' : '' ?>">
                            <input type="radio" name="gateway" value="paypal" <?= empty($stripeEnabled) ? 'checked' : '' ?>>
                            <div class="method-icon">PP</div>
                            <div class="method-info">
                                <h4>PayPal</h4>
                                <p>Approve and capture payment through PayPal</p>
                            </div>
                        </label>
                    <?php endif; ?>

                    <?php if (empty($stripeEnabled) && empty($paypalEnabled)): ?>
                        <div class="gateway-note">
                            No payment gateways are currently enabled for this install. Configure Stripe or PayPal in Config Hub to accept upgrades.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="checkout-submit-wrap">
                    <button type="submit" class="btn btn-lg" <?= empty($stripeEnabled) && empty($paypalEnabled) ? 'disabled' : '' ?>>Complete Purchase</button>
                </div>
            </form>
        </div>

        <div class="order-summary">
            <h3>Order Summary</h3>
            <div class="checkout-summary-row">
                <span><?= htmlspecialchars($package['name']) ?> Plan</span>
                <span>$<?= number_format((float)($package['price'] ?? 0), 2) ?></span>
            </div>
            <p class="checkout-summary-copy">
                Includes: <?= round($package['max_storage_bytes'] / 1024 / 1024 / 1024, 0) ?>GB Storage, Direct Links, No Ads<?= \App\Service\FeatureService::rewardsEnabled() ? ', and PPD Rewards' : '' ?>.
            </p>

            <div class="total-row">
                <span>Total</span>
                <span>$<?= number_format((float)($package['price'] ?? 0), 2) ?></span>
            </div>

            <div class="checkout-security-note">
                Secure 256-bit encrypted payment flow
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.method-card').forEach(card => {
            card.addEventListener('click', () => {
                document.querySelectorAll('.method-card').forEach(c => c.classList.remove('active'));
                card.classList.add('active');
            });
        });
    </script>
</body>
</html>
