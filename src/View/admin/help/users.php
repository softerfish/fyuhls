<div class="p-1">
    <p class="guide-purpose mb-4">Use User Management to create accounts, moderate access, assign packages, change roles, and repair account-level issues without touching the database directly.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Creating Accounts</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Create from the main page:</strong> The Create Account card lets admins create user or admin accounts directly from the list page.</li>
        <li class="mb-2"><strong>Choose the starting state:</strong> Set package, role, status, and a temporary password before saving the account.</li>
        <li><strong>Refine after creation:</strong> Open the edit screen if you need to reset the password, add credit, or manage 2FA for that user.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How Search Works</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Exact match only:</strong> Username and email are encrypted in the database, so this page supports exact username, exact email, or exact numeric user ID search.</li>
        <li><strong>Edit for deeper changes:</strong> Open the user record to change package, role, status, password, or manual rewards credit.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Safe Admin Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Search for the exact user first:</strong> Avoid making changes on the wrong account, especially when multiple users share similar names.</li>
        <li><strong>Use quick actions only for obvious state changes:</strong> Ban, unban, or role changes are fine from the list, but package moves and repairs belong on the edit screen.</li>
        <li><strong>Check related pages before deleting:</strong> Review Files, Withdrawals, and Requests if the user is involved in moderation, billing, or payout issues.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Available Actions</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Ban / Unban:</strong> Changes the account status and blocks or restores normal access.</li>
        <li class="mb-2"><strong>Make Admin / Remove Admin:</strong> Changes the user's administrative role.</li>
        <li class="mb-2"><strong>Delete:</strong> Removes the user if there are no pending or approved withdrawals blocking deletion.</li>
        <li><strong>Edit Screen:</strong> Lets you reset the password, assign a package, and add a manual bonus credit if Rewards is enabled.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Safeguards:</strong> The system prevents you from deleting, banning, or demoting the last active administrator, and it also blocks those same destructive actions on your own admin account from this list.
    </div>
</div>
