<?php include dirname(__DIR__) . '/header.php'; ?>
<?php $trustedBaseUrl = \App\Service\SeoService::trustedBaseUrl() ?? ''; ?>
<?php $demoAdminViewer = \App\Service\DemoModeService::currentViewerIsDemoAdmin(); ?>

<div class="page-header mb-4">
    <h1>Add New Storage Server</h1>
    <a href="/admin/configuration?tab=storage" class="btn btn-outline-secondary btn-sm">&larr; Back to Servers</a>
</div>

<?php if ($demoAdminViewer): ?>
    <div class="alert alert-warning border-0 shadow-sm small mb-4">
        <strong>Demo admin mode:</strong> Storage configuration is read-only for this account. This page is view-only.
    </div>
<?php endif; ?>

<!-- Tab Navigation -->
<?php
$activeTab = $_GET['tab'] ?? 'local';
$tabs = [
    'local' => ['label' => 'Local Storage', 'icon' => 'bi-hdd'],
    'wasabi'=> ['label' => 'Wasabi S3', 'icon' => 'bi-snow'],
    'r2'    => ['label' => 'Cloudflare R2', 'icon' => 'bi-cloud-sun'],
    'b2'    => ['label' => 'Backblaze B2', 'icon' => 'bi-cloud'],
    's3'    => ['label' => 'S3 Compatible', 'icon' => 'bi-box-seam'],
];
?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-0">
        <div class="d-flex border-bottom overflow-auto">
            <?php foreach ($tabs as $key => $tab): ?>
                <a href="?tab=<?= $key ?>" 
                   class="px-4 py-3 text-decoration-none fw-bold small transition-all <?= $activeTab === $key ? 'text-primary border-bottom border-primary border-3 bg-light' : 'text-muted' ?>"
                   class="add-server-tab-link px-4 py-3 text-decoration-none fw-bold small transition-all <?= $activeTab === $key ? 'text-primary border-bottom border-primary border-3 bg-light' : 'text-muted' ?>">
                    <i class="bi <?= $tab['icon'] ?> me-2"></i><?= $tab['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Configuration Form -->
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0">Connector Configuration</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="/admin/file-server/add">
                    <?= \App\Core\Csrf::field() ?>
                    <fieldset <?= $demoAdminViewer ? 'disabled' : '' ?>>
                    <input type="hidden" name="type" value="<?= ($activeTab === 'b2' || $activeTab === 'wasabi' || $activeTab === 'r2') ? 's3' : $activeTab ?>">
                    <input type="hidden" name="provider_preset" value="<?= $activeTab ?>">
                    <input type="hidden" name="capacity" value="0">

                    <div class="mb-4">
                        <label class="form-label fw-bold small">Server Friendly Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. <?= $tabs[$activeTab]['label'] ?> - Primary" required>
                        <div class="text-muted extra-small mt-1">A nickname to help you identify this storage in the list.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active (Accepting Uploads)</option>
                            <option value="read-only">Read-Only (Downloads Only)</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>

                    <?php if (in_array($activeTab, ['b2', 'r2', 'wasabi', 's3'], true)): ?>
                        <div class="alert alert-info border-0 shadow-sm small mb-4">
                            <strong>Browser Multipart Requirement:</strong> Configure bucket CORS for your exact Fyuhls origin, allow <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and expose the <code>ETag</code> header. Without that, browser-based large uploads cannot finish cleanly.
                        </div>
                        <div class="mb-4 p-3 border rounded bg-light">
                            <div class="fw-bold small mb-2">Provider Validation Checklist</div>
                            <ul class="extra-small text-muted mb-0 ps-3">
                                <li>Bucket exists and the name matches exactly.</li>
                                <li>The app key can upload, read, and complete multipart uploads for this bucket.</li>
                                <li>Bucket CORS allows your exact Fyuhls origin and allows <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>.</li>
                                <li><code>ETag</code> is exposed in CORS response headers.</li>
                                <li>The endpoint and region match the actual bucket region you are using.</li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <hr class="my-4">

                    <!-- Provider Specific Fields -->
                    <?php if ($activeTab === 'local'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Storage Path</label>
                            <input type="text" name="path" class="form-control" value="storage/uploads" required>
                            <div class="text-muted extra-small mt-1">Use a path inside Fyuhls' <code>storage/</code> directory, such as <code>storage/uploads</code>. Absolute paths outside the app storage directory are blocked for safety.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'b2'): ?>
                        <div class="alert alert-warning border-0 shadow-sm small mb-4">
                            <strong>B2 lifecycle recommendation:</strong> For Fyuhls, set the bucket lifecycle to <strong>Keep only the last version of the file</strong>. Dedup still works normally because Fyuhls tracks shared files in its own database, but this B2 setting prevents old hidden versions from piling up in the bucket browser after deletes.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">B2 Bucket Name</label>
                            <input type="text" name="path" id="b2BucketName" class="form-control" placeholder="my-backblaze-bucket" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">B2 Region</label>
                                <input type="text" name="config[s3_region]" id="b2Region" class="form-control" value="<?= htmlspecialchars($_GET['region'] ?? 'us-west-004') ?>" placeholder="e.g. us-east-005" required>
                                <div class="text-muted extra-small mt-1">Example only: use the region from your real B2 S3 endpoint, like <code>us-east-005</code> from <code>s3.us-east-005.backblazeb2.com</code>.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Key ID (Access Key)</label>
                                <input type="text" name="config[s3_key]" id="b2KeyId" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">B2 S3 Endpoint Host or URL (Optional)</label>
                            <input type="text" name="config[s3_endpoint]" id="b2Endpoint" class="form-control" placeholder="e.g. s3.us-east-005.backblazeb2.com">
                            <div class="text-muted extra-small mt-1">Optional shortcut. Paste the exact B2 S3 endpoint if you want Fyuhls to auto-detect the region. If left blank, we build it from the region above.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Application Key (Secret Key)</label>
                            <input type="password" name="config[s3_secret]" id="b2SecretKey" class="form-control" required>
                        </div>
                        <div class="border rounded p-3 bg-light mb-4">
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="b2DiscoverBtn">Load My B2 Buckets</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="b2ApplyCorsBtn">Apply Fyuhls CORS</button>
                            </div>
                            <div class="text-muted extra-small mb-3">This saves time during setup. Fyuhls can validate your B2 credentials, load your buckets, auto-fill the bucket, region, and endpoint, and apply the recommended upload CORS rule for <code><?= htmlspecialchars($trustedBaseUrl !== '' ? $trustedBaseUrl : 'your trusted site URL') ?></code>. <strong>Important:</strong> clicking <strong>Verify &amp; Connect Server</strong> does not apply bucket CORS by itself. Use <strong>Apply Fyuhls CORS</strong> after the bucket name and current Application Key are filled in.</div>
                            <div class="mb-3 d-none" id="b2BucketPickerWrap">
                                <label class="form-label fw-bold small">Choose a Backblaze Bucket</label>
                                <select class="form-select" id="b2BucketPicker"></select>
                                <div class="text-muted extra-small mt-1">Selecting a bucket will automatically fill the bucket name, region, and endpoint fields above.</div>
                            </div>
                            <div class="alert alert-secondary border-0 small mb-0" id="b2AutomationStatus">
                                Enter your B2 Key ID and Application Key, then click <strong>Load My B2 Buckets</strong>. Once your bucket is selected, click <strong>Apply Fyuhls CORS</strong> to write the recommended upload CORS rule to the real B2 bucket. Do this before testing large browser uploads.
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($activeTab === 'r2'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">R2 Bucket Name</label>
                            <input type="text" name="path" class="form-control" placeholder="my-r2-bucket" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Cloudflare Account ID</label>
                            <input type="text" name="config[s3_endpoint]" class="form-control" placeholder="e.g. 1a2b3c4d5e6f7g8h9i0j..." required>
                            <div class="text-muted extra-small mt-1">Enter the Account ID from Cloudflare R2 Overview. Fyuhls will automatically build the full R2 endpoint for you.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Access Key ID</label>
                                <input type="text" name="config[s3_key]" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Secret Access Key</label>
                                <input type="password" name="config[s3_secret]" class="form-control" required>
                            </div>
                        </div>
                        <input type="hidden" name="config[s3_region]" value="auto">
                    <?php endif; ?>

                    <?php if ($activeTab === 'wasabi'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Wasabi Bucket Name</label>
                            <input type="text" name="path" id="wasabiBucketName" class="form-control" placeholder="my-wasabi-bucket" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Wasabi Region</label>
                                <input type="text" name="config[s3_region]" id="wasabiRegion" class="form-control" value="us-east-1" placeholder="e.g. us-east-1" required>
                                <div class="text-muted extra-small mt-1">Example only: use the region from your real Wasabi endpoint, like <code>us-east-1</code> from <code>s3.us-east-1.wasabisys.com</code>.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Access Key</label>
                                <input type="text" name="config[s3_key]" id="wasabiAccessKey" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Wasabi Endpoint Host or URL (Optional)</label>
                            <input type="text" name="config[s3_endpoint]" id="wasabiEndpoint" class="form-control" placeholder="e.g. s3.us-east-1.wasabisys.com">
                            <div class="text-muted extra-small mt-1">Optional shortcut. Paste the exact Wasabi S3 endpoint if you want Fyuhls to auto-detect the region. If left blank, we build it from the region above.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Secret Key</label>
                            <input type="password" name="config[s3_secret]" id="wasabiSecretKey" class="form-control" required>
                        </div>
                        <div class="border rounded p-3 bg-light mb-4">
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="wasabiDiscoverBtn">Load My Wasabi Buckets</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="wasabiApplyCorsBtn">Apply Fyuhls CORS</button>
                            </div>
                            <div class="text-muted extra-small mb-3">This can validate your Wasabi credentials, load buckets that the current key can see, auto-fill the bucket, region, and endpoint, and apply the recommended upload CORS rule for <code><?= htmlspecialchars($trustedBaseUrl !== '' ? $trustedBaseUrl : 'your trusted site URL') ?></code>. <strong>Important:</strong> clicking <strong>Verify &amp; Connect Server</strong> does not apply bucket CORS by itself. Use <strong>Apply Fyuhls CORS</strong> after the bucket name and current secret key are filled in.</div>
                            <div class="mb-3 d-none" id="wasabiBucketPickerWrap">
                                <label class="form-label fw-bold small">Choose a Wasabi Bucket</label>
                                <select class="form-select" id="wasabiBucketPicker"></select>
                                <div class="text-muted extra-small mt-1">Selecting a bucket will automatically fill the bucket name, region, and endpoint fields above.</div>
                            </div>
                            <div class="alert alert-secondary border-0 small mb-0" id="wasabiAutomationStatus">
                                Enter your Wasabi Access Key and Secret Key, then click <strong>Load My Wasabi Buckets</strong>. Once your bucket is selected, click <strong>Apply Fyuhls CORS</strong> to write the recommended upload CORS rule to the real Wasabi bucket before testing large browser uploads.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 's3'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Bucket Name</label>
                            <input type="text" name="path" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Endpoint URL</label>
                            <input type="text" name="config[s3_endpoint]" class="form-control" placeholder="e.g. s3.amazonaws.com" required>
                            <div class="text-muted extra-small mt-1">Use the provider endpoint host or URL for the object storage service, not the bucket name by itself. Localhost, private-network, and metadata-style endpoints are blocked.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Region</label>
                                <input type="text" name="config[s3_region]" class="form-control" placeholder="us-east-1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Access Key</label>
                                <input type="text" name="config[s3_key]" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Secret Key</label>
                                <input type="password" name="config[s3_secret]" class="form-control" required>
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr class="my-4">

                        <div class="mb-4">
                            <label class="form-label fw-bold small">Public Download URL (Optional)</label>
                        <?php 
                        $currentHost = $trustedBaseUrl !== '' ? $trustedBaseUrl : 'https://yourdomain.com';
                        $suggestedUrl = '';
                        $placeholder = "e.g. https://storage.yourdomain.com/";
                        
                        if ($activeTab === 'local') {
                            $suggestedUrl = $currentHost . "/storage/uploads/";
                        } elseif ($activeTab === 'b2') {
                            $suggestedUrl = "https://f00X.backblazeb2.com/file/YOUR-BUCKET/";
                            $placeholder = "e.g. https://f004.backblazeb2.com/file/my-bucket/";
                        } elseif ($activeTab === 'wasabi') {
                            $suggestedUrl = "https://YOUR-BUCKET.s3." . ($_GET['region'] ?? 'us-east-1') . ".wasabisys.com/";
                            $placeholder = "e.g. https://my-bucket.s3.us-east-1.wasabisys.com/";
                        } elseif ($activeTab === 'r2') {
                            $suggestedUrl = "https://pub-XXXXXX.r2.dev/";
                            $placeholder = "e.g. https://pub-123abc456.r2.dev/ or https://cdn.yoursite.com/";
                        } elseif ($activeTab === 's3') {
                            $placeholder = "e.g. https://my-bucket.nyc3.digitaloceanspaces.com/";
                        }
                        ?>
                        <input type="text" name="url" class="form-control" placeholder="<?= $placeholder ?>" value="<?= $activeTab === 'local' ? $suggestedUrl : '' ?>">
                        <div class="text-muted extra-small mt-2">
                            <strong>When to fill this in:</strong>
                            <ul class="mb-2 ps-3">
                                <li>Use this only if the bucket is public or if you have a public CDN/custom domain in front of the bucket.</li>
                                <li>Leave it empty if you want Fyuhls to keep tighter control over access, payout verification, and download gating.</li>
                            </ul>
                            <strong>Recommended Formats:</strong>
                            <ul class="mb-2 ps-3">
                                <?php if ($activeTab === 'local'): ?>
                                    <li><code><?= $currentHost ?>/storage/uploads/</code></li>
                                <?php elseif ($activeTab === 'r2'): ?>
                                    <li><code>https://pub-your-id.r2.dev/</code> (from R2 Public Access)</li>
                                    <li><code>https://custom-domain.com/</code> (if connected via Cloudflare)</li>
                                <?php elseif ($activeTab === 'b2'): ?>
                                    <li><code>https://f00X.backblazeb2.com/file/your-bucket/</code> (raw public B2 bucket URL)</li>
                                    <li><code>https://files.yourdomain.com/</code> (recommended if you put Cloudflare or another CDN in front of B2)</li>
                                <?php elseif ($activeTab === 'wasabi'): ?>
                                    <li><code>https://your-bucket.s3.your-region.wasabisys.com/</code> (direct Wasabi bucket URL)</li>
                                    <li><code>https://files.yourdomain.com/</code> (recommended if you put Cloudflare or another CDN in front of Wasabi)</li>
                                <?php elseif ($activeTab === 's3'): ?>
                                    <li><code>https://your-bucket.your-provider.com/</code> (if your provider exposes public bucket URLs)</li>
                                    <li><code>https://cdn.yourdomain.com/</code> (if you place a CDN or custom domain in front of the bucket)</li>
                                <?php else: ?>
                                    <li><code>https://your-bucket.your-provider.com/</code></li>
                                <?php endif; ?>
                            </ul>
                            
                            <strong>Impact on Rewards (PPD):</strong>
                            <ul class="mb-0 ps-3">
                                <li><strong>Direct public URL provided:</strong> PPD can still be enabled, but it usually counts when the download starts because completion is harder for Fyuhls to verify.</li>
                                <li><strong>Best control:</strong> Leave the URL empty and let Fyuhls serve or hand off the file through the app-controlled path.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="mb-4 p-3 border rounded bg-light">
                        <label class="form-label fw-bold small">High-Performance Delivery</label>
                        <select name="delivery_method" class="form-select mb-2">
                            <option value="php">App-Controlled (PHP) - Fyuhls reads and streams the file itself</option>
                            <option value="nginx">Nginx Handoff - Fyuhls authorizes, then Nginx serves the file</option>
                            <option value="apache">Apache Handoff - Fyuhls authorizes, then Apache serves the file</option>
                            <option value="litespeed">LiteSpeed Handoff - Fyuhls authorizes, then LiteSpeed serves the file</option>
                        </select>
                        <div class="text-muted extra-small">
                            <strong>What each one means:</strong>
                            <ul class="mb-2 ps-3">
                                <li><strong>App-Controlled (PHP):</strong> Best when you want the strongest control over gating, byte-level transfer tracking, and percent-based PPD verification for standard file downloads. Usually the slowest option.</li>
                                <li><strong>Nginx Handoff:</strong> Good for high traffic. Fyuhls approves the request, then Nginx handles the file transfer. For standard files, Fyuhls can still honor <code>ppd_min_download_percent</code> when Nginx writes the dedicated completion access log that Fyuhls reads during cron.</li>
                                <li><strong>Apache Handoff:</strong> Similar to Nginx handoff, but uses Apache and <code>X-SendFile</code>. For standard files, percent-based PPD verification falls back to PHP delivery.</li>
                                <li><strong>LiteSpeed Handoff:</strong> Similar to Apache/Nginx handoff, but uses LiteSpeed's internal delivery header. For standard files, percent-based PPD verification falls back to PHP delivery.</li>
                            </ul>
                            <strong>PPD Warning:</strong> For ordinary file downloads like ZIP, PDF, and EXE files, PHP is the strongest path for exact payout proof. Nginx can support threshold-based payout proof through its completion log pipeline, but Apache and LiteSpeed standard-file handoff still fall back to PHP when percent-based verification is required.
                            <br><br>
                            <strong>Streaming note:</strong> Video streaming can use a separate watch/session model. Do not assume streaming support means Apache or LiteSpeed can verify ordinary file-download completion the same way.
                            <br><br>
                            <strong>Nginx completion log requirements:</strong> To let Nginx honor <code>ppd_min_download_percent</code> for standard files, configure a dedicated Nginx <code>access_log</code> entry that records Fyuhls' download ID, file ID, original URI, final status, and bytes sent. Then set the same log path in <strong>Config Hub &gt; Downloads &gt; Nginx Completion Log Path</strong>.
                            <br><br>
                            <strong>Filesystem handoff note:</strong> Apache and LiteSpeed handoff tests only make sense when the selected storage server resolves to a real filesystem path that the web server can read. Remote object-storage connectors should use the normal connection check plus a real download test instead of assuming a handoff header proves the cloud path.
                            <br><br>
                            <strong>Cloudflare note:</strong> If this site runs behind Cloudflare, also configure Nginx real-IP restoration so the completion log records the real visitor IP instead of the proxy IP. Fyuhls Cloudflare trust fixes PHP-side IP handling, but the Nginx log still depends on Nginx's own <code>real_ip_header</code> and <code>set_real_ip_from</code> configuration.
                            <br><br>
                            <strong>Best choice:</strong> Use App-Controlled (PHP) for the strongest standard-file payout verification, or use Nginx if you want more speed while still honoring <code>ppd_min_download_percent</code>.
                        </div>
                        <div class="alert alert-light border small mt-3 mb-0">
                            <strong>Capability summary:</strong>
                            <ul class="mb-0 ps-3 mt-2">
                                <li><strong>App-Controlled (PHP):</strong> Accelerated delivery: no. Standard-file threshold PPD: yes.</li>
                                <li><strong>Nginx Handoff:</strong> Accelerated delivery: yes. Standard-file threshold PPD: yes, when the Nginx completion log pipeline is configured.</li>
                                <li><strong>Apache Handoff:</strong> Accelerated delivery: yes. Standard-file threshold PPD: falls back to PHP.</li>
                                <li><strong>LiteSpeed Handoff:</strong> Accelerated delivery: yes. Standard-file threshold PPD: falls back to PHP.</li>
                                <li><strong>Streaming/media:</strong> Separate watch/session model. Do not treat it as proof that ordinary ZIP, PDF, or EXE downloads are verified the same way.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-5">
                        <button type="submit" class="btn btn-primary btn-lg"><?= $demoAdminViewer ? 'Read-Only in Demo Mode' : 'Verify & Connect Server' ?></button>
                    </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>

    <!-- Walkthrough / Guide -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 bg-light h-100">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-4 d-flex align-items-center text-primary">
                    <i class="bi bi-info-circle-fill me-2"></i> How to Connect <?= $tabs[$activeTab]['label'] ?>
                </h5>

                <?php if ($activeTab === 'local'): ?>
                    <div class="small lh-lg">
                        <p>Local storage is the simplest way to get started. It stores files directly on your web server's hard drive.</p>
                        <strong>Quick Tips:</strong>
                        <ul>
                            <li>Ensure the <code>storage/uploads</code> folder is writable by PHP and your web server user.</li>
                            <li>The capacity depends entirely on your server's available disk space.</li>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'b2'): ?>
                    <div class="small lh-lg">
                        <h6 class="fw-bold text-dark">step 1: create or log in to backblaze</h6>
                        <p class="mb-3">Go to <a href="https://www.backblaze.com/b2/cloud-storage.html" target="_blank" rel="noopener noreferrer">Backblaze B2</a> and sign in to the account that will own this bucket.</p>

                        <h6 class="fw-bold text-dark">step 2: create the bucket</h6>
                        <ol class="mb-3">
                            <li>Open <strong>Buckets</strong> in the Backblaze sidebar.</li>
                            <li>Click <strong>Create a Bucket</strong>.</li>
                            <li>Enter a globally unique bucket name and keep it written down exactly.</li>
                            <li>For most Fyuhls installs, choose <strong>Private</strong>. Only choose <strong>Public</strong> if you intentionally want public object URLs or a public CDN/public-bucket flow.</li>
                            <li>After the bucket is created, open its details and note the S3 endpoint shown there, such as <code>s3.us-east-005.backblazeb2.com</code>.</li>
                        </ol>

                        <h6 class="fw-bold text-dark">step 3: find the region</h6>
                        <p class="mb-3">Your Fyuhls <strong>B2 Region</strong> is the middle part of the endpoint. Example: <code>s3.us-east-005.backblazeb2.com</code> means the region is <code>us-east-005</code>.</p>

                        <h6 class="fw-bold text-dark">step 4: create a dedicated b2 app key</h6>
                        <ol class="mb-3">
                            <li>Open <strong>App Keys</strong> in Backblaze.</li>
                            <li>Click <strong>Add a New Application Key</strong>.</li>
                            <li>Create a dedicated key for Fyuhls instead of relying on the master key.</li>
                            <li>Restrict it to the bucket you just created if you want tighter access control.</li>
                            <li>If you restrict it to one bucket, also enable <strong>Allow List All Bucket Names</strong>.</li>
                            <li>Copy the <strong>keyID</strong> and <strong>applicationKey</strong> immediately. Backblaze only shows the secret once.</li>
                        </ol>

                        <h6 class="fw-bold text-dark">step 5: fill in the fyuhls form</h6>
                        <p class="mb-3">On the left side of this page, enter your bucket name, region, key ID, application key, and optionally the exact B2 S3 endpoint. Then click <strong>Verify &amp; Connect Server</strong>.</p>
                        <div class="alert alert-info border-0 small mt-3 mb-3">
                            <strong>step 6: set bucket cors for large uploads</strong><br>Fyuhls large object-storage uploads go directly from the browser to Backblaze using the <strong>S3-compatible multipart upload</strong> path. Your bucket must allow your exact Fyuhls origin, allow <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and expose <code>ETag</code>.
                        </div>
                        <div class="alert alert-warning border-0 small mt-3 mb-3">
                            <strong>Use the built-in automation first:</strong> after entering your Key ID and Application Key, click <strong>Load My B2 Buckets</strong>, choose the right bucket, and then click <strong>Apply Fyuhls CORS</strong>. Only fall back to the manual Native API block below if the automation button cannot finish the job or you need to repair an unusual existing rule set.
                        </div>
                        <div class="bg-white border rounded p-3 small mt-3 mb-0">
                            <div class="fw-bold mb-2">Recommended S3-compatible CORS rule</div>
<pre class="mb-2"><code>&lt;CORSConfiguration&gt;
  &lt;CORSRule&gt;
    &lt;AllowedOrigin&gt;https://yourdomain.com&lt;/AllowedOrigin&gt;
    &lt;AllowedMethod&gt;PUT&lt;/AllowedMethod&gt;
    &lt;AllowedMethod&gt;GET&lt;/AllowedMethod&gt;
    &lt;AllowedMethod&gt;HEAD&lt;/AllowedMethod&gt;
    &lt;AllowedHeader&gt;*&lt;/AllowedHeader&gt;
    &lt;ExposeHeader&gt;ETag&lt;/ExposeHeader&gt;
    &lt;MaxAgeSeconds&gt;3600&lt;/MaxAgeSeconds&gt;
  &lt;/CORSRule&gt;
&lt;/CORSConfiguration&gt;</code></pre>
                            <div class="text-muted extra-small">Replace <code>https://yourdomain.com</code> with the exact browser origin your users upload from. Add a second rule or origin entry if you also use <code>https://www.yourdomain.com</code>.</div>
                        </div>
                        <div class="bg-white border rounded p-3 small mt-3">
                            <div class="fw-bold mb-2">Manual recovery only if the automation button is not enough</div>
                            <ol class="mb-2 ps-3">
                                <li>Try <strong>Apply Fyuhls CORS</strong> on this page first.</li>
                                <li>If that cannot complete the update, open <strong>Windows PowerShell</strong> on your own computer.</li>
                                <li>Replace the bucket name, key ID, app key, and domain below with your real values.</li>
                                <li>Paste the whole block at once.</li>
                            </ol>
<pre class="mb-2"><code>$keyId = "YOUR_B2_KEY_ID"
$appKey = "YOUR_B2_APPLICATION_KEY"
$bucketName = "YOUR_BUCKET_NAME"
$origin = "https://yourdomain.com"

$basicAuth = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${keyId}:${appKey}"))
$auth = Invoke-RestMethod -Method Get -Uri "https://api.backblazeb2.com/b2api/v3/b2_authorize_account" -Headers @{ Authorization = "Basic $basicAuth" }
$apiUrl = $auth.apiInfo.storageApi.apiUrl

$buckets = Invoke-RestMethod -Method Post -Uri ($apiUrl + "/b2api/v3/b2_list_buckets") -Headers @{ Authorization = $auth.authorizationToken } -Body (@{ accountId = $auth.accountId } | ConvertTo-Json) -ContentType "application/json"
$bucket = $buckets.buckets | Where-Object { $_.bucketName -eq $bucketName }

$corsRules = @(
    @{
        corsRuleName = "fyuhls-upload"
        allowedOrigins = @($origin)
        allowedHeaders = @("*")
        allowedOperations = @("s3_put", "s3_get", "s3_head")
        exposeHeaders = @("ETag")
        maxAgeSeconds = 3600
    }
)

$updateBody = @{
    accountId  = $auth.accountId
    bucketId   = $bucket.bucketId
    bucketType = $bucket.bucketType
    corsRules  = $corsRules
} | ConvertTo-Json -Depth 10

Invoke-RestMethod -Method Post -Uri ($apiUrl + "/b2api/v3/b2_update_bucket") -Headers @{ Authorization = $auth.authorizationToken } -Body $updateBody -ContentType "application/json"</code></pre>
                            <div class="text-muted extra-small">Use this only if <strong>Apply Fyuhls CORS</strong> cannot finish the update or the bucket already has unusual custom rules. After it succeeds, wait about a minute, hard refresh fyuhls, and try the upload again.</div>
                        </div>
                        <div class="alert alert-secondary border-0 small mt-3 mb-0">
                            <strong>step 7: where this goes</strong><br>Not inside Fyuhls. This rule is configured on the <strong>Backblaze bucket itself</strong>. If uploads still fail after you save the server here, check the bucket's real CORS rules again instead of only trusting the simple Backblaze website toggle.
                        </div>
                        <div class="small text-muted mt-3">
                            <strong>Sources:</strong>
                            <ul class="mb-0 ps-3">
                                <li><a href="https://help.backblaze.com/hc/en-us/articles/360047425453-Getting-Started-with-the-S3-Compatible-API" target="_blank" rel="noopener noreferrer">Backblaze: Getting Started with the S3 Compatible API</a></li>
                                <li><a href="https://help.backblaze.com/hc/en-us/articles/360052129034-Creating-and-Managing-Application-Keys" target="_blank" rel="noopener noreferrer">Backblaze: Creating and Managing Application Keys</a></li>
                                <li><a href="https://www.backblaze.com/docs/en/cloud-storage-call-the-s3-compatible-api" target="_blank" rel="noopener noreferrer">Backblaze: How to Call the S3-Compatible API</a></li>
                                <li><a href="https://www.backblaze.com/docs/cloud-storage-enable-and-manage-cors-rules" target="_blank" rel="noopener noreferrer">Backblaze: Enable and Manage CORS Rules</a></li>
                                <li><a href="https://www.backblaze.com/docs/cloud-storage-cross-origin-resource-sharing-rules" target="_blank" rel="noopener noreferrer">Backblaze: Cloud Storage Cross-Origin Resource Sharing Rules</a></li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'r2'): ?>
                    <div class="small lh-lg">
                        <h6 class="fw-bold text-dark">step 1: enable r2</h6>
                        <p class="mb-3">log in to your <a href="https://dash.cloudflare.com/" target="_blank">cloudflare dashboard</a>, click <strong>r2</strong> in the sidebar, and enable the service if you haven't yet.</p>

                        <h6 class="fw-bold text-dark">step 2: create a bucket</h6>
                        <ol class="mb-3">
                            <li>Click <strong>Create Bucket</strong>.</li>
                            <li><strong>Bucket Name:</strong> Give it a unique name (e.g., <code>fyuhls-storage</code>).</li>
                            <li><strong>Location:</strong> Choose "Automatic" or a specific region.</li>
                            <li>Click <strong>Create Bucket</strong>.</li>
                        </ol>

                        <h6 class="fw-bold text-dark">step 3: generate api keys (S3 Credentials)</h6>
                        <ol class="mb-3">
                            <li>On the Cloudflare sidebar (top right or Bottom left depending on view), go to <strong>My Profile > API Tokens</strong>.</li>
                            <li>Click <strong>Create API Token</strong>.</li>
                            <li>Select the <strong>R2 Token</strong> template or create a custom one with <strong>Object Read & Write</strong> permissions.</li>
                            <li><strong>Important:</strong> You are looking for the <strong>Access Key ID</strong> and <strong>Secret Access Key</strong>. <br>
                                <span class="text-danger fw-bold small">Note: DO NOT use the "API Token" (long string) in the password field. Use the S3-specific Secret Access Key.</span>
                            </li>
                        </ol>

                        <h6 class="fw-bold text-dark">step 4: find account id</h6>
                        <p class="mb-3">Go back to <strong>R2 > Overview</strong>. Your <strong>Account ID</strong> is in the right-hand sidebar. (It's a long string of letters and numbers).</p>

                        <h6 class="fw-bold text-dark">step 5: connect the dots</h6>
                        <p>Fill in the fields on the left. Use your Cloudflare <strong>Account ID</strong>, not a full endpoint URL. Fyuhls will automatically format the R2 endpoint as <code>{account_id}.r2.cloudflarestorage.com</code>.</p>
                        <div class="alert alert-info border-0 small mt-3 mb-0">
                            Add an R2 bucket CORS policy for your Fyuhls origin that allows <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and exposes <code>ETag</code>.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'wasabi'): ?>
                    <div class="small lh-lg">
                        <h6 class="fw-bold text-dark">grab a wasabi account</h6>
                        <p class="mb-3">go to <a href="https://wasabi.com/" target="_blank">wasabi</a> and sign up or log in.</p>

                        <h6 class="fw-bold text-dark">make a bucket</h6>
                        <ol class="mb-3">
                            <li>In the left sidebar, click on <strong>Buckets</strong>.</li>
                            <li>Click <strong>Create Bucket</strong>.</li>
                            <li><strong>Bucket Name:</strong> Give it a unique name and note it down.</li>
                            <li><strong>Region:</strong> Select the region closest to your users. Note this region exactly (e.g., <code>us-east-1</code>).</li>
                        </ol>

                        <h6 class="fw-bold text-dark">get your access keys</h6>
                        <ol class="mb-3">
                            <li>In the left sidebar, click on <strong>Access Keys</strong>.</li>
                            <li>Click <strong>Create Access Key</strong>.</li>
                            <li>Choose <strong>Sub-User</strong> if possible instead of <strong>Root User</strong>. This is the safer option for Fyuhls because it avoids giving the bucket credentials full account-wide access.</li>
                            <li>Create or select the user the key should belong to. A popup will appear containing your <strong>Access Key</strong> and <strong>Secret Key</strong>. <br>
                                <strong>IMPORTANT:</strong> Copy the <code>Secret Key</code> immediately. You cannot retrieve it later.</li>
                        </ol>

                        <h6 class="fw-bold text-dark">connect the dots</h6>
                        <p class="mb-2">Fill in the fields on the left like this, then click <strong>verify &amp; connect server</strong>:</p>
                        <ul class="mb-3 ps-3">
                            <li><strong>Bucket Name:</strong> your real Wasabi bucket name.</li>
                            <li><strong>Region:</strong> the exact region from your bucket or endpoint, such as <code>us-east-1</code>.</li>
                            <li><strong>Access Key / Secret Key:</strong> the key pair you just created in Wasabi.</li>
                            <li><strong>Wasabi Endpoint Host or URL:</strong> usually leave this blank. Fyuhls can build the endpoint from the region automatically as <code>https://s3.your-region.wasabisys.com</code>.</li>
                        </ul>
                        <div class="alert alert-light border small mt-3 mb-0">
                            <strong>Endpoint field tip:</strong> Only fill in <strong>Wasabi Endpoint Host or URL</strong> if you already know the exact Wasabi S3 endpoint and want Fyuhls to auto-detect the region from it. Example: <code>https://s3.us-east-1.wasabisys.com</code> or <code>s3.us-east-1.wasabisys.com</code>.
                        </div>
                        <div class="alert alert-info border-0 small mt-3 mb-0">
                            Add a Wasabi bucket CORS rule for your Fyuhls domain with <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and expose <code>ETag</code>.
                        </div>
                        <div class="bg-white border rounded p-3 small mt-3">
                            <div class="fw-bold mb-2">Recommended S3-compatible CORS rule</div>
<pre class="mb-2"><code>&lt;CORSConfiguration&gt;
  &lt;CORSRule&gt;
    &lt;AllowedOrigin&gt;https://yourdomain.com&lt;/AllowedOrigin&gt;
    &lt;AllowedMethod&gt;PUT&lt;/AllowedMethod&gt;
    &lt;AllowedMethod&gt;GET&lt;/AllowedMethod&gt;
    &lt;AllowedMethod&gt;HEAD&lt;/AllowedMethod&gt;
    &lt;AllowedHeader&gt;*&lt;/AllowedHeader&gt;
    &lt;ExposeHeader&gt;ETag&lt;/ExposeHeader&gt;
    &lt;MaxAgeSeconds&gt;3600&lt;/MaxAgeSeconds&gt;
  &lt;/CORSRule&gt;
&lt;/CORSConfiguration&gt;</code></pre>
                            <div class="text-muted extra-small">Replace <code>https://yourdomain.com</code> with the exact origin your users upload from.</div>
                        </div>
                        <div class="alert alert-secondary border-0 small mt-3 mb-0">
                            <strong>Where this goes:</strong> This is configured on the <strong>Wasabi bucket</strong>, not inside Fyuhls. If the Wasabi web console does not expose the full S3 CORS options you need, use the provider's S3-compatible API/CLI instead.
                        </div>
                    </div>
                <?php endif; ?>

                    <?php if ($activeTab === 's3'): ?>
                        <div class="small lh-lg">
                            <p>Use this for generic S3-compatible providers like <strong>DigitalOcean Spaces</strong>, <strong>Linode Object Storage</strong>, or your own <strong>MinIO</strong> instance.</p>
                            <strong>Requirements:</strong>
                        <ul>
                            <li>A valid S3 endpoint host or URL for the provider service.</li>
                            <li>A Bucket already created on the provider side.</li>
                            <li>Credentials with object read/write and multipart upload permissions.</li>
                        </ul>
                        <div class="alert alert-info border-0 small mt-3 mb-0">
                            Make sure the bucket CORS policy allows your Fyuhls origin, <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and exposes <code>ETag</code>.
                        </div>
                        <div class="alert alert-secondary border-0 small mt-3 mb-0">
                            <strong>Where this goes:</strong> This rule is configured on the <strong>storage provider bucket/service</strong>, not in Fyuhls. If the provider dashboard is limited, use the provider's S3-compatible API/CLI to set bucket CORS.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.transition-all { transition: all 0.2s ease-in-out; }
.extra-small { font-size: 0.75rem; }
.bg-light { background-color: #f8fafc !important; }
.add-server-tab-link { white-space: nowrap; margin-bottom: -1px; }
</style>

<?php include dirname(__DIR__) . '/footer.php'; ?>

<?php if ($activeTab === 'b2'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const demoReadOnly = <?= $demoAdminViewer ? 'true' : 'false' ?>;
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const keyInput = document.getElementById('b2KeyId');
    const secretInput = document.getElementById('b2SecretKey');
    const bucketInput = document.getElementById('b2BucketName');
    const regionInput = document.getElementById('b2Region');
    const endpointInput = document.getElementById('b2Endpoint');
    const discoverBtn = document.getElementById('b2DiscoverBtn');
    const applyCorsBtn = document.getElementById('b2ApplyCorsBtn');
    const bucketPickerWrap = document.getElementById('b2BucketPickerWrap');
    const bucketPicker = document.getElementById('b2BucketPicker');
    const statusBox = document.getElementById('b2AutomationStatus');
    let discoveredBuckets = [];

    const setStatus = (message, type = 'secondary') => {
        statusBox.className = 'alert border-0 small mb-0 alert-' + type;
        statusBox.innerHTML = message;
    };

    const setBusy = (busy) => {
        discoverBtn.disabled = busy;
        applyCorsBtn.disabled = busy;
        discoverBtn.textContent = busy ? 'Working...' : 'Load My B2 Buckets';
    };

    const fillBucketDetails = (bucket) => {
        if (!bucket) {
            return;
        }

        bucketInput.value = bucket.bucket_name || '';
        regionInput.value = bucket.region || regionInput.value;
        endpointInput.value = bucket.endpoint || endpointInput.value;
    };

    const renderBucketOptions = (buckets) => {
        discoveredBuckets = Array.isArray(buckets) ? buckets : [];
        bucketPicker.innerHTML = '';

        if (!discoveredBuckets.length) {
            bucketPickerWrap.classList.add('d-none');
            return;
        }

        discoveredBuckets.forEach((bucket, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = bucket.bucket_name + ' (' + bucket.bucket_type + ')';
            bucketPicker.appendChild(option);
        });

        bucketPickerWrap.classList.remove('d-none');
        fillBucketDetails(discoveredBuckets[0]);
    };

    bucketPicker.addEventListener('change', function () {
        const bucket = discoveredBuckets[parseInt(bucketPicker.value, 10)];
        fillBucketDetails(bucket);
    });

    discoverBtn.addEventListener('click', async function () {
        if (demoReadOnly) {
            setStatus('This demo admin account is read-only while demo mode is enabled.', 'warning');
            return;
        }
        if (!keyInput.value.trim() || !secretInput.value.trim()) {
            setStatus('Enter the B2 Key ID and Application Key first so Fyuhls can talk to your Backblaze account.', 'warning');
            return;
        }

        setBusy(true);
        setStatus('Connecting to Backblaze and loading your buckets...', 'info');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('key_id', keyInput.value.trim());
            formData.append('application_key', secretInput.value.trim());

            const response = await fetch('/admin/file-server/b2/discover', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not load Backblaze buckets.');
            }

            regionInput.value = result.region || regionInput.value;
            endpointInput.value = result.endpoint || endpointInput.value;
            renderBucketOptions(result.buckets || []);

            if ((result.buckets || []).length === 0) {
                setStatus('Backblaze connected, but this account does not currently expose any buckets Fyuhls can use. Create a bucket first, then try again.', 'warning');
            } else if ((result.buckets || []).length === 1) {
                setStatus('Backblaze connected. Fyuhls found 1 bucket and filled the bucket, region, and endpoint fields for you.', 'success');
            } else {
                setStatus('Backblaze connected. Choose the bucket you want to use and Fyuhls will fill the matching details above.', 'success');
            }
        } catch (error) {
            bucketPickerWrap.classList.add('d-none');
            setStatus(error.message || 'Could not load Backblaze buckets.', 'danger');
        } finally {
            setBusy(false);
        }
    });

    applyCorsBtn.addEventListener('click', async function () {
        if (demoReadOnly) {
            setStatus('This demo admin account is read-only while demo mode is enabled.', 'warning');
            return;
        }
        const bucketName = bucketInput.value.trim();
        if (!keyInput.value.trim() || !secretInput.value.trim() || !bucketName) {
            setStatus('Load your bucket first, or at least fill the Key ID, Application Key, and Bucket Name before applying CORS.', 'warning');
            return;
        }

        if (!confirm('Apply the recommended Fyuhls upload CORS rule to "' + bucketName + '" for <?= addslashes($trustedBaseUrl !== '' ? $trustedBaseUrl : 'your trusted site URL') ?>?')) {
            return;
        }

        setBusy(true);
        setStatus('Applying the recommended Fyuhls browser-upload CORS rule to your B2 bucket...', 'info');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('key_id', keyInput.value.trim());
            formData.append('application_key', secretInput.value.trim());
            formData.append('bucket_name', bucketName);

            const response = await fetch('/admin/file-server/b2/apply-cors', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not apply Backblaze CORS.');
            }

            setStatus('Fyuhls CORS was applied to <strong>' + result.bucket_name + '</strong> for <code>' + result.origin + '</code>. Backblaze may take about a minute to make the change live.', 'success');
        } catch (error) {
            setStatus(error.message || 'Could not apply Backblaze CORS.', 'danger');
        } finally {
            setBusy(false);
        }
    });
});
</script>
<?php endif; ?>
<?php if ($activeTab === 'wasabi'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const demoReadOnly = <?= $demoAdminViewer ? 'true' : 'false' ?>;
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    const accessKeyInput = document.getElementById('wasabiAccessKey');
    const secretKeyInput = document.getElementById('wasabiSecretKey');
    const bucketInput = document.getElementById('wasabiBucketName');
    const regionInput = document.getElementById('wasabiRegion');
    const endpointInput = document.getElementById('wasabiEndpoint');
    const discoverBtn = document.getElementById('wasabiDiscoverBtn');
    const applyCorsBtn = document.getElementById('wasabiApplyCorsBtn');
    const bucketPickerWrap = document.getElementById('wasabiBucketPickerWrap');
    const bucketPicker = document.getElementById('wasabiBucketPicker');
    const statusBox = document.getElementById('wasabiAutomationStatus');
    let discoveredBuckets = [];

    const setStatus = (message, type = 'secondary') => {
        statusBox.className = 'alert border-0 small mb-0 alert-' + type;
        statusBox.innerHTML = message;
    };

    const setBusy = (busy) => {
        discoverBtn.disabled = busy;
        applyCorsBtn.disabled = busy;
        discoverBtn.textContent = busy ? 'Working...' : 'Load My Wasabi Buckets';
    };

    const fillBucketDetails = (bucket) => {
        if (!bucket) {
            return;
        }

        bucketInput.value = bucket.bucket_name || '';
        regionInput.value = bucket.region || regionInput.value;
        endpointInput.value = bucket.endpoint || endpointInput.value;
    };

    const renderBucketOptions = (buckets) => {
        discoveredBuckets = Array.isArray(buckets) ? buckets : [];
        bucketPicker.innerHTML = '';

        if (!discoveredBuckets.length) {
            bucketPickerWrap.classList.add('d-none');
            return;
        }

        discoveredBuckets.forEach((bucket, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = bucket.bucket_name;
            bucketPicker.appendChild(option);
        });

        bucketPickerWrap.classList.remove('d-none');
        fillBucketDetails(discoveredBuckets[0]);
    };

    bucketPicker.addEventListener('change', function () {
        const bucket = discoveredBuckets[parseInt(bucketPicker.value, 10)];
        fillBucketDetails(bucket);
    });

    discoverBtn.addEventListener('click', async function () {
        if (demoReadOnly) {
            setStatus('This demo admin account is read-only while demo mode is enabled.', 'warning');
            return;
        }
        if (!accessKeyInput.value.trim() || !secretKeyInput.value.trim()) {
            setStatus('Enter the Wasabi Access Key and Secret Key first so Fyuhls can talk to your Wasabi account.', 'warning');
            return;
        }

        setBusy(true);
        setStatus('Connecting to Wasabi and loading your buckets...', 'info');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('access_key', accessKeyInput.value.trim());
            formData.append('secret_key', secretKeyInput.value.trim());
            formData.append('region', regionInput.value.trim());
            formData.append('endpoint', endpointInput.value.trim());

            const response = await fetch('/admin/file-server/wasabi/discover', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not load Wasabi buckets.');
            }

            regionInput.value = result.region || regionInput.value;
            endpointInput.value = result.endpoint || endpointInput.value;
            renderBucketOptions(result.buckets || []);

            if ((result.buckets || []).length === 0) {
                setStatus('Wasabi connected, but this key does not currently expose any buckets Fyuhls can use. Create a bucket first or confirm the key has bucket-list access.', 'warning');
            } else if ((result.buckets || []).length === 1) {
                setStatus('Wasabi connected. Fyuhls found 1 bucket and filled the bucket, region, and endpoint fields for you.', 'success');
            } else {
                setStatus('Wasabi connected. Choose the bucket you want to use and Fyuhls will fill the matching details above.', 'success');
            }
        } catch (error) {
            bucketPickerWrap.classList.add('d-none');
            setStatus(error.message || 'Could not load Wasabi buckets.', 'danger');
        } finally {
            setBusy(false);
        }
    });

    applyCorsBtn.addEventListener('click', async function () {
        if (demoReadOnly) {
            setStatus('This demo admin account is read-only while demo mode is enabled.', 'warning');
            return;
        }

        const bucketName = bucketInput.value.trim();
        if (!accessKeyInput.value.trim() || !secretKeyInput.value.trim() || !bucketName) {
            setStatus('Load your Wasabi bucket first, or at least fill the access key, secret key, and bucket name before applying CORS.', 'warning');
            return;
        }

        if (!confirm('Apply the recommended Fyuhls upload CORS rule to "' + bucketName + '" for <?= addslashes($trustedBaseUrl !== '' ? $trustedBaseUrl : 'your trusted site URL') ?>?')) {
            return;
        }

        setBusy(true);
        setStatus('Applying the recommended Fyuhls browser-upload CORS rule to your Wasabi bucket...', 'info');

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('access_key', accessKeyInput.value.trim());
            formData.append('secret_key', secretKeyInput.value.trim());
            formData.append('bucket_name', bucketName);
            formData.append('region', regionInput.value.trim());
            formData.append('endpoint', endpointInput.value.trim());

            const response = await fetch('/admin/file-server/wasabi/apply-cors', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not apply Wasabi CORS.');
            }

            setStatus('Fyuhls CORS was applied to <strong>' + result.bucket_name + '</strong> for <code>' + result.origin + '</code>. Wasabi may take about a minute to make the change live.', 'success');
        } catch (error) {
            setStatus(error.message || 'Could not apply Wasabi CORS.', 'danger');
        } finally {
            setBusy(false);
        }
    });
});
</script>
<?php endif; ?>
