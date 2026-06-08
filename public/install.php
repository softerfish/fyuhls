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
\App\Core\Config::load(ROOT_PATH . '/config/app.php');

function installRequestIsHttps(): bool
{
    return \App\Service\SecurityService::isHttpsRequest();
}

function installRequestHostIsLocal(): bool
{
    return \App\Service\SecurityService::isLocalDevelopmentRequest();
}

if (!installRequestIsHttps() && !installRequestHostIsLocal()) {
    $redirectLocation = \App\Service\SecurityService::buildHttpsBootstrapRedirectLocation('/install.php');
    if ($redirectLocation === null) {
        http_response_code(400);
        exit('Invalid host header.');
    }

    header('Location: ' . $redirectLocation, true, 301);
    exit;
}

$installNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; script-src 'self' 'nonce-{$installNonce}'; style-src 'self' 'nonce-{$installNonce}'; img-src 'self' data:; font-src 'self' data:; object-src 'none'; frame-ancestors 'self';");

use App\Service\Database\SchemaService;
use App\Service\DiagnosticsService;
use App\Service\InstallSecurityService;
use App\Service\ConfigPointerService;

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

function isPathAbsolute(string $path): bool
{
    return InstallSecurityService::isAbsolutePath($path);
}

function normalizeInstallPath(string $path): string
{
    return InstallSecurityService::normalizeAbsolutePath($path);
}

function pathStartsWithBase(string $candidate, string $base): bool
{
    return InstallSecurityService::pathStartsWithBase($candidate, $base);
}

function validateHiddenConfigPath(string $path): string
{
    return InstallSecurityService::validateHiddenConfigPath($path, ROOT_PATH);
}

function defaultHiddenConfigPath(): string
{
    return InstallSecurityService::defaultHiddenConfigPathForProject(ROOT_PATH);
}

function resolveHiddenConfigPath(?string $requestedPath = null): string
{
    $candidate = trim((string)$requestedPath);
    if ($candidate === '') {
        $candidate = InstallSecurityService::nextAvailableHiddenConfigPath(ROOT_PATH);
    }

    return validateHiddenConfigPath($candidate);
}

function getExistingInstallWarning(): ?string
{
    if (\App\Service\InstallSecurityService::hasInstalledMarker(ROOT_PATH)) {
        return 'Installation is unavailable on this server.';
    }

    if (!file_exists(LEGACY_CONFIG_PATH) || filesize(LEGACY_CONFIG_PATH) <= 0) {
        return null;
    }

    try {
        $config = ConfigPointerService::loadLinkedConfigArray(LEGACY_CONFIG_PATH);
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
    } catch (RuntimeException $e) {
        return null;
    } catch (Throwable $e) {
        return 'Installation is unavailable on this server.';
    }
}

function listDatabaseTables(PDO $pdo): array
{
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) ?: [];
    return array_map(static fn(array $row): string => (string)($row[0] ?? ''), $rows);
}

function fyuhlsSchemaTableNames(): array
{
    return array_keys(SchemaService::getMasterSchema([], true));
}

function detectExistingFyuhlsTables(PDO $pdo): array
{
    $currentTables = array_filter(listDatabaseTables($pdo));
    if ($currentTables === []) {
        return [];
    }

    return array_values(array_intersect($currentTables, fyuhlsSchemaTableNames()));
}

function cleanupPartialInstall(PDO $pdo, array $tablesBeforeInstall): void
{
    $currentTables = array_filter(listDatabaseTables($pdo));
    if ($currentTables === []) {
        return;
    }

    $knownTables = fyuhlsSchemaTableNames();
    $tablesToDrop = array_values(array_intersect(
        array_diff($currentTables, $tablesBeforeInstall),
        $knownTables
    ));

    if ($tablesToDrop === []) {
        return;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (array_reverse($tablesToDrop) as $tableName) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $tableName) . '`');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function installerNormalizeSmtpPort(string $smtpPort): int
{
    try {
        return \App\Service\MailHostSafetyService::normalizeSmtpPort($smtpPort);
    } catch (RuntimeException $e) {
        throw new InvalidArgumentException($e->getMessage(), 0, $e);
    }
}

function installerNormalizeSmtpHost(string $host): string
{
    try {
        return \App\Service\MailHostSafetyService::normalizeSmtpHost($host);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        if ($message === 'SMTP host is required.') {
            $message = 'SMTP host is required for the installer email test.';
        }

        throw new InvalidArgumentException($message, 0, $e);
    }
}

function installerNormalizeSmtpSecureMethod(string $secureMethod): string
{
    $secureMethod = strtolower(trim($secureMethod));
    if (!in_array($secureMethod, ['none', 'ssl', 'tls'], true)) {
        throw new InvalidArgumentException('Encryption must be one of None, SSL, or TLS.');
    }

    return $secureMethod;
}

function installerBuildMailServiceFromPost(): \App\Service\MailService
{
    $smtpHost = installerNormalizeSmtpHost((string)($_POST['email_smtp_host'] ?? ''));
    $fromAddress = trim((string)($_POST['email_from_address'] ?? ''));
    $smtpPort = trim((string)($_POST['email_smtp_port'] ?? '465'));
    $secureMethod = installerNormalizeSmtpSecureMethod((string)($_POST['email_secure_method'] ?? 'ssl'));
    $smtpRequiresAuth = isset($_POST['email_smtp_requires_auth']);
    $smtpUser = trim((string)($_POST['email_smtp_auth_username'] ?? ''));
    $smtpPass = (string)($_POST['email_smtp_auth_password'] ?? '');

    if ($fromAddress === '') {
        throw new InvalidArgumentException('From address is required for the installer email test.');
    }
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('From address is invalid.');
    }
    if ($smtpRequiresAuth && $smtpUser === '') {
        throw new InvalidArgumentException('SMTP username is required when authentication is enabled.');
    }

    return new \App\Service\MailService(
        $smtpHost,
        installerNormalizeSmtpPort($smtpPort),
        $fromAddress,
        $secureMethod,
        $smtpRequiresAuth,
        $smtpUser,
        $smtpPass
    );
}

// Security Lock: Prevent re-installation if a config file is already linked
if ($existingInstallWarning = getExistingInstallWarning()) {
    http_response_code(403);
    die($existingInstallWarning);
}

try {
    InstallSecurityService::prepareSessionStoragePath(ROOT_PATH);
    InstallSecurityService::applySecureSessionCookieSettings(installRequestIsHttps());
    session_start();
} catch (RuntimeException $e) {
    http_response_code(500);
    exit('Installer session storage is unavailable. Fix the PHP session path or make storage/sessions writable before continuing.');
}
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['install_csrf'];

$error = '';
$success = '';
$submittedConfigPath = '';
$formData = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'config_path' => '',
    'admin_user' => 'admin',
    'admin_email' => '',
    'admin_pass' => '',
    'email_smtp_host' => '',
    'email_smtp_port' => '465',
    'email_from_address' => '',
    'email_secure_method' => 'ssl',
    'email_smtp_auth_username' => '',
    'email_smtp_auth_password' => '',
    'email_limit_per_minute' => '20',
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

$diagnosticsService = new DiagnosticsService();
$submittedConfigPath = trim((string)($_POST['config_path'] ?? ''));
$installerPathReport = $diagnosticsService->getInstallerPathChecks(
    $submittedConfigPath !== '' ? $submittedConfigPath : null,
    $submittedConfigPath !== ''
);
$installerBlockingIssues = (int)($installerPathReport['blocking_issues'] ?? 0);
$installerPathChecksPass = $installerBlockingIssues === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['install_action'] ?? '') === 'test_email_connection') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals($_SESSION['install_csrf'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF token mismatch. Refresh the installer and try again.']);
        exit;
    }

    try {
        installerBuildMailServiceFromPost()->testConnection();
        echo json_encode(['status' => 'success', 'message' => 'SMTP connection successful.']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 3. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canInstall && $installerPathChecksPass) {
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
        $configPath = $_POST['config_path'] ?? defaultHiddenConfigPath();
        $smtpHost = trim((string)($_POST['email_smtp_host'] ?? ''));
        $smtpPort = trim((string)($_POST['email_smtp_port'] ?? '465'));
        $fromAddress = trim((string)($_POST['email_from_address'] ?? ''));
        $secureMethod = strtolower(trim((string)($_POST['email_secure_method'] ?? 'ssl')));
        $smtpRequiresAuth = isset($_POST['email_smtp_requires_auth']);
        $smtpUser = trim((string)($_POST['email_smtp_auth_username'] ?? ''));
        $smtpPass = (string)($_POST['email_smtp_auth_password'] ?? '');
        $emailLimitPerMinute = trim((string)($_POST['email_limit_per_minute'] ?? '20'));

        $formData = [
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass' => $dbPass,
            'config_path' => '',
            'admin_user' => $adminUser,
            'admin_email' => $adminEmail,
            'admin_pass' => $adminPass,
            'email_smtp_host' => $smtpHost,
            'email_smtp_port' => $smtpPort,
            'email_from_address' => $fromAddress,
            'email_secure_method' => $secureMethod,
            'email_smtp_auth_username' => $smtpUser,
            'email_smtp_auth_password' => $smtpPass,
            'email_limit_per_minute' => $emailLimitPerMinute,
        ];

        $emailSetupRequested = $smtpHost !== ''
            || $fromAddress !== ''
            || $smtpUser !== ''
            || $smtpPass !== ''
            || $smtpRequiresAuth;

        if (empty($dbName) || empty($dbUser) || empty($adminEmail) || empty($adminPass)) {
            $error = "All fields are required.";
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Admin email address is invalid.";
        } elseif (strlen($adminUser) < 3 || strlen($adminUser) > 30 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $adminUser)) {
            $error = "Admin username must be 3 to 30 characters and may only contain letters, numbers, underscores, dots, and hyphens.";
        } elseif (strlen($adminPass) < 10) {
            $error = "Admin password must be at least 10 characters.";
        } elseif ($emailSetupRequested && ($smtpHost === '' || $fromAddress === '')) {
            $error = "If you configure email during install, SMTP host and from address are required.";
        } elseif ($emailSetupRequested && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $error = "Installer email setup requires a valid from address.";
        } elseif ($emailSetupRequested) {
            try {
                $smtpHost = installerNormalizeSmtpHost($smtpHost);
                $formData['email_smtp_host'] = $smtpHost;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        if ($error === '') {
            if ($emailSetupRequested) {
                try {
                    $smtpPort = (string)installerNormalizeSmtpPort($smtpPort);
                    $formData['email_smtp_port'] = $smtpPort;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            if ($error === '' && !in_array($secureMethod, ['none', 'ssl', 'tls'], true)) {
                $error = "Installer email setup requires a valid encryption method.";
            } elseif ($error === '' && $emailSetupRequested && $smtpRequiresAuth && $smtpUser === '') {
                $error = "Installer email setup requires an SMTP username when authentication is enabled.";
            } elseif ($error === '' && (!ctype_digit($emailLimitPerMinute) || (int)$emailLimitPerMinute < 1)) {
                $error = "Installer email setup requires a send-rate limit of at least 1 email per minute.";
            }
        }

        if ($error === '') {
            $configWritten = false;
            $pointerWritten = false;
            $installCompleted = false;
            $configCreatedByInstaller = false;
            $tablesBeforeInstall = [];
            $installReservation = null;

            try {
                $installReservation = InstallSecurityService::acquireInstallReservation(ROOT_PATH);
                if ($existingInstallWarning = getExistingInstallWarning()) {
                    throw new Exception($existingInstallWarning);
                }
                $configPath = resolveHiddenConfigPath($configPath);
                $formData['config_path'] = '';
                $configDir = dirname($configPath);
                if (!is_dir($configDir) && !mkdir($configDir, 0700, true) && !is_dir($configDir)) {
                    $error = "Warning: The secure config directory could not be created by the PHP user. Please create it manually and grant write access temporarily.";
                } elseif (!is_writable($configDir)) {
                    $error = "Warning: The secure config directory is not writable by the PHP user. Please update its permissions temporarily to complete installation.";
                } elseif (file_exists($configPath)) {
                    $error = "Warning: The hidden config file already exists. Restore the existing install or choose a new empty hidden config path before continuing.";
                } elseif (file_exists(LEGACY_CONFIG_PATH) && !is_writable(LEGACY_CONFIG_PATH)) {
                    $error = "Warning: config/database.php is not writable by the PHP user. Please update its permissions temporarily to complete installation.";
                } else {
                    $configCreatedByInstaller = !file_exists($configPath);
                    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $tablesBeforeInstall = listDatabaseTables($pdo);
                    $existingFyuhlsTables = detectExistingFyuhlsTables($pdo);
                    if ($existingFyuhlsTables !== []) {
                        throw new Exception(
                            'This database already contains Fyuhls tables (' . implode(', ', array_slice($existingFyuhlsTables, 0, 8)) . '). Restore the config pointer or use recovery instead of running the installer again.'
                        );
                    }

                    // Generate a true 256-bit cryptographically secure key for AES Database Encryption
                    // base64 encoding 32 raw bytes gives us 44 characters of full 256-bit entropy.
                    $encryptionKey = base64_encode(random_bytes(32));
                    $appKey = bin2hex(random_bytes(16));

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

                    // Run the PHP schema sync after the base SQL import so fresh installs
                    // also pick up any runtime drift repairs and index-shape corrections.
                    $schemaService = new SchemaService($pdo);
                    $schemaSync = SchemaService::withRepairWindow(static fn() => $schemaService->sync(true));
                    if (empty($schemaSync['success'])) {
                        throw new Exception('Schema sync failed during install: ' . (string)($schemaSync['error'] ?? 'Unknown schema sync error.'));
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
                    $detectedCanonicalBaseUrl = rtrim(\App\Service\SeoService::detectInstallBaseUrl(), '/');
                    if ($detectedCanonicalBaseUrl !== '' && !preg_match('#^https?://(?:localhost|127\\.0\\.0\\.1|\\[?::1\\]?)(?:[:/]|$)#i', $detectedCanonicalBaseUrl)) {
                        $settingsStmt->execute(['seo_canonical_base_url', $detectedCanonicalBaseUrl, 'seo', 0]);
                    }
                    if ($emailSetupRequested) {
                        $settingsStmt->execute(['email_smtp_host', $smtpHost, 'email', 0]);
                        $settingsStmt->execute(['email_smtp_port', (string)((int)$smtpPort), 'email', 0]);
                        $settingsStmt->execute(['email_from_address', $fromAddress, 'email', 0]);
                        $settingsStmt->execute(['email_secure_method', $secureMethod, 'email', 0]);
                        $settingsStmt->execute(['email_smtp_requires_auth', $smtpRequiresAuth ? '1' : '0', 'email', 0]);
                        $settingsStmt->execute(['email_smtp_auth_username', $smtpUser, 'email', 0]);
                        $settingsStmt->execute(['email_limit_per_minute', (string)((int)$emailLimitPerMinute), 'email', 0]);
                    }

                    $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
                    $publicId = 'u_' . bin2hex(random_bytes(6));

                    \App\Service\EncryptionService::setKey($encryptionKey);
                    if ($emailSetupRequested && $smtpPass !== '') {
                        $settingsStmt->execute([
                            'email_smtp_auth_password',
                            \App\Service\EncryptionService::encrypt($smtpPass),
                            'email',
                            0,
                        ]);
                    }
                    $encUser = \App\Service\EncryptionService::encrypt($adminUser);
                    $encEmail = \App\Service\EncryptionService::encrypt($adminEmail);
                    $adminUsernameLookup = \App\Model\User::credentialLookupHash($adminUser);
                    $adminEmailLookup = \App\Model\User::credentialLookupHash($adminEmail);
                    $encLocalStoragePath = \App\Service\EncryptionService::encrypt('storage/uploads');

                    $localServerStmt = $pdo->prepare("UPDATE file_servers SET storage_path = ? WHERE is_default = 1 AND server_type = 'local'");
                    $localServerStmt->execute([$encLocalStoragePath]);

                    $stmt = $pdo->prepare("INSERT INTO users (public_id, username, email, username_lookup, email_lookup, password, role, is_super_admin, package_id, status, email_verified) VALUES (?, ?, ?, ?, ?, ?, 'admin', 1, 4, 'active', 1)");
                    $stmt->execute([$publicId, $encUser, $encEmail, $adminUsernameLookup, $adminEmailLookup, $hashedPass]);
                    $newAdminUserId = (int)$pdo->lastInsertId();
                    if ($newAdminUserId <= 0) {
                        throw new Exception('The initial Super Admin account could not be created safely.');
                    }

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
                        'app_key' => $appKey,
                    ];
                    if ($detectedCanonicalBaseUrl !== '' && !preg_match('#^https?://(?:localhost|127\\.0\\.0\\.1|\\[?::1\\]?)(?:[:/]|$)#i', $detectedCanonicalBaseUrl)) {
                        $configArray['base_url'] = $detectedCanonicalBaseUrl;
                        $detectedInstallHost = (string)(parse_url($detectedCanonicalBaseUrl, PHP_URL_HOST) ?? '');
                        if ($detectedInstallHost !== '') {
                            $configArray['security']['allowed_hosts'] = [$detectedInstallHost];
                        }
                    }
                    $configContent = "<?php\n\n// FYUHLS_HIDDEN_CONFIG\nreturn " . var_export($configArray, true) . ";\n";
                    \App\Service\InstallSecurityService::writeNewHiddenConfigAtomically($configPath, $configContent);
                    $configWritten = true;

                    // Link the absolute path inside the webroot config pointer
                    $relativePointer = "<?php\n// This file safely points the application to your hidden absolute configuration.\nreturn require " . var_export($configPath, true) . ";\n";
                    if (file_put_contents(LEGACY_CONFIG_PATH, $relativePointer) === false) {
                        throw new Exception("Could not write config/database.php");
                    }
                    $pointerWritten = true;
                    \App\Service\InstallSecurityService::writeInstalledMarker(ROOT_PATH);
                    \App\Core\Auth::login($newAdminUserId, 'admin');
                    unset($_SESSION['install_csrf']);

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
                        clearstatcache();

                        $remainingCleanupTargets = [];
                        if (file_exists(SCHEMA_PATH) || is_dir(SCHEMA_DIR)) {
                            $remainingCleanupTargets[] = 'the database/ setup folder';
                        }
                        if (file_exists(__FILE__)) {
                            $remainingCleanupTargets[] = 'public/install.php';
                        }

                        if ($remainingCleanupTargets === []) {
                            $success .= "<br><br><em>Installer and setup schema folder were automatically deleted for security.</em>";
                        } else {
                            $success .= "<br><br><strong class='install-warning-text'>Post-install cleanup was only partially completed. Please remove " . htmlspecialchars(implode(' and ', $remainingCleanupTargets), ENT_QUOTES, 'UTF-8') . " manually if they are still present.</strong>";
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($pointerWritten && file_exists(LEGACY_CONFIG_PATH)) {
                    @unlink(LEGACY_CONFIG_PATH);
                }
                if ($configWritten && $configCreatedByInstaller && file_exists($configPath)) {
                    @unlink($configPath);
                }
                if (isset($pdo) && $pdo instanceof PDO && !$installCompleted) {
                    try {
                        cleanupPartialInstall($pdo, $tablesBeforeInstall);
                    } catch (Throwable $cleanupError) {
                        error_log("Installer cleanup failed: " . $cleanupError->getMessage());
                    }
                }
                error_log("Installer failed: " . $e->getMessage());
                $error = "Installation failed. See the server error log for details.";
            } finally {
                InstallSecurityService::releaseInstallReservation($installReservation);
            }
        }
    }
}

if ($error === '' && $canInstall && !$installerPathChecksPass) {
    $error = 'Fix the install readiness items below before continuing. The installer found one or more filesystem permission problems that would break setup.';
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
        input, select { width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; background: #fff; }
        button { background: #2563eb; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; font-weight: 600; cursor: pointer; width: 100%; }
        button:hover { background: #1d4ed8; }
        button:disabled { background: #9ca3af; cursor: not-allowed; }
        .error { color: #dc2626; background: #fee2e2; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
        .success { color: #059669; background: #d1fae5; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
        .warning { color: #92400e; background: #fef3c7; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
        .req-list { list-style: none; padding: 0; margin-bottom: 1.5rem; }
        .req-item { display: flex; justify-content: space-between; padding: 0.25rem 0; border-bottom: 1px solid #f3f4f6; }
        .met { color: #059669; }
        .not-met { color: #dc2626; font-weight: bold; }
        .install-version { margin-top: -0.5rem; color: #6b7280; font-size: 0.95rem; }
        .install-config-note { font-size: 0.85rem; color: #4B5563; margin-top: 0; margin-bottom: 0.5rem; }
        .install-warning-text { color: red; }
        .install-check-table { width: 100%; border-collapse: collapse; margin: 0.75rem 0 1.5rem; }
        .install-check-table td { padding: 0.65rem 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .install-check-label { font-weight: 600; color: #111827; }
        .install-check-note { color: #6b7280; font-size: 0.84rem; line-height: 1.4; margin-top: 0.2rem; }
        .install-check-path { color: #6b7280; font-size: 0.78rem; word-break: break-all; margin-top: 0.25rem; }
        .install-check-state { text-align: right; white-space: nowrap; font-weight: 700; }
        .install-check-state--ok { color: #059669; }
        .install-check-state--warning { color: #b45309; }
        .install-check-state--error { color: #dc2626; }
        .install-section-note { font-size: 0.9rem; color: #4b5563; margin: -0.4rem 0 1rem; line-height: 1.5; }
        .install-inline-check { display: flex; align-items: center; gap: 0.6rem; }
        .install-inline-check input { width: auto; }
        .install-promo { margin: 1rem 0 1.5rem; padding: 1rem 1.1rem; border: 1px solid #bfdbfe; border-radius: 8px; background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 100%); }
        .install-promo-eyebrow { margin-bottom: 0.35rem; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #1d4ed8; }
        .install-promo-title { margin: 0 0 0.35rem; font-size: 1rem; color: #111827; }
        .install-promo-copy { margin: 0; font-size: 0.9rem; color: #334155; line-height: 1.55; }
        .install-promo a { color: #1d4ed8; text-decoration: none; }
        .install-promo a:hover { text-decoration: underline; }
        .install-button-row { display: flex; flex-direction: column; gap: 0.75rem; }
        .install-secondary-button { background: #111827; }
        .install-secondary-button:hover { background: #1f2937; }
        .install-email-test-result { display: none; padding: 0.75rem; border-radius: 6px; font-size: 0.92rem; line-height: 1.45; }
        .install-email-test-result--success { display: block; color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
        .install-email-test-result--error { display: block; color: #b91c1c; background: #fee2e2; border: 1px solid #fecaca; }
        .install-progress { display: none; padding: 0.85rem 0.95rem; border-radius: 6px; color: #1e3a8a; background: #dbeafe; border: 1px solid #bfdbfe; font-size: 0.92rem; line-height: 1.45; }
        .install-progress.is-active { display: flex; gap: 0.65rem; align-items: flex-start; }
        .install-progress-spinner { width: 1rem; height: 1rem; margin-top: 0.1rem; border: 2px solid #93c5fd; border-top-color: #1d4ed8; border-radius: 999px; animation: install-spin 0.8s linear infinite; flex: 0 0 auto; }
        @keyframes install-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="installer-box">
    <h1>Install System</h1>
    <p class="install-version">Version <?= htmlspecialchars(getInstallVersion()) ?></p>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
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
        <div class="requirements">
            <h3>Install Readiness</h3>
            <p class="install-config-note">This checks the folders and files the installer needs before it touches your database. If you leave the hidden config path blank, Fyuhls will use the default safe location outside the webroot or automatically step to the next safe unused filename if that default name is already taken.</p>
            <?php if (($installerPathReport['warnings'] ?? 0) > 0 && $installerBlockingIssues === 0): ?>
                <div class="warning">The installer can continue, but a few paths will need to be created during setup. That is fine as long as PHP can write to their parent folders.</div>
            <?php endif; ?>
            <table class="install-check-table">
                <tbody>
                <?php foreach (($installerPathReport['checks'] ?? []) as $check): ?>
                    <tr>
                        <td>
                            <div class="install-check-label"><?= htmlspecialchars((string)($check['label'] ?? 'Check')) ?></div>
                            <div class="install-check-note"><?= htmlspecialchars((string)($check['message'] ?? '')) ?></div>
                        </td>
                        <td class="install-check-state install-check-state--<?= htmlspecialchars((string)($check['status'] ?? 'ok')) ?>">
                            <?php
                            $status = (string)($check['status'] ?? 'ok');
                            echo $status === 'ok' ? 'OK' : ($status === 'warning' ? 'CHECK' : 'FIX');
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canInstall): ?>
            <form method="POST" id="installerForm">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <h3>Database Connection</h3>
                <div class="group"><label>Database Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($formData['db_host']) ?>" required></div>
                <div class="group"><label>Database Port</label><input type="text" name="db_port" value="<?= htmlspecialchars($formData['db_port']) ?>" required></div>
                <div class="group"><label>Database Name</label><input type="text" name="db_name" placeholder="filehosting" value="<?= htmlspecialchars($formData['db_name']) ?>" required></div>
                <div class="group"><label>Database User</label><input type="text" name="db_user" placeholder="root" value="<?= htmlspecialchars($formData['db_user']) ?>" required></div>
                <div class="group"><label>Database Password</label><input type="password" name="db_pass" value="<?= htmlspecialchars($formData['db_pass']) ?>"></div>
                <h3>Security & Configuration</h3>
                <div class="group">
                    <label>Hidden Config Path</label>
                    <p class="install-config-note">Choose an absolute path outside the Fyuhls webroot and config directories, or leave this blank to let Fyuhls choose a safe default outside the webroot. If the default filename already exists, Fyuhls will automatically move to the next safe unused filename. This file stores your database credentials, database encryption key, and application key.</p>
                    <input type="text" name="config_path" value="<?= htmlspecialchars($formData['config_path']) ?>" placeholder="/outside-webroot/fyuhls_secure/fyuhls_config.php">
                </div>
                <h3>Admin Account</h3>
                <div class="group"><label>Admin Username</label><input type="text" name="admin_user" placeholder="admin" value="<?= htmlspecialchars($formData['admin_user']) ?>" required></div>
                <div class="group"><label>Admin Email</label><input type="email" name="admin_email" placeholder="admin@example.com" value="<?= htmlspecialchars($formData['admin_email']) ?>" required></div>
                <div class="group"><label>Admin Password</label><input type="password" name="admin_pass" value="<?= htmlspecialchars($formData['admin_pass']) ?>" required></div>
                <h3>Email Setup</h3>
                <p class="install-section-note">Optional, but useful for launches. Configure SMTP now so verification, password reset, support, and admin alerts work immediately after install.</p>
                <div class="install-promo">
                    <div class="install-promo-eyebrow">Recommended inbox option</div>
                    <p class="install-promo-title">Hostinger Business Email</p>
                    <p class="install-promo-copy">Business email packages for operators who want branded mailbox coverage for support, alerts, and transactional admin communication. Packages start at $0.39/month before coupon, and using the supplied link can get you an additional 20% off. <a href="https://www.hostinger.com/?REFERRALCODE=PHXCORRECHKN" target="_blank" rel="noopener noreferrer">Check Hostinger Business Email</a>.</p>
                </div>
                <div class="group"><label>SMTP Host</label><input type="text" name="email_smtp_host" placeholder="smtp.example.com" value="<?= htmlspecialchars($formData['email_smtp_host']) ?>"></div>
                <div class="group"><label>SMTP Port</label><input type="text" name="email_smtp_port" placeholder="465" value="<?= htmlspecialchars($formData['email_smtp_port']) ?>"></div>
                <div class="group"><label>From Address</label><input type="email" name="email_from_address" placeholder="noreply@yoursite.com" value="<?= htmlspecialchars($formData['email_from_address']) ?>"></div>
                <div class="group">
                    <label>Encryption</label>
                    <select name="email_secure_method">
                        <option value="none" <?= $formData['email_secure_method'] === 'none' ? 'selected' : '' ?>>None</option>
                        <option value="ssl" <?= $formData['email_secure_method'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="tls" <?= $formData['email_secure_method'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                    </select>
                </div>
                <div class="group install-inline-check">
                    <input type="checkbox" name="email_smtp_requires_auth" id="install_email_auth" value="1" <?= !empty($_POST['email_smtp_requires_auth']) ? 'checked' : '' ?>>
                    <label for="install_email_auth" style="margin-bottom: 0;">Server Requires Authentication</label>
                </div>
                <div class="group"><label>SMTP Username</label><input type="text" name="email_smtp_auth_username" placeholder="mailbox@example.com" value="<?= htmlspecialchars($formData['email_smtp_auth_username']) ?>"></div>
                <div class="group"><label>SMTP Password</label><input type="password" name="email_smtp_auth_password" value="<?= htmlspecialchars($formData['email_smtp_auth_password']) ?>"></div>
                <div class="group"><label>Send Rate Limit (emails / minute)</label><input type="text" name="email_limit_per_minute" placeholder="20" value="<?= htmlspecialchars($formData['email_limit_per_minute']) ?>"></div>
                <div class="group">
                    <button type="button" class="install-secondary-button" id="installTestEmailConnectionBtn">Test Email Connection</button>
                </div>
                <div id="installEmailTestResult" class="install-email-test-result" aria-live="polite"></div>
                <div class="install-button-row">
                    <button type="submit" id="installSubmitBtn" <?= $installerPathChecksPass ? '' : 'disabled' ?>>Install Now</button>
                    <div id="installProgress" class="install-progress" aria-live="polite" aria-atomic="true">
                        <span class="install-progress-spinner" aria-hidden="true"></span>
                        <span>Installing now. This can take a minute while the database, config file, and admin account are created. Please keep this tab open.</span>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <div class="error">Please fix the system requirements above to continue.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script nonce="<?= htmlspecialchars($installNonce, ENT_QUOTES, 'UTF-8') ?>">
function updateInstallerEmailTestResult(status, message) {
    const result = document.getElementById('installEmailTestResult');
    if (!result) {
        return;
    }

    result.className = 'install-email-test-result install-email-test-result--' + (status === 'success' ? 'success' : 'error');
    result.textContent = message;
}

function parseInstallerJsonResponse(response) {
    return response.text().then(function(text) {
        let payload = null;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            payload = null;
        }

        if (payload && typeof payload === 'object') {
            return payload;
        }

        return {
            status: 'error',
            message: text.trim() !== '' ? text.trim() : 'The installer returned an unexpected response.'
        };
    });
}

function testInstallerEmailConnection() {
    const button = document.getElementById('installTestEmailConnectionBtn');
    const form = document.querySelector('.installer-box form');
    if (!button || !form) {
        return;
    }

    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Testing...';
    updateInstallerEmailTestResult('success', 'Testing SMTP connection...');

    const formData = new FormData(form);
    formData.set('install_action', 'test_email_connection');

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(parseInstallerJsonResponse)
    .then(function(data) {
        updateInstallerEmailTestResult(data.status === 'success' ? 'success' : 'error', data.message || 'SMTP test finished.');
    })
    .catch(function(error) {
        updateInstallerEmailTestResult('error', 'SMTP test failed before the installer returned a valid response. ' + error.message);
    })
    .finally(function() {
        button.disabled = false;
        button.textContent = originalText;
    });
}

function markInstallerSubmitting(event) {
    const form = document.getElementById('installerForm');
    if (!form) {
        return;
    }

    if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }

    form.dataset.submitting = '1';

    const submitButton = document.getElementById('installSubmitBtn');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.textContent = 'Installing...';
    }

    const testButton = document.getElementById('installTestEmailConnectionBtn');
    if (testButton) {
        testButton.disabled = true;
    }

    const progress = document.getElementById('installProgress');
    if (progress) {
        progress.classList.add('is-active');
    }
}

const installerForm = document.getElementById('installerForm');
if (installerForm) {
    installerForm.addEventListener('submit', markInstallerSubmitting);
}

const installTestEmailConnectionBtn = document.getElementById('installTestEmailConnectionBtn');
if (installTestEmailConnectionBtn) {
    installTestEmailConnectionBtn.addEventListener('click', function(event) {
        event.preventDefault();
        testInstallerEmailConnection();
    });
}
</script>
</body>
</html>
