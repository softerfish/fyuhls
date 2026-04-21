<div class="alert alert-info border-0 shadow-sm small mb-4">
    <i class="bi bi-info-circle me-2"></i> Manage how files are ingested into your storage network. These settings directly impact server load and user experience.
</div>

<form method="POST" action="/admin/configuration/save">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="section" value="uploads">

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_concurrent" id="upConcurrent" value="1" <?= ($uploadConcurrent === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upConcurrent">Synchronous Uploads</label>
            </div>
            <small class="text-muted">Process multiple file segments simultaneously to speed up uploads.</small>
        </div>
        <div class="col-md-6 mb-4">
            <label class="form-label fw-bold">Max Concurrent Threads</label>
            <input type="number" class="form-control" name="upload_concurrent_limit" value="<?= htmlspecialchars($uploadConcurrentLimit) ?>" min="1">
            <small class="text-muted">Default is 2. High values may increase server CPU usage.</small>
        </div>
    </div>

    <hr class="my-4">

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_chunking_enabled" id="upChunkEnabled" value="1" <?= ($uploadChunkingEnabled === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upChunkEnabled">Enable Chunked Uploads</label>
            </div>
            <small class="text-muted">Splits browser uploads into smaller parts. If disabled, multipart browser uploads are blocked until it is turned back on.</small>
        </div>
        <div class="col-md-6 mb-4">
            <label class="form-label fw-bold">Chunk Size (MB)</label>
            <input type="number" class="form-control" name="upload_chunk_size_mb" value="<?= htmlspecialchars($uploadChunkSizeMb) ?>" min="1">
            <small class="text-muted">Recommended: 10MB to 50MB for most environments.</small>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">Allowed File Extensions</label>
        <input type="text" class="form-control" name="upload_allowed_extensions" value="<?= htmlspecialchars($uploadAllowedExtensions) ?>" placeholder="jpg,jpeg,zip,mp4">
        <small class="text-muted">Comma-separated list of extensions. (Empty = Allow all, not recommended).</small>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_login_required" id="upLoginReq" value="1" <?= ($uploadLoginRequired === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upLoginReq">Login Required</label>
            </div>
            <small class="text-muted">Only signed-in users can upload when this is enabled. New installs default to On.</small>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_detect_duplicates" id="upDetectDup" value="1" <?= ($uploadDetectDuplicates === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upDetectDup">Deduplication</label>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_hide_popup" id="upHidePopup" value="1" <?= ($uploadHidePopup === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upHidePopup">Hide Upload Popup</label>
            </div>
            <small class="text-muted">Uploads still run, but the progress panel stays collapsed by default.</small>
        </div>
        <div class="col-md-4 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="upload_append_filename" id="upAppendName" value="1" <?= ($uploadAppendFilename === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="upAppendName">Original Name in URL</label>
            </div>
            <small class="text-muted">Adds the original filename to generated links for readability. New installs default to On.</small>
        </div>
    </div>

    <div class="alert alert-light border shadow-sm small mb-4">
        <div class="fw-bold mb-2">URL examples</div>
        <div>Without original name: <code>https://your-site.example/file/AbC123xyZ9</code></div>
        <div>With original name: <code>https://your-site.example/file/AbC123xyZ9/report.pdf</code></div>
    </div>

    <div class="alert alert-light border shadow-sm small mb-4">
        <div class="fw-bold mb-2">Download Page Actions</div>
        <div class="mb-2">Control whether signed-in visitors can use the download-page <code>+</code> action to add a deduplicated copy of a file into their own account without re-uploading it.</div>
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
        <small class="text-muted">Saved copies reuse the existing stored object through deduplication, but they still count against the saver's own storage quota like a normal duplicate upload.</small>
    </div>

    <div class="mt-4 pt-3 border-top">
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-save me-2"></i> Save Upload Configuration
        </button>
    </div>
</form>
