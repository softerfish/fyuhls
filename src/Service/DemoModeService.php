<?php

namespace App\Service;

use App\Core\Auth;
use App\Model\Setting;

class DemoModeService
{
    public static function isEnabled(): bool
    {
        return Setting::get('demo_mode', '0') === '1';
    }

    public static function demoAdminUserId(): int
    {
        return (int)Setting::get('demo_admin_user_id', '0');
    }

    public static function currentViewerIsDemoAdmin(): bool
    {
        // both conditions must be true - demo mode on globally AND this user is the designated demo account
        if (!self::isEnabled()) {
            return false;
        }

        $userId = (int)(Auth::id() ?? 0);
        return $userId > 0 && $userId === self::demoAdminUserId();
    }

    public static function hiddenLabel(string $label = 'Hidden for demo admin'): string
    {
        return $label;
    }

    public static function maskIp(?string $ip): string
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return self::hiddenLabel();
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.***.***';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $prefix = array_slice($parts, 0, 2);
            return implode(':', $prefix) . ':****:****';
        }

        return self::hiddenLabel();
    }

    public static function maskEmail(?string $email): string
    {
        $email = trim((string)$email);
        if ($email === '' || !str_contains($email, '@')) {
            return self::hiddenLabel();
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = strlen($local) <= 2
            ? substr($local, 0, 1) . '*'
            : substr($local, 0, 1) . str_repeat('*', max(2, strlen($local) - 2)) . substr($local, -1);

        $domainParts = explode('.', $domain);
        if (!empty($domainParts[0])) {
            $domainParts[0] = substr($domainParts[0], 0, 1) . str_repeat('*', max(2, strlen($domainParts[0]) - 1));
        }

        return $local . '@' . implode('.', $domainParts);
    }

    public static function maskPerson(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return self::hiddenLabel();
        }

        $words = preg_split('/\s+/', $value) ?: [];
        $masked = [];
        foreach ($words as $word) {
            $masked[] = substr($word, 0, 1) . str_repeat('*', max(2, strlen($word) - 1));
        }

        return implode(' ', $masked);
    }

    public static function redactTextContent(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return $value;
        }

        $value = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip-hidden]', $value) ?? $value;
        $value = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[email-hidden]', $value) ?? $value;
        return $value;
    }

    public static function redactValueForContext(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return self::redactContext($value);
        }

        $key = strtolower($key);
        if ($value === null) {
            return null;
        }

        if (str_contains($key, 'ip')) {
            return self::maskIp((string)$value);
        }

        if (str_contains($key, 'email')) {
            return self::maskEmail((string)$value);
        }

        if (str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'password')
            || str_contains($key, 'key')
            || str_contains($key, 'path')
            || str_contains($key, 'authorization')
            || str_contains($key, 'cookie')
        ) {
            return self::hiddenLabel();
        }

        if (str_contains($key, 'username') || str_contains($key, 'name')) {
            return self::maskPerson((string)$value);
        }

        if (is_string($value)) {
            return self::redactTextContent($value);
        }

        return $value;
    }

    public static function redactContext(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $redacted[$key] = self::redactValueForContext((string)$key, $value);
        }

        return $redacted;
    }
}
