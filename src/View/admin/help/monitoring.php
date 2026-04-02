<div class="small">

    <p class="mb-4">Monitor the CPU, RAM, and disk usage of your main web server and all connected storage nodes.</p>

    

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">1. Server Resource Tracking</h6>

    <p class="mb-3">This page shows real-time graphs for system resources. It helps you identify when you need to upgrade your hosting or add more storage nodes.</p>



    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">2. Critical Alerts</h6>

    <p class="mb-3">If a server's disk space goes above 90%, it will be highlighted in red. The system will also stop sending new uploads to that server automatically.</p>



    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">3. Troubleshooting</h6>

    <ul class="mb-4">

        <li><strong>Monitoring is blank?</strong> Make sure your storage servers are correctly configured. The web server needs to be able to ping them to get health data.</li>

        <li><strong>Wrong disk size?</strong> If the disk usage doesn't match your host's dashboard, check if you have large log files or trash folders taking up space outside of the script's control.</li>

    </ul>



    <div class="alert alert-info border-0">

        <strong>Automated Health:</strong> The <strong>Cron Job</strong> runs these health checks every few minutes. If your monitoring looks "frozen," verify your Cron Job is active.

    </div>

</div>

