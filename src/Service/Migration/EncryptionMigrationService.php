<?php

namespace App\Service\Migration;

use App\Core\Database;
use App\Service\EncryptionService;
use Exception;

class EncryptionMigrationService {
    
    private $db;

    /**
     * FUTUREPROOF GUARD: These columns must NEVER be encrypted.
     */
    private const FORBIDDEN_COLUMNS = ['id', 'password', 'role', 'status', 'created_at', 'updated_at', 'public_id', 'short_id'];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function buildWhereClause(array $keys, array $row, array &$params): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = "`$key` = ?";
            $params[] = $row[$key] ?? null;
        }
        return implode(' AND ', $parts);
    }

    private function tryResolveMigrationConflict(string $table, string $column, array $row, array $pks, string $encrypted): bool
    {
        if ($table !== 'download_limits' || $column !== 'ip_address') {
            return false;
        }

        $targetKey = [
            'ip_address' => $encrypted,
            'window_start' => $row['window_start'] ?? null,
        ];

        $select = $this->db->prepare("SELECT attempt_count FROM `download_limits` WHERE `ip_address` = ? AND `window_start` = ? LIMIT 1");
        $select->execute([$targetKey['ip_address'], $targetKey['window_start']]);
        $existing = $select->fetchColumn();
        if ($existing === false) {
            return false;
        }

        $sourceAttemptCount = (int)($row['attempt_count'] ?? 0);
        if ($sourceAttemptCount > 0) {
            $update = $this->db->prepare("
                UPDATE `download_limits`
                SET `attempt_count` = `attempt_count` + ?
                WHERE `ip_address` = ? AND `window_start` = ?
            ");
            $update->execute([$sourceAttemptCount, $targetKey['ip_address'], $targetKey['window_start']]);
        }

        $deleteParams = [];
        $deleteWhere = $this->buildWhereClause($pks, $row, $deleteParams);
        $delete = $this->db->prepare("DELETE FROM `download_limits` WHERE $deleteWhere");
        $delete->execute($deleteParams);

        return true;
    }

    /**
     * Get the total number of items across all tables that still need encryption
     */
    public function getPendingCount(): int {
        $count = 0;
        $schema = \App\Service\Database\SchemaService::getMasterSchema();
        $excludedTables = ['system_stats', 'stats_history'];

        foreach ($schema as $table => $definition) {
            if (in_array($table, $excludedTables)) continue;
            
            $encryptedColumns = \App\Service\Database\SchemaService::getEncryptedColumns($table);
            if (empty($encryptedColumns)) continue;

            // Verify table exists before querying
            try {
                $check = $this->db->query("SHOW TABLES LIKE '$table'")->fetch();
                if (!$check) continue;

                foreach ($encryptedColumns as $column) {
                    // Security Guard: Skip forbidden columns
                    if (in_array(strtolower($column), self::FORBIDDEN_COLUMNS)) continue;

                    try {
                        // Double check column exists (extra safety)
                        $colCheck = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
                        if (!$colCheck) continue;

                        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != '' AND `$column` NOT LIKE 'ENC:%'");
                        $stmt->execute();
                        $count += (int)$stmt->fetchColumn();
                    } catch (Exception $e) { }
                }
            } catch (Exception $e) { }
        }
        return $count;
    }

    public function getPendingItems(int $limit = 10): array {
        $items = [];
        if ($limit <= 0) {
            return $items;
        }

        $schema = \App\Service\Database\SchemaService::getMasterSchema();
        $excludedTables = ['system_stats', 'stats_history'];

        foreach ($schema as $table => $definition) {
            if (in_array($table, $excludedTables, true)) {
                continue;
            }

            $encryptedColumns = \App\Service\Database\SchemaService::getEncryptedColumns($table);
            if (empty($encryptedColumns)) {
                continue;
            }

            try {
                $check = $this->db->query("SHOW TABLES LIKE '$table'")->fetch();
                if (!$check) {
                    continue;
                }
            } catch (Exception $e) {
                continue;
            }

            $primary = $definition['primary'] ?? 'id';
            $pks = is_array($primary) ? $primary : [$primary];

            foreach ($encryptedColumns as $column) {
                if (in_array(strtolower($column), self::FORBIDDEN_COLUMNS, true)) {
                    continue;
                }

                try {
                    $colCheck = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
                    if (!$colCheck) {
                        continue;
                    }

                    $selectCols = array_merge($pks, [$column]);
                    if ($table === 'download_limits' && !in_array('attempt_count', $selectCols, true)) {
                        $selectCols[] = 'attempt_count';
                    }
                    $selectList = "`" . implode("`, `", array_unique($selectCols)) . "`";
                    $remaining = max(1, $limit - count($items));
                    $stmt = $this->db->prepare("SELECT $selectList FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != '' AND `$column` NOT LIKE 'ENC:%' LIMIT $remaining");
                    $stmt->execute();

                    foreach ($stmt->fetchAll() as $row) {
                        $pkValues = [];
                        foreach ($pks as $pk) {
                            $pkValues[$pk] = $row[$pk] ?? null;
                        }

                        $items[] = [
                            'table' => $table,
                            'column' => $column,
                            'primary_keys' => $pkValues,
                            'value_preview' => mb_substr((string)($row[$column] ?? ''), 0, 80),
                        ];

                        if (count($items) >= $limit) {
                            return $items;
                        }
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        return $items;
    }

    /**
     * expand the right column sizes to hold long encrypted strings based on Master Schema
     */
    public function expandColumns(): void {
        $schema = \App\Service\Database\SchemaService::getMasterSchema();

        foreach ($schema as $table => $definition) {
            $encryptedColumns = \App\Service\Database\SchemaService::getEncryptedColumns($table);
            if (empty($encryptedColumns)) continue;

            foreach ($encryptedColumns as $column) {
                if (isset($definition['columns'][$column])) {
                    $def = $definition['columns'][$column];
                    try {
                        // We use the Master Schema definition to force the correct size (e.g. VARCHAR(255) or TEXT)
                        $this->db->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $def");
                    } catch (Exception $e) {
                        // Ignore errors like "column doesn't exist yet" - sync will handle that
                    }
                }
            }
        }
    }

    /**
     * encrypt any old plaintext data in batches
     */
    public function encryptLegacyData(): array {
        $results = ['migrated' => 0, 'errors' => 0, 'error_details' => [], 'pending_samples' => []];
        $schema = \App\Service\Database\SchemaService::getMasterSchema();
        $excludedTables = ['system_stats', 'stats_history'];
        
        foreach ($schema as $table => $definition) {
            if (in_array($table, $excludedTables)) continue;

            $encryptedColumns = \App\Service\Database\SchemaService::getEncryptedColumns($table);
            if (empty($encryptedColumns)) continue;

            // Verify table exists
            $tableCheck = $this->db->query("SHOW TABLES LIKE '$table'")->fetch();
            if (!$tableCheck) continue;

            // Identify Primary Keys (could be single string or array for composite)
            $primary = $definition['primary'] ?? 'id';
            $pks = is_array($primary) ? $primary : [$primary];

            foreach ($encryptedColumns as $column) {
                // SECURITY GUARD: Never migrate forbidden core structural columns
                if (in_array(strtolower($column), self::FORBIDDEN_COLUMNS)) {
                    error_log("EncryptionMigration: Blocking attempt to encrypt forbidden column '$table.$column'");
                    continue;
                }

                try {
                    // Safety check: verify column exists before selecting
                    $colCheck = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
                    if (!$colCheck) continue;

                    // Select all PK columns + the column to encrypt
                    $selectCols = array_merge($pks, [$column]);
                    $selectList = "`" . implode("`, `", array_unique($selectCols)) . "`";

                    // Find rows where column is NOT empty and DOES NOT start with 'ENC:'
                    $stmt = $this->db->prepare("SELECT $selectList FROM `$table` WHERE `$column` IS NOT NULL AND `$column` != '' AND `$column` NOT LIKE 'ENC:%' LIMIT 500");
                    $stmt->execute();
                    $rows = $stmt->fetchAll();

                    foreach ($rows as $row) {
                        try {
                            $encrypted = EncryptionService::encrypt($row[$column]);
                            
                            // Build dynamic WHERE clause based on actual PKs from Master Schema
                            $whereParts = [];
                            $updateParams = [$encrypted];
                            foreach ($pks as $pk) {
                                $whereParts[] = "`$pk` = ?";
                                $updateParams[] = $row[$pk];
                            }
                            $whereClause = implode(" AND ", $whereParts);

                            $update = $this->db->prepare("UPDATE `$table` SET `$column` = ? WHERE $whereClause");
                            $update->execute($updateParams);
                            $results['migrated']++;
                        } catch (Exception $e) {
                            if ($this->tryResolveMigrationConflict($table, $column, $row, $pks, $encrypted ?? '')) {
                                $results['migrated']++;
                                continue;
                            }
                            $results['errors']++;
                            if (count($results['error_details']) < 5) {
                                $pkValues = [];
                                foreach ($pks as $pk) {
                                    $pkValues[$pk] = $row[$pk] ?? null;
                                }
                                $results['error_details'][] = [
                                    'table' => $table,
                                    'column' => $column,
                                    'primary_keys' => $pkValues,
                                    'error' => $e->getMessage(),
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {
                    $results['errors']++;
                    if (count($results['error_details']) < 5) {
                        $results['error_details'][] = [
                            'table' => $table,
                            'column' => $column,
                            'primary_keys' => [],
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        if ($results['errors'] > 0 || $this->getPendingCount() > 0) {
            $results['pending_samples'] = $this->getPendingItems(5);
        }

        return $results;
    }
}
