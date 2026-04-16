<?php if (!empty($shareFields)): ?>
<?php
$primaryField = $shareFields[0];
$extraFields = array_slice($shareFields, 1);
$panelId = 'downloadSharePanel' . substr(md5(json_encode($shareFields)), 0, 8);
$extraId = $panelId . 'Extra';
$primaryInputId = $panelId . 'Primary';
?>
<div class="download-share-panel">
    <h2 class="download-share-heading">Share This File</h2>
    <div class="download-share-row download-share-row--primary">
        <label class="download-share-label" for="<?= htmlspecialchars($primaryInputId) ?>"><?= htmlspecialchars((string)$primaryField['label']) ?></label>
        <div class="download-share-control">
            <input type="text" readonly class="download-share-input" id="<?= htmlspecialchars($primaryInputId) ?>" value="<?= htmlspecialchars((string)$primaryField['value'], ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="download-share-copy" data-copy-target="<?= htmlspecialchars($primaryInputId) ?>">Copy</button>
        </div>
    </div>

    <?php if (!empty($extraFields)): ?>
    <button type="button" class="download-share-toggle" data-share-toggle="<?= htmlspecialchars($extraId) ?>" aria-expanded="false">
        More share options
    </button>
    <div class="download-share-extra" id="<?= htmlspecialchars($extraId) ?>" hidden>
        <?php foreach ($extraFields as $index => $field): ?>
        <?php $inputId = $panelId . 'Field' . $index; ?>
        <div class="download-share-row">
            <label class="download-share-label" for="<?= htmlspecialchars($inputId) ?>"><?= htmlspecialchars((string)$field['label']) ?></label>
            <div class="download-share-control">
                <input type="text" readonly class="download-share-input" id="<?= htmlspecialchars($inputId) ?>" value="<?= htmlspecialchars((string)$field['value'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" class="download-share-copy" data-copy-target="<?= htmlspecialchars($inputId) ?>">Copy</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
