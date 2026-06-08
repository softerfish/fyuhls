<?php

namespace App\Service\Storage;

use App\Interface\StorageProvider;
use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use Aws\S3\Exception\S3Exception;

/**
 * Generic S3-compatible provider (works with Backblaze B2, Wasabi, AWS, etc.)
 * Built from a file_servers row via ServerProviderFactory.
 */
class S3StorageProvider implements StorageProvider {
    private S3Client $client;
    private string $bucket;
    private string $publicUrl;
    private bool $purgeVersionsOnDelete;

    public function __construct(S3Client $client, string $bucket, string $publicUrl = '', bool $purgeVersionsOnDelete = false) {
        $this->client    = $client;
        $this->bucket    = $bucket;
        $this->publicUrl = rtrim($publicUrl, '/');
        $this->purgeVersionsOnDelete = $purgeVersionsOnDelete;
    }

    // handles small files inline and large files via multipart automatically
    public function save(string $sourcePath, string $destinationPath): bool {
        if (!extension_loaded('curl')) {
            error_log('[S3StorageProvider] curl extension not loaded');
            return false;
        }
        try {
            $destinationPath = $this->normalizeObjectKey($destinationPath);
            set_time_limit(60 * 60 * 6);
            ini_set('memory_limit', '512M');

            $source   = fopen($sourcePath, 'rb');
            $uploader = new ObjectUploader($this->client, $this->bucket, $destinationPath, $source);
            $result   = $uploader->upload();
            if (is_resource($source)) {
                fclose($source);
            }

            $statusCode = (int)($result['@metadata']['statusCode'] ?? 0);
            return in_array($statusCode, [200, 201, 204]);
        } catch (\Exception $e) {
            error_log('[S3StorageProvider] upload failed: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(string $path): bool {
        try {
            $key = $this->normalizeObjectKey($path);

            if ($this->purgeVersionsOnDelete) {
                return $this->deleteAllVersions($key);
            }

            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey' || $e->getAwsErrorCode() === 'NotFound' || $e->getStatusCode() === 404) {
                return true; // Idempotent
            }
            error_log('[S3StorageProvider] delete failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteVariants(string $path, array $variants = []): bool {
        $success = true;
        foreach ($variants as $variantPath) {
            if (!$this->delete($variantPath)) {
                $success = false;
            }
        }
        return $success;
    }

    public function exists(string $path): bool {
        try {
            return $this->client->doesObjectExist($this->bucket, $this->normalizeObjectKey($path));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getUrl(string $path): string {
        try {
            $path = $this->normalizeObjectKey($path);
        } catch (\RuntimeException $e) {
            return '';
        }

        if ($this->publicUrl) {
            return $this->publicUrl . '/' . ltrim($path, '/');
        }
        // fall back to proxy
        return '/download/proxy/' . base64_encode($path);
    }

    public function getAbsolutePath(string $path): string {
        return $this->normalizeObjectKey($path);
    }

    public function getPresignedUrl(string $path, int $expiry = 3600, array $options = []): ?string {
        try {
            $path = $this->normalizeObjectKey($path);
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $path,
            ];

            if (!empty($options['response_content_disposition'])) {
                $params['ResponseContentDisposition'] = (string)$options['response_content_disposition'];
            }
            if (!empty($options['response_content_type'])) {
                $params['ResponseContentType'] = (string)$options['response_content_type'];
            }

            $cmd     = $this->client->getCommand('GetObject', $params);
            $request = $this->client->createPresignedRequest($cmd, '+' . $expiry . ' seconds');
            return (string) $request->getUri();
        } catch (S3Exception $e) {
            error_log('[S3StorageProvider] presign failed: ' . $e->getMessage());
            return null;
        }
    }

    // seekable streaming for download proxying
    public function stream(string $path, int $seekStart = 0, ?callable $onProgress = null, ?int $maxBytes = null): void {
        try {
            $path = $this->normalizeObjectKey($path);
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $path,
                '@http' => ['stream' => true],
            ];

            if ($seekStart > 0 || $maxBytes !== null) {
                $rangeStart = max(0, $seekStart);
                $rangeEnd = $maxBytes === null ? '' : max($rangeStart, $rangeStart + $maxBytes - 1);
                $params['Range'] = 'bytes=' . $rangeStart . '-' . $rangeEnd;
            }

            $result = $this->client->getObject($params);
            $body = $result['Body'] ?? null;
            if (!$body) {
                http_response_code(404);
                return;
            }

            $totalSent = $seekStart;
            $chunkSize = 65536;

            $remaining = $maxBytes;
            while ((!method_exists($body, 'eof') || !$body->eof()) && ($remaining === null || $remaining > 0)) {
                $readLength = $remaining === null ? $chunkSize : min($chunkSize, $remaining);
                $data = $body->read($readLength);
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

            if (method_exists($body, 'close')) {
                $body->close();
            }
        } catch (S3Exception $e) {
            error_log('[S3StorageProvider] stream failed: ' . $e->getMessage());
            http_response_code(500);
        }
    }

    private function deleteAllVersions(string $key): bool {
        $key = $this->normalizeObjectKey($key);
        $keyMarker = null;
        $versionMarker = null;
        $foundAny = false;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Prefix' => $key,
                'MaxKeys' => 1000,
            ];

            if ($keyMarker !== null) {
                $params['KeyMarker'] = $keyMarker;
            }
            if ($versionMarker !== null) {
                $params['VersionIdMarker'] = $versionMarker;
            }

            $result = $this->client->listObjectVersions($params);

            $entries = [];
            foreach (($result['Versions'] ?? []) as $version) {
                if (($version['Key'] ?? '') === $key && !empty($version['VersionId'])) {
                    $entries[] = (string)$version['VersionId'];
                }
            }
            foreach (($result['DeleteMarkers'] ?? []) as $marker) {
                if (($marker['Key'] ?? '') === $key && !empty($marker['VersionId'])) {
                    $entries[] = (string)$marker['VersionId'];
                }
            }

            foreach (array_unique($entries) as $versionId) {
                $foundAny = true;
                $this->client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'VersionId' => $versionId,
                ]);
            }

            $truncated = (bool)($result['IsTruncated'] ?? false);
            $keyMarker = $truncated ? ($result['NextKeyMarker'] ?? null) : null;
            $versionMarker = $truncated ? ($result['NextVersionIdMarker'] ?? null) : null;
        } while ($truncated);

        if ($foundAny) {
            return true;
        }

        // Fall back to a normal delete so the call remains idempotent if the key is already absent.
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        return true;
    }

    public function getName(): string { return 'S3 Compatible Storage'; }
    public function getDescription(): string { return 'S3-compatible cloud storage (Backblaze B2, Wasabi, AWS, etc.)'; }
    public function testConnection(): bool {
        try {
            $this->client->listObjectsV2([
                'Bucket'  => $this->bucket,
                'MaxKeys' => 1
            ]);
            return true;
        } catch (\Exception $e) {
            error_log('[S3StorageProvider] connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getCapabilities(): array {
        return [
            'multipart' => true,
            'presigned_part_upload' => true,
            'presigned_download' => true,
            'head' => true,
            'completion_validates_part_identity' => true,
            'multipart_etag_case_insensitive' => false,
        ];
    }

    public function createMultipartUpload(string $destinationPath, array $options = []): ?array {
        try {
            $destinationPath = $this->normalizeObjectKey($destinationPath);
            $params = array_merge([
                'Bucket' => $this->bucket,
                'Key' => $destinationPath,
            ], $options);
            $result = $this->client->createMultipartUpload($params);
            return [
                'upload_id' => (string)($result['UploadId'] ?? ''),
                'key' => (string)($result['Key'] ?? $destinationPath),
            ];
        } catch (\Exception $e) {
            error_log('[S3StorageProvider] createMultipartUpload failed: ' . $e->getMessage());
            return null;
        }
    }

    public function createMultipartPartUrl(string $destinationPath, string $uploadId, int $partNumber, int $expiry = 3600, array $options = []): ?string {
        try {
            $destinationPath = $this->normalizeObjectKey($destinationPath);
            $cmd = $this->client->getCommand('UploadPart', array_merge([
                'Bucket' => $this->bucket,
                'Key' => $destinationPath,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ], $options));
            $request = $this->client->createPresignedRequest($cmd, '+' . $expiry . ' seconds');
            return (string)$request->getUri();
        } catch (\Exception $e) {
            error_log('[S3StorageProvider] createMultipartPartUrl failed: ' . $e->getMessage());
            return null;
        }
    }

    public function listMultipartParts(string $destinationPath, string $uploadId): array {
        try {
            $destinationPath = $this->normalizeObjectKey($destinationPath);
            $parts = [];
            $partNumberMarker = null;
            $seenMarkers = [];

            do {
                $params = [
                    'Bucket' => $this->bucket,
                    'Key' => $destinationPath,
                    'UploadId' => $uploadId,
                ];
                if ($partNumberMarker !== null) {
                    $params['PartNumberMarker'] = $partNumberMarker;
                }

                $result = $this->client->listParts($params);
                foreach (($result['Parts'] ?? []) as $part) {
                    $parts[] = [
                        'part_number' => (int)($part['PartNumber'] ?? 0),
                        'etag' => trim((string)($part['ETag'] ?? ''), '"'),
                        'size' => (int)($part['Size'] ?? 0),
                    ];
                }

                $isTruncated = (bool)($result['IsTruncated'] ?? false);
                $nextMarker = $isTruncated ? (string)($result['NextPartNumberMarker'] ?? '') : '';
                if ($isTruncated && ($nextMarker === '' || $nextMarker === (string)$partNumberMarker || isset($seenMarkers[$nextMarker]))) {
                    throw new \RuntimeException('S3 multipart listing cursor did not advance.');
                }

                if ($isTruncated) {
                    $seenMarkers[$nextMarker] = true;
                    $partNumberMarker = $nextMarker;
                }
            } while ($isTruncated);

            usort($parts, static fn(array $a, array $b): int => (int)$a['part_number'] <=> (int)$b['part_number']);

            return $parts;
        } catch (\Exception $e) {
            error_log('[S3StorageProvider] listMultipartParts failed: ' . $e->getMessage());
            return [];
        }
    }

    public function completeMultipartUpload(string $destinationPath, string $uploadId, array $parts): bool {
        try {
            $destinationPath = $this->normalizeObjectKey($destinationPath);
            $awsParts = [];
            foreach ($parts as $part) {
                $awsParts[] = [
                    'ETag' => $part['etag'],
                    'PartNumber' => (int)$part['part_number'],
                ];
            }

            $result = $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $destinationPath,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $awsParts],
            ]);

            $statusCode = (int)($result['@metadata']['statusCode'] ?? 0);
            return in_array($statusCode, [200, 201, 204], true);
        } catch (\Exception $e) {
            error_log('[S3StorageProvider] completeMultipartUpload failed: ' . $e->getMessage());
            return false;
        }
    }

    public function abortMultipartUpload(string $destinationPath, string $uploadId): bool {
        try {
            $destinationPath = $this->normalizeObjectKey($destinationPath);
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $destinationPath,
                'UploadId' => $uploadId,
            ]);
            return true;
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'NoSuchUpload') !== false) {
                // Treat already-finished or already-aborted uploads as a successful cleanup outcome.
                return true;
            }
            error_log('[S3StorageProvider] abortMultipartUpload failed: ' . $e->getMessage());
            return false;
        }
    }

    public function head(string $path): ?array {
        try {
            $path = $this->normalizeObjectKey($path);
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $path,
            ]);

            return [
                'path' => $path,
                'content_length' => (int)($result['ContentLength'] ?? 0),
                'content_type' => (string)($result['ContentType'] ?? ''),
                'etag' => trim((string)($result['ETag'] ?? ''), '"'),
                'last_modified' => isset($result['LastModified']) ? strtotime((string)$result['LastModified']) : null,
            ];
        } catch (\Exception $e) {
            error_log('[S3StorageProvider] head failed: ' . $e->getMessage());
            return null;
        }
    }

    private function normalizeObjectKey(string $path): string
    {
        return StoragePathGuard::normalizeObjectPath($path, 'S3 object key');
    }
}
