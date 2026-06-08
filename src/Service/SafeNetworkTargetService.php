<?php

namespace App\Service;

final class SafeNetworkTargetService
{
    public static function normalizePublicHttpUrl(string $url, string $label, bool $allowPath = true): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \RuntimeException($label . ' is required.');
        }

        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException($label . ' must start with http:// or https://');
        }

        $validated = filter_var($url, FILTER_VALIDATE_URL);
        if ($validated === false) {
            throw new \RuntimeException($label . ' is not a valid URL.');
        }

        self::assertSafeRemoteHttpUrl((string)$validated, $label, $allowPath);
        return (string)$validated;
    }

    public static function assertSafeRemoteHttpUrl(string $url, string $label, bool $allowPath = true): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException($label . ' must be a valid http:// or https:// URL.');
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new \RuntimeException($label . ' cannot include embedded credentials.');
        }

        if (!$allowPath) {
            $path = (string)($parts['path'] ?? '');
            if ($path !== '' && $path !== '/') {
                throw new \RuntimeException($label . ' must only contain the host and optional port.');
            }
        }
        if (!empty($parts['query']) || !empty($parts['fragment'])) {
            throw new \RuntimeException($label . ' cannot include query strings or fragments.');
        }

        self::assertSafeResolvableHost($host, $label);
    }

    public static function assertSafeResolvableHost(string $host, string $label): void
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost')) {
            throw new \RuntimeException($label . ' cannot point at localhost or loopback hosts.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && self::isPrivateOrReservedIp($host)) {
            throw new \RuntimeException($label . ' cannot use private, loopback, or reserved IP addresses.');
        }

        $resolvedIps = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $resolvedIps = array_merge($resolvedIps, $ipv4);
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (!empty($record['ipv6'])) {
                        $resolvedIps[] = (string)$record['ipv6'];
                    }
                }
            }
        }

        foreach (array_unique($resolvedIps) as $ip) {
            if (self::isPrivateOrReservedIp((string)$ip)) {
                throw new \RuntimeException($label . ' cannot resolve to private, loopback, or reserved IP addresses.');
            }
        }
    }

    public static function isPrivateOrReservedIp(string $ip): bool
    {
        $ip = strtolower(trim($ip));
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        if (str_starts_with($ip, '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $mapped;
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && preg_match('/^2001:0?db8:/', $ip) === 1;
    }
}
