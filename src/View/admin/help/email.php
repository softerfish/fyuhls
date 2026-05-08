<div class="p-1">
    <p class="guide-purpose mb-4">Use the Email tab for SMTP delivery, mail-queue health, test tools, and editable templates. This page is where you tune how the app sends mail, not where you decide whether features like tickets or rewards exist.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Use it when:</strong> You need to change SMTP settings, test connection or sending, adjust queue rate, or edit email templates.</li>
        <li class="mb-2"><strong>Use another page when:</strong> You want to change which ticket events send email. That lives in <strong>Config Hub &gt; Tickets</strong>.</li>
        <li><strong>What it does not do:</strong> It does not replace the queue cron, Support Center diagnostics, or page-specific settings that trigger the emails.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Is On The Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Queue stats:</strong> Pending, recently sent, and failed-attempt cards help you see whether SMTP problems are current or historical.</li>
        <li class="mb-2"><strong>SMTP Server Configuration:</strong> Host, port, encryption, auth, sender identity, and per-minute send rate.</li>
        <li class="mb-2"><strong>Test Tools:</strong> Connection tests and real send tests that use the SMTP values shown on the page.</li>
        <li><strong>Templates:</strong> Edit system message subjects and bodies without changing code.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Template Groups</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Account:</strong> Welcome, confirmation, password reset, package-change, and account-warning templates.</li>
        <li class="mb-2"><strong>Security:</strong> New-device, login, and two-factor notifications.</li>
        <li class="mb-2"><strong>Rewards:</strong> Withdrawal and reward-related templates.</li>
        <li class="mb-2"><strong>Tickets:</strong> Support inbox notifications, user reply notices, close notices, and public-intake admin templates such as contact, abuse, and DMCA.</li>
        <li><strong>Payments and support:</strong> Payment lifecycle messages plus any remaining operator-facing support templates.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Save SMTP first:</strong> Host, auth, and sender values should be stored before you assume templates are the problem.</li>
        <li><strong>Run a connection test:</strong> This catches auth and port problems quickly.</li>
        <li><strong>Send a real test email:</strong> Use it to confirm delivery and inbox placement before blaming queue logic.</li>
        <li><strong>Edit templates last:</strong> Once the transport works, tune subjects, bodies, and placeholders.</li>
    </ol>

    <div class="alert alert-info border-0 shadow-sm small mb-3">
        <strong>Tip:</strong> If connection tests pass but users still do not receive mail, check DNS records, sender reputation, provider throttling, and whether the mail-queue cron is running on schedule.
    </div>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/configuration?tab=tickets" class="guide-action-link">Config Hub &gt; Tickets</a> for ticket-event toggles and support inbox settings, <a href="/admin/status" class="guide-action-link">System Status</a> for queue or cron health, and <a href="/admin/support" class="guide-action-link">Support Center</a> for sanitized escalation bundles.
    </div>
</div>
