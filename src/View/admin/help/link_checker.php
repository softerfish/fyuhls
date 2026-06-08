<div class="help-section">
    <h6 class="fw-bold text-dark mb-3">Link Checker Settings</h6>
    <p class="small text-muted">This Config Hub tab controls the public link-checker tool. Use it when you want to change who can use the checker, what it reveals, or how aggressive its default behavior should be.</p>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">What These Settings Control</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Availability:</strong> Decides whether the public link checker is on at all and whether guests can use it.</li>
            <li><strong>Output rules:</strong> Controls how much file status or existence information the checker gives back to the caller.</li>
            <li><strong>Abuse pressure:</strong> Works with captcha and rate limits so the checker does not become an easy probing tool.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">What Changing It Does</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Turning the checker on:</strong> Makes the public checker available again, which can help legitimate users but can also increase probing traffic.</li>
            <li><strong>Making it stricter:</strong> Reduces information leakage and abuse risk, but can make the tool less convenient for normal visitors.</li>
            <li><strong>Relaxing restrictions:</strong> Improves convenience for users who check links often, but also raises the chance of enumeration or automated probing.</li>
        </ul>
    </div>

    <div class="alert alert-light border-0 small py-2 mb-0">
        <strong>Related settings:</strong> Pair this tab with <a href="/admin/configuration?tab=security" class="guide-action-link">Security</a> for captcha and VPN/proxy controls, and with <a href="/admin/status" class="guide-action-link">System Status</a> if the checker is behaving inconsistently.
    </div>
</div>
