<?php
if (!function_exists('renderAdminPageHeader')) {
    function renderAdminPageHeader(string $title, string $description = '', string $actionsHtml = ''): void
    {
        ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1"><?= htmlspecialchars($title) ?></h1>
                <?php if ($description !== ''): ?>
                    <p class="text-muted mb-0"><?= htmlspecialchars($description) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($actionsHtml !== ''): ?>
                <div><?= $actionsHtml ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderAdminCardStart')) {
    function renderAdminCardStart(?string $title = null, array $options = []): void
    {
        $cardClass = trim((string)($options['cardClass'] ?? 'card border-0 shadow-sm'));
        $headerClass = trim((string)($options['headerClass'] ?? 'card-header bg-white'));
        $bodyClass = trim((string)($options['bodyClass'] ?? 'card-body'));
        $headerHtml = (string)($options['headerHtml'] ?? '');
        ?>
        <div class="<?= htmlspecialchars($cardClass) ?>">
            <?php if ($title !== null || $headerHtml !== ''): ?>
                <div class="<?= htmlspecialchars($headerClass) ?>">
                    <?php if ($title !== null): ?>
                        <div class="fw-semibold"><?= htmlspecialchars($title) ?></div>
                    <?php endif; ?>
                    <?= $headerHtml ?>
                </div>
            <?php endif; ?>
            <div class="<?= htmlspecialchars($bodyClass) ?>">
        <?php
    }
}

if (!function_exists('renderAdminCardEnd')) {
    function renderAdminCardEnd(): void
    {
        ?>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('renderAdminStatCard')) {
    function renderAdminStatCard(string $label, string $value, string $cardClass = '', string $valueClass = ''): void
    {
        $cardClass = trim('card border-0 shadow-sm h-100 ' . $cardClass);
        $valueClass = trim('fs-4 fw-bold ' . $valueClass);
        ?>
        <div class="<?= htmlspecialchars($cardClass) ?>">
            <div class="card-body">
                <div class="text-muted small"><?= htmlspecialchars($label) ?></div>
                <div class="<?= htmlspecialchars($valueClass) ?>"><?= $value ?></div>
            </div>
        </div>
        <?php
    }
}
