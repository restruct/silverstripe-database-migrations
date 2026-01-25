<?php

namespace Restruct\SilverStripe\Migrations;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;

/**
 * Handles merging data from deprecated tables into their replacements.
 *
 * Use case: When merging two similar block types (e.g., BlockBanner → BlockHero),
 * data needs to be migrated from the old table to the new one with optional
 * column mapping and a marker field to identify migrated records.
 *
 * Config format:
 * ```yaml
 * Restruct\SilverStripe\Migrations\DatabaseMigrationExtension:
 *   table_merges:
 *     BlockBanner:                          # Source table name
 *       target: BlockHero                   # Target table name
 *       columns:                            # Column mapping (optional)
 *         BannerBackgroundImageID: HeroBackgroundImageID
 *       marker:                             # Set a value on migrated records (optional)
 *         table: Element                    # Table containing the marker column
 *         column: Style                     # Column name
 *         value: hero-banner                # Value to set
 *       versioned: true                     # Auto-handle _Live and _Versions tables
 * ```
 *
 * Security note:
 * - Table/column names use escapeIdentifier() (identifiers can't be parameterized)
 * - Actual values (marker value) use prepared_query() for safe parameter binding
 * - All config comes from YAML, not user input
 */
class TableMergeHandler
{
    use Injectable;
    use Configurable;

    /**
     * Get the database schema helper
     *
     * @return \SilverStripe\ORM\Connect\DBSchemaManager
     */
    protected function getSchema()
    {
        return DB::get_schema();
    }

    /**
     * Get the database connection
     *
     * @return \SilverStripe\ORM\Connect\Database
     */
    protected function getConn()
    {
        return DB::get_conn();
    }

    /**
     * Process all configured table merges
     *
     * @param array<string, array{target: string, columns?: array, marker?: array, versioned?: bool}> $tableMerges
     * @return int Number of tables successfully merged
     */
    public function runTableMerges(array $tableMerges): int
    {
        if (empty($tableMerges)) {
            return 0;
        }

        $totalMerged = 0;

        foreach ($tableMerges as $sourceTable => $config) {
            $targetTable = $config['target'] ?? null;
            if (!$targetTable) {
                DB::alteration_message("TableMerge: Missing 'target' for {$sourceTable}", 'error');
                continue;
            }

            $merged = $this->mergeTable($sourceTable, $targetTable, $config);
            $totalMerged += $merged;
        }

        if ($totalMerged > 0) {
            DB::alteration_message(
                sprintf('TableMerge: Merged %d table(s)', $totalMerged),
                'changed'
            );
        }

        return $totalMerged;
    }

    /**
     * Merge a single source table into a target table
     *
     * @param string $sourceTable Source table name (will be moved to _obsolete_*)
     * @param string $targetTable Target table name to merge into
     * @param array{columns?: array, marker?: array, versioned?: bool} $config Merge configuration
     * @return int 1 if merged successfully, 0 if skipped
     */
    protected function mergeTable(string $sourceTable, string $targetTable, array $config): int
    {
        $schema = $this->getSchema();

        // Check if source table exists
        if (!$schema->hasTable($sourceTable)) {
            return 0;
        }

        // Check if target table exists
        if (!$schema->hasTable($targetTable)) {
            DB::alteration_message("TableMerge: Target table '{$targetTable}' does not exist", 'notice');
            return 0;
        }

        $columnMapping = $config['columns'] ?? [];
        $marker = $config['marker'] ?? null;
        $versioned = $config['versioned'] ?? false;

        // Count records to migrate
        $conn = $this->getConn();
        $count = (int) DB::query(
            "SELECT COUNT(*) FROM " . $conn->escapeIdentifier($sourceTable)
        )->value();

        if ($count === 0) {
            DB::alteration_message("TableMerge: No records in '{$sourceTable}' to migrate", 'notice');
            $this->moveTableAside($sourceTable);
            if ($versioned) {
                $this->moveTableAside($sourceTable . '_Live');
                $this->moveTableAside($sourceTable . '_Versions');
            }
            return 0;
        }

        DB::alteration_message("TableMerge: Migrating {$count} record(s) from '{$sourceTable}' to '{$targetTable}'", 'changed');

        // Migrate main table
        $this->migrateTableData($sourceTable, $targetTable, $columnMapping);

        // Migrate versioned tables if configured
        if ($versioned) {
            $this->migrateTableData($sourceTable . '_Live', $targetTable . '_Live', $columnMapping);
            $this->migrateVersionsData($sourceTable . '_Versions', $targetTable . '_Versions', $columnMapping);
        }

        // Set marker field if configured
        if ($marker) {
            $this->setMarkerField($sourceTable, $marker, $versioned);
        }

        // Move old tables aside
        $this->moveTableAside($sourceTable);
        if ($versioned) {
            $this->moveTableAside($sourceTable . '_Live');
            $this->moveTableAside($sourceTable . '_Versions');
        }

        return 1;
    }

    /**
     * Migrate data from source to target table
     *
     * Inserts records from source that don't exist in target (by ID).
     * Also updates existing target records where columns are empty/null.
     *
     * @param string $source Source table name
     * @param string $target Target table name
     * @param array<string, string> $columnMapping Source column => target column mapping
     */
    protected function migrateTableData(string $source, string $target, array $columnMapping): void
    {
        $schema = $this->getSchema();

        if (!$schema->hasTable($source) || !$schema->hasTable($target)) {
            return;
        }

        // Find which columns to migrate
        $sourceFields = $schema->fieldList($source);
        $targetFields = $schema->fieldList($target);

        // Build column pairs for migration
        $columnPairs = $this->buildColumnPairs($sourceFields, $targetFields, $columnMapping);

        if (empty($columnPairs)) {
            DB::alteration_message("TableMerge: No compatible columns found for {$source} → {$target}", 'notice');
            return;
        }

        $conn = $this->getConn();
        $sourceColsSql = [];
        $targetColsSql = [];

        foreach ($columnPairs as $sourceCol => $targetCol) {
            $sourceColsSql[] = 's.' . $conn->escapeIdentifier($sourceCol);
            $targetColsSql[] = $conn->escapeIdentifier($targetCol);
        }

        // Insert records that don't exist in target
        $sql = sprintf(
            "INSERT INTO %s (%s) SELECT %s FROM %s s LEFT JOIN %s t ON s.ID = t.ID WHERE t.ID IS NULL",
            $conn->escapeIdentifier($target),
            implode(', ', $targetColsSql),
            implode(', ', $sourceColsSql),
            $conn->escapeIdentifier($source),
            $conn->escapeIdentifier($target)
        );

        DB::query($sql);
        $inserted = DB::affected_rows();

        // Update existing records where target columns are empty/null
        if ($inserted === 0 && count($columnPairs) > 1) {
            $this->updateExistingRecords($source, $target, $columnPairs);
        }

        if ($inserted > 0) {
            DB::alteration_message("{$target}: inserted {$inserted} record(s)", 'notice');
        }
    }

    /**
     * Handle _Versions table which uses RecordID/Version instead of ID
     *
     * Note: _Versions tables have their own auto-increment ID separate from RecordID,
     * so we exclude ID from the insert and let MySQL auto-generate it.
     *
     * @param string $source Source versions table name (e.g., 'BlockBanner_Versions')
     * @param string $target Target versions table name (e.g., 'BlockHero_Versions')
     * @param array<string, string> $columnMapping Source column => target column mapping
     */
    protected function migrateVersionsData(string $source, string $target, array $columnMapping): void
    {
        $schema = $this->getSchema();

        if (!$schema->hasTable($source) || !$schema->hasTable($target)) {
            return;
        }

        $sourceFields = $schema->fieldList($source);
        $targetFields = $schema->fieldList($target);

        // Build column pairs, excluding ID (Versions tables have their own auto-increment ID)
        $columnPairs = $this->buildColumnPairs($sourceFields, $targetFields, $columnMapping);
        unset($columnPairs['ID']);

        // Ensure RecordID and Version are included
        if (!isset($columnPairs['RecordID'])) {
            $columnPairs['RecordID'] = 'RecordID';
        }
        if (!isset($columnPairs['Version'])) {
            $columnPairs['Version'] = 'Version';
        }

        $conn = $this->getConn();
        $sourceColsSql = [];
        $targetColsSql = [];

        foreach ($columnPairs as $sourceCol => $targetCol) {
            $sourceColsSql[] = 's.' . $conn->escapeIdentifier($sourceCol);
            $targetColsSql[] = $conn->escapeIdentifier($targetCol);
        }

        $sql = sprintf(
            "INSERT INTO %s (%s) SELECT %s FROM %s s LEFT JOIN %s t ON s.RecordID = t.RecordID AND s.Version = t.Version WHERE t.RecordID IS NULL",
            $conn->escapeIdentifier($target),
            implode(', ', $targetColsSql),
            implode(', ', $sourceColsSql),
            $conn->escapeIdentifier($source),
            $conn->escapeIdentifier($target)
        );

        DB::query($sql);
        $inserted = DB::affected_rows();

        if ($inserted > 0) {
            DB::alteration_message("{$target}: inserted {$inserted} version record(s)", 'notice');
        }
    }

    /**
     * Build column pairs for migration based on mapping and matching names
     *
     * Strategy:
     * 1. Always include ID if both tables have it
     * 2. Add explicitly mapped columns (handles renamed columns)
     * 3. Add columns with matching names in both tables
     *
     * @param array<string, string> $sourceFields Source table field list (fieldName => spec)
     * @param array<string, string> $targetFields Target table field list (fieldName => spec)
     * @param array<string, string> $columnMapping Explicit source => target column mapping
     * @return array<string, string> Column pairs to migrate (sourceCol => targetCol)
     */
    protected function buildColumnPairs(array $sourceFields, array $targetFields, array $columnMapping): array
    {
        $pairs = [];

        // Always include ID if both tables have it
        if (isset($sourceFields['ID']) && isset($targetFields['ID'])) {
            $pairs['ID'] = 'ID';
        }

        // Add mapped columns
        foreach ($columnMapping as $sourceCol => $targetCol) {
            // Check if source column exists (may have been renamed already)
            $actualSourceCol = $this->findField($sourceFields, [$sourceCol, $targetCol]);
            if ($actualSourceCol && isset($targetFields[$targetCol])) {
                $pairs[$actualSourceCol] = $targetCol;
            }
        }

        // Add columns with matching names (excluding ID which is already handled)
        foreach ($sourceFields as $fieldName => $spec) {
            if ($fieldName === 'ID') {
                continue;
            }
            if (isset($targetFields[$fieldName]) && !isset($pairs[$fieldName])) {
                $pairs[$fieldName] = $fieldName;
            }
        }

        return $pairs;
    }

    /**
     * Find a field in the field list, checking multiple candidate names (case-insensitive)
     *
     * Useful when a column may have been renamed already by column_renames config.
     *
     * @param array<string, string> $fields Field list from schema->fieldList()
     * @param string[] $candidates List of possible field names to check
     * @return string|null The actual field name (original case) if found, null otherwise
     */
    protected function findField(array $fields, array $candidates): ?string
    {
        $fieldsLower = array_change_key_case($fields, CASE_LOWER);

        foreach ($candidates as $candidate) {
            $candidateLower = strtolower($candidate);
            if (isset($fieldsLower[$candidateLower])) {
                // Return the original case field name
                foreach ($fields as $fieldName => $spec) {
                    if (strtolower($fieldName) === $candidateLower) {
                        return $fieldName;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Update existing records in target where values are empty
     *
     * Called when source records already exist in target by ID.
     * Copies column values from source to target.
     *
     * @param string $source Source table name
     * @param string $target Target table name
     * @param array<string, string> $columnPairs Column pairs (sourceCol => targetCol)
     */
    protected function updateExistingRecords(string $source, string $target, array $columnPairs): void
    {
        $conn = $this->getConn();
        $updates = [];

        foreach ($columnPairs as $sourceCol => $targetCol) {
            if ($sourceCol === 'ID') {
                continue;
            }
            $updates[] = sprintf(
                "t.%s = s.%s",
                $conn->escapeIdentifier($targetCol),
                $conn->escapeIdentifier($sourceCol)
            );
        }

        if (empty($updates)) {
            return;
        }

        $sql = sprintf(
            "UPDATE %s t INNER JOIN %s s ON t.ID = s.ID SET %s",
            $conn->escapeIdentifier($target),
            $conn->escapeIdentifier($source),
            implode(', ', $updates)
        );

        DB::query($sql);
        $updated = DB::affected_rows();

        if ($updated > 0) {
            DB::alteration_message("{$target}: updated {$updated} existing record(s)", 'notice');
        }
    }

    /**
     * Set marker field on migrated records
     *
     * The marker field is useful for:
     * - Distinguishing migrated records from original ones
     * - Setting a style/variant value for correct template rendering
     * - Audit trail of which records came from the deprecated type
     *
     * @param string $sourceTable Source table name (used to identify records via JOIN)
     * @param array{table: string, column: string, value: string} $marker Marker configuration
     * @param bool $versioned Whether to also update _Live table
     */
    protected function setMarkerField(string $sourceTable, array $marker, bool $versioned): void
    {
        $schema = $this->getSchema();
        $markerTable = $marker['table'] ?? null;
        $markerColumn = $marker['column'] ?? null;
        $markerValue = $marker['value'] ?? null;

        if (!$markerTable || !$markerColumn || $markerValue === null) {
            return;
        }

        // Set on draft table
        if ($schema->hasTable($markerTable) && $schema->hasTable($sourceTable)) {
            $this->updateMarkerField($markerTable, $sourceTable, $markerColumn, $markerValue);
        }

        // Set on live table
        if ($versioned) {
            $liveMarkerTable = $markerTable . '_Live';
            $liveSourceTable = $sourceTable . '_Live';
            if ($schema->hasTable($liveMarkerTable) && $schema->hasTable($liveSourceTable)) {
                $this->updateMarkerField($liveMarkerTable, $liveSourceTable, $markerColumn, $markerValue);
            }
        }
    }

    /**
     * Update marker field value using prepared query
     *
     * Uses prepared_query() for safe parameter binding of the marker value.
     *
     * @param string $markerTable Table containing the marker column
     * @param string $sourceTable Source table (used to identify records via JOIN)
     * @param string $column Column name to update
     * @param string $value Value to set on matching records
     */
    protected function updateMarkerField(string $markerTable, string $sourceTable, string $column, string $value): void
    {
        $conn = $this->getConn();

        $sql = sprintf(
            "UPDATE %s m INNER JOIN %s s ON m.ID = s.ID SET m.%s = ? WHERE m.%s IS NULL OR m.%s = ''",
            $conn->escapeIdentifier($markerTable),
            $conn->escapeIdentifier($sourceTable),
            $conn->escapeIdentifier($column),
            $conn->escapeIdentifier($column),
            $conn->escapeIdentifier($column)
        );

        DB::prepared_query($sql, [$value]);
        $count = DB::affected_rows();

        if ($count > 0) {
            DB::alteration_message("{$markerTable}: set {$column}={$value} on {$count} record(s)", 'notice');
        }
    }

    /**
     * Move a table aside (rename to _obsolete_*)
     *
     * Uses counter suffix to avoid collisions with previously obsoleted tables.
     * E.g., if _obsolete_BlockBanner exists, creates _obsolete_BlockBanner_2.
     *
     * @param string $table Table name to move aside
     */
    protected function moveTableAside(string $table): void
    {
        $schema = $this->getSchema();

        if (!$schema->hasTable($table)) {
            return;
        }

        $conn = $this->getConn();
        $obsoleteName = '_obsolete_' . $table;

        // Find a unique name by adding counter suffix if needed
        if ($schema->hasTable($obsoleteName)) {
            $counter = 2;
            while ($schema->hasTable($obsoleteName . '_' . $counter)) {
                $counter++;
            }
            $obsoleteName = $obsoleteName . '_' . $counter;
        }

        DB::alteration_message("TableMerge: Moving {$table} → {$obsoleteName}", 'notice');
        DB::query(sprintf(
            "RENAME TABLE %s TO %s",
            $conn->escapeIdentifier($table),
            $conn->escapeIdentifier($obsoleteName)
        ));
    }
}