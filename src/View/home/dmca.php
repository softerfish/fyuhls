<?php
$title = 'DMCA Takedown Notice';
$metaDescription = 'Submit a DMCA takedown notice for copyrighted material hosted on this site.';
include __DIR__ . '/header.php';
?>

    <style>
        .dmca-shell {
            max-width: 800px;
            border: none;
            box-shadow: none;
            background: transparent;
        }
        .dmca-intro {
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 2rem;
        }
        .dmca-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        .dmca-textarea {
            width: 100%;
            padding: 0.625rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.875rem;
        }
        .dmca-help {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }
        .dmca-confirm-box {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        .dmca-confirm-title { margin-top: 0; }
        .dmca-confirm-label {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            cursor: pointer;
            font-weight: 400;
        }
        .dmca-confirm-checkbox {
            width: auto;
            margin-top: 0.25rem;
        }
    </style>

    <div class="auth-container dmca-shell">
        <h2>DMCA Takedown Notice</h2>
        <p class="dmca-intro">If you believe that your copyrighted work has been infringed, please complete the form below.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="POST">
            <?= \App\Core\Csrf::field() ?>
            <div class="dmca-grid">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" name="name" id="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label for="url">Infringing URL(s)</label>
                <textarea name="url" id="url" rows="7" class="dmca-textarea" placeholder="https://yourdomain.com/file/123&#10;https://yourdomain.com/file/456&#10;https://yourdomain.com/file/789" required><?= htmlspecialchars($_POST['url'] ?? '') ?></textarea>
                <p class="dmca-help">Paste one or more URLs here. You can paste a block of links and fyuhls will sort them into a one-link-per-line list.</p>
            </div>
            <div class="form-group">
                <label for="description">Detailed Description</label>
                <textarea name="description" id="description" rows="6" class="dmca-textarea" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="signature">Electronic Signature</label>
                <p class="dmca-help">Typing your full name here acts as your digital signature.</p>
                <input type="text" name="signature" id="signature" value="<?= htmlspecialchars($_POST['signature'] ?? '') ?>" required>
            </div>
            <div class="dmca-confirm-box">
                <p class="dmca-confirm-title"><strong>Confirmation:</strong></p>
                <label class="dmca-confirm-label">
                    <input type="checkbox" required class="dmca-confirm-checkbox">
                    I have a good faith belief that the use of the material is not authorized by the copyright owner, its agent, or the law.
                </label>
            </div>
            <?php include __DIR__ . '/../partials/captcha.php'; ?>
            <button type="submit" class="btn">Submit Takedown Notice</button>
        </form>
    </div>

<?php include __DIR__ . '/footer.php'; ?>

