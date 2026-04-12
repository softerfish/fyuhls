<div class="p-1">
    <p class="guide-purpose mb-4">Use Contact Messages for ordinary support questions, account issues, billing questions, and partner inquiries that come through the public contact form.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Work The Queue</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Open the newest unresolved message first:</strong> Prioritize logins, payment problems, and anything that blocks a real user from using the site.</li>
        <li><strong>Read the full message before replying:</strong> Many requests already include usernames, file links, or screenshots that point you directly to the right admin page.</li>
        <li><strong>Use the built-in reply action:</strong> The response is sent through your SMTP configuration, so it should be treated like a real support email.</li>
        <li><strong>Escalate to the right queue when needed:</strong> Move into <a href="/admin/abuse-reports" class="guide-action-link">Abuse Reports</a>, <a href="/admin/dmca" class="guide-action-link">DMCA</a>, <a href="/admin/users" class="guide-action-link">Users</a>, or <a href="/admin/files" class="guide-action-link">Files</a> when the contact message is really pointing at one of those workflows.</li>
        <li><strong>Close the loop clearly:</strong> Tell the sender what changed, what they should try next, and whether you still need anything from them.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Each Message Gives You</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Email address:</strong> The reply destination. If it looks wrong, solve the issue internally first and then ask the user to re-contact you with a valid address.</li>
        <li class="mb-2"><strong>IP address:</strong> Useful when tracing fraud, rate limiting, support abuse, or access issues.</li>
        <li class="mb-2"><strong>Message body:</strong> Often includes exact file short IDs, package complaints, download issues, or registration problems that lead you to the next page.</li>
        <li><strong>Created date:</strong> Helps you see whether the message lines up with a deployment, payment window, or system outage.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Troubleshooting</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>User did not get your reply?</strong> Check <a href="/admin/configuration?tab=email" class="guide-action-link">Email Settings</a> first. If SMTP is broken, support replies will silently fail with everything else.</li>
        <li class="mb-2"><strong>Too much spam?</strong> Enable contact-form captcha in <a href="/admin/configuration?tab=security" class="guide-action-link">Security</a>.</li>
        <li><strong>Message belongs elsewhere?</strong> Use the right operational queue rather than solving legal, abuse, or payout issues informally from this inbox.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Privacy:</strong> Contact messages and addresses are stored encrypted. Treat this as a support record, not a public chat log, and keep internal-only notes out of user-facing replies.
    </div>
</div>
