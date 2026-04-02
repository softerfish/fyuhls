<div class="p-1">
    <p class="guide-purpose mb-4">Storage nodes let you spread files across local disks and supported object-storage providers such as generic S3, Backblaze B2, Wasabi, and Cloudflare R2. The guide below explains what each storage option means and where to change it.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Node Statuses</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Active:</strong> Available for uploads and normal operations. Change this on the add or edit screen, then save the node.</li>
        <li class="mb-2"><strong>Read-Only:</strong> Existing files can still be served, but the node should not receive new uploads. Change the status on the edit screen, then save the node.</li>
        <li><strong>Disabled:</strong> Hidden from new upload selection and operational use. Change the status on the edit screen, then save the node.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Key Options</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Storage Path / Bucket Details:</strong> Sets the root location where stored files live. Edit it only when you are creating a new node or doing a real migration, then save the node.</li>
        <li class="mb-2"><strong>Public URL:</strong> Stores the public-facing base URL used by the selected delivery method when applicable. Change it only if the same files are still exposed under that hostname.</li>
        <li class="mb-2"><strong>Capacity:</strong> Sets optional capacity tracking for planning and warnings. Change the number on the add or edit screen, then save the node.</li>
        <li><strong>Delivery Method:</strong> Controls whether downloads use app-controlled PHP, Nginx acceleration, Apache X-SendFile, LiteSpeed headers, or provider URLs where supported. Change it on the add or edit screen, then save the node.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">PPD Accuracy By Delivery Method</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>App-Controlled (PHP):</strong> Strongest standard-file payout verification and the safest choice when <code>ppd_min_download_percent</code> matters.</li>
        <li class="mb-2"><strong>Nginx Handoff:</strong> The only accelerated standard-file path that can still honor <code>ppd_min_download_percent</code> through the Nginx completion log pipeline.</li>
        <li class="mb-2"><strong>Apache / LiteSpeed Handoff:</strong> Speed-first standard-file handoff modes. When percent-based PPD verification is required, Fyuhls falls back to PHP delivery.</li>
        <li class="mb-2"><strong>Delivery test meaning:</strong> A passing Apache or LiteSpeed delivery test proves the handoff works. It does not mean threshold-based standard-file payout verification stays on that handoff path.</li>
        <li class="mb-2"><strong>Nginx completion log:</strong> Configure the dedicated Nginx access log and make sure the same path is set in <code>Config Hub &gt; Downloads &gt; Nginx Completion Log Path</code>.</li>
        <li class="mb-2"><strong>Cloudflare + Nginx:</strong> If the site is behind Cloudflare, configure Nginx real-IP restoration too. Fyuhls Cloudflare trust restores the real IP in PHP, but the Nginx completion log still needs Nginx <code>real_ip_header</code> and the Cloudflare proxy ranges.</li>
        <li><strong>Streaming/media:</strong> Separate from ordinary file downloads. Watch-based media validation should not be treated as proof that ZIP, PDF, or EXE completion works the same way.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Nginx Health Checks</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>What the admin warning means:</strong> If the dashboard shows skipped Nginx completion events, Fyuhls is refusing to credit those downloads because the completion log is missing trusted data needed to prove the download.</li>
        <li class="mb-2"><strong><code>missing_viewer_identity</code>:</strong> The completion log is not writing a numeric <code>viewer_user_id</code>.</li>
        <li class="mb-2"><strong><code>missing_client_ip</code>:</strong> Fyuhls could not recover a trustworthy client IP from the completion event.</li>
        <li class="mb-2"><strong>Safe default:</strong> Skipped completion events are a fail-closed protection. Fyuhls would rather under-credit than guess.</li>
        <li><strong>Where to fix it:</strong> Update the Nginx log format on the server and make sure the same log path is configured in the Downloads tab.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">B2-Specific Tools</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Bucket discovery:</strong> The B2 add and edit pages can authorize with Backblaze and list your available buckets. Enter or re-enter the key fields, then use the helper button on that screen.</li>
        <li class="mb-2"><strong>Auto-fill:</strong> Picking a bucket fills the bucket name, region, and endpoint fields automatically.</li>
        <li><strong>Apply Fyuhls CORS:</strong> Uses the current B2 credentials and bucket name to write or re-write the recommended upload CORS rule on the real bucket. Use it after a bucket change, key rotation, or browser multipart upload failure.</li>
    </ul>

    <div class="alert alert-primary border-0 shadow-sm small">
        <strong>Tip:</strong> Put overloaded nodes into <em>Read-Only</em> first when you need to drain them without breaking existing files.
    </div>

    <div class="alert alert-info border-0 shadow-sm small mt-3">
        <strong>CORS:</strong> Direct browser multipart uploads require object-storage buckets to allow your site origin, <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and to expose the <code>ETag</code> header back to the browser.
    </div>
</div>
