<div class="p-1">
    <p class="guide-purpose mb-4">Use Requests as the unified inbox for contact messages, abuse reports, and DMCA notices. The goal is to keep every public-facing complaint or request in one operational queue instead of scattering it across separate pages.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Request Types</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Contact:</strong> General site requests, questions, and support emails submitted through the public contact flow.</li>
        <li class="mb-2"><strong>Abuse:</strong> Public file abuse reports. These usually need moderation actions more than email replies.</li>
        <li><strong>DMCA:</strong> Copyright notices that often require careful investigation, file review, and a clear response trail.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Is On The Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Inbox filters:</strong> The top buttons split the queue into All, Archive, Contact, DMCA, and Abuse views.</li>
        <li class="mb-2"><strong>Status filter:</strong> The small status field narrows the current queue to one status label without leaving the page.</li>
        <li class="mb-2"><strong>Open:</strong> Expands the full request detail row, including the request body, target, signature where applicable, workflow tools, and activity history.</li>
        <li class="mb-2"><strong>Reply:</strong> Available on Contact and DMCA rows. It sends the message directly from the request record and keeps the reply trail attached to the item.</li>
        <li><strong>Delete File:</strong> Available on Abuse rows. This is a moderation action for the reported file, not a status-only action.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Best Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Filter by type first:</strong> Work DMCA, abuse, and contact queues separately if the inbox is busy.</li>
        <li><strong>Open the detail row:</strong> Review the full body, target, submitter, and any previous internal activity.</li>
        <li><strong>Add an internal note before handing off:</strong> This preserves context for the next admin.</li>
        <li><strong>Reply from the request record:</strong> Contact and DMCA items support direct replies so the activity trail stays on the item.</li>
        <li><strong>Use the status buttons deliberately:</strong> Move items out of the new or pending state only after a real review, not just because someone opened them.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Status And Archive Reference</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>All / Contact / DMCA / Abuse:</strong> The top tabs filter the active inbox by request type.</li>
        <li class="mb-2"><strong>Archive:</strong> Closed or completed items move out of the active queue into the Archive tab.</li>
        <li class="mb-2"><strong>Contact statuses:</strong> New, Read, Replied, and Closed. <strong>Closed</strong> moves the request into Archive.</li>
        <li class="mb-2"><strong>DMCA statuses:</strong> Pending, Investigating, Resolved, and Rejected. <strong>Resolved</strong> and <strong>Rejected</strong> move the request into Archive.</li>
        <li class="mb-2"><strong>Abuse statuses:</strong> Pending, Reviewed, Action Taken, and Dismissed. <strong>Action Taken</strong> and <strong>Dismissed</strong> move the report into Archive.</li>
        <li class="mb-2"><strong>DMCA target links:</strong> Submitted links are normalized into a one-per-line list and shown as clickable links in the admin view.</li>
        <li><strong>Activity:</strong> Replies, internal notes, and status changes all stay attached to the request so the review trail survives handoff.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">DMCA File Removal Workflow</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Process Removal of Files:</strong> DMCA requests now include a dedicated card below Request Details that tries to match each submitted URL to a local Fyuhls file.</li>
        <li class="mb-2"><strong>Checkboxes:</strong> Each matched file gets its own checkbox so you can remove only the links covered by the current review.</li>
        <li class="mb-2"><strong>Process Selected Files:</strong> Removes only the checked matched files.</li>
        <li class="mb-2"><strong>Process All Files for Removal:</strong> Removes every matched local file from that DMCA request in one action.</li>
        <li class="mb-2"><strong>Unavailable rows:</strong> URLs that do not match a local file, or files already deleted or pending purge, are shown but cannot be processed again.</li>
        <li class="mb-2"><strong>No page refresh:</strong> The DMCA removal form updates inline. After a successful action, the matching rows switch to an already-processed state.</li>
        <li><strong>Live activity:</strong> The Activity section is updated immediately with the new removal event, so the audit trail stays visible without reloading the inbox.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small mb-3">
        <strong>Abuse reports are different:</strong> Most abuse reports do not include a reply address. Treat them as moderation actions first, then use internal notes and status updates to preserve the review trail.
    </div>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Tip:</strong> Keep DMCA items well documented. A short internal note explaining what you checked, what file was involved, what links were reviewed, and what action you took is much more useful later than a simple status change.
    </div>
</div>
