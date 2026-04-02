<?php

namespace App\Service\Security;

class PathValidator {
    public static function isSafeZipEntry(string $filename): bool {
        if (strpos($filename, '../') !== false || strpos($filename, '..\\') !== false) {
            return false;
        }
        return true;
    }

    public static function isSafePluginDir(string $dir): bool {
        if (strpos($dir, '.') !== false || strpos($dir, '/') !== false || strpos($dir, '\\') !== false) {
            return false;
        }
        return true;
    }
}

