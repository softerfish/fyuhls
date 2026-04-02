<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Core\StorageManager;
use App\Model\File;

class DownloadManager
{
    private string $secretKey;
    private static bool $rateLimitTableReady = false;

    private function getFileServerRow(?int $fileServerId): ?array
    {
        if (!$fileServerId) {
            return null;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM file_servers WHERE id = ? LIMIT 1");
        $stmt->execute([$fileServerId]);
        $server = $stmt->fetch();

        return $server ?: null;
    }

    private function isBackblazeStyleServer(?array $server): bool
    {
        if (!$server) {
            return false;
        }

        $serverType = strtolower((string)($server['server_type'] ?? ''));
        if (in_array($serverType, ['b2', 'backblaze'], true)) {
            return true;
        }

        $rawConfig = (string)($server['config'] ?? '');
        if ($rawConfig !== '' && !str_starts_with($rawConfig, '{')) {
            try {
                $rawConfig = \App\Service\EncryptionService::decrypt($rawConfig);
            } catch (\Throwable $e) {
                $rawConfig = '{}';
            }
        }

        $config = json_decode($rawConfig, true) ?: [];
        $providerPreset = strtolower((string)($config['provider_preset'] ?? $server['provider_preset'] ?? ''));
        if (in_array($providerPreset, ['b2', 'backblaze'], true)) {
            return true;
        }

        $endpoint = strtolower((string)($config['s3_endpoint'] ?? ''));
        return $endpoint !== '' && str_contains($endpoint, 'backblazeb2.com');
    }

    public function __construct()
    {
        // In production, this should be a strong random key in config
        $this->secretKey = Config::get('app_key', 'change_this_to_a_random_string');
    }

    /**
     * generate a signed temporary download url
     */
    public function generateSignedUrl(int|string $fileId, string $filename, ?string $sessionId = null): string
    {
        $expiry = time() + 3600; // 1 hour expiration
        $ip = \App\Service\SecurityService::getClientIp(); // bind to ip to prevent sharing links

        $sessionPart = $sessionId ?? '';
        $data = "{$fileId}|{$expiry}|{$ip}|{$sessionPart}";
        $signature = hash_hmac('sha256', $data, $this->secretKey);

        $append = \App\Model\Setting::get('upload_append_filename', '0') === '1' ? '/' . urlencode($filename) : '';

        $baseUrl = \App\Service\SeoService::trustedBaseUrl();

        $query = ['token' => $signature, 'expires' => $expiry];
        if ($sessionId !== null && $sessionId !== '') {
            $query['session'] = $sessionId;
        }

        return $baseUrl . "/download/{$fileId}{$append}?" . http_build_query($query);
    }

    private function buildDownloadContentDisposition(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            $filename = 'download';
        }

        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';
        $encoded = rawurlencode($filename);

        return "attachment; filename=\"{$fallback}\"; filename*=UTF-8''{$encoded}";
    }

    public function previewDelivery(array $file): array
    {
        $db = Database::getInstance()->getConnection();
        $provider = StorageManager::getProviderById($file['file_server_id'] ? (int)$file['file_server_id'] : null, $db);
        $capabilities = method_exists($provider, 'getCapabilities') ? $provider->getCapabilities() : [];
        $minPercent = (int)\App\Model\Setting::get('ppd_min_download_percent', '0');
        $server = $this->getFileServerRow($file['file_server_id'] ? (int)$file['file_server_id'] : null);
        $isBackblaze = $this->isBackblazeStyleServer($server);

        if ($minPercent > 0) {
            return [
                'mode' => 'tracked_proxy',
                'url' => null,
                'reason' => 'ppd_progress_tracking_enabled',
            ];
        }

        if (!empty($capabilities['presigned_download'])) {
            if (!$isBackblaze) {
                $presignedUrl = $provider->getPresignedUrl($file['storage_path'], 300, [
                    'response_content_disposition' => $this->buildDownloadContentDisposition((string)($file['filename'] ?? 'download')),
                    'response_content_type' => 'application/octet-stream',
                ]);
                if ($presignedUrl) {
                    return [
                        'mode' => 'signed_origin',
                        'url' => $presignedUrl,
                        'reason' => !empty($file['is_public']) ? 'public_signed_origin' : 'private_signed_origin',
                    ];
                }
            }

            if ($isBackblaze) {
                $deliveryMethod = strtolower((string)($server['delivery_method'] ?? 'php'));
                if (in_array($deliveryMethod, ['apache', 'litespeed'], true)) {
                    $fastPresignedUrl = $provider->getPresignedUrl($file['storage_path'], 300, [
                        'response_content_disposition' => $this->buildDownloadContentDisposition((string)($file['filename'] ?? 'download')),
                    ]);
                    if (!$fastPresignedUrl) {
                        $fastPresignedUrl = $provider->getPresignedUrl($file['storage_path'], 300);
                    }
                    if ($fastPresignedUrl) {
                        return [
                            'mode' => 'signed_origin',
                            'url' => $fastPresignedUrl,
                            'reason' => 'backblaze_fast_signed_origin',
                        ];
                    }
                }

                return [
                    'mode' => 'php_proxy',
                    'url' => null,
                    'reason' => 'backblaze_preserve_filename_via_app_proxy',
                ];
            }

            $cdnUrl = $this->buildCdnDownloadUrl($file);
            if ($cdnUrl !== null) {
                return [
                    'mode' => 'cdn',
                    'url' => $cdnUrl,
                    'reason' => 'public_object_storage_cdn',
                ];
            }

            $presignedUrl = $provider->getPresignedUrl($file['storage_path'], 300);
            if ($presignedUrl) {
                return [
                    'mode' => 'signed_origin',
                    'url' => $presignedUrl,
                    'reason' => !empty($file['is_public']) ? 'public_signed_origin' : 'private_signed_origin',
                ];
            }
        }

        $method = 'php';
        if ($server) {
            $method = (string)($server['delivery_method'] ?? 'php');
        }

        return [
            'mode' => match ($method) {
                'nginx' => 'nginx_accel',
                'apache' => 'apache_xsendfile',
                'litespeed' => 'litespeed_internal',
                default => 'php_proxy',
            },
            'url' => null,
            'reason' => 'app_controlled_delivery',
        ];
    }

    /**
     * validate the signed url
     */
    public function validateSignature(int|string $fileId, string $token, int $expires, ?string $sessionId = null): bool
    {
        if (time() > $expires) {
            return false;
        }
        $ip = \App\Service\SecurityService::getClientIp();
        $data = "{$fileId}|{$expires}|{$ip}|" . ($sessionId ?? '');
        $expected = hash_hmac('sha256', $data, $this->secretKey);

        return hash_equals($expected, $token);
    }

    /**
     * check if request is from a valid source (anti-hotlink / anti-iframe)
     */
    public function validateRequestSource(): bool
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $trustedHost = parse_url(\App\Service\SeoService::trustedBaseUrl(), PHP_URL_HOST) ?: '';

        // if no referer (direct access), we might want to block or allow depending on strictness
        // for ppd (pay per download), strictly require referer from our own site.
        if (empty($referer)) {
            return false;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);

        // use an allowlist from config when available; do not trust host blindly
        $allowedHosts = Config::get('security.allowed_hosts', []);
        if (is_array($allowedHosts) && !empty($allowedHosts)) {
            return in_array($refererHost, $allowedHosts, true);
        }
        // fallback: compare to current host
        return $trustedHost !== '' && $refererHost === $trustedHost;
    }

    /**
     * check rate limit (simple sliding window)
     * limit: 5 downloads per 10 minutes for guests
     */
    public function checkRateLimit(string $ip): bool
    {
        $limit = (int)Config::get('security.rate_limit.download.limit', 5);
        $window = (int)Config::get('security.rate_limit.download.window', 600);
        $currentWindow = floor(time() / $window) * $window;

        $db = \App\Core\Database::getInstance()->getConnection();
        $this->ensureRateLimitTable($db);

        // cleanup old records occasionally (1% chance)
        if (rand(1, 100) === 1) {
            $db->exec("DELETE FROM download_limits WHERE window_start < " . (time() - $window));
        }

        // race condition fix: atomic update
        // instead of read-then-write, we assume write success.
        // if the resulting count > limit, we block.

        $stmt = $db->prepare("
            INSERT INTO download_limits (ip_address, window_start, attempt_count) 
            VALUES (?, ?, 1) 
            ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1
        ");
        $stmt->execute([$ip, $currentWindow]);

        // check new count after atomic increment
        $stmt = $db->prepare("SELECT attempt_count FROM download_limits WHERE ip_address = ? AND window_start = ?");
        $stmt->execute([$ip, $currentWindow]);
        $count = $stmt->fetchColumn();

        if ($count > $limit) {
            return false; // limit exceeded
        }

        return true;
    }

    private function ensureRateLimitTable(\PDO $db): void
    {
        if (self::$rateLimitTableReady) {
            return;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS `download_limits` (
            `ip_address` VARCHAR(45) NOT NULL,
            `window_start` BIGINT UNSIGNED NOT NULL,
            `attempt_count` INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`ip_address`, `window_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::$rateLimitTableReady = true;
    }

    /**
     * verify cloudflare turnstile token
     */
    public function verifyTurnstile(string $token): bool
    {
        $secret = \App\Model\Setting::getEncrypted('captcha_secret_key', Config::get('turnstile.secret_key'));
        if (empty($secret)) {
            return true; // Bypass if not configured
        }
        if ($token === '') {
            return false;
        }

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => \App\Service\SecurityService::getClientIp()
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$result) {
            return false;
        }

        $response = json_decode($result, true);
        return ($response['success'] ?? false) === true;
    }

    private function buildCdnDownloadUrl(array $file): ?string
    {
        if (empty($file['is_public'])) {
            return null;
        }

        $enabled = \App\Model\Setting::get('cdn_download_redirects_enabled', '0') === '1';
        $baseUrl = rtrim((string)\App\Model\Setting::get('cdn_download_base_url', ''), '/');
        if (!$enabled || $baseUrl === '') {
            return null;
        }

        $path = trim((string)($file['storage_path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        return $baseUrl . '/' . $encodedPath;
    }
}
