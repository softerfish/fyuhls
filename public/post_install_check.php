<?php

define('BASE_PATH', realpath(__DIR__ . '/..'));

require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Model\Setting;
use App\Service\Database\SchemaService;

Config::load(BASE_PATH . '/config/app.php');

$configReady = file_exists(BASE_PATH . '/config/database.php');
$dbConnected = false;
$schemaVersion = '';
$schemaMatches = false;
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

if ($configReady) {
    try {
        Config::load(BASE_PATH . '/config/database.php');
        $db = Database::getInstance()->getConnection();
        $dbConnected = $db !== null;
        if ($dbConnected) {
            $schemaVersion = (string)Setting::get('schema_version', '');
            $schemaMatches = $schemaVersion === SchemaService::SCHEMA_VERSION;
            $smtpConfigured = trim(Setting::get('email_smtp_host', '')) !== '' && trim(Setting::get('email_from_address', '')) !== '';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$checks = [
    'Config pointer present' => $configReady,
    'Database connection' => $dbConnected,
    'Schema version matches' => $schemaMatches,
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
    <style>
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
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1 style="margin-top: 0;">Post-Install Self-Test</h1>
            <p class="muted">Version <?= htmlspecialchars($appVersion) ?>. Use this page right after installation to confirm the core environment is ready.</p>

            <?php if ($error): ?>
                <div class="alert">Database check failed: <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <table>
                <tbody>
                <?php foreach ($checks as $label => $passed): ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td style="text-align: right;" class="<?= $passed ? 'ok' : 'fail' ?>"><?= $passed ? 'PASS' : 'FAIL' ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <td>Schema version</td>
                        <td style="text-align: right;"><?= htmlspecialchars($schemaVersion !== '' ? $schemaVersion : '(missing)') ?> / expected <?= htmlspecialchars(SchemaService::SCHEMA_VERSION) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="actions">
                <a class="btn btn-primary" href="/">Go to Site</a>
                <a class="btn btn-secondary" href="/admin">Open Admin</a>
            </div>
        </div>
    </div>
</body>
</html>
