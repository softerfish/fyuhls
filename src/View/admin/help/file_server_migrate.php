<div class="p-1">
    <p class="guide-purpose mb-4">Use Migrate Files when you are moving stored objects from one node to another without changing the public file records users already depend on.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Before You Start</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Confirm the destination node is healthy:</strong> Test it first from the Storage tab.</li>
        <li class="mb-2"><strong>Check free capacity:</strong> Migration can fail partway through if the target is too small.</li>
        <li class="mb-2"><strong>Use read-only where helpful:</strong> Putting the source or destination into a controlled state reduces surprises during larger moves.</li>
        <li><strong>Run cron:</strong> Background cleanup and reconciliation tasks should be healthy before you begin.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Safe Migration Flow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Migrate a small batch first:</strong> Validate permissions, delivery, and object key layout before moving a large set.</li>
        <li><strong>Test public delivery after the first batch:</strong> Download a migrated file to confirm URLs and storage access still work.</li>
        <li><strong>Watch System Status and logs:</strong> Failed copy or delete operations should be investigated before you continue.</li>
        <li><strong>Only retire the source node after validation:</strong> Keep it available until you are sure the migrated files are healthy.</li>
    </ol>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Important:</strong> Migration changes infrastructure underneath live files. Treat it like a storage operation, not a cosmetic setting change. Backups and rollback planning matter.
    </div>
</div>
