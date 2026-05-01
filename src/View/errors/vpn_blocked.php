<?php
$currentUserId = \App\Core\Auth::id() ?? 0;
$package = $currentUserId ? \App\Model\Package::getUserPackage($currentUserId) : \App\Model\Package::getGuestPackage();
$showAds = (bool)($package['show_ads'] ?? false);
$adLeft = $showAds ? \App\Model\Setting::get('ad_download_left', '') : '';
$adRight = $showAds ? \App\Model\Setting::get('ad_download_right', '') : '';
$adTop = $showAds ? \App\Model\Setting::get('ad_download_top', '') : '';
$adBottom = $showAds ? \App\Model\Setting::get('ad_download_bottom', '') : '';
$adOverlay = $showAds ? \App\Model\Setting::get('ad_download_overlay', '') : '';
?>
<style>
    .vpn-block-shell {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 2rem;
        gap: 2rem;
        max-width: 1400px;
        margin: 0 auto;
        width: 100%;
        box-sizing: border-box;
    }

    .vpn-block-sidebar {
        flex: 0 0 300px;
        max-width: 300px;
        display: none;
        align-self: center;
    }

    .vpn-block-sidebar-card,
    .vpn-block-top-ad,
    .vpn-block-bottom-ad {
        background: #f1f5f9;
        padding: 1rem;
        border-radius: 8px;
        text-align: center;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .vpn-block-center {
        flex: 1 1 auto;
        max-width: 620px;
        min-width: 0;
        width: 100%;
    }

    .vpn-block-card {
        width: 100%;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        padding: 2.5rem;
        text-align: center;
        box-sizing: border-box;
    }

    .vpn-block-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 1.25rem;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(239, 68, 68, 0.12);
        color: #dc2626;
        font-size: 2rem;
        font-weight: 700;
    }

    .vpn-block-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 0 0.5rem;
        color: #0f172a;
    }

    .vpn-block-copy {
        color: #475569;
        font-size: 1rem;
        line-height: 1.75;
        margin: 0 auto 1.5rem;
        max-width: 38rem;
    }

    .vpn-block-ip {
        margin: 0 auto 1.5rem;
        max-width: 28rem;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        border-radius: 10px;
        padding: 0.875rem 1rem;
        color: #334155;
        font-size: 0.95rem;
        overflow-wrap: anywhere;
        box-sizing: border-box;
    }

    .vpn-block-ip strong {
        color: #0f172a;
    }

    .vpn-block-actions {
        display: flex;
        justify-content: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .vpn-block-top-ad {
        margin-bottom: 1.5rem;
    }

    .vpn-block-bottom-ad {
        margin-top: 1.5rem;
    }

    .vpn-block-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 160px;
        padding: 0.8rem 1.1rem;
        border-radius: 10px;
        border: 1px solid transparent;
        text-decoration: none;
        font-weight: 600;
        box-sizing: border-box;
        transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .vpn-block-button--primary {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    .vpn-block-button--secondary {
        background: #fff;
        border-color: #cbd5e1;
        color: #0f172a;
    }

    .vpn-block-button:hover {
        text-decoration: none;
    }

    .vpn-block-button--primary:hover {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
    }

    .vpn-block-button--secondary:hover {
        background: #f8fafc;
        color: #0f172a;
    }

    .vpn-block-overlay-wrap {
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        box-sizing: border-box;
    }

    .vpn-block-overlay-card {
        position: relative;
        max-width: 90%;
        max-height: 90%;
        background: #fff;
        padding: 2rem;
        border-radius: 12px;
        overflow: auto;
        box-sizing: border-box;
    }

    .vpn-block-overlay-close {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #ef4444;
        color: #fff;
        border: 0;
        border-radius: 999px;
        width: 30px;
        height: 30px;
        cursor: pointer;
        font-weight: 700;
    }

    .vpn-block-overlay-body {
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    @media (min-width: 1100px) {
        .vpn-block-sidebar {
            display: block;
        }
    }

    @media (max-width: 640px) {
        .vpn-block-shell {
            padding: 1rem;
        }

        .vpn-block-card {
            padding: 1.5rem;
        }

        .vpn-block-actions {
            flex-direction: column;
        }

        .vpn-block-button {
            width: 100%;
        }
    }
</style>

<?php if ($adOverlay !== ''): ?>
    <div id="vpnBlockOverlayWrap" class="vpn-block-overlay-wrap">
        <div class="vpn-block-overlay-card">
            <button type="button" id="closeVpnBlockOverlayBtn" class="vpn-block-overlay-close">&times;</button>
            <div class="vpn-block-overlay-body"><?= $adOverlay ?></div>
        </div>
    </div>
<?php endif; ?>

<div class="vpn-block-shell">
    <?php if ($adLeft !== ''): ?>
        <div class="vpn-block-sidebar">
            <div class="vpn-block-sidebar-card"><?= $adLeft ?></div>
        </div>
    <?php endif; ?>

    <div class="vpn-block-center">
        <?php if ($adTop !== ''): ?>
            <div class="vpn-block-top-ad"><?= $adTop ?></div>
        <?php endif; ?>

        <div class="vpn-block-card">
            <div class="vpn-block-icon">!</div>
            <h1 class="vpn-block-title">VPN / Proxy Detected</h1>
            <p class="vpn-block-copy">
                To protect the service from abuse, we have decided to block usage of VPN, proxy, Tor exit, or similar.
                Disable it, then refresh the page and try again.
            </p>
            <?php if (!empty($ip)): ?>
                <div class="vpn-block-ip">
                    <strong>Your IP:</strong> <?= htmlspecialchars((string) $ip) ?>
                </div>
            <?php endif; ?>
            <div class="vpn-block-actions">
                <a href="/" class="vpn-block-button vpn-block-button--primary">Back to Home</a>
                <a href="/contact" class="vpn-block-button vpn-block-button--secondary">Contact Support</a>
            </div>
        </div>

        <?php if ($adBottom !== ''): ?>
            <div class="vpn-block-bottom-ad"><?= $adBottom ?></div>
        <?php endif; ?>
    </div>

    <?php if ($adRight !== ''): ?>
        <div class="vpn-block-sidebar">
            <div class="vpn-block-sidebar-card"><?= $adRight ?></div>
        </div>
    <?php endif; ?>
</div>

<?php if ($adOverlay !== ''): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const closeButton = document.getElementById("closeVpnBlockOverlayBtn");
    const overlay = document.getElementById("vpnBlockOverlayWrap");
    if (!closeButton || !overlay) {
        return;
    }
    closeButton.addEventListener("click", function() {
        overlay.style.display = "none";
    });
});
</script>
<?php endif; ?>
