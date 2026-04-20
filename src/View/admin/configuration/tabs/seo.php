<?php
$seoTab = $seoTab ?? 'overview';
$score = (int)($seoHealth['score'] ?? 0);
$rating = $seoHealth['rating'] ?? 'Needs Work';
$robotsUrl = \App\Service\SeoService::trustedBaseUrl() . '/robots.txt';
$sitemapUrl = \App\Service\SeoService::trustedBaseUrl() . '/sitemap.xml';
?>

<div class="row">
    <div class="col-md-3">
        <div class="nav flex-column nav-pills border-end pe-3">
            <button class="nav-link text-start mb-2 <?= $seoTab === 'overview' ? 'active' : '' ?>" data-nav-url="?tab=seo&seo_tab=overview">
                <i class="bi bi-speedometer2 me-2"></i> Overview
            </button>
            <button class="nav-link text-start mb-2 <?= $seoTab === 'general' ? 'active' : '' ?>" data-nav-url="?tab=seo&seo_tab=general">
                <i class="bi bi-sliders me-2"></i> General
            </button>
            <button class="nav-link text-start mb-2 <?= $seoTab === 'homepage' ? 'active' : '' ?>" data-nav-url="?tab=seo&seo_tab=homepage">
                <i class="bi bi-house-door me-2"></i> Homepage
            </button>
            <button class="nav-link text-start mb-2 <?= $seoTab === 'templates' ? 'active' : '' ?>" data-nav-url="?tab=seo&seo_tab=templates">
                <i class="bi bi-file-earmark-richtext me-2"></i> Templates
            </button>
            <button class="nav-link text-start mb-2 <?= $seoTab === 'indexing' ? 'active' : '' ?>" data-nav-url="?tab=seo&seo_tab=indexing">
                <i class="bi bi-diagram-3 me-2"></i> Indexing
            </button>
            <button class="nav-link text-start mb-2 <?= $seoTab === 'verification' ? 'active' : '' ?>" data-nav-url="?tab=seo&seo_tab=verification">
                <i class="bi bi-patch-check me-2"></i> Verification
            </button>
        </div>
    </div>
    <div class="col-md-9">
        <div class="ps-3">
            <?php if ($seoTab === 'overview'): ?>
                <div class="row g-4 mb-4">
                    <div class="col-lg-5">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <span class="text-uppercase small text-muted fw-bold">SEO Health Score</span>
                                <div class="display-4 fw-bold mt-2 mb-2"><?= $score ?></div>
                                <div class="badge <?= $score >= 90 ? 'bg-success' : ($score >= 75 ? 'bg-primary' : ($score >= 55 ? 'bg-warning text-dark' : 'bg-danger')) ?>">
                                    <?= htmlspecialchars($rating) ?>
                                </div>
                                <p class="small text-muted mt-3 mb-0">
                                    This score focuses on what the script can control directly: titles, descriptions, sitemap, robots rules, structured data, and verification readiness.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3">Quick Status</h5>
                                <div class="row g-3">
                                    <?php foreach (($seoHealth['checks'] ?? []) as $label => $value): ?>
                                        <div class="col-md-6">
                                            <div class="border rounded p-3 h-100 bg-light">
                                                <div class="small text-uppercase text-muted fw-bold mb-1"><?= htmlspecialchars($label) ?></div>
                                                <div class="fw-semibold"><?= htmlspecialchars($value) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">Priority Issues</h5>
                        <?php if (!empty($seoHealth['issues'])): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($seoHealth['issues'] as $issue): ?>
                                    <?php
                                    $badgeClass = match ($issue['severity']) {
                                        'critical' => 'bg-danger',
                                        'high' => 'bg-warning text-dark',
                                        'medium' => 'bg-primary',
                                        default => 'bg-secondary',
                                    };
                                    ?>
                                    <div class="list-group-item px-0 py-3">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-bold mb-1"><?= htmlspecialchars($issue['title']) ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($issue['description']) ?></div>
                                            </div>
                                            <span class="badge <?= $badgeClass ?> text-uppercase"><?= htmlspecialchars($issue['severity']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success border-0 mb-0">No immediate SEO issues detected in the settings layer.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">Recommended Launch Order</h5>
                        <ol class="small text-muted mb-0 ps-3">
                            <li class="mb-2">Set the canonical base URL to your production domain.</li>
                            <li class="mb-2">Write a homepage title and description that target your main phrase.</li>
                            <li class="mb-2">Enable the XML sitemap and keep auth pages blocked from indexing.</li>
                            <li class="mb-2">Configure file-page title and description templates before public file pages start getting crawled.</li>
                            <li>Add Search Console verification so you can submit the sitemap and watch indexing issues directly.</li>
                        </ol>
                    </div>
                </div>
            <?php elseif ($seoTab === 'general'): ?>
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="seo">
                    <input type="hidden" name="seo_scope" value="general">

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-bold">Title Template</label>
                            <input type="text" class="form-control" name="seo_title_template" value="<?= htmlspecialchars($seoConfig['seo_title_template']) ?>">
                            <small class="text-muted">Use variables like <code>%page_title%</code> and <code>%site_name%</code>.</small>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-bold">Canonical Base URL</label>
                            <input type="url" class="form-control" name="seo_canonical_base_url" value="<?= htmlspecialchars($seoConfig['seo_canonical_base_url']) ?>" placeholder="https://example.com">
                            <small class="text-muted">The production URL used for canonical tags, sitemap URLs, and robots.txt. If you leave this blank in storage, the script auto-detects the current install URL from the live request.</small>
                            <div class="small text-muted mt-1">Detected from this install: <code><?= htmlspecialchars(\App\Service\SeoService::detectInstallBaseUrl()) ?></code></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Default Meta Description</label>
                        <textarea class="form-control" name="seo_default_meta_description" rows="3"><?= htmlspecialchars($seoConfig['seo_default_meta_description']) ?></textarea>
                        <small class="text-muted">Fallback summary used when a public page does not define its own description.</small>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">Default Meta Robots</label>
                            <select class="form-select" name="seo_default_robots">
                                <?php foreach (['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'] as $robotsValue): ?>
                                    <option value="<?= $robotsValue ?>" <?= $seoConfig['seo_default_robots'] === $robotsValue ? 'selected' : '' ?>><?= $robotsValue ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">Default Social Image URL</label>
                            <input type="text" class="form-control" name="seo_default_social_image" value="<?= htmlspecialchars($seoConfig['seo_default_social_image']) ?>" placeholder="/assets/img/share-banner.jpg">
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">Organization / Site Name</label>
                            <input type="text" class="form-control" name="seo_organization_name" value="<?= htmlspecialchars($seoConfig['seo_organization_name']) ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">Save General SEO Settings</button>
                </form>
            <?php elseif ($seoTab === 'homepage'): ?>
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="seo">
                    <input type="hidden" name="seo_scope" value="homepage">

                    <div class="mb-4">
                        <label class="form-label fw-bold">Homepage SEO Title</label>
                        <input type="text" class="form-control" name="seo_home_title" value="<?= htmlspecialchars($seoConfig['seo_home_title']) ?>">
                        <small class="text-muted">Best used for your main keyword target, for example "PHP File Hosting Script | YourBrand".</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Homepage Meta Description</label>
                        <textarea class="form-control" name="seo_home_description" rows="3"><?= htmlspecialchars($seoConfig['seo_home_description']) ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-bold">Homepage H1 Override</label>
                            <input type="text" class="form-control" name="seo_home_h1" value="<?= htmlspecialchars($seoConfig['seo_home_h1']) ?>">
                            <small class="text-muted">Optional. If filled, this replaces the default landing-page headline.</small>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-bold">Homepage Intro Override</label>
                            <textarea class="form-control" name="seo_home_intro" rows="3"><?= htmlspecialchars($seoConfig['seo_home_intro']) ?></textarea>
                            <small class="text-muted">Optional. Use this for a stronger opening paragraph tied to your real keyword target.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label class="form-label fw-bold">Homepage Robots</label>
                            <select class="form-select" name="seo_home_robots">
                                <?php foreach (['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'] as $robotsValue): ?>
                                    <option value="<?= $robotsValue ?>" <?= $seoConfig['seo_home_robots'] === $robotsValue ? 'selected' : '' ?>><?= $robotsValue ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-4">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="seo_home_faq_schema" id="seoHomeFaqSchema" value="1" <?= $seoConfig['seo_home_faq_schema'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="seoHomeFaqSchema">Enable Homepage FAQ Schema</label>
                                <div class="small text-muted mt-2">Adds FAQ JSON-LD to the page source for search engines. This does not visibly change the homepage design.</div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-4">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="seo_home_software_schema" id="seoHomeSoftwareSchema" value="1" <?= $seoConfig['seo_home_software_schema'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="seoHomeSoftwareSchema">Enable Homepage Software Schema</label>
                                <div class="small text-muted mt-2">Adds SoftwareApplication JSON-LD to the page source for search engines. This does not visibly change the homepage design.</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border-0 small">
                        <strong>Preview idea:</strong> Keep the title tight and keyword-led, then use the H1 and intro to explain the main selling points without stuffing repeated phrases everywhere.
                    </div>

                    <button type="submit" class="btn btn-primary px-4">Save Homepage SEO</button>
                </form>
            <?php elseif ($seoTab === 'templates'): ?>
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="seo">
                    <input type="hidden" name="seo_scope" value="templates">

                    <div class="mb-4">
                        <label class="form-label fw-bold">Public File Page Title Template</label>
                        <input type="text" class="form-control" name="seo_file_title_template" value="<?= htmlspecialchars($seoConfig['seo_file_title_template']) ?>">
                        <small class="text-muted">Variables available: <code>%filename%</code>, <code>%site_name%</code>, <code>%page_title%</code>, <code>%version%</code>.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Public File Page Description Template</label>
                        <textarea class="form-control" name="seo_file_description_template" rows="3"><?= htmlspecialchars($seoConfig['seo_file_description_template']) ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="seo_file_noindex_private" id="seoFilePrivateNoindex" value="1" <?= $seoConfig['seo_file_noindex_private'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="seoFilePrivateNoindex">Noindex private or restricted file pages</label>
                    </div>

                    <div class="alert alert-light border-0 small">
                        <strong>Recommendation:</strong> Public file pages can drive traffic, but only if the filename pattern is useful. If your file names are random or thin, rely more on homepage and docs SEO until the public file inventory has enough quality.
                    </div>

                    <button type="submit" class="btn btn-primary px-4">Save Page Templates</button>
                </form>
            <?php elseif ($seoTab === 'indexing'): ?>
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="seo">
                    <input type="hidden" name="seo_scope" value="indexing">

                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body">
                            <div class="alert alert-info border-0 shadow-sm small mb-4">
                                <strong>These are live URLs, not uploaded files:</strong> Fyuhls generates <code>/robots.txt</code> and <code>/sitemap.xml</code> dynamically when search engines request them.
                                <div class="mt-2">
                                    <a href="<?= htmlspecialchars($robotsUrl) ?>" target="_blank" rel="noopener noreferrer" class="me-3">Open robots.txt</a>
                                    <a href="<?= htmlspecialchars($sitemapUrl) ?>" target="_blank" rel="noopener noreferrer">Open sitemap.xml</a>
                                </div>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="seo_sitemap_enabled" id="seoSitemapEnabled" value="1" <?= $seoConfig['seo_sitemap_enabled'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="seoSitemapEnabled">Enable XML Sitemap</label>
                                <div class="small text-muted mt-2">Turns on the live <code>/sitemap.xml</code> route so Google and other search engines can fetch your sitemap directly from the website URL.</div>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="seo_sitemap_include_files" id="seoSitemapFiles" value="1" <?= $seoConfig['seo_sitemap_include_files'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="seoSitemapFiles">Include public file pages in sitemap</label>
                                <div class="small text-muted mt-2">Adds eligible public file pages into the live sitemap feed. Those URLs are built dynamically from your public file records.</div>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="seo_robots_block_auth_pages" id="seoBlockAuth" value="1" <?= $seoConfig['seo_robots_block_auth_pages'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="seoBlockAuth">Block auth pages from indexing</label>
                                <div class="small text-muted mt-2">Adds auth-page rules to the live <code>/robots.txt</code> output and applies <code>noindex,nofollow</code> on login, register, and reset pages.</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="seo_noindex_internal_pages" id="seoNoindexInternal" value="1" <?= $seoConfig['seo_noindex_internal_pages'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="seoNoindexInternal">Noindex internal member pages</label>
                                <div class="small text-muted mt-2">Keeps member-only utility pages like settings, notifications, trash, recent, and shared out of search results through robots rules and page-level meta tags.</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info border-0 small">
                        <strong>Submitting to Google:</strong>
                        <div class="mt-2">Submit <code><?= htmlspecialchars($sitemapUrl) ?></code> in Google Search Console. It does not need to exist as a physical file on disk as long as the URL loads valid XML.</div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">Save Indexing Rules</button>
                </form>
            <?php elseif ($seoTab === 'verification'): ?>
                <form method="POST" action="/admin/configuration/save">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="seo">
                    <input type="hidden" name="seo_scope" value="verification">

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-bold">Google Search Console Verification</label>
                            <input type="text" class="form-control" name="seo_verification_google" value="<?= htmlspecialchars($seoConfig['seo_verification_google']) ?>">
                            <small class="text-muted">Paste the token value only, not the full meta tag.</small>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label fw-bold">Bing Webmaster Verification</label>
                            <input type="text" class="form-control" name="seo_verification_bing" value="<?= htmlspecialchars($seoConfig['seo_verification_bing']) ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Custom Head Code</label>
                        <textarea class="form-control font-monospace" name="seo_custom_head_code" rows="8"><?= htmlspecialchars($seoConfig['seo_custom_head_code']) ?></textarea>
                        <small class="text-muted">Use this for analytics, extra verification tags, or advanced social metadata. Only paste code you trust. This is intentionally privileged and runs on the frontend. Keep it concise; large blocks are rejected.</small>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">Save Verification and Scripts</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
