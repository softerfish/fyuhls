<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = "API Reference - {$siteName}";
$metaDescription = "Public API reference for {$siteName}, covering personal API tokens, multipart uploads, managed uploads, file metadata, idempotency, and controlled download links.";
include __DIR__ . '/header.php';
?>

<div class="api-shell">
    <div class="api-hero">
        <span class="api-eyebrow">Developer API</span>
        <h1><?= htmlspecialchars($siteName) ?> API Reference</h1>
        <p class="api-lead">The current <code>/api/v1/</code> API is built for account-bound integrations. It supports personal API tokens, direct-to-storage multipart uploads, a managed upload shortcut for desktop tools, owner-scoped file metadata, and application-controlled download links.</p>
        <div class="api-callouts">
            <div class="api-chip">Version: <strong>v1</strong></div>
            <div class="api-chip">Auth: <strong>Bearer or X-API-Token</strong></div>
            <div class="api-chip">Upload model: <strong>Multipart direct-to-storage</strong></div>
            <div class="api-chip">Download model: <strong>App-controlled links</strong></div>
        </div>
    </div>

    <div class="api-grid">
        <aside class="api-nav-card">
            <h2>Contents</h2>
            <nav class="api-nav">
                <a href="#overview">Overview</a>
                <a href="#auth">Authentication</a>
                <a href="#tokens">Tokens and Scopes</a>
                <a href="#idempotency">Idempotency</a>
                <a href="#endpoints">Endpoint Map</a>
                <a href="#examples">Code Samples</a>
                <a href="#managed-upload">Managed Upload</a>
                <a href="#multipart-upload">Multipart Upload Flow</a>
                <a href="#resume">Resume Interrupted Uploads</a>
                <a href="#files">Files and Downloads</a>
                <a href="#errors">Errors and Limits</a>
                <a href="#production">Production Checklist</a>
            </nav>
        </aside>

        <div class="api-content">
            <section id="overview" class="api-card">
                <h2>Overview</h2>
                <p>This API is designed for real integrations, not just browser calls. Every API token belongs to a specific user account, so uploads, quotas, folders, visibility rules, and package limits all run in that user context.</p>
                <ul class="api-list">
                    <li>Uploads land in the authenticated user's account.</li>
                    <li>Quota is reserved before upload completion to avoid oversubscription.</li>
                    <li>Large files should use multipart direct-to-storage uploads.</li>
                    <li>Download links stay application-controlled so <?= htmlspecialchars($siteName) ?> can decide CDN, signed-origin, or tracked delivery.</li>
                </ul>
                <p class="api-note">Uploads are direct-to-storage, but downloads stay policy-driven. Your client requests a signed download link from <?= htmlspecialchars($siteName) ?> and the app still decides whether the final transfer uses CDN, signed origin, Nginx, Apache, LiteSpeed, or PHP based on site configuration, package rules, and payout-verification requirements.</p>
            </section>

            <section id="auth" class="api-card">
                <h2>Authentication</h2>
                <p>Third-party tools should use personal API tokens. Browser-session calls still work for the site itself, but tokens are the intended public integration method.</p>
                <div class="api-subgrid">
                    <div>
                        <h3>Supported headers</h3>
                        <pre><code>Authorization: Bearer fyu_your_token_here
X-API-Token: fyu_your_token_here</code></pre>
                    </div>
                    <div>
                        <h3>Behavior</h3>
                        <ul class="api-list compact">
                            <li>Token requests do not require CSRF.</li>
                            <li>Session requests still use CSRF for mutating actions.</li>
                            <li>Revoking a token immediately blocks future requests.</li>
                        </ul>
                    </div>
                </div>
            </section>

            <section id="tokens" class="api-card">
                <h2>Tokens and Scopes</h2>
                <p>Users create API tokens in account settings. Tokens are shown once, stored hashed, and can be revoked without affecting the user password or browser session.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Scope</th>
                                <th>Purpose</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>files.upload</code></td>
                                <td>Create sessions, sign parts, report parts, complete, and abort uploads.</td>
                            </tr>
                            <tr>
                                <td><code>files.read</code></td>
                                <td>Read file metadata and request application-controlled download links.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="api-note">Tokens are account-bound. An upload created with a user token is uploaded into that user's account and counts against that user's quota.</p>
            </section>

            <section id="idempotency" class="api-card">
                <h2>Idempotency and Safe Retries</h2>
                <p>Upload session creation and upload completion accept <code>Idempotency-Key</code> or <code>X-Idempotency-Key</code>. This lets tools retry safely when a network timeout happens after the server may already have processed the request.</p>
                <pre><code>Idempotency-Key: desktop-client-42e0f2f4-1</code></pre>
                <ul class="api-list">
                    <li>If the same key is reused with the same payload, the completed response is replayed.</li>
                    <li>If the same key is already being processed, the API returns <code>409</code>.</li>
                    <li>If the same key is reused with a different payload, the request is rejected.</li>
                </ul>
            </section>

            <section id="endpoints" class="api-card">
                <h2>Endpoint Map</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Endpoint</th>
                                <th>Purpose</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>POST</td><td><code>/api/v1/uploads/sessions</code></td><td>Create a multipart upload session.</td></tr>
                            <tr><td>POST</td><td><code>/api/v1/uploads/managed</code></td><td>Create a session and return signed part URLs in one call.</td></tr>
                            <tr><td>GET</td><td><code>/api/v1/uploads/sessions/{id}</code></td><td>Inspect an upload session for resume/retry.</td></tr>
                            <tr><td>POST</td><td><code>/api/v1/uploads/sessions/{id}/parts/sign</code></td><td>Request signed upload URLs for one or more parts.</td></tr>
                            <tr><td>POST</td><td><code>/api/v1/uploads/sessions/{id}/parts/report</code></td><td>Report a successfully uploaded part and its ETag.</td></tr>
                            <tr><td>POST</td><td><code>/api/v1/uploads/sessions/{id}/complete</code></td><td>Finalize the multipart upload and create the file record.</td></tr>
                            <tr><td>POST</td><td><code>/api/v1/uploads/sessions/{id}/abort</code></td><td>Abort the multipart upload and release reservation state.</td></tr>
                            <tr><td>GET</td><td><code>/api/v1/files/{id}</code></td><td>Get owner-scoped file metadata.</td></tr>
                            <tr><td>GET</td><td><code>/api/v1/downloads/{id}/link</code></td><td>Get an application-signed download link.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="examples" class="api-card">
                <h2>Code Samples</h2>
                <p>These examples use the managed-upload shortcut first because it is the fastest path for third-party tools. Replace the base URL, token, file IDs, and session IDs with your own values.</p>

                <h3>curl: create a managed upload</h3>
                <pre><code>curl -X POST "https://your-site.example/api/v1/uploads/managed" \
  -H "Authorization: Bearer fyu_your_token_here" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: desktop-upload-001" \
  -d '{
    "filename": "archive.iso",
    "size": 10737418240,
    "mime_type": "application/octet-stream",
    "folder_id": 123,
    "part_numbers": [1, 2, 3],
    "expires_in": 3600
  }'</code></pre>

                <h3>curl: request file metadata</h3>
                <pre><code>curl "https://your-site.example/api/v1/files/123" \
  -H "Authorization: Bearer fyu_your_token_here"</code></pre>

                <h3>curl: request a download link</h3>
                <pre><code>curl "https://your-site.example/api/v1/downloads/123/link" \
  -H "Authorization: Bearer fyu_your_token_here"</code></pre>

                <h3>PHP: create a managed upload</h3>
                <pre><code>&lt;?php
$payload = [
    'filename' =&gt; 'archive.iso',
    'size' =&gt; 10737418240,
    'mime_type' =&gt; 'application/octet-stream',
    'folder_id' =&gt; 123,
    'part_numbers' =&gt; [1, 2, 3],
    'expires_in' =&gt; 3600,
];

$ch = curl_init('https://your-site.example/api/v1/uploads/managed');
curl_setopt_array($ch, [
    CURLOPT_POST =&gt; true,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER =&gt; [
        'Authorization: Bearer fyu_your_token_here',
        'Content-Type: application/json',
        'Idempotency-Key: desktop-upload-001',
    ],
    CURLOPT_POSTFIELDS =&gt; json_encode($payload),
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

var_dump($status, json_decode($response, true));</code></pre>

                <h3>Node.js: create a managed upload</h3>
                <pre><code>const response = await fetch('https://your-site.example/api/v1/uploads/managed', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer fyu_your_token_here',
    'Content-Type': 'application/json',
    'Idempotency-Key': 'desktop-upload-001'
  },
  body: JSON.stringify({
    filename: 'archive.iso',
    size: 10737418240,
    mime_type: 'application/octet-stream',
    folder_id: 123,
    part_numbers: [1, 2, 3],
    expires_in: 3600
  })
});

const data = await response.json();
console.log(response.status, data);</code></pre>

                <h3>End-to-end multipart example</h3>
                <pre><code>1. Create the upload session

curl -X POST "https://your-site.example/api/v1/uploads/sessions" \
  -H "Authorization: Bearer fyu_your_token_here" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: upload-session-001" \
  -d '{
    "filename": "archive.iso",
    "size": 10737418240,
    "mime_type": "application/octet-stream",
    "folder_id": 123
  }'

2. Request a signed URL for part 1

curl -X POST "https://your-site.example/api/v1/uploads/sessions/ups_ab12cd34ef56/parts/sign" \
  -H "Authorization: Bearer fyu_your_token_here" \
  -H "Content-Type: application/json" \
  -d '{
    "part_numbers": [1],
    "expires_in": 3600
  }'

3. Upload that part directly to object storage

curl -X PUT "https://signed-storage-url-from-step-2" \
  -H "Content-Type: application/octet-stream" \
  --data-binary "@archive.part1"

4. Report the completed part back to <?= htmlspecialchars($siteName) ?>

curl -X POST "https://your-site.example/api/v1/uploads/sessions/ups_ab12cd34ef56/parts/report" \
  -H "Authorization: Bearer fyu_your_token_here" \
  -H "Content-Type: application/json" \
  -d '{
    "part_number": 1,
    "etag": "\"etag-returned-by-storage\"",
    "part_size": 67108864
  }'

5. Complete the upload after all parts are reported

curl -X POST "https://your-site.example/api/v1/uploads/sessions/ups_ab12cd34ef56/complete" \
  -H "Authorization: Bearer fyu_your_token_here" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: upload-complete-001" \
  -d '{
    "checksum_sha256": "optional-final-sha256"
  }'</code></pre>
            </section>

            <section id="managed-upload" class="api-card">
                <h2>Managed Upload Shortcut</h2>
                <p><code>POST /api/v1/uploads/managed</code> is the easiest way for desktop tools to start. It creates the session and immediately returns signed part URLs for the requested part numbers.</p>
                <p class="api-note">If the site uses B2, Wasabi, R2, or another S3-compatible bucket, the storage side still needs correct CORS. The API session and signed part URLs are only one half of the flow; the bucket must still allow the site origin and expose <code>ETag</code> for multipart uploads to complete reliably.</p>
                <h3>Example request</h3>
                <pre><code>{
  "filename": "archive.iso",
  "size": 10737418240,
  "mime_type": "application/octet-stream",
  "folder_id": 123,
  "part_numbers": [1, 2, 3],
  "expires_in": 3600
}</code></pre>
                <h3>Example response</h3>
                <pre><code>{
  "status": "ok",
  "session": {
    "public_id": "ups_ab12cd34ef56",
    "status": "pending"
  },
  "part_size_bytes": 67108864,
  "parts": [
    {
      "part_number": 1,
      "method": "PUT",
      "url": "https://..."
    }
  ],
  "complete_url": "/api/v1/uploads/sessions/ups_ab12cd34ef56/complete",
  "report_part_url": "/api/v1/uploads/sessions/ups_ab12cd34ef56/parts/report"
}</code></pre>
            </section>

            <section id="multipart-upload" class="api-card">
                <h2>Multipart Upload Flow</h2>
                <ol class="api-steps">
                    <li>Create a session with <code>/uploads/sessions</code> or use <code>/uploads/managed</code>.</li>
                    <li>Request signed part URLs for the part numbers you want to upload next.</li>
                    <li>Upload parts directly to object storage using the returned URLs.</li>
                    <li>Report each completed part with its <code>part_number</code>, <code>etag</code>, and byte size.</li>
                    <li>Call <code>/complete</code> when all parts are uploaded and reported.</li>
                </ol>

                <h3>Report-part example</h3>
                <pre><code>{
  "part_number": 1,
  "etag": "\"8b1a9953c4611296a827abf8c47804d7\"",
  "part_size": 67108864
}</code></pre>

                <h3>Complete example</h3>
                <pre><code>{
  "checksum_sha256": "optional-client-calculated-sha256"
}</code></pre>

                <p class="api-note">For large integrations, keep the client on the direct-to-storage multipart path. Do not proxy 10 GB to 100 GB uploads through PHP if you care about throughput and reliability.</p>
            </section>

            <section id="resume" class="api-card">
                <h2>Resume Interrupted Uploads</h2>
                <p>The intended resume pattern is simple: persist the upload session ID locally, ask the API for the latest session state, determine which parts still need to be uploaded, then request fresh signed URLs only for the missing parts.</p>

                <ol class="api-steps">
                    <li>Store the session <code>public_id</code> in the desktop app or browser state when the upload begins.</li>
                    <li>After a crash, refresh, or network drop, call <code>GET /api/v1/uploads/sessions/{id}</code>.</li>
                    <li>Inspect the returned session status, uploaded bytes, completed parts, and any part state your client already knows.</li>
                    <li>Request fresh signed URLs for the remaining parts with <code>/parts/sign</code>.</li>
                    <li>Upload the missing parts, report them, and then call <code>/complete</code>.</li>
                </ol>

                <h3>Resume check</h3>
                <pre><code>curl "https://your-site.example/api/v1/uploads/sessions/ups_ab12cd34ef56" \
  -H "Authorization: Bearer fyu_your_token_here"</code></pre>

                <h3>Typical resumed session response</h3>
                <pre><code>{
  "status": "ok",
  "session": {
    "public_id": "ups_ab12cd34ef56",
    "status": "uploading",
    "expected_size": 10737418240,
    "uploaded_bytes": 201326592,
    "completed_parts": 3
  }
}</code></pre>

                <h3>Request fresh URLs only for the missing parts</h3>
                <pre><code>curl -X POST "https://your-site.example/api/v1/uploads/sessions/ups_ab12cd34ef56/parts/sign" \
  -H "Authorization: Bearer fyu_your_token_here" \
  -H "Content-Type: application/json" \
  -d '{
    "part_numbers": [4, 5, 6],
    "expires_in": 3600
  }'</code></pre>

                <p class="api-note">Do not assume an old signed storage URL is still valid after a pause or restart. Always ask for fresh part URLs before resuming if the previous URLs may have expired.</p>
            </section>

            <section id="files" class="api-card">
                <h2>Files and Downloads</h2>
                <div class="api-subgrid">
                    <div>
                        <h3>File metadata</h3>
                        <p><code>GET /api/v1/files/{id}</code> returns owner-scoped metadata including filename, size, mime type, short ID, folder, visibility, download count, and current file status.</p>
                    </div>
                    <div>
                        <h3>Download links</h3>
                        <p><code>GET /api/v1/downloads/{id}/link</code> returns an application-signed link. The app still decides whether the actual transfer uses CDN, signed origin, or app-controlled delivery.</p>
                    </div>
                </div>
                <pre><code>{
  "status": "ok",
  "url": "https://your-site.example/download/123?token=...",
  "expires_in": 3600,
  "delivery": "cdn",
  "delivery_reason": "public_object_storage_cdn"
}</code></pre>
                <ul class="api-list">
                    <li>Treat the returned link as opaque and short-lived.</li>
                    <li>Do not hardcode assumptions about the final transfer method in the client.</li>
                    <li>If the site requires percent-based payout verification for ordinary downloads, Apache and LiteSpeed standard-file transfers can still fall back to PHP even when those handoff modes are enabled in the admin area.</li>
                    <li>Streaming and watch-based media flows are separate from ordinary file-download delivery and should not be treated as the same completion-verification model.</li>
                </ul>
            </section>

            <section id="errors" class="api-card">
                <h2>Errors and Limits</h2>
                <ul class="api-list">
                    <li><code>401</code>: authentication failed or token is invalid.</li>
                    <li><code>403</code>: token is valid but missing the required scope, or CSRF failed for session-mode writes.</li>
                    <li><code>404</code>: the file or upload session is not accessible to the caller.</li>
                    <li><code>409</code>: an idempotent request with the same key is still in flight.</li>
                    <li><code>422</code>: validation failed, upload state is inconsistent, or the provider-side step could not be completed.</li>
                </ul>
                <p>API traffic is rate-limited per token, per user, and per IP. Exact limits are site-configurable and can differ by deployment.</p>
            </section>

            <section id="production" class="api-card">
                <h2>Production Checklist</h2>
                <ul class="api-list">
                    <li>Use API tokens instead of browser cookies in third-party tools.</li>
                    <li>Send idempotency keys on create and complete.</li>
                    <li>Expose <code>ETag</code> in bucket CORS for multipart uploads.</li>
                    <li>Keep direct object-storage URLs out of client logic. Use the download-link endpoint.</li>
                    <li>Assume delivery can change between PHP, CDN, Nginx, Apache, and LiteSpeed depending on site policy. Build clients around the API contract, not a fixed transport path.</li>
                    <li>Persist upload session IDs locally so desktop tools can resume large transfers.</li>
                    <li>Test the full path with your actual B2, R2, Wasabi, or S3-compatible bucket before pushing real traffic.</li>
                </ul>
            </section>
        </div>
    </div>
</div>

<style>
    .api-shell { max-width: 1240px; margin: 0 auto; padding: 3rem 2rem 5rem; }
    .api-hero { margin-bottom: 2rem; padding: 2.5rem; background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(15, 23, 42, 0.03)); border: 1px solid rgba(37, 99, 235, 0.12); border-radius: 24px; }
    .api-eyebrow { display: inline-block; margin-bottom: 0.9rem; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--primary-color); }
    .api-hero h1 { margin: 0 0 1rem; font-size: clamp(2.2rem, 4vw, 3.5rem); line-height: 1.05; letter-spacing: -0.04em; }
    .api-lead { margin: 0; max-width: 900px; font-size: 1.05rem; line-height: 1.75; color: var(--text-muted); }
    .api-callouts { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem; }
    .api-chip { padding: 0.65rem 0.95rem; background: rgba(255,255,255,0.8); border: 1px solid var(--border-color); border-radius: 999px; font-size: 0.9rem; }
    .api-grid { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .api-nav-card, .api-card { background: #fff; border: 1px solid var(--border-color); border-radius: 20px; box-shadow: 0 16px 50px -28px rgba(15, 23, 42, 0.22); }
    .api-nav-card { position: sticky; top: 96px; padding: 1.25rem; }
    .api-nav-card h2 { margin: 0 0 0.9rem; font-size: 1rem; }
    .api-nav { display: grid; gap: 0.55rem; }
    .api-nav a { text-decoration: none; color: var(--text-color); padding: 0.55rem 0.75rem; border-radius: 10px; font-weight: 600; }
    .api-nav a:hover { background: rgba(37, 99, 235, 0.08); color: var(--primary-color); }
    .api-content { display: grid; gap: 1.25rem; }
    .api-card { padding: 1.5rem; }
    .api-card h2 { margin-top: 0; margin-bottom: 0.9rem; font-size: 1.45rem; }
    .api-card h3 { margin: 1.25rem 0 0.7rem; font-size: 1rem; }
    .api-card p { color: var(--text-muted); line-height: 1.75; }
    .api-subgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .api-list { margin: 0.75rem 0 0; padding-left: 1.15rem; color: var(--text-color); line-height: 1.8; }
    .api-list.compact { line-height: 1.65; }
    .api-steps { margin: 0.75rem 0 0; padding-left: 1.25rem; line-height: 1.85; }
    .api-note { margin-top: 1rem; padding: 0.95rem 1rem; border-left: 4px solid var(--primary-color); background: rgba(37, 99, 235, 0.06); border-radius: 12px; color: var(--text-color); }
    .table-wrap { overflow-x: auto; margin-top: 0.85rem; }
    table { width: 100%; border-collapse: collapse; min-width: 680px; }
    th, td { text-align: left; padding: 0.9rem 0.85rem; border-bottom: 1px solid var(--border-color); vertical-align: top; }
    th { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
    pre { margin: 0.9rem 0 0; padding: 1rem 1.1rem; border-radius: 14px; background: #0f172a; color: #e2e8f0; overflow-x: auto; font-size: 0.85rem; line-height: 1.7; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    @media (max-width: 980px) {
        .api-grid { grid-template-columns: 1fr; }
        .api-nav-card { position: static; }
    }
    @media (max-width: 760px) {
        .api-shell { padding: 2rem 1rem 4rem; }
        .api-hero { padding: 1.5rem; }
        .api-subgrid { grid-template-columns: 1fr; }
        table { min-width: 560px; }
    }
</style>

<?php include __DIR__ . '/footer.php'; ?>
