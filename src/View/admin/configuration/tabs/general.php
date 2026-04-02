<form method="POST" action="/admin/configuration/save">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="section" value="general">
    
    <div class="row">
        <div class="col-md-6 mb-4">
            <label class="form-label fw-bold">Site Name</label>
            <input type="text" class="form-control" name="app_name" value="<?= htmlspecialchars($appName) ?>" placeholder="Fyuhls">
            <small class="text-muted">The name of your file hosting platform.</small>
        </div>
        <div class="col-md-6 mb-4">
            <label class="form-label fw-bold">Admin Notification Email</label>
            <input type="email" class="form-control" name="admin_notification_email" value="<?= htmlspecialchars($adminEmail) ?>" placeholder="admin@example.com">
            <small class="text-muted">Where alerts (DMCA, Abuse) are sent.</small>
        </div>
    </div>

    <div class="mb-4">
        <label class="form-label fw-bold">Reserved Usernames</label>
        <input type="text" class="form-control" name="reserved_usernames" value="<?= htmlspecialchars($reservedUsernames) ?>">
        <small class="text-muted">Comma-separated list of names that cannot be registered.</small>
    </div>

    <hr class="my-4">

    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="allow_registrations" id="allowReg" value="1" <?= ($allowRegistrations === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="allowReg">Allow New Registrations</label>
                </div>
                <small class="text-muted">Turn off to close public signups.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="require_email_verification" id="requireEmailVer" value="1" <?= ($requireEmailVer === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="requireEmailVer">Require Email Verification</label>
                </div>
                <small class="text-muted">Users must confirm their email before they can log in. New installs default to On.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_powered_by_footer" id="showPoweredBy" value="1" <?= ($showPoweredBy === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="showPoweredBy">Show 'Powered by' in Footer</label>
                </div>
                <small class="text-muted">We'd appreciate the support of leaving this enabled, as a lot of time has been put into making this script. Please consider a <a href="https://buymeacoffee.com/softerfish" target="_blank" rel="noopener noreferrer">Buy Me a Coffee</a> if you disable it.</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenanceMode" value="1" <?= ($maintenanceMode === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-danger" for="maintenanceMode">Maintenance Mode</label>
                </div>
                <small class="text-muted">Only admins can access the site when enabled.</small>
            </div>
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="demo_mode" id="demoMode" value="1" <?= ($demoMode === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-danger" for="demoMode">Demo Mode</label>
                </div>
                <small class="text-danger">Enables demo-mode behavior for the site and its designated demo admin account. Use this together with the demo-admin assignment in Users when you want one admin account to stay redacted and read-only while other admins keep normal access. Default: Off.</small>
            </div>
            </div>
            </div>

            <hr class="my-4">

            <h5 class="fw-bold mb-3"><i class="bi bi-play-btn me-2"></i> Video Processing</h5>
            <div class="row">
            <div class="col-md-6 mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="ffmpeg_enabled" id="ffmpegEnabled" value="1" <?= ($ffmpegEnabled === '1') ? 'checked' : '' ?> onchange="document.getElementById('ffmpegPathRow').style.display = this.checked ? '' : 'none'">
                <label class="form-check-label fw-bold" for="ffmpegEnabled">Enable FFmpeg (Video Thumbnails/Transcoding)</label>
            </div>
            <small class="text-muted">Requires FFmpeg binary to be installed on your server.</small>
            </div>
            <div class="col-md-6 mb-3" id="ffmpegPathRow" style="<?= ($ffmpegEnabled !== '1') ? 'display:none;' : '' ?>">
            <label class="form-label fw-bold">FFmpeg Binary Path</label>
            <input type="text" class="form-control" name="ffmpeg_path" value="<?= htmlspecialchars($ffmpegPath) ?>" placeholder="/usr/bin/ffmpeg">
            <small class="text-muted">Full path to the ffmpeg executable.</small>
            </div>
            </div>

            <div class="mt-4 pt-3 border-top">

        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-save me-2"></i> Save General Configuration
        </button>
    </div>
</form>
