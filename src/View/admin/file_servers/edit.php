<?php include dirname(__DIR__) . '/header.php'; ?>
<?php $trustedBaseUrl = \App\Service\SeoService::trustedBaseUrl() ?? ''; ?>
<?php
$demoAdminViewer = \App\Service\DemoModeService::currentViewerIsDemoAdmin();
$hiddenKeyPlaceholder = $demoAdminViewer
    ? 'Hidden for demo admin'
    : 'Saved value hidden. Enter a new value to replace it.';
?>

<div class="page-header mb-4">
    <h1>Edit Storage Server: <?= htmlspecialchars($server['name']) ?></h1>
    <a href="/admin/configuration?tab=storage" class="btn btn-outline-secondary btn-sm">&larr; Back to Servers</a>
</div>

<?php if ($demoAdminViewer): ?>
    <div class="alert alert-warning border-0 shadow-sm small mb-4">
        <strong>Demo admin mode:</strong> Storage configuration is read-only for this account. This page is view-only.
    </div>
<?php endif; ?>

<!-- Detect active tab based on server type -->
<?php
$type = $server['server_type'];
$activeTab = $type; // default
if ($type === 's3') {
    // try to guess if it's B2, Wasabi or R2 based on endpoint
    $endpoint = $config['s3_endpoint'] ?? '';
    if (str_contains($endpoint, 'backblazeb2.com')) $activeTab = 'b2';
    elseif (str_contains($endpoint, 'wasabisys.com')) $activeTab = 'wasabi';
    elseif (str_contains($endpoint, 'r2.cloudflarestorage.com')) $activeTab = 'r2';
    elseif (preg_match('/^[a-f0-9]{32}$/i', $endpoint)) $activeTab = 'r2'; // raw account id
}

$tabs = [
    'local' => ['label' => 'Local Storage', 'icon' => 'bi-hdd'],
    'b2'    => ['label' => 'Backblaze B2', 'icon' => 'bi-cloud'],
    'r2'    => ['label' => 'Cloudflare R2', 'icon' => 'bi-cloud-sun'],
    'wasabi'=> ['label' => 'Wasabi S3', 'icon' => 'bi-snow'],
    's3'    => ['label' => 'S3 Compatible', 'icon' => 'bi-box-seam'],
];
if (!isset($tabs[$activeTab])) {
    $activeTab = 's3';
}
?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-0">
        <div class="d-flex border-bottom overflow-auto bg-light rounded-top">
            <div class="px-4 py-3 text-primary fw-bold small border-bottom border-primary border-3 bg-white">
                <i class="bi <?= $tabs[$activeTab]['icon'] ?> me-2"></i> Editing <?= $tabs[$activeTab]['label'] ?> Configuration
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Configuration Form -->
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0">Server Configuration</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= \App\Core\Csrf::field() ?>
                    <fieldset <?= $demoAdminViewer ? 'disabled' : '' ?>>
                    <input type="hidden" name="type" value="<?= $server['server_type'] ?>">
                    <input type="hidden" name="provider_preset" value="<?= $activeTab ?>">

                    <div class="mb-4">
                        <label class="form-label fw-bold small">Server Friendly Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($server['name']) ?>" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= $server['status'] === 'active' ? 'selected' : '' ?>>Active (Accepting Uploads)</option>
                            <option value="read-only" <?= $server['status'] === 'read-only' ? 'selected' : '' ?>>Read-Only (Downloads Only)</option>
                            <option value="disabled" <?= $server['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>

                    <?php if (in_array($activeTab, ['b2', 'r2', 'wasabi', 's3'], true)): ?>
                        <div class="alert alert-info border-0 shadow-sm small mb-4">
                            <strong>Browser Multipart Requirement:</strong> Verify bucket CORS still allows your exact Fyuhls origin, allows <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and exposes the <code>ETag</code> header. If uploads fail after a storage change, check CORS first.
                        </div>
                        <div class="mb-4 p-3 border rounded bg-light">
                            <div class="fw-bold small mb-2">Provider Validation Checklist</div>
                            <ul class="extra-small text-muted mb-0 ps-3">
                                <li>Bucket name still matches the storage path exactly.</li>
                                <li>The app key still allows upload, object reads, and multipart completion for this bucket.</li>
                                <li>CORS still allows your exact Fyuhls origin with <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>.</li>
                                <li><code>ETag</code> remains exposed to the browser.</li>
                                <li>Endpoint, region, and any public URL still point at the same bucket/account.</li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <hr class="my-4">

                    <!-- Provider Specific Fields -->
                    <?php if ($activeTab === 'local'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Storage Path</label>
                            <input type="text" name="path" class="form-control" value="<?= htmlspecialchars($server['storage_path']) ?>" required>
                            <div class="text-muted extra-small mt-1">Keep local storage inside Fyuhls' <code>storage/</code> directory. Absolute paths outside that directory are blocked for safety.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'b2'): ?>
                        <div class="alert alert-warning border-0 shadow-sm small mb-4">
                            <strong>B2 lifecycle recommendation:</strong> For Fyuhls, set the bucket lifecycle to <strong>Keep only the last version of the file</strong>. Dedup still works normally because Fyuhls tracks shared files in its own database, but this B2 setting prevents old hidden versions from piling up in the bucket browser after deletes.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">B2 Bucket Name</label>
                            <input type="text" name="path" id="b2BucketName" class="form-control" value="<?= htmlspecialchars($server['storage_path']) ?>" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">B2 Region</label>
                                <input type="text" name="config[s3_region]" id="b2Region" class="form-control" value="<?= htmlspecialchars($config['s3_region'] ?? '') ?>" required>
                                <div class="text-muted extra-small mt-1">Use the region from your real B2 S3 endpoint, like <code>us-east-005</code> from <code>s3.us-east-005.backblazeb2.com</code>.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Key ID (Access Key)</label>
                                <input type="password" name="config[s3_key]" id="b2KeyId" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">B2 S3 Endpoint Host or URL (Optional)</label>
                            <input type="text" name="config[s3_endpoint]" id="b2Endpoint" class="form-control" value="<?= htmlspecialchars($config['s3_endpoint'] ?? '') ?>" placeholder="e.g. s3.us-east-005.backblazeb2.com">
                            <div class="text-muted extra-small mt-1">Optional shortcut. Paste the exact B2 S3 endpoint if you want Fyuhls to auto-detect the region. If left blank, we build it from the region above.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Application Key (Secret Key)</label>
                            <input type="password" name="config[s3_secret]" id="b2SecretKey" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                            <div class="text-muted extra-small mt-1">For the automation buttons below, re-enter the current Application Key here so Fyuhls can talk to Backblaze on your behalf.</div>
                        </div>
                        <div class="border rounded p-3 bg-light mb-4">
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="b2DiscoverBtn">Reload My B2 Buckets</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="b2ApplyCorsBtn">Apply Fyuhls CORS</button>
                            </div>
                        <div class="text-muted extra-small mb-3">Use this if you want Fyuhls to confirm your B2 credentials, reload your buckets, refresh the region and endpoint, or re-apply the recommended upload CORS rule for <code><?= htmlspecialchars($trustedBaseUrl !== '' ? $trustedBaseUrl : 'your trusted site URL') ?></code>. <strong>Save Server Settings</strong> only saves the Fyuhls record. <strong>Apply Fyuhls CORS</strong> is the button that actually updates the real B2 bucket CORS rule.</div>
                            <div class="mb-3 d-none" id="b2BucketPickerWrap">
                                <label class="form-label fw-bold small">Choose a Backblaze Bucket</label>
                                <select class="form-select" id="b2BucketPicker"></select>
                                <div class="text-muted extra-small mt-1">Selecting a bucket updates the bucket name, region, and endpoint fields above.</div>
                            </div>
                            <div class="alert alert-secondary border-0 small mb-0" id="b2AutomationStatus">
                            If you want Fyuhls to automate the B2 checks here, re-enter your Application Key and click <strong>Reload My B2 Buckets</strong>. Use <strong>Apply Fyuhls CORS</strong> when you need Fyuhls to write the upload CORS rule to the real B2 bucket again, especially after a bucket change, key rotation, or multipart/preflight error.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'wasabi'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Wasabi Bucket Name</label>
                            <input type="text" name="path" class="form-control" value="<?= htmlspecialchars($server['storage_path']) ?>" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Wasabi Region</label>
                                <input type="text" name="config[s3_region]" class="form-control" value="<?= htmlspecialchars($config['s3_region'] ?? '') ?>" required>
                                <div class="text-muted extra-small mt-1">Use the region from your real Wasabi endpoint, like <code>us-east-1</code> from <code>s3.us-east-1.wasabisys.com</code>.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Access Key</label>
                                <input type="password" name="config[s3_key]" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Wasabi Endpoint Host or URL (Optional)</label>
                            <input type="text" name="config[s3_endpoint]" class="form-control" value="<?= htmlspecialchars($config['s3_endpoint'] ?? '') ?>" placeholder="e.g. s3.us-east-1.wasabisys.com">
                            <div class="text-muted extra-small mt-1">Optional shortcut. Paste the exact Wasabi S3 endpoint if you want Fyuhls to auto-detect the region. If left blank, we build it from the region above.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Secret Key</label>
                            <input type="password" name="config[s3_secret]" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                        </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'r2'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">R2 Bucket Name</label>
                            <input type="text" name="path" class="form-control" value="<?= htmlspecialchars($server['storage_path']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Cloudflare Account ID</label>
                            <?php 
                                $accountId = str_replace('.r2.cloudflarestorage.com', '', $config['s3_endpoint'] ?? '');
                            ?>
                            <input type="text" name="config[s3_endpoint]" class="form-control" value="<?= htmlspecialchars($accountId) ?>" required>
                            <div class="text-muted extra-small mt-1">Use the Account ID shown in Cloudflare Dashboard &rsaquo; Storage &amp; Databases &rsaquo; R2 Object Storage &rsaquo; Overview. Fyuhls will build the endpoint for you.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Access Key ID</label>
                                <input type="password" name="config[s3_key]" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                                <div class="text-muted extra-small mt-1">Cloudflare Dashboard &rsaquo; R2 &rsaquo; Overview &rsaquo; Manage R2 API Tokens &rsaquo; Create API Token</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Secret Access Key</label>
                                <input type="password" name="config[s3_secret]" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                                <div class="text-muted extra-small mt-1">Only shown once when you create the API token. If lost, delete the token and create a new one.</div>
                            </div>
                        </div>
                        <input type="hidden" name="config[s3_region]" value="auto">
                    <?php endif; ?>

                    <?php if ($activeTab === 's3'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Bucket Name</label>
                            <input type="text" name="path" class="form-control" value="<?= htmlspecialchars($server['storage_path']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Endpoint URL</label>
                            <input type="text" name="config[s3_endpoint]" class="form-control" value="<?= htmlspecialchars($config['s3_endpoint'] ?? '') ?>" required>
                            <div class="text-muted extra-small mt-1">Use the provider endpoint host or URL for the object storage service, not the bucket name by itself. Localhost, private-network, and metadata-style endpoints are blocked.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Region</label>
                                <input type="text" name="config[s3_region]" class="form-control" value="<?= htmlspecialchars($config['s3_region'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Access Key</label>
                                <input type="password" name="config[s3_key]" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Secret Key</label>
                                <input type="password" name="config[s3_secret]" class="form-control" value="" placeholder="<?= htmlspecialchars($hiddenKeyPlaceholder) ?>" autocomplete="off" spellcheck="false">
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr class="my-4">

                    <div class="mb-4">
                        <label class="form-label fw-bold small">Public Download URL (Optional)</label>
                        <?php 
                        $suggestedUrl = '';
                        if ($activeTab === 'b2') $suggestedUrl = "https://f00X.backblazeb2.com/file/" . $server['storage_path'] . "/";
                        elseif ($activeTab === 'wasabi') $suggestedUrl = "https://s3." . ($config['s3_region'] ?? 'us-east-1') . ".wasabisys.com/" . $server['storage_path'] . "/";
                        elseif ($activeTab === 'r2') $suggestedUrl = "https://pub-XXXXXX.r2.dev/";
                        else $suggestedUrl = ($trustedBaseUrl !== '' ? $trustedBaseUrl : 'https://yourdomain.com') . '/storage/uploads/';
                        ?>
                        <input type="text" name="url" class="form-control" value="<?= htmlspecialchars($server['public_url']) ?>" placeholder="e.g. <?= $suggestedUrl ?>">
                        <div class="text-muted extra-small mt-2">
                            <strong>When to fill this in:</strong>
                            <ul class="mb-2 ps-3">
                                <li>Use this only if the bucket is public or if you have a public CDN/custom domain in front of the bucket.</li>
                                <li>Leave it empty if you want Fyuhls to keep tighter control over access, payout verification, and download gating.</li>
                            </ul>
                            <strong>Examples:</strong>
                            <ul class="mb-2 ps-3">
                                <?php if ($activeTab === 'b2'): ?>
                                    <li><code>https://f00X.backblazeb2.com/file/your-bucket/</code> or your CDN/custom domain in front of B2</li>
                                <?php elseif ($activeTab === 'wasabi'): ?>
                                    <li><code>https://your-bucket.s3.your-region.wasabisys.com/</code> or your CDN/custom domain in front of Wasabi</li>
                                <?php elseif ($activeTab === 'r2'): ?>
                                    <li><code>https://pub-your-id.r2.dev/</code> or your Cloudflare custom domain</li>
                                <?php elseif ($activeTab === 's3'): ?>
                                    <li><code>https://your-bucket.your-provider.com/</code> or your CDN/custom domain in front of the bucket</li>
                                <?php else: ?>
                                    <li><code><?= htmlspecialchars(($trustedBaseUrl !== '' ? $trustedBaseUrl : 'https://yourdomain.com') . '/storage/uploads/') ?></code></li>
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
                            <option value="php" <?= $server['delivery_method'] === 'php' ? 'selected' : '' ?>>App-Controlled (PHP) - Fyuhls reads and streams the file itself</option>
                            <option value="nginx" <?= $server['delivery_method'] === 'nginx' ? 'selected' : '' ?>>Nginx Handoff - Fyuhls authorizes, then Nginx serves the file</option>
                            <option value="apache" <?= $server['delivery_method'] === 'apache' ? 'selected' : '' ?>>Apache Handoff - Fyuhls authorizes, then Apache serves the file</option>
                            <option value="litespeed" <?= $server['delivery_method'] === 'litespeed' ? 'selected' : '' ?>>LiteSpeed Handoff - Fyuhls authorizes, then LiteSpeed serves the file</option>
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
                            <strong>Filesystem handoff note:</strong> Apache and LiteSpeed handoff tests only make sense when this storage server resolves to a real filesystem path that the web server can read. Remote object-storage connectors should use the normal connection check plus a real download test instead of assuming a handoff header proves the cloud path.
                            <br><br>
                            <strong>Cloudflare note:</strong> If the site is behind Cloudflare, also configure Nginx real-IP restoration so the completion log records the real visitor IP. Fyuhls Cloudflare trust fixes PHP-side IP handling, but the Nginx completion log still depends on Nginx's own <code>real_ip_header</code> and <code>set_real_ip_from</code> configuration.
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

                    <div class="mb-4">
                        <label class="form-label fw-bold small">Max Capacity (Bytes)</label>
                        <input type="number" name="capacity" class="form-control" value="<?= $server['max_capacity_bytes'] ?>">
                        <small class="text-muted">0 = Unlimited</small>
                    </div>

                    <div class="d-grid gap-2 mt-5">
                        <button type="submit" class="btn btn-primary btn-lg"><?= $demoAdminViewer ? 'Read-Only in Demo Mode' : 'Save Server Settings' ?></button>
                    </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>

    <!-- Stats & Health -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 bg-light h-100">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-4 text-primary">Server Health</h5>
                <div class="mb-4">
                    <div class="small text-muted text-uppercase fw-bold">Current Usage</div>
                    <div class="h3 fw-bold"><?= number_format($server['current_usage_bytes'] / 1024 / 1024 / 1024, 2) ?> GB</div>
                </div>
                <div class="alert alert-info border-0 small">
                    <i class="bi bi-info-circle me-1"></i> Changing the storage path or bucket name on an active server will make existing files unreachable. Only do this if you are performing a manual migration.
                </div>

                <?php if (in_array($activeTab, ['b2', 'r2', 'wasabi', 's3'], true)): ?>
                    <div class="alert alert-primary border-0 small mt-3">
                        <i class="bi bi-cloud-check me-1"></i> Multipart uploads depend on bucket CORS. Keep your Fyuhls origin allowed for <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and expose <code>ETag</code> to the browser.
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'b2'): ?>
                    <div class="alert alert-warning border-0 small mt-3">
                        <strong>Backblaze note:</strong> Fyuhls uses the <strong>S3-compatible</strong> multipart path. Start with the built-in automation here: re-enter the current Application Key, click <strong>Reload My B2 Buckets</strong> if needed, and then click <strong>Apply Fyuhls CORS</strong>. Only use the manual recovery block below if the automation button cannot complete the update or you need to repair a bucket with unusual existing custom rules.
                    </div>
                    <div class="bg-white border rounded p-3 small mt-3">
                        <div class="fw-bold mb-2">B2 edit checklist</div>
                        <ol class="mb-0 ps-3">
                            <li>Confirm the bucket name still matches the same B2 bucket that already holds your files.</li>
                            <li>Confirm the region still matches the real endpoint, such as <code>us-east-005</code> from <code>s3.us-east-005.backblazeb2.com</code>.</li>
                            <li>If you rotated your Backblaze key, update both the key ID and the secret here.</li>
                            <li>Leave the secret blank only if you want to keep the currently saved one.</li>
                            <li>If browser uploads start failing, check bucket CORS first. Large Fyuhls uploads depend on the browser being allowed to send multipart <code>PUT</code> requests directly to B2 and read back <code>ETag</code>.</li>
                            <li>If browser uploads fail, re-check the bucket's real CORS rules and confirm they still allow your exact site origin, <code>PUT</code>, <code>GET</code>, and <code>HEAD</code>, and expose <code>ETag</code>.</li>
                        </ol>
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
                    <div class="bg-white border rounded p-3 small mt-3">
                        <div class="fw-bold mb-2">Manual recovery block if the automation button is not enough</div>
                        <p class="mb-2">If uploads start failing with a browser CORS or preflight error and <strong>Apply Fyuhls CORS</strong> cannot fix it, paste this into <strong>Windows PowerShell</strong> after replacing the placeholder values:</p>
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
                        <div class="text-muted extra-small">This updates the real B2 bucket CORS rule through the Native API. Use it only when the built-in Fyuhls automation cannot finish the update or you need a direct repair path.</div>
                    </div>
                    <div class="alert alert-secondary border-0 small mt-3">
                        <strong>Where this goes:</strong> This rule is configured on the <strong>Backblaze bucket itself</strong>, not in Fyuhls. Use the bucket settings if available, or the Native API / other provider tools if Backblaze says custom rules are already in place.
                    </div>
                <?php endif; ?>

                <hr class="my-4">

                <h6 class="fw-bold mb-3"><i class="bi bi-speedometer2 me-2"></i>Test Delivery Configuration</h6>
                <p class="extra-small text-muted mb-3">Verify if your <strong><?= strtoupper($server['delivery_method']) ?></strong> delivery handoff is configured correctly on your web server.</p>
                
                <a href="<?= $demoAdminViewer ? '#' : '/admin/file-server/test-delivery/' . $server['id'] ?>" class="btn btn-outline-primary btn-sm w-100 <?= $demoAdminViewer ? 'disabled' : '' ?>" <?= $demoAdminViewer ? 'aria-disabled="true" tabindex="-1"' : 'target="_blank"' ?>>
                    <i class="bi bi-box-arrow-up-right me-2"></i> Run Delivery Test
                </a>
                
                <div class="mt-3 bg-white p-2 rounded border extra-small text-muted">
                    <strong>How to read results:</strong>
                    <ul class="mb-0 ps-3 mt-1">
                        <li><strong>Success:</strong> A file downloads named <code>fyuhls_test.txt</code>.</li>
                        <li><strong>Fail:</strong> You see a 404 or 500 error from your web server.</li>
                    </ul>
                </div>
                <?php if (in_array($server['delivery_method'], ['apache', 'litespeed'], true)): ?>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <strong><?= $server['delivery_method'] === 'apache' ? 'Apache' : 'LiteSpeed' ?> payout note:</strong> This test confirms the delivery handoff itself. If <code>ppd_min_download_percent</code> is greater than <code>0</code>, standard file downloads that need threshold-based PPD verification still fall back to <strong>App-Controlled (PHP)</strong>.
                    </div>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <strong><?= $server['delivery_method'] === 'apache' ? 'Apache' : 'LiteSpeed' ?> filesystem note:</strong> This handoff test expects the storage connector to resolve to a real filesystem path the web server can read. If this server points at remote object storage, use the connection check and a real download flow instead of treating this test as proof of cloud-delivery handoff.
                    </div>
                <?php endif; ?>
                <?php if ($server['delivery_method'] === 'nginx'): ?>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <strong>Nginx completion note:</strong> This test confirms the delivery handoff itself. It does <strong>not</strong> prove the completion log pipeline is fully wired. For Nginx threshold-based PPD, your Nginx config must also write the dedicated completion access log, and Fyuhls must be pointed at the same path in <strong>Config Hub &gt; Downloads</strong>.
                    </div>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <strong>Cloudflare real-IP note:</strong> If the site is behind Cloudflare, make sure Nginx is also configured with <code>real_ip_header CF-Connecting-IP;</code> and the current Cloudflare <code>set_real_ip_from</code> ranges. Otherwise the completion log may contain a Cloudflare proxy IP instead of the real visitor IP.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

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
        discoverBtn.textContent = busy ? 'Working...' : 'Reload My B2 Buckets';
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

        const existingIndex = discoveredBuckets.findIndex((bucket) => bucket.bucket_name === bucketInput.value.trim());
        bucketPicker.value = String(existingIndex >= 0 ? existingIndex : 0);
        fillBucketDetails(discoveredBuckets[parseInt(bucketPicker.value, 10)]);
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
            setStatus('Re-enter the current B2 Key ID and Application Key first. Fyuhls cannot reload buckets from Backblaze without both values.', 'warning');
            return;
        }

        setBusy(true);
        setStatus('Connecting to Backblaze and reloading your buckets...', 'info');

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
            } else {
                setStatus('Backblaze connected. Pick a bucket if needed, and Fyuhls will keep the bucket, region, and endpoint fields in sync.', 'success');
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
            setStatus('Re-enter the Application Key and make sure the bucket name is filled in before applying the Fyuhls CORS rule.', 'warning');
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
