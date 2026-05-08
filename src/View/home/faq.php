<?php
use App\Service\SiteContentService;

$pageLocale = SiteContentService::requestLocale();
$siteContent = SiteContentService::page('faq', $pageLocale);
$siteContentTokens = SiteContentService::tokenContext();
$extraHead = ($extraHead ?? '') . SiteContentService::previewHeadHtml('faq', $pageLocale);

$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = "FAQ - {$siteName}";
$metaDescription = "Frequently asked questions about uploads, storage limits, download rules, package behavior, account access, and rewards on {$siteName}.";
include __DIR__ . '/header.php';

$faqHeader = $siteContent['header'] ?? [];
$faqItems = is_array($siteContent['items'] ?? null) ? $siteContent['items'] : [];
$faqCta = $siteContent['cta'] ?? [];

$categoryMeta = [
    'uploads' => [
        'label' => 'Uploads',
        'description' => 'Upload limits, storage space, large files, and remote imports.',
    ],
    'downloads' => [
        'label' => 'Downloads',
        'description' => 'Access rules, speeds, retention, and share links.',
    ],
    'accounts' => [
        'label' => 'Accounts',
        'description' => 'Registration, verification, privacy, and deleting files.',
    ],
    'billing' => [
        'label' => 'Plans & Billing',
        'description' => 'What changes as your plan changes.',
    ],
    'creator_rewards' => [
        'label' => 'Creator Rewards',
        'description' => 'How earnings qualify and when payouts become ready.',
    ],
    'safety' => [
        'label' => 'Privacy & Abuse',
        'description' => 'Moderation, policy removals, and support follow-up.',
    ],
    'api' => [
        'label' => 'API',
        'description' => 'Programmatic access, tokens, and integrations.',
    ],
];

$groupedFaqItems = [];
foreach ($faqItems as $item) {
    if (!is_array($item)) {
        continue;
    }
    $category = (string)($item['category'] ?? '');
    if ($category === '' || !isset($categoryMeta[$category])) {
        $category = 'downloads';
    }
    $groupedFaqItems[$category][] = $item;
}

$orderedCategories = array_values(array_filter(array_keys($categoryMeta), static fn(string $key): bool => !empty($groupedFaqItems[$key])));
?>

<div class="faq-shell">
    <section class="faq-hero">
        <div class="faq-hero-copy">
            <h1><?= SiteContentService::renderInlineMarkdown((string)($faqHeader['title'] ?? 'Frequently Asked Questions'), $siteContentTokens) ?></h1>
            <div class="faq-header-copy"><?= SiteContentService::renderMarkdown((string)($faqHeader['intro'] ?? ''), $siteContentTokens) ?></div>
            <div class="faq-inline-support">
                <div>
                    <strong>Need a direct answer?</strong>
                    <span>If you do not see your situation here, contact support and include the file link, account email, or reward question you want reviewed.</span>
                </div>
                <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/contact', $pageLocale)) ?>" class="btn faq-support-button">Contact Support</a>
            </div>
        </div>
        <div class="faq-hero-tools">
            <label class="faq-search-label" for="faqSearch">Search answers</label>
            <div class="faq-search-wrap">
                <span class="faq-search-icon" aria-hidden="true">&#128269;</span>
                <input id="faqSearch" type="search" class="faq-search-input" placeholder="Search uploads, downloads, accounts, rewards, or API">
            </div>
            <?php if (!empty($orderedCategories)): ?>
                <div class="faq-jump-label">Jump to a topic</div>
                <div class="faq-jump-chips">
                    <?php foreach ($orderedCategories as $categoryKey): ?>
                        <a href="#faq-section-<?= htmlspecialchars($categoryKey) ?>" class="faq-jump-chip">
                            <?= htmlspecialchars($categoryMeta[$categoryKey]['label']) ?>
                            <span><?= count($groupedFaqItems[$categoryKey] ?? []) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div id="faqEmptySearch" class="faq-empty-search" hidden>
        <h2>No FAQ matches that search yet.</h2>
        <p>Try a shorter phrase, or contact support if you need help with something specific.</p>
    </div>

    <?php foreach ($orderedCategories as $categoryKey): ?>
        <?php
        $items = $groupedFaqItems[$categoryKey] ?? [];
        if ($items === []) {
            continue;
        }
        $category = $categoryMeta[$categoryKey];
        ?>
        <section id="faq-section-<?= htmlspecialchars($categoryKey) ?>" class="faq-section" data-faq-section>
            <div class="faq-section-header">
                <div>
                    <h2><?= htmlspecialchars($category['label']) ?></h2>
                    <p><?= htmlspecialchars($category['description']) ?></p>
                </div>
                <div class="faq-section-count"><?= count($items) ?> question<?= count($items) === 1 ? '' : 's' ?></div>
            </div>

            <div class="faq-section-list">
                <?php foreach ($items as $item): ?>
                    <?php
                    $question = (string)($item['question'] ?? '');
                    $answerMarkdown = (string)($item['answer'] ?? '');
                    $answerPlain = trim(strip_tags(SiteContentService::renderMarkdown($answerMarkdown, $siteContentTokens)));
                    $searchText = mb_strtolower(trim($question . ' ' . $answerPlain));
                    ?>
                    <details class="faq-entry" data-faq-entry data-search="<?= htmlspecialchars($searchText) ?>">
                        <summary>
                            <span class="faq-entry-question"><?= SiteContentService::renderInlineMarkdown($question, $siteContentTokens) ?></span>
                            <span class="faq-entry-toggle" aria-hidden="true"></span>
                        </summary>
                        <div class="faq-entry-body">
                            <div class="faq-answer-copy"><?= SiteContentService::renderMarkdown($answerMarkdown, $siteContentTokens) ?></div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="faq-cta">
        <h2><?= SiteContentService::renderInlineMarkdown((string)($faqCta['title'] ?? 'Still have questions?'), $siteContentTokens) ?></h2>
        <div class="faq-cta-copy"><?= SiteContentService::renderMarkdown((string)($faqCta['body'] ?? ''), $siteContentTokens) ?></div>
        <a href="<?= htmlspecialchars(SiteContentService::localizeUrl('/contact', $pageLocale)) ?>" class="faq-cta-button btn"><?= htmlspecialchars((string)($faqCta['button_label'] ?? 'Contact Support')) ?></a>
    </div>
</div>

<style>
    .faq-shell { max-width: 1120px; margin: 3.5rem auto 5rem; padding: 0 2rem; }
    .faq-hero { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(320px, .9fr); gap: 2rem; align-items: start; margin-bottom: 2rem; }
    .faq-hero-copy { background: white; border: 1px solid var(--border-color); border-radius: 22px; padding: 1.9rem 2.1rem; }
    .faq-hero-copy h1 { font-size: clamp(2.25rem, 3vw, 3rem); line-height: 1.05; margin: 0 0 1rem; letter-spacing: 0; }
    .faq-header-copy { color: var(--text-muted); font-size: 1.03rem; line-height: 1.68; max-width: 64ch; }
    .faq-header-copy p:first-child,
    .faq-answer-copy p:first-child,
    .faq-cta-copy p:first-child { margin-top: 0; }
    .faq-header-copy p:last-child,
    .faq-answer-copy p:last-child,
    .faq-cta-copy p:last-child { margin-bottom: 0; }
    .faq-inline-support { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 1.6rem; padding-top: 1.2rem; border-top: 1px solid #e7edf7; }
    .faq-inline-support strong { display: block; color: var(--text-main); margin-bottom: .25rem; }
    .faq-inline-support span { color: var(--text-muted); display: block; }
    .faq-hero-tools { background: linear-gradient(180deg, rgba(37,99,235,.06), rgba(37,99,235,.02)); border: 1px solid rgba(37,99,235,.12); border-radius: 22px; padding: 1.5rem; }
    .faq-search-label { display: block; font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #5f6f89; margin-bottom: .65rem; }
    .faq-search-wrap { position: relative; margin-bottom: 1rem; }
    .faq-search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #7c8ba1; font-size: 1rem; line-height: 1; }
    .faq-search-input { width: 100%; border: 1px solid #d7e0ef; background: white; color: var(--text-main); border-radius: 14px; height: 52px; padding: 0 1rem 0 2.9rem; font-size: 1rem; outline: none; box-sizing: border-box; }
    .faq-search-input:focus { border-color: rgba(37,99,235,.45); box-shadow: 0 0 0 4px rgba(37,99,235,.08); }
    .faq-jump-label { font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #5f6f89; margin: 1rem 0 .7rem; }
    .faq-jump-chips { display: flex; flex-wrap: wrap; gap: .65rem; }
    .faq-jump-chip { display: inline-flex; align-items: center; gap: .55rem; padding: .68rem .95rem; border-radius: 999px; border: 1px solid #d7e0ef; background: white; color: #294062; text-decoration: none; font-weight: 700; font-size: .96rem; }
    .faq-jump-chip span { min-width: 1.5rem; height: 1.5rem; border-radius: 999px; background: #edf3ff; color: #295ed9; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; }
    .faq-support-button { flex-shrink: 0; }
    .faq-empty-search { text-align: center; background: white; border: 1px dashed #c9d6ea; border-radius: 20px; padding: 2.5rem 1.5rem; margin-bottom: 2rem; }
    .faq-empty-search h2 { margin: 0 0 .65rem; font-size: 1.5rem; }
    .faq-empty-search p { margin: 0; color: var(--text-muted); }
    .faq-section { margin-bottom: 2.5rem; }
    .faq-section-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; margin-bottom: 1.1rem; }
    .faq-section-header h2 { margin: 0 0 .35rem; font-size: 1.8rem; letter-spacing: 0; }
    .faq-section-header p { margin: 0; color: var(--text-muted); max-width: 62ch; }
    .faq-section-count { flex-shrink: 0; padding: .5rem .85rem; border-radius: 999px; background: #eef4ff; color: #295ed9; font-size: .9rem; font-weight: 700; }
    .faq-section-list { display: grid; gap: 1rem; }
    .faq-entry { background: white; border: 1px solid var(--border-color); border-radius: 18px; overflow: hidden; }
    .faq-entry summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.2rem 1.3rem; font-size: 1.12rem; font-weight: 800; color: var(--text-main); }
    .faq-entry summary::-webkit-details-marker { display: none; }
    .faq-entry-toggle { width: 28px; height: 28px; border-radius: 999px; background: #eef4ff; color: #295ed9; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; position: relative; }
    .faq-entry-toggle::before,
    .faq-entry-toggle::after { content: ""; position: absolute; background: currentColor; border-radius: 999px; }
    .faq-entry-toggle::before { width: 12px; height: 2px; }
    .faq-entry-toggle::after { width: 2px; height: 12px; transition: opacity .18s ease; }
    .faq-entry[open] .faq-entry-toggle::after { opacity: 0; }
    .faq-entry[open] { border-color: rgba(37,99,235,.18); box-shadow: 0 16px 30px -22px rgba(37,99,235,.32); }
    .faq-entry-body { padding: 0 1.3rem 1.3rem; color: var(--text-muted); line-height: 1.75; }
    .faq-entry-body p,
    .faq-entry-body ul,
    .faq-entry-body ol { margin-top: 0; }
    .faq-entry-body ul,
    .faq-entry-body ol { padding-left: 1.1rem; }
    .faq-cta { text-align: center; margin-top: 4rem; padding: 3rem 2rem; background: linear-gradient(135deg, rgba(37, 99, 235, 0.07), rgba(99, 102, 241, 0.1)); border-radius: 24px; border: 1px solid rgba(37, 99, 235, 0.15); }
    .faq-cta-copy { margin-bottom: 2rem; color: var(--text-muted); max-width: 60ch; margin-left: auto; margin-right: auto; }
    .faq-cta-button { width: auto; padding: 0.875rem 2.5rem; }

    @media (max-width: 980px) {
        .faq-hero { grid-template-columns: 1fr; }
        .faq-inline-support { flex-direction: column; align-items: flex-start; }
    }

    @media (max-width: 720px) {
        .faq-shell { padding: 0 1rem; margin-top: 2rem; }
        .faq-hero-copy,
        .faq-hero-tools,
        .faq-cta { padding: 1.4rem; }
        .faq-section-header { flex-direction: column; align-items: flex-start; }
        .faq-entry summary { font-size: 1rem; padding: 1rem 1rem; }
        .faq-entry-body { padding: 0 1rem 1rem; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('faqSearch');
    if (!searchInput) {
        return;
    }

    const entries = Array.from(document.querySelectorAll('[data-faq-entry]'));
    const sections = Array.from(document.querySelectorAll('[data-faq-section]'));
    const emptyState = document.getElementById('faqEmptySearch');

    const applyFilter = function () {
        const query = searchInput.value.trim().toLowerCase();
        let visibleCount = 0;

        entries.forEach(function (entry) {
            const haystack = (entry.getAttribute('data-search') || '').toLowerCase();
            const matches = query === '' || haystack.indexOf(query) !== -1;
            entry.hidden = !matches;
            if (matches) {
                visibleCount += 1;
            } else {
                entry.removeAttribute('open');
            }
        });

        sections.forEach(function (section) {
            const visibleEntries = section.querySelectorAll('[data-faq-entry]:not([hidden])').length;
            section.hidden = visibleEntries === 0;
        });

        if (emptyState) {
            emptyState.hidden = visibleCount !== 0;
        }
    };

    searchInput.addEventListener('input', applyFilter);
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
