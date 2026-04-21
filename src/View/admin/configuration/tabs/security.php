<?php
// Data is now passed from ConfigurationController
// $migrationService and $pendingEncryption are already available in scope

// Key Strength Logic
$currentKey = \App\Core\Config::get('security.encryption_key', '');
$isBase64 = (base64_decode($currentKey, true) !== false && strlen(base64_decode($currentKey)) === 32);
$isHex = (ctype_xdigit($currentKey) && strlen($currentKey) === 32);
$keyStrength = $isBase64 ? 'Enterprise' : ($isHex ? 'Legacy (128-bit Entropy)' : 'Weak/Invalid');
$strengthClass = $isBase64 ? 'text-success' : 'text-danger';
$generatedEnterpriseKey = \App\Service\EncryptionService::generateKey();
$maskedEnterpriseKey = str_repeat('*', max(24, strlen($generatedEnterpriseKey)));

$secTab = $_GET['sec_tab'] ?? 'identity';
?>

<div class="row">
    <div class="col-md-3">
        <div class="nav flex-column nav-pills border-end pe-3" id="v-pills-tab" role="tablist" aria-orientation="vertical">
            <button class="nav-link text-start mb-2 <?= $secTab === 'identity' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=identity">
                <i class="bi bi-shield-check me-2"></i> Identity & VPN
            </button>
            <button class="nav-link text-start mb-2 <?= $secTab === 'keys' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=keys">
                <i class="bi bi-key me-2"></i> Encryption Keys
                <?php if (!empty($securityNoticeCounts['keys'])): ?>
                    <span class="badge bg-warning text-dark float-end"><?= (int)$securityNoticeCounts['keys'] ?></span>
                <?php endif; ?>
            </button>
            <button class="nav-link text-start mb-2 <?= $secTab === 'captcha' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=captcha">
                <i class="bi bi-robot me-2"></i> Captcha
            </button>
            <button class="nav-link text-start mb-2 <?= $secTab === 'cloudflare' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=cloudflare">
                <i class="bi bi-cloud-check me-2"></i> Cloudflare
            </button>
            <button class="nav-link text-start mb-2 <?= $secTab === 'migration' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=migration">
                <i class="bi bi-database-lock me-2"></i> Migration
                <?php if (!empty($securityNoticeCounts['migration'])): ?>
                    <span class="badge bg-warning text-dark float-end"><?= (int)$securityNoticeCounts['migration'] ?></span>
                <?php endif; ?>
            </button>
            <button class="nav-link text-start mb-2 <?= $secTab === 'health' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=health">
                <i class="bi bi-heart-pulse me-2"></i> Database Health
                <?php if (!empty($securityNoticeCounts['health'])): ?>
                    <span class="badge bg-warning text-dark float-end"><?= (int)$securityNoticeCounts['health'] ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>
    <div class="col-md-9">
        <div class="tab-content ps-3">
            <?php if ($secTab === 'keys'): ?>
                <h5 class="fw-bold mb-4">Encryption Strength Audit</h5>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold">Current Encryption Standard:</span>
                            <span class="<?= $strengthClass ?> fw-bold"><?= $keyStrength ?></span>
                        </div>
                        <p class="small text-muted">
                            AES-256 requires 32 bytes of entropy. A 32-character hexadecimal key only provides 128 bits of true entropy. 
                            Enterprise sites should use a Base64-encoded binary key for maximum brute-force resistance.
                        </p>
                        
                        <?php if (!$isBase64): ?>
                            <div class="alert alert-warning border-0 small">
                                <i class="bi bi-exclamation-triangle me-2"></i> 
                                <strong>Upgrade Recommended:</strong> Your current key is using a legacy format. 
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 pt-3 border-top">
                            <label class="form-label small fw-bold text-uppercase">Generate New Enterprise Key</label>
                            <div class="input-group">
                                <input
                                    type="text"
                                    id="newSecureKey"
                                    class="form-control font-monospace small"
                                    readonly
                                    value="<?= htmlspecialchars($maskedEnterpriseKey) ?>"
                                    data-masked="<?= htmlspecialchars($maskedEnterpriseKey) ?>"
                                    data-actual="<?= htmlspecialchars($generatedEnterpriseKey) ?>"
                                    data-visible="0"
                                >
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-security-action="toggle-generated-key" data-security-target="newSecureKey">Show</button>
                                <button type="button" class="btn btn-outline-dark btn-sm" data-security-action="copy-to-clipboard" data-security-target="newSecureKey">Copy</button>
                            </div>
                            <small class="text-danger mt-2 d-block">
                                <i class="bi bi-shield-slash me-1"></i> <strong>WARNING:</strong> Changing your encryption key without a full re-encryption pass will make existing encrypted data unreadable.
                            </small>
                            <small class="text-muted mt-2 d-block">
                                <strong>What re-encrypting means:</strong> put the site in maintenance mode, take a full database and config backup, keep the old key available, decrypt existing encrypted rows with the old key, rewrite them with the new key, then verify logins, emails, file servers, and payment-related settings before returning the site to normal operation.
                            </small>
                        </div>
                    </div>
                </div>
            <?php elseif ($secTab === 'identity'): ?>
                <form method="POST" action="/admin/security/update?tab=identity">
                    <?= \App\Core\Csrf::field() ?>
                    <h5 class="fw-bold mb-4">VPN & Bot Protection</h5>
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body">
                            <div class="fw-bold mb-3">Protection Mode</div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="vpn_proxy_mode" id="vpnModeEnforcement" value="enforcement" <?= ($vpnProtectionMode ?? 'enforcement') === 'enforcement' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vpnModeEnforcement">
                                    <span class="fw-bold d-block">Enforcement mode</span>
                                    <span class="small text-muted">Block VPN/proxy traffic before it reaches the app.</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="vpn_proxy_mode" id="vpnModeIntelligence" value="intelligence" <?= ($vpnProtectionMode ?? '') === 'intelligence' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vpnModeIntelligence">
                                    <span class="fw-bold d-block">Intelligence mode</span>
                                    <span class="small text-muted">Query proxycheck.io, store the result on the download session/receipt, and use it for fraud scoring without blocking. This gives the Rewards Fraud page stronger proxy and VPN detection signals even when you do not want to hard-block the visitor.</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">ProxyCheck.io API Key</label>
                        <div class="input-group">
                            <input type="<?= !empty($demoAdmin) ? 'text' : 'password' ?>" class="form-control" id="proxycheckApiKey" name="proxycheck_api_key" value="<?= htmlspecialchars($proxycheckApiKey) ?>" placeholder="Your API Key" <?= !empty($demoAdmin) ? 'readonly' : '' ?>>
                            <?php if (empty($demoAdmin)): ?>
                                <button type="button" class="btn btn-outline-secondary" data-security-action="toggle-sensitive-input" data-security-target="proxycheckApiKey">Show</button>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">Optional paid integration. Required for Enforcement mode and for Intelligence mode lookups.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">VPN / Proxy Whitelist</label>
                        <textarea class="form-control" name="vpn_whitelist" rows="3" placeholder="127.0.0.1, 10.0.0.0/8, trusted-office-ip"><?= htmlspecialchars($vpnWhitelist ?? '') ?></textarea>
                        <small class="text-muted">Optional comma-separated IPs, CIDR ranges, or trusted addresses that should bypass proxy blocking and intelligence scoring. Use this for office networks, monitoring probes, or approved admin access points.</small>
                    </div>

                    <h5 class="fw-bold mb-4 mt-5">Brute Force Prevention</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Login Rate Limit</label>
                            <input type="number" class="form-control" name="rate_limit_login" value="<?= $rateLimitLogin ?>">
                            <small class="text-muted">Max attempts per 5 mins.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration Rate Limit</label>
                            <input type="number" class="form-control" name="rate_limit_registration" value="<?= $rateLimitReg ?>">
                            <small class="text-muted">Max signups per 5 mins.</small>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top">
                        <button type="submit" class="btn btn-primary px-4">Save Security Rules</button>
                    </div>
                </form>

                <hr class="my-5">

                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="security_features">
                    <h5 class="fw-bold mb-4">Two-Factor Authentication</h5>
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="two_factor_enabled" id="twoFactorEnabled" value="1" <?= !empty($twoFactorEnabled) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="twoFactorEnabled">Enable 2FA</label>
                            </div>
                            <label class="form-label fw-bold">Enforcement Start Date</label>
                            <input type="date" class="security-two-factor-date form-control" name="2fa_enforce_date" value="<?= htmlspecialchars($twoFactorEnforceDate ?? '') ?>">
                            <small class="text-muted d-block mt-2">Leave blank to keep 2FA optional. If set, users without 2FA will be forced to set it up after this date.</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary px-4">Save 2FA Settings</button>
                </form>
            <?php elseif ($secTab === 'cloudflare'): ?>
                <!-- Cloudflare content simplified for Hub -->
                <form method="POST" action="/admin/security/update?tab=cloudflare">
                    <?= \App\Core\Csrf::field() ?>
                    <h5 class="fw-bold mb-4">Cloudflare Integration</h5>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="trust_cloudflare" id="trustCf" value="1" <?= $trustCloudflare ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="trustCf">Trust Cloudflare Headers</label>
                    </div>
                    <div class="small text-muted mb-4">Enable this only when the site is actually behind Cloudflare and you are syncing trusted proxy ranges. Rewards fraud scoring, country detection, and security logs rely on the real visitor IP being restored correctly.</div>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
                <hr class="my-5">
                <form method="POST" action="/admin/security/sync">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn-outline-primary">Sync Cloudflare IP Ranges Now</button>
                </form>
            <?php elseif ($secTab === 'captcha'): ?>
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="captcha">
                    <h5 class="fw-bold mb-2">Bot Protection (Cloudflare Turnstile)</h5>
                    <p class="text-muted small mb-4">Enter your Turnstile site key and secret key, then enable the placements where you want the challenge to appear. If the keys are blank, the placement checkboxes do nothing.</p>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Site Key</label>
                            <input type="text" class="form-control" name="captcha_site_key" value="<?= htmlspecialchars($captchaSiteKey) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Secret Key</label>
                            <input type="password" class="form-control" name="captcha_secret_key" value="" placeholder="<?= !empty($captchaSecretKey) ? 'Saved. Leave blank to keep current.' : 'Turnstile secret key' ?>" autocomplete="off" spellcheck="false">
                        </div>
                    </div>

                    <h6 class="fw-bold mt-4 mb-3">Captcha Placements</h6>
                    <div class="row bg-light p-3 rounded">
                        <div class="col-md-6">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="captcha_user_login" id="capLogin" value="1" <?= ($captchaPlacements['captcha_user_login'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capLogin">Login</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="captcha_register" id="capReg" value="1" <?= ($captchaPlacements['captcha_register'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capReg">User Registration</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="captcha_download_guest" id="capGuest" value="1" <?= ($captchaPlacements['captcha_download_guest'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capGuest">Guest Download</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="captcha_download_free" id="capFree" value="1" <?= ($captchaPlacements['captcha_download_free'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capFree">Free User Download</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="captcha_report_file" id="capReport" value="1" <?= ($captchaPlacements['captcha_report_file'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capReport">Report File</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="captcha_contact" id="capContact" value="1" <?= (($captchaPlacements['captcha_contact'] ?? '0') === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capContact">Contact Us</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="captcha_dmca" id="capDmca" value="1" <?= (($captchaPlacements['captcha_dmca'] ?? '0') === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capDmca">DMCA Form</label>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border shadow-sm small mt-4 mb-0">
                        <div class="fw-bold mb-1">How this works</div>
                        <div>Login protects the shared account sign-in page for both normal users and administrators.</div>
                        <div>User Registration protects account creation.</div>
                        <div>Guest Download and Free User Download protect download flows by audience.</div>
                        <div>Report File protects the abuse-report form on public file pages.</div>
                        <div>Contact Us and DMCA Form protect those public legal/support submission forms from spam and bot abuse.</div>
                    </div>

                    <div class="mt-4 pt-3 border-top">
                        <button type="submit" class="btn btn-primary px-4">Save Captcha Rules</button>
                    </div>
                </form>
            <?php elseif ($secTab === 'migration'): ?>
                <h5 class="fw-bold mb-4">Enterprise Data Encryption</h5>
                <p class="text-muted small mb-4">
                    Upgrade your database to the latest encryption standard (AES-256). 
                    This will secure sensitive PII like Emails, Usernames, and IP Addresses.
                </p>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold">Status:</span>
                            <?php if ($pendingEncryption > 0): ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-clock-history me-1"></i> <?= $pendingEncryption ?> Items Pending</span>
                            <?php else: ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Fully Secured</span>
                            <?php endif; ?>
                        </div>

                        <div class="alert alert-info border-0 small">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            Encryption uses the key defined in <code>config/app.php</code>. Ensure you have backed up your key before proceeding.
                        </div>

                        <?php if ($pendingEncryption > 0): ?>
                            <form method="POST" action="/admin/security/migrate">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                                    <i class="bi bi-lock-fill me-2"></i> Secure All Pending Data
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-light w-100 py-2 disabled" disabled>
                                <i class="bi bi-shield-check me-2"></i> Database is fully encrypted
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <h6 class="fw-bold small text-uppercase text-muted mb-3">Advanced Maintenance</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 border rounded bg-light">
                                <span class="d-block fw-bold small mb-1">Expand Columns</span>
                                <p class="small text-muted mb-2">Ensures all DB columns are large enough for encrypted strings.</p>
                                <button class="btn btn-sm btn-outline-dark disabled" disabled>Columns Optimized</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($secTab === 'health'): ?>
                <h5 class="fw-bold mb-4">Database Integrity</h5>
                <p>Ensure your database schema matches the application source of truth.</p>
                <form method="POST" action="/admin/security/sync-schema">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="repair_drift" id="repairDrift" value="1">
                        <label class="form-check-label" for="repairDrift">Deep Repair (Fix column type/size drift)</label>
                    </div>
                    <button type="submit" class="btn btn-danger px-4">Run Schema Sync</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleGeneratedKey(inputId, button) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const isVisible = input.getAttribute('data-visible') === '1';
    if (isVisible) {
        input.value = input.getAttribute('data-masked') || '';
        input.setAttribute('data-visible', '0');
        button.textContent = 'Show';
    } else {
        input.value = input.getAttribute('data-actual') || '';
        input.setAttribute('data-visible', '1');
        button.textContent = 'Hide';
    }
}

function toggleSensitiveInput(inputId, button) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const isPassword = input.getAttribute('type') === 'password';
    input.setAttribute('type', isPassword ? 'text' : 'password');
    button.textContent = isPassword ? 'Hide' : 'Show';
}

function copyToClipboard(inputId) {
    const input = document.getElementById(inputId);
    if (!input) {
        return;
    }

    navigator.clipboard.writeText(input.value || '').then(function() {
        alert('Copied to clipboard.');
    }).catch(function() {
        alert('Unable to copy to clipboard.');
    });
}

document.addEventListener('click', function(event) {
    const actionButton = event.target.closest('[data-security-action]');
    if (!actionButton) {
        return;
    }

    const action = actionButton.getAttribute('data-security-action');
    const targetId = actionButton.getAttribute('data-security-target') || '';
    if (action === 'toggle-generated-key') {
        toggleGeneratedKey(targetId, actionButton);
    } else if (action === 'copy-to-clipboard') {
        copyToClipboard(targetId);
    } else if (action === 'toggle-sensitive-input') {
        toggleSensitiveInput(targetId, actionButton);
    }
});
</script>

<style>
.security-two-factor-date{max-width:300px}
</style>
