<?php

namespace App\Controller\Admin;

use App\Core\Database;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Service\Security\PathValidator;
use ZipArchive;
use App\Core\View;

class PluginController
{
    private const MAX_PLUGIN_ZIP_BYTES = 20 * 1024 * 1024;
    private const MAX_PLUGIN_ZIP_FILES = 500;
    private const MAX_PLUGIN_EXTRACTED_BYTES = 50 * 1024 * 1024;

    private function checkAuth()
    {
        Auth::requireAdmin();
    }

    private function failUpload(string $message, string $logMessage, array $context = []): void
    {
        Logger::error($logMessage, $context + ['admin_id' => Auth::id()]);
        echo $message;
        exit;
    }

    public function index()
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();

        // Fetch installed plugins from DB
        $installed = $db->query("SELECT * FROM plugins")->fetchAll(\PDO::FETCH_ASSOC);
        $installedMap = array_column($installed, null, 'directory');

        // Scan directory for plugins
        $pluginDir = dirname(__DIR__, 2) . '/Plugin';
        $dirs = array_filter(glob($pluginDir . '/*'), 'is_dir');

        $allPlugins = [];

        foreach ($dirs as $dir) {
            $dirname = basename($dir);
            $jsonPath = $dir . '/plugin.json';

            if (file_exists($jsonPath)) {
                $meta = json_decode(file_get_contents($jsonPath), true);
                $allPlugins[$dirname] = [
                    'meta' => $meta,
                    'db_record' => $installedMap[$dirname] ?? null
                ];
            }
        }

        View::render('admin/plugins/index.php', ['plugins' => $allPlugins]);
    }

    public function settings(string $dir)
    {
        $this->checkAuth();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }
        }

        $safeDir = htmlspecialchars($dir);

        // Basic Settings Logic based on convention
        // Plugins should register hooks to render their settings
        // But for this simple implementation, we can look for settings.php in plugin dir

        // Validate Dir (Simple Traversal Check)
        if (strpos($dir, '.') !== false || strpos($dir, '/') !== false) {
            die("Invalid Plugin Directory");
        }

        $settingsPath = dirname(__DIR__, 2) . '/Plugin/' . $dir . '/settings.php';

        if (file_exists($settingsPath)) {
            include $settingsPath;
        }
        else {
            include dirname(__DIR__, 2) . '/View/admin/header.php';
            echo "<div class='page-header'><h1>Settings: $safeDir</h1><a href='/admin/plugins' class='btn'>&laquo; Back to Plugins</a></div>";
            echo "<div class='card'><div class='card-body'>No settings page available for this plugin.</div></div>";
            include dirname(__DIR__, 2) . '/View/admin/footer.php';
        }
    }

    public function upload()
    {
        $this->checkAuth();

        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            die("CSRF Token Mismatch");
        }

        if (!isset($_FILES['plugin_zip']) || !is_array($_FILES['plugin_zip'])) {
            $this->failUpload("Upload failed.", 'plugin upload missing file payload');
        }

        if ($_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
            $zipPath = $_FILES['plugin_zip']['tmp_name'];
            $originalName = (string)($_FILES['plugin_zip']['name'] ?? '');
            $uploadSize = (int)($_FILES['plugin_zip']['size'] ?? 0);

            if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
                $this->failUpload("Plugin upload failed: only .zip archives are allowed.", 'plugin upload rejected invalid extension', ['filename' => $originalName]);
            }

            if ($uploadSize <= 0 || $uploadSize > self::MAX_PLUGIN_ZIP_BYTES) {
                $this->failUpload(
                    "Plugin upload failed: ZIP archives must be smaller than " . (int)(self::MAX_PLUGIN_ZIP_BYTES / (1024 * 1024)) . " MB.",
                    'plugin upload rejected size limit',
                    ['filename' => $originalName, 'size' => $uploadSize]
                );
            }

            $zip = new ZipArchive;

            if ($zip->open($zipPath) === TRUE) {
                $extractPath = dirname(__DIR__, 2) . '/Plugin/';
                $entryCount = 0;
                $totalExtractedBytes = 0;
                $hasPluginMeta = false;

                // Secure Extraction Loop (Zip Slip Prevention)
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (!PathValidator::isSafeZipEntry($filename)) {
                        $zip->close();
                        $this->failUpload("Security Error: Malicious ZIP detected (Zip Slip)", 'plugin upload malicious zip slip detected', ['filename' => $originalName, 'entry' => $filename]);
                    }

                    $stat = $zip->statIndex($i);
                    if (!is_array($stat)) {
                        $zip->close();
                        $this->failUpload("Plugin upload failed: ZIP archive metadata could not be read.", 'plugin upload failed stat read', ['filename' => $originalName, 'entry' => $filename]);
                    }

                    $entryCount++;
                    if ($entryCount > self::MAX_PLUGIN_ZIP_FILES) {
                        $zip->close();
                        $this->failUpload(
                            "Plugin upload failed: ZIP archive contains too many files.",
                            'plugin upload rejected file count limit',
                            ['filename' => $originalName, 'files' => $entryCount]
                        );
                    }

                    $size = max(0, (int)($stat['size'] ?? 0));
                    $totalExtractedBytes += $size;
                    if ($totalExtractedBytes > self::MAX_PLUGIN_EXTRACTED_BYTES) {
                        $zip->close();
                        $this->failUpload(
                            "Plugin upload failed: extracted plugin size is too large.",
                            'plugin upload rejected extracted size limit',
                            ['filename' => $originalName, 'bytes' => $totalExtractedBytes]
                        );
                    }

                    if (strtolower(basename(str_replace('\\', '/', $filename))) === 'plugin.json') {
                        $hasPluginMeta = true;
                    }

                    $zip->extractTo($extractPath, $filename);
                }

                if (!$hasPluginMeta) {
                    $zip->close();
                    $this->failUpload("Plugin upload failed: plugin.json was not found in the archive.", 'plugin upload missing plugin metadata', ['filename' => $originalName]);
                }

                $zip->close();
                echo "Plugin uploaded successfully! <a href='/admin/plugins'>Go Back</a>";
                Logger::info('plugin uploaded', ['admin_id' => Auth::id(), 'filename' => $originalName, 'files' => $entryCount, 'bytes' => $totalExtractedBytes]);
            }
            else {
                $this->failUpload("Failed to open ZIP file.", 'plugin upload failed open zip', ['filename' => $originalName]);
            }
        }
        else {
            $this->failUpload("Upload failed.", 'plugin upload http error', ['code' => $_FILES['plugin_zip']['error'] ?? null]);
        }
    }

    private function validateDir(string $dir)
    {
        if (!PathValidator::isSafePluginDir($dir)) {
            die("Invalid Plugin Directory");
        }
    }

    public function install(string $dir)
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die("Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF Token Mismatch");
        }
        $this->validateDir($dir);
        $db = Database::getInstance()->getConnection();

        $jsonPath = dirname(__DIR__, 2) . '/Plugin/' . $dir . '/plugin.json';
        if (!file_exists($jsonPath))
            die("Plugin meta not found");

        $meta = json_decode(file_get_contents($jsonPath), true);

        // Register in DB
        $stmt = $db->prepare("INSERT INTO plugins (name, directory, version, is_active) VALUES (?, ?, ?, 0)");
        $stmt->execute([$meta['name'], $dir, $meta['version']]);

        Logger::info('plugin installed', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
    }

    public function activate(string $dir)
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die("Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF Token Mismatch");
        }
        $this->validateDir($dir);
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE plugins SET is_active = 1 WHERE directory = ?")->execute([$dir]);
        Logger::info('plugin activated', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
    }

    public function deactivate(string $dir)
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die("Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF Token Mismatch");
        }
        $this->validateDir($dir);
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE plugins SET is_active = 0 WHERE directory = ?")->execute([$dir]);
        Logger::info('plugin deactivated', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
    }

    public function uninstall(string $dir)
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die("Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            die("CSRF Token Mismatch");
        }
        $this->validateDir($dir);

        // Security check: Must not contain relative traversal paths
        if (strpos($dir, '.') !== false || strpos($dir, '/') !== false || strpos($dir, '\\') !== false) {
            die("Invalid Plugin Directory");
        }

        $db = Database::getInstance()->getConnection();

        // Remove from DB
        $db->prepare("DELETE FROM plugins WHERE directory = ?")->execute([$dir]);

        $pluginPath = dirname(__DIR__, 2) . '/Plugin/' . $dir;
        $pluginFile = $pluginPath . '/' . $dir . 'Plugin.php';
        
        // Try to trigger the plugin's native uninstall method to clean up its own DB tables/data
        if (file_exists($pluginFile)) {
            require_once $pluginFile;
            $className = "\\Plugin\\{$dir}\\{$dir}Plugin";
            if (class_exists($className)) {
                $plugin = new $className();
                if (method_exists($plugin, 'uninstall')) {
                    $plugin->uninstall();
                }
            }
        }

        // Physically delete the plugin files
        $this->deleteDirRecursively($pluginPath);

        Logger::info('plugin uninstalled and deleted', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
        exit;
    }

    private function deleteDirRecursively($dir) {
        if (!file_exists($dir)) {
            return true;
        }
    
        if (!is_dir($dir)) {
            return unlink($dir);
        }
    
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
    
            if (!$this->deleteDirRecursively($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
    
        }
    
        return rmdir($dir);
    }
}
