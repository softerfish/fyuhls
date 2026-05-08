<div class="p-1">
    <p class="guide-purpose mb-4">Use <strong>Tickets</strong> as the unified operational queue for logged-in user support tickets, public contact submissions, abuse reports, and DMCA notices. This page is for active queue work, not just historical reference.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Use it when:</strong> You need to reply to a user, leave an internal note, review a complaint, close a ticket, or process DMCA or abuse actions.</li>
        <li class="mb-2"><strong>Use another page when:</strong> You need to change global email triggers, ticket rate limits, or reminder behavior. Those live in <strong>Config Hub &gt; Tickets</strong> and <strong>Config Hub &gt; Email</strong>.</li>
        <li><strong>What it does not do:</strong> It does not replace package settings, public form settings, or SMTP configuration. It consumes those systems.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Ticket Types In The Queue</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Support:</strong> Logged-in user tickets opened from the frontend account area.</li>
        <li class="mb-2"><strong>Contact:</strong> General public contact messages. These often need a reply or a short internal handoff note.</li>
        <li class="mb-2"><strong>Abuse:</strong> Reported files or policy complaints. These are usually moderation-first items.</li>
        <li><strong>DMCA:</strong> Copyright notices with the strictest audit needs. Document what you reviewed and what action you took.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Is On The Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Summary cards:</strong> Show the current queue load for open tickets, tickets needing staff reply, waiting-on-user tickets, high-priority tickets, and older open work.</li>
        <li class="mb-2"><strong>Filters and search:</strong> Narrow the queue by type, status, priority, stale age, or free-text search without leaving the page.</li>
        <li class="mb-2"><strong>Queue rows:</strong> Surface type, status, stale age, submitter, latest reply, and whether the item needs staff attention.</li>
        <li class="mb-2"><strong>Thread panel:</strong> Shows the public conversation plus internal activity in one place.</li>
        <li><strong>Action forms:</strong> Reply, add an internal note, change status, and run abuse or DMCA-specific actions directly from the open ticket.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Start with the summary cards:</strong> High-priority and older open tickets should usually be handled before newer low-risk ones.</li>
        <li><strong>Filter by type when busy:</strong> Work support, contact, abuse, and DMCA queues separately when the inbox is under pressure.</li>
        <li><strong>Read the thread before replying:</strong> Many items already have notes, previous staff replies, or file actions attached.</li>
        <li><strong>Use internal notes for handoff context:</strong> Notes are for staff only and should explain what you checked, not just that you opened the row.</li>
        <li><strong>Close deliberately:</strong> A closed ticket can reopen on user reply, so close it when the operational work is genuinely complete.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Status Model</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Open:</strong> New or active work that still needs attention.</li>
        <li class="mb-2"><strong>Waiting on User:</strong> Staff replied and is waiting for the reporter or account owner.</li>
        <li class="mb-2"><strong>Waiting on Staff:</strong> The system or a previous handoff marked this as needing another staff response.</li>
        <li><strong>Closed:</strong> Operationally complete for now. A user reply can reopen support tickets.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">DMCA And Abuse Actions</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>DMCA matched files:</strong> Process only the URLs covered by the current notice. The page records the activity so the audit trail survives reloads and handoffs.</li>
        <li class="mb-2"><strong>Abuse delete action:</strong> Treat file deletion as a moderation action, not just a status change.</li>
        <li><strong>Public forms stay distinct:</strong> Contact, abuse, and DMCA still have their own public forms, but all new submissions land here as unified tickets.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small mb-3">
        <strong>Important:</strong> Internal notes are staff-only context. They should explain the review or handoff state without assuming the next admin remembers the original ticket.
    </div>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/configuration?tab=tickets" class="guide-action-link">Config Hub &gt; Tickets</a> for rate limits and reminder settings, <a href="/admin/configuration?tab=email" class="guide-action-link">Config Hub &gt; Email</a> for ticket templates and SMTP, and <a href="/admin/support" class="guide-action-link">Support Center</a> when you need a sanitized escalation bundle.
    </div>
</div>
