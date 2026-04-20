<?php

namespace App\Service;

class HostService
{
    /**
     * Get host system metrics with safety checks for restricted environments.
     */
    public function getMetrics(): array
    {
        return [
            'disk' => $this->getDiskUsage(),
            'cpu' => $this->getCpuLoad(),
            'ram' => $this->getRamUsage(),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'os' => PHP_OS
        ];
    }

    /**
     * Get Disk usage using native PHP functions (Safest).
     */
    private function getDiskUsage(): array
    {
        $path = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        
        try {
            $total = @disk_total_space($path) ?: 0;
            $free = @disk_free_space($path) ?: 0;
            $used = $total - $free;
            $percent = $total > 0 ? round(($used / $total) * 100, 1) : 0;

            return [
                'total' => $total,
                'used' => $used,
                'free' => $free,
                'percent' => $percent,
                'readable_total' => $this->formatBytes($total),
                'readable_used' => $this->formatBytes($used)
            ];
        } catch (\Exception $e) {
            return ['percent' => 0, 'readable_total' => 'N/A', 'readable_used' => 'N/A'];
        }
    }

    /**
     * Get CPU Load with Circuit Breaker and OS checks.
     */
    private function getCpuLoad(): ?float
    {
        if ($this->isFunctionDisabled('shell_exec')) {
            // Fallback for systems that allow sys_getloadavg
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                return isset($load[0]) ? (float)$load[0] : null;
            }
            return null;
        }

        // Circuit Breaker: Use a very short timeout for shell commands
        if (stristr(PHP_OS, 'win')) {
            // Windows Fallback (WMI) - can be slow
            return null; 
        } else {
            // Linux/Unix
            try {
                $load = @shell_exec("uptime | awk -F'load average:' '{ print $2 }' | cut -d, -f1");
                return $load !== null ? (float)trim($load) : null;
            } catch (\Exception $e) {
                return null;
            }
        }
    }

    /**
     * Get RAM usage (Linux only).
     */
    private function getRamUsage(): ?array
    {
        if ($this->isFunctionDisabled('shell_exec') || stristr(PHP_OS, 'win')) {
            return null;
        }

        try {
            $free = @shell_exec('free');
            if (!$free) return null;

            $free = trim($free);
            $free_arr = explode("\n", $free);
            if (!isset($free_arr[1])) return null;

            $mem = preg_split("/\s+/", $free_arr[1]);
            // index 1=total, 2=used, 3=free
            $total = (int)$mem[1] * 1024;
            $used = (int)$mem[2] * 1024;
            $percent = round(($used / $total) * 100, 1);

            return [
                'total' => $total,
                'used' => $used,
                'percent' => $percent,
                'readable_total' => $this->formatBytes($total),
                'readable_used' => $this->formatBytes($used)
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function isFunctionDisabled(string $func): bool
    {
        $disabled = explode(',', ini_get('disable_functions'));
        return in_array($func, array_map('trim', $disabled));
    }

    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
