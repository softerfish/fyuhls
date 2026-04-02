<?php

namespace App\Model;

use App\Core\Database;
use App\Core\Config;
use PDO;

class Setting {
    private static function shouldBlockWriteForDemoAdmin(): bool {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        return \App\Service\DemoModeService::currentViewerIsDemoAdmin();
    }

    public static function get(string $key, $default = null) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    // check the DB first, fall back to config/app.php - lets the admin UI override config values
    public static function getOrConfig(string $key, $default = null) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            return $val;
        }
        return Config::get($key, $default);
    }

    public static function set(string $key, string $value, string $group = 'general'): void {
        if (self::shouldBlockWriteForDemoAdmin()) {
            throw new \RuntimeException('This demo admin account is read-only.');
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_group) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)
        ");
        $stmt->execute([$key, $value, $group]);
    }

    public static function getAllByGroup(string $group): array {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM settings WHERE setting_group = ?");
        $stmt->execute([$group]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function getEncrypted(string $key, $default = null) {
        $val = self::get($key);
        return $val !== null ? \App\Service\EncryptionService::decrypt($val) : $default;
    }

    public static function setEncrypted(string $key, ?string $value, string $group = 'general'): void {
        $val = $value !== null ? \App\Service\EncryptionService::encrypt($value) : null;
        self::set($key, (string)$val, $group);
    }
}
