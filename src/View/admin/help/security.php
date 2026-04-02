<div class="p-1">
    <p class="guide-purpose mb-4">Use the Security tab to control identity protection, bot protection, encryption, and schema health. The tab is split into sub-sections, each with its own save or action buttons.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Security Sections</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Identity &amp; VPN:</strong> ProxyCheck mode, whitelist entries, login and registration rate limits, and the built-in 2FA policy.</li>
        <li class="mb-2"><strong>Encryption Keys:</strong> Shows key strength and lets you generate a stronger replacement key.</li>
        <li class="mb-2"><strong>Captcha:</strong> Stores Turnstile site and secret keys plus every enabled placement.</li>
        <li class="mb-2"><strong>Cloudflare:</strong> Controls trusted-proxy behavior and the Cloudflare IP-range sync action.</li>
        <li class="mb-2"><strong>Migration:</strong> Encrypts any remaining plaintext data and reports pending rows.</li>
        <li><strong>Database Health:</strong> Runs schema sync and optional drift repair.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Identity &amp; VPN</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Enforcement mode:</strong> Blocks suspicious VPN or proxy traffic before it reaches the app. Select the radio button and click <strong>Save Security Rules</strong>.</li>
        <li class="mb-2"><strong>Intelligence mode:</strong> Does not block by itself. It stores proxy and VPN intelligence so systems like <strong>Rewards Fraud</strong> can score traffic more accurately. Select it, then save the Identity &amp; VPN section.</li>
        <li class="mb-2"><strong>ProxyCheck.io API Key:</strong> Required for both enforcement and intelligence lookups. Enter the key, then click <strong>Save Security Rules</strong>.</li>
        <li class="mb-2"><strong>VPN / Proxy Whitelist:</strong> Lets trusted IPs or CIDR ranges bypass proxy enforcement and intelligence scoring. Edit the list, then save the Identity &amp; VPN section.</li>
        <li class="mb-2"><strong>Login Rate Limit:</strong> Caps sign-in attempts per five-minute window. Change the number, then save the Identity &amp; VPN section.</li>
        <li><strong>Registration Rate Limit:</strong> Caps new signups per five-minute window. Change the number, then save the Identity &amp; VPN section.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Two-Factor Authentication</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Enable 2FA:</strong> Turns on the built-in two-factor system. Toggle it, then click <strong>Save 2FA Settings</strong>.</li>
        <li><strong>Enforcement Start Date:</strong> If set, users without 2FA are forced to enroll after that date. Choose the date, then save the 2FA section.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Captcha And Cloudflare</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Site Key / Secret Key:</strong> Cloudflare Turnstile credentials used across all enabled placements. Enter the keys, then click <strong>Save Captcha Rules</strong>.</li>
        <li class="mb-2"><strong>Captcha placements:</strong> Login, User Registration, Guest Download, Free User Download, Report File, Contact Us, and DMCA Form can all be toggled independently. Change the switches, then save the Captcha section.</li>
        <li class="mb-2"><strong>Invisible mode caution:</strong> If you use Cloudflare Turnstile in <code>Invisible</code> mode, users who click submit very quickly can still hit a temporary <code>Please complete the captcha.</code> error if the background token has not finished loading yet. Managed mode is the safer default.</li>
        <li class="mb-2"><strong>Trust Cloudflare Headers:</strong> Only enable this when the site is really behind Cloudflare and you keep the trusted proxy ranges current. Toggle it, then save the Cloudflare section.</li>
        <li><strong>Sync Cloudflare IP Ranges Now:</strong> Refreshes the internal trusted-proxy list used for real IP restoration, country checks, and network-aware fraud scoring.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Keys, Migration, And Health</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Current Encryption Standard:</strong> Shows whether the configured key meets the current enterprise format expectations.</li>
        <li class="mb-2"><strong>Generate New Enterprise Key:</strong> Creates a stronger replacement key. Use the <strong>Show</strong> and <strong>Copy</strong> buttons, then update your config file during maintenance.</li>
        <li class="mb-2"><strong>Secure All Pending Data:</strong> Encrypts legacy rows that are still in plaintext. Use the button in the Migration section.</li>
        <li><strong>Run Schema Sync / Deep Repair:</strong> Checks the database schema for missing or drifted structures and optionally repairs them from the Database Health section.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Setup Order</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Decide your VPN mode first:</strong> Use Enforcement mode if you want ProxyCheck to block suspicious traffic immediately, or Intelligence mode if you only want fraud signals.</li>
        <li><strong>Whitelist safe networks:</strong> Add trusted office IPs, probes, or internal gateways before testing enforcement.</li>
        <li><strong>Turn on Cloudflare trust only after syncing ranges:</strong> This keeps the app from trusting spoofed headers from the open internet.</li>
        <li><strong>Set 2FA policy last:</strong> Enable it first, then set an enforcement date only after you are ready for users to be forced through setup.</li>
    </ol>

    <div class="alert alert-danger border-0 shadow-sm small">
        <strong>Critical:</strong> Do not replace the encryption key on a live install unless you intend to re-encrypt the database. Existing encrypted data depends on that key.
    </div>
</div>
