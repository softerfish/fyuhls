<?php
$extraHead = '
<script src="https://cdn.jsdelivr.net/npm/kjua@0.9.0/dist/kjua.min.js"></script>
<link rel="stylesheet" href="/assets/css/filemanager.css?v=' . time() . '">
';
include __DIR__ . '/header.php';
?>
<div class="two-factor-setup-container fm-container">
    <div class="two-factor-setup-main fm-main">
        <div class="fm-toolbar">
            <div class="toolbar-left">
                <h2 class="folder-title">Security: Setup 2FA</h2>
                <div class="breadcrumbs">
                    <a href="/">Home</a>
                    <span class="crumb-sep">/</span>
                    <a href="/settings">Settings</a>
                    <span class="crumb-sep">/</span>
                    <span>2FA Setup</span>
                </div>
            </div>
        </div>

        <div class="two-factor-setup-content">
            <div class="mb-5 mt-2">
                <p class="text-muted">Two-Factor Authentication adds a second layer of security to your account. In addition to your password, you will need a code from your phone to log in.</p>
            </div>

            <?php if (isset($_SESSION['2fa_error'])): ?>
                <div class="alert alert-danger mb-4"><?= htmlspecialchars($_SESSION['2fa_error']); unset($_SESSION['2fa_error']); ?></div>
            <?php endif; ?>

            <div class="row g-5">
                <div class="col-md-5">
                    <div class="card shadow-sm border-0 bg-white">
                        <div class="card-body p-4 text-center">
                            <h6 class="fw-bold mb-3 text-uppercase small text-muted">Scan The QR Code</h6>
                            <div id="qr-container" class="p-2 border rounded mb-3 bg-white d-inline-block"></div>
                            <p class="extra-small text-muted mt-2">Open your authenticator app and scan the code above. Proton Auth and Ente Auth both work well.</p>
                            <div class="mt-4 pt-3 border-top">
                                <span class="extra-small fw-bold text-muted text-uppercase">Manual Key</span>
                                <div class="bg-light p-2 rounded font-monospace extra-small text-break mt-2 border">
                                    <?= htmlspecialchars($secret) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <h6 class="fw-bold mb-3 text-uppercase small text-muted">Save These Recovery Codes</h6>
                    <div class="p-4 bg-light border rounded-3 mb-4">
                        <p class="small text-danger fw-bold mb-3">Important: save these codes now.</p>
                        <p class="extra-small text-muted mb-3">If you lose your phone, these are the only way to recover your account. Each code can be used once.</p>
                        <div class="row g-2">
                            <?php foreach ($recoveryCodes as $code): ?>
                                <div class="col-6">
                                    <div class="p-2 bg-white border rounded font-monospace small text-center"><?= htmlspecialchars($code) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <hr class="my-4 opacity-10">

                    <h6 class="fw-bold mb-3 text-uppercase small text-muted">Verify And Activate</h6>
                    <p class="small mb-3 text-muted">Enter the current 6-digit code from your authenticator app.</p>
                    <form method="POST" action="/2fa/setup">
                        <?= \App\Core\Csrf::field() ?>
                        <div class="text-center">
                            <input type="text" name="code" class="two-factor-setup-code form-control form-control-lg mx-auto fw-bold" placeholder="000000" maxlength="6" required>
                        </div>
                        <button class="btn btn-primary w-100 py-3 fw-bold mt-2" type="submit">Enable 2FA Now</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const qr = kjua({ text: "<?= $qrUrl ?>", size: 200, fill: '#000', back: '#fff', rounded: 10 });
document.getElementById('qr-container').appendChild(qr);
</script>
<style>
.extra-small { font-size: 0.75rem; }
.fm-main { background: #fdfdfd; }
.two-factor-setup-container { margin-top: 1rem; }
.two-factor-setup-main { margin-left: auto; margin-right: auto; max-width: 980px; }
.two-factor-setup-content { max-width: 850px; padding: 0 2rem 2rem 2rem; }
.two-factor-setup-code { letter-spacing: 0.3rem; border-color: var(--primary-color); width: 40%; margin-bottom: 3rem !important; }
</style>
<?php include __DIR__ . '/footer.php'; ?>
