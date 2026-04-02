<div class="help-section">
    <h6 class="fw-bold text-dark mb-3">Configuration Hub</h6>
    <p class="small text-muted">Config Hub is the main control panel for site-wide behavior. Each tab saves only its own section, so changing one tab does not overwrite the others. Use the save button at the bottom of the tab you are editing.</p>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">1. General</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Site Name:</strong> Changes the brand label used across the frontend, emails, and admin area. Edit the field, then click <strong>Save General Configuration</strong>.</li>
            <li><strong>Admin Notification Email:</strong> Sets the address that receives DMCA, abuse, and admin-facing alerts. Change the address, then save the General tab.</li>
            <li><strong>Reserved Usernames:</strong> Blocks specific names from being registered. Enter a comma-separated list, then save the General tab.</li>
            <li><strong>Allow New Registrations:</strong> Opens or closes public signup. Toggle the switch, then save the General tab.</li>
            <li><strong>Require Email Verification:</strong> Forces new users to verify email before login access. Toggle the switch, then save the General tab.</li>
            <li><strong>Maintenance Mode:</strong> Restricts the frontend to admins only. Turn it on only when you are ready for a maintenance window, then save the General tab.</li>
            <li><strong>Demo Mode:</strong> Enables the demo-mode feature set used with the designated demo admin account. Toggle it here, then save the General tab.</li>
            <li><strong>Show Powered By:</strong> Controls the branded footer credit on the public site. Toggle the switch, then save the General tab.</li>
            <li><strong>FFmpeg Settings:</strong> Enables video thumbnail generation and stores the FFmpeg binary path. Turn the switch on, enter the server path, then save the General tab.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">2. Security</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Identity &amp; VPN:</strong> Controls ProxyCheck mode, whitelist entries, and login or registration rate limits. Change the fields on that sub-tab, then click <strong>Save Security Rules</strong>.</li>
            <li><strong>2FA:</strong> Uses the separate 2FA card inside Security. Turn it on, optionally choose an enforcement date, then click <strong>Save 2FA Settings</strong>.</li>
            <li><strong>Captcha:</strong> Stores Turnstile keys and placement switches for login, registration, guest download, free-user download, file reports, contact, and DMCA forms. Change the keys or switches, then click <strong>Save Captcha Rules</strong>.</li>
            <li><strong>Cloudflare:</strong> Controls trusted Cloudflare header handling and the IP-range sync action. Save the toggle on that sub-tab, then use the sync button whenever proxy ranges need refreshing.</li>
            <li><strong>Migration / Health:</strong> Runs pending encryption work and schema repair tools. These are action buttons, not ordinary save forms.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">3. Email And Storage</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Email:</strong> Stores SMTP host, auth, sender details, rate limits, and system templates. Change the SMTP form, then click <strong>Save SMTP Config</strong>. Template edits are saved from the template modal itself.</li>
            <li><strong>Storage:</strong> Controls file-server inventory, statuses, capacity planning, delivery methods, connection tests, migrations, and provider-specific tools like the B2 bucket picker and B2 CORS automation. Storage nodes are changed from their own add, edit, and migrate screens.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">4. Monetization, SEO, Cron Jobs, Downloads, Uploads</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Monetization:</strong> Covers rewards, affiliate, payout rules, ad placements, PPD tiers, and payment-gateway settings. Change the fields in that tab and use its save button.</li>
            <li><strong>SEO:</strong> Controls title templates, homepage metadata, sitemap behavior, robots rules, file-page templates, structured data, and search-engine verification. Edit the fields on the SEO tab, then save that tab.</li>
            <li><strong>Cron Jobs:</strong> Changes the managed task intervals stored in the database. Edit the frequency fields and click <strong>Save Frequencies</strong>. The manual trigger button is separate.</li>
            <li><strong>Downloads:</strong> Controls account requirement, country blocks, active connection tracking, remote URL background processing, streaming support, Nginx completion log settings, and optional CDN redirects. Edit those fields and click <strong>Save Download Settings</strong>.</li>
            <li><strong>Uploads:</strong> Controls browser upload behavior, guest-versus-login policy, deduplication, multipart support, upload tray behavior, and filename-in-URL behavior. Edit those fields and click <strong>Save Upload Configuration</strong>.</li>
        </ul>
    </div>

    <div class="alert alert-light border-0 small py-2">
        <strong>Tip:</strong> When you are unsure where a setting lives, use the tab name first. Settings that affect quotas or user entitlements usually belong to <strong>Packages</strong>, while site-wide defaults belong to <strong>Config Hub</strong>.
    </div>
</div>
