<?php

namespace App\Core;

use PDO;
use PDOException;
use App\Core\Config;

class Database {
    private static ?Database $instance = null;
    private ?PDO $connection = null;
    private bool $isRepairing = false;

    private function __construct() {
        $config = Config::get('database');
        
        if (!$config) {
            return; 
        }

        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s;port=%s",
            $config['host'],
            $config['dbname'],
            $config['charset'],
            $config['port']
        );

        try {
            $this->connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
            ]);

        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Internal Server Error");
        }
    }

    private function checkSchemaVersion(): void {
        static $alreadyChecked = false;
        if ($alreadyChecked) return;
        $alreadyChecked = true;
        if (!$this->connection) return;

        try {
            // Use internal connection directly to avoid recursion via Setting model
            $stmt = $this->connection->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1");
            $currentVersion = $stmt ? $stmt->fetchColumn() : null;
            $masterVersion = \App\Service\Database\SchemaService::SCHEMA_VERSION;

            if ($currentVersion !== $masterVersion) {
                // Only set if not already detected to avoid overwriting a more specific query error
                $existingDrift = $this->connection->query("SELECT setting_value FROM settings WHERE setting_key = 'db_drift_detected' LIMIT 1")->fetchColumn();
                if ($existingDrift !== '1') {
                    $this->setInternalSetting('db_drift_detected', '1', 'system');
                    $this->setInternalSetting('db_drift_error', "Version mismatch: Code ($masterVersion) vs DB ($currentVersion).", 'system');
                }
            }
        } catch (\Exception $e) {
            // If settings table is missing, that is definitely a drift!
            if (($e instanceof PDOException) && $e->errorInfo[1] == 1146) {
                 error_log("Database: Critical table 'settings' is missing.");
            }
        }
    }

    /**
     * Internal helper to save settings without using the Setting model 
     * (Avoids recursion during boot)
     */
    private function setInternalSetting(string $key, string $value, string $group): void {
        try {
            $stmt = $this->connection->prepare("
                INSERT INTO settings (setting_key, setting_value, setting_group) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group)
            ");
            $stmt->execute([$key, $value, $group]);
        } catch (\Exception $e) {
            error_log("Database: Failed to save internal setting ($key): " . $e->getMessage());
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
            
            // Proactive Drift Detection: Version Mismatch
            // Run exactly once when the database is first initialized.
            if (self::$instance->getConnection() !== null) {
                self::$instance->checkSchemaVersion();
            }
        }
        return self::$instance;
    }

    public function getConnection(): ?PDO {
        return $this->connection;
    }

    /**
     * Lazy Recovery Wrapper: prepare
     * 
     * @throws PDOException
     */
    public function prepare(string $sql): \PDOStatement {
        try {
            $stmt = $this->connection->prepare($sql);
            if (!$stmt) {
                throw new PDOException("PDO prepare failed for: $sql");
            }
            return $stmt;
        } catch (PDOException $e) {
            if ($this->shouldFlagDrift($e)) {
                $this->flagDatabaseDrift($sql, $e);
            }
            throw $e;
        }
    }

    /**
     * Lazy Recovery Wrapper: query
     * 
     * @throws PDOException
     */
    public function query(string $sql): \PDOStatement {
        $start = microtime(true);
        try {
            $stmt = $this->connection->query($sql);
            if (!$stmt) {
                throw new PDOException("PDO query failed for: $sql");
            }
            $this->trackSlowQuery($sql, $start);
            return $stmt;
        } catch (PDOException $e) {
            if ($this->shouldFlagDrift($e)) {
                $this->flagDatabaseDrift($sql, $e);
            }
            throw $e;
        }
    }

    /**
     * Lazy Recovery Wrapper: exec
     * 
     * @throws PDOException
     */
    public function exec(string $sql): int|false {
        $start = microtime(true);
        try {
            $res = $this->connection->exec($sql);
            $this->trackSlowQuery($sql, $start);
            return $res;
        } catch (PDOException $e) {
            if ($this->shouldFlagDrift($e)) {
                $this->flagDatabaseDrift($sql, $e);
            }
            throw $e;
        }
    }

    private function shouldFlagDrift(PDOException $e): bool {
        $errorCode = $e->errorInfo[1] ?? 0;
        // 1146: Table doesn't exist
        // 1054: Unknown column
        return in_array($errorCode, [1146, 1054]);
    }

    private function flagDatabaseDrift(string $sql, PDOException $e): void {
        try {
            error_log("Database Drift Detected! SQL Error: " . $e->getMessage() . " | SQL: " . substr($sql, 0, 100));
            
            // Set the drift flag so the Admin UI can show an alert
            // We use the internal helper to avoid recursion or multiple instances
            $this->setInternalSetting('db_drift_detected', '1', 'system');
            $this->setInternalSetting('db_drift_error', $e->getMessage(), 'system');
            
        } catch (\Exception $ex) {
            error_log("Database: Failed to flag drift: " . $ex->getMessage());
        }
    }

    private function trackSlowQuery(string $sql, float $startTime): void {
        $duration = (microtime(true) - $startTime) * 1000;
        if ($duration > 500) { // 500ms threshold for Enterprise
            error_log(sprintf("[Slow Query] %0.2fms | SQL: %s", $duration, substr($sql, 0, 500)));
        }
    }


    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {}
}
