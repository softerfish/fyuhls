<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = "Affiliate Program - {$siteName}";
$ppsCommission = \App\Model\Setting::get('pps_commission_percent', '50', 'rewards');
include __DIR__ . '/header.php';
?>

<div class="affiliate-hero">
    <h1>Earn with <?= htmlspecialchars($siteName) ?></h1>
    <p>Use the reward model this install supports, track your earning path clearly, and share your referral link when affiliate referrals are enabled.</p>
</div>

<div class="section">
    <div class="program-grid">
        <?php if (in_array('mixed', $enabledModels, true)): ?>
        <div class="program-card" style="<?= ($userModel === 'mixed') ? 'border: 2px solid var(--primary-color); background: #f0f9ff;' : '' ?>">
            <span class="badge" style="background: <?= ($userModel === 'mixed') ? 'var(--primary-color); color: white;' : '#e0e7ff; color: #3730a3;' ?>">
                <?= ($userModel === 'mixed') ? 'Your Current Model' : 'Hybrid Model' ?>
            </span>
            <h2>PPD + PPS Hybrid</h2>
            <p>Combine both reward types. Hybrid uses the configured mixed percentages instead of the full standalone model values.</p>
            <ul style="padding-left: 1.25rem; color: #4b5563; line-height: 2; flex-grow: 1;">
                <li><strong><?= htmlspecialchars($mixedPpdPercent ?? '30') ?>%</strong> of the standard PPD tier rate</li>
                <li><strong><?= htmlspecialchars($mixedPpsPercent ?? '30') ?>%</strong> of the standard PPS commission</li>
                <li>Useful when your traffic includes both download-heavy and conversion-heavy sources</li>
            </ul>
            <?php if ($user && $userModel !== 'mixed'): ?>
                <form method="POST" action="/settings/update-monetization">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="model" value="mixed">
                    <button type="submit" class="btn btn-outline-primary w-100 mt-3">Switch to Hybrid</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (in_array('ppd', $enabledModels, true)): ?>
        <div class="program-card" style="<?= ($userModel === 'ppd') ? 'border: 2px solid var(--primary-color); background: #f0f9ff;' : '' ?>">
            <span class="badge" style="<?= ($userModel === 'ppd') ? 'background: var(--primary-color); color: white;' : '' ?>">
                <?= ($userModel === 'ppd') ? 'Your Current Model' : 'PPD Program' ?>
            </span>
            <h2>Pay Per Download</h2>
            <p>Earn based on the configured geographic download tiers. Qualification still depends on the anti-fraud, verification, and completion rules set by the admin.</p>
            <ul style="padding-left: 1.25rem; color: #4b5563; line-height: 2; flex-grow: 1;">
                <li>Rates come from the live tier table below</li>
                <li>Qualification depends on IP, file-size, and progress rules</li>
                <li>Use your rewards page to review cleared balances and payout requests</li>
            </ul>
            <?php if ($user && $userModel !== 'ppd'): ?>
                <form method="POST" action="/settings/update-monetization">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="model" value="ppd">
                    <button type="submit" class="btn btn-outline-primary w-100 mt-3">Switch to PPD</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (in_array('pps', $enabledModels, true)): ?>
        <div class="program-card" style="<?= ($userModel === 'pps') ? 'border: 2px solid var(--primary-color); background: #f0f9ff;' : '' ?>">
            <span class="badge" style="<?= ($userModel === 'pps') ? 'background: var(--primary-color); color: white;' : '' ?>">
                <?= ($userModel === 'pps') ? 'Your Current Model' : 'PPS Program' ?>
            </span>
            <h2>Pay Per Sale</h2>
            <p>Earn a commission whenever a tracked premium purchase is attributed to your account through the site referral flow.</p>
            <ul style="padding-left: 1.25rem; color: #4b5563; line-height: 2; flex-grow: 1;">
                <li><strong><?= htmlspecialchars($ppsCommission) ?>%</strong> commission based on the current PPS setting</li>
                <li>Best for referral-heavy traffic and buyers instead of raw download volume</li>
                <li>Use the referral link below to attribute signups and sales</li>
            </ul>
            <?php if ($user && $userModel !== 'pps'): ?>
                <form method="POST" action="/settings/update-monetization">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="model" value="pps">
                    <button type="submit" class="btn btn-outline-primary w-100 mt-3">Switch to PPS</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div style="margin-top: 5rem;">
        <h2 style="font-size: 2rem; text-align: center; margin-bottom: 2rem;">Current PPD Tier Rates</h2>
        <table class="tier-table">
            <thead>
                <tr>
                    <th>Tier</th>
                    <th>Countries</th>
                    <th>Rate per 1000 Downloads</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($tiers)): foreach ($tiers as $tier): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($tier['name']) ?></strong></td>
                        <td><?= $tier['countries'] ? htmlspecialchars(str_replace(',', ', ', $tier['countries'])) : 'Fallback / all other countries' ?></td>
                        <td><strong>$<?= number_format($tier['rate_per_1000'], 2) ?></strong></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="3" style="text-align: center;">No PPD tiers have been configured yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="font-size: 0.875rem; color: #6b7280; margin-top: 1rem; text-align: center;">The rates above are pulled from the live admin configuration and can change as the operator updates tiers or payout strategy.</p>
    </div>

    <div class="cta-box">
        <?php if ($user): ?>
            <h2>Your referral link</h2>
            <p style="margin-bottom: 2rem; font-size: 1.125rem; opacity: 0.9;">Share this link when you want signups and eligible purchases credited to your account under the current reward rules.</p>
            <div style="display: flex; gap: 0.5rem; max-width: 600px; margin: 0 auto;">
                <?php $refCode = trim((string)($user['public_id'] ?? '')); ?>
                <?php $refLink = \App\Service\SeoService::trustedBaseUrl() . '/?ref=' . rawurlencode($refCode !== '' ? $refCode : (string) $user['id']); ?>
                <input type="text" value="<?= htmlspecialchars($refLink) ?>" readonly style="flex: 1; padding: 1rem; border: none; border-radius: 8px; color: #111827; font-weight: 500;">
                <button class="btn" style="background: #111827; color: white; border: none; padding: 0 1.5rem;" onclick="navigator.clipboard.writeText(this.previousElementSibling.value); alert('Copied!')">Copy</button>
            </div>
        <?php else: ?>
            <h2>Create an account to start earning</h2>
            <p style="margin-bottom: 2rem; font-size: 1.125rem; opacity: 0.9;">Register for an account to access rewards, referral tools, and the account-side dashboard used to track cleared earnings and payout requests.</p>
            <a href="/register" class="btn">Create My Account</a>
        <?php endif; ?>
    </div>

    <style>
        .affiliate-hero { background: #111827; color: white; padding: 6rem 2rem; text-align: center; }
        .affiliate-hero h1 { font-size: 3rem; font-weight: 800; margin-bottom: 1.5rem; }
        .affiliate-hero p { font-size: 1.25rem; opacity: 0.9; max-width: 700px; margin: 0 auto; }
        .section { padding: 5rem 2rem; max-width: 1200px; margin: 0 auto; }
        .program-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem; margin-top: 3rem; }
        .program-card { background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 2.5rem; transition: transform 0.2s; display: flex; flex-direction: column; }
        .program-card:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .program-card h2 { font-size: 1.75rem; margin-bottom: 1rem; color: #111827; }
        .program-card p { line-height: 1.6; color: #4b5563; margin-bottom: 1.5rem; }
        .program-card .badge { display: inline-block; background: #eff6ff; color: #2563eb; font-weight: 700; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.875rem; margin-bottom: 1.5rem; }
        .tier-table { width: 100%; border-collapse: collapse; margin-top: 2rem; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
        .tier-table th { background: #f9fafb; padding: 1rem; text-align: left; font-weight: 600; color: #374151; }
        .tier-table td { padding: 1rem; border-top: 1px solid #e5e7eb; color: #4b5563; }
        .cta-box { background: linear-gradient(135deg, rgba(37, 99, 235, 0.07), rgba(99, 102, 241, 0.1)); border: 1px solid rgba(37, 99, 235, 0.15); border-radius: 24px; padding: 4rem; text-align: center; color: var(--text-color); margin-top: 4rem; }
        .cta-box h2 { font-size: 2.5rem; margin-bottom: 1.5rem; }
        .cta-box p { color: var(--text-muted); }
        .cta-box input[type="text"] { background: white; }
        .cta-box .btn { display: inline-block; width: auto; font-size: 1.125rem; padding: 0.875rem 2.5rem; }
        .cta-box .btn:hover { background: var(--primary-hover); }
    </style>

<?php include __DIR__ . '/footer.php'; ?>
