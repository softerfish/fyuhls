<?php
include __DIR__ . '/header.php';
include __DIR__ . '/partials/shell_helpers.php';
?>
<div id="top"></div>
<?php

ob_start();
?>
    <a href="/admin" class="btn btn-sm btn-outline-dark shadow-sm">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
<?php
$helpActions = ob_get_clean();
renderAdminPageHeader('Help & Docs', 'Browse the admin documentation index and jump straight to the section you need.', $helpActions);
?>

<?php
ob_start();
?>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($sections as $i => $section): ?>
            <a class="btn btn-sm btn-outline-primary" href="#s<?= (int)$i ?>">
                <?= htmlspecialchars($section['title']) ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php
$indexHeader = ob_get_clean();
renderAdminCardStart(null, ['headerHtml' => $indexHeader]);
?>
    <div class="help-sections-stack">
        <?php foreach ($sections as $i => $section): ?>
            <section id="s<?= (int)$i ?>" class="help-section-block<?= $i > 0 ? ' border-top pt-4 mt-4' : '' ?>">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                    <h5 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($section['title']) ?></h5>
                    <a href="#top" class="small text-decoration-none">Back to top</a>
                </div>
                <?= $section['html'] ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php renderAdminCardEnd(); ?>

<style>
    .help-sections-stack {
        scroll-margin-top: 90px;
    }

    .help-section-block {
        scroll-margin-top: 90px;
    }
</style>

<?php include __DIR__ . '/footer.php'; ?>
