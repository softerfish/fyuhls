<?php
$siteName = \App\Model\Setting::getOrConfig('app.name', \App\Core\Config::get('app_name', 'Fyuhls'));
$title = "FAQ - {$siteName}";
$metaDescription = "Frequently asked questions about uploads, storage limits, download rules, package behavior, account access, and rewards on {$siteName}.";
include __DIR__ . '/header.php';

$requireAccountToDownload = \App\Model\Setting::get('require_account_to_download', '0') === '1';
$allowRegistrations = \App\Model\Setting::get('allow_registrations', '1') === '1';
?>

<div class="container">
    <div class="header">
        <h1>Frequently Asked Questions</h1>
        <p>Answers based on the current package rules, site configuration, and how this Fyuhls install is set up right now.</p>
    </div>

    <div class="faq-item">
        <h3>Is there a limit to the file size I can upload?</h3>
        <div class="faq-answer">
            <p>
                <?php
                $sentences = ["Upload limits depend on the package assigned to your account."];
                foreach ($packages as $pkg) {
                    $size = (int)$pkg['max_upload_size'];
                    $formattedSize = $size === 0
                        ? "can upload files of any size allowed by the server"
                        : "can upload a file up to " . (($size >= 1073741824) ? round($size / 1073741824, 2) . ' GB' : round($size / 1048576, 2) . ' MB');
                    $sentences[] = "A " . strtolower($pkg['name']) . " account " . $formattedSize . ".";
                }
                echo implode(' ', $sentences);
                ?>
            </p>
        </div>
    </div>

    <div class="faq-item">
        <h3>How long are my files stored?</h3>
        <div class="faq-answer">
            <p>
                <?php
                $sentences = ["Retention is based on package rules and is measured from the last download date."];
                foreach ($packages as $pkg) {
                    $days = (int)$pkg['file_expiry_days'];
                    $text = $days === 0 ? "stored indefinitely" : "stored for {$days} days after the most recent download";
                    $sentences[] = "Files uploaded under " . strtolower($pkg['name']) . " are " . $text . ".";
                }
                echo implode(' ', $sentences);
                ?>
            </p>
        </div>
    </div>

    <div class="faq-item">
        <h3>Is there a limit to the storage space I can use?</h3>
        <div class="faq-answer">
            <p>
                <?php
                $sentences = ["Total storage depends on your package."];
                foreach ($packages as $pkg) {
                    $bytes = (int)$pkg['max_storage_bytes'];
                    $formattedStorage = $bytes === 0
                        ? "has unlimited storage"
                        : "has a storage limit of " . (($bytes >= 1073741824) ? round($bytes / 1073741824, 2) . ' GB' : round($bytes / 1048576, 2) . ' MB');
                    $sentences[] = "A " . strtolower($pkg['name']) . " account " . $formattedStorage . ".";
                }
                echo implode(' ', $sentences);
                ?>
            </p>
        </div>
    </div>

    <div class="faq-item">
        <h3>Does this site support large-file uploads?</h3>
        <div class="faq-answer">
            <p>Yes. Fyuhls is built around package-based upload limits and supports larger multipart-friendly upload flows on installations that are configured for object storage and resumable transfer handling.</p>
        </div>
    </div>

    <div class="faq-item">
        <h3>Do downloads have access restrictions?</h3>
        <div class="faq-answer">
            <p>
                <?= $requireAccountToDownload
                    ? 'Yes. This site currently requires you to create an account and log in before a download can begin.'
                    : 'Guest downloads are currently allowed, although package rules and security checks may still apply.' ?>
            </p>
        </div>
    </div>

    <div class="faq-item">
        <h3>Does the platform include an API?</h3>
        <div class="faq-answer">
            <p>Yes. Fyuhls includes a public API with personal API tokens, multipart upload session support, managed upload shortcuts, file metadata access, and application-controlled download links.</p>
        </div>
    </div>

    <div class="faq-item">
        <h3>Are there speed or bandwidth limits?</h3>
        <div class="faq-answer">
            <p>
                <?php
                $sentences = ["Download speed and daily transfer limits vary by package."];
                foreach ($packages as $pkg) {
                    $speed = (int)$pkg['download_speed'];
                    $formattedSpeed = $speed === 0
                        ? "unlimited speed"
                        : (($speed >= 1048576) ? round($speed / 1048576, 2) . ' MB/s' : round($speed / 1024, 2) . ' KB/s');
                    $dailyBytes = (int)$pkg['max_daily_downloads'];
                    $dailyText = $dailyBytes === 0
                        ? "no daily transfer cap"
                        : "a daily transfer limit of " . (($dailyBytes >= 1073741824) ? round($dailyBytes / 1073741824, 2) . ' GB' : round($dailyBytes / 1048576, 2) . ' MB');
                    $sentences[] = ucfirst($pkg['name']) . " gets {$formattedSpeed} with {$dailyText}.";
                }
                echo implode(' ', $sentences);
                ?>
            </p>
        </div>
    </div>

    <div class="faq-item">
        <h3>Can I share my files with others?</h3>
        <div class="faq-answer">
            <p>Yes. Each uploaded file can generate its own shareable link. Visibility and direct-link behavior still depend on the package and site rules set by the admin.</p>
        </div>
    </div>

    <div class="faq-item">
        <h3>How do I create or upgrade an account?</h3>
        <div class="faq-answer">
            <p>
                <?= $allowRegistrations
                    ? 'You can register directly from the site. If a paid package is available, upgrade links appear on the pricing area and checkout pages.'
                    : 'New registrations are currently closed. Existing users can still log in and use the site normally.' ?>
            </p>
        </div>
    </div>

    <div class="cta">
        <h2>Still have questions?</h2>
        <p style="margin-bottom: 2rem; color: var(--text-muted);">If you are unsure which package or rule applies to your account, contact support directly.</p>
        <a href="/contact" class="btn" style="width: auto; padding: 0.875rem 2.5rem;">Contact Support</a>
    </div>
</div>

<style>
    .container { max-width: 850px; margin: 4rem auto; padding: 0 2rem; }
    .header { text-align: center; margin-bottom: 4rem; }
    .header h1 { font-size: 2.5rem; margin-bottom: 1rem; background: linear-gradient(135deg, var(--primary-color), #6366f1); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .header p { color: var(--text-muted); font-size: 1.125rem; }
    .faq-item { background: white; border: 1px solid var(--border-color); border-radius: 16px; padding: 2rem; margin-bottom: 1.5rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    .faq-item:hover { transform: translateY(-2px); box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.1); border-color: var(--primary-color); }
    .faq-item h3 { margin-top: 0; margin-bottom: 1.25rem; font-size: 1.25rem; display: flex; gap: 1rem; align-items: center; color: var(--text-main); }
    .faq-item h3::before { content: "Q"; background: var(--primary-light); color: var(--primary-color); width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 0.875rem; font-weight: 800; flex-shrink: 0; }
    .faq-answer { display: flex; gap: 1rem; align-items: flex-start; }
    .faq-answer::before { content: "A"; background: #f0fdf4; color: var(--success-color); width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 0.875rem; font-weight: 800; flex-shrink: 0; margin-top: 0.25rem; }
    .faq-answer > div, .faq-answer > p { margin: 0; color: var(--text-muted); line-height: 1.7; flex: 1; }
    .cta { text-align: center; margin-top: 5rem; padding: 4rem 2rem; background: linear-gradient(135deg, rgba(37, 99, 235, 0.07), rgba(99, 102, 241, 0.1)); border-radius: 24px; border: 1px solid rgba(37, 99, 235, 0.15); }
</style>

<?php include __DIR__ . '/footer.php'; ?>
