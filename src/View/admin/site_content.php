<?php
include __DIR__ . '/header.php';
include __DIR__ . '/partials/shell_helpers.php';

$activeBlocks = $pageDefinition['blocks'] ?? [];
$activeRoute = $pageDefinition['route'] ?? '/';
$activeLocale = $activeLocale ?? \App\Service\SiteContentService::DEFAULT_LOCALE;

if (!function_exists('siteContentFieldInput')) {
    function siteContentFieldInput(string $fieldName, array $fieldDef, mixed $value): void
    {
        $type = (string)($fieldDef['type'] ?? 'text');
        $label = (string)($fieldDef['label'] ?? '');
        $options = $fieldDef['options'] ?? [];
        if ($type === 'hidden') {
            ?>
            <input type="hidden" name="<?= htmlspecialchars($fieldName) ?>" value="<?= htmlspecialchars((string)$value) ?>">
            <?php
            return;
        }
        ?>
        <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                <label class="form-label fw-semibold mb-0"><?= htmlspecialchars($label) ?></label>
                <?php if ($type === 'markdown'): ?>
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none site-content-markdown-help-link" data-bs-toggle="collapse" data-bs-target="#siteContentMarkdownHelpPanel" aria-expanded="false" aria-controls="siteContentMarkdownHelpPanel">
                        Markdown help
                    </button>
                <?php endif; ?>
            </div>
            <?php if ($type === 'markdown'): ?>
                <textarea class="form-control site-content-markdown" name="<?= htmlspecialchars($fieldName) ?>" rows="4"><?= htmlspecialchars((string)$value) ?></textarea>
            <?php elseif ($type === 'select'): ?>
                <select class="form-select" name="<?= htmlspecialchars($fieldName) ?>">
                    <?php foreach ((array)$options as $optionValue => $optionLabel): ?>
                        <option value="<?= htmlspecialchars((string)$optionValue) ?>"<?= (string)$value === (string)$optionValue ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string)$optionLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($type === 'url'): ?>
                <input type="url" class="form-control" name="<?= htmlspecialchars($fieldName) ?>" value="<?= htmlspecialchars((string)$value) ?>" placeholder="/faq or https://example.com">
                <div class="form-text">Allowed: <code>/internal-path</code>, <code>https://example.com</code>, <code>mailto:name@example.com</code>, or <code>tel:+123456789</code>.</div>
            <?php else: ?>
                <input type="text" class="form-control" name="<?= htmlspecialchars($fieldName) ?>" value="<?= htmlspecialchars((string)$value) ?>">
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('siteContentBlockHint')) {
    function siteContentBlockHint(string $pageKey, string $blockKey): ?string
    {
        $hints = [
            'homepage' => [
                'hero' => 'Shows on the public homepage hero area for visitors who are not logged in, plus admin preview mode.',
                'panel' => 'Shows in the dark summary panel on the right side of the public homepage hero area.',
                'features_section' => 'Shows above the homepage feature card grid on the public homepage.',
                'feature_cards' => 'Shows as the feature cards in the public homepage features section.',
                'quick_faq_section' => 'Shows above the smaller FAQ teaser section on the public homepage.',
                'quick_faq_cards' => 'Shows as the compact FAQ teaser cards on the public homepage, not the full /faq page.',
                'pricing_section' => 'Shows above the package/pricing cards on the public homepage.',
            ],
            'faq' => [
                'header' => 'Shows at the top of the public /faq page.',
                'items' => 'Shows as the main FAQ entries on the public /faq page. Use categories to decide which help-center section each question appears in.',
                'cta' => 'Shows as the closing contact/help call to action at the bottom of the public /faq page.',
            ],
            'contact' => [
                'page' => 'Shows as the main heading, intro text, and submit button label on the public /contact page.',
                'fields' => 'Shows as the visible form labels on the public /contact page.',
            ],
            'dmca' => [
                'page' => 'Shows as the main heading, intro text, and submit button label on the public /dmca page.',
                'fields' => 'Shows as the visible form labels, help text, and confirmation copy on the public /dmca page.',
            ],
            'footer' => [
                'brand' => 'Shows in the public site footer text area.',
                'custom_links' => 'Shows as the editable custom links in the public site footer.',
            ],
        ];

        return $hints[$pageKey][$blockKey] ?? null;
    }
}

if (!function_exists('siteContentBlockStatLine')) {
    function siteContentBlockStatLine(array $blockDef, mixed $blockValue): string
    {
        if (($blockDef['type'] ?? 'object') === 'list') {
            $count = is_array($blockValue) ? count($blockValue) : 0;
            $label = (string)($blockDef['item_label'] ?? 'item');
            return $count . ' ' . $label . ($count === 1 ? '' : 's');
        }

        $fields = (array)($blockDef['fields'] ?? []);
        return count($fields) . ' field' . (count($fields) === 1 ? '' : 's');
    }
}

$blockCount = count($activeBlocks);
$currentRevisionCount = is_array($pageRevisions ?? null) ? count($pageRevisions) : 0;
$themeWarningCount = is_array($themeWarnings ?? null) ? count($themeWarnings) : 0;
$publicPageUrl = htmlspecialchars(\App\Service\SiteContentService::localizeUrl((string)$activeRoute, (string)$activeLocale));
$canDirectlyViewPublicPage = (string)$activeRoute !== '/';

ob_start();
?>
<div class="d-flex flex-wrap gap-2 align-items-center site-content-action-bar">
    <?php if ($canDirectlyViewPublicPage): ?>
        <a href="<?= $publicPageUrl ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary shadow-sm">
            <i class="bi bi-box-arrow-up-right me-1"></i> View Public Page
        </a>
    <?php endif; ?>
    <a href="/admin/site-content/export?page=<?= urlencode((string)$activePageKey) ?>&locale=<?= urlencode((string)$activeLocale) ?>" class="btn btn-sm btn-outline-dark shadow-sm">
        <i class="bi bi-download me-1"></i> Export JSON
    </a>
    <button class="btn btn-sm btn-outline-primary shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#siteContentImportBox" aria-expanded="false" aria-controls="siteContentImportBox">
        <i class="bi bi-upload me-1"></i> Import JSON
    </button>
    <?php if ($previewUrl !== ''): ?>
        <a href="<?= htmlspecialchars($previewUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary shadow-sm">
            <i class="bi bi-eye me-1"></i> Open Preview
        </a>
    <?php endif; ?>
</div>
<?php
$pageActions = ob_get_clean();
renderAdminPageHeader('Site Content', 'Edit public-facing text with markdown, live saves, preview links, and revision history.', $pageActions);
?>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm"><?= htmlspecialchars($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm"><?= htmlspecialchars($errorMessage) ?></div>
<?php endif; ?>

<?php if (is_array($importResult) && !empty($importResult['pages'])): ?>
    <div class="alert alert-info border-0 shadow-sm">
        Imported pages: <?= htmlspecialchars(implode(', ', (array)$importResult['pages'])) ?> (locale <?= htmlspecialchars((string)($importResult['locale'] ?? 'en')) ?>)
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="site-content-summary-chip">
            <span class="site-content-summary-label">Page</span>
            <strong><?= htmlspecialchars((string)$pageDefinition['label']) ?></strong>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="site-content-summary-chip">
            <span class="site-content-summary-label">Locale</span>
            <strong><code><?= htmlspecialchars((string)$activeLocale) ?></code></strong>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="site-content-summary-chip">
            <span class="site-content-summary-label">Blocks</span>
            <strong><?= (int)$blockCount ?></strong>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="site-content-summary-chip">
            <span class="site-content-summary-label">Revisions kept</span>
            <strong><?= (int)$currentRevisionCount ?> / 10</strong>
        </div>
    </div>
    <?php if ($themeWarningCount > 0): ?>
        <div class="col-12">
            <div class="site-content-soft-note site-content-soft-note-warning">
                <strong>Theme note:</strong> <?= (int)$themeWarningCount ?> custom override<?= $themeWarningCount === 1 ? '' : 's' ?> may bypass Site Content helpers.
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="collapse mb-4" id="siteContentImportBox">
    <?php renderAdminCardStart('Import Site Content', ['cardClass' => 'card border-0 shadow-sm']); ?>
        <form method="POST" action="/admin/site-content/import" enctype="multipart/form-data" class="row g-3">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="page_key" value="<?= htmlspecialchars($activePageKey) ?>">
            <input type="hidden" name="locale" value="<?= htmlspecialchars($activeLocale) ?>">
            <div class="col-12">
                <label class="form-label fw-semibold">Paste exported JSON</label>
                <textarea class="form-control" name="import_json" rows="8" placeholder='{"schema_version":"1.0","pages":{...}}'></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Or upload export file</label>
                <input type="file" class="form-control" name="import_file" accept="application/json,.json">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Import Content</button>
            </div>
        </form>
    <?php renderAdminCardEnd(); ?>
</div>

<div class="row g-4">
    <div class="col-xl-3">
        <?php renderAdminCardStart('Editing Locale', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <form method="GET" action="/admin/site-content" class="row g-2">
                <input type="hidden" name="page" value="<?= htmlspecialchars($activePageKey) ?>">
                <div class="col-12">
                    <label class="form-label fw-semibold">Locale code</label>
                    <input type="text" class="form-control" name="locale" value="<?= htmlspecialchars($activeLocale) ?>" list="siteContentLocales" placeholder="en">
                    <datalist id="siteContentLocales">
                        <?php foreach ((array)$availableLocales as $localeCode): ?>
                            <option value="<?= htmlspecialchars((string)$localeCode) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-12 small text-muted">
                    Use language tags like <code>en</code>, <code>fr</code>, or <code>pt-BR</code>. Preview, save, revisions, and export all follow the active locale.
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Switch Locale</button>
                </div>
            </form>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Editable Pages', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="list-group list-group-flush">
                <?php foreach ($pageDefinitions as $pageKey => $definition): ?>
                    <a href="/admin/site-content?page=<?= urlencode((string)$pageKey) ?>&locale=<?= urlencode((string)$activeLocale) ?>" class="list-group-item list-group-item-action<?= $pageKey === $activePageKey ? ' active' : '' ?>">
                        <div class="fw-semibold"><?= htmlspecialchars((string)$definition['label']) ?></div>
                        <div class="small opacity-75"><?= htmlspecialchars((string)($definition['route'] ?? '/')) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('This Page Sections', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="list-group list-group-flush site-content-block-nav">
                <?php foreach ($activeBlocks as $blockKey => $blockDef): ?>
                    <a href="#<?= htmlspecialchars('block-' . $blockKey) ?>" class="list-group-item list-group-item-action" data-block-nav-link="<?= htmlspecialchars('block-' . $blockKey) ?>">
                        <div class="fw-semibold"><?= htmlspecialchars((string)($blockDef['label'] ?? ucfirst((string)$blockKey))) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars(siteContentBlockStatLine((array)$blockDef, $pageContent[$blockKey] ?? [])) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Editor Tools', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <div class="accordion accordion-flush" id="siteContentSidebarTools">
                <div class="accordion-item border-0">
                    <h2 class="accordion-header" id="siteContentMarkdownHeading">
                        <button class="accordion-button collapsed px-0 py-2 shadow-none bg-transparent" type="button" data-bs-toggle="collapse" data-bs-target="#siteContentMarkdownHelpPanel" aria-expanded="false" aria-controls="siteContentMarkdownHelpPanel">
                            Markdown Help
                        </button>
                    </h2>
                    <div id="siteContentMarkdownHelpPanel" class="accordion-collapse collapse" aria-labelledby="siteContentMarkdownHeading" data-bs-parent="#siteContentSidebarTools">
                        <div class="accordion-body px-0 pt-0">
                            <ul class="small text-muted mb-0 ps-3">
                                <?php foreach ($markdownHelpLines as $line): ?>
                                    <li><code><?= htmlspecialchars($line) ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="accordion-item border-0">
                    <h2 class="accordion-header" id="siteContentTokensHeading">
                        <button class="accordion-button collapsed px-0 py-2 shadow-none bg-transparent" type="button" data-bs-toggle="collapse" data-bs-target="#siteContentTokensPanel" aria-expanded="false" aria-controls="siteContentTokensPanel">
                            Available Tokens
                        </button>
                    </h2>
                    <div id="siteContentTokensPanel" class="accordion-collapse collapse" aria-labelledby="siteContentTokensHeading" data-bs-parent="#siteContentSidebarTools">
                        <div class="accordion-body px-0 pt-0">
                            <p class="text-muted small">Use these inside markdown or text fields when you want live package or site values to flow through.</p>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($availableTokens as $token): ?>
                                    <button type="button" class="badge text-bg-light border site-content-token-chip" data-copy-text="<?= htmlspecialchars($token) ?>"><?= htmlspecialchars($token) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($themeWarnings)): ?>
                    <div class="accordion-item border-0">
                        <h2 class="accordion-header" id="siteContentThemeHeading">
                            <button class="accordion-button collapsed px-0 py-2 shadow-none bg-transparent" type="button" data-bs-toggle="collapse" data-bs-target="#siteContentThemePanel" aria-expanded="false" aria-controls="siteContentThemePanel">
                                Theme Notes
                            </button>
                        </h2>
                        <div id="siteContentThemePanel" class="accordion-collapse collapse" aria-labelledby="siteContentThemeHeading" data-bs-parent="#siteContentSidebarTools">
                            <div class="accordion-body px-0 pt-0">
                                <div class="small text-muted">
                                    <?php foreach ($themeWarnings as $warning): ?>
                                        <div class="mb-3">
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars((string)$warning['label']) ?></div>
                                            <div>Custom override detected at <code><?= htmlspecialchars((string)$warning['path']) ?></code>.</div>
                                            <div>This page may ignore Site Content until the override calls <code>\App\Service\SiteContentService::page(...)</code>.</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php renderAdminCardEnd(); ?>
    </div>

    <div class="col-xl-6">
        <form method="POST" action="/admin/site-content/save" id="siteContentForm">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="page_key" value="<?= htmlspecialchars($activePageKey) ?>">
            <input type="hidden" name="locale" value="<?= htmlspecialchars($activeLocale) ?>">

            <?php foreach ($activeBlocks as $blockKey => $blockDef): ?>
                <?php
                $blockValue = $pageContent[$blockKey] ?? [];
                $anchorId = 'block-' . $blockKey;
                $blockTitle = (string)($blockDef['label'] ?? ucfirst((string)$blockKey));
                $blockStats = siteContentBlockStatLine((array)$blockDef, $blockValue);
                renderAdminCardStart($blockTitle, ['cardClass' => 'card border-0 shadow-sm mb-4']);
                $blockHint = siteContentBlockHint((string)$activePageKey, (string)$blockKey);
                ?>
                    <div id="<?= htmlspecialchars($anchorId) ?>"></div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="site-content-block-stat"><?= htmlspecialchars($blockStats) ?></span>
                    </div>
                    <?php if ($blockHint !== null && $blockHint !== ''): ?>
                        <p class="small text-muted mb-3 site-content-soft-note"><?= htmlspecialchars($blockHint) ?></p>
                    <?php endif; ?>
                    <?php if (($blockDef['type'] ?? 'object') === 'list'): ?>
                        <div class="site-content-list" data-block-key="<?= htmlspecialchars((string)$blockKey) ?>" data-template-id="template-<?= htmlspecialchars((string)$blockKey) ?>">
                            <?php foreach ((array)$blockValue as $index => $item): ?>
                                <div class="card border mb-3 site-content-item">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="fw-semibold"><?= htmlspecialchars((string)($blockDef['item_label'] ?? 'Item')) ?> <span class="site-content-item-number"><?= (int)$index + 1 ?></span></div>
                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-secondary site-content-move-up">Up</button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary site-content-move-down">Down</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger site-content-remove-item">Remove</button>
                                            </div>
                                        </div>
                                        <?php foreach ($blockDef['item_fields'] as $fieldKey => $fieldDef): ?>
                                            <?php
                                            $fieldName = 'blocks[' . $blockKey . '][' . $index . '][' . $fieldKey . ']';
                                            siteContentFieldInput($fieldName, $fieldDef, $item[$fieldKey] ?? '');
                                            ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <template id="template-<?= htmlspecialchars((string)$blockKey) ?>">
                            <div class="card border mb-3 site-content-item">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div class="fw-semibold"><?= htmlspecialchars((string)($blockDef['item_label'] ?? 'Item')) ?> <span class="site-content-item-number"></span></div>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary site-content-move-up">Up</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary site-content-move-down">Down</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger site-content-remove-item">Remove</button>
                                        </div>
                                    </div>
                                    <?php foreach ($blockDef['item_fields'] as $fieldKey => $fieldDef): ?>
                                        <div class="mb-3">
                                            <?php if (($fieldDef['type'] ?? 'text') !== 'hidden'): ?>
                                                <label class="form-label fw-semibold"><?= htmlspecialchars((string)($fieldDef['label'] ?? 'Field')) ?></label>
                                            <?php endif; ?>
                                            <?php if (($fieldDef['type'] ?? 'text') === 'hidden'): ?>
                                                <input type="hidden" data-field="<?= htmlspecialchars((string)$fieldKey) ?>">
                                            <?php elseif (($fieldDef['type'] ?? 'text') === 'markdown'): ?>
                                                <textarea class="form-control site-content-markdown" rows="4" data-field="<?= htmlspecialchars((string)$fieldKey) ?>"></textarea>
                                            <?php elseif (($fieldDef['type'] ?? 'text') === 'select'): ?>
                                                <select class="form-select" data-field="<?= htmlspecialchars((string)$fieldKey) ?>">
                                                    <?php foreach ((array)($fieldDef['options'] ?? []) as $optionValue => $optionLabel): ?>
                                                        <option value="<?= htmlspecialchars((string)$optionValue) ?>"><?= htmlspecialchars((string)$optionLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php elseif (($fieldDef['type'] ?? 'text') === 'url'): ?>
                                                <input type="url" class="form-control" data-field="<?= htmlspecialchars((string)$fieldKey) ?>" placeholder="/faq or https://example.com">
                                            <?php else: ?>
                                                <input type="text" class="form-control" data-field="<?= htmlspecialchars((string)$fieldKey) ?>">
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </template>
                        <button type="button" class="btn btn-outline-primary site-content-add-item" data-block-key="<?= htmlspecialchars((string)$blockKey) ?>">Add <?= htmlspecialchars((string)($blockDef['item_label'] ?? 'Item')) ?></button>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($blockDef['fields'] as $fieldKey => $fieldDef): ?>
                                <div class="col-12">
                                    <?php
                                    $fieldName = 'blocks[' . $blockKey . '][' . $fieldKey . ']';
                                    siteContentFieldInput($fieldName, $fieldDef, $blockValue[$fieldKey] ?? '');
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php renderAdminCardEnd(); ?>
            <?php endforeach; ?>

            <div class="position-sticky bottom-0 bg-white border rounded shadow-sm p-3 d-flex flex-wrap align-items-center justify-content-between gap-3 site-content-savebar">
                <div class="small text-muted">
                    Editing <strong><?= htmlspecialchars((string)$pageDefinition['label']) ?></strong> for <code><?= htmlspecialchars((string)$activeRoute) ?></code> in locale <code><?= htmlspecialchars($activeLocale) ?></code>. Preview links open the live page with a temporary token and do not publish changes.
                    <span class="site-content-dirty-indicator ms-2" id="siteContentDirtyIndicator">
                        <span class="site-content-dirty-indicator-dot"></span>
                        Unsaved changes
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-danger" name="reset_page" value="1" data-confirm-message="Reset this page to the built-in defaults? This clears current overrides and saves a new revision.">Reset To Default</button>
                    <button type="submit" class="btn btn-outline-primary" formaction="/admin/site-content/preview">Generate Preview Link</button>
                    <button type="submit" class="btn btn-primary">Save Live Changes</button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-xl-3">
        <?php if (is_array($selectedRevision)): ?>
            <?php renderAdminCardStart('Revision Details', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
                <div class="small text-muted mb-2">
                    Revision #<?= (int)($selectedRevision['id'] ?? 0) ?> for <code><?= htmlspecialchars((string)($selectedRevision['locale'] ?? $activeLocale)) ?></code>
                </div>
                <div class="mb-3">
                    <div class="fw-semibold"><?= htmlspecialchars(ucfirst((string)($selectedRevision['change_reason'] ?? 'save'))) ?></div>
                    <div class="small text-muted">
                        <?= htmlspecialchars((string)($selectedRevision['created_at'] ?? '')) ?>
                        <?php if (!empty($selectedRevision['username'])): ?>
                            by <?= htmlspecialchars((string)$selectedRevision['username']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $summary = (array)($selectedRevision['summary'] ?? []); ?>
                <div class="small text-muted mb-3">
                    <div><strong><?= (int)($summary['block_count'] ?? 0) ?></strong> blocks</div>
                    <div><strong><?= (int)($summary['list_item_count'] ?? 0) ?></strong> list items</div>
                    <div><strong><?= (int)($summary['non_empty_field_count'] ?? 0) ?></strong> populated fields</div>
                </div>
                <?php $diffs = (array)($selectedRevision['diff_against_current'] ?? []); ?>
                <?php if ($diffs === []): ?>
                    <div class="small text-muted">This revision matches the current live snapshot for this locale.</div>
                <?php else: ?>
                    <div class="small text-muted mb-2">Compared with the current live snapshot:</div>
                    <ul class="small ps-3 mb-0">
                        <?php foreach ($diffs as $diff): ?>
                            <li><strong><?= htmlspecialchars((string)($diff['label'] ?? $diff['block_key'] ?? 'Block')) ?>:</strong> <?= htmlspecialchars((string)($diff['summary'] ?? 'Changed')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php renderAdminCardEnd(); ?>
        <?php endif; ?>

        <?php renderAdminCardStart('Revision History', ['cardClass' => 'card border-0 shadow-sm mb-4']); ?>
            <?php if (!empty($pageRevisions)): ?>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary active site-content-revision-filter" data-filter="all">All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary site-content-revision-filter" data-filter="save">Saves</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary site-content-revision-filter" data-filter="restore">Restores</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary site-content-revision-filter" data-filter="import">Imports</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary site-content-revision-filter" data-filter="reset">Resets</button>
                </div>
            <?php endif; ?>
            <?php if (empty($pageRevisions)): ?>
                <p class="text-muted small mb-0">No revisions yet for this page.</p>
            <?php else: ?>
                <div class="d-grid gap-2">
                    <?php foreach ($pageRevisions as $revision): ?>
                        <div class="border rounded p-2 site-content-revision-row" data-revision-reason="<?= htmlspecialchars(strtolower((string)($revision['change_reason'] ?? 'save'))) ?>">
                            <div class="fw-semibold small mb-1"><?= htmlspecialchars(ucfirst((string)($revision['change_reason'] ?? 'save'))) ?></div>
                            <div class="small text-muted mb-1">
                                <?= htmlspecialchars((string)($revision['created_at'] ?? '')) ?>
                                <?php if (!empty($revision['username'])): ?>
                                    by <?= htmlspecialchars((string)$revision['username']) ?>
                                <?php endif; ?>
                            </div>
                            <?php $revisionSummary = (array)($revision['summary'] ?? []); ?>
                            <div class="small text-muted mb-2">
                                <?= (int)($revisionSummary['block_count'] ?? 0) ?> blocks, <?= (int)($revisionSummary['list_item_count'] ?? 0) ?> list items, <?= (int)($revisionSummary['non_empty_field_count'] ?? 0) ?> populated fields
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="/admin/site-content?page=<?= urlencode($activePageKey) ?>&locale=<?= urlencode($activeLocale) ?>&revision=<?= (int)($revision['id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">View Details</a>
                                <form method="POST" action="/admin/site-content/restore" data-confirm-message="Restore this revision? This will save the restored state as a new revision." class="d-inline">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="page_key" value="<?= htmlspecialchars($activePageKey) ?>">
                                    <input type="hidden" name="locale" value="<?= htmlspecialchars($activeLocale) ?>">
                                    <input type="hidden" name="revision_id" value="<?= (int)($revision['id'] ?? 0) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-dark">Restore This Revision</button>
        </form>
    </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php renderAdminCardEnd(); ?>

        <?php renderAdminCardStart('Preview Behavior', ['cardClass' => 'card border-0 shadow-sm']); ?>
            <ul class="small text-muted mb-0 ps-3 site-content-low-priority">
                <li>Preview links stay valid for about <?= (int)$previewTtlMinutes ?> minutes.</li>
                <li>Preview tokens only work for the same admin session that created them.</li>
                <li>Preview pages are marked <code>noindex</code> so search engines should ignore them.</li>
                <li>Restore creates a new revision entry instead of rewriting history.</li>
            </ul>
        <?php renderAdminCardEnd(); ?>
    </div>
</div>

<style>
.site-content-markdown{font-family:Consolas,Monaco,monospace;min-height:120px}
.site-content-savebar{z-index:10;bottom:1rem}
.site-content-savebar .btn{white-space:nowrap}
.site-content-item .btn{white-space:nowrap}
.site-content-summary-chip{background:#f8fafc;border:1px solid #dbe4f0;border-radius:14px;padding:0.9rem 1rem;height:100%}
.site-content-summary-label{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.3rem}
.site-content-soft-note{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.75rem .9rem}
.site-content-soft-note-warning{background:#fff7ed;border-color:#fdba74;color:#9a3412}
.site-content-block-stat{display:inline-flex;align-items:center;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:999px;padding:.2rem .55rem;font-size:.78rem;font-weight:600}
.site-content-token-chip{cursor:pointer;background:#fff;padding:.35rem .55rem}
.site-content-token-chip:hover{background:#eff6ff}
.site-content-revision-row{background:#fff}
.site-content-low-priority{opacity:.9}
.site-content-block-nav .list-group-item{padding:.7rem .8rem}
.site-content-block-nav .list-group-item.active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.site-content-markdown-help-link{font-size:.8rem}
.site-content-dirty-indicator{display:none;align-items:center;gap:.4rem;font-weight:600;color:#9a3412}
.site-content-dirty-indicator.is-visible{display:inline-flex}
.site-content-dirty-indicator-dot{width:.55rem;height:.55rem;border-radius:50%;background:#f59e0b;display:inline-block}
@media (max-width: 991.98px){
    .site-content-action-bar .btn{flex:1 1 calc(50% - .5rem)}
    .site-content-savebar{position:static!important}
    .site-content-savebar .d-flex{width:100%;flex-wrap:wrap}
    .site-content-savebar .btn{flex:1 1 100%}
}
@media (max-width: 575.98px){
    .site-content-action-bar .btn{flex:1 1 100%}
}
</style>

<script>
document.addEventListener('click', function(event) {
    const addButton = event.target.closest('.site-content-add-item');
    if (addButton) {
        const blockKey = addButton.getAttribute('data-block-key');
        const list = document.querySelector('.site-content-list[data-block-key="' + blockKey + '"]');
        const template = document.getElementById('template-' + blockKey);
        if (!list || !template) return;

        const clone = template.content.firstElementChild.cloneNode(true);
        const index = list.querySelectorAll('.site-content-item').length;
        clone.querySelectorAll('[data-field]').forEach(function(field) {
            const key = field.getAttribute('data-field');
            const name = 'blocks[' + blockKey + '][' + index + '][' + key + ']';
            field.setAttribute('name', name);
            if (key === 'id') {
                field.value = '';
            }
        });
        const number = clone.querySelector('.site-content-item-number');
        if (number) number.textContent = String(index + 1);
        list.appendChild(clone);
        return;
    }

    const removeButton = event.target.closest('.site-content-remove-item');
    if (removeButton) {
        const item = removeButton.closest('.site-content-item');
        const list = removeButton.closest('.site-content-list');
        if (item && list) {
            item.remove();
            renumberSiteContentList(list);
        }
        return;
    }

    const moveUpButton = event.target.closest('.site-content-move-up');
    if (moveUpButton) {
        const item = moveUpButton.closest('.site-content-item');
        const list = moveUpButton.closest('.site-content-list');
        if (item && list && item.previousElementSibling) {
            list.insertBefore(item, item.previousElementSibling);
            renumberSiteContentList(list);
        }
        return;
    }

    const moveDownButton = event.target.closest('.site-content-move-down');
    if (moveDownButton) {
        const item = moveDownButton.closest('.site-content-item');
        const list = moveDownButton.closest('.site-content-list');
        if (item && list && item.nextElementSibling) {
            list.insertBefore(item.nextElementSibling, item);
            renumberSiteContentList(list);
        }
        return;
    }

    const tokenButton = event.target.closest('.site-content-token-chip');
    if (tokenButton) {
        const token = tokenButton.getAttribute('data-copy-text') || '';
        if (!token) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(token).then(function() {
                tokenButton.textContent = 'Copied';
                window.setTimeout(function() {
                    tokenButton.textContent = token;
                }, 900);
            }).catch(function() {});
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('siteContentForm');
    const dirtyIndicator = document.getElementById('siteContentDirtyIndicator');
    const revisionFilterButtons = Array.from(document.querySelectorAll('.site-content-revision-filter'));
    const revisionRows = Array.from(document.querySelectorAll('.site-content-revision-row'));
    const navLinks = Array.from(document.querySelectorAll('[data-block-nav-link]'));
    const blockAnchors = Array.from(document.querySelectorAll('[id^="block-"]'));
    let dirty = false;

    function setDirtyState(nextDirty) {
        dirty = nextDirty;
        if (dirtyIndicator) {
            dirtyIndicator.classList.toggle('is-visible', dirty);
        }
    }

    if (form) {
        form.addEventListener('input', function() {
            if (!dirty) setDirtyState(true);
        });
        form.addEventListener('change', function() {
            if (!dirty) setDirtyState(true);
        });
        form.addEventListener('submit', function() {
            setDirtyState(false);
        });
    }

    revisionFilterButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const filter = button.getAttribute('data-filter') || 'all';
            revisionFilterButtons.forEach(function(item) {
                item.classList.toggle('active', item === button);
            });
            revisionRows.forEach(function(row) {
                const reason = row.getAttribute('data-revision-reason') || '';
                const show = filter === 'all' || reason === filter;
                row.style.display = show ? '' : 'none';
            });
        });
    });

    function setActiveBlockLink(blockId) {
        navLinks.forEach(function(link) {
            const isActive = link.getAttribute('data-block-nav-link') === blockId;
            link.classList.toggle('active', isActive);
        });
    }

    if ('IntersectionObserver' in window && blockAnchors.length > 0 && navLinks.length > 0) {
        const observer = new IntersectionObserver(function(entries) {
            const visible = entries
                .filter(function(entry) { return entry.isIntersecting; })
                .sort(function(a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });
            if (visible.length > 0) {
                setActiveBlockLink(visible[0].target.id);
            }
        }, {
            rootMargin: '-20% 0px -65% 0px',
            threshold: [0, 1]
        });
        blockAnchors.forEach(function(anchor) {
            observer.observe(anchor);
        });
    }
});

function renumberSiteContentList(list) {
    const blockKey = list.getAttribute('data-block-key');
    list.querySelectorAll('.site-content-item').forEach(function(item, index) {
        const number = item.querySelector('.site-content-item-number');
        if (number) number.textContent = String(index + 1);
        item.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(field) {
            const match = field.name.match(/^blocks\[[^\]]+\]\[\d+\]\[([^\]]+)\]$/);
            if (!match) return;
            field.name = 'blocks[' + blockKey + '][' + index + '][' + match[1] + ']';
        });
    });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
