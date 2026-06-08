<?php

namespace App\Service\Database;

use PDO;
use RuntimeException;

class ManualJsonColumnMigrationService
{
    private const SCHEMA_SYNC_LOCK_NAME = 'fyuhls_schema_sync';
    private static $afterMigrationStepHandler = null;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function setAfterMigrationStepHandlerForTests(?callable $handler): void
    {
        self::$afterMigrationStepHandler = $handler;
    }

    public function inspect(): array
    {
        $schema = SchemaService::getMasterSchema([], false);
        $targets = $this->targetColumns();
        $plan = [];
        $tableDdls = [];

        foreach ($targets as $tableName => $columns) {
            $tableDdls[$tableName] = $this->loadTableDdl($tableName);
            foreach ($columns as $columnName) {
                $expectedDefinition = (string)($schema[$tableName]['columns'][$columnName] ?? '');
                $currentColumn = $this->loadColumnMetadata($tableName, $columnName);
                $primaryKey = $schema[$tableName]['primary'] ?? 'id';

                if ($currentColumn === null) {
                    $plan[] = [
                        'table' => $tableName,
                        'column' => $columnName,
                        'expected_definition' => $expectedDefinition,
                        'status' => 'missing',
                        'needs_migration' => false,
                        'blank_rows' => 0,
                        'invalid_rows' => 0,
                        'invalid_samples' => [],
                    ];
                    continue;
                }

                $currentType = strtolower((string)($currentColumn['DATA_TYPE'] ?? ''));
                $needsMigration = !$this->columnMatchesExpectedJsonShape(
                    $expectedDefinition,
                    $currentType,
                    $tableDdls[$tableName] ?? '',
                    $columnName
                );
                $blankRows = 0;
                $invalidRows = 0;
                $invalidSamples = [];

                if ($needsMigration) {
                    $blankRows = $this->countBlankRows($tableName, $columnName);
                    $invalidRows = $this->countInvalidJsonRows($tableName, $columnName);
                    if ($invalidRows > 0 && is_string($primaryKey) && $primaryKey !== '') {
                        $invalidSamples = $this->sampleInvalidPrimaryKeys($tableName, $columnName, $primaryKey);
                    }
                }

                $plan[] = [
                    'table' => $tableName,
                    'column' => $columnName,
                    'expected_definition' => $expectedDefinition,
                    'current_type' => $currentType,
                    'current_column_type' => (string)($currentColumn['COLUMN_TYPE'] ?? ''),
                    'status' => $needsMigration ? 'drifted' : 'aligned',
                    'needs_migration' => $needsMigration,
                    'blank_rows' => $blankRows,
                    'invalid_rows' => $invalidRows,
                    'invalid_samples' => $invalidSamples,
                ];
            }
        }

        return $plan;
    }

    public function migrate(): array
    {
        $plan = $this->inspect();
        $pending = array_values(array_filter($plan, static fn(array $row): bool => !empty($row['needs_migration'])));

        if ($pending === []) {
            return [
                'success' => true,
                'message' => 'No manual JSON-column migration work is required.',
                'plan' => $plan,
                'logs' => [],
            ];
        }

        $invalid = array_values(array_filter($pending, static fn(array $row): bool => (int)($row['invalid_rows'] ?? 0) > 0));
        if ($invalid !== []) {
            $details = [];
            foreach ($invalid as $row) {
                $sampleSuffix = '';
                $samples = array_map('strval', (array)($row['invalid_samples'] ?? []));
                if ($samples !== []) {
                    $sampleSuffix = ' Sample IDs: ' . implode(', ', $samples) . '.';
                }
                $details[] = sprintf(
                    '%s.%s has %d invalid JSON row(s).%s',
                    $row['table'],
                    $row['column'],
                    (int)$row['invalid_rows'],
                    $sampleSuffix
                );
            }

            throw new RuntimeException(
                'Manual JSON-column migration cannot continue until invalid payloads are fixed. '
                . implode(' | ', $details)
            );
        }

        $logs = [];
        $lockAcquired = false;
        $backups = [];

        try {
            $lockAcquired = $this->acquireSchemaLock();
            if (!$lockAcquired) {
                throw new RuntimeException('Another schema sync or repair is already running. Wait for it to finish before starting the manual JSON-column migration.');
            }

            $backups = $this->createTableBackups($pending);

            foreach ($pending as $index => $row) {
                $tableName = (string)$row['table'];
                $columnName = (string)$row['column'];
                $expectedDefinition = (string)$row['expected_definition'];
                $blankRows = (int)($row['blank_rows'] ?? 0);

                if ($blankRows > 0) {
                    $logs[] = sprintf('Normalizing %d blank %s.%s value(s) to NULL.', $blankRows, $tableName, $columnName);
                    $stmt = $this->pdo->prepare(sprintf(
                        'UPDATE %s SET %s = NULL WHERE %s IS NOT NULL AND TRIM(CAST(%s AS CHAR)) = \'\'',
                        $this->quoteIdentifier($tableName),
                        $this->quoteIdentifier($columnName),
                        $this->quoteIdentifier($columnName),
                        $this->quoteIdentifier($columnName)
                    ));
                    $stmt->execute();
                }

                $logs[] = sprintf('Altering %s.%s to %s.', $tableName, $columnName, $expectedDefinition);
                $this->pdo->exec(sprintf(
                    'ALTER TABLE %s MODIFY COLUMN %s %s',
                    $this->quoteIdentifier($tableName),
                    $this->quoteIdentifier($columnName),
                    $expectedDefinition
                ));

                if (is_callable(self::$afterMigrationStepHandler)) {
                    (self::$afterMigrationStepHandler)([
                        'table' => $tableName,
                        'column' => $columnName,
                        'expected_definition' => $expectedDefinition,
                        'index' => $index,
                    ]);
                }
            }

            $postPlan = $this->inspect();
            $remaining = array_values(array_filter($postPlan, static fn(array $row): bool => !empty($row['needs_migration'])));
            if ($remaining !== []) {
                $remainingSummary = array_map(
                    static fn(array $row): string => $row['table'] . '.' . $row['column'],
                    $remaining
                );
                throw new RuntimeException(
                    'Manual JSON-column migration finished with unresolved drift: ' . implode(', ', $remainingSummary)
                );
            }

            $this->dropTableBackups($backups);

            return [
                'success' => true,
                'message' => 'Manual JSON-column migration completed successfully.',
                'plan' => $postPlan,
                'logs' => $logs,
            ];
        } catch (\Throwable $e) {
            if ($backups !== []) {
                $this->restoreTablesFromBackups($backups);
                $this->dropTableBackups($backups);
            }

            throw $e;
        } finally {
            if ($lockAcquired) {
                $this->releaseSchemaLock();
            }
        }
    }

    private function targetColumns(): array
    {
        return [
            'reward_receipts' => ['risk_reasons_json'],
            'earnings' => ['risk_reasons_json', 'metadata'],
            'download_sessions' => ['risk_reasons_json'],
            'download_session_events' => ['event_payload'],
        ];
    }

    private function loadColumnMetadata(string $tableName, string $columnName): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$tableName, $columnName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function loadTableDdl(string $tableName): string
    {
        $stmt = $this->pdo->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($tableName));
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($row)) {
            return '';
        }

        foreach (['Create Table', 'Create View'] as $key) {
            if (!empty($row[$key]) && is_string($row[$key])) {
                return $row[$key];
            }
        }

        return '';
    }

    private function columnMatchesExpectedJsonShape(string $expectedDefinition, string $currentType, string $tableDdl, string $columnName): bool
    {
        $normalizedExpected = strtoupper(trim($expectedDefinition));
        $normalizedCurrentType = strtolower(trim($currentType));
        if (!str_contains($normalizedExpected, 'JSON')) {
            return $normalizedCurrentType === strtolower(trim(strtok($normalizedExpected, ' ')));
        }

        if ($normalizedCurrentType === 'json') {
            return true;
        }

        if (!in_array($normalizedCurrentType, ['longtext', 'text'], true)) {
            return false;
        }

        return $this->tableDdlShowsJsonValidation($tableDdl, $columnName);
    }

    private function tableDdlShowsJsonValidation(string $tableDdl, string $columnName): bool
    {
        if ($tableDdl === '') {
            return false;
        }

        $quotedColumn = preg_quote('`' . $columnName . '`', '/');
        if (preg_match('/' . $quotedColumn . '.*\bJSON\b/i', $tableDdl) === 1) {
            return true;
        }

        return preg_match('/JSON_VALID\s*\(\s*`' . preg_quote($columnName, '/') . '`\s*\)/i', $tableDdl) === 1;
    }

    private function countBlankRows(string $tableName, string $columnName): int
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND TRIM(CAST(%s AS CHAR)) = \'\'',
            $this->quoteIdentifier($tableName),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($columnName)
        ));
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private function countInvalidJsonRows(string $tableName, string $columnName): int
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND TRIM(CAST(%s AS CHAR)) <> \'\' AND JSON_VALID(%s) = 0',
            $this->quoteIdentifier($tableName),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($columnName)
        ));
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private function sampleInvalidPrimaryKeys(string $tableName, string $columnName, string $primaryKey): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT %s FROM %s WHERE %s IS NOT NULL AND TRIM(CAST(%s AS CHAR)) <> \'\' AND JSON_VALID(%s) = 0 ORDER BY %s LIMIT 5',
            $this->quoteIdentifier($primaryKey),
            $this->quoteIdentifier($tableName),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($columnName),
            $this->quoteIdentifier($primaryKey)
        ));
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function acquireSchemaLock(): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([self::SCHEMA_SYNC_LOCK_NAME]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseSchemaLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([self::SCHEMA_SYNC_LOCK_NAME]);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<int, array<string, mixed>> $pending
     * @return array<string, array{backup_table: string, create_sql: string}>
     */
    private function createTableBackups(array $pending): array
    {
        $backups = [];
        foreach ($this->targetColumns() as $tableName => $_columns) {
            foreach ($pending as $row) {
                if ((string)($row['table'] ?? '') !== $tableName) {
                    continue;
                }

                $ddl = $this->loadTableDdl($tableName);
                if ($ddl === '') {
                    throw new RuntimeException('Unable to capture the current schema for ' . $tableName . ' before running the manual JSON-column migration.');
                }

                $backupTable = sprintf('__fyuhls_json_backup_%s_%s', $tableName, bin2hex(random_bytes(4)));
                $this->pdo->exec(sprintf(
                    'CREATE TABLE %s LIKE %s',
                    $this->quoteIdentifier($backupTable),
                    $this->quoteIdentifier($tableName)
                ));
                $this->pdo->exec(sprintf(
                    'INSERT INTO %s SELECT * FROM %s',
                    $this->quoteIdentifier($backupTable),
                    $this->quoteIdentifier($tableName)
                ));

                $backups[$tableName] = [
                    'backup_table' => $backupTable,
                    'create_sql' => $ddl,
                ];

                break;
            }
        }

        return $backups;
    }

    /**
     * @param array<string, array{backup_table: string, create_sql: string}> $backups
     */
    private function restoreTablesFromBackups(array $backups): void
    {
        $dropOrder = array_reverse(array_keys($this->targetColumns()));
        $foreignKeysDisabled = false;

        try {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $foreignKeysDisabled = true;

            foreach ($dropOrder as $tableName) {
                if (!isset($backups[$tableName])) {
                    continue;
                }

                $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName));
            }

            foreach (array_keys($this->targetColumns()) as $tableName) {
                if (!isset($backups[$tableName])) {
                    continue;
                }

                $this->pdo->exec((string)$backups[$tableName]['create_sql']);
                $this->pdo->exec(sprintf(
                    'INSERT INTO %s SELECT * FROM %s',
                    $this->quoteIdentifier($tableName),
                    $this->quoteIdentifier($backups[$tableName]['backup_table'])
                ));
            }
        } finally {
            if ($foreignKeysDisabled) {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }

    /**
     * @param array<string, array{backup_table: string, create_sql: string}> $backups
     */
    private function dropTableBackups(array $backups): void
    {
        foreach ($backups as $backup) {
            $backupTable = (string)($backup['backup_table'] ?? '');
            if ($backupTable === '') {
                continue;
            }

            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($backupTable));
        }
    }
}
