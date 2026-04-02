<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Model\Setting;

class SecurityService {
    
    private static array $runtimeCache = [];

    /**
     * Normalize an IP address to its standard representation (v4 or v6).
     */
    public static function normalizeIp(string $ip): string {
        $binaryIp = @inet_pton($ip);
        return $binaryIp !== false ? inet_ntop($binaryIp) : $ip;
    }

    /**
     * Check if IP is a VPN/Proxy using external API or local database
     */
    public function isVpnOrProxy(string $ip): bool {
        $ip = self::normalizeIp($ip);

        // 1. Static Runtime Cache (Single Request Performance)
        if (isset(self::$runtimeCache[$ip])) {
            return self::$runtimeCache[$ip];
        }

        if ($ip === '127.0.0.1' || $ip === '::1') {
            return self::$runtimeCache[$ip] = false;
        }

        // 2. Proxy Check (Never block the proxy itself - prevents site-wide lockout)
        if (self::isTrustedProxy($ip)) {
            return self::$runtimeCache[$ip] = false;
        }

        // 3. Admin Whitelist Check
        if ($this->isWhitelisted($ip)) {
            return self::$runtimeCache[$ip] = false;
        }

        $db = Database::getInstance()->getConnection();
        $encIp = \App\Service\EncryptionService::encrypt($ip);

        // 1. Check Cache (24 hour TTL)
        try {
            $stmt = $db->prepare("SELECT is_vpn FROM security_cache WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
            $stmt->execute([$encIp]);
            $cached = $stmt->fetch();
            if ($cached !== false) {
                return (bool)$cached['is_vpn'];
            }
        } catch (\Exception $e) { }

        // 3. Negative Quota Caching (Protection against API exhaustion)
        $apiDownKey = 'proxycheck_api_unavailable';
        $apiDownUntil = (int)Setting::get($apiDownKey, '0');
        if ($apiDownUntil > time()) {
            error_log("VPN_BLOCK: Skipping check for $ip because API is cached as unavailable/exhausted until " . date('H:i:s', $apiDownUntil));
            return self::$runtimeCache[$ip] = false; // API is known-down, fail-soft allow
        }

        // 4. Not in cache, hit API if configured
        $apiKey = Setting::getEncrypted('proxycheck_api_key', '');
        $isVpn = false;

        if ($apiKey) {
            $url = "https://proxycheck.io/v2/{$ip}?key={$apiKey}&vpn=1&asn=1";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Keep security high
            curl_setopt($ch, CURLOPT_USERAGENT, 'FileHosting/1.0 (VPN-Blocker)');
            
            $response = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false) {
                $data = json_decode($response, true);
                
                // Handle API Errors / Quota Limits
                if (isset($data['status']) && $data['status'] === 'error') {
                    if (str_contains($data['message'], 'Queries per day exceeded')) {
                        error_log("VPN_BLOCK: Quota exceeded for ProxyCheck.io. Caching failure for 1 hour.");
                        Setting::set($apiDownKey, (string)(time() + 3600), 'security');
                        return self::$runtimeCache[$ip] = false;
                    } else {
                        error_log("VPN_BLOCK: ProxyCheck.io Error for $ip: " . ($data['message'] ?? 'Unknown Error'));
                    }
                }

                if (isset($data[$ip]['proxy']) && $data[$ip]['proxy'] === 'yes') {
                    $isVpn = true;
                    error_log("VPN_BLOCK: Detected VPN for $ip (Type: " . ($data[$ip]['type'] ?? 'unknown') . ")");
                }
            } else {
                error_log("VPN_BLOCK: cURL error fetching ProxyCheck.io for $ip: $err (HTTP: $httpCode)");
            }
        } else {
            error_log("VPN_BLOCK: Missing ProxyCheck API Key in settings.");
        }

        // 5. Store in Cache
        try {
            $db->prepare("DELETE FROM security_cache WHERE ip_address = ?")->execute([$encIp]);
            $db->prepare("INSERT INTO security_cache (ip_address, is_vpn) VALUES (?, ?)")->execute([$encIp, (int)$isVpn]);
        } catch (\Exception $e) { }

        return self::$runtimeCache[$ip] = $isVpn;
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
    body.ad-blocked { overflow: hidden; }
</style>
<div id="ad-block-modal">
    <h2>Adblock Detected!</h2>
    <p>Please disable your ad blocker to download this file.</p>
    <p>We rely on ads to keep this service free.</p>
    <button onclick="location.reload()" style="padding:10px 20px; background:#2563eb; color:white; border-radius:4px; cursor:pointer; margin-top:20px;">I've Disabled It</button>
</div>
<script>
    window.onload = function() {
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
    };
</script>
JS;
    }

    public function isCountryBlocked(string $ip): bool {
        $ip = self::normalizeIp($ip);
        if ($ip === '127.0.0.1' || $ip === '::1') return false;

        $blockedList = Setting::get('blocked_download_countries', '');
        if (empty($blockedList)) return false;

        $blockedArr = array_filter(array_map('trim', explode(',', strtoupper($blockedList))));
        $url = "https://ip-api.com/json/{$ip}?fields=countryCode";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FileHosting/1.0 (Geo-Blocker)');
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data['countryCode']) && in_array(strtoupper($data['countryCode']), $blockedArr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the client's actual IP address securely.
     */
    public static function getClientIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // 1. Only attempt identity header verification if the "Badge Checker" is enabled
        // This prevents IP spoofing by only trusting headers from verified proxies (like Cloudflare or Localhost)
        if (Setting::get('trust_cloudflare', '1') === '1' && self::isTrustedProxy($remoteAddr)) {
            // Check if we trust the connecting IP (must be a known Cloudflare or local proxy range)
            // 1. Try Cloudflare specific header
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
            // 2. Try X-Forwarded-For (take leftmost public IP)
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                foreach (array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return self::normalizeIp($remoteAddr);
    }

    /**
     * Check if an IP belongs to a trusted proxy range (DB-backed)
     */
    private static function isTrustedProxy(string $ip): bool {
        // Always trust local ranges
        $localRanges = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'];
        foreach ($localRanges as $range) {
            if (self::ipInCidr($ip, $range)) return true;
        }

        // Check database for synced Cloudflare or Custom ranges
        try {
            $db = Database::getInstance()->getConnection();
            if (!$db) return false;
            
            $stmt = $db->query("SELECT ip_range FROM trusted_proxies WHERE is_active = 1");
            $ranges = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($ranges as $range) {
                if (self::ipInCidr($ip, $range)) return true;
            }
        } catch (\Exception $e) {
            // Fallback to minimal hardcoded CF ranges if DB is not ready or fails
            if (str_starts_with($ip, '103.21.244.') || str_starts_with($ip, '104.16.')) return true;
        }

        return false;
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
}
