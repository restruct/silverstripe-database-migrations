<?php

namespace Restruct\SilverStripe\Migrations\Tests;

use Restruct\SilverStripe\Migrations\DatabaseMigrationExtension;
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
}
