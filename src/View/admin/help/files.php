<div class="p-1">
    <p class="guide-purpose mb-4">The Files page is the admin-side inventory of hosted files. Use it to inspect ownership, confirm storage placement, and queue removals.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What You Can Do Here</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Filename search:</strong> The admin file filter now supports partial filename matches as well as exact lookups.</li>
        <li class="mb-2"><strong>Review ownership:</strong> The owner column helps you trace who uploaded a file before taking action.</li>
        <li class="mb-2"><strong>Review storage placement:</strong> The server column tells you which file server currently holds the stored file.</li>
        <li><strong>Delete:</strong> This does not hard-delete immediately. It marks the file for background purge so physical deletion can be handled safely by cron.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What This Page Does Not Do</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>No bulk tools:</strong> The current admin file list is single-action only.</li>
        <li><strong>No file edit screen:</strong> There is no separate admin file editor in this build. Use the list filter and the public file page link when you need to inspect a specific file.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Important:</strong> If cron is not running, files marked for purge can remain on disk longer than expected even though they are already hidden from normal use.
    </div>
</div>
