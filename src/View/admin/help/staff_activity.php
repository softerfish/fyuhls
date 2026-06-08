<div class="p-1">
    <p class="guide-purpose mb-4">Use Staff Activity as the admin audit trail. This page is for answering who changed something, what changed, when it happened, and whether the action touched money, permissions, packages, or security-sensitive settings.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Is On This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Filter bar:</strong> Narrow the audit trail by actor, action, target type, risk level, date range, or free-text search without leaving the page.</li>
        <li class="mb-2"><strong>Summary cards:</strong> Quick counts for visible events, high-risk actions, account actions, and rewards or payout activity inside the current filtered view.</li>
        <li class="mb-2"><strong>Activity cards:</strong> Each row gives you the human summary first, then the target, actor, timestamp, and expandable detail fields.</li>
        <li><strong>Pagination:</strong> Use first, previous, next, and last when you are investigating older actions instead of relying on one long scroll.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Read It</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Start with the summary sentence:</strong> It should tell you the action in plain language before you open the detail drawer.</li>
        <li><strong>Use details to confirm before and after state:</strong> For settings, packages, withdrawals, and manual credits, the detail panel should show what changed, not just that something changed.</li>
        <li><strong>Treat high-risk rows differently:</strong> Manual credits, withdrawal updates, package changes, rewards-fraud actions, and configuration edits deserve closer review than ordinary browsing or content cleanup.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What The Main Features Do</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Search:</strong> Matches actor name, action name, target labels, IDs, and visible details so you can chase one issue across multiple actions.</li>
        <li class="mb-2"><strong>Actor filter:</strong> Limits the page to one staff user when you are reviewing ownership or accountability.</li>
        <li class="mb-2"><strong>Action filter:</strong> Limits the page to a specific kind of event, such as package changes, manual credits, or configuration updates.</li>
        <li class="mb-2"><strong>Target type filter:</strong> Limits the page to the affected record family, such as users, packages, withdrawals, files, or config.</li>
        <li class="mb-2"><strong>High-risk only:</strong> Shows only the actions that are most likely to affect money, access, or system-wide behavior.</li>
        <li><strong>Expandable details:</strong> Shows structured audit fields like changed keys, before and after values, note text, and target-user context when the current viewer is allowed to see them.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Changing Filters Or View State Does</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Changing filters:</strong> Rebuilds the result set and summary counts only for the events you are allowed to see.</li>
        <li class="mb-2"><strong>Changing page:</strong> Moves through the same filtered result set without changing the current search or filter state.</li>
        <li><strong>Permission-aware visibility:</strong> Some sensitive event families are hidden entirely unless your staff role has the matching management capability.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small mb-3">
        <strong>Important:</strong> This page is an audit tool, not the place to make the change itself. Use the links inside the activity cards to jump to the actual page that owns the package, user, withdrawal, or configuration record.
    </div>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/docs#staff-activity" class="guide-action-link">Documentation &gt; Staff Activity</a>,
        <?php if (\App\Service\FeatureService::rewardsEnabled()): ?>
            <a href="/admin/withdrawals" class="guide-action-link">Withdrawals</a> for payout actions,
        <?php else: ?>
            <a href="/admin/configuration?tab=monetization" class="guide-action-link">Config Hub &gt; Monetization</a> for payout availability,
        <?php endif; ?>
        <a href="/admin/users" class="guide-action-link">Users</a> for account moderation, and <a href="/admin/configuration" class="guide-action-link">Config Hub</a> for the system-wide settings that generate many of the high-risk audit rows.
    </div>
</div>
