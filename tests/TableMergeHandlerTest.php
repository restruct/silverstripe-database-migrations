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

    /**
     * Pins what the code does today (0.3.0), not a settled design: when every source ID already
     * exists in the target (nothing inserted) and there is more than one column pair, the source
     * values OVERWRITE the same-ID target rows - they do not only fill empty columns.
     */
    public function testSameIdTargetRowsAreOverwrittenWhenNothingIsInserted(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        $this->createRawTable('DbmHero', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', \'e1\')');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'dst1\', \'old\')');

        $merged = TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);

        $this->assertSame(1, $merged);
        $this->assertSame(1, $this->rowCount('DbmHero'));
        $this->assertSame(['src1'], $this->column('DbmHero', 'Title'));
        $this->assertSame(['e1'], $this->column('DbmHero', 'Extra'));
    }

    /**
     * Partial overlap: some source rows are new (inserted), some already exist in the target by ID.
     * The existing rows are NOT updated (the same-ID update only runs when nothing is inserted);
     * the merge must warn about the skipped rows instead of looking complete.
     */
    public function testPartialOverlapWarnsAboutSkippedRowsAndLeavesThemUnchanged(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        $this->createRawTable('DbmHero', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', \'e1\'), (2, \'src2\', \'e2\'), (3, \'src3\', \'e3\')');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'dst1\', \'old\')');

        // alteration_message() echoes in CLI unless the schema is quiet, so capture it
        DB::quiet(false);
        ob_start();
        try {
            TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);
        } finally {
            $output = ob_get_clean();
            DB::quiet(true);
        }

        // What is written is unchanged: new rows inserted, the existing row left as it was
        $this->assertSame(['dst1', 'src2', 'src3'], $this->column('DbmHero', 'Title'));
        $this->assertSame(['old', 'e2', 'e3'], $this->column('DbmHero', 'Extra'));
        // The warning is an error-type message naming both tables and the skipped count
        $this->assertStringContainsString(
            '! TableMerge: DbmBanner -> DbmHero: 1 source row(s) already exist in DbmHero by ID and were NOT updated',
            $output
        );
    }

    public function testNoOverlapWarningWhenAllRowsAreNew(): void
    {
        $this->createBlockTables();
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\', 11)');
        DB::query('INSERT INTO "DbmHero" VALUES (2, \'h2\', 12)');

        DB::quiet(false);
        ob_start();
        try {
            TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);
        } finally {
            $output = ob_get_clean();
            DB::quiet(true);
        }

        $this->assertStringContainsString('DbmHero: inserted 1 record(s)', $output);
        $this->assertStringNotContainsString('were NOT updated', $output);
    }

    /**
     * Full overlap: every source row already exists in the target, nothing is inserted, and the
     * same-ID update DOES run. The partial-overlap warning must not fire here, or an operator is
     * told rows were dropped when they were in fact copied. Pins the `$inserted > 0` half of the
     * warning condition (the partial-overlap test above only pins the `$existing > 0` half).
     */
    public function testNoOverlapWarningWhenFullOverlapOverwritesTarget(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        $this->createRawTable('DbmHero', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', \'e1\')');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'dst1\', \'old\')');

        // alteration_message() echoes in CLI unless the schema is quiet, so capture it
        DB::quiet(false);
        ob_start();
        try {
            TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);
        } finally {
            $output = ob_get_clean();
            DB::quiet(true);
        }

        // The overwrite happened (same precondition as testSameIdTargetRowsAreOverwrittenWhenNothingIsInserted)
        $this->assertSame(['src1'], $this->column('DbmHero', 'Title'));
        // ...so no "were NOT updated" warning may be printed
        $this->assertStringNotContainsString('were NOT updated', $output);
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
