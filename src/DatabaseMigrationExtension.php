<?php

namespace Restruct\SilverStripe\Migrations;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DatabaseAdmin;

/**
 * Handles database migrations during dev/build:
 * - ClassName value remapping (reads from DataObject::$legacy_classnames)
 * - Table renames (reads from DataObject::$legacy_table_names + static config)
 * - Column renames (from static config)
 * - Table merges (merge deprecated tables into their replacements)
 *
 * Applied to DatabaseAdmin via config to ensure migrations run before
 * SilverStripe processes any schema updates.
 */
class DatabaseMigrationExtension extends Extension
{
    use Configurable;

    /**
     * Additional classname mappings (for non-DataObject classes)
     * @config
     */
    private static array $classname_mappings = [];

    /**
     * Additional table mappings (for join tables, versioned tables, etc.)
     * @config
     */
    private static array $table_mappings = [];

    /**
     * Column renames: [table => [old_column => new_column]]
     * Useful for fixing name collisions (e.g., field named same as table)
     * @config
     */
    private static array $column_renames = [];

    /**
     * Table merges: merge data from deprecated tables into replacement tables
     * Format: [source_table => [target => ..., columns => [...], marker => [...], versioned => bool]]
     * @config
     * @see TableMergeHandler for full config documentation
     */
    private static array $table_merges = [];

    private static bool $migrations_run = false;
    private static bool $merges_run = false;

    /**
     * Runs before dev/build processes any DataObject schemas.
     */
    public function onBeforeBuild(): void
    {
        if (self::$migrations_run) {
            return;
        }
        self::$migrations_run = true;

        $this->setupClassnameRemapping();
        $this->runTableMigrations();
        $this->runColumnRenames();
    }

    /**
     * Runs after dev/build has processed schema updates.
     * Table merges run here so both source and target tables exist.
     */
    public function onAfterBuild(): void
    {
        if (self::$merges_run) {
            return;
        }
        self::$merges_run = true;

        $this->runTableMerges();
    }

    /**
     * Run configured table merges
     */
    protected function runTableMerges(): void
    {
        $tableMerges = static::config()->get('table_merges') ?: [];
        if (empty($tableMerges)) {
            return;
        }

        /** @var TableMergeHandler $handler */
        $handler = Injector::inst()->get(TableMergeHandler::class);
        $handler->runTableMerges($tableMerges);
    }

    /**
     * Collect legacy_classnames from all DataObjects and inject into DatabaseAdmin config
     */
    protected function setupClassnameRemapping(): void
    {
        $remappings = [];

        // Get global mappings from config
        $globalMappings = static::config()->get('classname_mappings') ?: [];
        $remappings = array_merge($remappings, $globalMappings);

        // Collect from DataObjects
        $dataObjectClasses = ClassInfo::subclassesFor(DataObject::class, false);
        foreach ($dataObjectClasses as $class) {
            $legacyClassnames = Config::inst()->get($class, 'legacy_classnames', Config::UNINHERITED);
            if (!empty($legacyClassnames) && is_array($legacyClassnames)) {
                foreach ($legacyClassnames as $oldClassName) {
                    $remappings[$oldClassName] = $class;
                }
            }
        }

        if (!empty($remappings)) {
            $existingRemappings = Config::inst()->get(DatabaseAdmin::class, 'classname_value_remapping') ?: [];
            $mergedRemappings = array_merge($existingRemappings, $remappings);
            Config::modify()->set(DatabaseAdmin::class, 'classname_value_remapping', $mergedRemappings);

            DB::alteration_message(
                sprintf('DatabaseMigration: Registered %d classname remappings', count($remappings)),
                'notice'
            );
        }
    }

    /**
     * Collect legacy_table_names from DataObjects and run table renames
     */
    protected function runTableMigrations(): void
    {
        $tableMappings = [];

        // Get global mappings from config
        $globalMappings = static::config()->get('table_mappings') ?: [];
        $tableMappings = array_merge($tableMappings, $globalMappings);

        // Collect from DataObjects
        $dataObjectClasses = ClassInfo::subclassesFor(DataObject::class, false);
        foreach ($dataObjectClasses as $class) {
            $legacyTableNames = Config::inst()->get($class, 'legacy_table_names', Config::UNINHERITED);
            $currentTableName = Config::inst()->get($class, 'table_name', Config::UNINHERITED);

            if (!empty($legacyTableNames) && is_array($legacyTableNames) && $currentTableName) {
                foreach ($legacyTableNames as $oldTableName) {
                    $tableMappings[$oldTableName] = $currentTableName;
                }
            }
        }

        if (empty($tableMappings)) {
            return;
        }

        $conn = DB::get_conn();
        $renamedCount = 0;

        foreach ($tableMappings as $oldName => $newName) {
            $existingTables = array_change_key_case(DB::table_list(), CASE_LOWER);

            $oldExists = isset($existingTables[strtolower($oldName)]);
            $newExists = isset($existingTables[strtolower($newName)]);

            if (!$oldExists) {
                continue;
            }

            if ($oldExists && !$newExists) {
                $this->renameTable($conn, $oldName, $newName);
                $renamedCount++;
                continue;
            }

            if ($oldExists && $newExists) {
                $newCount = (int) DB::query("SELECT COUNT(*) FROM " . $conn->escapeIdentifier($newName))->value();

                if ($newCount === 0) {
                    $obsoleteName = '_obsolete_' . $newName;
                    DB::alteration_message("Moving empty table aside: {$newName} -> {$obsoleteName}", 'notice');
                    DB::query("RENAME TABLE " . $conn->escapeIdentifier($newName) . " TO " . $conn->escapeIdentifier($obsoleteName));

                    $this->renameTable($conn, $oldName, $newName);
                    $renamedCount++;
                } else {
                    DB::alteration_message(
                        "WARNING: Both '{$oldName}' and '{$newName}' exist with data - manual merge required!",
                        'error'
                    );
                }
            }
        }

        if ($renamedCount > 0) {
            DB::alteration_message(
                sprintf('DatabaseMigration: Renamed %d tables', $renamedCount),
                'changed'
            );
        }
    }

    protected function renameTable($conn, string $oldName, string $newName): void
    {
        DB::alteration_message("Renaming table: {$oldName} -> {$newName}", 'changed');
        DB::query("RENAME TABLE " . $conn->escapeIdentifier($oldName) . " TO " . $conn->escapeIdentifier($newName));
    }

    /**
     * Rename columns in specified tables
     * Format: [table => [old_column => new_column]]
     */
    protected function runColumnRenames(): void
    {
        $columnRenames = static::config()->get('column_renames') ?: [];
        if (empty($columnRenames)) {
            return;
        }

        $schema = DB::get_schema();
        $conn = DB::get_conn();
        $renamedCount = 0;

        foreach ($columnRenames as $tableName => $columns) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            // Get existing columns using framework helper
            $existingColumns = $schema->fieldList($tableName);
            $existingColumnsLower = array_change_key_case($existingColumns, CASE_LOWER);

            foreach ($columns as $oldColumn => $newColumn) {
                $oldColLower = strtolower($oldColumn);
                $newColLower = strtolower($newColumn);

                // Skip if old column doesn't exist
                if (!isset($existingColumnsLower[$oldColLower])) {
                    continue;
                }

                // Skip if new column already exists (migration already done)
                if (isset($existingColumnsLower[$newColLower])) {
                    continue;
                }

                // The fieldList returns column spec strings, but we need raw column info for CHANGE
                // Use SHOW COLUMNS for the detailed info we need
                $columnInfo = $this->getColumnInfo($tableName, $oldColumn);
                if (!$columnInfo) {
                    continue;
                }

                $columnType = $columnInfo['Type'];
                $nullable = $columnInfo['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
                $default = $columnInfo['Default'] !== null
                    ? "DEFAULT " . $conn->quoteString($columnInfo['Default'])
                    : '';

                $sql = sprintf(
                    "ALTER TABLE %s CHANGE %s %s %s %s %s",
                    $conn->escapeIdentifier($tableName),
                    $conn->escapeIdentifier($oldColumn),
                    $conn->escapeIdentifier($newColumn),
                    $columnType,
                    $nullable,
                    $default
                );

                DB::alteration_message("Renaming column: {$tableName}.{$oldColumn} -> {$newColumn}", 'changed');
                DB::query($sql);
                $renamedCount++;
            }
        }

        if ($renamedCount > 0) {
            DB::alteration_message(
                sprintf('DatabaseMigration: Renamed %d columns', $renamedCount),
                'changed'
            );
        }
    }

    /**
     * Get detailed column info for a specific column
     */
    protected function getColumnInfo(string $table, string $column): ?array
    {
        $conn = DB::get_conn();
        $result = DB::query("SHOW COLUMNS FROM " . $conn->escapeIdentifier($table));

        foreach ($result as $row) {
            if (strcasecmp($row['Field'], $column) === 0) {
                return $row;
            }
        }

        return null;
    }
}
