<?php

namespace Restruct\SilverStripe\Migrations\Tests;

use Restruct\SilverStripe\Migrations\TableMergeHandler;
use Restruct\SilverStripe\Migrations\Tests\Stub\MigratedThing;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;

/**
 * TableMergeHandler against real tables in the temp database: the documented contract of
 * table_merges (insert missing rows, column mapping, marker, versioned tables, move aside).
 */
class TableMergeHandlerTest extends SapphireTest
{
    use RawTablesTrait;

    protected $usesDatabase = true;

    // Not used by these tests, but RawTablesTrait::dropTestTables() rebuilds its table, so it must
    // be part of this class's temp schema too.
    protected static $extra_dataobjects = [
        MigratedThing::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::quiet(true);
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();
        DB::quiet(false);
        parent::tearDown();
    }

    protected function createBlockTables(string $suffix = ''): void
    {
        $this->createRawTable('DbmBanner' . $suffix, '"ID" int, "Title" varchar(50), "BannerImageID" int');
        $this->createRawTable('DbmHero' . $suffix, '"ID" int, "Title" varchar(50), "HeroImageID" int');
    }

    public function testMergeInsertsMissingRowsMapsColumnsAndMovesSourceAside(): void
    {
        $this->createBlockTables();
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\', 11), (2, \'b2\', 12)');
        DB::query('INSERT INTO "DbmHero" VALUES (3, \'h3\', 13)');

        $merged = TableMergeHandler::create()->runTableMerges([
            'DbmBanner' => ['target' => 'DbmHero', 'columns' => ['BannerImageID' => 'HeroImageID']],
        ]);

        $this->assertSame(1, $merged);
        $this->assertSame(['b1', 'b2', 'h3'], $this->column('DbmHero', 'Title'));
        $this->assertSame(['11', '12', '13'], array_map('strval', $this->column('DbmHero', 'HeroImageID')));
        $this->assertFalse($this->tableExists('DbmBanner'));
        $this->assertTrue($this->tableExists('_obsolete_DbmBanner'));
    }

    public function testMarkerIsSetOnlyOnMigratedRecordsWhereEmpty(): void
    {
        $this->createBlockTables();
        // Base table holding the marker column, as elemental's Element table holds Style
        $this->createRawTable('DbmElement', '"ID" int, "Style" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\', 0), (2, \'b2\', 0)');
        DB::query('INSERT INTO "DbmElement" VALUES (1, NULL), (2, \'preset\'), (3, NULL)');

        TableMergeHandler::create()->runTableMerges([
            'DbmBanner' => [
                'target' => 'DbmHero',
                'marker' => ['table' => 'DbmElement', 'column' => 'Style', 'value' => 'banner-style'],
            ],
        ]);

        // 1: migrated and empty -> marked; 2: migrated but preset -> kept; 3: not migrated -> untouched
        $this->assertSame(['banner-style', 'preset', null], $this->column('DbmElement', 'Style'));
    }

    public function testVersionedTablesAreMergedAndMovedAside(): void
    {
        $this->createBlockTables();
        $this->createBlockTables('_Live');
        $this->createRawTable('DbmBanner_Versions', '"ID" int NOT NULL AUTO_INCREMENT PRIMARY KEY, "RecordID" int, "Version" int, "Title" varchar(50)');
        $this->createRawTable('DbmHero_Versions', '"ID" int NOT NULL AUTO_INCREMENT PRIMARY KEY, "RecordID" int, "Version" int, "Title" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'draft\', 0)');
        DB::query('INSERT INTO "DbmBanner_Live" VALUES (1, \'live\', 0)');
        DB::query('INSERT INTO "DbmBanner_Versions" ("RecordID", "Version", "Title") VALUES (1, 1, \'v1\'), (1, 2, \'v2\')');
        // A pre-existing version row in the target with a clashing auto-increment ID
        DB::query('INSERT INTO "DbmHero_Versions" ("ID", "RecordID", "Version", "Title") VALUES (1, 9, 1, \'hero v1\')');

        TableMergeHandler::create()->runTableMerges([
            'DbmBanner' => ['target' => 'DbmHero', 'versioned' => true],
        ]);

        $this->assertSame(['draft'], $this->column('DbmHero', 'Title'));
        $this->assertSame(['live'], $this->column('DbmHero_Live', 'Title'));
        $this->assertSame(['hero v1', 'v1', 'v2'], $this->column('DbmHero_Versions', 'Title'));
        foreach (['DbmBanner', 'DbmBanner_Live', 'DbmBanner_Versions'] as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} was not moved aside");
            $this->assertTrue($this->tableExists('_obsolete_' . $table));
        }
    }

    public function testEmptySourceIsMovedAsideWithoutMerging(): void
    {
        $this->createBlockTables();

        $merged = TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);

        $this->assertSame(0, $merged);
        $this->assertTrue($this->tableExists('_obsolete_DbmBanner'));
        $this->assertSame(0, $this->rowCount('DbmHero'));
    }

    public function testMissingTargetLeavesSourceInPlace(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\')');

        $merged = TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmNowhere']]);

        $this->assertSame(0, $merged);
        $this->assertSame(['b1'], $this->column('DbmBanner', 'Title'));
    }

    public function testEntryWithoutTargetIsSkipped(): void
    {
        $this->createBlockTables();
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\', 0)');

        $merged = TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['columns' => []]]);

        $this->assertSame(0, $merged);
        $this->assertTrue($this->tableExists('DbmBanner'));
    }

    public function testMovingAsideTwiceGetsACounterSuffix(): void
    {
        $this->createRawTable('_obsolete_DbmBanner', '"ID" int');
        $this->createBlockTables();

        TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);

        $this->assertTrue($this->tableExists('_obsolete_DbmBanner_2'));
        $this->assertFalse($this->tableExists('DbmBanner'));
    }
}
