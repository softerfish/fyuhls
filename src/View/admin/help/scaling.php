<div class="p-1">
    <p class="guide-purpose mb-4">Use Scaling Guide to read the current delivery posture of the install before you change storage, CDN, reward-proof, or transfer settings. This page is about how the platform behaves under load, not just whether one setting is on.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Is On This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Verdict summary:</strong> A plain-language reading of whether the current install is on a light, mixed, or heavier delivery path.</li>
        <li class="mb-2"><strong>Delivery posture cards:</strong> Break down how object storage, local delivery, Nginx handoff, CDN redirects, and reward-proof rules affect the hot path.</li>
        <li class="mb-2"><strong>Operator notes:</strong> Explain why the page is recommending caution or a different path without exposing raw secrets like bucket names or internal endpoints.</li>
        <li><strong>Action prompts:</strong> Link you back to the exact admin pages that can change the current posture.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What The Main Features Do</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Storage summary:</strong> Tells you whether object storage, local storage, and Nginx-assisted delivery are actually available to this install.</li>
        <li class="mb-2"><strong>Reward-proof pressure signals:</strong> Show when PPD thresholds, verified completion, or concurrency tracking force Fyuhls to stay involved longer in download flows.</li>
        <li class="mb-2"><strong>CDN posture:</strong> Shows whether CDN redirects are configured well enough to be treated as a real offload path.</li>
        <li><strong>Page variants by permission:</strong> Higher-privilege viewers get more policy detail; lower-privilege viewers see the reduced operational summary instead.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">What Changing These Settings Does</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Changing Downloads settings:</strong> Alters whether the app, the web server, storage, or the CDN carries more of the request path.</li>
        <li class="mb-2"><strong>Changing reward-proof rules:</strong> Can make rewarded downloads safer but heavier, especially when stronger verification is required before crediting.</li>
        <li class="mb-2"><strong>Changing package concurrency or wait rules:</strong> Can shift how long Fyuhls needs to stay involved in active sessions, even if storage is otherwise healthy.</li>
        <li><strong>Changing storage-node setup:</strong> Can improve or degrade multipart uploads, browser delivery, and CDN handoff depending on provider support and CORS completeness.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Use It Safely</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Read the verdict first:</strong> It gives the current big-picture answer before you dive into individual cards.</li>
        <li><strong>Use the cards to identify the bottleneck family:</strong> Delivery, proof, concurrency, or storage config.</li>
        <li><strong>Change the owning page next:</strong> This guide explains the impact, but the real changes still happen in Config Hub or Storage Nodes.</li>
    </ol>

    <div class="alert alert-info border-0 shadow-sm small mb-3">
        <strong>Important:</strong> This page is intentionally descriptive. It should help admins understand the tradeoffs of the current setup without becoming a second settings panel.
    </div>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/docs#scaling" class="guide-action-link">Documentation &gt; Scaling Guide</a>, <a href="/admin/configuration?tab=downloads" class="guide-action-link">Config Hub &gt; Downloads</a>, <a href="/admin/configuration?tab=monetization" class="guide-action-link">Config Hub &gt; Monetization</a>, and <a href="/admin/configuration?tab=storage" class="guide-action-link">Storage Nodes</a>.
    </div>
</div>
