<div class="p-1">
    <p class="guide-purpose mb-4"><strong>Site Content</strong> is the structured editor for public-facing copy. It lets admins change visible homepage and support-page content without editing theme files or raw HTML.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">When To Use This Page</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Use it when:</strong> You want to change public copy on Homepage, FAQ, Contact, DMCA, or Footer.</li>
        <li class="mb-2"><strong>Use SEO instead when:</strong> You want to change metadata, titles, or search-facing settings rather than visible page copy.</li>
        <li><strong>What it does not do:</strong> It does not unlock fixed branding such as the locked <strong>Powered by</strong> footer attribution.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Current Capabilities</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Managed pages:</strong> Homepage, FAQ, Contact, DMCA, and Footer.</li>
        <li class="mb-2"><strong>Preview links:</strong> Open the real public route with a short-lived preview token.</li>
        <li class="mb-2"><strong>Locale-aware editing:</strong> Switch between locales such as <code>en</code> and <code>fr</code>.</li>
        <li class="mb-2"><strong>Revision history:</strong> Stores the newest 10 revisions per page and locale, with compare and restore tools.</li>
        <li><strong>Import and export:</strong> Move locale-specific content snapshots in and out safely.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Markdown And Theme Rules</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Allowed markdown:</strong> Headings, bold, italic, paragraphs, lists, links, blockquotes, inline code, and fenced code blocks.</li>
        <li class="mb-2"><strong>Blocked:</strong> Raw HTML, scripts, and unsafe attributes.</li>
        <li class="mb-2"><strong>Allowed link schemes:</strong> Relative internal paths like <code>/faq</code>, <code>https://</code>, <code>mailto:</code>, and <code>tel:</code>.</li>
        <li><strong>Theme helper:</strong> Custom themes should call <code>\App\Service\SiteContentService::page(...)</code> with the matching page key and request locale so managed content still flows through.</li>
    </ul>

    <div class="alert alert-light border-0 shadow-sm small mb-0">
        <strong>Related pages:</strong> <a href="/admin/configuration?tab=seo" class="guide-action-link">Config Hub &gt; SEO</a> for metadata, <a href="/admin/docs" class="guide-action-link">Documentation</a> for the longer handbook view, and your theme overrides if you are extending the frontend rendering layer.
    </div>
</div>
