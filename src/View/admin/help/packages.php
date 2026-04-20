<div class="p-1">
    <p class="guide-purpose mb-4">Packages control quotas, waiting times, storage allowances, premium behavior, and capability flags assigned to users. Every signup, upgrade, and downgrade eventually maps back to one of these package records.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Current Page Behavior</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Built-in packages only:</strong> This page edits package records that already exist in the database.</li>
        <li class="mb-2"><strong>Type:</strong> The package level identifier, such as free or premium, used by the app logic.</li>
        <li class="mb-2"><strong>Max Upload:</strong> Maximum allowed size for a single uploaded file in this package.</li>
        <li class="mb-2"><strong>Max Storage:</strong> Total storage quota for the account. This is enforced during uploads.</li>
        <li class="mb-2"><strong>Wait Time:</strong> Delay before a download can start for users on that package.</li>
        <li class="mb-2"><strong>Expiry:</strong> Number of inactive days before files from that package become eligible for cleanup. <code>0</code> means never expire.</li>
        <li><strong>Ads:</strong> Whether download pages for that package are allowed to show ad placements.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Edit Screen Options</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Max Daily Downloads:</strong> Total daily bandwidth allowance for the package.</li>
        <li class="mb-2"><strong>Download Speed:</strong> Optional throttling limit in bytes per second.</li>
        <li class="mb-2"><strong>Allow Remote Upload:</strong> Lets users import a file from a URL instead of their browser.</li>
        <li class="mb-2"><strong>Allow Direct Hotlinking:</strong> Lets the package use direct file links where enabled.</li>
        <li class="mb-2"><strong>Concurrent Uploads / Downloads:</strong> Per-package concurrency limits. Download concurrency depends on active-download tracking being enabled.</li>
        <li><strong>Package Price:</strong> Used by checkout and payment gateways for paid upgrades.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Package Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Create the free baseline first:</strong> Define the lowest-tier behavior clearly before layering premium plans on top.</li>
        <li><strong>Keep upload size and storage aligned:</strong> A package should not allow huge single uploads that make no sense against the total quota.</li>
        <li><strong>Review daily download logic with rewards and abuse rules:</strong> Large bandwidth allowances affect cost, fraud strategy, and user expectations.</li>
        <li><strong>Test with a real account:</strong> After major changes, log into a user on that package and confirm uploads, downloads, waits, and limits behave as expected.</li>
    </ol>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Important:</strong> Package numbers are stored in bytes for accuracy. Use the examples on the edit screen when converting MB, GB, or TB values.
    </div>
</div>
