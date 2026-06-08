<?php

namespace App\Core;

class Config {
    private static array $settings = [];

    private static function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::mergeRecursiveDistinct($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    public static function load(string $path): void {
        if (!file_exists($path)) {
            throw new \Exception("Config file not found: {$path}");
        }
        $config = require $path;
        self::loadArray(is_array($config) ? $config : []);
    }

    public static function loadArray(array $config): void
    {
        self::$settings = self::mergeRecursiveDistinct(self::$settings, $config);
    }

    public static function get(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = self::$settings;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, $value): void {
        $keys = explode('.', $key);
        $settings =& self::$settings;
        $lastIndex = count($keys) - 1;

        foreach ($keys as $index => $segment) {
            if ($index === $lastIndex) {
                $settings[$segment] = $value;
                return;
            }

            if (!isset($settings[$segment]) || !is_array($settings[$segment])) {
                $settings[$segment] = [];
            }

            $settings =& $settings[$segment];
        }
    }
}
