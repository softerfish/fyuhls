<style>
    .download-page-shell{display:flex;justify-content:center;align-items:center;flex:1;padding:2rem;gap:2rem;max-width:1400px;margin:0 auto;width:100%}
    .download-page-sidebar{flex:0 0 300px;max-width:300px;display:none;align-self:center}
    .download-page-sidebar-card{background:#f1f5f9;padding:1rem;border-radius:8px;text-align:center;overflow-wrap:anywhere;word-break:break-all}
    .download-page-center{flex:1 1 auto;max-width:560px;min-width:0;width:100%}
    .download-page-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;box-sizing:border-box}
    .download-page-top-ad{background:#f1f5f9;padding:.75rem;text-align:center;border-radius:8px;margin-bottom:1.5rem;overflow-wrap:anywhere;word-break:break-all}
    .download-page-title{font-size:1.18rem;font-weight:700;line-height:1.28;margin:0;overflow-wrap:anywhere;word-break:break-all}
    .download-page-meta{color:#64748b;font-size:.875rem;margin:0 0 2rem}
    .download-file-bar{display:flex;align-items:flex-start;gap:1rem;margin-bottom:1.5rem;padding:1rem;border:1px solid #dbe4f0;border-radius:14px;background:#f8fafc}
    .download-file-bar__icon{flex:0 0 56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.5rem;font-weight:700}
    .download-file-bar__body{flex:1;min-width:0;display:flex;flex-direction:column;gap:.55rem}
    .download-file-bar__header{display:flex;align-items:flex-start;justify-content:space-between;gap:.9rem}
    .download-file-bar__meta{display:flex;flex-wrap:wrap;gap:.8rem;font-size:.875rem;color:#64748b}
    .download-file-bar__meta-item{display:inline-flex;align-items:center;gap:.35rem}
    .download-file-bar__meta-button{padding:0;background:none;border:none;color:#64748b;font-size:.875rem;cursor:pointer}
    .download-file-bar__meta-button:hover{text-decoration:underline;color:#475569}
    .download-file-bar__actions{display:flex;align-items:center;gap:.45rem;opacity:.86;flex:0 0 auto;padding-top:.1rem}
    .download-file-action{width:32px;height:32px;border-radius:8px;border:1px solid #d7e0ea;background:#f8fafc;color:#64748b;font-size:1rem;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all .18s ease}
    .download-file-action:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(15,23,42,.06);border-color:#cbd5e1;background:#fff;color:#334155}
    .download-file-action--delete{border-color:#fecaca;color:#dc2626;background:#fff7f7}
    .download-file-action--save-active{border-color:#16a34a;background:#16a34a;color:#fff}
    .download-file-action:disabled{cursor:default;opacity:1}
    .download-action-status{display:none;margin:1rem 0 0;font-size:.875rem}
    .download-action-status--success{color:#166534}
    .download-action-status--error{color:#b91c1c}
    .download-stream-card{margin:0 0 1.5rem;padding:1rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px}
    .download-stream-title{font-weight:700;color:#1d4ed8;margin-bottom:.5rem}
    .download-stream-copy{margin:0 0 .75rem;color:#334155;font-size:.9rem}
    .download-stream-video{width:100%;max-height:420px;border-radius:10px;background:#000}
    .download-stream-status{margin-top:.65rem;font-size:.85rem;color:#475569}
    .download-stream-disabled{margin:0 0 1.5rem;padding:1rem;background:#f8fafc;border:1px solid #cbd5e1;border-radius:12px;color:#475569;font-size:.9rem}
    .download-captcha-wrap{margin-bottom:1.5rem}
    .download-captcha-copy{font-size:.875rem;color:#475569;margin:0 0 .75rem}
    .download-timer-wrap{display:none;margin-bottom:1.5rem}
    .download-timer-copy{color:#475569;font-size:.9375rem;margin-bottom:1rem}
    .download-primary-button{width:100%;padding:.875rem;background:var(--primary-color,#2563eb);color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:not-allowed;opacity:.5;transition:opacity .2s}
    .download-primary-button--auto-width{width:auto}
    .download-primary-button--enabled{cursor:pointer;opacity:1}
    .download-overlay-wrap{position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;background:rgba(0,0,0,.8);display:flex;align-items:center;justify-content:center}
    .download-overlay-card{position:relative;max-width:90%;max-height:90%;background:#fff;padding:2rem;border-radius:12px;overflow:auto}
    .download-overlay-close{position:absolute;top:10px;right:10px;background:#ef4444;color:#fff;border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;font-weight:700}
    .download-overlay-body{overflow-wrap:anywhere;word-break:break-all}
    .download-page-bottom-ad{background:#f1f5f9;padding:.75rem;text-align:center;border-radius:8px;margin-top:1.5rem;overflow-wrap:anywhere;word-break:break-all}
    .download-share-panel{margin-top:1.5rem;padding:1rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}
    .download-share-heading{font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 .75rem}
    .download-share-row{margin-bottom:.875rem}
    .download-share-row:last-child{margin-bottom:0}
    .download-share-label{display:block;font-size:.85rem;font-weight:600;color:#334155;margin-bottom:.4rem}
    .download-share-control{display:flex;gap:.5rem;align-items:stretch}
    .download-share-input{flex:1;min-width:0;padding:.75rem .875rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;font-size:.88rem}
    .download-share-copy{padding:.75rem 1rem;border:0;border-radius:8px;background:#e2e8f0;color:#0f172a;font-weight:600;cursor:pointer;white-space:nowrap}
    .download-share-copy:hover{background:#cbd5e1}
    .download-share-toggle{margin-top:.25rem;padding:0;background:none;border:0;color:#2563eb;font-size:.875rem;font-weight:600;cursor:pointer}
    .download-share-toggle:hover{text-decoration:underline}
    .download-share-extra{margin-top:.875rem;padding-top:.875rem;border-top:1px solid #e2e8f0}
    .download-abuse-trigger-wrap{margin-top:1rem;text-align:left}
    .download-abuse-trigger{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:.8125rem;font-weight:500;transition:color .2s}
    .download-abuse-status{display:none;margin-top:1rem}
    @media (max-width: 640px){.download-share-control{flex-direction:column}.download-share-copy{width:100%}.download-file-bar{flex-wrap:wrap}.download-file-bar__header{flex-direction:column;align-items:flex-start}.download-file-bar__actions{padding-top:0}}
    @media (min-width: 1024px) {.download-ad-sidebar { display: block !important; }}
</style>

<?php
$downloadFile = isset($file) && is_array($file) ? $file : [];
$downloadPackage = isset($package) && is_array($package) ? $package : [];
$downloadUploadedLabel = !empty($downloadFile['created_at']) ? date('Y-m-d', strtotime((string)$downloadFile['created_at'])) : '';
$downloadDownloads = (int)($downloadFile['downloads'] ?? 0);
$downloadActionButtonTitle = !empty($downloadAlreadySaved) ? 'Already in your account' : 'Add to your account';
?>

<?php if (($adOverlay ?? '') !== ''): ?>
<div id="adOverlayWrap" class="download-overlay-wrap">
    <div class="download-overlay-card">
        <button type="button" id="closeAdOverlayBtn" class="download-overlay-close">&times;</button>
        <div class="download-overlay-body"><?= $adOverlay ?></div>
    </div>
</div>
<?php endif; ?>

<div class="download-page-shell">
    <?php if (($adLeft ?? '') !== ''): ?>
    <div class="download-ad-sidebar download-page-sidebar">
        <div class="download-page-sidebar-card"><?= $adLeft ?></div>
    </div>
    <?php endif; ?>

    <div class="download-page-center">
        <div class="download-page-card">
            <?php if (!empty($downloadPackage['block_adblock'])): ?>
                <?= (new \App\Service\SecurityService())->getAntiAdblockScript() ?>
            <?php endif; ?>

            <?php if (!empty($showAds)): ?>
            <div class="download-page-top-ad">
                <?= $adTop ?>
            </div>
            <?php endif; ?>

            <div class="download-file-bar">
                <div class="download-file-bar__icon" aria-hidden="true">&#8681;</div>
                <div class="download-file-bar__body">
                    <div class="download-file-bar__header">
                        <h1 class="download-page-title"><?= htmlspecialchars((string)($downloadFile['filename'] ?? 'File')) ?></h1>
                        <?php if (!empty($canDeleteFile) || !empty($downloadActionVisible)): ?>
                        <div class="download-file-bar__actions">
                            <?php if (!empty($canDeleteFile)): ?>
                                <button
                                    type="button"
                                    id="downloadDeleteBtn"
                                    class="download-file-action download-file-action--delete"
                                    title="Delete file"
                                    aria-label="Delete file"
                                >&#128465;</button>
                            <?php endif; ?>
                            <?php if (!empty($downloadActionVisible)): ?>
                                <button
                                    type="button"
                                    id="downloadSaveBtn"
                                    class="download-file-action <?= !empty($downloadAlreadySaved) ? 'download-file-action--save-active' : '' ?>"
                                    title="<?= htmlspecialchars($downloadActionButtonTitle) ?>"
                                    aria-label="<?= htmlspecialchars($downloadActionButtonTitle) ?>"
                                    <?= !empty($downloadAlreadySaved) ? 'disabled' : '' ?>
                                ><?= !empty($downloadAlreadySaved) ? '&#10003;' : '+' ?></button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="download-file-bar__meta">
                        <?php if ($downloadUploadedLabel !== ''): ?>
                            <span class="download-file-bar__meta-item">Uploaded on <?= htmlspecialchars($downloadUploadedLabel) ?></span>
                        <?php endif; ?>
                        <span class="download-file-bar__meta-item"><?= round(((int)($downloadFile['file_size'] ?? 0)) / 1024 / 1024, 2) ?> MB</span>
                        <span class="download-file-bar__meta-item"><?= number_format($downloadDownloads) ?> downloads</span>
                        <?php if (!empty($abuseReportsEnabled)): ?>
                            <button type="button" id="openAbuseModalBtn" class="download-file-bar__meta-button">Report abuse</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div id="downloadActionStatus" class="download-action-status" aria-live="polite"></div>

            <?php if (($streamUrl ?? null) !== null): ?>
            <div class="download-stream-card">
                <div class="download-stream-title">Streaming Preview Enabled</div>
                <p class="download-stream-copy">This video can be streamed directly in the browser. Reward credit only counts after the configured watch thresholds are met.</p>
                <video id="rewardStreamPlayer" class="download-stream-video" controls preload="metadata">
                    <source src="<?= htmlspecialchars((string)$streamUrl) ?>" type="<?= htmlspecialchars((string)$displayMimeType) ?>">
                </video>
                <div id="rewardStreamStatus" class="download-stream-status">Playback progress is being tracked for fraud protection.</div>
            </div>
            <?php elseif (!empty($streamingEligible)): ?>
            <div class="download-stream-disabled">Streaming support is enabled for this video, but the browser player is hidden when countdown or captcha gates are active. The standard download flow below still works.</div>
            <?php endif; ?>

            <form method="POST" action="/file/generate-link" id="downloadForm">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="file_id" value="<?= (int)($downloadFile['id'] ?? 0) ?>">
                <input type="hidden" name="timezone_offset" id="rfTimezoneOffset" value="">
                <input type="hidden" name="platform_bucket" id="rfPlatformBucket" value="">
                <input type="hidden" name="screen_bucket" id="rfScreenBucket" value="">

                <?php if (!empty($captchaDownload) && !empty($captchaSiteKey)): ?>
                    <div id="captchaWrap" class="download-captcha-wrap">
                        <p class="download-captcha-copy">Please complete the check below to continue.</p>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>
                        <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars((string)$captchaSiteKey) ?>" data-callback="onCaptchaSolved"></div>
                    </div>

                    <?php if (($waitTime ?? 0) > 0): ?>
                    <div id="timerWrap" class="download-timer-wrap">
                        <p class="download-timer-copy" id="timerMsg">Please wait <strong id="count"><?= (int)$waitTime ?></strong> seconds...</p>
                    </div>
                    <?php endif; ?>

                    <button type="submit" id="dlBtn" class="download-primary-button" disabled>Download Now</button>
                <?php else: ?>
                    <?php if (($waitTime ?? 0) > 0): ?>
                    <p class="download-timer-copy" id="timerMsg">Please wait <strong id="count"><?= (int)$waitTime ?></strong> seconds...</p>
                    <button type="submit" id="dlBtn" class="download-primary-button download-primary-button--auto-width btn btn-block" disabled>Download Now</button>
                    <?php else: ?>
                    <button type="submit" class="download-primary-button download-primary-button--enabled download-primary-button--auto-width btn btn-block">Download Now</button>
                    <?php endif; ?>
                <?php endif; ?>
            </form>

            <?php if (!empty($showAds)): ?>
            <div class="download-page-bottom-ad">
                <?= $adBottom ?>
            </div>
            <?php endif; ?>

            <?php \App\Core\View::render('home/partials/download_share_panel.php', ['shareFields' => $shareFields ?? []]); ?>

            <?php if (!empty($abuseReportsEnabled)): ?>
            <div id="abuseModal" class="modal-overlay">
                <div class="modal-container">
                    <div class="modal-header">
                        <h3>Report Abuse</h3>
                        <button type="button" class="modal-close" id="closeAbuseModalBtn">&times;</button>
                    </div>
                    <form id="abuseForm">
                        <input type="hidden" name="file_id" value="<?= (int)($downloadFile['id'] ?? 0) ?>">
                        <?= \App\Core\Csrf::field() ?>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="abuseReason">Reason for Report</label>
                                <select id="abuseReason" name="reason" class="form-control" required>
                                    <option value="" disabled selected>Select a reason...</option>
                                    <option value="copyright">Copyright Infringement (DMCA)</option>
                                    <option value="illegal">Illegal Materials</option>
                                    <option value="spam">Spam or Scam</option>
                                    <option value="other">Other Violation</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="abuseDetails">Additional Details (Optional)</label>
                                <textarea id="abuseDetails" name="details" class="form-control" placeholder="Please provide any additional context..."></textarea>
                            </div>
                            <?php if (!empty($reportCaptchaEnabled) && !empty($reportCaptchaSiteKey)): ?>
                            <div class="form-group">
                                <label class="d-block">Spam Protection</label>
                                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>
                                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars((string)$reportCaptchaSiteKey) ?>"></div>
                            </div>
                            <?php endif; ?>
                            <div id="abuseStatus" class="download-abuse-status"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" id="cancelAbuseModalBtn">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="abuseSubmitBtn">Submit Report</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($downloadActionVisible)): ?>
            <form id="downloadSaveForm" hidden>
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="file_id" value="<?= (int)($downloadFile['id'] ?? 0) ?>">
            </form>
            <?php endif; ?>

            <?php if (!empty($canDeleteFile)): ?>
            <form id="downloadDeleteForm" hidden>
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="file_id" value="<?= (int)($downloadFile['id'] ?? 0) ?>">
                <input type="hidden" name="delete_reason" id="downloadDeleteReasonField" value="">
            </form>
            <?php if (!empty($deleteRequiresReason)): ?>
            <div id="deleteReasonModal" class="modal-overlay">
                <div class="modal-container">
                    <div class="modal-header">
                        <h3>Delete File</h3>
                        <button type="button" class="modal-close" id="closeDeleteReasonModalBtn">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="deleteReasonInput">Reason for deleting this file</label>
                            <textarea id="deleteReasonInput" class="form-control" rows="4" placeholder="Explain why this file is being removed."></textarea>
                        </div>
                        <div id="deleteReasonStatus" class="download-action-status"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="cancelDeleteReasonModalBtn">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteReasonBtn">Delete File</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($adRight ?? '') !== ''): ?>
    <div class="download-ad-sidebar download-page-sidebar">
        <div class="download-page-sidebar-card"><?= $adRight ?></div>
    </div>
    <?php endif; ?>
</div>

<script>
<?php if (!empty($captchaDownload) && !empty($captchaSiteKey)): ?>
var waitTime = <?= (int)$waitTime ?>;
var captchaDone = false;
function onCaptchaSolved(token) {
    captchaDone = true;
    if (waitTime > 0) {
        document.getElementById("captchaWrap").querySelector("p").textContent = "Captcha verified!";
        document.getElementById("timerWrap").style.display = "block";
        startCountdown();
    } else {
        enableBtn();
    }
}
function startCountdown() {
    var count = waitTime;
    var el = document.getElementById("count");
    var timer = setInterval(function() {
        count--;
        el.textContent = count;
        if (count <= 0) {
            clearInterval(timer);
            document.getElementById("timerMsg").textContent = "Ready!";
            enableBtn();
        }
    }, 1000);
}
function enableBtn() {
    var btn = document.getElementById("dlBtn");
    btn.disabled = false;
    btn.classList.add("download-primary-button--enabled");
}
<?php elseif (($waitTime ?? 0) > 0): ?>
var count = <?= (int)$waitTime ?>;
var el = document.getElementById("count");
var timer = setInterval(function() {
    count--;
    el.textContent = count;
    if (count <= 0) {
        clearInterval(timer);
        document.getElementById("timerMsg").textContent = "Ready!";
        var btn = document.getElementById("dlBtn");
        btn.disabled = false;
        btn.classList.add("download-primary-button--enabled");
    }
}, 1000);
<?php endif; ?>

(function() {
    var tz = document.getElementById("rfTimezoneOffset");
    var platform = document.getElementById("rfPlatformBucket");
    var screenBucket = document.getElementById("rfScreenBucket");
    if (tz) {
        tz.value = String(new Date().getTimezoneOffset());
    }
    if (platform) {
        var ua = navigator.userAgent || "";
        var platformLabel = navigator.platform || "unknown";
        platform.value = platformLabel.substring(0, 64) + "|" + ua.substring(0, 24);
    }
    if (screenBucket && window.screen) {
        var width = Math.min(9999, window.screen.width || 0);
        var height = Math.min(9999, window.screen.height || 0);
        screenBucket.value = width + "x" + height;
    }
})();

function setDownloadActionStatus(message, kind) {
    const status = document.getElementById("downloadActionStatus");
    if (!status) {
        return;
    }
    status.textContent = message;
    status.className = "download-action-status";
    if (kind) {
        status.classList.add("download-action-status--" + kind);
    }
    status.style.display = message ? "block" : "none";
}

const closeAdOverlayBtn = document.getElementById("closeAdOverlayBtn");
if (closeAdOverlayBtn) {
    closeAdOverlayBtn.addEventListener("click", function() {
        const overlay = document.getElementById("adOverlayWrap");
        if (overlay) {
            overlay.style.display = "none";
        }
    });
}

document.querySelectorAll("[data-copy-target]").forEach(function(button) {
    button.addEventListener("click", async function() {
        const target = document.getElementById(button.getAttribute("data-copy-target"));
        if (!target) {
            return;
        }
        try {
            await navigator.clipboard.writeText(target.value);
            const original = button.textContent;
            button.textContent = "Copied";
            setTimeout(function() {
                button.textContent = original;
            }, 1400);
        } catch (err) {
            target.focus();
            target.select();
        }
    });
});
document.querySelectorAll("[data-share-toggle]").forEach(function(button) {
    button.addEventListener("click", function() {
        const target = document.getElementById(button.getAttribute("data-share-toggle"));
        if (!target) {
            return;
        }
        const expanded = button.getAttribute("aria-expanded") === "true";
        target.hidden = expanded;
        button.setAttribute("aria-expanded", expanded ? "false" : "true");
        button.textContent = expanded ? "More share options" : "Fewer share options";
    });
});

<?php if (!empty($downloadActionVisible)): ?>
const downloadSaveBtn = document.getElementById("downloadSaveBtn");
const downloadSaveForm = document.getElementById("downloadSaveForm");
if (downloadSaveBtn && downloadSaveForm) {
    downloadSaveBtn.addEventListener("click", function() {
        if (downloadSaveBtn.disabled) {
            return;
        }
        setDownloadActionStatus("Adding file to your account...", "");
        downloadSaveBtn.disabled = true;

        fetch("/file/save-to-account", {
            method: "POST",
            body: new FormData(downloadSaveForm),
            credentials: "same-origin"
        })
        .then(function(resp) {
            return resp.json();
        })
        .then(function(data) {
            if (data && data.status === "success") {
                downloadSaveBtn.innerHTML = "&#10003;";
                downloadSaveBtn.classList.add("download-file-action--save-active");
                setDownloadActionStatus(data.message || "File added to your account.", "success");
                return;
            }
            throw new Error((data && data.message) ? data.message : "Could not add the file to your account.");
        })
        .catch(function(error) {
            downloadSaveBtn.disabled = false;
            setDownloadActionStatus(error.message, "error");
        });
    });
}
<?php endif; ?>

<?php if (!empty($canDeleteFile)): ?>
function performDownloadDelete() {
    const deleteForm = document.getElementById("downloadDeleteForm");
    const deleteBtn = document.getElementById("downloadDeleteBtn");
    if (!deleteForm || !deleteBtn) {
        return;
    }

    deleteBtn.disabled = true;
    setDownloadActionStatus("Deleting file...", "");

    fetch("/file/delete", {
        method: "POST",
        body: new FormData(deleteForm),
        credentials: "same-origin"
    })
    .then(function(resp) {
        return resp.json();
    })
    .then(function(data) {
        if (data && data.status === "success") {
            setDownloadActionStatus(data.message || "File deleted.", "success");
            window.setTimeout(function() {
                window.location.href = (data && data.redirect_url) ? data.redirect_url : "/";
            }, 500);
            return;
        }
        throw new Error((data && data.message) ? data.message : "Delete failed.");
    })
    .catch(function(error) {
        deleteBtn.disabled = false;
        setDownloadActionStatus(error.message, "error");
        const deleteReasonStatus = document.getElementById("deleteReasonStatus");
        if (deleteReasonStatus) {
            deleteReasonStatus.textContent = error.message;
            deleteReasonStatus.className = "download-action-status download-action-status--error";
            deleteReasonStatus.style.display = "block";
        }
    });
}

const downloadDeleteBtn = document.getElementById("downloadDeleteBtn");
if (downloadDeleteBtn) {
    downloadDeleteBtn.addEventListener("click", function() {
        <?php if (!empty($deleteRequiresReason)): ?>
        const modal = document.getElementById("deleteReasonModal");
        const reasonStatus = document.getElementById("deleteReasonStatus");
        if (reasonStatus) {
            reasonStatus.textContent = "";
            reasonStatus.style.display = "none";
        }
        if (modal) {
            modal.style.display = "flex";
        }
        <?php else: ?>
        if (window.confirm("Delete this file permanently?")) {
            performDownloadDelete();
        }
        <?php endif; ?>
    });
}

<?php if (!empty($deleteRequiresReason)): ?>
function toggleDeleteReasonModal(show) {
    const modal = document.getElementById("deleteReasonModal");
    if (!modal) {
        return;
    }
    modal.style.display = show ? "flex" : "none";
    if (!show) {
        const input = document.getElementById("deleteReasonInput");
        if (input) {
            input.value = "";
        }
        const status = document.getElementById("deleteReasonStatus");
        if (status) {
            status.textContent = "";
            status.style.display = "none";
        }
    }
}

const confirmDeleteReasonBtn = document.getElementById("confirmDeleteReasonBtn");
if (confirmDeleteReasonBtn) {
    confirmDeleteReasonBtn.addEventListener("click", function() {
        const input = document.getElementById("deleteReasonInput");
        const hiddenReason = document.getElementById("downloadDeleteReasonField");
        const reason = input ? input.value.trim() : "";
        const status = document.getElementById("deleteReasonStatus");
        if (reason === "") {
            if (status) {
                status.textContent = "A delete reason is required.";
                status.className = "download-action-status download-action-status--error";
                status.style.display = "block";
            }
            return;
        }
        if (hiddenReason) {
            hiddenReason.value = reason;
        }
        toggleDeleteReasonModal(false);
        performDownloadDelete();
    });
}

const closeDeleteReasonModalBtn = document.getElementById("closeDeleteReasonModalBtn");
if (closeDeleteReasonModalBtn) {
    closeDeleteReasonModalBtn.addEventListener("click", function() {
        toggleDeleteReasonModal(false);
    });
}

const cancelDeleteReasonModalBtn = document.getElementById("cancelDeleteReasonModalBtn");
if (cancelDeleteReasonModalBtn) {
    cancelDeleteReasonModalBtn.addEventListener("click", function() {
        toggleDeleteReasonModal(false);
    });
}

const deleteReasonModal = document.getElementById("deleteReasonModal");
if (deleteReasonModal) {
    deleteReasonModal.addEventListener("click", function(event) {
        if (event.target === deleteReasonModal) {
            toggleDeleteReasonModal(false);
        }
    });
}
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($abuseReportsEnabled)): ?>
function toggleAbuseModal(show) {
    const modal = document.getElementById("abuseModal");
    modal.style.display = show ? "flex" : "none";
    if (!show) {
        document.getElementById("abuseForm").reset();
        document.getElementById("abuseStatus").style.display = "none";
        document.getElementById("abuseSubmitBtn").disabled = false;
    }
}

function submitAbuse(e) {
    e.preventDefault();
    const btn = document.getElementById("abuseSubmitBtn");
    const status = document.getElementById("abuseStatus");

    btn.disabled = true;
    status.style.display = "block";
    status.innerHTML = "<span style='color: var(--text-muted); font-size: 0.875rem;'>Submitting...</span>";

    const fd = new FormData(e.target);

    fetch("/file/report", {
        method: "POST",
        body: fd
    })
    .then(r => r.text())
    .then(txt => {
        if (txt.includes("Success")) {
            status.innerHTML = "<span style='color: var(--success-color); font-size: 0.875rem;'>" + txt.replace("Success: ", "") + "</span>";
            setTimeout(() => toggleAbuseModal(false), 2000);
        } else {
            status.innerHTML = "<span style='color: var(--error-color); font-size: 0.875rem;'>" + txt + "</span>";
            btn.disabled = false;
        }
    })
    .catch(function() {
        status.innerHTML = "<span style='color: var(--error-color); font-size: 0.875rem;'>Network error. Please try again.</span>";
        btn.disabled = false;
    });
}

const openAbuseModalBtn = document.getElementById("openAbuseModalBtn");
if (openAbuseModalBtn) {
    openAbuseModalBtn.addEventListener("click", function() {
        toggleAbuseModal(true);
    });
    openAbuseModalBtn.addEventListener("mouseenter", function() {
        openAbuseModalBtn.style.color = "#64748b";
    });
    openAbuseModalBtn.addEventListener("mouseleave", function() {
        openAbuseModalBtn.style.color = "#94a3b8";
    });
}

const abuseModal = document.getElementById("abuseModal");
if (abuseModal) {
    abuseModal.addEventListener("click", function(event) {
        if (event.target === abuseModal) {
            toggleAbuseModal(false);
        }
    });
}

const abuseForm = document.getElementById("abuseForm");
if (abuseForm) {
    abuseForm.addEventListener("submit", submitAbuse);
}

const closeAbuseModalBtn = document.getElementById("closeAbuseModalBtn");
if (closeAbuseModalBtn) {
    closeAbuseModalBtn.addEventListener("click", function() {
        toggleAbuseModal(false);
    });
}

const cancelAbuseModalBtn = document.getElementById("cancelAbuseModalBtn");
if (cancelAbuseModalBtn) {
    cancelAbuseModalBtn.addEventListener("click", function() {
        toggleAbuseModal(false);
    });
}
<?php endif; ?>

<?php if (($streamUrl ?? null) !== null && ($streamSessionId ?? null) !== null): ?>
(function() {
    const player = document.getElementById("rewardStreamPlayer");
    const status = document.getElementById("rewardStreamStatus");
    if (!player) return;
    const sessionId = <?= json_encode($streamSessionId) ?>;
    const fileId = <?= (int)$file['id'] ?>;
    const csrfToken = <?= json_encode($streamCsrf) ?>;
    let lastReported = 0;
    let completed = false;

    function sendUpdate(state) {
        if (!player.duration || !isFinite(player.duration)) return;
        const current = Math.max(0, player.currentTime || 0);
        const percent = Math.min(100, (current / player.duration) * 100);
        const payload = new URLSearchParams();
        payload.set("csrf_token", csrfToken);
        payload.set("file_id", String(fileId));
        payload.set("session_id", sessionId);
        payload.set("state", state);
        payload.set("watch_seconds", String(Math.floor(current)));
        payload.set("watch_percent", String(percent.toFixed(2)));
        payload.set("current_time", String(current.toFixed(2)));
        payload.set("duration", String(player.duration.toFixed(2)));

        fetch("/file/stream-heartbeat", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            credentials: "same-origin",
            body: payload.toString()
        }).then(function(resp) {
            return resp.json();
        }).then(function(data) {
            if (status && data && data.message) {
                status.textContent = data.message;
            }
        }).catch(function() {});
    }

    player.addEventListener("timeupdate", function() {
        if ((player.currentTime - lastReported) >= 10) {
            lastReported = player.currentTime;
            sendUpdate("progress");
        }
    });

    player.addEventListener("ended", function() {
        if (completed) return;
        completed = true;
        sendUpdate("complete");
    });
})();
<?php endif; ?>
</script>
