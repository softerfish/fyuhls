<?php

namespace App\Service;

use App\Core\Database;

class PackageAllowanceService
{
    public static function dailyDownloadLimitSummary(?int $userId, array $package): array
    {
        $dailyLimit = (int)($package['max_daily_downloads'] ?? 0);
        if ($dailyLimit <= 0) {
            return [
                'label' => 'Daily limit left',
                'value' => 'Unlimited',
                'used_bytes' => 0,
                'remaining_bytes' => 0,
                'limit_bytes' => 0,
                'has_limit' => false,
            ];
        }

        $usedToday = 0;

        try {
            $db = Database::getInstance()->getConnection();
            $usageDate = gmdate('Y-m-d');
            $actorKey = $userId !== null && $userId > 0
                ? 'user:' . $userId
                : 'ip:' . hash('sha256', SecurityService::getClientIp());

            $stmt = $db->prepare("
                SELECT COALESCE(SUM(bytes_used), 0)
                FROM download_bandwidth_usage
                WHERE actor_key = ? AND usage_date = ?
            ");
            $stmt->execute([$actorKey, $usageDate]);
            $usedToday = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $usedToday = 0;
        }

        $remaining = max(0, $dailyLimit - $usedToday);

        return [
            'label' => 'Daily limit left',
            'value' => self::formatBytes($remaining),
            'used_bytes' => $usedToday,
            'remaining_bytes' => $remaining,
            'limit_bytes' => $dailyLimit,
            'has_limit' => true,
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        $value = max(0, $bytes);

        if ($value >= 1024 ** 4) {
            return round($value / (1024 ** 4), 1) . ' TB';
        }

        if ($value >= 1024 ** 3) {
            return round($value / (1024 ** 3), 1) . ' GB';
        }

        if ($value >= 1024 ** 2) {
            return round($value / (1024 ** 2), 1) . ' MB';
        }

        return '0';
    }
}
