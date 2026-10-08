<?php

namespace Restruct\SilverStripe\Migrations\Tests;

use Restruct\SilverStripe\Migrations\DatabaseMigrationExtension;
use Restruct\SilverStripe\Migrations\Tests\Stub\ChangeOnlyMigrationExtension;
use Restruct\SilverStripe\Migrations\Tests\Stub\MigratedThing;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;

/**
 * Behaviour of the dev/build hooks, run through the build class this Silverstripe major actually
 * uses (DatabaseAdmin on 5, DbBuild on 6) rather than by calling the extension directly - so a
 * wiring mistake in _config/config.yml fails here too.
 */
class DatabaseMigrationExtensionTest extends SapphireTest
{
    use RawTablesTrait;

    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        MigratedThing::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetRunFlags();
        // alteration_message() echoes on the CLI; keep the test output clean
        DB::quiet(true);
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();
        $this->resetRunFlags();
        DB::quiet(false);
        parent::tearDown();
    }

    /**
     * The class whose doBuild() runs dev/build on this major. Strings, not ::class on imports,
     * because neither class exists on both majors.
     */
    protected function buildClass(): string
    {
        return class_exists('SilverStripe\\Dev\\Command\\DbBuild')
            ? 'SilverStripe\\Dev\\Command\\DbBuild'
            : 'SilverStripe\\ORM\\DatabaseAdmin';
    }

    /**
     * Fire the hook the same way doBuild() does: through extend() on the build class, with that
     * major's arguments - other extensions on the build class (framework's own relation
     * validation, for one) declare them as required.
     */
    protected function fireHook(string $hook): object
    {
        $builder = Injector::inst()->create($this->buildClass());
        if (class_exists('SilverStripe\\PolyExecution\\PolyOutput')) {
            // SS6: ($output, $populate, $testMode)
            $output = new \SilverStripe\PolyExecution\PolyOutput(
                \SilverStripe\PolyExecution\PolyOutput::FORMAT_ANSI,
                \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_QUIET
            );
            $populate = false;
            $testMode = true;
            // extend() takes its arguments by reference, so they must be variables
            $builder->extend($hook, $output, $populate, $testMode);
        } else {
            // SS5: ($quiet, $populate, $testMode)
            $quiet = true;
            $populate = false;
            $testMode = true;
            $builder->extend($hook, $quiet, $populate, $testMode);
        }
        return $builder;
    }

    /**
     * Regression: on Silverstripe 6 the extension was applied to DatabaseAdmin only, a class that
     * no longer exists, so none of the migrations ran and nothing said so.
     */
    public function testExtensionIsAppliedToThisMajorsBuildClass(): void
    {
        $buildClass = $this->buildClass();
        $this->assertTrue(
            $buildClass::has_extension(DatabaseMigrationExtension::class),
            "DatabaseMigrationExtension is not applied to {$buildClass}"
        );
    }

    /**
     * Regression: the remapping was written to DatabaseAdmin's config, which DbBuild never reads.
     * It must land in the config of the class whose migrateClassNames() runs.
     */
    public function testLegacyClassnamesAreRegisteredOnTheBuildClass(): void
    {
        Config::modify()->set(DatabaseMigrationExtension::class, 'classname_mappings', [
            'Old\\Namespace\\Deleted' => MigratedThing::class,
        ]);

        $builder = $this->fireHook('onBeforeBuild');

        // What migrateClassNames() reads: static::config() on the running build class
        $remapping = $builder->config()->get('classname_value_remapping');
        $this->assertSame(MigratedThing::class, $remapping['Old\\Namespace\\DbmThing'] ?? null, 'from $legacy_classnames');
        $this->assertSame(MigratedThing::class, $remapping['Old\\Namespace\\Deleted'] ?? null, 'from classname_mappings');
    }

    public function testLegacyClassnamesMergeWithExistingRemapping(): void
    {
        $buildClass = $this->buildClass();
        Config::modify()->set($buildClass, 'classname_value_remapping', ['Pre\\Existing' => 'Some\\Target']);

        $this->fireHook('onBeforeBuild');

        $remapping = Config::inst()->get($buildClass, 'classname_value_remapping');
        $this->assertSame('Some\\Target', $remapping['Pre\\Existing'] ?? null);
        $this->assertSame(MigratedThing::class, $remapping['Old\\Namespace\\DbmThing'] ?? null);
    }

    public function testLegacyTableIsRenamedWhenCurrentTableIsAbsent(): void
    {
        DB::query('DROP TABLE "DbmTestThing"');
        $this->createRawTable('DbmTestOldThing', '"ID" int NOT NULL AUTO_INCREMENT PRIMARY KEY, "Title" varchar(255)');
        DB::query('INSERT INTO "DbmTestOldThing" ("Title") VALUES (\'kept\')');

        $this->fireHook('onBeforeBuild');

        $this->assertFalse($this->tableExists('DbmTestOldThing'));
        $this->assertSame(['kept'], $this->column('DbmTestThing', 'Title'));
    }

    public function testEmptyCurrentTableIsMovedAsideBeforeRename(): void
    {
        // DbmTestThing exists (built from the stub) and is empty
        $this->createRawTable('DbmTestOldThing', '"ID" int NOT NULL AUTO_INCREMENT PRIMARY KEY, "Title" varchar(255)');
        DB::query('INSERT INTO "DbmTestOldThing" ("Title") VALUES (\'kept\')');

        $this->fireHook('onBeforeBuild');

        $this->assertTrue($this->tableExists('_obsolete_DbmTestThing'));
        $this->assertFalse($this->tableExists('DbmTestOldThing'));
        $this->assertSame(['kept'], $this->column('DbmTestThing', 'Title'));
    }

    /**
     * Regression: the empty table was always moved to a fixed "_obsolete_<table>" name, so a
     * leftover copy from an earlier run made RENAME TABLE fail and aborted dev/build.
     */
    public function testObsoleteNameCollisionDoesNotAbortTheBuild(): void
    {
        $this->createRawTable('_obsolete_DbmTestThing', '"ID" int');
        $this->createRawTable('DbmTestOldThing', '"ID" int NOT NULL AUTO_INCREMENT PRIMARY KEY, "Title" varchar(255)');
        DB::query('INSERT INTO "DbmTestOldThing" ("Title") VALUES (\'kept\')');

        $this->fireHook('onBeforeBuild');

        $this->assertTrue($this->tableExists('_obsolete_DbmTestThing_2'));
        $this->assertSame(['kept'], $this->column('DbmTestThing', 'Title'));
    }

    public function testBothTablesWithDataAreLeftForManualMerge(): void
    {
        DB::query('INSERT INTO "DbmTestThing" ("ClassName", "Title") VALUES (' . DB::get_conn()->quoteString(MigratedThing::class) . ', \'current\')');
        $this->createRawTable('DbmTestOldThing', '"ID" int NOT NULL AUTO_INCREMENT PRIMARY KEY, "Title" varchar(255)');
        DB::query('INSERT INTO "DbmTestOldThing" ("Title") VALUES (\'legacy\')');

        $this->fireHook('onBeforeBuild');

        $this->assertSame(['current'], $this->column('DbmTestThing', 'Title'));
        $this->assertSame(['legacy'], $this->column('DbmTestOldThing', 'Title'));
        $this->assertFalse($this->tableExists('_obsolete_DbmTestThing'));
    }

    public function testConfiguredTableMappingsRenameNonDataObjectTables(): void
    {
        Config::modify()->set(DatabaseMigrationExtension::class, 'table_mappings', [
            'DbmOldJoin' => 'DbmNewJoin',
        ]);
        $this->createRawTable('DbmOldJoin', '"ID" int');
        DB::query('INSERT INTO "DbmOldJoin" ("ID") VALUES (7)');

        $this->fireHook('onBeforeBuild');

        $this->assertFalse($this->tableExists('DbmOldJoin'));
        $this->assertSame(['7'], array_map('strval', $this->column('DbmNewJoin', 'ID')));
    }

    public function testColumnRenameKeepsTypeNullabilityAndDefault(): void
    {
        Config::modify()->set(DatabaseMigrationExtension::class, 'column_renames', [
            'DbmCols' => ['OldName' => 'NewName'],
        ]);
        $this->createRawTable('DbmCols', '"ID" int, "OldName" varchar(50) NOT NULL DEFAULT \'dflt\'');
        DB::query('INSERT INTO "DbmCols" ("ID", "OldName") VALUES (1, \'value\')');

        $this->fireHook('onBeforeBuild');

        $cols = [];
        foreach (DB::query('SHOW COLUMNS FROM "DbmCols"') as $row) {
            $cols[$row['Field']] = $row;
        }
        $this->assertArrayNotHasKey('OldName', $cols);
        $this->assertArrayHasKey('NewName', $cols);
        $this->assertSame('varchar(50)', $cols['NewName']['Type']);
        $this->assertSame('NO', $cols['NewName']['Null']);
        // MariaDB reports a string default quoted, MySQL unquoted
        $this->assertSame('dflt', trim((string) $cols['NewName']['Default'], "'"));
        $this->assertSame(['value'], $this->column('DbmCols', 'NewName'));
    }

    /**
     * Configure one column rename and run onBeforeBuild. With $viaChange the extension is swapped
     * for one that takes the CHANGE fallback (servers without RENAME COLUMN), so both paths run on
     * any server.
     */
    protected function renameColumn(string $table, string $old, string $new, bool $viaChange): void
    {
        $renames = [$table => [$old => $new]];
        Config::modify()->set(DatabaseMigrationExtension::class, 'column_renames', $renames);
        if ($viaChange) {
            Config::modify()->set(ChangeOnlyMigrationExtension::class, 'column_renames', $renames);
            // Extensible resolves extension instances through Injector::get()
            Injector::inst()->registerService(new ChangeOnlyMigrationExtension(), DatabaseMigrationExtension::class);
        }

        $this->fireHook('onBeforeBuild');
    }

    /**
     * SHOW FULL COLUMNS row of one column, or null when it does not exist.
     */
    protected function fullColumn(string $table, string $column): ?array
    {
        foreach (DB::query(sprintf('SHOW FULL COLUMNS FROM "%s"', $table)) as $row) {
            if ($row['Field'] === $column) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Regression (#2): the rename was rebuilt from Type/Null/Default, which dropped AUTO_INCREMENT,
     * so the next insert without an ID got 0 (or failed) instead of the next sequence value.
     */
    protected function assertRenameKeepsAutoIncrement(bool $viaChange): void
    {
        $this->createRawTable('DbmAi', '"OldId" int NOT NULL AUTO_INCREMENT PRIMARY KEY, "Title" varchar(50)');
        DB::query('INSERT INTO "DbmAi" ("Title") VALUES (\'first\')');

        $this->renameColumn('DbmAi', 'OldId', 'NewId', $viaChange);

        $this->assertNull($this->fullColumn('DbmAi', 'OldId'));
        $col = $this->fullColumn('DbmAi', 'NewId');
        $this->assertNotNull($col);
        $this->assertStringContainsStringIgnoringCase('auto_increment', $col['Extra']);
        $this->assertSame('PRI', $col['Key']);
        DB::query('INSERT INTO "DbmAi" ("Title") VALUES (\'second\')');
        $this->assertSame(['1', '2'], array_map('strval', $this->column('DbmAi', 'NewId', 'Title')));
    }

    public function testColumnRenameKeepsAutoIncrement(): void
    {
        $this->assertRenameKeepsAutoIncrement(false);
    }

    public function testColumnRenameKeepsAutoIncrementViaChange(): void
    {
        $this->assertRenameKeepsAutoIncrement(true);
    }

    /**
     * Regression (#2): an expression default was quoted into a string literal ('CURRENT_TIMESTAMP')
     * and ON UPDATE CURRENT_TIMESTAMP (in Extra) was dropped.
     */
    protected function assertRenameKeepsCurrentTimestamp(bool $viaChange): void
    {
        $this->createRawTable(
            'DbmStamp',
            '"ID" int, "OldStamp" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );

        $this->renameColumn('DbmStamp', 'OldStamp', 'NewStamp', $viaChange);

        $col = $this->fullColumn('DbmStamp', 'NewStamp');
        $this->assertNotNull($col);
        // MySQL reports "CURRENT_TIMESTAMP", MariaDB "current_timestamp()"; a quoted literal would
        // start with a quote (MariaDB) or not be accepted for a timestamp at all
        $this->assertMatchesRegularExpression('/^current_timestamp/i', (string) $col['Default']);
        $this->assertStringContainsStringIgnoringCase('on update current_timestamp', $col['Extra']);
        $this->assertSame('NO', $col['Null']);

        // The default is evaluated, not stored as text: an insert without the column gets "now"
        DB::query('INSERT INTO "DbmStamp" ("ID") VALUES (1)');
        $stamp = (string) DB::query('SELECT "NewStamp" FROM "DbmStamp"')->value();
        $this->assertNotSame('0000-00-00 00:00:00', $stamp);
        $this->assertGreaterThan(strtotime('-1 day'), strtotime($stamp));
    }

    public function testColumnRenameKeepsCurrentTimestampDefaultAndOnUpdate(): void
    {
        $this->assertRenameKeepsCurrentTimestamp(false);
    }

    public function testColumnRenameKeepsCurrentTimestampDefaultAndOnUpdateViaChange(): void
    {
        $this->assertRenameKeepsCurrentTimestamp(true);
    }

    /**
     * Regression (#2): the column's own collation (here differing from the table's) and its comment
     * were dropped; a literal default must still come back as the same literal.
     */
    protected function assertRenameKeepsCollationCommentAndDefault(bool $viaChange): void
    {
        DB::query(
            'CREATE TABLE "DbmColl" ("ID" int, '
            . '"OldName" varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT \'it\'\'s\' COMMENT \'keep me, please\') '
            . 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        DB::query('INSERT INTO "DbmColl" ("ID", "OldName") VALUES (1, \'value\')');

        $this->renameColumn('DbmColl', 'OldName', 'NewName', $viaChange);

        $col = $this->fullColumn('DbmColl', 'NewName');
        $this->assertNotNull($col);
        $this->assertSame('utf8mb4_bin', $col['Collation']);
        $this->assertSame('keep me, please', $col['Comment']);
        $this->assertSame('varchar(50)', $col['Type']);
        $this->assertSame('NO', $col['Null']);
        $this->assertSame(['value'], $this->column('DbmColl', 'NewName'));
        // Literal default: check by effect, since MariaDB reports it quoted and MySQL unquoted
        DB::query('INSERT INTO "DbmColl" ("ID") VALUES (2)');
        $this->assertSame(['value', "it's"], $this->column('DbmColl', 'NewName'));
    }

    public function testColumnRenameKeepsCollationCommentAndDefault(): void
    {
        $this->assertRenameKeepsCollationCommentAndDefault(false);
    }

    public function testColumnRenameKeepsCollationCommentAndDefaultViaChange(): void
    {
        $this->assertRenameKeepsCollationCommentAndDefault(true);
    }

    public function testColumnRenameIsSkippedWhenNewColumnAlreadyExists(): void
    {
        Config::modify()->set(DatabaseMigrationExtension::class, 'column_renames', [
            'DbmCols' => ['OldName' => 'NewName'],
        ]);
        $this->createRawTable('DbmCols', '"ID" int, "OldName" varchar(50), "NewName" varchar(50)');
        DB::query('INSERT INTO "DbmCols" ("ID", "OldName", "NewName") VALUES (1, \'old\', \'new\')');

        $this->fireHook('onBeforeBuild');

        $this->assertSame(['old'], $this->column('DbmCols', 'OldName'));
        $this->assertSame(['new'], $this->column('DbmCols', 'NewName'));
    }

    /**
     * table_merges must run from onAfterBuild (after the schema exists), not onBeforeBuild.
     */
    public function testTableMergesRunOnAfterBuildOnly(): void
    {
        Config::modify()->set(DatabaseMigrationExtension::class, 'table_merges', [
            'DbmMergeSrc' => ['target' => 'DbmMergeDst'],
        ]);
        $this->createRawTable('DbmMergeSrc', '"ID" int, "Title" varchar(50)');
        $this->createRawTable('DbmMergeDst', '"ID" int, "Title" varchar(50)');
        DB::query('INSERT INTO "DbmMergeSrc" ("ID", "Title") VALUES (1, \'moved\')');

        $this->fireHook('onBeforeBuild');
        $this->assertSame(0, $this->rowCount('DbmMergeDst'), 'merge ran before the schema build');

        $this->fireHook('onAfterBuild');
        $this->assertSame(['moved'], $this->column('DbmMergeDst', 'Title'));
        $this->assertFalse($this->tableExists('DbmMergeSrc'));
    }

    /**
     * Regression (#4): the run-once guards were set by the first build and never cleared, so every
     * later build in the same process (a test run, a long-running worker) silently skipped all
     * migrations and merges. Deliberately no resetRunFlags() between the two builds.
     */
    public function testASecondBuildInTheSameProcessRunsMigrationsAndMergesAgain(): void
    {
        // Build 1: a column rename and a table merge
        Config::modify()->set(DatabaseMigrationExtension::class, 'column_renames', [
            'DbmCols' => ['OldName' => 'NewName'],
        ]);
        Config::modify()->set(DatabaseMigrationExtension::class, 'table_merges', [
            'DbmMergeSrc' => ['target' => 'DbmMergeDst'],
        ]);
        $this->createRawTable('DbmCols', '"ID" int, "OldName" varchar(50), "OtherOld" varchar(50)');
        $this->createRawTable('DbmMergeSrc', '"ID" int, "Title" varchar(50)');
        $this->createRawTable('DbmMergeDst', '"ID" int, "Title" varchar(50)');
        DB::query('INSERT INTO "DbmMergeSrc" ("ID", "Title") VALUES (1, \'first\')');

        $this->fireHook('onBeforeBuild');
        $this->fireHook('onAfterBuild');
        $this->assertSame(['NewName'], $this->columnsLike('DbmCols', 'NewName'));
        $this->assertSame(['first'], $this->column('DbmMergeDst', 'Title'));

        // Build 2 in the same process: new config, new source data
        Config::modify()->set(DatabaseMigrationExtension::class, 'column_renames', [
            'DbmCols' => ['OtherOld' => 'OtherNew'],
        ]);
        Config::modify()->set(DatabaseMigrationExtension::class, 'table_merges', [
            'DbmMergeSrc2' => ['target' => 'DbmMergeDst'],
        ]);
        $this->createRawTable('DbmMergeSrc2', '"ID" int, "Title" varchar(50)');
        DB::query('INSERT INTO "DbmMergeSrc2" ("ID", "Title") VALUES (2, \'second\')');

        $this->fireHook('onBeforeBuild');
        $this->fireHook('onAfterBuild');
        $this->assertSame(['OtherNew'], $this->columnsLike('DbmCols', 'OtherNew'), 'second build skipped the column rename');
        $this->assertSame(['first', 'second'], $this->column('DbmMergeDst', 'Title'), 'second build skipped the merge');
    }

    /**
     * Regression (#4): the guards were private statics on a Configurable class, so they were also
     * read into (and exposed through) the extension's config.
     */
    public function testRunGuardsAreNotConfig(): void
    {
        $this->assertNull(Config::inst()->get(DatabaseMigrationExtension::class, 'migrations_run'));
        $this->assertNull(Config::inst()->get(DatabaseMigrationExtension::class, 'merges_run'));
    }

    protected function columnsLike(string $table, string $column): array
    {
        return DB::query(sprintf('SHOW COLUMNS FROM "%s" LIKE %s', $table, DB::get_conn()->quoteString($column)))->column('Field');
    }
}
