<?php
// public/install.php

// 1. Define Paths
define('ROOT_PATH', dirname(__DIR__));
define('LEGACY_CONFIG_PATH', ROOT_PATH . '/config/database.php');
define('SCHEMA_DIR', ROOT_PATH . '/database');
define('SCHEMA_PATH', SCHEMA_DIR . '/DATABASE_SCHEMA.sql');
define('VERSION_PATH', ROOT_PATH . '/config/version.php');

// Load Autoloader for Cryptography
require_once ROOT_PATH . '/vendor/autoload.php';

$installNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; script-src 'self'; style-src 'self' 'nonce-{$installNonce}'; img-src 'self' data:; font-src 'self' data:; object-src 'none'; frame-ancestors 'self';");

use App\Service\Database\SchemaService;

function getInstallVersion(): string
{
    if (file_exists(VERSION_PATH)) {
        $version = require VERSION_PATH;
        if (is_array($version) && !empty($version['version'])) {
            return (string)$version['version'];
        }
    }

    return '0.1';
}

function getExistingInstallWarning(): ?string
{
    if (!file_exists(LEGACY_CONFIG_PATH) || filesize(LEGACY_CONFIG_PATH) <= 0) {
        return null;
    }

    try {
        $config = require LEGACY_CONFIG_PATH;
        $db = $config['database'] ?? null;
        if (!is_array($db)) {
            return 'Installation is unavailable on this server.';
        }

        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s;port=%s",
            $db['host'] ?? 'localhost',
            $db['dbname'] ?? '',
            $db['charset'] ?? 'utf8mb4',
            $db['port'] ?? '3306'
        );

        $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1");
        $schemaVersion = $stmt ? (string)$stmt->fetchColumn() : '';

        if ($schemaVersion === '') {
            return 'Installation is unavailable on this server.';
        }

        return 'Installation is unavailable on this server.';
    } catch (Throwable $e) {
        return 'Installation is unavailable on this server.';
    }
}

// Security Lock: Prevent re-installation if a config file is already linked
if ($existingInstallWarning = getExistingInstallWarning()) {
    http_response_code(403);
    die($existingInstallWarning);
}

session_start();
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['install_csrf'];

$error = '';
$success = '';
$formData = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'config_path' => '/home/username/encryption_info/fyuhls_config.php',
    'admin_user' => 'admin',
    'admin_email' => '',
];

// 2. Requirements Check
$requirements = [
    'php' => ['name' => 'PHP Version', 'version' => '8.2.0', 'current' => PHP_VERSION, 'met' => version_compare(PHP_VERSION, '8.2.0', '>=')],
    'pdo' => ['name' => 'PDO Extension', 'met' => extension_loaded('pdo')],
    'pdo_mysql' => ['name' => 'PDO MySQL Extension', 'met' => extension_loaded('pdo_mysql')],
    'openssl' => ['name' => 'OpenSSL Extension (Encryption)', 'met' => extension_loaded('openssl')],
];

$canInstall = true;
foreach ($requirements as $req) {
    if (!$req['met']) {
        $canInstall = false;
    }
}

// 3. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canInstall) {
    if (!hash_equals($_SESSION['install_csrf'], $_POST['csrf_token'] ?? '')) {
        $error = "CSRF Token Mismatch";
    } else {
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPass = $_POST['db_pass'] ?? '';
        $dbPort = $_POST['db_port'] ?? '3306';
        $adminUser = $_POST['admin_user'] ?? 'admin';
        $adminEmail = $_POST['admin_email'] ?? '';
        $adminPass = $_POST['admin_pass'] ?? '';

        $configPath = $_POST['config_path'] ?? '';

        $formData = [
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'config_path' => $configPath,
            'admin_user' => $adminUser,
            'admin_email' => $adminEmail,
        ];

        if (empty($dbName) || empty($dbUser) || empty($adminEmail) || empty($adminPass) || empty($configPath)) {
            $error = "All fields are required.";
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Admin email address is invalid.";
        } elseif (strlen($adminUser) < 3 || strlen($adminUser) > 30 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $adminUser)) {
            $error = "Admin username must be 3 to 30 characters and may only contain letters, numbers, underscores, dots, and hyphens.";
        } elseif (strlen($adminPass) < 10) {
            $error = "Admin password must be at least 10 characters.";
        } else {
            $configWritten = false;
            $pointerWritten = false;
            $installCompleted = false;

            // Ensure the config directory exists or is writable
            $configDir = dirname($configPath);
            if (!is_dir($configDir) || !is_writable($configDir)) {
                $error = "Warning: The path {$configDir} does not exist or is not writable by the PHP user. Please create the folder and set CHMOD 777 permissions first.";
            } else {
                try {
                    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                    // Generate a true 256-bit cryptographically secure key for AES Database Encryption
                    // base64 encoding 32 raw bytes gives us 44 characters of full 256-bit entropy.
                    $encryptionKey = base64_encode(random_bytes(32));

                    if (!file_exists(SCHEMA_PATH)) {
                        throw new Exception("Schema file not found at " . SCHEMA_PATH);
                    }

                    $sql = file_get_contents(SCHEMA_PATH);
                    $sql = preg_replace('/^--.*$/m', '', $sql);
                    $queries = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($queries as $query) {
                        if (!empty($query)) {
                            $pdo->exec($query);
                        }
                    }

                    $schemaVersion = SchemaService::SCHEMA_VERSION;
                    $settingsStmt = $pdo->prepare("
                        INSERT INTO settings (setting_key, setting_value, setting_group, is_system)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            setting_value = VALUES(setting_value),
                            setting_group = VALUES(setting_group),
                            is_system = VALUES(is_system)
                    ");
                    $settingsStmt->execute(['schema_version', $schemaVersion, 'system', 1]);
                    $settingsStmt->execute(['db_drift_detected', '0', 'system', 1]);
                    $settingsStmt->execute(['db_drift_error', '', 'system', 1]);
                    $settingsStmt->execute(['require_email_verification', '1', 'general', 0]);
                    $settingsStmt->execute(['demo_mode', '0', 'general', 0]);
                    $settingsStmt->execute(['upload_append_filename', '1', 'uploads', 0]);
                    $settingsStmt->execute(['upload_login_required', '1', 'uploads', 0]);

                    $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
                    $publicId = 'u_' . bin2hex(random_bytes(6));

                    \App\Service\EncryptionService::setKey($encryptionKey);
                    $encUser = \App\Service\EncryptionService::encrypt($adminUser);
                    $encEmail = \App\Service\EncryptionService::encrypt($adminEmail);
                    $encLocalStoragePath = \App\Service\EncryptionService::encrypt('storage/uploads');

                    $localServerStmt = $pdo->prepare("UPDATE file_servers SET storage_path = ? WHERE is_default = 1 AND server_type = 'local'");
                    $localServerStmt->execute([$encLocalStoragePath]);

                    $stmt = $pdo->prepare("INSERT INTO users (public_id, username, email, password, role, package_id, status) VALUES (?, ?, ?, ?, 'admin', 4, 'active')");
                    $stmt->execute([$publicId, $encUser, $encEmail, $hashedPass]);

                    $configArray = [
                        'database' => [
                            'host' => $dbHost,
                            'dbname' => $dbName,
                            'username' => $dbUser,
                            'password' => $dbPass,
                            'charset' => 'utf8mb4',
                            'port' => $dbPort,
                        ],
                        'security' => [
                            'encryption_key' => $encryptionKey,
                        ],
                    ];
                    $configContent = "<?php\n\nreturn " . var_export($configArray, true) . ";\n";
                    if (file_put_contents($configPath, $configContent) === false) {
                        throw new Exception("Could not write to the specified config file: $configPath");
                    }
                    $configWritten = true;

                    // Link the absolute path inside the webroot config pointer
                    $relativePointer = "<?php\n// This file safely points the application to your hidden absolute configuration.\nreturn require " . var_export($configPath, true) . ";\n";
                    if (file_put_contents(LEGACY_CONFIG_PATH, $relativePointer) === false) {
                        throw new Exception("Could not write config/database.php");
                    }
                    $pointerWritten = true;

                    $installCompleted = true;

                    $success = "Installation successful! <a href='/post_install_check.php'>Run the post-install self-test</a> or <a href='/'>click here to login</a>.";

                    // Cleanup setup files only after the entire install completed successfully.
                    if ($installCompleted) {
                        $schemaDeleted = true;
                        if (is_dir(SCHEMA_DIR)) {
                            $schemaDeleted = @unlink(SCHEMA_PATH);
                            if ($schemaDeleted) {
                                $schemaDeleted = @rmdir(SCHEMA_DIR);
                            }
                        }
                        $installerDeleted = @unlink(__FILE__);

                        if ($schemaDeleted && $installerDeleted) {
                            $success .= "<br><br><em>Installer and setup schema folder were automatically deleted for security.</em>";
                        } else {
                            $success .= "<br><br><strong class='install-warning-text'>Warning: Could not fully delete setup files. Please remove public/install.php and the database/ folder manually if they still exist.</strong>";
                        }
                    }
                } catch (Exception $e) {
                    if ($pointerWritten && file_exists(LEGACY_CONFIG_PATH)) {
                        @unlink(LEGACY_CONFIG_PATH);
                    }
                    if ($configWritten && file_exists($configPath)) {
                        @unlink($configPath);
                    }
                    error_log("Installer failed: " . $e->getMessage());
                    $error = "Installation failed. Please review your database details, config path, and server permissions, then try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fyuhls Installer</title>
    <style nonce="<?= htmlspecialchars($installNonce, ENT_QUOTES, 'UTF-8') ?>">
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .installer-box { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h1 { margin-top: 0; color: #111827; }
        .group { margin-bottom: 1rem; }
        label { display: block; font-weight: 500; margin-bottom: 0.5rem; color: #374151; }
        input { width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; }
        button { background: #2563eb; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; font-weight: 600; cursor: pointer; width: 100%; }
        button:hover { background: #1d4ed8; }
        button:disabled { background: #9ca3af; cursor: not-allowed; }
        .error { color: #dc2626; background: #fee2e2; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
        .success { color: #059669; background: #d1fae5; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
        .req-list { list-style: none; padding: 0; margin-bottom: 1.5rem; }
        .req-item { display: flex; justify-content: space-between; padding: 0.25rem 0; border-bottom: 1px solid #f3f4f6; }
        .met { color: #059669; }
        .not-met { color: #dc2626; font-weight: bold; }
        .install-version { margin-top: -0.5rem; color: #6b7280; font-size: 0.95rem; }
        .install-config-note { font-size: 0.85rem; color: #4B5563; margin-top: 0; margin-bottom: 0.5rem; }
        .install-warning-text { color: red; }
    </style>
</head>
<body>
<div class="installer-box">
    <h1>Install System</h1>
    <p class="install-version">Version <?= htmlspecialchars(getInstallVersion()) ?></p>
    <?php if ($error): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?= $success ?></div>
    <?php else: ?>
        <div class="requirements">
            <h3>System Requirements</h3>
            <ul class="req-list">
                <?php foreach ($requirements as $key => $req): ?>
                    <li class="req-item">
                        <span><?= htmlspecialchars($req['name'] ?? ucfirst($key)) ?> <?= isset($req['current']) ? "({$req['current']})" : '' ?></span>
                        <span class="<?= $req['met'] ? 'met' : 'not-met' ?>"><?= $req['met'] ? '&#10004; OK' : '&#10008; FAIL' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php if ($canInstall): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <h3>Database Connection</h3>
                <div class="group"><label>Database Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($formData['db_host']) ?>" required></div>
                <div class="group"><label>Database Port</label><input type="text" name="db_port" value="<?= htmlspecialchars($formData['db_port']) ?>" required></div>
                <div class="group"><label>Database Name</label><input type="text" name="db_name" placeholder="filehosting" value="<?= htmlspecialchars($formData['db_name']) ?>" required></div>
                <div class="group"><label>Database User</label><input type="text" name="db_user" placeholder="root" value="<?= htmlspecialchars($formData['db_user']) ?>" required></div>
                <div class="group"><label>Database Password</label><input type="password" name="db_pass"></div>
                <h3>Security & Configuration</h3>
                <div class="group">
                    <label>Absolute Config Path (Highly Recommended)</label>
                    <p class="install-config-note">For maximum security, enter a server path completely outside of your public web directory. We will store your database credentials and a 256-bit Database Encryption key here.</p>
                    <input type="text" name="config_path" value="<?= htmlspecialchars($formData['config_path']) ?>" required>
                </div>
                <h3>Admin Account</h3>
                <div class="group"><label>Admin Username</label><input type="text" name="admin_user" placeholder="admin" value="<?= htmlspecialchars($formData['admin_user']) ?>" required></div>
                <div class="group"><label>Admin Email</label><input type="email" name="admin_email" placeholder="admin@example.com" value="<?= htmlspecialchars($formData['admin_email']) ?>" required></div>
                <div class="group"><label>Admin Password</label><input type="password" name="admin_pass" required></div>
                <button type="submit">Install Now</button>
            </form>
        <?php else: ?>
            <div class="error">Please fix the system requirements above to continue.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
