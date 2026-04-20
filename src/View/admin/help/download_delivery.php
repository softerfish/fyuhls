<div class="p-1">
    <p class="guide-purpose mb-4">Fyuhls can show the normal download page while changing the actual transfer path underneath. Delivery troubleshooting starts with understanding that difference.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Main Delivery Paths</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>App-controlled PHP:</strong> Strongest application control and simplest compatibility path.</li>
        <li class="mb-2"><strong>CDN or direct object-storage delivery:</strong> Useful for eligible public files when you want lower origin pressure.</li>
        <li class="mb-2"><strong>Nginx / Apache / LiteSpeed handoff:</strong> Accelerated delivery paths that still depend on correct server-side setup.</li>
        <li><strong>Streaming:</strong> Separate from ordinary file downloads. A working stream flow does not prove ordinary file delivery is configured correctly.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Where To Configure It</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Config Hub &gt; Downloads:</strong> Account requirement, country blocks, active-download tracking, streaming, Nginx completion-log settings, and CDN redirects.</li>
        <li class="mb-2"><strong>Storage Nodes:</strong> Provider-level delivery method and node-specific behavior.</li>
        <li><strong>Packages:</strong> Download limits, waits, and concurrency rules that can still gate an otherwise healthy delivery path.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Tip:</strong> If users can reach the normal download page but transfers still behave strangely, inspect the storage node, delivery method, package rules, and Nginx/CDN settings in that order.
    </div>
</div>
