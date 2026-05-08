<div class="p-1">
    <p class="guide-purpose mb-4">Fyuhls can keep the same public download page while changing the actual transfer path underneath. Use this guide when the page looks normal but the transfer behavior is not.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Use it when:</strong> You need to trace why a file is using PHP, CDN, Nginx, Apache, LiteSpeed, or object-storage delivery.</li>
        <li><strong>Use another page when:</strong> The issue is really about package waits, guest access, or storage-node state. Those belong to <strong>Packages</strong> or <strong>Storage Nodes</strong>.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Main Delivery Paths</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>App-controlled PHP:</strong> Highest compatibility and strongest application control.</li>
        <li class="mb-2"><strong>CDN or direct object-storage delivery:</strong> Useful for eligible public files when you want lower origin pressure.</li>
        <li class="mb-2"><strong>Nginx / Apache / LiteSpeed handoff:</strong> Faster transfer paths that still depend on correct server and logging setup.</li>
        <li><strong>Streaming:</strong> Separate from ordinary file downloads. A working stream path does not prove standard-file delivery is healthy.</li>
    </ul>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/docs#storage" class="guide-action-link">Storage Nodes</a> for node-level delivery methods, <a href="/admin/configuration?tab=downloads" class="guide-action-link">Config Hub &gt; Downloads</a> for CDN and Nginx completion-log settings, and <a href="/admin/downloads/current" class="guide-action-link">Live Downloads</a> when you need to confirm active transfer behavior in real time.
    </div>
</div>
