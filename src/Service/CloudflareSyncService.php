<?php

namespace App\Service;

use App\Core\Database;
use App\Core\Logger;
use App\Model\Setting;
use App\Service\Database\SchemaService;
use Exception;
use PDOException;

class CloudflareSyncService {

    private const CLOUDFLARE_JSON_API = 'https://api.cloudflare.com/client/v4/ips';
    private const DEFAULT_SYNC_INTERVAL_MINS = 1440;

    /**
     * @throws Exception
     */
    public function syncIfNeeded(): bool {
        try {
            $lastSync = (int)Setting::get('cloudflare_last_sync', 0);
            $intervalMins = $this->configuredIntervalMins();
            if ((time() - $lastSync) >= ($intervalMins * 60)) {
                return $this->sync();
            }
        } catch (PDOException $e) {
            return $this->sync();
        }
        return false;
    }

    /**
     * @throws Exception
     * @throws PDOException
     */
    public function sync(): bool {
        try {
            return $this->runSync();
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                $this->createTable();
                return $this->runSync();
            }
            throw $e;
        }
    }

    /**
     * @throws Exception
     * @throws PDOException
     */
    private function runSync(): bool {
        try {
            $json = $this->fetchUrl(self::CLOUDFLARE_JSON_API);

            if (empty($json)) {
                throw new Exception("Empty response from Cloudflare. Possible outgoing firewall block.");
            }

            $data = json_decode($json, true);
            if (!$data || !isset($data['result']['ipv4_cidrs'])) {
                $snippet = substr($json, 0, 50);
                throw new Exception("Invalid JSON from Cloudflare. Response started with: $snippet");
            }

            $ranges = array_merge($data['result']['ipv4_cidrs'], $data['result']['ipv6_cidrs']);
            $db = Database::getInstance()->getConnection();

            $db->beginTransaction();
            $db->exec("DELETE FROM trusted_proxies WHERE proxy_type = 'cloudflare'");

            $stmt = $db->prepare("INSERT INTO trusted_proxies (ip_range, proxy_type) VALUES (?, 'cloudflare')");
            foreach ($ranges as $range) {
                $range = trim($range);
                if (empty($range)) continue;
                $stmt->execute([$range]);
            }

            Setting::set('cloudflare_last_sync', (string)time(), 'security');
            $db->commit();

        Logger::info('Cloudflare proxy IP ranges synced', [
            'range_count' => count($ranges),
            'setting_key' => 'trusted_proxies',
        ]);
            return true;

        } catch (Exception $e) {
            $db = Database::getInstance()->getConnection();
            if ($db && $db->inTransaction()) $db->rollBack();
            Logger::error("Cloudflare Sync Failed", ['error' => $e->getMessage()]);
            // Re-throw so CronManager can log the error details to the cron_tasks table
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    private function fetchUrl(string $url): string {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SecuritySync/1.1';

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_USERAGENT, $ua);

            $content = curl_exec($ch);

            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("cURL Error: " . $error);
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Cloudflare API returned HTTP $httpCode");
            }

            return (string)$content;
        }

        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: $ua\r\n"]]);
            $content = @file_get_contents($url, false, $ctx);
            if ($content === false) {
                throw new Exception("file_get_contents failed. cURL is missing and allow_url_fopen is restricted.");
            }
            return (string)$content;
        }

        throw new Exception("No way to fetch external data. Please enable cURL or allow_url_fopen.");
    }

    public function createTable(): void {
        SchemaService::withRepairWindow(static function (): void {
            SchemaService::ensureTables(['trusted_proxies'], true);
        });
    }

    private function configuredIntervalMins(): int
    {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT interval_mins FROM cron_tasks WHERE task_key = ? LIMIT 1");
            $stmt->execute(['cf_sync']);
            $interval = (int)$stmt->fetchColumn();
            return $interval > 0 ? $interval : self::DEFAULT_SYNC_INTERVAL_MINS;
        } catch (\Throwable $e) {
            return self::DEFAULT_SYNC_INTERVAL_MINS;
        }
    }
}
