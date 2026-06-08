<?php

namespace App\Service\Storage;

use App\Interface\StorageProvider;
use App\Core\Config;

class LocalStorage implements StorageProvider {
    private string $rootPath;

    public function __construct() {
        // Defaults to storage/uploads (private)
        $path = Config::get('storage.local.path', 'storage/uploads');
        $this->rootPath = StoragePathGuard::normalizeLocalRootPath((string)$path);

        if (!is_dir($this->rootPath)) {
            mkdir($this->rootPath, 0755, true);
        }
    }

    public function save(string $sourcePath, string $destinationPath): bool {
        try {
            $fullPath = $this->absoluteObjectPath($destinationPath);
        } catch (\RuntimeException $e) {
            return false;
        }
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return rename($sourcePath, $fullPath);
    }

    public function delete(string $path): bool {
        try {
            $fullPath = $this->absoluteObjectPath($path);
        } catch (\RuntimeException $e) {
            return false;
        }
        return !file_exists($fullPath) || unlink($fullPath);
    }

    public function deleteVariants(string $path, array $variants = []): bool {
        if ($variants !== []) {
            $success = true;
            foreach ($variants as $variantPath) {
                try {
                    $fullPath = $this->absoluteObjectPath((string)$variantPath);
                } catch (\RuntimeException $e) {
                    return false;
                }

                if (file_exists($fullPath) && !unlink($fullPath)) {
                    $success = false;
                }
            }

            return $success;
        }

        try {
            $normalizedPath = $this->normalizeObjectPath($path);
            $dir = dirname($this->absoluteObjectPath($normalizedPath));
        } catch (\RuntimeException $e) {
            return false;
        }
        if (!is_dir($dir)) return false;

        $pattern = $dir . '/' . pathinfo($normalizedPath, PATHINFO_FILENAME) . '*';
        $files = glob($pattern);
        $success = true;
        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                $success = false;
            }
        }
        return $success;
    }

    public function exists(string $path): bool {
        try {
            return file_exists($this->absoluteObjectPath($path));
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function getUrl(string $path): string {
        try {
            $normalizedPath = $this->normalizeObjectPath($path);
        } catch (\RuntimeException $e) {
            return '';
        }
        $normalized = ltrim($normalizedPath, '/');
        if (str_starts_with($normalized, 'thumbnails/')) {
            $normalized = substr($normalized, strlen('thumbnails/'));
        }

        return Config::get('base_url') . '/thumbnail/' . $normalized;
    }

    public function getAbsolutePath(string $path): string {
        return $this->absoluteObjectPath($path);
    }

    public function getPresignedUrl(string $path, int $expiry = 3600, array $options = []): ?string {
        return null; // Local storage doesn't support direct cloud presigned URLs
    }

    public function stream(string $path, int $seekStart = 0, ?callable $onProgress = null, ?int $maxBytes = null): void {
        try {
            $fullPath = $this->getAbsolutePath($path);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            return;
        }
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
        $chunkSize = 8192;

        // Output file in chunks
        $remaining = $maxBytes;
        while (!feof($fp) && ($remaining === null || $remaining > 0)) {
            $readLength = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
            $data = fread($fp, $readLength);
            if ($data === '' || $data === false) {
                break;
            }
            echo $data;
            flush();
            if (connection_aborted()) {
                break;
            }
            $sentLength = strlen($data);
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

    public function getName(): string {
        return 'Local Storage';
    }

    public function getDescription(): string {
        return 'Standard local file system storage.';
    }

    public function testConnection(): bool {
        return is_dir($this->rootPath) && is_writable($this->rootPath);
    }

    public function getCapabilities(): array {
        return [
            'multipart' => true,
            'presigned_part_upload' => false,
            'app_part_upload' => true,
            'presigned_download' => false,
            'head' => true,
            'completion_validates_part_identity' => false,
            'multipart_etag_case_insensitive' => false,
        ];
    }

    public function createMultipartUpload(string $destinationPath, array $options = []): ?array {
        try {
            $this->normalizeObjectPath($destinationPath);
        } catch (\RuntimeException $e) {
            return null;
        }

        $uploadId = bin2hex(random_bytes(16));
        $dir = $this->multipartRoot($uploadId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return ['upload_id' => $uploadId];
    }

    public function createMultipartPartUrl(string $destinationPath, string $uploadId, int $partNumber, int $expiry = 3600, array $options = []): ?string {
        return null;
    }

    public function listMultipartParts(string $destinationPath, string $uploadId): array {
        try {
            $this->normalizeObjectPath($destinationPath);
        } catch (\RuntimeException $e) {
            return [];
        }

        $partsDir = $this->multipartRoot($uploadId);
        if (!is_dir($partsDir)) {
            return [];
        }

        $parts = [];
        foreach (glob($partsDir . '/part-*.bin') ?: [] as $partPath) {
            if (!is_file($partPath)) {
                continue;
            }

            if (!preg_match('/part-(\d+)\.bin$/', $partPath, $matches)) {
                continue;
            }

            $parts[] = [
                'part_number' => (int)$matches[1],
                'etag' => (string)(hash_file('sha256', $partPath) ?: ''),
                'size' => max(0, (int)(filesize($partPath) ?: 0)),
            ];
        }

        usort($parts, static fn(array $a, array $b): int => (int)$a['part_number'] <=> (int)$b['part_number']);
        return $parts;
    }

    public function completeMultipartUpload(string $destinationPath, string $uploadId, array $parts): bool {
        $partsDir = $this->multipartRoot($uploadId);
        if (!is_dir($partsDir)) {
            return false;
        }

        try {
            $fullPath = $this->absoluteObjectPath($destinationPath);
        } catch (\RuntimeException $e) {
            return false;
        }
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $target = fopen($fullPath, 'wb');
        if (!$target) {
            return false;
        }

        try {
            foreach ($parts as $part) {
                $partPath = $this->multipartPartPath($uploadId, (int)$part['part_number']);
                if (!is_file($partPath)) {
                    fclose($target);
                    return false;
                }

                $source = fopen($partPath, 'rb');
                if (!$source) {
                    fclose($target);
                    return false;
                }

                stream_copy_to_stream($source, $target);
                fclose($source);
            }
        } finally {
            fclose($target);
        }

        $this->removeDirectory($partsDir);
        return true;
    }

    public function abortMultipartUpload(string $destinationPath, string $uploadId): bool {
        try {
            $this->normalizeObjectPath($destinationPath);
        } catch (\RuntimeException $e) {
            return false;
        }

        $partsDir = $this->multipartRoot($uploadId);
        if (!is_dir($partsDir)) {
            return true;
        }

        $this->removeDirectory($partsDir);
        return true;
    }

    public function writeMultipartPart(string $destinationPath, string $uploadId, int $partNumber, $stream): array {
        $this->normalizeObjectPath($destinationPath);
        $partsDir = $this->multipartRoot($uploadId);
        if (!is_dir($partsDir)) {
            mkdir($partsDir, 0755, true);
        }

        $partPath = $this->multipartPartPath($uploadId, $partNumber);
        $target = fopen($partPath, 'wb');
        if (!$target) {
            throw new \RuntimeException('Could not open local part destination for writing.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($target);
                throw new \RuntimeException('Could not read uploaded part data.');
            }
            if ($chunk === '') {
                continue;
            }
            $written = fwrite($target, $chunk);
            if ($written === false) {
                fclose($target);
                throw new \RuntimeException('Could not write uploaded part data.');
            }
            $size += $written;
            hash_update($hash, substr($chunk, 0, $written));
        }
        fclose($target);

        return [
            'etag' => hash_final($hash),
            'part_size' => $size,
        ];
    }

    public function head(string $path): ?array {
        try {
            $normalizedPath = $this->normalizeObjectPath($path);
            $fullPath = $this->getAbsolutePath($normalizedPath);
        } catch (\RuntimeException $e) {
            return null;
        }
        if (!is_file($fullPath)) {
            return null;
        }

        return [
            'path' => $normalizedPath,
            'content_length' => filesize($fullPath),
            'last_modified' => filemtime($fullPath),
            'etag' => md5_file($fullPath),
        ];
    }

    private function normalizeObjectPath(string $path): string
    {
        return StoragePathGuard::normalizeObjectPath($path);
    }

    private function absoluteObjectPath(string $path): string
    {
        return StoragePathGuard::absoluteObjectPath($this->rootPath, $path);
    }

    private function multipartRoot(string $uploadId): string
    {
        return $this->rootPath . '/.multipart-local/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId);
    }

    private function multipartPartPath(string $uploadId, int $partNumber): string
    {
        return $this->multipartRoot($uploadId) . '/part-' . $partNumber . '.bin';
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
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

            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } elseif (is_file($full)) {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
