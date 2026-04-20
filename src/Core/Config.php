<?php

namespace App\Core;

class Config {
    private static array $settings = [];

    public static function load(string $path): void {
        if (!file_exists($path)) {
            throw new \Exception("Config file not found: {$path}");
        }
        $config = require $path;
        self::$settings = array_merge(self::$settings, $config);
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
        self::$settings[$key] = $value;
    }
}
