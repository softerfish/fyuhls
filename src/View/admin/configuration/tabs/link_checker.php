<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Link Checker Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#link-checker-availability"><i class="bi bi-toggle-on me-2"></i> Availability</a>
            <a class="nav-link text-start" href="#link-checker-limits"><i class="bi bi-speedometer2 me-2"></i> Limits</a>
            <a class="nav-link text-start" href="#link-checker-actions"><i class="bi bi-person-plus me-2"></i> Account Actions</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">Link Checker</h5>
                <p class="config-section-intro__text">Control whether the public footer-linked checker is available, how heavily one visitor can use it, and whether signed-in users can pull eligible results into their own account.</p>
            </div>
            <ul class="config-summary-chips">
                <li class="config-summary-chip <?= (($linkCheckerEnabled ?? '1') === '1') ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Public page: <?= (($linkCheckerEnabled ?? '1') === '1') ? 'Enabled' : 'Disabled' ?></li>
                <li class="config-summary-chip config-summary-chip--info">Batch: <?= (int)($linkCheckerMaxLinks ?? 100) ?> links</li>
                <li class="config-summary-chip config-summary-chip--info">Rate: <?= (int)($linkCheckerLinksPerSecond ?? 25) ?>/sec/IP</li>
            </ul>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>Link Checker is a public utility page linked from the site footer when enabled. The hardened checker only reveals whether a local link is available or not available, without exposing private file metadata.</p>
            </div>
        </details>

<form method="POST" action="/admin/configuration/save">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="section" value="link_checker">

    <div id="link-checker-availability"></div>
    <?php renderAdminCardStart('Availability', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="link_checker_enabled" id="linkCheckerEnabled" value="1" <?= ($linkCheckerEnabled ?? '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="linkCheckerEnabled">Enable Public Link Checker</label>
        </div>
        <small class="config-form-note d-block mt-2">When disabled, the footer link is hidden and the <code>/link-checker</code> page stops responding publicly.</small>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div id="link-checker-limits"></div>
    <?php renderAdminCardStart('Batch Limits and Rate Limit', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="mb-4">
        <label class="form-label fw-bold">Maximum Links Per Check</label>
        <input type="number" class="form-control" name="link_checker_max_links" value="<?= htmlspecialchars($linkCheckerMaxLinks ?? '100') ?>" min="1" max="1000">
        <small class="config-form-note">Default is <strong>100</strong>. This is the maximum number of links a visitor can submit in one batch.</small>
    </div>
    <div class="mb-4">
        <label class="form-label fw-bold">Maximum Links Processed Per Second Per IP</label>
        <input type="number" class="form-control" name="link_checker_links_per_second" value="<?= htmlspecialchars($linkCheckerLinksPerSecond ?? '25') ?>" min="1" max="250">
        <small class="config-form-note">Default is <strong>25</strong>. This weighted rate limit is designed to stay friendly to shared hosting by capping how many total links a single IP can process each second.</small>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div id="link-checker-actions"></div>
    <?php renderAdminCardStart('Account Actions', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="mb-4">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="link_checker_allow_copy_to_account" id="linkCheckerAllowCopyToAccount" value="1" <?= ($linkCheckerAllowCopyToAccount ?? '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="linkCheckerAllowCopyToAccount">Allow Copy To Account From Link Checker</label>
        </div>
        <small class="config-form-note d-block mt-2">When enabled, signed-in users can select available public file links from the checker results and save those files into their own account. Existing download-page save rules, duplicate detection, and storage quota checks still apply.</small>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div class="config-sticky-save">
        <p class="config-sticky-save__text">These limits affect a public utility page, so they are worth tuning conservatively on shared hosting or during traffic spikes.</p>
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-save me-2"></i> Save Link Checker Settings
        </button>
    </div>
</form>
</div>
</div>
