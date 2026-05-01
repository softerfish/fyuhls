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
$captchaEnabledCount = 0;
foreach (($captchaPlacements ?? []) as $placementValue) {
    if ($placementValue === '1') {
        $captchaEnabledCount++;
    }
}
?>

<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Security Sections</div>
        <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
            <button class="nav-link text-start <?= $secTab === 'identity' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=identity">
                <i class="bi bi-shield-check me-2"></i> Protection
            </button>
            <button class="nav-link text-start <?= $secTab === 'keys' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=keys">
                <i class="bi bi-key me-2"></i> Encryption Keys
                <?php if (!empty($securityNoticeCounts['keys'])): ?>
                    <span class="badge bg-warning text-dark float-end"><?= (int)$securityNoticeCounts['keys'] ?></span>
                <?php endif; ?>
            </button>
            <button class="nav-link text-start <?= $secTab === 'captcha' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=captcha">
                <i class="bi bi-robot me-2"></i> Captcha
            </button>
            <button class="nav-link text-start <?= $secTab === 'cloudflare' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=cloudflare">
                <i class="bi bi-cloud-check me-2"></i> Cloudflare
            </button>
            <button class="nav-link text-start <?= $secTab === 'migration' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=migration">
                <i class="bi bi-database-lock me-2"></i> Migration
                <?php if (!empty($securityNoticeCounts['migration'])): ?>
                    <span class="badge bg-warning text-dark float-end"><?= (int)$securityNoticeCounts['migration'] ?></span>
                <?php endif; ?>
            </button>
            <button class="nav-link text-start <?= $secTab === 'health' ? 'active' : '' ?>" data-nav-url="?tab=security&sec_tab=health">
                <i class="bi bi-heart-pulse me-2"></i> Database Health
                <?php if (!empty($securityNoticeCounts['health'])): ?>
                    <span class="badge bg-warning text-dark float-end"><?= (int)$securityNoticeCounts['health'] ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>
    <div class="config-section-content">
        <div class="tab-content">
            <?php if ($secTab === 'keys'): ?>
                <div class="config-section-intro">
                    <div>
                        <h5 class="config-section-intro__title">Encryption Strength Audit</h5>
                        <p class="config-section-intro__text">Review the active encryption key format and generate a stronger enterprise-ready replacement when you are planning a full re-encryption window.</p>
                    </div>
                    <ul class="config-summary-chips">
                        <li class="config-summary-chip <?= $isBase64 ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Key: <?= htmlspecialchars($keyStrength) ?></li>
                        <li class="config-summary-chip <?= ($pendingEncryption ?? 0) > 0 ? 'config-summary-chip--warning' : 'config-summary-chip--success' ?>">Pending items: <?= (int)($pendingEncryption ?? 0) ?></li>
                    </ul>
                </div>
                <details class="config-help-panel">
                    <summary>How this works</summary>
                    <div class="config-help-panel__body">
                        <p>Fyuhls treats Base64-encoded 32-byte keys as the enterprise format. Key changes are high-impact and should only happen during a maintenance window with backups in place.</p>
                    </div>
                </details>
                <div class="card border-0 shadow-sm config-section-card">
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
                            <div class="config-soft-callout config-soft-callout--warning small">
                                <i class="bi bi-exclamation-triangle me-2"></i> 
                                <strong>Upgrade Recommended:</strong> Your current key is using a legacy format. 
                            </div>
                        <?php endif; ?>

                        <div class="config-danger-zone">
                            <div class="config-danger-zone__title">High-Risk Action</div>
                            <p class="config-danger-zone__text">Generate a new enterprise key only when you are prepared to run a full re-encryption maintenance window. This is not a casual rotate-and-save setting.</p>
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
                    <div class="config-section-intro">
                        <div>
                            <h5 class="config-section-intro__title">Protection</h5>
                            <p class="config-section-intro__text">Control VPN/proxy handling, registration limits, login pressure, and the baseline rules that shape who can reach the app.</p>
                        </div>
                        <ul class="config-summary-chips">
                            <li class="config-summary-chip config-summary-chip--info">Mode: <?= htmlspecialchars(ucfirst((string)($vpnProtectionMode ?? 'none'))) ?></li>
                            <li class="config-summary-chip config-summary-chip--info">Login limit: <?= (int)$rateLimitLogin ?>/5m</li>
                            <li class="config-summary-chip config-summary-chip--info">Registration: <?= (int)$rateLimitReg ?>/10m</li>
                        </ul>
                    </div>
                    <details class="config-help-panel">
                        <summary>How this works</summary>
                        <div class="config-help-panel__body">
                            <p>Use Enforcement mode when you want to block proxy traffic up front. Use Intelligence mode when you want to keep the visitor flow open but feed fraud scoring with stronger network signals.</p>
                        </div>
                    </details>
                    <div class="card border-0 shadow-sm config-section-card">
                        <div class="card-body">
                            <div class="fw-bold mb-3">Protection Mode</div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="vpn_proxy_mode" id="vpnModeNone" value="none" <?= ($vpnProtectionMode ?? 'none') === 'none' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vpnModeNone">
                                    <span class="fw-bold d-block">None</span>
                                    <span class="small text-muted">Do not query ProxyCheck and do not block VPN or proxy traffic. This is the default when no ProxyCheck API key is saved.</span>
                                </label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="vpn_proxy_mode" id="vpnModeEnforcement" value="enforcement" <?= ($vpnProtectionMode ?? '') === 'enforcement' ? 'checked' : '' ?>>
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
                        <small class="config-form-note">Optional paid integration. Required for Enforcement mode and for Intelligence mode lookups. If this field is empty, Protection Mode will fall back to None.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">VPN / Proxy Whitelist</label>
                        <textarea class="form-control" name="vpn_whitelist" rows="3" placeholder="127.0.0.1, 10.0.0.0/8, trusted-office-ip"><?= htmlspecialchars($vpnWhitelist ?? '') ?></textarea>
                        <small class="config-form-note">Optional comma-separated IPs, CIDR ranges, or trusted addresses that should bypass proxy blocking and intelligence scoring. Use this for office networks, monitoring probes, or approved admin access points.</small>
                    </div>

                    <h5 class="fw-bold mb-4 mt-5">Brute Force Prevention</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Login Rate Limit</label>
                            <input type="number" class="form-control" name="rate_limit_login" value="<?= $rateLimitLogin ?>">
                            <small class="config-form-note">Max attempts per 5 mins.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Registration Rate Limit</label>
                            <input type="number" class="form-control" name="rate_limit_registration" value="<?= $rateLimitReg ?>">
                            <small class="config-form-note">Max signups per IP address per 10 minutes.</small>
                        </div>
                    </div>

                    <div class="config-sticky-save">
                        <p class="config-sticky-save__text">Protection changes take effect as soon as this form is saved.</p>
                        <button type="submit" class="btn btn-primary px-4">Save Security Rules</button>
                    </div>
                </form>

                <hr class="my-5">

                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="security_features">
                    <h5 class="fw-bold mb-3">Two-Factor Authentication</h5>
                    <div class="card border-0 shadow-sm config-section-card">
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="two_factor_enabled" id="twoFactorEnabled" value="1" <?= !empty($twoFactorEnabled) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="twoFactorEnabled">Enable 2FA</label>
                            </div>
                            <label class="form-label fw-bold">Enforcement Start Date</label>
                            <input type="date" class="security-two-factor-date form-control" name="2fa_enforce_date" value="<?= htmlspecialchars($twoFactorEnforceDate ?? '') ?>">
                            <small class="config-form-note mt-2">Leave blank to keep 2FA optional. If set, users without 2FA will be forced to set it up after this date.</small>
                        </div>
                    </div>
                    <div class="config-sticky-save">
                        <p class="config-sticky-save__text">2FA settings are saved separately so you can adjust rollout timing without changing other protection rules.</p>
                        <button type="submit" class="btn btn-primary px-4">Save 2FA Settings</button>
                    </div>
                </form>
            <?php elseif ($secTab === 'cloudflare'): ?>
                <!-- Cloudflare content simplified for Hub -->
                <form method="POST" action="/admin/security/update?tab=cloudflare">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="config-section-intro">
                        <div>
                            <h5 class="config-section-intro__title">Cloudflare Integration</h5>
                            <p class="config-section-intro__text">Restore real visitor IPs safely and keep trusted proxy ranges in sync when the site is behind Cloudflare.</p>
                        </div>
                        <ul class="config-summary-chips">
                            <li class="config-summary-chip <?= !empty($trustCloudflare) ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Trust headers: <?= !empty($trustCloudflare) ? 'On' : 'Off' ?></li>
                        </ul>
                    </div>
                    <details class="config-help-panel">
                        <summary>How this works</summary>
                        <div class="config-help-panel__body">
                            <p>Only enable header trust when the site is actually proxied by Cloudflare and you are syncing their IP ranges. Otherwise request IPs and country signals can become unreliable.</p>
                        </div>
                    </details>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="trust_cloudflare" id="trustCf" value="1" <?= $trustCloudflare ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="trustCf">Trust Cloudflare Headers</label>
                    </div>
                    <div class="config-form-note mb-4">Enable this only when the site is actually behind Cloudflare and you are syncing trusted proxy ranges. Rewards fraud scoring, country detection, and security logs rely on the real visitor IP being restored correctly.</div>
                    <div class="config-sticky-save">
                        <p class="config-sticky-save__text">Trust settings affect request IP parsing across the app.</p>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
                <div class="config-utility-zone">
                    <div class="config-utility-zone__title">Utility Action</div>
                    <p class="config-utility-zone__text">Use this when you want to refresh Cloudflare's trusted proxy ranges immediately instead of waiting for the scheduled sync task.</p>
                    <form method="POST" action="/admin/security/sync" class="config-utility-zone__actions">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-outline-primary">Sync Cloudflare IP Ranges Now</button>
                    </form>
                </div>
            <?php elseif ($secTab === 'captcha'): ?>
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="captcha">
                    <div class="config-section-intro">
                        <div>
                            <h5 class="config-section-intro__title">Captcha</h5>
                            <p class="config-section-intro__text">Use Cloudflare Turnstile on the flows that attract the most spam, scraping, or abuse without overloading every page with challenges.</p>
                        </div>
                        <ul class="config-summary-chips">
                            <li class="config-summary-chip <?= $captchaEnabledCount > 0 ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Placements: <?= $captchaEnabledCount ?> enabled</li>
                            <li class="config-summary-chip <?= !empty($captchaSiteKey) ? 'config-summary-chip--info' : 'config-summary-chip--warning' ?>">Keys: <?= !empty($captchaSiteKey) ? 'Configured' : 'Missing' ?></li>
                        </ul>
                    </div>
                    <details class="config-help-panel">
                        <summary>How this works</summary>
                        <div class="config-help-panel__body">
                            <p>Enter your Turnstile site key and secret key, then enable the placements where you want the challenge to appear. If the keys are blank, the placement checkboxes do nothing.</p>
                        </div>
                    </details>
                    
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
                    <div class="config-soft-callout config-soft-callout--info">
                    <div class="row row-cols-1 row-cols-md-2 g-3">
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_user_login" id="capLogin" value="1" <?= ($captchaPlacements['captcha_user_login'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capLogin">Login</label>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_download_guest" id="capGuest" value="1" <?= ($captchaPlacements['captcha_download_guest'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capGuest">Guest Download</label>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_register" id="capReg" value="1" <?= ($captchaPlacements['captcha_register'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capReg">User Registration</label>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_download_free" id="capFree" value="1" <?= ($captchaPlacements['captcha_download_free'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capFree">Free User Download</label>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_report_file" id="capReport" value="1" <?= ($captchaPlacements['captcha_report_file'] === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capReport">Report File</label>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_contact" id="capContact" value="1" <?= (($captchaPlacements['captcha_contact'] ?? '0') === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capContact">Contact Us</label>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_dmca" id="capDmca" value="1" <?= (($captchaPlacements['captcha_dmca'] ?? '0') === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capDmca">DMCA Form</label>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="captcha_link_checker" id="capLinkChecker" value="1" <?= (($captchaPlacements['captcha_link_checker'] ?? '0') === '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="capLinkChecker">Link Checker</label>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div class="config-sticky-save">
                        <p class="config-sticky-save__text">Captcha placement changes update the public flows listed above and can be tuned independently from the rest of Security.</p>
                        <button type="submit" class="btn btn-primary px-4">Save Captcha Rules</button>
                    </div>
                </form>
            <?php elseif ($secTab === 'migration'): ?>
                <div class="config-section-intro">
                    <div>
                        <h5 class="config-section-intro__title">Enterprise Data Encryption</h5>
                        <p class="config-section-intro__text">Review pending plaintext fields and run the migration that rewrites supported sensitive data into the latest at-rest encryption format.</p>
                    </div>
                    <ul class="config-summary-chips">
                        <li class="config-summary-chip <?= $pendingEncryption > 0 ? 'config-summary-chip--warning' : 'config-summary-chip--success' ?>">Pending: <?= (int)$pendingEncryption ?></li>
                        <li class="config-summary-chip <?= $isBase64 ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Key: <?= $isBase64 ? 'Enterprise' : 'Legacy' ?></li>
                    </ul>
                </div>
                <details class="config-help-panel">
                    <summary>How this works</summary>
                    <div class="config-help-panel__body">
                        <p>The migration is designed for supported fields like usernames, emails, IP addresses, and other sensitive metadata. It is safest to run this during a controlled maintenance window.</p>
                    </div>
                </details>

                <div class="card border-0 shadow-sm config-section-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold">Status:</span>
                            <?php if ($pendingEncryption > 0): ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-clock-history me-1"></i> <?= $pendingEncryption ?> Items Pending</span>
                            <?php else: ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Fully Secured</span>
                            <?php endif; ?>
                        </div>

                        <div class="config-soft-callout config-soft-callout--info small mb-3">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            Encryption uses the key defined in <code>config/app.php</code>. Ensure you have backed up your key before proceeding.
                        </div>

                        <?php if ($pendingEncryption > 0 && !empty($pendingEncryptionItems)): ?>
                            <div class="config-soft-callout config-soft-callout--warning small mb-3">
                                <div class="fw-bold mb-2">Pending items detected</div>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($pendingEncryptionItems as $item): ?>
                                        <?php
                                        $table = (string)($item['table'] ?? '');
                                        $column = (string)($item['column'] ?? '');
                                        $pkPairs = [];
                                        foreach (($item['primary_keys'] ?? []) as $pkName => $pkValue) {
                                            $pkPairs[] = (string)$pkName . '=' . (string)$pkValue;
                                        }
                                        $pkSummary = implode(', ', $pkPairs);
                                        ?>
                                        <li class="mb-2">
                                            <strong><?= htmlspecialchars($table) ?>.<?= htmlspecialchars($column) ?></strong>
                                            <?php if ($pkSummary !== ''): ?>
                                                <span class="text-muted">(<?= htmlspecialchars($pkSummary) ?>)</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if ($pendingEncryption > count($pendingEncryptionItems)): ?>
                                    <div class="text-muted mt-2">Showing the first <?= count($pendingEncryptionItems) ?> pending items out of <?= (int)$pendingEncryption ?> total.</div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($pendingEncryption > 0 && !empty($demoAdmin)): ?>
                            <div class="config-soft-callout config-soft-callout--warning small mb-3">
                                <div class="fw-bold mb-1">Pending items detected</div>
                                <div>Detailed pending-item references are hidden for the demo admin account.</div>
                            </div>
                        <?php endif; ?>

                        <?php if ($pendingEncryption > 0): ?>
                            <div class="config-danger-zone">
                                <div class="config-danger-zone__title">High-Risk Action</div>
                                <p class="config-danger-zone__text">This writes encrypted replacements back into the database. Run it during a controlled window after confirming backups and key availability.</p>
                                <form method="POST" action="/admin/security/migrate" class="config-danger-zone__actions">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                                        <i class="bi bi-lock-fill me-2"></i> Secure All Pending Data
                                    </button>
                                </form>
                            </div>
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
                            <div class="config-soft-callout small">
                                <span class="d-block fw-bold small mb-1">Expand Columns</span>
                                <p class="small text-muted mb-2">Ensures all DB columns are large enough for encrypted strings.</p>
                                <button class="btn btn-sm btn-outline-dark disabled" disabled>Columns Optimized</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($secTab === 'health'): ?>
                <div class="config-section-intro">
                    <div>
                        <h5 class="config-section-intro__title">Database Health</h5>
                        <p class="config-section-intro__text">Compare the live schema to Fyuhls' source-of-truth database map and optionally repair deeper drift.</p>
                    </div>
                    <ul class="config-summary-chips">
                        <li class="config-summary-chip <?= !empty($securityNoticeCounts['health']) ? 'config-summary-chip--warning' : 'config-summary-chip--success' ?>">Drift notices: <?= (int)($securityNoticeCounts['health'] ?? 0) ?></li>
                    </ul>
                </div>
                <details class="config-help-panel">
                    <summary>How this works</summary>
                    <div class="config-help-panel__body">
                        <p>Run the normal sync when you want to check for missing tables or columns. Use deep repair only when you specifically need to correct column-type or column-size drift.</p>
                    </div>
                </details>
                <form method="POST" action="/admin/security/sync-schema">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="repair_drift" id="repairDrift" value="1">
                        <label class="form-check-label" for="repairDrift">Deep Repair (Fix column type/size drift)</label>
                    </div>
                    <div class="config-danger-zone">
                        <div class="config-danger-zone__title">High-Risk Action</div>
                        <p class="config-danger-zone__text">Schema sync writes directly to the database. Deep repair is more invasive because it can alter column shapes as well as missing structure.</p>
                        <div class="config-danger-zone__actions">
                            <button type="submit" class="btn btn-danger px-4">Run Schema Sync</button>
                        </div>
                    </div>
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

