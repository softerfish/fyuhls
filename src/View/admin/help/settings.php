<div class="small">
    <p class="mb-4">This guide is shared by the <strong>General</strong>, <strong>Downloads</strong>, and <strong>Uploads</strong> tabs in Config Hub. Each item below explains what the setting does and how to change it.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">General Tab</h6>
    <ul class="mb-4">
        <li><strong>Site Name:</strong> Updates the brand name shown across the public site, admin screens, and system emails. Edit the text field, then click <strong>Save General Configuration</strong>.</li>
        <li><strong>Admin Notification Email:</strong> Sets the inbox used for DMCA, abuse, and admin-facing alerts. Change the address, then save the General tab.</li>
        <li><strong>Reserved Usernames:</strong> Blocks specific names from being registered. Enter a comma-separated list, then save the General tab.</li>
        <li><strong>Allow New Registrations:</strong> Opens or closes public signup. Toggle the switch, then save the General tab.</li>
        <li><strong>Require Email Verification:</strong> Makes new users confirm email before they can log in. Toggle the switch, then save the General tab.</li>
        <li><strong>Maintenance Mode:</strong> Restricts the frontend to admins only. Toggle it on when you need a maintenance window, then save the General tab.</li>
        <li><strong>Demo Mode:</strong> Enables the demo-mode feature set used with the designated demo admin account. Toggle it here, then save the General tab.</li>
        <li><strong>Show Powered By:</strong> Controls whether the public footer shows the branded credit. Toggle the switch, then save the General tab.</li>
        <li><strong>Enable FFmpeg:</strong> Turns on video-thumbnail and video-processing support. Turn it on only if FFmpeg is installed on the server, then save the General tab.</li>
        <li><strong>FFmpeg Binary Path:</strong> Stores the full path to the server's FFmpeg executable. Enter the path and save the General tab after enabling FFmpeg.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Downloads Tab</h6>
    <ul class="mb-4">
        <li><strong>Require User Account to Download:</strong> Blocks guest downloads entirely. Toggle the switch, then click <strong>Save Download Settings</strong>.</li>
        <li><strong>Block Downloads by Country:</strong> Restricts downloads for comma-separated ISO country codes such as <code>US,CN,RU</code>. Edit the list, then save the Downloads tab.</li>
        <li><strong>Track Active Download Connections:</strong> Enables the live connection tracker used by the <strong>Live Downloads</strong> page and package-based concurrent-download limits. Toggle it, then save the Downloads tab.</li>
        <li><strong>Process Remote URL Downloads in Background:</strong> Moves remote URL imports onto cron instead of making the browser wait. Toggle it only if cron is healthy, then save the Downloads tab.</li>
        <li><strong>Enable Streaming Support:</strong> Enables stream-session handling for supported media flows. Toggle it, then save the Downloads tab.</li>
        <li><strong>Nginx Completion Log Path:</strong> Tells Fyuhls where the dedicated Nginx completion access log lives. Enter the exact file path, then save the Downloads tab.</li>
        <li><strong>Nginx Completion Event Retention:</strong> Controls how long processed Nginx completion events are kept before cron purges them. Change the number of days, then save the Downloads tab.</li>
        <li><strong>Nginx Completion Log Lines Per Cron Run:</strong> Caps how many completion-log lines the cron worker ingests per run. Increase it for busy Nginx installs, then save the Downloads tab.</li>
        <li><strong>Enable CDN Redirects for Public Object-Storage Files:</strong> Lets eligible public files redirect to a public bucket/CDN hostname you already control. Toggle it, then save the Downloads tab.</li>
        <li><strong>CDN Base URL:</strong> Stores the exact hostname or fixed path prefix used for those CDN redirects. Enter the value without a trailing slash, then save the Downloads tab.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Uploads Tab</h6>
    <ul class="mb-4">
        <li><strong>Synchronous Uploads:</strong> Turns browser-side concurrent upload processing on or off. Toggle it, then click <strong>Save Upload Configuration</strong>.</li>
        <li><strong>Max Concurrent Threads:</strong> Sets how many browser upload jobs can run at the same time. Change the number, then save the Uploads tab.</li>
        <li><strong>Enable Chunked Uploads:</strong> Allows browser uploads to be split into smaller pieces. Toggle it, then save the Uploads tab.</li>
        <li><strong>Chunk Size (MB):</strong> Sets the chunk size used by upload handling where chunking applies. Change the number, then save the Uploads tab.</li>
        <li><strong>Allowed File Extensions:</strong> Limits uploads to a comma-separated extension allowlist such as <code>jpg,png,zip,mp4</code>. Edit the list, then save the Uploads tab.</li>
        <li><strong>Login Required:</strong> Decides whether uploads are restricted to signed-in users or whether guest uploads are allowed. Toggle it, then save the Uploads tab.</li>
        <li><strong>Deduplication:</strong> Reuses existing stored content when the same checksum and size already exist. Toggle it, then save the Uploads tab.</li>
        <li><strong>Hide Upload Popup:</strong> Keeps the upload tray collapsed by default while uploads continue. Toggle it, then save the Uploads tab.</li>
        <li><strong>Original Name in URL:</strong> Adds the original filename to generated file URLs. Toggle it, then save the Uploads tab.</li>
        <li><strong>Multipart Object Storage:</strong> This is controlled by the upload settings together with your storage-node and bucket CORS configuration. Use the Uploads tab to allow browser uploads, then use <strong>Storage Nodes</strong> to keep the object-storage side configured correctly.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small mb-0">
        <strong>Reference:</strong> Site-wide defaults live here, but package-specific quotas and entitlements still live in <strong>Packages</strong>, and object-storage delivery behavior still lives in <strong>Storage Nodes</strong>.
    </div>
</div>
