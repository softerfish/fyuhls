<div class="p-1">
    <p class="guide-purpose mb-4">Storage nodes control where files live and how they are served. Use this page when the question is about placement, delivery method, or provider configuration rather than a single user or ticket.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Use it when:</strong> You need to add a node, change a delivery method, adjust capacity planning, fix multipart/CORS problems, or migrate files between nodes.</li>
        <li><strong>Use another page when:</strong> You need to change site-wide download defaults. Those live in <strong>Config Hub &gt; Downloads</strong>.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Key Concepts</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Statuses:</strong> <strong>Active</strong> accepts uploads, <strong>Read-Only</strong> drains new uploads while keeping old files online, and <strong>Disabled</strong> removes the node from normal use.</li>
        <li class="mb-2"><strong>Delivery Method:</strong> Decides whether files use app-controlled PHP, Nginx acceleration, Apache X-SendFile, LiteSpeed, or provider URLs where supported.</li>
        <li class="mb-2"><strong>Provider helpers:</strong> B2 bucket discovery and automatic CORS writing exist to reduce manual provider mistakes.</li>
        <li><strong>Nginx completion-log support:</strong> Matters when you want accelerated delivery while still preserving standard-file payout accuracy.</li>
    </ul>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/configuration?tab=downloads" class="guide-action-link">Config Hub &gt; Downloads</a> for Nginx completion-log settings and CDN redirects, <a href="/admin/server-monitoring" class="guide-action-link">Server Monitoring</a> for node health, and <a href="/admin/docs#storage-workflows" class="guide-action-link">Storage Node Workflows</a> for add, edit, and migration references.
    </div>
</div>
