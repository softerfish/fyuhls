<?php

namespace App\Interface;

interface StorageProvider {
    public function save(string $sourcePath, string $destinationPath): bool;
    public function delete(string $path): bool;
    public function exists(string $path): bool;
    public function getUrl(string $path): string;
    public function getAbsolutePath(string $path): string;
    
    // New methods for Cloud Support
    public function getPresignedUrl(string $path, int $expiry = 3600, array $options = []): ?string;
    public function stream(string $path, int $seekStart = 0, ?callable $onProgress = null, ?int $maxBytes = null): void;
    public function deleteVariants(string $path, array $variants = []): bool;
    public function getName(): string;
    public function getDescription(): string;
    public function testConnection(): bool;
    public function getCapabilities(): array;
    public function createMultipartUpload(string $destinationPath, array $options = []): ?array;
    public function createMultipartPartUrl(string $destinationPath, string $uploadId, int $partNumber, int $expiry = 3600, array $options = []): ?string;
    public function listMultipartParts(string $destinationPath, string $uploadId): array;
    public function completeMultipartUpload(string $destinationPath, string $uploadId, array $parts): bool;
    public function abortMultipartUpload(string $destinationPath, string $uploadId): bool;
    public function head(string $path): ?array;
}
