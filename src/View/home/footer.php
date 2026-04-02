<?php
$showRewards = $showRewards ?? \App\Service\FeatureService::rewardsEnabled();
$showAffiliate = \App\Service\FeatureService::affiliateEnabled();
?>
    </main>

    <footer style="background: white; border-top: 1px solid var(--border-color); padding: 3rem 0; margin-top: auto;">
        <div style="max-width: 1200px; margin: 0 auto; padding: 0 2rem; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <span style="font-weight: 700; color: var(--primary-color); font-size: 1.25rem; letter-spacing: -0.5px;"><?= htmlspecialchars($siteName ?? \App\Model\Setting::getOrConfig('app.name', 'Fyuhls')) ?></span>
                <span style="color: #6b7280; font-size: 0.875rem;">Secure Cloud Storage</span>
            </div>
            <div style="display: flex; gap: 2rem;">
                <a href="/contact" style="color: #9ca3af; text-decoration: none; font-size: 0.875rem;">Contact Us</a>
                <a href="/api" style="color: #9ca3af; text-decoration: none; font-size: 0.875rem;">API</a>
                <?php if ($showAffiliate): ?>
                    <a href="/affiliate" style="color: #9ca3af; text-decoration: none; font-size: 0.875rem;">Affiliate</a>
                <?php endif; ?>
                <a href="/faq" style="color: #9ca3af; text-decoration: none; font-size: 0.875rem;">FAQ</a>
                <a href="/dmca" style="color: #9ca3af; text-decoration: none; font-size: 0.875rem;">DMCA</a>
                <?php if (\App\Model\Setting::get('show_powered_by_footer', '1') === '1'): ?>
                    <span style="color: #9ca3af; font-size: 0.875rem;">Powered by: <a href="https://fyuhls.com" target="_blank" style="color: #9ca3af; text-decoration: none;">fyuhls.com</a></span>
                <?php endif; ?>
            </div>
        </div>
    </footer>

    <div id="contextMenu" class="context-menu" style="display: none;">
        <ul>
            <li id="ctxDownload"><span class="icon" aria-hidden="true">&#11015;</span> Download</li>
            <li id="ctxRename"><span class="icon" aria-hidden="true">&#9998;</span> Rename</li>
            <li id="ctxMove"><span class="icon" aria-hidden="true">&#128194;</span> Move to...</li>
            <li id="ctxCopy"><span class="icon" aria-hidden="true">&#128203;</span> Copy to...</li>
            <li id="ctxProps"><span class="icon" aria-hidden="true">&#8505;</span> Properties</li>
            <li class="separator"></li>
            <li id="ctxTrash" class="text-danger"><span class="icon" aria-hidden="true">&#128465;</span> Move to Trash</li>
        </ul>
    </div>

    <div id="actionModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 400px; padding: 2rem;">
            <h3 id="modalTitle" style="margin-top: 0;">Confirm Action</h3>
            <p id="modalDescription" style="color: #6b7280; font-size: 0.95rem;"></p>
            <div id="modalInputContainer" style="display: none; margin: 1.5rem 0;">
                <input type="text" id="modalInput" class="form-control" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 8px;" placeholder="Enter value...">
            </div>
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                <button class="btn btn-secondary" id="modalCancelBtn" style="background: #f3f4f6; color: #4b5563;">Cancel</button>
                <button class="btn" id="modalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
    <?= $extraBottom ?? '' ?>
</body>
</html>
