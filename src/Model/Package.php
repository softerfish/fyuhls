<?php

namespace App\Model;

use App\Core\Database;
use PDO;

class Package
{
    private static bool $runtimeColumnsChecked = false;

    public static function find(int $id): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $stmt = $db->prepare("SELECT * FROM packages WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function getGuestPackage(): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $stmt = $db->query("SELECT * FROM packages WHERE level_type = 'guest' LIMIT 1");
        return $stmt->fetch() ?: null;
    }

    public static function getUserPackage(int $userId): ?array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        $stmt = $db->prepare("
            SELECT p.* FROM packages p 
            JOIN users u ON u.package_id = p.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $package = $stmt->fetch();
        return $package ?: self::getGuestPackage();
    }

    public static function getAll(): array
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);
        return $db->query("SELECT * FROM packages ORDER BY id ASC")->fetchAll();
    }

    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance()->getConnection();
        self::ensureRuntimeColumns($db);

        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        $values[] = $id;

        $sql = "UPDATE packages SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        return $stmt->execute($values);
    }

    private static function ensureRuntimeColumns($db): void
    {
        if (self::$runtimeColumnsChecked) {
            return;
        }

        try {
            $db->query("SELECT price FROM packages LIMIT 1");
        } catch (\PDOException $e) {
            $db->exec("ALTER TABLE packages ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER name");
            $db->exec("UPDATE packages SET price = CASE WHEN level_type = 'paid' THEN 9.99 ELSE 0.00 END");
        }

        try {
            $db->query("SELECT concurrent_downloads FROM packages LIMIT 1");
        } catch (\PDOException $e) {
            $db->exec("ALTER TABLE packages ADD COLUMN concurrent_downloads INT UNSIGNED NOT NULL DEFAULT 1 AFTER concurrent_uploads");
        }

        self::$runtimeColumnsChecked = true;
    }
}
