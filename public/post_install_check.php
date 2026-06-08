<?php

define('BASE_PATH', realpath(__DIR__ . '/..'));

require_once BASE_PATH . '/vendor/autoload.php';
App\Core\Config::load(BASE_PATH . '/config/app.php');

function postInstallRequestIsHttps(): bool
{
    return \App\Service\SecurityService::isHttpsRequest();
}

function postInstallRequestHostIsLocal(): bool
{
    return \App\Service\SecurityService::isLocalDevelopmentRequest();
}

if (!postInstallRequestIsHttps() && !postInstallRequestHostIsLocal()) {
    $redirectLocation = \App\Service\SecurityService::buildHttpsBootstrapRedirectLocation('/post_install_check.php');
    if ($redirectLocation === null) {
        http_response_code(400);
        exit('Invalid host header.');
    }

    header('Location: ' . $redirectLocation, true, 301);
    exit;
}

$postInstallNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; script-src 'self'; style-src 'self' 'nonce-{$postInstallNonce}'; img-src 'self' data:; font-src 'self' data:; object-src 'none'; frame-ancestors 'self';");

use App\Core\Config;
use App\Core\Database;
use App\Core\Auth;
use App\Model\Setting;
use App\Service\ConfigPointerService;
use App\Service\Database\SchemaService;
use App\Service\InstallSecurityService;

$configReady = file_exists(BASE_PATH . '/config/database.php');

if (!$configReady) {
    http_response_code(403);
    exit('This post-install page is only available after setup is complete.');
}

try {
    Config::loadArray(ConfigPointerService::loadLinkedConfigArray(BASE_PATH . '/config/database.php'));
} catch (RuntimeException $e) {
    http_response_code(503);
    exit('The hidden config file referenced by config/database.php is missing or unreadable. Restore the hidden config file before using the post-install checker.');
}
$encryptionKey = (string)Config::get('security.encryption_key', '');
if ($encryptionKey !== '') {
    \App\Service\EncryptionService::setKey($encryptionKey);
}

if (session_status() === PHP_SESSION_NONE) {
    try {
        InstallSecurityService::prepareSessionStoragePath(BASE_PATH);

        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if (postInstallRequestIsHttps()) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
    } catch (RuntimeException $e) {
        http_response_code(500);
        exit('Session storage is unavailable for the post-install checker. Fix the PHP session path or make storage/sessions writable before continuing.');
    }
}

if (!InstallSecurityService::canAccessPostInstallCheck(
    Auth::isSuperAdmin(),
    Auth::hasCapability('configuration.manage')
)) {
    http_response_code(403);
    exit('This post-install page is only available to Super Admin or Configuration staff after setup.');
}

$dbConnected = false;
$schemaVersion = '';
$schemaMatches = false;
$schemaDriftDetected = false;
$storageWritable = is_dir(BASE_PATH . '/storage/uploads') && is_writable(BASE_PATH . '/storage/uploads');
$gdAvailable = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');
$smtpConfigured = false;
$error = null;
$appVersion = '0.1';

if (file_exists(BASE_PATH . '/config/version.php')) {
    $version = require BASE_PATH . '/config/version.php';
    if (is_array($version) && !empty($version['version'])) {
        $appVersion = (string)$version['version'];
    }
}

try {
    $db = Database::getInstance()->getConnection();
    $dbConnected = $db !== null;
    if ($dbConnected) {
        $schemaVersion = (string)Setting::get('schema_version', '');
        $schemaDriftDetected = Setting::get('db_drift_detected', '0') === '1';
        $schemaMatches = $schemaVersion === SchemaService::SCHEMA_VERSION && !$schemaDriftDetected;
        $smtpConfigured = trim(Setting::get('email_smtp_host', '')) !== '' && trim(Setting::get('email_from_address', '')) !== '';
    } else {
        $error = 'Database connection failed. Review the hidden config database credentials and server availability before continuing.';
    }
} catch (Throwable $e) {
    $error = 'Database check failed. Review the database connection and schema from the admin area.';
}

$checks = [
    'Config pointer present' => $configReady,
    'Database connection' => $dbConnected,
    'Schema structurally current' => $schemaMatches,
    'Storage writable' => $storageWritable,
    'GD available' => $gdAvailable,
    'SMTP configured' => $smtpConfigured,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fyuhls Post-Install Check</title>
    <style nonce="<?= htmlspecialchars($postInstallNonce, ENT_QUOTES, 'UTF-8') ?>">
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 2rem; }
        .wrap { max-width: 760px; margin: 0 auto; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08); padding: 1.5rem; }
        .muted { color: #6b7280; }
        .ok { color: #047857; font-weight: 600; }
        .fail { color: #b91c1c; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        td { padding: 0.8rem 0; border-bottom: 1px solid #e5e7eb; }
        .actions { margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 0.75rem 1rem; border-radius: 8px; text-decoration: none; font-weight: 600; }
        .btn-primary { background: #111827; color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .alert { margin-top: 1rem; padding: 1rem; border-radius: 10px; background: #fef2f2; color: #991b1b; }
        code { background: #f3f4f6; padding: 0.15rem 0.35rem; border-radius: 4px; }
        .post-install-title { margin-top: 0; }
        .post-install-align-right { text-align: right; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1 class="post-install-title">Post-Install Self-Test</h1>
            <p class="muted">Version <?= htmlspecialchars($appVersion) ?>. Use this page right after installation to confirm the core environment is ready.</p>

            <?php if ($error): ?>
                <div class="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($checks)): ?>
                <table>
                    <tbody>
                    <?php foreach ($checks as $label => $passed): ?>
                        <tr>
                            <td><?= htmlspecialchars($label) ?></td>
                            <td class="post-install-align-right <?= $passed ? 'ok' : 'fail' ?>"><?= $passed ? 'PASS' : 'FAIL' ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <td>Schema version</td>
                            <td class="post-install-align-right"><?= htmlspecialchars($schemaVersion !== '' ? $schemaVersion : '(missing)') ?> / expected <?= htmlspecialchars(SchemaService::SCHEMA_VERSION) ?></td>
                        </tr>
                        <tr>
                            <td>Schema drift flag</td>
                            <td class="post-install-align-right <?= $schemaDriftDetected ? 'fail' : 'ok' ?>"><?= $schemaDriftDetected ? 'DRIFT DETECTED' : 'CLEAR' ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="actions">
                <a class="btn btn-primary" href="/">Go to Site</a>
                <a class="btn btn-secondary" href="/admin">Open Admin</a>
            </div>
        </div>
    </div>
</body>
</html>
