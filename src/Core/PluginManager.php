<?php

namespace App\Core;

use App\Core\Database;
use App\Core\PluginInterface;

class PluginManager {
    private static array $actions = [];
    private static array $filters = [];

    private static function isSafePluginDirectoryName(string $pluginDir): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $pluginDir) === 1;
    }

    private static function canQueryPlugins(): bool {
        $dbConfig = Config::get('database');
        if (!is_array($dbConfig) || empty($dbConfig['host']) || empty($dbConfig['dbname']) || empty($dbConfig['username'])) {
            return false;
        }

        try {
            $db = Database::getInstance()->getConnection();
            if (!$db) {
                return false;
            }

            $stmt = $db->query("SHOW TABLES LIKE 'plugins'");
            return $stmt && (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // Load active plugins from DB
    public static function loadPlugins(\App\Core\Router $router): void {
        if (!self::canQueryPlugins()) {
            return;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT directory FROM plugins WHERE is_active = 1");
            if ($stmt) {
                $plugins = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                $pluginBase = realpath(dirname(__DIR__) . '/Plugin');
                if ($pluginBase === false) {
                    return;
                }
                foreach ($plugins as $pluginDir) {
                    $pluginDir = (string)$pluginDir;
                    if (!self::isSafePluginDirectoryName($pluginDir)) {
                        continue;
                    }

                    $pluginFolder = dirname(__DIR__) . '/Plugin/' . $pluginDir;
                    $resolvedFolder = realpath($pluginFolder);
                    if ($resolvedFolder === false || !str_starts_with(str_replace('\\', '/', $resolvedFolder) . '/', str_replace('\\', '/', $pluginBase) . '/')) {
                        continue;
                    }

                    $pluginPath = $resolvedFolder . '/' . $pluginDir . 'Plugin.php';
                    $resolvedPluginPath = realpath($pluginPath);
                    if ($resolvedPluginPath !== false && str_starts_with(str_replace('\\', '/', $resolvedPluginPath), str_replace('\\', '/', $resolvedFolder) . '/')) {
                        require_once $resolvedPluginPath;
                        
                        $className = "\\Plugin\\{$pluginDir}\\{$pluginDir}Plugin";
                        if (class_exists($className)) {
                            $plugin = new $className();
                            if ($plugin instanceof PluginInterface) {
                                $plugin->registerRoutes($router);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Plugins are optional. If they cannot be loaded, continue boot quietly.
        }
    }

    // Check if a plugin is active by its directory name
    public static function isActive(string $pluginDir): bool {
        if (!self::isSafePluginDirectoryName($pluginDir)) {
            return false;
        }

        if (!self::canQueryPlugins()) {
            return false;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT 1 FROM plugins WHERE directory = ? AND is_active = 1");
            $stmt->execute([$pluginDir]);
            return (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function getActivePlugins(): array {
        if (!self::canQueryPlugins()) {
            return [];
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT directory FROM plugins WHERE is_active = 1");
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
            return array_values(array_filter($rows, static fn ($dir): bool => self::isSafePluginDirectoryName((string)$dir)));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // Add a hook (action)
    public static function addAction(string $tag, callable $callback, int $priority = 10): void {
        self::$actions[$tag][] = [
            'callback' => $callback,
            'priority' => $priority
        ];
        
        // Sort by priority
        usort(self::$actions[$tag], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    // Execute a hook (action)
    public static function doAction(string $tag, ...$args): void {
        if (!isset(self::$actions[$tag])) {
            return;
        }

        foreach (self::$actions[$tag] as $action) {
            call_user_func_array($action['callback'], $args);
        }
    }

    // Add a filter
    public static function addFilter(string $tag, callable $callback, int $priority = 10): void {
        self::$filters[$tag][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // Sort by priority
        usort(self::$filters[$tag], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    // Apply filters
    public static function applyFilters(string $tag, $value, ...$args) {
        if (!isset(self::$filters[$tag])) {
            return $value;
        }

        foreach (self::$filters[$tag] as $filter) {
            $value = call_user_func_array($filter['callback'], array_merge([$value], $args));
        }

        return $value;
    }
}
