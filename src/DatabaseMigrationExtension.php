<?php

namespace Restruct\SilverStripe\Migrations;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Handles database migrations during dev/build:
 * - ClassName value remapping (reads from DataObject::$legacy_classnames)
 * - Table renames (reads from DataObject::$legacy_table_names + static config)
 * - Column renames (from static config)
 * - Table merges (merge deprecated tables into their replacements)
 *
 * Applied to DatabaseAdmin via config to ensure migrations run before
 * SilverStripe processes any schema updates.
 *
 * Silverstripe 5 builds the database through SilverStripe\ORM\DatabaseAdmin, Silverstripe 6 through
 * SilverStripe\Dev\Command\DbBuild. Both fire onBeforeBuild/onAfterBuild and both own a
 * classname_value_remapping config; _config/config.yml applies this extension to whichever exists.
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
            // Write to the class that is actually running the build (DatabaseAdmin on SS5, DbBuild on
            // SS6): its migrateClassNames() reads static::config(), so config set on any other class is
            // never consulted. This used to be hardcoded to DatabaseAdmin::class, which does not exist on
            // SS6 - the remapping would have been set on a dead key and silently never applied.
            $buildClass = $this->getBuildClass();
            $existingRemappings = Config::inst()->get($buildClass, 'classname_value_remapping') ?: [];
            $mergedRemappings = array_merge($existingRemappings, $remappings);
            Config::modify()->set($buildClass, 'classname_value_remapping', $mergedRemappings);

            DB::alteration_message(
                sprintf('DatabaseMigration: Registered %d classname remappings', count($remappings)),
                'notice'
            );
        }
    }

    /**
     * The class whose doBuild() is running: the extension's owner when invoked through the hook,
     * otherwise whichever build class this Silverstripe major has.
     */
    protected function getBuildClass(): string
    {
        $owner = $this->getOwner();
        if ($owner) {
            return get_class($owner);
        }

        // Class names as strings, not ::class on an import: neither class exists on both majors.
        return class_exists('SilverStripe\\Dev\\Command\\DbBuild')
            ? 'SilverStripe\\Dev\\Command\\DbBuild'
            : 'SilverStripe\\ORM\\DatabaseAdmin';
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
                    // $obsoleteName = '_obsolete_' . $newName;
                    // A fixed name made RENAME TABLE fail (and dev/build abort) whenever an
                    // _obsolete_ copy was already there, e.g. on a restored or re-migrated database.
                    // Same counter scheme as TableMergeHandler::moveTableAside().
                    $obsoleteName = $this->getFreeObsoleteTableName($newName, $existingTables);
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

    /**
     * First free "_obsolete_<table>" name, suffixed _2, _3, ... when earlier copies exist.
     *
     * @param array $existingTables DB::table_list() with lower-cased keys
     */
    protected function getFreeObsoleteTableName(string $table, array $existingTables): string
    {
        $obsoleteName = '_obsolete_' . $table;
        if (!isset($existingTables[strtolower($obsoleteName)])) {
            return $obsoleteName;
        }

        $counter = 2;
        while (isset($existingTables[strtolower($obsoleteName . '_' . $counter)])) {
            $counter++;
        }

        return $obsoleteName . '_' . $counter;
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

                // $columnType = $columnInfo['Type'];
                // $nullable = $columnInfo['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
                // $default = $columnInfo['Default'] !== null
                //     ? "DEFAULT " . $conn->quoteString($columnInfo['Default'])
                //     : '';
                //
                // $sql = sprintf(
                //     "ALTER TABLE %s CHANGE %s %s %s %s %s",
                //     $conn->escapeIdentifier($tableName),
                //     $conn->escapeIdentifier($oldColumn),
                //     $conn->escapeIdentifier($newColumn),
                //     $columnType,
                //     $nullable,
                //     $default
                // );
                # (#2) The CHANGE above was rebuilt from Type/Null/Default only, so a rename dropped
                # AUTO_INCREMENT and ON UPDATE (Extra), the column collation and its comment, and
                # quoted expression defaults (CURRENT_TIMESTAMP became the string 'CURRENT_TIMESTAMP').
                # RENAME COLUMN changes the name only and leaves the definition alone; servers that
                # predate it get a CHANGE that repeats the column's own definition verbatim.
                $sql = $this->getRenameColumnSql($tableName, $columnInfo['Field'], $newColumn);
                if ($sql === null) {
                    DB::alteration_message(
                        "WARNING: Could not read the definition of {$tableName}.{$oldColumn} - column not renamed",
                        'error'
                    );
                    continue;
                }

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
     * The ALTER TABLE statement that renames a column while keeping its whole definition: type,
     * NULL-ability, default (literal or expression), AUTO_INCREMENT, ON UPDATE, character set,
     * collation and comment. Null when the column definition cannot be read (fallback path only).
     */
    protected function getRenameColumnSql(string $table, string $oldColumn, string $newColumn): ?string
    {
        $conn = DB::get_conn();

        if ($this->supportsRenameColumn()) {
            return sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $conn->escapeIdentifier($table),
                $conn->escapeIdentifier($oldColumn),
                $conn->escapeIdentifier($newColumn)
            );
        }

        # Older servers only have CHANGE, which takes a full column definition. Take it from SHOW
        # CREATE TABLE as the server prints it, rather than reassembling it from SHOW COLUMNS: that
        # is the one source that carries every attribute, with defaults already quoted the way the
        # server wants them back (a literal quoted, CURRENT_TIMESTAMP and other expressions bare),
        # which SHOW COLUMNS reports differently on MySQL and MariaDB.
        $definition = $this->getColumnDefinition($table, $oldColumn);
        if ($definition === null) {
            return null;
        }

        return sprintf(
            'ALTER TABLE %s CHANGE %s %s %s',
            $conn->escapeIdentifier($table),
            $conn->escapeIdentifier($oldColumn),
            $conn->escapeIdentifier($newColumn),
            $definition
        );
    }

    /**
     * Whether the server has ALTER TABLE ... RENAME COLUMN: MySQL 8.0 and MariaDB 10.5.2 onwards.
     */
    protected function supportsRenameColumn(): bool
    {
        # SELECT VERSION() rather than the connector's server_info: through some client libraries
        # MariaDB's server_info carries a "5.5.5-" replication prefix in front of the real version.
        $version = (string) DB::query('SELECT VERSION()')->value();
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)) {
            # Unknown version string: take the path that works on every MySQL-family server
            return false;
        }

        if (stripos($version, 'mariadb') !== false) {
            return version_compare($matches[1], '10.5.2', '>=');
        }

        return version_compare($matches[1], '8.0.0', '>=');
    }

    /**
     * The column's definition (everything after its name) from SHOW CREATE TABLE, without the
     * trailing comma - e.g. `int(11) NOT NULL AUTO_INCREMENT`.
     */
    protected function getColumnDefinition(string $table, string $column): ?string
    {
        $conn = DB::get_conn();

        # MariaDB's ANSI sql_mode (Silverstripe's default) makes SHOW CREATE TABLE print portable SQL
        # and leave out server-specific column options - AUTO_INCREMENT among them, the very thing
        # this must keep. So read it under plain ANSI_QUOTES (which the identifier quoting below
        # still relies on) and put the session's own mode back afterwards, also on failure.
        $sqlMode = (string) DB::query('SELECT @@SESSION.sql_mode')->value();
        DB::query("SET SESSION sql_mode = 'ANSI_QUOTES'");
        try {
            $row = DB::query('SHOW CREATE TABLE ' . $conn->escapeIdentifier($table))->record();
        } finally {
            DB::query('SET SESSION sql_mode = ' . $conn->quoteString($sqlMode));
        }
        $createSql = $row ? (string) (array_values($row)[1] ?? '') : '';

        foreach (preg_split('/\R/', $createSql) as $line) {
            # A column line is "  <quoted name> <definition>,". The quote is " under ANSI_QUOTES
            # (set above); a backtick is accepted too, in case a server ignores the mode for this
            # output. A quote inside the name is doubled. Key, index and constraint lines start with
            # a keyword, not a quote.
            if (!preg_match('/^\s*(["`])((?:(?!\1).|\1\1)+)\1\s+(.+?),?\s*$/', $line, $matches)) {
                continue;
            }
            $name = str_replace($matches[1] . $matches[1], $matches[1], $matches[2]);
            if (strcasecmp($name, $column) === 0) {
                return $matches[3];
            }
        }

        return null;
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
