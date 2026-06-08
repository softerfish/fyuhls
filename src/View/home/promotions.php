<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'fyuhls'));
$title = "Promotions - {$siteName}";
$offers = is_array($offers ?? null) ? $offers : [];
$isAuthenticated = (bool)($isAuthenticated ?? \App\Core\Auth::check());
$bonusOfferDefinitions = \App\Service\BonusOfferService::definitions();

$extraHead = '
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
<style>
    .promotions-shell { margin-top: 1rem; }
    .promotions-main { min-width: 0; }
    .promotions-public-wrap { max-width: 1180px; margin: 0 auto; width: 100%; padding: 1rem 1.25rem 4rem; }
    .promotions-hero,
    .promotions-card,
    .promotions-empty {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 16px;
    }
    .promotions-hero { padding: 1.5rem; margin-bottom: 1.5rem; }
    .promotions-hero h1 { margin: 0 0 0.85rem; font-size: 2rem; font-weight: 800; }
    .promotions-hero p { margin: 0; color: var(--text-muted); line-height: 1.7; max-width: 760px; }
    .promotions-hero-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.25rem; }
    .promotions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 1rem; }
    .promotions-card { padding: 1.25rem; min-width: 0; }
    .promotions-card__eyebrow { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; font-weight: 800; margin-bottom: 0.65rem; }
    .promotions-card h2 { margin: 0 0 0.75rem; font-size: 1.25rem; }
    .promotions-card__copy { color: var(--text-muted); line-height: 1.65; margin-bottom: 1rem; }
    .promotions-pill-row { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
    .promotions-pill { display: inline-flex; align-items: center; padding: 0.45rem 0.75rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 700; background: #eff6ff; color: #1d4ed8; }
    .promotions-metric-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.55rem; }
    .promotions-metric-list li { color: #334155; font-size: 0.9rem; line-height: 1.55; }
    .promotions-empty { padding: 2rem; text-align: center; color: var(--text-muted); }
    @media (max-width: 900px) {
        .promotions-hero h1 { font-size: 1.7rem; }
        .promotions-hero-actions { flex-direction: column; }
    }
</style>';

include __DIR__ . '/header.php';
if ($isAuthenticated) {
    include __DIR__ . '/partials/account_sidebar_styles.php';
}
?>

<?php if ($isAuthenticated): ?>
<div class="fm-container promotions-shell">
    <?php include __DIR__ . '/partials/account_sidebar.php'; ?>
    <div class="fm-main promotions-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Promotions</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <span>Promotions</span>
                </div>
            </div>
            <div class="toolbar-right">
                <div class="toolbar-controls">
                    <span class="text-muted small">See active bonus offers, custom thresholds, and the dates or weekdays they run in.</span>
                </div>
            </div>
        </div>
<?php else: ?>
<div class="promotions-public-wrap">
<?php endif; ?>

        <div class="promotions-hero">
            <h1>Active promotions and bonus offers</h1>
            <p><?= $isAuthenticated
                ? 'Track the promotions tied to your account, see the thresholds that matter, and watch what is still waiting for review before it moves into your rewards balance.'
                : 'See the current promotions running on ' . htmlspecialchars($siteName) . '. Create an account to track progress, earn bonus offers, and cash out credited rewards from one dashboard.' ?></p>
            <div class="promotions-hero-actions">
                <?php if ($isAuthenticated): ?>
                    <a href="/rewards" class="btn btn-primary">Open Rewards</a>
                    <a href="/affiliate" class="btn btn-white">Open Creator Rewards Guide</a>
                <?php else: ?>
                    <a href="/register" class="btn btn-primary">Create Account</a>
                    <a href="/login" class="btn btn-white">Login</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($offers)): ?>
            <div class="promotions-empty">
                No promotions are active right now. Check back later for upload, referral, and rewards bonus campaigns.
            </div>
        <?php else: ?>
            <div class="promotions-grid">
                <?php foreach ($offers as $offer): ?>
                    <article class="promotions-card">
                        <div class="promotions-card__eyebrow"><?= htmlspecialchars((string)($bonusOfferDefinitions['offerKinds'][$offer['offer_kind']] ?? 'Promotion')) ?></div>
                        <h2><?= htmlspecialchars((string)($offer['public_title'] ?? 'Promotion')) ?></h2>
                        <div class="promotions-card__copy"><?= nl2br(htmlspecialchars((string)($offer['public_description'] ?? ''))) ?></div>
                        <div class="promotions-pill-row">
                            <span class="promotions-pill"><?= htmlspecialchars(\App\Service\BonusOfferService::formatRewardPreview($offer, [])) ?></span>
                            <span class="promotions-pill"><?= htmlspecialchars(\App\Service\BonusOfferService::formatOfferSchedule($offer)) ?></span>
                            <span class="promotions-pill"><?= htmlspecialchars(\App\Service\BonusOfferService::formatUserAwardMode($offer)) ?></span>
                        </div>
                        <ul class="promotions-metric-list">
                            <li><strong>How to earn it:</strong> <?= htmlspecialchars(\App\Service\BonusOfferService::formatUserGoalSummary($offer)) ?></li>
                            <?php if (!empty($offer['progress_label'])): ?>
                                <li><strong>Your progress:</strong> <?= htmlspecialchars(\App\Service\BonusOfferService::formatUserProgress($offer)) ?></li>
                            <?php endif; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

<?php if ($isAuthenticated): ?>
    </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
