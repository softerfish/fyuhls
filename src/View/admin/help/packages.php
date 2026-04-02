<div class="p-1">
    <p class="guide-purpose mb-4">Packages control the limits and features assigned to users. Every registration and downgrade path eventually maps a user to one of these package records.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Current Page Behavior</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Built-in packages only:</strong> This page edits the packages already in the database. Creating brand new packages from the UI is not available yet.</li>
        <li class="mb-2"><strong>Type:</strong> The package level identifier, such as free or premium, used by the app logic.</li>
        <li class="mb-2"><strong>Max Upload:</strong> Maximum allowed size for a single uploaded file in this package.</li>
        <li class="mb-2"><strong>Max Storage:</strong> Total storage quota for the account. This is enforced during uploads.</li>
        <li class="mb-2"><strong>Wait Time:</strong> Delay before a download can start for users on that package.</li>
        <li class="mb-2"><strong>Expiry:</strong> Number of inactive days before files from that package are eligible for cleanup. <code>0</code> means never expire.</li>
        <li><strong>Ads:</strong> Whether download pages for that package are allowed to show ad placements.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Edit Screen Options</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Max Daily Downloads:</strong> Total bandwidth allowance for a 24-hour window.</li>
        <li class="mb-2"><strong>Download Speed:</strong> Optional throttling limit in bytes per second.</li>
        <li class="mb-2"><strong>Allow Remote Upload:</strong> Lets users import a file from a URL instead of uploading it from their browser.</li>
        <li class="mb-2"><strong>Allow Direct Hotlinking:</strong> Lets the package use direct file links where enabled.</li>
        <li class="mb-2"><strong>Concurrent Uploads:</strong> Per-package upload concurrency limit.</li>
        <li class="mb-2"><strong>Concurrent Downloads:</strong> Per-package app-tracked download concurrency limit. Saving a value above 0 automatically enables active-download tracking in the Downloads tab.</li>
        <li><strong>Package Price:</strong> Used by the checkout flow for paid upgrades and payment-gateway transactions.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Important:</strong> The numbers on this page are stored in bytes for accuracy. Use the examples shown on the edit screen when converting MB or GB values.
    </div>
</div>
