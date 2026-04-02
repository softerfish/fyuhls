<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Database;
use App\Core\PluginManager;
use App\Model\Setting;

class DiagnosticsService {
    public const SUPPORT_EMAIL = 'fyuhls.script@gmail.com';
    
    /**
     * Generate the complete diagnostics bundle as an array.
     */
    public function generateBundle(): array {
        return [
            'metadata' => [
                'timestamp' => date('c'),
                'software' => 'fyuhls',
                'version' => $this->getAppVersion()
            ],
            'system' => $this->getSystemInfo(),
            'config' => $this->getConfigSummary(),
            'logs' => $this->getSanitizedLogs(500) // Last 500 lines
        ];
    }

    public function generateSupportBundle(array $context = []): array {
        $issueDescription = trim((string)($context['issue_description'] ?? ''));

        return [
            'metadata' => [
                'timestamp' => date('c'),
                'software' => 'fyuhls',
                'version' => $this->getAppVersion(),
                'support_token' => 'support_' . bin2hex(random_bytes(6)),
                'submitted_issue' => $issueDescription !== '' ? $this->sanitizeString($issueDescription) : null,
                'support_email' => self::SUPPORT_EMAIL,
            ],
            'summary' => $this->getSupportSummary(),
            'system' => $this->getSystemInfo(),
            'checks' => $this->getSystemChecks(),
            'plugins' => $this->getPluginSummary(),
            'config' => $this->getConfigSummary(),
            'logs' => $this->getSanitizedLogs(200),
        ];
    }

    public function generateSupportEmailBody(array $bundle): string {
        $summary = $bundle['summary'] ?? [];
        $checks = $bundle['checks'] ?? [];

        $lines = [
            'Fyuhls Support Bundle',
            '=====================',
            'Support Token: ' . ($bundle['metadata']['support_token'] ?? 'unknown'),
            'Generated: ' . ($bundle['metadata']['timestamp'] ?? date('c')),
            'Version: ' . ($bundle['metadata']['version'] ?? 'unknown'),
            '',
            'Issue Description:',
            (string)($bundle['metadata']['submitted_issue'] ?? '(not provided)'),
            '',
            'Environment Summary:',
            '- PHP: ' . ($summary['php_version'] ?? 'unknown'),
            '- MySQL: ' . ($summary['mysql_version'] ?? 'unknown'),
            '- Web Server: ' . ($summary['server_software'] ?? 'unknown'),
            '- Active Plugins: ' . implode(', ', $summary['active_plugins'] ?? []),
            '',
            'Checks:',
            '- Storage Writable: ' . (($checks['storage_writable'] ?? false) ? 'yes' : 'no'),
            '- GD Available: ' . (($checks['gd_available'] ?? false) ? 'yes' : 'no'),
            '- FFmpeg Ready: ' . (($checks['ffmpeg_ready'] ?? false) ? 'yes' : 'no'),
            '- SMTP Configured: ' . (($checks['smtp_configured'] ?? false) ? 'yes' : 'no'),
            '',
            'Sanitized Bundle JSON:',
            json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];

        return implode("\n", $lines);
    }

    private function getAppVersion(): string {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $versionFile = $root . '/config/version.php';
        if (file_exists($versionFile)) {
            $v = include $versionFile;
            return is_array($v) ? ($v['version'] ?? '1.0.0') : '1.0.0';
        }
        return '1.0.0';
    }

    private function getSystemInfo(): array {
        $hostMetrics = (new HostService())->getMetrics();

        return [
            'os' => PHP_OS,
            'php_version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'memory_limit' => ini_get('memory_limit'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'loaded_extensions' => get_loaded_extensions(),
            'mysql_version' => $this->getMysqlVersion(),
            'host_metrics' => $hostMetrics,
        ];
    }

    private function getSupportSummary(): array {
        $system = $this->getSystemInfo();
        $plugins = $this->getPluginSummary();
        $checks = $this->getSystemChecks();

        return [
            'php_version' => $system['php_version'] ?? 'unknown',
            'mysql_version' => $system['mysql_version'] ?? 'unknown',
            'server_software' => $system['server'] ?? 'unknown',
            'active_plugins' => array_map(static fn(array $plugin) => $plugin['directory'], $plugins),
            'storage_status' => ($checks['storage_writable'] ?? false) ? 'writable' : 'not_writable',
        ];
    }

    private function getMysqlVersion(): string {
        try {
            $db = \App\Core\Database::getInstance()->getConnection();
            if (!$db) return 'Database connection not initialized';
            return $db->getAttribute(\PDO::ATTR_SERVER_VERSION) ?: 'Unknown';
        } catch (\Throwable $e) {
            return 'Error connecting to DB: ' . $e->getMessage();
        }
    }

    private function getConfigSummary(): array {
        $summary = [];
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $files = ['app.php', 'database.php']; 
        
        foreach ($files as $file) {
            $path = $root . '/config/' . $file;
            if (file_exists($path)) {
                $cfg = include $path;
                if (is_array($cfg)) {
                    $summary[$file] = $this->sanitizeConfig($cfg);
                }
            }
        }
        return $summary;
    }

    private function getPluginSummary(): array {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT directory, version, is_active FROM plugins ORDER BY directory ASC");
            $plugins = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            $plugins = [];
        }

        if (empty($plugins)) {
            return array_map(static fn(string $directory) => [
                'directory' => $directory,
                'version' => 'unknown',
                'is_active' => true,
            ], PluginManager::getActivePlugins());
        }

        return array_map(static fn(array $plugin) => [
            'directory' => $plugin['directory'] ?? 'unknown',
            'version' => $plugin['version'] ?? 'unknown',
            'is_active' => (int)($plugin['is_active'] ?? 0) === 1,
        ], $plugins);
    }

    private function getSystemChecks(): array {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $uploadPath = $root . '/storage/uploads';
        $ffmpegPath = Setting::getOrConfig('video.ffmpeg_path', Config::get('video.ffmpeg_path', ''));
        $ffmpegEnabled = Setting::getOrConfig('video.ffmpeg_enabled', '1') === '1';

        return [
            'storage_writable' => is_dir($uploadPath) && is_writable($uploadPath),
            'gd_available' => function_exists('imagecreatetruecolor') && function_exists('imagejpeg'),
            'ffmpeg_ready' => $ffmpegEnabled && !empty($ffmpegPath) && file_exists($ffmpegPath),
            'smtp_configured' => trim(Setting::get('email_smtp_host', '')) !== '' && trim(Setting::get('email_from_address', '')) !== '',
        ];
    }

    private function sanitizeConfig(array $config): array {
        $redactKeys = ['secret', 'key', 'password', 'token', 'access_key', 'username', 'email', 'host', 'path'];
        $sanitized = [];
        
        foreach ($config as $k => $v) {
            if (is_array($v)) {
                $sanitized[$k] = $this->sanitizeConfig($v);
                continue;
            }

            $lk = strtolower((string)$k);
            $shouldRedact = false;
            foreach ($redactKeys as $rk) {
                if (strpos($lk, $rk) !== false) {
                    $shouldRedact = true;
                    break;
                }
            }
            $sanitized[$k] = $shouldRedact ? '[REDACTED]' : (is_string($v) ? $this->sanitizeString($v) : $v);
        }
        return $sanitized;
    }

    private function getSanitizedLogs(int $limit = 500): array {
        $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $logFile = $root . '/storage/logs/app.log';

        if (!file_exists($logFile)) {
            return ['status' => 'No logs found'];
        }

        $fp = @fopen($logFile, 'rb');
        if (!$fp) {
            return ['status' => 'Could not open logs'];
        }

        $buffer = 4096;
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $lines = [];
        $currentLine = '';

        while ($pos > 0 && count($lines) < $limit) {
            $readSize = min($buffer, $pos);
            $pos -= $readSize;
            fseek($fp, $pos);
            $chunk = fread($fp, $readSize);
            
            $currentLine = $chunk . $currentLine;
            $items = explode(PHP_EOL, $currentLine);
            
            if ($pos > 0) {
                // array_shift takes the FIRST element, which might be incomplete
                $currentLine = array_shift($items); 
            } else {
                $currentLine = ''; // At start of file, nothing before this
            }

            while (count($items) > 0 && count($lines) < $limit) {
                $lines[] = array_pop($items);
            }
        }
        fclose($fp);

        $lines = array_reverse($lines);
        $sanitized = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                if (isset($entry['ctx']) && is_array($entry['ctx'])) {
                    $entry['ctx'] = $this->sanitizeConfig($entry['ctx']);
                }
                if (isset($entry['msg'])) {
                    $entry['msg'] = $this->sanitizeString($entry['msg']);
                }
                if (isset($entry['file'])) {
                    $entry['file'] = $this->sanitizeString((string)$entry['file']);
                }
                $sanitized[] = $entry;
            } else {
                $sanitized[] = $this->sanitizeString(trim($line));
            }
        }
        return $sanitized;
    }

    private function sanitizeString(string $str): string {
        $str = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_REDACTED]', $str);
        $str = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP_REDACTED]', $str);
        $str = preg_replace('/\b([a-f0-9]{1,4}:){2,}[a-f0-9]{1,4}\b/i', '[IPV6_REDACTED]', $str);
        $str = preg_replace('/(key|secret|password|token)\s*[:=]\s*[^\s,]+/i', '$1: [REDACTED]', $str);
        $str = preg_replace('~(?:[A-Za-z]:[\\\\/]|/)(?:[^\\s"\']+[\\\\/])*[^\\s"\']*~', '[PATH_REDACTED]', $str);
        $str = preg_replace('/ENC:[A-Za-z0-9+\/=:_-]+/', '[ENCRYPTED_VALUE]', $str);
        return $str;
    }
}
