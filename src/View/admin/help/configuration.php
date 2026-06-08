<div class="help-section">
    <h6 class="fw-bold text-dark mb-3">Configuration Hub</h6>
    <p class="small text-muted">Config Hub is the site-wide control surface. Each tab saves its own section only, so changing one tab does not overwrite the rest.</p>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">When To Use Config Hub</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Use Config Hub when:</strong> You are changing a site-wide default, security rule, delivery behavior, queue setting, monetization master switch, or SEO policy.</li>
            <li><strong>Use another page instead when:</strong> You are editing one package, working a ticket, moderating a file, or changing public-site copy. Those belong to their dedicated admin pages.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">Main Tabs</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>General:</strong> Site name, notifications, registration policy, maintenance, demo mode, footer branding, image-thumbnail GD readiness, and FFmpeg video-thumbnail support.</li>
            <li><strong>Security:</strong> ProxyCheck mode and scope, whitelist entries, 2FA, captcha, Cloudflare trust, encryption migration, and schema health.</li>
            <li><strong>Email:</strong> SMTP, queue rate, test tools, and templates.</li>
            <li><strong>Tickets:</strong> Support inbox address, email triggers, rate limits, and waiting-on-user reminder controls.</li>
            <li><strong>Monetization:</strong> Rewards, affiliate, payout, ads, payment gateways, and related behavior.</li>
            <li><strong>Downloads / Uploads:</strong> Site-wide transfer, upload, link-checker, and save-to-account defaults.</li>
            <li><strong>SEO / Cron:</strong> Search-engine behavior and job-frequency controls.</li>
        </ul>
    </div>

    <div class="alert alert-light border-0 small py-2">
        <strong>Tip:</strong> If a setting affects every user or every request path, it probably belongs in <strong>Config Hub</strong>. If it affects just one plan, one queue item, or one content page, it probably belongs somewhere else.
    </div>
</div>
