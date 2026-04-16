<?php

namespace App\Core;

class View {
    private static array $hooks = [];

    public static function registerHook(string $name, callable $callback): void {
        self::$hooks[$name][] = $callback;
    }

    public static function hook(string $name, array $params = []): void {
        if (isset(self::$hooks[$name])) {
            foreach (self::$hooks[$name] as $callback) {
                echo call_user_func_array($callback, $params);
            }
        }
    }

    public static function render(string $template, array $data = []): void {
        $theme = Config::get('theme.name', '');
        if ($theme !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
            $theme = '';
        }
        $base = dirname(__DIR__) . '/View';
        $root = dirname(__DIR__, 2);
        
        $paths = [];
        
        // 1. Look in the user's custom override folder (highest priority)
        $paths[] = $root . '/themes/custom';
        
        // 2. Look in the active theme (middle priority)
        if ($theme && $theme !== 'custom') {
            $paths[] = $root . '/themes/' . $theme . '/views';
        }
        
        // 3. Fallback to core views (lowest priority)
        $paths[] = $base;
        $templateFile = null;
        foreach ($paths as $p) {
            $candidate = rtrim($p, '/\\') . '/' . ltrim($template, '/\\');
            if (file_exists($candidate)) {
                $templateFile = $candidate;
                break;
            }
        }
        if (!$templateFile) {
            http_response_code(500);
            echo "view not found";
            return;
        }
        // containment check: resolved path must stay inside the project root
        $realTemplate = realpath($templateFile);
        $realRoot = realpath($root);
        if ($realTemplate === false || $realRoot === false || !str_starts_with(str_replace('\\', '/', $realTemplate), str_replace('\\', '/', $realRoot) . '/')) {
            http_response_code(500);
            echo "view not found";
            return;
        }
        extract($data, EXTR_SKIP);
        include $realTemplate;
    }
}
