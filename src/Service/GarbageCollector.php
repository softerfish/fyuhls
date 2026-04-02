<?php

namespace App\Service;

class GarbageCollector {
    public static function cleanupChunks(): void {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $chunkDir = $root . '/storage/cache/chunks/';
        if (!is_dir($chunkDir)) return;

        $dirs = glob($chunkDir . '*', GLOB_ONLYDIR);
        $now = time();

        foreach ($dirs as $dir) {
            // If directory is older than 24 hours, delete it
            if ($now - filemtime($dir) > 86400) {
                self::deleteDir($dir);
            }
        }
    }

    private static function deleteDir(string $dir): void {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::deleteDir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
