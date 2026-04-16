<style>
    .download-page-shell{display:flex;justify-content:center;align-items:center;flex:1;padding:2rem;gap:2rem;max-width:1400px;margin:0 auto;width:100%}
    .download-page-sidebar{flex:0 0 300px;max-width:300px;display:none;align-self:center}
    .download-page-sidebar-card{background:#f1f5f9;padding:1rem;border-radius:8px;text-align:center;overflow-wrap:anywhere;word-break:break-all}
    .download-page-center{flex:1 1 auto;max-width:560px;min-width:0;width:100%}
    .download-page-card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:2.5rem;width:100%;box-sizing:border-box}
    .download-page-top-ad{background:#f1f5f9;padding:.75rem;text-align:center;border-radius:8px;margin-bottom:1.5rem;overflow-wrap:anywhere;word-break:break-all}
    .download-page-title{font-size:1.25rem;font-weight:700;margin:0 0 .25rem;overflow-wrap:anywhere;word-break:break-all}
    .download-page-meta{color:#64748b;font-size:.875rem;margin:0 0 2rem}
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
    @media (max-width: 640px){.download-share-control{flex-direction:column}.download-share-copy{width:100%}}
    @media (min-width: 1024px) {.download-ad-sidebar { display: block !important; }}
</style>

<?php
$downloadFile = isset($file) && is_array($file) ? $file : [];
$downloadPackage = isset($package) && is_array($package) ? $package : [];
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

            <h1 class="download-page-title"><?= htmlspecialchars((string)($downloadFile['filename'] ?? 'File')) ?></h1>
            <p class="download-page-meta"><?= round(((int)($downloadFile['file_size'] ?? 0)) / 1024 / 1024, 2) ?> MB</p>

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
            <div class="download-abuse-trigger-wrap">
                <button type="button" id="openAbuseModalBtn" class="download-abuse-trigger">Report Abuse</button>
            </div>

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
