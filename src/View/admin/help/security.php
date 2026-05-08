<div class="p-1">
    <p class="guide-purpose mb-4">Use the Security tab for identity protection, bot protection, encryption hygiene, and trusted-proxy behavior. It is the place for security posture and infrastructure trust, not everyday moderation work.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Use it when:</strong> You need to change VPN or proxy policy, login protection, 2FA rules, Turnstile placement, Cloudflare trust, or encryption-maintenance actions.</li>
        <li class="mb-2"><strong>Use another page when:</strong> You need to review abuse, DMCA, or reward-fraud items. Those pages consume the security signals set here.</li>
        <li><strong>What it does not do:</strong> It does not replace package-level user experience settings or page-level moderation queues.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Identity &amp; VPN</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Protection Mode:</strong> <strong>None</strong> disables ProxyCheck use, <strong>Enforcement</strong> blocks suspicious traffic, and <strong>Intelligence</strong> stores proxy signals without blocking by itself.</li>
        <li class="mb-2"><strong>Enforcement Scope:</strong> Choose whether enforcement applies to <strong>all public pages</strong> or only <strong>download pages</strong>.</li>
        <li class="mb-2"><strong>ProxyCheck API Key:</strong> Required for both Enforcement and Intelligence modes.</li>
        <li class="mb-2"><strong>Whitelist:</strong> Trusted IPs or CIDR ranges that should bypass VPN or proxy enforcement and scoring.</li>
        <li><strong>Login and registration rate limits:</strong> Caps authentication pressure even when proxy blocking is not the main control.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Other Security Sections</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>2FA:</strong> Enable globally, then optionally set an enforcement date once you are ready to require it.</li>
        <li class="mb-2"><strong>Captcha:</strong> Turnstile keys and per-placement switches for login, registration, public forms, and download surfaces.</li>
        <li class="mb-2"><strong>Cloudflare:</strong> Only trust Cloudflare headers if the site is truly behind Cloudflare and your proxy ranges are current.</li>
        <li class="mb-2"><strong>Migration:</strong> Encrypt pending legacy data without rotating the live key unexpectedly.</li>
        <li><strong>Database Health:</strong> Schema sync and repair tools for structural drift.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Choose the ProxyCheck mode first:</strong> Decide whether you want blocking, intelligence-only scoring, or neither.</li>
        <li><strong>Set the scope deliberately:</strong> Use download-page-only enforcement if you want the public site to stay reachable while still protecting transfer surfaces.</li>
        <li><strong>Whitelist safe networks before enforcement testing:</strong> Office IPs, staging probes, or trusted gateways should be added first.</li>
        <li><strong>Turn on Cloudflare trust only after syncing ranges:</strong> This keeps spoofed client headers from becoming trusted.</li>
        <li><strong>Change keys cautiously:</strong> Encryption-key work is operationally sensitive and should be handled during a planned maintenance window.</li>
    </ol>

    <div class="alert alert-danger border-0 shadow-sm small mb-3">
        <strong>Critical:</strong> Do not replace the live encryption key unless you understand the re-encryption implications for existing stored data.
    </div>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/rewards-fraud" class="guide-action-link">Rewards Fraud</a> for downstream risk review, <a href="/admin/configuration?tab=downloads" class="guide-action-link">Config Hub &gt; Downloads</a> for transfer-side behavior that can combine with VPN policy, and <a href="/admin/status" class="guide-action-link">System Status</a> for encryption, queue, and schema health checks.
    </div>
</div>
