<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">General Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#general-identity"><i class="bi bi-badge-ad me-2"></i> Site Identity</a>
            <a class="nav-link text-start" href="#general-platform"><i class="bi bi-toggles me-2"></i> Platform Controls</a>
            <a class="nav-link text-start" href="#general-thumbnails"><i class="bi bi-image me-2"></i> Thumbnail Generation</a>
            <a class="nav-link text-start" href="#general-transcoding"><i class="bi bi-film me-2"></i> Video Transcoding</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">General</h5>
                <p class="config-section-intro__text">Manage the core site identity, member-facing platform switches, and preview the media features planned for an upcoming release.</p>
            </div>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>These are the install-wide defaults that define what the site is called, whether new users can sign up, and what upcoming media capabilities are planned next. Thumbnail generation and video transcoding are intentionally held for a later release.</p>
            </div>
        </details>

<form method="POST" action="/admin/configuration/save">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="section" value="general">

    <div id="general-identity"></div>
    <?php renderAdminCardStart('Site Identity', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="row">
            <div class="col-md-6 mb-4">
                <label class="form-label fw-bold">Site Name</label>
                <input type="text" class="form-control" name="app_name" value="<?= htmlspecialchars($appName) ?>" placeholder="Fyuhls">
                <small class="config-form-note">The name of your file hosting platform.</small>
            </div>
            <div class="col-md-6 mb-4">
                <label class="form-label fw-bold">Admin Notification Email</label>
                <input type="email" class="form-control" name="admin_notification_email" value="<?= htmlspecialchars($adminEmail) ?>" placeholder="admin@example.com">
                <small class="config-form-note">Where alerts (DMCA, Abuse) are sent.</small>
            </div>
        </div>
        <div class="mb-0">
            <label class="form-label fw-bold">Reserved Usernames</label>
            <input type="text" class="form-control" name="reserved_usernames" value="<?= htmlspecialchars($reservedUsernames) ?>">
            <small class="config-form-note">Comma-separated list of names that cannot be registered.</small>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div id="general-platform"></div>
    <?php renderAdminCardStart('Platform Controls', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="row">
            <div class="col-md-6">
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_registrations" id="allowReg" value="1" <?= ($allowRegistrations === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="allowReg">Allow New Registrations</label>
                </div>
                <small class="config-form-note">Turn off to close public signups.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="require_email_verification" id="requireEmailVer" value="1" <?= ($requireEmailVer === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="requireEmailVer">Require Email Verification</label>
                </div>
                <small class="config-form-note">Users must confirm their email before they can log in. New installs default to On.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_powered_by_footer" id="showPoweredBy" value="1" <?= ($showPoweredBy === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showPoweredBy">Show 'Powered by' in Footer</label>
                </div>
                <small class="config-form-note">We'd appreciate the support of leaving this enabled, as a lot of time has been put into making this script. Please consider a <a href="https://buymeacoffee.com/softerfish" target="_blank" rel="noopener noreferrer">Buy Me a Coffee</a> if you disable it.</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenanceMode" value="1" <?= ($maintenanceMode === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-danger" for="maintenanceMode">Maintenance Mode</label>
                </div>
                <small class="config-form-note">Only admins can access the site when enabled.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="demo_mode" id="demoMode" value="1" <?= ($demoMode === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-danger" for="demoMode">Demo Mode</label>
                </div>
                <small class="config-form-note text-danger">Enables demo-mode behavior for the site and its designated demo admin account. A staff manager can predesignate that admin from Users before turning demo mode on, so the target account does not need to sign into itself first. If no one is designated yet, enabling demo mode falls back to the current admin. Default: Off.</small>
            </div>
            </div>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div id="general-thumbnails"></div>
    <?php renderAdminCardStart('Thumbnail Generation', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="config-soft-callout config-soft-callout--info mb-4">
            <h6 class="fw-bold mb-2"><i class="bi bi-hourglass-split me-2"></i>Coming soon</h6>
            <p class="mb-2">Thumbnail generation for both images and videos is being held for a future release.</p>
            <p class="mb-0">When it ships, image thumbnails will rely on PHP GD and video thumbnails will rely on FFmpeg.</p>
        </div>

        <div class="row">
            <div class="col-lg-6 mb-4">
                <label class="form-label fw-bold">Image Thumbnails</label>
                <div class="config-soft-callout config-soft-callout--warning">
                    <p class="mb-2"><i class="bi bi-image me-2"></i>Image thumbnail generation is not configurable in this release yet.</p>
                    <p class="mb-0">We will wire in the GD-based generation flow in a later update.</p>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <label class="form-label fw-bold">Video Thumbnails</label>
                <div class="config-soft-callout config-soft-callout--warning">
                    <p class="mb-2"><i class="bi bi-camera-video me-2"></i>Video thumbnail generation is also coming later.</p>
                    <p class="mb-0">FFmpeg is still worth planning for now, but shared hosting often does not support it because the host may not provide the binary or may block <code>shell_exec</code> and similar PHP process functions.</p>
                </div>
            </div>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div id="general-transcoding"></div>
    <?php renderAdminCardStart('Video Transcoding', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
        <div class="config-soft-callout config-soft-callout--info">
            <h6 class="fw-bold mb-2"><i class="bi bi-hourglass-split me-2"></i>Coming soon</h6>
            <p class="mb-2">Server-side video transcoding is not available in this build yet.</p>
            <p class="mb-0">We are intentionally holding both transcoding and thumbnail-generation controls until the underlying conversion workflow is ready for a later release.</p>
        </div>
    <?php renderAdminCardEnd(); ?>

    <div class="config-sticky-save">
        <p class="config-sticky-save__text">General settings control the install-wide defaults most users feel first, while the media sections above preview the thumbnail and transcoding features planned for a future release.</p>
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-save me-2"></i> Save General Configuration
        </button>
    </div>
</form>
</div>
</div>
