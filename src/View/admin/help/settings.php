<div class="small">
    <p class="mb-4">This reference is shared by the <strong>General</strong>, <strong>Downloads</strong>, and <strong>Uploads</strong> tabs in Config Hub. Use it when you need the site-wide defaults behind the user-facing file flow.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use These Tabs</h6>
    <ul class="mb-4">
        <li><strong>General:</strong> Brand, registration, maintenance, footer branding, and FFmpeg support.</li>
        <li><strong>Downloads:</strong> Site-wide delivery rules, country blocks, live-download tracking, remote URL processing, streaming, CDN redirects, and Nginx completion-log settings.</li>
        <li><strong>Uploads:</strong> Browser-upload behavior, guest-vs-login policy, deduplication, chunking, and download-page save actions.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">General Tab Highlights</h6>
    <ul class="mb-4">
        <li><strong>Site Name:</strong> Updates the brand name shown across the public site, admin screens, and system emails.</li>
        <li><strong>Admin Notification Email:</strong> Default inbox for site-wide admin alerts.</li>
        <li><strong>Maintenance Mode / Demo Mode:</strong> Operational controls that affect how the whole app behaves.</li>
        <li><strong>Show Powered By:</strong> Controls the public footer credit outside the locked Site Content fields.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Downloads Tab Highlights</h6>
    <ul class="mb-4">
        <li><strong>Require User Account to Download:</strong> Blocks guest downloads entirely.</li>
        <li><strong>Track Active Download Connections:</strong> Feeds the <strong>Live Downloads</strong> page and package concurrency checks.</li>
        <li><strong>Nginx Completion Log settings:</strong> Required if you depend on Nginx completion verification for ordinary-file payout accuracy.</li>
        <li><strong>CDN Redirects:</strong> Lets eligible public files hand off to a CDN or public object-storage hostname.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Uploads Tab Highlights</h6>
    <ul class="mb-4">
        <li><strong>Chunked Uploads and chunk size:</strong> Affect browser behavior and multipart-style upload handling.</li>
        <li><strong>Allowed extensions:</strong> Site-wide upload allowlist.</li>
        <li><strong>Login Required:</strong> Governs guest uploads versus logged-in-only uploads.</li>
        <li><strong>Download Page Actions:</strong> Controls who can use the <code>+</code> save-to-account action from the public download page.</li>
    </ul>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/packages" class="guide-action-link">Packages</a> for per-plan limits, <a href="/admin/configuration?tab=security" class="guide-action-link">Security</a> for VPN/proxy policy, and <a href="/admin/docs#storage" class="guide-action-link">Storage Nodes</a> for provider-side delivery and multipart dependencies.
    </div>
</div>
