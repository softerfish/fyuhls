<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Model\Setting;

class SecurityService {
    private const PROXY_LOOKUP_STATUS_CLEAN = 'clean';
    private const PROXY_LOOKUP_STATUS_PROXY = 'proxy';
    private const PROXY_LOOKUP_STATUS_UNAVAILABLE = 'unavailable';
    private const COUNTRY_LOOKUP_STATUS_ALLOWED = 'allowed';
    private const COUNTRY_LOOKUP_STATUS_BLOCKED = 'blocked';
    private const COUNTRY_LOOKUP_STATUS_UNAVAILABLE = 'unavailable';
    private const INSECURE_APP_KEYS = [
        '',
        'change_this_to_a_random_string',
        'REPLACE_DURING_INSTALL',
    ];
    private const CLOUDFLARE_TRUSTED_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function hasSecureAppKey(): bool
    {
        $key = trim((string)Config::get('app_key', ''));
        return $key !== '' && !in_array($key, self::INSECURE_APP_KEYS, true);
    }

    public static function getSecureAppKey(): ?string
    {
        if (!self::hasSecureAppKey()) {
            return null;
        }

        return trim((string)Config::get('app_key', ''));
    }

    public static function getVpnProtectionMode(): string
    {
        $configuredMode = strtolower(trim((string)Setting::get('vpn_proxy_mode', '')));
        $apiKey = trim((string)Setting::getEncrypted('proxycheck_api_key', ''));

        if ($apiKey === '') {
            return 'none';
        }

        if (in_array($configuredMode, ['enforcement', 'intelligence'], true)) {
            return $configuredMode;
        }

        return Setting::get('block_vpn_traffic', '0') === '1' ? 'enforcement' : 'intelligence';
    }

    public static function getVpnProtectionScope(): string
    {
        $scope = strtolower(trim((string)Setting::get('vpn_proxy_scope', 'all_pages')));
        return in_array($scope, ['all_pages', 'download_pages'], true) ? $scope : 'all_pages';
    }

    public static function isHttpsRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
            return true;
        }

        if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
            return true;
        }

        $remoteAddr = self::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        if (!self::isTrustedProxyAddress($remoteAddr)) {
            return false;
        }

        $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === '') {
            return false;
        }

        return explode(',', $forwardedProto)[0] === 'https';
    }

    public static function isLocalDevelopmentRequest(): bool
    {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''))));
        if ($host === '') {
            return false;
        }

        $host = explode(':', $host)[0];
        $hostLooksLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
        if (!$hostLooksLocal) {
            return false;
        }

        $remoteAddr = self::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        return self::ipInCidr($remoteAddr, '127.0.0.0/8') || self::ipInCidr($remoteAddr, '::1/128');
    }

    public static function buildHttpsBootstrapRedirectLocation(string $fallbackPath = '/'): ?string
    {
        $authority = self::safeBootstrapRedirectAuthority();
        if ($authority === null) {
            return null;
        }

        return 'https://' . $authority . self::sanitizeBootstrapRedirectUri($fallbackPath);
    }

    private static function safeBootstrapRedirectAuthority(): ?string
    {
        $candidate = self::parseBootstrapAuthority((string)($_SERVER['HTTP_HOST'] ?? ''))
            ?? self::parseBootstrapAuthority((string)($_SERVER['SERVER_NAME'] ?? ''));
        if ($candidate === null) {
            return null;
        }

        $candidateHost = strtolower((string)($candidate['host'] ?? ''));
        if ($candidateHost === '') {
            return null;
        }

        if (self::bootstrapHostIsLocal($candidateHost)) {
            return self::isLocalDevelopmentRequest()
                ? self::formatBootstrapAuthority($candidateHost, $candidate['port'] ?? null)
                : null;
        }

        $trustedHosts = self::configuredBootstrapTrustedHosts();
        if ($trustedHosts === [] || !in_array($candidateHost, $trustedHosts, true)) {
            return null;
        }

        return self::formatBootstrapAuthority($candidateHost, $candidate['port'] ?? null);
    }

    private static function configuredBootstrapTrustedHosts(): array
    {
        $trustedHosts = [];

        $allowedHosts = Config::get('security.allowed_hosts', []);
        if (is_string($allowedHosts)) {
            $allowedHosts = preg_split('/[\r\n,]+/', $allowedHosts) ?: [];
        }

        if (is_array($allowedHosts)) {
            foreach ($allowedHosts as $allowedHost) {
                $host = strtolower(trim((string)$allowedHost));
                if ($host !== '' && self::bootstrapHostIsValidPublic($host)) {
                    $trustedHosts[] = $host;
                }
            }
        }

        $configuredBaseUrl = trim((string)Config::get('base_url', ''));
        if ($configuredBaseUrl !== '') {
            $configuredHost = strtolower(trim((string)parse_url($configuredBaseUrl, PHP_URL_HOST)));
            if ($configuredHost !== '' && self::bootstrapHostIsValidPublic($configuredHost)) {
                $trustedHosts[] = $configuredHost;
            }
        }

        return array_values(array_unique($trustedHosts));
    }

    private static function sanitizeBootstrapRedirectUri(string $fallbackPath): string
    {
        $fallbackPath = trim($fallbackPath);
        if ($fallbackPath === '' || !str_starts_with($fallbackPath, '/')) {
            $fallbackPath = '/';
        }

        $requestUri = trim((string)($_SERVER['REQUEST_URI'] ?? $fallbackPath));
        if ($requestUri === '' || preg_match('/[\x00-\x1F\x7F]/', $requestUri) === 1) {
            return $fallbackPath;
        }

        if (str_starts_with($requestUri, '/')) {
            return $requestUri;
        }

        $parts = parse_url($requestUri);
        if ($parts === false) {
            return $fallbackPath;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '' || !str_starts_with($path, '/')) {
            return $fallbackPath;
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $path . $query;
    }

    private static function parseBootstrapAuthority(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F\s\/\\\\?#@]/', $value) === 1) {
            return null;
        }

        if (preg_match('/^\[(?<host>[A-Fa-f0-9:]+)\](?::(?<port>\d{1,5}))?$/', $value, $matches) === 1) {
            $host = strtolower(trim((string)($matches['host'] ?? '')));
            $port = isset($matches['port']) && $matches['port'] !== '' ? (int)$matches['port'] : null;
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return null;
            }

            return self::normalizeBootstrapAuthority($host, $port);
        }

        if (preg_match('/^(?<host>[A-Za-z0-9.-]+)(?::(?<port>\d{1,5}))?$/', $value, $matches) !== 1) {
            return null;
        }

        $host = strtolower(trim((string)($matches['host'] ?? '')));
        $port = isset($matches['port']) && $matches['port'] !== '' ? (int)$matches['port'] : null;

        return self::normalizeBootstrapAuthority($host, $port);
    }

    private static function normalizeBootstrapAuthority(string $host, ?int $port): ?array
    {
        if ($host === '') {
            return null;
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        if (
            !self::bootstrapHostIsLocal($host)
            && !self::bootstrapHostIsValidPublic($host)
            && !filter_var($host, FILTER_VALIDATE_IP)
        ) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    private static function formatBootstrapAuthority(string $host, ?int $port): string
    {
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    private static function bootstrapHostIsLocal(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
    }

    private static function bootstrapHostIsValidPublic(string $host): bool
    {
        if ($host === '' || self::bootstrapHostIsLocal($host)) {
            return false;
        }

        return (bool)preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)
            || (bool)filter_var($host, FILTER_VALIDATE_IP);
    }

    public static function isTrustedProxyAddress(string $ip): bool
    {
        return self::isTrustedProxy(self::normalizeIp($ip));
    }


    private static array $runtimeCache = [];
    private static array $runtimeIntelCache = [];
    private static ?array $trustedProxyRangesCache = null;
    private static $proxyLookupHttpHandler = null;
    private static $countryLookupHttpHandler = null;

    /**
     * Normalize an IP address to its standard representation (v4 or v6).
     */
    public static function normalizeIp(string $ip): string {
        $binaryIp = @inet_pton($ip);
        return $binaryIp !== false ? inet_ntop($binaryIp) : $ip;
    }

    /**
     * @internal Test-only override for ProxyCheck lookups.
     */
    public static function setProxyLookupHttpHandlerForTests(?callable $handler): void
    {
        self::$proxyLookupHttpHandler = $handler;
    }

    /**
     * @internal Test-only override for country lookups.
     */
    public static function setCountryLookupHttpHandlerForTests(?callable $handler): void
    {
        self::$countryLookupHttpHandler = $handler;
    }

    /**
     * @param array{lookup_status?: string} $intel
     */
    public static function proxyIntelRequiresFailClosed(array $intel): bool
    {
        return (string)($intel['lookup_status'] ?? '') === self::PROXY_LOOKUP_STATUS_UNAVAILABLE;
    }

    /**
     * Check if IP is a VPN/Proxy. Thin wrapper around lookupProxyIntel.
     */
    public function isVpnOrProxy(string $ip): bool {
        $intel = $this->lookupProxyIntel($ip);
        return !empty($intel['is_proxy']);
    }

    /**
     * Query ProxyCheck and return structured intelligence data.
     * Results are cached for 24 hours in security_cache.
     *
     * @return array{is_proxy: bool, type: string|null, provider: string|null, last_seen: string|null, risk: int, lookup_status: string}
     */
    public function lookupProxyIntel(string $ip): array {
        $ip = self::normalizeIp($ip);

        $cleanIntel = self::buildProxyIntelResult(false, null, null, null, 0, self::PROXY_LOOKUP_STATUS_CLEAN);
        $unavailableIntel = self::buildProxyIntelResult(false, null, null, null, 0, self::PROXY_LOOKUP_STATUS_UNAVAILABLE);

        // runtime cache so a single request never hits the API twice
        if (isset(self::$runtimeIntelCache[$ip])) {
            return self::$runtimeIntelCache[$ip];
        }

        if ($ip === '127.0.0.1' || $ip === '::1') {
            return self::$runtimeIntelCache[$ip] = $cleanIntel;
        }

        // never flag the reverse proxy itself
        if (self::isTrustedProxy($ip)) {
            return self::$runtimeIntelCache[$ip] = $cleanIntel;
        }

        // admin whitelist
        if ($this->isWhitelisted($ip)) {
            return self::$runtimeIntelCache[$ip] = $cleanIntel;
        }

        $db = Database::getInstance()->getConnection();
        $lookupKey = self::buildProxyCacheLookupKey($ip);

        // check database cache (24 hour TTL)
        if ($lookupKey !== null) {
            try {
                $stmt = $db->prepare("SELECT is_vpn, proxy_intel_json FROM security_cache WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
                $stmt->execute([$lookupKey]);
                $cached = $stmt->fetch();
                if ($cached !== false) {
                    return self::$runtimeIntelCache[$ip] = self::decodeCachedProxyIntel($cached);
                }
            } catch (\Exception $e) {
            }
        }

        // negative quota cache - API known-down, fail-closed for protected actions
        $apiDownKey = 'proxycheck_api_unavailable';
        $apiDownUntil = (int)Setting::get($apiDownKey, '0');
        if ($apiDownUntil > time()) {
            error_log("PROXY_INTEL: skipping check for $ip, API cached as unavailable until " . date('H:i:s', $apiDownUntil));
            return self::$runtimeIntelCache[$ip] = $unavailableIntel;
        }

        // hit ProxyCheck API if configured
        $apiKey = Setting::getEncrypted('proxycheck_api_key', '');
        $intel = $cleanIntel;

        if ($apiKey) {
            $url = "https://proxycheck.io/v2/{$ip}?key={$apiKey}&vpn=1&asn=1&risk=1";
            ['response' => $response, 'error' => $err, 'http_code' => $httpCode] = self::executeProxyLookupRequest($url, $ip);

            if ($response !== false) {
                $data = json_decode($response, true);
                if (!is_array($data)) {
                    error_log("PROXY_INTEL: invalid JSON response for $ip");
                    return self::$runtimeIntelCache[$ip] = $unavailableIntel;
                }

                // handle API errors / quota limits
                if (isset($data['status']) && $data['status'] === 'error') {
                    $message = (string)($data['message'] ?? 'Unknown');
                    if (str_contains($message, 'Queries per day exceeded')) {
                        error_log("PROXY_INTEL: quota exceeded for ProxyCheck.io, caching failure for 1 hour.");
                        Setting::set($apiDownKey, (string)(time() + 3600), 'security');
                    } else {
                        error_log("PROXY_INTEL: ProxyCheck.io error for $ip: " . $message);
                    }

                    return self::$runtimeIntelCache[$ip] = $unavailableIntel;
                }

                if (isset($data[$ip]) && is_array($data[$ip])) {
                    $ipData = $data[$ip];
                    $isProxy = isset($ipData['proxy']) && $ipData['proxy'] === 'yes';
                    $intel = self::buildProxyIntelResult(
                        $isProxy,
                        isset($ipData['type']) ? substr((string)$ipData['type'], 0, 32) : null,
                        isset($ipData['provider']) ? substr((string)$ipData['provider'], 0, 128) : null,
                        isset($ipData['last seen']) ? substr((string)$ipData['last seen'], 0, 64) : null,
                        isset($ipData['risk']) ? max(0, min(100, (int)$ipData['risk'])) : 0,
                        $isProxy ? self::PROXY_LOOKUP_STATUS_PROXY : self::PROXY_LOOKUP_STATUS_CLEAN
                    );
                    if ($isProxy) {
                        error_log("PROXY_INTEL: detected proxy for $ip (type: " . ($intel['type'] ?? 'unknown') . ", risk: " . $intel['risk'] . ")");
                    }
                } else {
                    error_log("PROXY_INTEL: missing IP payload in response for $ip");
                    return self::$runtimeIntelCache[$ip] = $unavailableIntel;
                }
            } else {
                error_log("PROXY_INTEL: cURL error for $ip: $err (HTTP: $httpCode)");
                return self::$runtimeIntelCache[$ip] = $unavailableIntel;
            }
        } else {
            return self::$runtimeIntelCache[$ip] = $unavailableIntel;
        }

        if ($lookupKey !== null) {
            try {
                $intelJson = json_encode([
                    'type' => $intel['type'],
                    'provider' => $intel['provider'],
                    'last_seen' => $intel['last_seen'],
                    'risk' => $intel['risk'],
                    'lookup_status' => $intel['lookup_status'],
                ], JSON_UNESCAPED_SLASHES);
                $db->prepare("DELETE FROM security_cache WHERE ip_address = ?")->execute([$lookupKey]);
                $db->prepare("INSERT INTO security_cache (ip_address, is_vpn, proxy_intel_json) VALUES (?, ?, ?)")->execute([$lookupKey, (int)$intel['is_proxy'], $intelJson]);
            } catch (\Exception $e) {
            }
        }

        return self::$runtimeIntelCache[$ip] = $intel;
    }

    /**
     * Check if IP is in the admin-defined whitelist
     */
    public function isWhitelisted(string $ip): bool {
        $whitelist = Setting::get('vpn_whitelist', '');
        if (empty($whitelist)) return false;

        $ips = array_filter(array_map('trim', explode("\n", $whitelist)));
        foreach ($ips as $entry) {
            if (self::ipInCidr($ip, $entry)) return true;
        }
        return false;
    }

    public function getAntiAdblockScript(): string {
        return <<<JS
<style>
    #ad-block-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:9999; color:white; text-align:center; padding-top:20%; }
    #ad-block-modal h2 { font-size: 2rem; margin-bottom: 1rem; color: #ef4444; }
    #ad-block-reload-btn { padding:10px 20px; background:#2563eb; color:white; border-radius:4px; cursor:pointer; margin-top:20px; }
    body.ad-blocked { overflow: hidden; }
</style>
<div id="ad-block-modal">
    <h2>Adblock Detected!</h2>
    <p>Please disable your ad blocker to download this file.</p>
    <p>We rely on ads to keep this service free.</p>
    <button id="ad-block-reload-btn" type="button">I've Disabled It</button>
</div>
<script>
    const reloadButton = document.getElementById('ad-block-reload-btn');
    if (reloadButton) {
        reloadButton.addEventListener('click', function() {
            window.location.reload();
        });
    }

    window.addEventListener('load', function() {
        const testAd = document.createElement('div');
        testAd.innerHTML = '&nbsp;';
        testAd.className = 'adsbox';
        testAd.style.position = 'absolute';
        testAd.style.top = '-1000px';
        document.body.appendChild(testAd);

        window.setTimeout(function() {
            if (testAd.offsetHeight === 0) {
                document.getElementById('ad-block-modal').style.display = 'block';
                document.body.classList.add('ad-blocked');
            }
            testAd.remove();
        }, 100);
    });
</script>
JS;
    }

    public function isCountryBlocked(string $ip): bool {
        return $this->evaluateCountryBlock($ip)['blocked'];
    }

    /**
     * @return array{blocked: bool, country_code: ?string, status: string}
     */
    public function evaluateCountryBlock(string $ip): array
    {
        $ip = self::normalizeIp($ip);
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return [
                'blocked' => false,
                'country_code' => null,
                'status' => self::COUNTRY_LOOKUP_STATUS_ALLOWED,
            ];
        }

        $blockedList = Setting::get('blocked_download_countries', '');
        if (empty($blockedList)) {
            return [
                'blocked' => false,
                'country_code' => null,
                'status' => self::COUNTRY_LOOKUP_STATUS_ALLOWED,
            ];
        }

        $blockedArr = array_filter(array_map('trim', explode(',', strtoupper($blockedList))));
        $url = "https://ip-api.com/json/{$ip}?fields=status,countryCode";
        ['response' => $response] = self::executeCountryLookupRequest($url, $ip);

        if ($response === false) {
            return [
                'blocked' => false,
                'country_code' => null,
                'status' => self::COUNTRY_LOOKUP_STATUS_UNAVAILABLE,
            ];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [
                'blocked' => false,
                'country_code' => null,
                'status' => self::COUNTRY_LOOKUP_STATUS_UNAVAILABLE,
            ];
        }

        $countryCode = strtoupper(trim((string)($data['countryCode'] ?? '')));
        if ($countryCode === '' || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            return [
                'blocked' => false,
                'country_code' => null,
                'status' => self::COUNTRY_LOOKUP_STATUS_UNAVAILABLE,
            ];
        }

        $blocked = in_array($countryCode, $blockedArr, true);

        return [
            'blocked' => $blocked,
            'country_code' => $countryCode,
            'status' => $blocked ? self::COUNTRY_LOOKUP_STATUS_BLOCKED : self::COUNTRY_LOOKUP_STATUS_ALLOWED,
        ];
    }

    /**
     * @param array{status?: string} $decision
     */
    public static function countryLookupRequiresFailClosed(array $decision): bool
    {
        return (string)($decision['status'] ?? '') === self::COUNTRY_LOOKUP_STATUS_UNAVAILABLE;
    }

    /**
     * Get the client's actual IP address securely.
     */
    public static function getClientIp(): string {
        $remoteAddr = self::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));

        if (Setting::get('trust_cloudflare', '0') === '1' && self::isTrustedProxy($remoteAddr)) {
            // 1. Try Cloudflare specific header
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $ip = self::normalizeIp(trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0]));
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
            // 2. Try X-Forwarded-For (take the leftmost public, non-trusted hop)
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = self::leftmostPublicForwardedIp((string)$_SERVER['HTTP_X_FORWARDED_FOR']);
                if ($ip !== null) {
                    return $ip;
                }
            }
        }

        return $remoteAddr;
    }

    private static function leftmostPublicForwardedIp(string $headerValue): ?string
    {
        // X-Forwarded-For is ordered as client -> proxy -> proxy, so keep the original order.
        foreach (array_map('trim', explode(',', $headerValue)) as $ip) {
            $normalized = self::normalizeIp($ip);
            if (!filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }
            if (!self::isTrustedProxy($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Check if an IP belongs to a trusted proxy range (DB-backed)
     */
    private static function isTrustedProxy(string $ip): bool {
        foreach (self::trustedProxyRanges() as $range) {
            if (self::ipInCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function trustedProxyRanges(): array
    {
        if (is_array(self::$trustedProxyRangesCache)) {
            return self::$trustedProxyRangesCache;
        }

        $ranges = self::configuredTrustedProxyRanges();

        try {
            $db = Database::getInstance()->getConnection();
            if ($db) {
                $stmt = $db->query("SELECT ip_range FROM trusted_proxies WHERE is_active = 1");
                $dbRanges = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                foreach ($dbRanges as $range) {
                    $range = trim((string)$range);
                    if ($range !== '') {
                        $ranges[] = $range;
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('Trusted proxy range storage unavailable; using configured fallback ranges only.');
        }

        self::$trustedProxyRangesCache = array_values(array_unique($ranges));
        return self::$trustedProxyRangesCache;
    }

    private static function configuredTrustedProxyRanges(): array
    {
        $ranges = [];

        if (Setting::get('trust_loopback_proxy_headers', '0') === '1') {
            $ranges[] = '127.0.0.0/8';
            $ranges[] = '::1/128';
        }

        $configured = Config::get('security.trusted_proxy_ranges', []);
        if (is_string($configured)) {
            $configured = preg_split('/[\r\n,]+/', $configured) ?: [];
        }
        if (is_array($configured)) {
            foreach ($configured as $range) {
                $range = trim((string)$range);
                if ($range !== '') {
                    $ranges[] = $range;
                }
            }
        }

        if (Setting::get('trust_cloudflare', '0') === '1') {
            $ranges = array_merge($ranges, self::CLOUDFLARE_TRUSTED_RANGES);
        }

        return $ranges;
    }

    /**
     * IPv4 and IPv6 compatible CIDR check
     */
    public static function ipInCidr(string $ip, string $cidr): bool {
        if (strpos($cidr, '/') === false) return $ip === $cidr;

        [$subnet, $mask] = explode('/', $cidr);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskBin = ~((1 << (32 - (int)$mask)) - 1);
            return ($ipLong & $maskBin) === ($subnetLong & $maskBin);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return false;

            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            $maskInt = (int)$mask;

            for ($i = 0; $i < 16; $i++) {
                $bitMask = 0;
                if ($maskInt >= 8) {
                    $bitMask = 0xFF;
                    $maskInt -= 8;
                } elseif ($maskInt > 0) {
                    $bitMask = (0xFF << (8 - $maskInt)) & 0xFF;
                    $maskInt = 0;
                }

                if ((ord($ipBin[$i]) & $bitMask) !== (ord($subnetBin[$i]) & $bitMask)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    public static function normalizeRemoteDestinationIp(string $ip): string
    {
        $ip = self::normalizeIp($ip);
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mapped;
            }
        }

        return $ip;
    }

    public static function isAllowedRemoteDestinationIp(string $ip): bool
    {
        $ip = self::normalizeRemoteDestinationIp($ip);
        $blockedRanges = [
            '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
            '169.254.0.0/16', '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24',
            '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
            '224.0.0.0/4', '240.0.0.0/4', '::1/128', 'fc00::/7', 'fe80::/10', '2001:db8::/32',
        ];

        foreach ($blockedRanges as $range) {
            if (self::ipInCidr($ip, $range)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function resolveApprovedRemoteDestinationIps(?string $host): array
    {
        $host = trim((string)$host);
        if ($host === '') {
            return [];
        }

        $trimmedLiteral = trim($host, '[]');
        if (filter_var($trimmedLiteral, FILTER_VALIDATE_IP)) {
            $normalized = self::normalizeRemoteDestinationIp($trimmedLiteral);
            return self::isAllowedRemoteDestinationIp($normalized) ? [$normalized] : [];
        }

        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $approved = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$ip || !self::isAllowedRemoteDestinationIp($ip)) {
                continue;
            }
            $approved[] = self::normalizeRemoteDestinationIp($ip);
        }

        return array_values(array_unique($approved));
    }

    /**
     * Remove old security cache entries to prevent table bloat.
     */
    public function purgeCache(int $days = 30): int {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM security_cache WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @return array{is_proxy: bool, type: string|null, provider: string|null, last_seen: string|null, risk: int, lookup_status: string}
     */
    private static function buildProxyIntelResult(bool $isProxy, ?string $type, ?string $provider, ?string $lastSeen, int $risk, string $status): array
    {
        return [
            'is_proxy' => $isProxy,
            'type' => $type,
            'provider' => $provider,
            'last_seen' => $lastSeen,
            'risk' => max(0, min(100, $risk)),
            'lookup_status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $cached
     * @return array{is_proxy: bool, type: string|null, provider: string|null, last_seen: string|null, risk: int, lookup_status: string}
     */
    private static function decodeCachedProxyIntel(array $cached): array
    {
        $status = (bool)($cached['is_vpn'] ?? false) ? self::PROXY_LOOKUP_STATUS_PROXY : self::PROXY_LOOKUP_STATUS_CLEAN;
        $intel = self::buildProxyIntelResult((bool)($cached['is_vpn'] ?? false), null, null, null, 0, $status);
        if (!empty($cached['proxy_intel_json'])) {
            $decoded = json_decode((string)$cached['proxy_intel_json'], true);
            if (is_array($decoded)) {
                $intel = array_merge($intel, [
                    'type' => isset($decoded['type']) ? (string)$decoded['type'] : null,
                    'provider' => isset($decoded['provider']) ? (string)$decoded['provider'] : null,
                    'last_seen' => isset($decoded['last_seen']) ? (string)$decoded['last_seen'] : null,
                    'risk' => isset($decoded['risk']) ? max(0, min(100, (int)$decoded['risk'])) : 0,
                    'lookup_status' => isset($decoded['lookup_status']) ? (string)$decoded['lookup_status'] : $status,
                ]);
            }
        }

        $intel['is_proxy'] = (bool)($cached['is_vpn'] ?? false);
        if ($intel['lookup_status'] === '') {
            $intel['lookup_status'] = $status;
        }

        return $intel;
    }

    private static function buildProxyCacheLookupKey(string $ip): ?string
    {
        $secret = trim((string)Config::get('security.encryption_key', ''));
        if ($secret === '') {
            $secret = trim((string)Config::get('app_key', ''));
        }

        if ($secret === '') {
            return null;
        }

        return 'ip-hmac:' . hash_hmac('sha256', self::normalizeIp($ip), $secret);
    }

    /**
     * @return array{response: string|false, error: string, http_code: int}
     */
    private static function executeProxyLookupRequest(string $url, string $ip): array
    {
        if (is_callable(self::$proxyLookupHttpHandler)) {
            $result = call_user_func(self::$proxyLookupHttpHandler, $url, $ip);
            if (!is_array($result)) {
                return ['response' => false, 'error' => 'Invalid test HTTP handler response.', 'http_code' => 0];
            }

            return [
                'response' => array_key_exists('response', $result) ? $result['response'] : false,
                'error' => isset($result['error']) ? (string)$result['error'] : '',
                'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : 0,
            ];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Fyuhls/1.0 (ProxyIntel)');

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'response' => $response,
            'error' => $err !== false ? (string)$err : '',
            'http_code' => (int)$httpCode,
        ];
    }

    /**
     * @return array{response: string|false, error: string, http_code: int}
     */
    private static function executeCountryLookupRequest(string $url, string $ip): array
    {
        if (is_callable(self::$countryLookupHttpHandler)) {
            $result = call_user_func(self::$countryLookupHttpHandler, $url, $ip);
            if (!is_array($result)) {
                return ['response' => false, 'error' => 'Invalid test HTTP handler response.', 'http_code' => 0];
            }

            return [
                'response' => array_key_exists('response', $result) ? $result['response'] : false,
                'error' => isset($result['error']) ? (string)$result['error'] : '',
                'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : 0,
            ];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FileHosting/1.0 (Geo-Blocker)');
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'response' => $response,
            'error' => $err !== false ? (string)$err : '',
            'http_code' => (int)$httpCode,
        ];
    }
}
