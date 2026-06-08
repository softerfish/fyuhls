<?php
use App\Service\SiteContentService;

$showRewards = $showRewards ?? \App\Service\FeatureService::rewardsEnabled();
$showAffiliate = $showRewards;
$pageLocale = $pageLocale ?? SiteContentService::requestLocale();
$footerContent = SiteContentService::page('footer', $pageLocale);
$footerTokens = SiteContentService::tokenContext();
$footerBrand = $footerContent['brand'] ?? [];
$footerCustomLinks = $footerContent['custom_links'] ?? [];

$footerDefaultLinks = [
    ['label' => 'Contact Us', 'url' => SiteContentService::localizeUrl('/contact', $pageLocale), 'target' => '_self'],
    ['label' => 'API', 'url' => SiteContentService::localizeUrl('/api', $pageLocale), 'target' => '_self'],
];

if ($showAffiliate) {
    $footerDefaultLinks[] = ['label' => 'Affiliate', 'url' => SiteContentService::localizeUrl('/affiliate', $pageLocale), 'target' => '_self'];
}

$footerDefaultLinks[] = ['label' => 'FAQ', 'url' => SiteContentService::localizeUrl('/faq', $pageLocale), 'target' => '_self'];
$footerDefaultLinks[] = ['label' => 'DMCA', 'url' => SiteContentService::localizeUrl('/dmca', $pageLocale), 'target' => '_self'];

if (\App\Model\Setting::get('link_checker_enabled', '1') === '1') {
    $footerDefaultLinks[] = ['label' => 'Link Checker', 'url' => SiteContentService::localizeUrl('/link-checker', $pageLocale), 'target' => '_self'];
}

$footerLinks = array_merge($footerDefaultLinks, array_values(array_map(static function (array $link) use ($pageLocale): array {
    $link['url'] = SiteContentService::localizeUrl((string)($link['url'] ?? ''), $pageLocale);
    return $link;
}, array_filter((array)$footerCustomLinks, static function (array $link): bool {
    return trim((string)($link['label'] ?? '')) !== '' && trim((string)($link['url'] ?? '')) !== '';
}))));
?>
    </main>

    <style>
    .home-footer {
        background: white;
        border-top: 1px solid var(--border-color);
        padding: 3rem 0;
        margin-top: auto;
    }
    .home-footer-inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .home-footer-brand {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .home-footer-name {
        font-weight: 700;
        color: var(--primary-color);
        font-size: 1.25rem;
        letter-spacing: -0.5px;
    }
    .home-footer-tagline,
    .home-footer-link,
    .home-footer-powered {
        color: #9ca3af;
        font-size: 0.875rem;
    }
    .home-footer-tagline { color: #6b7280; }
    .home-footer-links {
        display: flex;
        gap: 2rem;
    }
    .home-footer-link {
        text-decoration: none;
    }
    .home-footer-hidden { display: none; }
    .home-action-modal-card { max-width: 400px; padding: 2rem; }
    .home-action-modal-title { margin-top: 0; }
    .home-action-modal-copy { color: #6b7280; font-size: 0.95rem; }
    .home-action-input-wrap { display: none; margin: 1.5rem 0; }
    .home-action-input {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
    }
    .home-action-footer {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        margin-top: 2rem;
    }
    .home-action-cancel {
        background: #f3f4f6;
        color: #4b5563;
    }
    </style>

    <footer class="home-footer">
        <div class="home-footer-inner">
            <div class="home-footer-brand">
                <span class="home-footer-name"><?= htmlspecialchars($siteName ?? \App\Model\Setting::getOrConfig('app.name', 'Fyuhls')) ?></span>
                <span class="home-footer-tagline"><?= SiteContentService::renderInlineMarkdown((string)($footerBrand['tagline'] ?? 'Secure Cloud Storage'), $footerTokens) ?></span>
            </div>
            <div class="home-footer-links">
                <?php foreach ($footerLinks as $link): ?>
                    <?php
                    $target = (string)($link['target'] ?? '_self');
                    $newTab = $target === '_blank';
                    ?>
                    <a href="<?= htmlspecialchars((string)$link['url']) ?>" class="home-footer-link"<?= $newTab ? ' target="_blank" rel="noopener"' : '' ?>><?= htmlspecialchars((string)$link['label']) ?></a>
                <?php endforeach; ?>
                <?php if (\App\Model\Setting::get('show_powered_by_footer', '1') === '1'): ?>
                    <span class="home-footer-powered">Powered by: <a href="https://fyuhls.com" target="_blank" class="home-footer-link">fyuhls.com</a></span>
                <?php endif; ?>
            </div>
        </div>
    </footer>

    <div id="contextMenu" class="context-menu home-footer-hidden">
        <ul>
            <li id="ctxDownload"><span class="icon" aria-hidden="true">&#11015;</span> Download</li>
            <li id="ctxReplace"><span class="icon" aria-hidden="true">&#128260;</span> Replace File</li>
            <li id="ctxRename"><span class="icon" aria-hidden="true">&#9998;</span> Rename</li>
            <li id="ctxMove"><span class="icon" aria-hidden="true">&#128194;</span> Move to...</li>
            <li id="ctxCopy"><span class="icon" aria-hidden="true">&#128203;</span> Copy to...</li>
            <li id="ctxProps"><span class="icon" aria-hidden="true">&#8505;</span> Properties</li>
            <li class="separator"></li>
            <li id="ctxTrash" class="text-danger"><span class="icon" aria-hidden="true">&#128465;</span> Move to Trash</li>
        </ul>
    </div>

    <div id="actionModal" class="modal home-footer-hidden">
        <div class="modal-content home-action-modal-card">
            <h3 id="modalTitle" class="home-action-modal-title">Confirm Action</h3>
            <p id="modalDescription" class="home-action-modal-copy"></p>
            <div id="modalInputContainer" class="home-action-input-wrap">
                <input type="text" id="modalInput" class="form-control home-action-input" placeholder="Enter value...">
            </div>
            <div class="modal-footer home-action-footer">
                <button class="btn btn-secondary home-action-cancel" id="modalCancelBtn">Cancel</button>
                <button class="btn" id="modalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('click', function(event) {
        const navTarget = event.target.closest('[data-nav-url]');
        if (navTarget) {
            event.preventDefault();
            const url = navTarget.getAttribute('data-nav-url');
            const target = navTarget.getAttribute('data-nav-target');
            if (url) {
                if (target === '_blank') {
                    window.open(url, '_blank', 'noopener');
                } else if (
                    typeof window.__fmHasBlockingUploads === 'function' &&
                    window.__fmHasBlockingUploads() &&
                    typeof window.__fmOpenWorkspaceUrl === 'function' &&
                    (url === '/' || /^\/folder\/\d+(?:[/?#]|$)/.test(url))
                ) {
                    window.__fmOpenWorkspaceUrl(url);
                } else {
                    window.location.href = url;
                }
            }
            return;
        }

        const copyTarget = event.target.closest('[data-copy-previous]');
        if (copyTarget) {
            const source = copyTarget.previousElementSibling;
            const value = source && 'value' in source ? source.value : '';
            if (value) {
                const successMessage = copyTarget.getAttribute('data-copy-success') || 'Copied!';
                const failureMessage = copyTarget.getAttribute('data-copy-failure') || 'Unable to copy automatically. Please select and copy the value manually.';
                const showResult = function(message) {
                    if (message) {
                        alert(message);
                    }
                };
                const fallbackCopy = function() {
                    if (!source || typeof source.select !== 'function') {
                        showResult(failureMessage);
                        return;
                    }

                    source.focus();
                    source.select();
                    try {
                        if (document.execCommand && document.execCommand('copy')) {
                            showResult(successMessage);
                        } else {
                            showResult(failureMessage);
                        }
                    } catch (error) {
                        showResult(failureMessage);
                    } finally {
                        if (window.getSelection) {
                            const selection = window.getSelection();
                            if (selection && typeof selection.removeAllRanges === 'function') {
                                selection.removeAllRanges();
                            }
                        }
                    }
                };

                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    navigator.clipboard.writeText(value)
                        .then(function() {
                            showResult(successMessage);
                        })
                        .catch(fallbackCopy);
                } else {
                    fallbackCopy();
                }
            }
        }
    });
    </script>
    <?= $extraBottom ?? '' ?>
</body>
</html>
