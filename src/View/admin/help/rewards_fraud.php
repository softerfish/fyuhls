<div class="p-1">
    <p class="guide-purpose mb-4">Use Rewards Fraud to decide which reward earnings should clear automatically, which should stay on hold, and which need a manual fraud decision before they become withdrawable.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Read This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Held Earnings:</strong> Traffic that is still waiting for the configured hold period, additional signals, or manual review before it can clear or be reversed.</li>
        <li class="mb-2"><strong>Flagged Earnings:</strong> Higher-risk traffic that matched multiple suspicious signals and should usually be inspected before clearing.</li>
        <li class="mb-2"><strong>Cleared Today / Reversed Today:</strong> Shows how much value you have already approved or reversed in the current day.</li>
        <li class="mb-2"><strong>Review Queue:</strong> Counts the earnings records that are currently waiting for a manual decision.</li>
        <li class="mb-2"><strong>Intelligence Health:</strong> Tells you whether Cloudflare-based real-IP detection looks trustworthy enough for country, ASN, and network scoring.</li>
        <li class="mb-2"><strong>Protection Settings:</strong> Controls which fraud signals are collected and how strict the system should be.</li>
        <li class="mb-2"><strong>Uploader Risk Scores:</strong> Highlights uploaders whose traffic patterns are repeatedly producing held or flagged rewards.</li>
        <li><strong>Network Insights:</strong> Summarizes repeated held and flagged traffic by ASN, country, and network type so you can spot datacenter or proxy-heavy clusters.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Protection Settings Reference</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Enable Rewards Fraud Protection:</strong> Master switch for the rewards fraud scoring and review system.</li>
        <li class="mb-2"><strong>Require Verified Completion for Reward Credit:</strong> Requires stronger proof that a download or watch event actually completed enough to qualify.</li>
        <li class="mb-2"><strong>Auto-Clear Low-Risk Earnings:</strong> Lets low-risk earnings clear without waiting for manual review.</li>
        <li class="mb-2"><strong>Use Cloudflare Integration Data:</strong> Uses Cloudflare-restored visitor and network context when Cloudflare trust is configured correctly.</li>
        <li class="mb-2"><strong>Use Proxy Intelligence Data:</strong> Uses proxy or VPN lookups, such as ProxyCheck, as part of fraud scoring.</li>
        <li class="mb-2"><strong>Use IP Hash / User-Agent Hash / Visitor Cookie Hash:</strong> Uses privacy-safe repeat-visitor signals to cluster suspicious traffic without relying only on raw IP addresses.</li>
        <li class="mb-2"><strong>Use Accept-Language Hash / Timezone Offset / Platform + Screen Bucket:</strong> Adds softer browser fingerprinting signals to strengthen clustering.</li>
        <li class="mb-2"><strong>Use ASN + Network Classification:</strong> Adds ISP, hosting, and datacenter context to the risk score.</li>
        <li class="mb-2"><strong>PPD Guests Only:</strong> Restricts rewarded pay-per-download counting to guest traffic only.</li>
        <li class="mb-2"><strong>Require Downloader Email Verification:</strong> Makes verified downloader accounts a requirement for lower-risk rewarded traffic.</li>
        <li class="mb-2"><strong>Block Linked Downloader Accounts:</strong> Penalizes or blocks downloader accounts that appear linked to the uploader.</li>
        <li class="mb-2"><strong>Hold New Downloader Accounts:</strong> Automatically treats very new downloader accounts as higher risk.</li>
        <li class="mb-2"><strong>Hold Period / Min Downloader Account Age:</strong> Defines how long earnings stay held and how old a downloader account should be before being treated as more trustworthy.</li>
        <li class="mb-2"><strong>Review Threshold / Flag Threshold:</strong> Defines when traffic becomes manual-review traffic and when it becomes strongly suspicious.</li>
        <li><strong>Event Retention / Trim Threshold:</strong> Controls how long fraud-event detail is retained and when old detail should be trimmed to protect storage growth.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Recommended Review Flow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Check Intelligence Health first:</strong> If Cloudflare trust is off or proxy ranges are missing, fix that before trusting country or network insights.</li>
        <li><strong>Open the reasons list:</strong> Look for repeated visitor-cookie reuse, premium-country spikes, very new downloader accounts, or linked downloader signals.</li>
        <li><strong>Use the Review Queue actions deliberately:</strong> <strong>Clear</strong> approves the earning, <strong>Keep Held</strong> leaves it in manual review, and <strong>Reverse</strong> rejects the earning and records the fraud decision.</li>
        <li><strong>Use Hold for uncertainty:</strong> If the traffic looks unusual but not clearly fraudulent, leave it held while you gather more evidence.</li>
        <li><strong>Clear only when the pattern looks organic:</strong> A mix of countries, believable completion proof, and no obvious repeated fingerprints is a good sign.</li>
        <li><strong>Reverse when proof is weak or the cluster looks coordinated:</strong> Replays, linked accounts, proxy-heavy premium traffic, and impossible watch/download proof should not become withdrawable.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">How To Edit The Page Behavior</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Protection Settings card:</strong> Change the fraud switches and thresholds on this page, then click <strong>Save Rewards Fraud Settings</strong>.</li>
        <li class="mb-2"><strong>Intelligence mode dependency:</strong> If you want proxy and VPN lookups to strengthen scoring without blocking visitors, turn on <strong>Intelligence mode</strong> in <strong>Config Hub &gt; Security &gt; Identity &amp; VPN</strong>.</li>
        <li><strong>Cloudflare trust dependency:</strong> If country or ASN signals look weak, fix <strong>Config Hub &gt; Security &gt; Cloudflare</strong> before making policy decisions based on network data.</li>
    </ul>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Tip:</strong> Cloudflare plus ProxyCheck intelligence gives the strongest signal quality. If you are running with weak or no real-IP restoration, keep Auto-Clear conservative and rely more on held review decisions. Intelligence mode on the Security page is the recommended way to feed ProxyCheck results into fraud scoring without hard-blocking visitors.
    </div>
</div>
