<div class="help-section">
    <h6 class="fw-bold text-dark mb-3">SEO Command Center</h6>
    <p class="small text-muted">The SEO tab controls how search engines and social platforms see your public pages. It is designed to help with indexing, click-through rate, structured data, and crawl hygiene without exposing low-value pages.</p>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">1. Overview</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>SEO Health Score:</strong> A quick score based on what the script can control directly, including title coverage, description quality, sitemap settings, robots rules, and verification readiness.</li>
            <li><strong>Priority Issues:</strong> The fastest fixes to make before launch, such as missing canonical base URL or weak homepage metadata.</li>
            <li><strong>Quick Status:</strong> Shows whether sitemap, file indexing, auth blocking, and schema toggles are currently active.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">2. General</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Title Template:</strong> Global title format for pages that do not use a more specific template. Use variables like <code>%page_title%</code> and <code>%site_name%</code>.</li>
            <li><strong>Canonical Base URL:</strong> Your production site URL. This is critical for canonical tags, sitemap links, and robots sitemap references. If you do not set it manually, the script falls back to the detected live request URL.</li>
            <li><strong>Default Meta Description:</strong> Fallback summary for public pages that do not set their own description.</li>
            <li><strong>Default Social Image:</strong> The image used for Open Graph and Twitter cards when a page does not have its own share image.</li>
            <li><strong>Organization Name:</strong> Used in structured data to describe the site or brand running this install.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">3. Homepage</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Homepage SEO Title:</strong> Usually the most important title on the site. Aim for your main search phrase plus your brand.</li>
            <li><strong>Homepage Meta Description:</strong> Write for click-through rate. Explain what the script offers and who it is for in one clean summary.</li>
            <li><strong>H1 / Intro Overrides:</strong> Lets you change the visible landing-page copy without editing template files.</li>
            <li><strong>FAQ Schema / Software Schema:</strong> Adds structured data to the homepage source code to help search engines understand the product and common questions. These toggles do not visibly change the page layout.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">4. Templates And Indexing</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>File Page Templates:</strong> Controls titles and descriptions for public file pages. Useful if your files are public and indexable.</li>
            <li><strong>Noindex Private Files:</strong> Prevents restricted or non-public file pages from competing in search results.</li>
            <li><strong>XML Sitemap:</strong> Generates the live <code>/sitemap.xml</code> route for public pages and optionally public file pages. This is a dynamic URL served by the app, not a physical file you upload manually.</li>
            <li><strong>Auth Page Blocking:</strong> Keeps login, register, forgot-password, and reset flows out of search results through both live <code>robots.txt</code> rules and page-level meta robots tags.</li>
            <li><strong>Internal Page Noindex:</strong> Stops member-only utility pages like settings, notifications, trash, and recent files from being indexed through both live <code>robots.txt</code> rules and page-level meta robots tags.</li>
        </ul>
    </div>

    <div class="mb-4">
        <div class="fw-bold small text-primary mb-1">5. Verification And Scripts</div>
        <ul class="extra-small text-muted ps-3">
            <li><strong>Search Console / Bing Tokens:</strong> Adds verification meta tags without requiring theme edits.</li>
            <li><strong>Custom Head Code:</strong> Use for analytics, advanced verification, or approved third-party metadata tags. Paste only trusted code.</li>
        </ul>
    </div>

    <div class="alert alert-light border-0 small py-2">
        <strong>Recommendation:</strong> Start with canonical URL, homepage metadata, sitemap, and auth-page blocking. Then improve file-page templates only if your public files are good enough to deserve indexing.
    </div>
</div>
