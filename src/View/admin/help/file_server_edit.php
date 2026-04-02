<div class="small">
    <p class="mb-4">Use the Edit Node page when the storage node already holds live files and you need to rotate credentials, change status, or re-run provider helpers carefully.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What To Be Careful With</h6>
    <ul class="mb-4">
        <li><strong>Bucket or path changes:</strong> Changing the storage path or bucket on an active server makes existing files point at the wrong place unless you are doing a real migration.</li>
        <li><strong>Key rotation:</strong> Update the credential fields when your provider keys change, then save the node.</li>
        <li><strong>Status changes:</strong> Switch to <strong>Read-Only</strong> when you want to stop new uploads without breaking existing files.</li>
        <li><strong>Public URL changes:</strong> Only change this when the same bucket is still exposed under the new hostname or CDN path.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">B2 Edit Helpers</h6>
    <ul class="mb-4">
        <li><strong>Reload My B2 Buckets:</strong> Re-authorizes against Backblaze and refreshes the known bucket, region, and endpoint details.</li>
        <li><strong>Re-enter the secret first:</strong> The edit page keeps your existing secret hidden, so you must paste it again before the B2 automation buttons can work.</li>
        <li><strong>Apply Fyuhls CORS:</strong> Re-writes the recommended browser-upload CORS rule on the real bucket using the current credentials and bucket name.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Delivery Methods</h6>
    <ul class="mb-4">
        <li><strong>App-Controlled (PHP):</strong> Strongest for standard-file download gating and percent-based PPD verification, but slowest.</li>
        <li><strong>Nginx Handoff:</strong> Requires <code>X-Accel-Redirect</code> and the matching completion-log pipeline if you want percent-based PPD proof.</li>
        <li><strong>Apache Handoff:</strong> Requires <code>mod_xsendfile</code>. For standard files, percent-based PPD verification falls back to PHP delivery.</li>
        <li><strong>LiteSpeed Handoff:</strong> Uses LiteSpeed headers. For standard files, percent-based PPD verification still falls back to PHP delivery.</li>
    </ul>

    <div class="alert alert-warning border-0">
        <strong>After changes:</strong> Save the node, then test one small upload and one small download before treating the node as healthy again.
    </div>
</div>
