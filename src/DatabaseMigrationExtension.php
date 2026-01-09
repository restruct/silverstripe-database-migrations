<?php

namespace Restruct\SilverStripe\Migrations;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DatabaseAdmin;

/**
 * Handles database migrations during dev/build:
 * - ClassName value remapping (reads from DataObject::$legacy_classnames)
 * - Table renames (reads from DataObject::$legacy_table_names + static config)
 * - Column renames (from static config)
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

    private static bool $migrations_run = false;

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

        $conn = DB::get_conn();
        $existingTables = array_change_key_case(DB::table_list(), CASE_LOWER);
        $renamedCount = 0;

        foreach ($columnRenames as $tableName => $columns) {
            if (!isset($existingTables[strtolower($tableName)])) {
                continue;
            }

            // Get existing columns for this table
            $existingColumns = [];
            $columnsResult = DB::query("SHOW COLUMNS FROM " . $conn->escapeIdentifier($tableName));
            foreach ($columnsResult as $row) {
                $existingColumns[strtolower($row['Field'])] = $row;
            }

            foreach ($columns as $oldColumn => $newColumn) {
                $oldColLower = strtolower($oldColumn);
                $newColLower = strtolower($newColumn);

                // Skip if old column doesn't exist
                if (!isset($existingColumns[$oldColLower])) {
                    continue;
                }

                // Skip if new column already exists (migration already done)
                if (isset($existingColumns[$newColLower])) {
                    continue;
                }

                $columnInfo = $existingColumns[$oldColLower];
                $columnType = $columnInfo['Type'];
                $nullable = $columnInfo['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
                $default = $columnInfo['Default'] !== null ? "DEFAULT " . $conn->quoteString($columnInfo['Default']) : '';

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
}
