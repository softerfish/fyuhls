<?php

namespace App\Controller\Admin;

use App\Core\Database;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Service\Security\PathValidator;
use ZipArchive;
use App\Core\View;
use App\Model\Setting;

class PluginController
{
    private const MAX_PLUGIN_ZIP_BYTES = 20 * 1024 * 1024;
    private const MAX_PLUGIN_ZIP_FILES = 500;
    private const MAX_PLUGIN_EXTRACTED_BYTES = 50 * 1024 * 1024;
    private static ?string $pluginBasePathOverride = null;

    private function checkAuth()
    {
        Auth::requireCapability('plugins.manage');
        if (!Auth::isSuperAdmin()) {
            Auth::denyAccess('Plugin management is restricted to the protected super admin account.');
        }
    }

    private function failUpload(string $message, string $logMessage, array $context = []): void
    {
        Logger::error($logMessage, $context + ['admin_id' => Auth::id()]);
        echo $message;
        exit;
    }

    private function abortText(int $status, string $message): void
    {
        http_response_code($status);
        exit($message);
    }

    private function pluginBasePath(): string
    {
        return self::$pluginBasePathOverride ?? dirname(__DIR__, 2) . '/Plugin';
    }

    public static function setPluginBasePathForTests(?string $path): void
    {
        self::$pluginBasePathOverride = $path;
    }

    private function cleanupPath(string $path): void
    {
        if ($path === '') {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->cleanupPath($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }

    private function loadPluginMetaFromDirectory(string $dir): array
    {
        $this->validateDir($dir);
        $jsonPath = $this->pluginBasePath() . '/' . $dir . '/plugin.json';
        if (!is_file($jsonPath)) {
            throw new \RuntimeException('Plugin meta not found');
        }

        $meta = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($meta) || trim((string)($meta['name'] ?? '')) === '' || trim((string)($meta['version'] ?? '')) === '') {
            throw new \RuntimeException('Plugin metadata is invalid.');
        }

        return $meta;
    }

    private function instantiatePlugin(string $dir): ?object
    {
        $this->validateDir($dir);
        $pluginBase = $this->pluginBasePath();
        $pluginPath = $pluginBase . '/' . $dir;
        if (!PathValidator::isPathWithinBase($pluginBase, $pluginPath)) {
            throw new \RuntimeException('Invalid Plugin Directory');
        }

        $pluginFile = $pluginPath . '/' . $dir . 'Plugin.php';
        if (!is_file($pluginFile)) {
            return null;
        }

        require_once $pluginFile;
        $className = "\\Plugin\\{$dir}\\{$dir}Plugin";
        if (!class_exists($className)) {
            throw new \RuntimeException('Plugin bootstrap class could not be loaded.');
        }

        return new $className();
    }

    private function invokePluginLifecycle(string $dir, string $method): void
    {
        $plugin = $this->instantiatePlugin($dir);
        $this->invokePluginLifecycleObject($plugin, $method);
    }

    private function invokePluginLifecycleObject(?object $plugin, string $method): void
    {
        if ($plugin === null) {
            return;
        }

        if (!method_exists($plugin, $method)) {
            return;
        }

        $result = $plugin->{$method}();
        if ($result === false) {
            throw new \RuntimeException('Plugin lifecycle hook `' . $method . '` reported failure.');
        }
    }

    private function queueLifecycleWarning(string $dir, string $method, \Throwable $e): void
    {
        Logger::warning('plugin lifecycle hook reported failure after the core action was already committed', [
            'plugin_dir' => $dir,
            'hook' => $method,
            'admin_id' => Auth::id(),
            'error' => $e->getMessage(),
        ]);
        $_SESSION['warning'] = sprintf(
            'The plugin %s action completed, but the `%s` hook reported an error: %s',
            $dir,
            $method,
            $e->getMessage()
        );
    }

    private function stagePluginArchive(string $zipPath, string $originalName): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open ZIP file.');
        }

        try {
            $stagingRoot = \App\Service\TemporaryArtifactService::createTempDirectory('fyuhls_plugin_');
        } catch (\Throwable $e) {
            $zip->close();
            throw new \RuntimeException('Plugin upload failed: could not prepare the staging directory.', 0, $e);
        }

        $entryCount = 0;
        $totalExtractedBytes = 0;
        $rootDir = null;
        $pluginMetaPath = null;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (!PathValidator::isSafeZipEntry($filename)) {
                    throw new \RuntimeException('Security Error: Malicious ZIP detected (Zip Slip)');
                }

                $normalized = str_replace('\\', '/', trim((string)$filename));
                $normalized = ltrim($normalized, '/');
                if ($normalized === '') {
                    continue;
                }

                $parts = array_values(array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== ''));
                if ($parts === []) {
                    continue;
                }

                $topLevel = $parts[0];
                if (!PathValidator::isSafePluginDir($topLevel)) {
                    throw new \RuntimeException('Plugin upload failed: archive root must be a single safe plugin directory.');
                }
                if ($rootDir === null) {
                    $rootDir = $topLevel;
                } elseif ($rootDir !== $topLevel) {
                    throw new \RuntimeException('Plugin upload failed: archive must contain exactly one plugin root directory.');
                }

                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new \RuntimeException('Plugin upload failed: ZIP archive metadata could not be read.');
                }

                $entryCount++;
                if ($entryCount > self::MAX_PLUGIN_ZIP_FILES) {
                    throw new \RuntimeException('Plugin upload failed: ZIP archive contains too many files.');
                }

                $size = max(0, (int)($stat['size'] ?? 0));
                $totalExtractedBytes += $size;
                if ($totalExtractedBytes > self::MAX_PLUGIN_EXTRACTED_BYTES) {
                    throw new \RuntimeException('Plugin upload failed: extracted plugin size is too large.');
                }

                $targetPath = PathValidator::buildSafeChildPath($stagingRoot, $normalized);
                if ($targetPath === null) {
                    throw new \RuntimeException('Security Error: Malicious ZIP detected (invalid entry path)');
                }

                $directory = str_ends_with($normalized, '/');
                if ($directory) {
                    if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                        throw new \RuntimeException('Plugin upload failed: could not create plugin directories.');
                    }
                    continue;
                }

                $parentDir = dirname($targetPath);
                if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                    throw new \RuntimeException('Plugin upload failed: could not prepare plugin directory.');
                }

                $stream = $zip->getStream($filename);
                if ($stream === false) {
                    throw new \RuntimeException('Plugin upload failed: ZIP entry could not be read.');
                }

                $out = fopen($targetPath, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new \RuntimeException('Plugin upload failed: extracted file could not be written.');
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);

                if (count($parts) === 2 && strtolower($parts[1]) === 'plugin.json') {
                    $pluginMetaPath = $targetPath;
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            $this->cleanupPath($stagingRoot);
            throw $e;
        }

        $zip->close();

        if ($rootDir === null || $pluginMetaPath === null || !is_file($pluginMetaPath)) {
            $this->cleanupPath($stagingRoot);
            throw new \RuntimeException('Plugin upload failed: plugin.json was not found at the plugin root.');
        }

        $meta = json_decode((string)file_get_contents($pluginMetaPath), true);
        if (!is_array($meta) || trim((string)($meta['name'] ?? '')) === '' || trim((string)($meta['version'] ?? '')) === '') {
            $this->cleanupPath($stagingRoot);
            throw new \RuntimeException('Plugin upload failed: plugin.json is invalid.');
        }

        $pluginRootPath = $stagingRoot . '/' . $rootDir;
        $pluginFile = $pluginRootPath . '/' . $rootDir . 'Plugin.php';
        if (!is_file($pluginFile)) {
            $this->cleanupPath($stagingRoot);
            throw new \RuntimeException('Plugin upload failed: the plugin bootstrap file is missing.');
        }

        $liveTarget = $this->pluginBasePath() . '/' . $rootDir;
        if (file_exists($liveTarget)) {
            $this->cleanupPath($stagingRoot);
            throw new \RuntimeException('Plugin upload failed: a plugin with that directory already exists. Remove it before uploading a replacement.');
        }

        return [
            'staging_root' => $stagingRoot,
            'plugin_root_path' => $pluginRootPath,
            'plugin_dir' => $rootDir,
            'meta' => $meta,
            'entry_count' => $entryCount,
            'bytes' => $totalExtractedBytes,
            'filename' => $originalName,
        ];
    }

    private function publishStagedPlugin(array $staged): void
    {
        $pluginDir = (string)($staged['plugin_dir'] ?? '');
        $pluginRootPath = (string)($staged['plugin_root_path'] ?? '');
        if ($pluginDir === '' || $pluginRootPath === '') {
            throw new \RuntimeException('Plugin upload failed: staging data was incomplete.');
        }

        $destination = $this->pluginBasePath() . '/' . $pluginDir;
        if (!@rename($pluginRootPath, $destination)) {
            throw new \RuntimeException('Plugin upload failed: could not publish the staged plugin.');
        }

        $stagingRoot = (string)($staged['staging_root'] ?? '');
        if ($stagingRoot !== '') {
            $this->cleanupPath($stagingRoot);
        }
    }

    public function index()
    {
        $this->checkAuth();
        $db = Database::getInstance()->getConnection();

        // Fetch installed plugins from DB
        $installed = $db->query("SELECT * FROM plugins")->fetchAll(\PDO::FETCH_ASSOC);
        $installedMap = array_column($installed, null, 'directory');

        // Scan directory for plugins
        $pluginDir = $this->pluginBasePath();
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

        View::render('admin/plugins/index.php', [
            'plugins' => $allPlugins,
            'pluginUploadsEnabled' => Setting::get('plugin_uploads_enabled', '1') === '1',
        ]);
    }

    public function uploadPolicy()
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Token Mismatch");
        }

        Setting::set('plugin_uploads_enabled', isset($_POST['plugin_uploads_enabled']) ? '1' : '0', 'security');
        Logger::info('plugin upload policy updated', [
            'admin_id' => Auth::id(),
            'enabled' => isset($_POST['plugin_uploads_enabled']),
        ]);

        header("Location: /admin/plugins");
    }

    public function settings(string $dir)
    {
        $this->checkAuth();
        $this->validateDir($dir);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
                die("CSRF Token Mismatch");
            }
        }

        $safeDir = htmlspecialchars($dir);
        $pluginBase = $this->pluginBasePath();
        $pluginPath = $pluginBase . '/' . $dir;
        if (!PathValidator::isPathWithinBase($pluginBase, $pluginPath) || !is_dir($pluginPath)) {
            $this->abortText(404, "Plugin is not installed.");
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT is_active FROM plugins WHERE directory = ? LIMIT 1");
        $stmt->execute([$dir]);
        $current = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$current) {
            $this->abortText(404, "Plugin is not installed.");
        }
        if (empty($current['is_active'])) {
            $this->abortText(403, "Plugin is not active.");
        }

        $settingsPath = $pluginBase . '/' . $dir . '/settings.php';
        if (!PathValidator::isPathWithinBase($pluginBase, $settingsPath)) {
            die("Invalid Plugin Directory");
        }

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

        if (Setting::get('plugin_uploads_enabled', '1') !== '1') {
            $this->abortText(403, "Plugin ZIP uploads are disabled by policy.");
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

            $staged = null;
            try {
                $staged = $this->stagePluginArchive($zipPath, $originalName);
                $this->publishStagedPlugin($staged);
                echo "Plugin uploaded successfully! <a href='/admin/plugins'>Go Back</a>";
                Logger::info('plugin uploaded', [
                    'admin_id' => Auth::id(),
                    'filename' => $originalName,
                    'files' => (int)$staged['entry_count'],
                    'bytes' => (int)$staged['bytes'],
                    'plugin_dir' => (string)$staged['plugin_dir'],
                ]);
            } catch (\Throwable $e) {
                if (is_array($staged) && !empty($staged['staging_root'])) {
                    $this->cleanupPath((string)$staged['staging_root']);
                }
                $this->failUpload($e->getMessage(), 'plugin upload failed', [
                    'filename' => $originalName,
                    'error' => $e->getMessage(),
                ]);
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
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Token Mismatch");
        }
        $this->validateDir($dir);
        $db = Database::getInstance()->getConnection();
        $meta = $this->loadPluginMetaFromDirectory($dir);

        $existing = $db->prepare("SELECT id FROM plugins WHERE directory = ? LIMIT 1");
        $existing->execute([$dir]);
        if ($existing->fetchColumn() !== false) {
            $this->abortText(409, "Plugin is already installed.");
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO plugins (name, directory, version, is_active) VALUES (?, ?, ?, 0)");
            $stmt->execute([(string)$meta['name'], $dir, (string)$meta['version']]);
            $this->invokePluginLifecycle($dir, 'install');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->abortText(422, $e->getMessage());
        }

        Logger::info('plugin installed', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
    }

    public function activate(string $dir)
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Token Mismatch");
        }
        $this->validateDir($dir);
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT is_active FROM plugins WHERE directory = ? LIMIT 1");
        $stmt->execute([$dir]);
        $current = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$current) {
            $this->abortText(404, "Plugin is not installed.");
        }
        if (!empty($current['is_active'])) {
            header("Location: /admin/plugins");
            return;
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE plugins SET is_active = 1 WHERE directory = ?")->execute([$dir]);
            $this->invokePluginLifecycle($dir, 'activate');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->abortText(422, $e->getMessage());
        }
        Logger::info('plugin activated', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
    }

    public function deactivate(string $dir)
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Token Mismatch");
        }
        $this->validateDir($dir);
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT is_active FROM plugins WHERE directory = ? LIMIT 1");
        $stmt->execute([$dir]);
        $current = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$current) {
            $this->abortText(404, "Plugin is not installed.");
        }
        if (empty($current['is_active'])) {
            header("Location: /admin/plugins");
            return;
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE plugins SET is_active = 0 WHERE directory = ?")->execute([$dir]);
            $this->invokePluginLifecycle($dir, 'deactivate');
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->abortText(422, $e->getMessage());
        }
        Logger::info('plugin deactivated', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
    }

    public function uninstall(string $dir)
    {
        $this->checkAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->abortText(405, "Method Not Allowed");
        }
        if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
            $this->abortText(403, "CSRF Token Mismatch");
        }
        $this->validateDir($dir);

        // Security check: Must not contain relative traversal paths
        if (strpos($dir, '.') !== false || strpos($dir, '/') !== false || strpos($dir, '\\') !== false) {
            die("Invalid Plugin Directory");
        }

        $pluginBase = $this->pluginBasePath();
        $pluginPath = $pluginBase . '/' . $dir;
        if (!PathValidator::isPathWithinBase($pluginBase, $pluginPath)) {
            die("Invalid Plugin Directory");
        }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM plugins WHERE directory = ? LIMIT 1");
        $stmt->execute([$dir]);
        if ($stmt->fetchColumn() === false) {
            $this->abortText(404, "Plugin is not installed.");
        }

        $trashPath = $pluginBase . '/.delete_' . $dir . '_' . bin2hex(random_bytes(4));
        $pluginInstance = null;
        $pluginRenamed = false;
        try {
            $pluginInstance = $this->instantiatePlugin($dir);
            if (file_exists($pluginPath)) {
                if (!@rename($pluginPath, $trashPath)) {
                    throw new \RuntimeException('Plugin uninstall failed: live files could not be isolated safely.');
                }
                $pluginRenamed = true;
            }

            $db->beginTransaction();
            $this->invokePluginLifecycleObject($pluginInstance, 'uninstall');
            $db->prepare("DELETE FROM plugins WHERE directory = ?")->execute([$dir]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($pluginRenamed && is_dir($trashPath) && !file_exists($pluginPath)) {
                @rename($trashPath, $pluginPath);
            }
            $this->abortText(422, $e->getMessage());
        }

        if ($pluginRenamed && is_dir($trashPath) && !$this->deleteDirRecursively($trashPath)) {
            Logger::warning('plugin uninstall cleanup left archived files behind after the authoritative state change completed', [
                'plugin_dir' => $dir,
                'trash_path' => $trashPath,
                'admin_id' => Auth::id(),
            ]);
            $_SESSION['warning'] = 'The plugin was uninstalled, but the archived plugin files could not be removed automatically.';
        }

        Logger::info('plugin uninstalled and deleted', ['dir' => $dir, 'admin_id' => Auth::id()]);
        header("Location: /admin/plugins");
        return;
    }

    private function deleteDirRecursively($dir) {
        $pluginBase = $this->pluginBasePath();
        if (!PathValidator::isPathWithinBase($pluginBase, $dir)) {
            return false;
        }

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
