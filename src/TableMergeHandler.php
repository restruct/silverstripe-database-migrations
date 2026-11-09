<?php

namespace Restruct\SilverStripe\Migrations;

use InvalidArgumentException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
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
 *       merge_mode: fill_empty              # Same-ID rows: fill_empty (default) or overwrite (optional)
 * ```
 *
 * merge_mode decides what happens to a source row whose ID already exists in the target:
 * - fill_empty (default): only target columns that are NULL or an empty string get the source
 *   value, and a has_one ID column that is 0 (an unset relation); every other target value is
 *   kept. A merge in this mode never overwrites data.
 * - overwrite: the target row's columns are replaced by the source values.
 * Source rows whose ID is not in the target are inserted in both modes. The default for merges
 * without their own merge_mode is DatabaseMigrationExtension.table_merge_mode.
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
     * Same-ID target rows: only NULL or empty-string columns take the source value, and has_one
     * ID columns that are 0 (an unset relation)
     */
    public const MERGE_MODE_FILL_EMPTY = 'fill_empty';

    /**
     * Same-ID target rows: columns are replaced by the source values
     */
    public const MERGE_MODE_OVERWRITE = 'overwrite';

    /**
     * Every accepted merge_mode value
     */
    public const MERGE_MODES = [
        self::MERGE_MODE_FILL_EMPTY,
        self::MERGE_MODE_OVERWRITE,
    ];

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
     * @param array<string, array{target: string, columns?: array, marker?: array, versioned?: bool, merge_mode?: string}> $tableMerges
     * @param string $defaultMergeMode merge_mode for definitions that do not set their own. Untyped
     *                                 on purpose: it comes straight from YAML, and a null or a
     *                                 number must get the same named error as a typo, not a TypeError.
     * @return int Number of tables successfully merged
     * @throws InvalidArgumentException when the default or any definition's merge_mode is not one of MERGE_MODES
     */
    public function runTableMerges(array $tableMerges, $defaultMergeMode = self::MERGE_MODE_FILL_EMPTY): int
    {
        if (empty($tableMerges)) {
            return 0;
        }

        # Validate every mode BEFORE any table is touched: a typo in the third definition must not
        # leave the first two merged and the build half done. Throwing aborts dev/build, which is
        # the point - a mode that is silently ignored would write the wrong data.
        $this->assertValidMergeMode($defaultMergeMode, 'DatabaseMigrationExtension.table_merge_mode');
        foreach ($tableMerges as $sourceTable => $config) {
            if (is_array($config) && array_key_exists('merge_mode', $config)) {
                $this->assertValidMergeMode($config['merge_mode'], "table_merges.{$sourceTable}.merge_mode");
            }
        }

        $totalMerged = 0;

        foreach ($tableMerges as $sourceTable => $config) {
            $targetTable = $config['target'] ?? null;
            if (!$targetTable) {
                DB::alteration_message("TableMerge: Missing 'target' for {$sourceTable}", 'error');
                continue;
            }

            # Definitions without their own merge_mode take the default
            $config['merge_mode'] = $config['merge_mode'] ?? $defaultMergeMode;

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
     * Throw unless $mode is one of MERGE_MODES
     *
     * @param mixed $mode The configured value (from YAML, so not necessarily a string)
     * @param string $where Config path named in the error, so the operator can find the typo
     * @throws InvalidArgumentException
     */
    protected function assertValidMergeMode($mode, string $where): void
    {
        if (is_string($mode) && in_array($mode, self::MERGE_MODES, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'TableMerge: %s is %s, must be one of: %s',
            $where,
            var_export($mode, true),
            implode(', ', self::MERGE_MODES)
        ));
    }

    /**
     * Merge a single source table into a target table
     *
     * @param string $sourceTable Source table name (will be moved to _obsolete_*)
     * @param string $targetTable Target table name to merge into
     * @param array{columns?: array, marker?: array, versioned?: bool, merge_mode?: string} $config Merge configuration
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
        # runTableMerges() has validated and defaulted this; the fallback only covers a subclass
        # calling mergeTable() directly
        $mergeMode = $config['merge_mode'] ?? self::MERGE_MODE_FILL_EMPTY;

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
        $this->migrateTableData($sourceTable, $targetTable, $columnMapping, $mergeMode);

        // Migrate versioned tables if configured
        if ($versioned) {
            # _Live rows are matched on ID like the draft table, so the same merge_mode applies
            $this->migrateTableData($sourceTable . '_Live', $targetTable . '_Live', $columnMapping, $mergeMode);
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
     * Merges source rows whose ID already exists in the target according to $mergeMode (see
     * updateExistingRecords()), then inserts the source rows that don't exist in target (by ID).
     * Both happen on every merge, whether or not rows are inserted.
     * (was, 0.3: "Inserts records from source that don't exist in target (by ID). If that inserts
     * nothing and there is more than one column pair, copies the source values onto the same-ID
     * target rows, overwriting them" - so on a partial overlap the existing rows were not merged)
     * (was: "Also updates existing target records where columns are empty/null." - it never
     * checked for empty values.)
     *
     * @param string $source Source table name
     * @param string $target Target table name
     * @param array<string, string> $columnMapping Source column => target column mapping
     * @param string $mergeMode One of MERGE_MODES, for the same-ID rows
     */
    protected function migrateTableData(
        string $source,
        string $target,
        array $columnMapping,
        string $mergeMode = self::MERGE_MODE_FILL_EMPTY
    ): void {
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

        // Count the source rows whose ID already exists in the target. This must happen BEFORE the
        // insert: afterwards the freshly inserted rows would match the join too. Only used for the
        // partial-overlap warning below; it does not change what is written.
        # (#3) Now used to decide whether there are same-ID rows to merge, and for the message.
        $existing = (int) DB::query(sprintf(
            "SELECT COUNT(*) FROM %s s INNER JOIN %s t ON s.ID = t.ID",
            $conn->escapeIdentifier($source),
            $conn->escapeIdentifier($target)
        ))->value();

        # (#3) Merge the same-ID rows FIRST, so the update only ever sees rows that were already in
        # the target; after the insert below, the new rows would match the join as well.
        if ($existing > 0) {
            $this->updateExistingRecords($source, $target, $columnPairs, $mergeMode);
            DB::alteration_message(
                sprintf(
                    'TableMerge: %s -> %s: %d source row(s) already exist in %s by ID, merged with merge_mode %s',
                    $source,
                    $target,
                    $existing,
                    $target,
                    $mergeMode
                ),
                'notice'
            );
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

        // Copy source values onto same-ID target rows (overwrites; only when nothing was inserted and more than one column pair)
        // was: Update existing records where target columns are empty/null
        # (#3) Replaced by the merge_mode update above, which runs on every merge with same-ID rows.
        // if ($inserted === 0 && count($columnPairs) > 1) {
        //     $this->updateExistingRecords($source, $target, $columnPairs);
        // }

        if ($inserted > 0) {
            DB::alteration_message("{$target}: inserted {$inserted} record(s)", 'notice');
        }

        // Partial overlap: some rows were inserted, so the same-ID update above did not run and the
        // source values of the rows that already existed in the target were dropped. Say so, loudly,
        // rather than letting the merge look complete. (Whether they should be merged is an open
        // design question for 1.0; the write behaviour is deliberately unchanged here.)
        # (#3) Settled: same-ID rows are now always merged (per merge_mode, above), so no rows are
        # dropped any more and this warning would be false.
        // if ($inserted > 0 && $existing > 0) {
        //     DB::alteration_message(
        //         sprintf(
        //             'TableMerge: %s -> %s: %d source row(s) already exist in %s by ID and were NOT updated'
        //             . ' (existing rows are only updated when a merge inserts nothing)',
        //             $source,
        //             $target,
        //             $existing,
        //             $target
        //         ),
        //         'error'
        //     );
        // }
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
     * Merge source values into the target rows that have the same ID, per $mergeMode
     * (was: "Overwrite existing records in target with the source values")
     * (was: "Update existing records in target where values are empty" - there is no empty check)
     *
     * Called when source records already exist in target by ID.
     * - overwrite: copies every paired column value from source to target, NULLs included.
     * - fill_empty: copies a source value only into a target column that is NULL or an empty
     *   string, or into a has_one ID column that is 0 (see isHasOneIdColumn()). In every other
     *   column 0, '0' and a zero date are values, not empty, so they are kept.
     *   (was: "string. 0, '0' and a zero date are values, not empty, so they are kept.")
     * Only the paired columns (mapped and same-named) are touched; target-only columns are not.
     *
     * @param string $source Source table name
     * @param string $target Target table name
     * @param array<string, string> $columnPairs Column pairs (sourceCol => targetCol)
     * @param string $mergeMode One of MERGE_MODES. Defaults to overwrite, which is what this method
     *                          did before merge_mode existed, for a subclass still calling it
     *                          with three arguments.
     */
    protected function updateExistingRecords(
        string $source,
        string $target,
        array $columnPairs,
        string $mergeMode = self::MERGE_MODE_OVERWRITE
    ): void {
        $conn = $this->getConn();
        $updates = [];

        foreach ($columnPairs as $sourceCol => $targetCol) {
            if ($sourceCol === 'ID') {
                continue;
            }
            $targetSql = $conn->escapeIdentifier($targetCol);
            $sourceSql = $conn->escapeIdentifier($sourceCol);

            if ($mergeMode === self::MERGE_MODE_FILL_EMPTY) {
                # Keep the target value unless it is NULL or ''. The CAST matters: MySQL compares a
                # number with '' by converting '' to 0, so a plain `t.X = ''` would treat an int 0
                # (a Boolean false, a Sort of 0) as empty and overwrite it.
                $emptySql = "t.%1\$s IS NULL OR CAST(t.%1\$s AS CHAR) = ''";
                # A has_one ID of 0 is how Silverstripe stores "no relation", so there it is empty
                # too. Compared as a string for the same reason as above: `t.X = 0` would also
                # match any non-numeric string, should the column not be an int after all.
                if ($this->isHasOneIdColumn($target, $targetCol)) {
                    $emptySql .= " OR CAST(t.%1\$s AS CHAR) = '0'";
                }
                $updates[] = sprintf(
                    "t.%1\$s = CASE WHEN {$emptySql} THEN s.%2\$s ELSE t.%1\$s END",
                    $targetSql,
                    $sourceSql
                );
                continue;
            }

            $updates[] = sprintf(
                "t.%s = s.%s",
                $targetSql,
                $sourceSql
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
            # affected_rows() counts rows whose values actually changed
            $what = $mergeMode === self::MERGE_MODE_FILL_EMPTY ? 'filled empty columns of' : 'updated';
            DB::alteration_message("{$target}: {$what} {$updated} existing record(s)", 'notice');
        }
    }

    /**
     * Whether $column of $table holds a has_one foreign key, for which 0 means "no relation"
     *
     * Decided from the DataObject schema when $table belongs to a DataObject class: the column is
     * a has_one ID when it is "{Relation}ID" and the class has that has_one. That keeps an ordinary
     * int field that merely ends in ID (an ExternalID, say) out of it. The target of a merge is
     * usually the table of a live replacement class, so this is the normal path.
     *
     * When no class owns the table (a legacy or hand-made table, or one not in the manifest),
     * there is no schema to ask, and any column whose name ends in "ID" counts: that is how
     * Silverstripe names has_one columns. The table's own ID column never reaches this method.
     *
     * @param string $table Target table name; a _Live table is resolved to its base table's class
     * @param string $column Target column name
     */
    protected function isHasOneIdColumn(string $table, string $column): bool
    {
        if (strlen($column) <= 2 || substr($column, -2) !== 'ID') {
            return false;
        }

        $class = $this->getTableClass($table);
        if ($class === null) {
            # No schema to ask: fall back to the naming convention
            return true;
        }

        return DataObject::getSchema()->hasOneComponent($class, substr($column, 0, -2)) !== null;
    }

    /**
     * The DataObject class whose own table is $table (or, for "X_Live", whose table is X)
     *
     * Stricter than DataObjectSchema::tableClass(), which keeps stripping "_suffix" parts until
     * something matches, so a raw "Page_Tags" would resolve to Page. Here the class must own the
     * (base) table exactly.
     *
     * @param string $table Table name
     * @return string|null The class, or null when no DataObject owns that table
     */
    protected function getTableClass(string $table): ?string
    {
        $schema = DataObject::getSchema();
        $baseTable = preg_replace('/_Live$/', '', $table);
        $class = $schema->tableClass($baseTable);
        if ($class && $schema->tableName($class) === $baseTable) {
            return $class;
        }

        return null;
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