<style>
    .download-page-shell{display:flex;justify-content:center;align-items:center;flex:1;padding:2rem;gap:2rem;max-width:1400px;margin:0 auto;width:100%}
    .download-page-sidebar{flex:0 0 300px;max-width:300px;display:none;align-self:center}
    .download-page-sidebar-card{background:#f1f5f9;padding:1rem;border-radius:8px;text-align:center;overflow-wrap:anywhere;word-break:break-all}
    .download-page-center{flex:1 1 auto;max-width:560px;min-width:0;width:100%}
    .download-page-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;box-sizing:border-box}
    .download-page-top-ad,.download-page-bottom-ad{background:#f1f5f9;padding:.75rem;text-align:center;border-radius:8px;overflow-wrap:anywhere;word-break:break-all}
    .download-page-top-ad{margin-bottom:1.5rem}
    .download-page-bottom-ad{margin-top:1.5rem}
    .download-page-title{font-size:1.18rem;font-weight:700;line-height:1.28;margin:0;overflow-wrap:anywhere;word-break:break-all}
    .download-page-meta{color:#64748b;font-size:.875rem;margin:0}
    .download-file-bar{display:flex;align-items:flex-start;gap:1rem;margin-bottom:1.5rem;padding:1rem;border:1px solid #dbe4f0;border-radius:14px;background:#f8fafc}
    .download-file-bar__icon{flex:0 0 56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.5rem;font-weight:700}
    .download-file-bar__body{flex:1;min-width:0;display:flex;flex-direction:column;gap:.55rem}
    .download-file-bar__meta{display:flex;flex-wrap:wrap;gap:.8rem;font-size:.875rem;color:#64748b}
    .download-file-bar__meta-item{display:inline-flex;align-items:center;gap:.35rem}
    .download-state-panel{padding:1.25rem 1.1rem;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
    .download-state-copy{color:#64748b;font-size:.95rem;margin:0;line-height:1.7;text-align:center}
    .download-share-panel{margin-top:1.5rem;padding:1rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}
    .download-share-heading{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 .75rem}
    .download-share-row{margin-bottom:.875rem}
    .download-share-row:last-child{margin-bottom:0}
    .download-share-label{display:block;font-size:.85rem;font-weight:600;color:#334155;margin-bottom:.4rem}
    .download-share-control{display:flex;gap:.5rem;align-items:stretch}
    .download-share-input{flex:1;min-width:0;padding:.75rem .875rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;font-size:.88rem}
    .download-share-copy{padding:.75rem 1rem;border:0;border-radius:8px;background:#e2e8f0;color:#0f172a;font-weight:600;cursor:pointer;white-space:nowrap}
    .download-share-copy:hover{background:#cbd5e1}
    .download-share-toggle{margin-top:.25rem;padding:0;background:none;border:0;color:#2563eb;font-size:.875rem;font-weight:600;cursor:pointer}
    .download-share-toggle:hover{text-decoration:underline}
    .download-share-extra{margin-top:.875rem;padding-top:.875rem;border-top:1px solid #e2e8f0}
    @media (min-width: 1100px){.download-page-sidebar{display:block}}
    @media (max-width: 640px){.download-share-control{flex-direction:column}.download-share-copy{width:100%}}
</style>

<div class="download-page-shell">
    <?php if (($adLeft ?? '') !== ''): ?>
    <div class="download-page-sidebar">
        <div class="download-page-sidebar-card"><?= $adLeft ?></div>
    </div>
    <?php endif; ?>

    <div class="download-page-center">
        <?php if (($adTop ?? '') !== ''): ?>
        <div class="download-page-top-ad"><?= $adTop ?></div>
        <?php endif; ?>

        <div class="download-page-card">
            <?php if (!empty($file)): ?>
            <div class="download-file-bar">
                <div class="download-file-bar__icon" aria-hidden="true">&#8681;</div>
                <div class="download-file-bar__body">
                    <h1 class="download-page-title"><?= htmlspecialchars((string)($file['filename'] ?? $heading)) ?></h1>
                    <div class="download-file-bar__meta">
                        <span class="download-file-bar__meta-item"><?= round(((int)($file['file_size'] ?? 0)) / 1024 / 1024, 2) ?> MB</span>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <h1 class="download-page-title"><?= htmlspecialchars((string)$heading) ?></h1>
            <p class="download-page-meta">&nbsp;</p>
            <?php endif; ?>

            <div class="download-state-panel">
                <p class="download-state-copy"><?= htmlspecialchars((string)$message) ?></p>
            </div>
            <?php \App\Core\View::render('home/partials/download_share_panel.php', ['shareFields' => $shareFields ?? []]); ?>
        </div>

        <?php if (($adBottom ?? '') !== ''): ?>
        <div class="download-page-bottom-ad"><?= $adBottom ?></div>
        <?php endif; ?>
    </div>

    <?php if (($adRight ?? '') !== ''): ?>
    <div class="download-page-sidebar">
        <div class="download-page-sidebar-card"><?= $adRight ?></div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($shareFields)): ?>
<script>
document.querySelectorAll("[data-copy-target]").forEach(function(button) {
    button.addEventListener("click", async function() {
        const target = document.getElementById(button.getAttribute("data-copy-target"));
        if (!target) {
            return;
        }
        try {
            await navigator.clipboard.writeText(target.value);
            const original = button.textContent;
            button.textContent = "Copied";
            setTimeout(function() {
                button.textContent = original;
            }, 1400);
        } catch (err) {
            target.focus();
            target.select();
        }
    });
});
document.querySelectorAll("[data-share-toggle]").forEach(function(button) {
    button.addEventListener("click", function() {
        const target = document.getElementById(button.getAttribute("data-share-toggle"));
        if (!target) {
            return;
        }
        const expanded = button.getAttribute("aria-expanded") === "true";
        target.hidden = expanded;
        button.setAttribute("aria-expanded", expanded ? "false" : "true");
        button.textContent = expanded ? "More share options" : "Fewer share options";
    });
});
</script>
<?php endif; ?>
