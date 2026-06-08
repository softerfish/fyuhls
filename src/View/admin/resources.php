<?php
include 'header.php';
include __DIR__ . '/partials/shell_helpers.php';

$resourceSections = is_array($resourceSections ?? null) ? $resourceSections : [];
$resourceGroups = [
    'tools' => [
        'title' => 'Operator Tools',
        'description' => 'Practical tools and services worth reviewing while launching, securing, or monetizing a hosting operation.',
    ],
    'partners' => [
        'title' => 'Partners & Sponsors',
        'description' => 'Commercial partners and supporting offers that can help with hosting, inbox setup, and launch costs.',
    ],
];
$groupedSections = ['tools' => [], 'partners' => []];
$resourceCount = 0;
foreach ($resourceSections as $section) {
    $groupKey = ($section['group'] ?? 'tools') === 'partners' ? 'partners' : 'tools';
    $groupedSections[$groupKey][] = $section;
    $resourceCount += count((array)($section['items'] ?? []));
}
$toolCount = array_sum(array_map(static fn(array $section): int => count((array)($section['items'] ?? [])), $groupedSections['tools']));
$partnerCount = array_sum(array_map(static fn(array $section): int => count((array)($section['items'] ?? [])), $groupedSections['partners']));

$headerActions = '<div class="d-flex flex-wrap gap-2">'
    . '<a class="btn btn-outline-primary btn-sm" href="mailto:' . htmlspecialchars($sponsorEmail) . '">Sponsor this page</a>'
    . '</div>';

renderAdminPageHeader(
    'Resources',
    'Curated tools, services, and partner offers that can help operators launch faster and run a cleaner hosting business.',
    $headerActions
);
?>

<style>
    .resources-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .resources-summary-card {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1rem 1.1rem;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }
    .resources-summary-label {
        color: #64748b;
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        margin-bottom: 0.35rem;
    }
    .resources-summary-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
    }
    .resources-summary-copy {
        margin-top: 0.3rem;
        color: var(--text-muted);
        font-size: 0.84rem;
        line-height: 1.45;
    }
    .resources-operator-note {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1rem 1.1rem;
        margin-bottom: 1.25rem;
    }
    .resources-operator-note-title {
        margin: 0 0 0.35rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
    }
    .resources-operator-note-copy {
        margin: 0;
        color: var(--text-muted);
        font-size: 0.9rem;
        line-height: 1.55;
    }
    .resources-jump-row {
        display: flex;
        gap: 0.65rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }
    .resources-jump-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        border-radius: 999px;
        padding: 0.48rem 0.9rem;
        border: 1px solid #dbe6f3;
        background: #fff;
        color: #334155;
        text-decoration: none;
        font-size: 0.82rem;
        font-weight: 700;
    }
    .resources-jump-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.35rem;
        height: 1.35rem;
        padding: 0 0.35rem;
        border-radius: 999px;
        background: #eef2ff;
        color: #3730a3;
        font-size: 0.72rem;
    }
    .resources-group-section {
        margin-bottom: 1.5rem;
    }
    .resources-group-section:last-child {
        margin-bottom: 0;
    }
    .resources-group-head {
        margin-bottom: 1rem;
    }
    .resources-group-title {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 800;
        color: #0f172a;
    }
    .resources-group-copy {
        margin: 0.35rem 0 0;
        color: var(--text-muted);
        font-size: 0.9rem;
        line-height: 1.55;
    }
    .resources-section-stack {
        display: grid;
        gap: 1rem;
    }
    .resources-section-card {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        background: #fff;
        overflow: hidden;
    }
    .resources-section-header {
        padding: 1rem 1.1rem;
        border-bottom: 1px solid var(--border-color);
        background: #fff;
    }
    .resources-section-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
    }
    .resources-section-copy {
        margin: 0.3rem 0 0;
        color: var(--text-muted);
        font-size: 0.88rem;
        line-height: 1.5;
    }
    .resources-list {
        display: grid;
        gap: 0;
    }
    .resources-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 1rem;
        align-items: start;
        padding: 1rem 1.1rem;
        border-top: 1px solid var(--border-color);
    }
    .resources-item:first-child {
        border-top: none;
    }
    .resources-item-main {
        min-width: 0;
    }
    .resources-item-top {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
        margin-bottom: 0.35rem;
    }
    .resources-item-name {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
    }
    .resources-item-kind {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.25rem 0.55rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #64748b;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .resources-item-bestfor {
        margin: 0 0 0.4rem;
        color: #334155;
        font-size: 0.84rem;
        font-weight: 600;
    }
    .resources-item-description {
        margin: 0;
        color: var(--text-muted);
        font-size: 0.88rem;
        line-height: 1.55;
        max-width: 880px;
    }
    .resources-item-action {
        white-space: nowrap;
        align-self: center;
    }
    .resources-empty {
        border: 1px dashed var(--border-color);
        border-radius: 14px;
        background: #f8fafc;
        padding: 1rem 1.1rem;
        color: var(--text-muted);
        font-size: 0.9rem;
    }
    @media (max-width: 900px) {
        .resources-item {
            grid-template-columns: 1fr;
        }
        .resources-item-action {
            justify-self: start;
        }
    }
</style>

<div class="resources-summary-grid">
    <div class="resources-summary-card">
        <div class="resources-summary-label">Total Resources</div>
        <div class="resources-summary-value"><?= number_format($resourceCount) ?></div>
        <div class="resources-summary-copy">Curated services and offers worth reviewing from the operator side.</div>
    </div>
    <div class="resources-summary-card">
        <div class="resources-summary-label">Operator Tools</div>
        <div class="resources-summary-value"><?= number_format($toolCount) ?></div>
        <div class="resources-summary-copy">Tools for monetization testing, fraud control, and workflow awareness.</div>
    </div>
    <div class="resources-summary-card">
        <div class="resources-summary-label">Partner Offers</div>
        <div class="resources-summary-value"><?= number_format($partnerCount) ?></div>
        <div class="resources-summary-copy">Helpful launch-stage providers for hosting, inboxes, and operator setup.</div>
    </div>
</div>

<div class="resources-operator-note">
    <h2 class="resources-operator-note-title">Note</h2>
    <p class="resources-operator-note-copy">We'd love to list more here. If you have a service you can offer, mail <a href="mailto:<?= htmlspecialchars($sponsorEmail) ?>"><?= htmlspecialchars($sponsorEmail) ?></a>.</p>
</div>

<div class="resources-jump-row">
    <?php foreach ($resourceGroups as $groupKey => $groupMeta): ?>
        <a class="resources-jump-chip" href="#resources-<?= htmlspecialchars($groupKey) ?>">
            <span><?= htmlspecialchars($groupMeta['title']) ?></span>
            <span class="resources-jump-count"><?= number_format(array_sum(array_map(static fn(array $section): int => count((array)($section['items'] ?? [])), $groupedSections[$groupKey] ?? []))) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php foreach ($resourceGroups as $groupKey => $groupMeta): ?>
    <section class="resources-group-section" id="resources-<?= htmlspecialchars($groupKey) ?>">
        <?php
        ob_start();
        ?>
            <div class="resources-group-head">
                <h3 class="resources-group-title"><?= htmlspecialchars($groupMeta['title']) ?></h3>
                <p class="resources-group-copy"><?= htmlspecialchars($groupMeta['description']) ?></p>
            </div>
        <?php
        $groupHeader = ob_get_clean();
        renderAdminCardStart(null, ['headerHtml' => $groupHeader]);
        ?>
            <div class="resources-section-stack">
                <?php if (!empty($groupedSections[$groupKey])): ?>
                    <?php foreach ($groupedSections[$groupKey] as $section): ?>
                        <section class="resources-section-card">
                            <div class="resources-section-header">
                                <h4 class="resources-section-title"><?= htmlspecialchars($section['title']) ?></h4>
                                <p class="resources-section-copy"><?= htmlspecialchars($section['description']) ?></p>
                            </div>
                            <?php if (!empty($section['items'])): ?>
                                <div class="resources-list">
                                    <?php foreach ($section['items'] as $item): ?>
                                        <article class="resources-item">
                                            <div class="resources-item-main">
                                                <div class="resources-item-top">
                                                    <h5 class="resources-item-name"><?= htmlspecialchars($item['name']) ?></h5>
                                                    <span class="resources-item-kind"><?=
                                                        $groupKey === 'partners'
                                                            ? 'Partner offer'
                                                            : ((($section['title'] ?? '') === 'Affiliates') ? 'Monetization' : 'Operator tool')
                                                    ?></span>
                                                </div>
                                                <?php if (!empty($item['best_for'])): ?>
                                                    <p class="resources-item-bestfor">Best for: <?= htmlspecialchars($item['best_for']) ?></p>
                                                <?php endif; ?>
                                                <p class="resources-item-description"><?= htmlspecialchars($item['description']) ?></p>
                                            </div>
                                            <div class="resources-item-action">
                                                <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($item['url']) ?>" target="_blank" rel="noopener noreferrer">Open Site</a>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="resources-empty">This section is open for future additions once you have a recommendation worth keeping here.</div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="resources-empty">No entries have been added to this group yet.</div>
                <?php endif; ?>
            </div>
        <?php renderAdminCardEnd(); ?>
    </section>
<?php endforeach; ?>

<?php include 'footer.php'; ?>
