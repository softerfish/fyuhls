<?php include __DIR__ . '/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Rewards Fraud</h1>
        <p class="text-muted mb-0">Review held earnings, inspect risk signals, and tune how rewardable traffic is scored and cleared.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Held Earnings</div><div class="fs-4 fw-bold">$<?= number_format((float)($overview['held_earnings'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Flagged Earnings</div><div class="fs-4 fw-bold text-danger">$<?= number_format((float)($overview['flagged_earnings'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Cleared Today</div><div class="fs-4 fw-bold text-success">$<?= number_format((float)($overview['cleared_today'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Reversed Today</div><div class="fs-4 fw-bold">$<?= number_format((float)($overview['reversed_today'] ?? 0), 2) ?></div></div></div>
    </div>
    <div class="col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">High-Risk Uploaders</div><div class="fs-4 fw-bold"><?= (int)($overview['high_risk_uploaders'] ?? 0) ?></div></div></div>
    </div>
    <div class="col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Review Queue</div><div class="fs-4 fw-bold"><?= (int)($overview['review_queue'] ?? 0) ?></div></div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Intelligence Health</h5>
                <div class="small text-muted mb-3">Cloudflare is the strongest built-in source for visitor network context. If this looks weak, review Config Hub &gt; Security &gt; Cloudflare before trusting country and network scoring.</div>
                <div class="alert alert-info border-0 shadow-sm small mb-3">
                    <strong>Want stronger fraud signals?</strong> Turn on <strong>Intelligence mode</strong> in <a href="/admin/configuration?tab=security&sec_tab=identity">Config Hub &gt; Security &gt; Identity &amp; VPN</a>. That lets fyuhls query ProxyCheck, attach proxy/VPN intelligence to reward sessions, and use it in fraud scoring without blocking the visitor.
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>Trust Cloudflare Headers</span>
                    <strong><?= !empty($cloudflareHealth['trust_cloudflare']) ? 'On' : 'Off' ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>Trusted Proxy Ranges</span>
                    <strong><?= (int)($cloudflareHealth['trusted_proxy_count'] ?? 0) ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span>Cloudflare Header Seen</span>
                    <strong><?= !empty($cloudflareHealth['cf_header_seen']) ? 'Yes' : 'No' ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span>Detected Visitor IP</span>
                    <strong><?= htmlspecialchars((string)($cloudflareHealth['real_ip_source'] ?? 'Unknown')) ?></strong>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Protection Settings</h5>
                <form method="POST" action="/admin/rewards-fraud/save">
                    <?= \App\Core\Csrf::field() ?>
                    <?php
                    $switches = [
                        'rewards_fraud_enabled' => 'Enable Rewards Fraud Protection',
                        'rewards_verified_completion_required' => 'Require Verified Completion for Reward Credit',
                        'rewards_auto_clear_low_risk' => 'Auto-Clear Low-Risk Earnings',
                        'rewards_use_cloudflare_intel' => 'Use Cloudflare Integration Data',
                        'rewards_use_proxy_intel' => 'Use Proxy Intelligence Data',
                        'rewards_use_ip_hash' => 'Use IP Hash',
                        'rewards_use_ua_hash' => 'Use User-Agent Hash',
                        'rewards_use_cookie_hash' => 'Use Visitor Cookie Hash',
                        'rewards_use_accept_language_hash' => 'Use Accept-Language Hash',
                        'rewards_use_timezone_offset' => 'Use Timezone Offset',
                        'rewards_use_platform_screen' => 'Use Platform + Screen Bucket',
                        'rewards_use_asn_network' => 'Use ASN + Network Classification',
                        'rewards_ppd_guests_only' => 'PPD Guests Only',
                        'rewards_require_downloader_verification' => 'Require Downloader Email Verification',
                        'rewards_block_linked_downloader_accounts' => 'Block Linked Downloader Accounts',
                        'rewards_hold_new_account_downloads' => 'Hold New Downloader Accounts',
                    ];
                    $switchDescriptions = [
                        'rewards_fraud_enabled' => 'Master switch for the rewards fraud review and scoring system.',
                        'rewards_verified_completion_required' => 'Only count rewarded traffic when the app has strong proof that the download or playback actually completed enough to qualify.',
                        'rewards_auto_clear_low_risk' => 'Allows low-risk earnings to clear automatically instead of waiting for manual review every time.',
                        'rewards_use_cloudflare_intel' => 'Uses Cloudflare-restored visitor and network context when Cloudflare is configured correctly.',
                        'rewards_use_proxy_intel' => 'Uses proxy or VPN intelligence, such as ProxyCheck lookups, as part of the fraud score.',
                        'rewards_use_ip_hash' => 'Uses a privacy-safe IP-derived signal to cluster repeat traffic without relying on raw IPs alone.',
                        'rewards_use_ua_hash' => 'Uses browser user-agent patterns to spot repeated clients across different sessions or IPs.',
                        'rewards_use_cookie_hash' => 'Uses the first-party visitor cookie to detect repeat visitors even when the IP changes.',
                        'rewards_use_accept_language_hash' => 'Adds browser language settings as a lightweight consistency signal.',
                        'rewards_use_timezone_offset' => 'Adds the browser timezone offset as another soft fingerprinting signal.',
                        'rewards_use_platform_screen' => 'Uses basic platform and screen-size buckets to strengthen browser-level clustering.',
                        'rewards_use_asn_network' => 'Uses network owner and network type data, such as ISP, datacenter, or hosting classification.',
                        'rewards_ppd_guests_only' => 'Only let guest downloads count toward pay-per-download rewards.',
                        'rewards_require_downloader_verification' => 'Require the downloader account to have a verified email before rewarded traffic can count.',
                        'rewards_block_linked_downloader_accounts' => 'Block or heavily penalize downloader accounts that look linked to the uploader.',
                        'rewards_hold_new_account_downloads' => 'Put very new downloader accounts into a held state instead of trusting them immediately.',
                    ];
                    $numberDescriptions = [
                        'rewards_hold_days' => 'How many days earnings should stay on hold before they are eligible to clear.',
                        'rewards_min_downloader_account_age_days' => 'Minimum age, in days, a downloader account should be before it is treated as lower risk.',
                        'rewards_review_threshold' => 'Risk score where traffic should move into manual review instead of clearing normally.',
                        'rewards_flag_threshold' => 'Higher risk score where traffic should be treated as strongly suspicious and flagged.',
                        'rewards_fraud_event_retention_days' => 'How long detailed fraud-session and event records should be kept before cleanup.',
                        'rewards_fraud_trim_mb' => 'Approximate size limit where older fraud event detail should begin trimming to protect storage and database growth.',
                    ];
                    foreach ($switches as $key => $label):
                    ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="<?= $key ?>" id="<?= $key ?>" value="1" <?= (($settings[$key] ?? '0') === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                            <div class="small text-muted"><?= htmlspecialchars($switchDescriptions[$key] ?? '') ?></div>
                        </div>
                    <?php endforeach; ?>

                    <div class="row mt-4">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Hold Period (Days)</label>
                            <input type="number" class="form-control" name="rewards_hold_days" value="<?= htmlspecialchars($settings['rewards_hold_days'] ?? '7') ?>" min="0">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_hold_days']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Min Downloader Account Age</label>
                            <input type="number" class="form-control" name="rewards_min_downloader_account_age_days" value="<?= htmlspecialchars($settings['rewards_min_downloader_account_age_days'] ?? '0') ?>" min="0">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_min_downloader_account_age_days']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Review Threshold</label>
                            <input type="number" class="form-control" name="rewards_review_threshold" value="<?= htmlspecialchars($settings['rewards_review_threshold'] ?? '25') ?>" min="0">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_review_threshold']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Flag Threshold</label>
                            <input type="number" class="form-control" name="rewards_flag_threshold" value="<?= htmlspecialchars($settings['rewards_flag_threshold'] ?? '50') ?>" min="1">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_flag_threshold']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Event Retention (Days)</label>
                            <input type="number" class="form-control" name="rewards_fraud_event_retention_days" value="<?= htmlspecialchars($settings['rewards_fraud_event_retention_days'] ?? '30') ?>" min="7">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_fraud_event_retention_days']) ?></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Trim Threshold (MB)</label>
                            <input type="number" class="form-control" name="rewards_fraud_trim_mb" value="<?= htmlspecialchars($settings['rewards_fraud_trim_mb'] ?? '1024') ?>" min="64">
                            <div class="small text-muted mt-1"><?= htmlspecialchars($numberDescriptions['rewards_fraud_trim_mb']) ?></div>
                        </div>
                    </div>

                    <div class="alert alert-light border small mt-3">
                        <div class="fw-bold mb-1">What to look for</div>
                        <div>Repeated visitor cookies across many IPs usually indicates replay automation.</div>
                        <div>Premium-country traffic from hosting or proxy-classified networks should usually be held before payout.</div>
                        <div>If Cloudflare intelligence looks weak, review Config Hub &gt; Security &gt; Cloudflare before trusting country/network insights.</div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">Save Rewards Fraud Settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Review Queue</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>File</th>
                                <th>Status</th>
                                <th>Risk</th>
                                <th>Reasons</th>
                                <th>Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reviewQueue)): ?>
                                <tr><td colspan="6" class="text-muted">No earnings are currently held or flagged.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reviewQueue as $row): ?>
                                    <?php $reasons = json_decode((string)($row['risk_reasons_json'] ?? '[]'), true) ?: []; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['username'] ?? ('User #' . (int)$row['user_id'])) ?></td>
                                        <td><?= htmlspecialchars($row['filename'] ?? ('File #' . (int)$row['file_id'])) ?></td>
                                        <td><span class="badge bg-<?= ($row['status'] ?? '') === 'flagged_review' ? 'danger' : 'warning text-dark' ?>"><?= htmlspecialchars((string)$row['status']) ?></span></td>
                                        <td><strong><?= (int)($row['risk_score'] ?? 0) ?></strong></td>
                                        <td>
                                            <?php if (empty($reasons)): ?>
                                                <span class="text-muted small">Held for manual review.</span>
                                            <?php else: ?>
                                                <ul class="small mb-0 ps-3">
                                                    <?php foreach (array_slice($reasons, 0, 3) as $reason): ?>
                                                        <li><?= htmlspecialchars((string)$reason) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </td>
                                        <td style="min-width: 230px;">
                                            <form method="POST" action="/admin/rewards-fraud/review">
                                                <?= \App\Core\Csrf::field() ?>
                                                <input type="hidden" name="earning_id" value="<?= (int)$row['id'] ?>">
                                                <select class="form-select form-select-sm mb-2" name="review_action">
                                                    <option value="clear">Clear</option>
                                                    <option value="hold">Keep Held</option>
                                                    <option value="reverse">Reverse</option>
                                                </select>
                                                <input type="text" class="form-control form-control-sm mb-2" name="review_note" placeholder="Optional review note">
                                                <button type="submit" class="btn btn-sm btn-primary w-100">Apply</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Uploader Risk Scores</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Uploader</th>
                                <th>Risk Score</th>
                                <th>Held</th>
                                <th>Flagged</th>
                                <th>Signals</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($uploaderScores)): ?>
                                <tr><td colspan="5" class="text-muted">No uploader risk data has been aggregated yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($uploaderScores as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['username'] ?? ('User #' . (int)$row['user_id'])) ?></td>
                                        <td><strong><?= (int)($row['risk_score'] ?? 0) ?></strong></td>
                                        <td><?= (int)($row['held_count'] ?? 0) ?></td>
                                        <td><?= (int)($row['flagged_count'] ?? 0) ?></td>
                                        <td class="small text-muted"><?= (int)($row['suspicious_file_count'] ?? 0) ?> suspicious files, <?= (int)($row['suspicious_network_count'] ?? 0) ?> suspicious networks</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body">
                <h5 class="fw-bold mb-3">Network Insights</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>ASN</th>
                                <th>Country</th>
                                <th>Network</th>
                                <th>Sessions</th>
                                <th>Held</th>
                                <th>Flagged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($networkInsights)): ?>
                                <tr><td colspan="6" class="text-muted">No network clusters have been summarized yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($networkInsights as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($row['asn'] ?? 'Unknown')) ?></td>
                                        <td><?= htmlspecialchars((string)($row['country_code'] ?? '--')) ?></td>
                                        <td><?= htmlspecialchars((string)($row['network_type'] ?? 'unknown')) ?></td>
                                        <td><?= (int)($row['session_count'] ?? 0) ?></td>
                                        <td><?= (int)($row['held_count'] ?? 0) ?></td>
                                        <td><?= (int)($row['flagged_count'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
