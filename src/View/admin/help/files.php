<div class="p-1">
    <p class="guide-purpose mb-4">Files is the admin-side inventory of hosted content. Use it to inspect ownership, confirm storage placement, spot duplicates, and remove or investigate specific uploads.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Review A File</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Search first:</strong> Narrow by filename, short ID, or owner so you are not acting on the wrong record.</li>
        <li><strong>Confirm owner and status:</strong> Make sure you know who uploaded it, whether it is still active, and whether another admin already changed it.</li>
        <li><strong>Check the storage server:</strong> The server column tells you where the content lives and whether a storage issue or migration could explain a report.</li>
        <li><strong>Delete with context:</strong> If the file is tied to abuse, DMCA, or a user dispute, review the related queue first so moderation records stay consistent.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What To Look At</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Owner:</strong> Trace who uploaded the content before you take action.</li>
        <li class="mb-2"><strong>Server:</strong> Confirms which storage node currently holds the stored object.</li>
        <li class="mb-2"><strong>Downloads:</strong> Useful for judging the impact of deleting or migrating a popular file.</li>
        <li><strong>Duplicate indicators:</strong> Help you distinguish unique stored objects from logical file entries that point at the same underlying content.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Tip:</strong> This page is the fastest bridge between moderation queues and infrastructure pages because it shows both user ownership and storage placement in one table.
    </div>
</div>
