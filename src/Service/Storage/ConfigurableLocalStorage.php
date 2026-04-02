<?php

namespace App\Service\Storage;

use App\Interface\StorageProvider;
use App\Core\Config;

/**
 * LocalStorage that accepts a configurable path and public URL,
 * used when spinning up a file_servers row with type=local.
 */
class ConfigurableLocalStorage implements StorageProvider {
    private string $rootPath;
    private string $publicUrl;

    public function __construct(string $rootPath, string $publicUrl = '') {
        // Safety: strip any leading public/
        $rootPath = preg_replace('/^(\/?public\/)/i', '', $rootPath);
        
        // ensure absolute path relative to project root if not already absolute
        if (!$this->isAbsolutePath($rootPath)) {
            $rootPath = ltrim($rootPath, '/\\');
            $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
            $rootPath = $root . '/' . $rootPath;
        }

        $this->rootPath  = rtrim($rootPath, '/\\');
        $this->publicUrl = rtrim($publicUrl, '/');

        if (!is_dir($this->rootPath)) {
            mkdir($this->rootPath, 0755, true);
        }
    }

    public function save(string $sourcePath, string $destinationPath): bool {
        $fullPath = $this->rootPath . '/' . $destinationPath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return rename($sourcePath, $fullPath);
    }

    public function delete(string $path): bool {
        $fullPath = $this->rootPath . '/' . $path;
        return file_exists($fullPath) ? unlink($fullPath) : false;
    }

    public function deleteVariants(string $path, array $variants = []): bool {
        // For local storage, variants are usually just files with different extensions/suffixes
        // We'll look for anything matching the base path
        $dir = dirname($this->rootPath . '/' . $path);
        if (!is_dir($dir)) return false;
        
        $pattern = $dir . '/' . pathinfo($path, PATHINFO_FILENAME) . '*';
        $files = glob($pattern);
        $success = true;
        foreach ($files as $file) {
            if (is_file($file)) {
                if (!unlink($file)) $success = false;
            }
        }
        return $success;
    }

    public function exists(string $path): bool {
        return file_exists($this->rootPath . '/' . $path);
    }

    public function getUrl(string $path): string {
        if ($this->publicUrl) {
            return $this->publicUrl . '/' . ltrim($path, '/');
        }
        $normalized = ltrim($path, '/');
        if (str_starts_with($normalized, 'thumbnails/')) {
            $normalized = substr($normalized, strlen('thumbnails/'));
        }

        return Config::get('base_url') . '/thumbnail/' . $normalized;
    }

    public function getAbsolutePath(string $path): string {
        return $this->rootPath . '/' . $path;
    }

    public function getPresignedUrl(string $path, int $expiry = 3600, array $options = []): ?string {
        return null;
    }

    public function stream(string $path, int $seekStart = 0, ?callable $onProgress = null, ?int $maxBytes = null): void {
        $fullPath = $this->getAbsolutePath($path);
        if (!file_exists($fullPath)) {
            http_response_code(404);
            return;
        }

        $fp = fopen($fullPath, 'rb');
        if (!$fp) {
            http_response_code(500);
            return;
        }

        if ($seekStart > 0) {
            fseek($fp, $seekStart);
        }

        $totalSent = $seekStart;
        $remaining = $maxBytes;
        while (!feof($fp) && ($remaining === null || $remaining > 0)) {
            $readLength = $remaining === null ? 8192 : min(8192, $remaining);
            $buffer = fread($fp, $readLength);
            if ($buffer === '' || $buffer === false) {
                break;
            }
            echo $buffer;
            flush();
            if (connection_aborted()) {
                break;
            }
            $sentLength = strlen($buffer);
            $totalSent += $sentLength;
            if ($remaining !== null) {
                $remaining -= $sentLength;
            }
            
            if ($onProgress) {
                $onProgress($totalSent);
            }
        }
        fclose($fp);
    }

    public function getName(): string { return 'Local Storage'; }
    public function getDescription(): string { return 'Local file system storage.'; }
    public function testConnection(): bool {
        return is_dir($this->rootPath) && is_writable($this->rootPath);
    }

    public function getCapabilities(): array {
        return [
            'multipart' => false,
            'presigned_part_upload' => false,
            'presigned_download' => false,
            'head' => true,
        ];
    }

    public function createMultipartUpload(string $destinationPath, array $options = []): ?array {
        return null;
    }

    public function createMultipartPartUrl(string $destinationPath, string $uploadId, int $partNumber, int $expiry = 3600, array $options = []): ?string {
        return null;
    }

    public function listMultipartParts(string $destinationPath, string $uploadId): array {
        return [];
    }

    public function completeMultipartUpload(string $destinationPath, string $uploadId, array $parts): bool {
        return false;
    }

    public function abortMultipartUpload(string $destinationPath, string $uploadId): bool {
        return false;
    }

    public function head(string $path): ?array {
        $fullPath = $this->getAbsolutePath($path);
        if (!is_file($fullPath)) {
            return null;
        }

        return [
            'path' => $path,
            'content_length' => filesize($fullPath),
            'last_modified' => filemtime($fullPath),
            'etag' => md5_file($fullPath),
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && (
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 ||
            str_starts_with($path, '\\\\') ||
            str_starts_with($path, '/')
        );
    }
}
