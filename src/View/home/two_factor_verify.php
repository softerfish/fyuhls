<?php include __DIR__ . '/header.php'; ?>
<div style="flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem;">
    <div class="auth-container shadow-lg" style="max-width: 450px; background: white; border-radius: 12px; padding: 2.5rem; border: 1px solid var(--border-color);">
        <div class="text-center mb-4">
            <div class="p-3 rounded-circle d-inline-block mb-3" style="background: rgba(37, 99, 235, 0.1);">
                <i class="bi bi-shield-lock-fill text-primary" style="font-size: 2rem;"></i>
            </div>
            <h2 class="fw-bold">Two-Factor Authentication</h2>
            <p class="text-muted small">Enter the 6-digit code from your authenticator app to continue.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small mb-4 text-center"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/2fa/verify" class="mb-4">
            <?= \App\Core\Csrf::field() ?>
            <div class="mb-4">
                <input type="text" name="code" class="form-control form-control-lg text-center fw-bold" placeholder="000000" maxlength="6" autofocus required style="letter-spacing: 0.5rem; font-size: 1.5rem;">
            </div>
            <div class="form-check mb-4 small">
                <input class="form-check-input" type="checkbox" name="trust_device" id="trustDevice" value="1">
                <label class="form-check-label text-muted" for="trustDevice">Trust this device for 30 days</label>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-3 shadow-sm">Verify & Login</button>
        </form>

        <hr class="my-4 opacity-10">

        <div class="text-center">
            <button class="btn btn-link btn-sm text-decoration-none text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#recoverySection">
                Lost your phone? Use a recovery code
            </button>
            <div class="collapse mt-3" id="recoverySection">
                <form method="POST" action="/2fa/recovery">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-uppercase" style="font-size: 0.7rem;">Recovery Code</label>
                        <input type="text" name="recovery_code" class="form-control text-center" placeholder="XXXX-XXXX-XXXX" required>
                    </div>
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Use Recovery Code</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
