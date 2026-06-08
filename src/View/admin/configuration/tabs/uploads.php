<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Upload Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#upload-transfer"><i class="bi bi-arrow-left-right me-2"></i> Transfer Behavior</a>
            <a class="nav-link text-start" href="#upload-rules"><i class="bi bi-sliders me-2"></i> Upload Rules</a>
            <a class="nav-link text-start" href="#upload-download-page"><i class="bi bi-plus-square me-2"></i> Download Page Actions</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">Uploads</h5>
                <p class="config-section-intro__text">Shape how browser uploads are chunked, how duplicates are handled, and which extra actions signed-in users can use from public download pages.</p>
            </div>
            <ul class="config-summary-chips">
                <li class="config-summary-chip <?= ($uploadChunkingEnabled === '1') ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Chunking: <?= ($uploadChunkingEnabled === '1') ? 'Enabled' : 'Disabled' ?></li>
                <li class="config-summary-chip <?= ($uploadDetectDuplicates === '1') ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Dedup: <?= ($uploadDetectDuplicates === '1') ? 'On' : 'Off' ?></li>
                <li class="config-summary-chip <?= ($uploadReplaceEnabled === '1') ? 'config-summary-chip--info' : 'config-summary-chip--warning' ?>">Replace file: <?= ($uploadReplaceEnabled === '1') ? 'On' : 'Off' ?></li>
                <li class="config-summary-chip <?= ($downloadPageSaveFree === '1' || $downloadPageSavePremium === '1' || $downloadPageSaveAdmin === '1') ? 'config-summary-chip--info' : 'config-summary-chip--warning' ?>">Download-page save: <?= ($downloadPageSaveFree === '1' || $downloadPageSavePremium === '1' || $downloadPageSaveAdmin === '1') ? 'Available' : 'Off' ?></li>
            </ul>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>These settings directly affect ingest speed, server load, duplicate handling, and how much physical storage each upload consumes. If you are tuning a large install, start with chunking, concurrency, and deduplication before changing the account-facing upload rules.</p>
            </div>
        </details>

        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="uploads">

    <div id="upload-transfer"></div>
    <?php renderAdminCardStart('Transfer Behavior', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_concurrent" id="upConcurrent" value="1" <?= ($uploadConcurrent === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upConcurrent">Synchronous Uploads</label>
            </div>
            <small class="config-form-note">Process multiple file segments simultaneously to speed up uploads.</small>
        </div>
        <div class="col-md-6 mb-4">
            <label class="form-label fw-bold">Max Concurrent Threads</label>
            <input type="number" class="form-control" name="upload_concurrent_limit" value="<?= htmlspecialchars($uploadConcurrentLimit) ?>" min="1">
            <small class="config-form-note">Default is 2. High values may increase server CPU usage.</small>
        </div>
    </div>

    <hr class="my-4">

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_chunking_enabled" id="upChunkEnabled" value="1" <?= ($uploadChunkingEnabled === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upChunkEnabled">Enable Chunked Uploads</label>
            </div>
            <small class="config-form-note">Splits browser uploads into smaller parts. If disabled, multipart browser uploads are blocked until it is turned back on.</small>
        </div>
        <div class="col-md-6 mb-4">
            <label class="form-label fw-bold">Chunk Size (MB)</label>
            <input type="number" class="form-control" name="upload_chunk_size_mb" value="<?= htmlspecialchars($uploadChunkSizeMb) ?>" min="1">
            <small class="config-form-note">Recommended: 10MB to 50MB for most environments.</small>
        </div>
    </div>
    <?php renderAdminCardEnd(); ?>

    <?php renderAdminCardStart('Deduplication', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="config-soft-callout config-soft-callout--info small mb-4">
        <div class="fw-bold mb-2">How deduplication works here</div>
        <div class="mb-2">When deduplication is enabled, Fyuhls detects identical content and reuses the existing stored object instead of keeping a second physical copy.</div>
        <div class="mb-2">When deduplication is disabled, identical uploads are stored as separate physical objects across classic uploads, multipart/API uploads, remote uploads, and download-page save actions.</div>
        <div>Logical files still count toward the uploader's own storage quota in both modes. The difference is whether identical content reuses one stored object or creates a second one.</div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_detect_duplicates" id="upDetectDup" value="1" <?= ($uploadDetectDuplicates === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upDetectDup">Enable Deduplication</label>
            </div>
            <small class="config-form-note d-block">When enabled, identical content reuses an existing stored object. When disabled, identical content is stored as a separate physical object.</small>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="config-soft-callout config-soft-callout--warning small h-100">
                <div class="fw-bold mb-2">What this switch still does not change</div>
                <div>It does not change quota accounting. Users are still charged for their own logical files either way; the switch only changes whether identical content reuses one stored object or creates another.</div>
            </div>
        </div>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div id="upload-rules"></div>
    <?php renderAdminCardStart('Upload Rules', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="mb-4">
        <label class="form-label fw-bold">Allowed File Extensions</label>
        <input type="text" class="form-control" name="upload_allowed_extensions" value="<?= htmlspecialchars($uploadAllowedExtensions) ?>" placeholder="jpg,jpeg,zip,mp4">
        <small class="config-form-note">Comma-separated list of extensions. (Empty = Allow all, not recommended).</small>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_login_required" id="upLoginReq" value="1" <?= ($uploadLoginRequired === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upLoginReq">Login Required</label>
            </div>
            <small class="config-form-note">Only signed-in users can upload when this is enabled. New installs default to On.</small>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_hide_popup" id="upHidePopup" value="1" <?= ($uploadHidePopup === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upHidePopup">Hide Upload Popup</label>
            </div>
            <small class="config-form-note">Uploads still run, but the progress panel stays collapsed by default.</small>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_append_filename" id="upAppendName" value="1" <?= ($uploadAppendFilename === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upAppendName">Original Name in URL</label>
            </div>
            <small class="config-form-note">Adds the original filename to generated links for readability. New installs default to On.</small>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_replace_enabled" id="upReplaceEnabled" value="1" <?= ($uploadReplaceEnabled === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upReplaceEnabled">Replace File In Place</label>
            </div>
            <small class="config-form-note d-block">Lets signed-in users replace the contents of one of their files while keeping the same file page URL. Default is Off.</small>
        </div>
        <div class="col-md-4 mb-3">
            <div class="config-soft-callout config-soft-callout--warning small h-100">
                <div class="fw-bold mb-2">Replace file risk</div>
                <div>Recommended for single-uploader or tightly controlled sites. A user can keep a trusted public URL and later replace the file with malware or other unsafe content while preserving that same link.</div>
            </div>
        </div>
    </div>

    <div class="config-soft-callout config-soft-callout--info small mb-4">
        <div class="fw-bold mb-2">URL examples</div>
        <div>Without original name: <code>https://your-site.example/file/AbC123xyZ9</code></div>
        <div>With original name: <code>https://your-site.example/file/AbC123xyZ9/report.pdf</code></div>
    </div>
    <div class="config-soft-callout config-soft-callout--info small mb-4">
        <div class="fw-bold mb-2">Replace file in place</div>
        <div>When enabled, users can choose a file from their dashboard and upload a new version without changing that file's existing public link. The file record stays the same; only the stored object behind it changes.</div>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div id="upload-download-page"></div>
    <?php renderAdminCardStart('Download Page Actions', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
    <div class="config-soft-callout config-soft-callout--info small mb-4">
        <div class="fw-bold mb-2">Download Page Actions</div>
        <div class="mb-2">Control whether signed-in visitors can use the download-page <code>+</code> action to add a file into their own account without re-uploading it.</div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="download_page_save_free" id="downloadPageSaveFree" value="1" <?= ($downloadPageSaveFree === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="downloadPageSaveFree">Allow for Free Users</label>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="download_page_save_premium" id="downloadPageSavePremium" value="1" <?= ($downloadPageSavePremium === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="downloadPageSavePremium">Allow for Premium Users</label>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="download_page_save_admin" id="downloadPageSaveAdmin" value="1" <?= ($downloadPageSaveAdmin === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="downloadPageSaveAdmin">Allow for Admin Users</label>
                </div>
            </div>
        </div>
        <small class="config-form-note">Saved copies still count against the saver's storage quota. With deduplication on, Fyuhls reuses the existing stored object. With deduplication off, Fyuhls creates a separate stored object for the saved copy.</small>
    </div>
    <?php renderAdminCardEnd(); ?>

    <div class="config-sticky-save">
        <p class="config-sticky-save__text">Upload changes affect browser ingest behavior immediately and can change storage pressure across the whole install.</p>
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-save me-2"></i> Save Upload Configuration
        </button>
    </div>
</form>
    </div>
</div>
