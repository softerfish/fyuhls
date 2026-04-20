<div class="p-1">
    <p class="guide-purpose mb-4">The file manager is the main user workspace. Support questions about missing files, failed uploads, restore behavior, sharing, or folder organization often start there.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What To Know First</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Search and filters:</strong> Many “missing file” reports are really search, filter, or sort issues before they are storage issues.</li>
        <li class="mb-2"><strong>Trash is soft delete:</strong> Move to Trash and permanent delete are separate workflows. Check trash before assuming data is gone.</li>
        <li class="mb-2"><strong>Grid vs list:</strong> Layout complaints can simply be users not realizing the view toggle exists.</li>
        <li><strong>Quota indicators:</strong> Upload failures often trace back to package limits, quota, or storage pressure rather than the file manager UI itself.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Support Checklist</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Check the user’s current view:</strong> Folder, search, and filters first.</li>
        <li><strong>Check trash behavior:</strong> If something “disappeared,” verify whether it was soft-deleted.</li>
        <li><strong>Check package and storage limits:</strong> Many upload problems are entitlement or quota issues.</li>
        <li><strong>Check background health:</strong> Multipart uploads and remote imports depend on healthy cron and storage state.</li>
    </ol>

    <div class="alert alert-light border-0 small py-2">
        <strong>Tip:</strong> Treat file-manager UI complaints and storage/cron/package complaints as related but separate layers. The fastest fixes usually come from identifying which layer is actually failing.
    </div>
</div>
