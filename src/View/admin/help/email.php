<div class="p-1">
    <p class="guide-purpose mb-4">Email settings cover SMTP delivery, outbound rate limiting, test tools, and editable system templates. The SMTP form, test tools, and template editor are separate actions on the same page.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">SMTP Configuration</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>SMTP Host / Port:</strong> The mail server endpoint and port provided by your email service. Enter the values, then click <strong>Save SMTP Config</strong>.</li>
        <li class="mb-2"><strong>From Address:</strong> The sender address users will see on system emails. Change it, then save the SMTP form.</li>
        <li class="mb-2"><strong>Encryption:</strong> Choose none, SSL, or TLS/STARTTLS to match your provider. Select the correct option, then save the SMTP form.</li>
        <li class="mb-2"><strong>Server Requires Authentication:</strong> Enable this for normal hosted SMTP accounts so the username and password fields appear. Toggle it, fill in the auth fields, then save the SMTP form.</li>
        <li><strong>Sending Rate Limit:</strong> Caps how many queued emails the cron worker should send per minute. Change the number, then save the SMTP form.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Built-In Tools</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Save SMTP Config:</strong> Stores the host, auth, sender, and rate-limit settings currently shown on the page.</li>
        <li class="mb-2"><strong>Test Connection:</strong> Verifies that the app can connect and authenticate to the SMTP server using the current form values, even before you save them.</li>
        <li><strong>Send Test Email:</strong> Sends a real email to the address you enter in the test box using the current SMTP form values.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Template Groups</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Account:</strong> Confirmation, welcome, password reset, package-change, downgrade, expiry warning, and storage warning templates.</li>
        <li class="mb-2"><strong>Security:</strong> New-device login and 2FA enabled or disabled notifications.</li>
        <li class="mb-2"><strong>Rewards:</strong> Withdrawal submitted, approved, paid, rejected, and abuse-report confirmation templates.</li>
        <li class="mb-2"><strong>Support:</strong> Contact responder, DMCA responder, and admin notification templates.</li>
        <li><strong>Payments:</strong> Pending, on hold, completed, failed, denied, and refunded payment templates.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Editing Templates</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Edit button:</strong> Opens the template modal for that row.</li>
        <li class="mb-2"><strong>Subject Line:</strong> Changes the email subject users will see. Edit it in the modal, then save the template.</li>
        <li><strong>Email Body:</strong> Changes the message body and supports placeholder variables such as <code>{username}</code>, <code>{site_name}</code>, and template-specific values shown below the editor. Update the text, then submit the modal.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Tip:</strong> If connection tests pass but real messages still do not arrive, check provider reputation, SPF or DKIM records, and whether the <code>mail_queue</code> cron job is running every minute.
    </div>
</div>
