<?php

namespace App\Core;

class Logger {
    private static string $logDir = '';
    private static string $logFile = '';
    private const MAX_LOG_BYTES = 26214400; // 25 MB

    private static function init(): void {
        if (self::$logDir === '') {
            $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
            self::$logDir = $root . '/storage/logs';
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
            self::$logFile = self::$logDir . '/app.log';
        }
    }

    public static function info(string $message, array $context = []): void {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::write('error', $message, $context);
    }

    public static function debug(string $message, array $context = []): void {
        // optional: only log in debug env
        if (Config::get('debug', false)) {
            self::write('debug', $message, $context);
        }
    }

    private static function write(string $level, string $message, array $context): void {
        self::init();
        $context = self::sanitize($context);
        $entry = [
            'ts' => date('c'),
            'level' => $level,
            'msg' => $message,
            'ctx' => $context
        ];
        $line = json_encode($entry) . PHP_EOL;

        clearstatcache(true, self::$logFile);
        $shouldOverwrite = is_file(self::$logFile) && (int)@filesize(self::$logFile) >= self::MAX_LOG_BYTES;
        $flags = LOCK_EX | ($shouldOverwrite ? 0 : FILE_APPEND);

        @file_put_contents(self::$logFile, $line, $flags);
    }

    private static function sanitize(array $context): array {
        $redactKeys = ['secret', 'secret_key', 'access_key', 'password', 'token'];
        $sanitized = [];
        foreach ($context as $k => $v) {
            $lk = strtolower((string)$k);
            $shouldRedact = false;
            foreach ($redactKeys as $rk) {
                if (strpos($lk, $rk) !== false) {
                    $shouldRedact = true;
                    break;
                }
            }
            $sanitized[$k] = $shouldRedact ? '[redacted]' : $v;
        }
        return $sanitized;
    }
}
