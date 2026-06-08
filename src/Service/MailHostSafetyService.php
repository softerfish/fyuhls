<?php

namespace App\Service;

final class MailHostSafetyService
{
    private const STANDARD_SMTP_PORTS = [25, 465, 587, 2525];

    public static function normalizeSmtpHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            throw new \RuntimeException('SMTP host is required.');
        }

        if (preg_match('#^[a-z]+://#i', $host) === 1) {
            throw new \RuntimeException('SMTP host should be a hostname or IP only, without http:// or https://.');
        }

        if (preg_match('/[\/?#]/', $host) === 1) {
            throw new \RuntimeException('SMTP host should not include a path, query string, or fragment.');
        }

        $hostOnly = $host;
        if (preg_match('/^\[(.+)\]$/', $hostOnly, $matches) === 1) {
            $hostOnly = $matches[1];
        }

        $hostPortParts = explode(':', $hostOnly);
        if (count($hostPortParts) > 2 && filter_var($hostOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \RuntimeException('SMTP host should not include a port. Use the SMTP port field instead.');
        }

        if (count($hostPortParts) === 2 && filter_var($hostOnly, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \RuntimeException('SMTP host should not include a port. Use the SMTP port field instead.');
        }

        SafeNetworkTargetService::assertSafeResolvableHost($hostOnly, 'SMTP host');
        return $hostOnly;
    }

    public static function normalizeSmtpPort(mixed $port): int
    {
        $rawPort = is_string($port) ? trim($port) : (string)$port;
        if ($rawPort === '' || preg_match('/^\d+$/', $rawPort) !== 1) {
            throw new \RuntimeException('SMTP port must be numeric.');
        }

        $port = (int)$rawPort;
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('SMTP port must be between 1 and 65535.');
        }

        if (!in_array($port, self::STANDARD_SMTP_PORTS, true)) {
            throw new \RuntimeException('SMTP port must be one of the standard mail ports: 25, 465, 587, or 2525.');
        }

        return $port;
    }
}
