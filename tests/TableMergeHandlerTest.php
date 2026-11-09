<?php

namespace Restruct\SilverStripe\Migrations\Tests;

use InvalidArgumentException;
use Restruct\SilverStripe\Migrations\TableMergeHandler;
use Restruct\SilverStripe\Migrations\Tests\Stub\MigratedThing;
use Restruct\SilverStripe\Migrations\Tests\Stub\MergeTarget;
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
        # The has_one tests merge into this class's table, so its schema can be asked
        MergeTarget::class,
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
     *
     * (#3) Settled 2026-11-09: overwriting is now opt-in with merge_mode: overwrite (the default is
     * fill_empty), so this test sets it. The full-overlap overwrite it pins is unchanged.
     */
    public function testSameIdTargetRowsAreOverwrittenWhenNothingIsInserted(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        $this->createRawTable('DbmHero', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', \'e1\')');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'dst1\', \'old\')');

        // $merged = TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);
        $merged = TableMergeHandler::create()->runTableMerges([
            'DbmBanner' => ['target' => 'DbmHero', 'merge_mode' => TableMergeHandler::MERGE_MODE_OVERWRITE],
        ]);

        $this->assertSame(1, $merged);
        $this->assertSame(1, $this->rowCount('DbmHero'));
        $this->assertSame(['src1'], $this->column('DbmHero', 'Title'));
        $this->assertSame(['e1'], $this->column('DbmHero', 'Extra'));
    }

    /**
     * Partial overlap: some source rows are new (inserted), some already exist in the target by ID.
     * The existing rows are NOT updated (the same-ID update only runs when nothing is inserted);
     * the merge must warn about the skipped rows instead of looking complete.
     *
     * (#3) Settled 2026-11-09: the existing rows are now merged per merge_mode on a partial overlap
     * too, so nothing is skipped and the "were NOT updated" warning is gone. Here, in the default
     * fill_empty mode, the existing row has no empty columns, so it is left as it was - the data
     * assertions are the same as before; only the message changed.
     * (was: testPartialOverlapWarnsAboutSkippedRowsAndLeavesThemUnchanged)
     */
    public function testPartialOverlapMergesExistingRowsAndNoLongerWarns(): void
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
        // $this->assertStringContainsString(
        //     '! TableMerge: DbmBanner -> DbmHero: 1 source row(s) already exist in DbmHero by ID and were NOT updated',
        //     $output
        // );
        # (#3) No rows are skipped any more: a notice names the same-ID rows and the mode instead
        $this->assertStringNotContainsString('were NOT updated', $output);
        $this->assertStringContainsString(
            'TableMerge: DbmBanner -> DbmHero: 1 source row(s) already exist in DbmHero by ID, merged with merge_mode fill_empty',
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
     *
     * (#3) The overwrite is opt-in since 2026-11-09 (merge_mode: overwrite), so this test sets it.
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
            // TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);
            TableMergeHandler::create()->runTableMerges([
                'DbmBanner' => ['target' => 'DbmHero', 'merge_mode' => TableMergeHandler::MERGE_MODE_OVERWRITE],
            ]);
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

    /**
     * (#3) Without a merge_mode the merge is fill_empty: on a same-ID row only the NULL and ''
     * target columns take the source value. An int 0 is a value and is kept - MySQL would compare
     * it equal to '' without the CAST in updateExistingRecords().
     */
    public function testDefaultMergeModeFillsOnlyEmptyColumnsOfSameIdRows(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "Extra" varchar(50), "Note" varchar(50), "Num" int');
        $this->createRawTable('DbmHero', '"ID" int, "Title" varchar(50), "Extra" varchar(50), "Note" varchar(50), "Num" int');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', \'e1\', \'n1\', 5)');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'dst1\', NULL, \'\', 0)');

        TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);

        $this->assertSame(1, $this->rowCount('DbmHero'));
        $this->assertSame(['dst1'], $this->column('DbmHero', 'Title'), 'a non-empty target value was overwritten');
        $this->assertSame(['e1'], $this->column('DbmHero', 'Extra'), 'a NULL target value was not filled');
        $this->assertSame(['n1'], $this->column('DbmHero', 'Note'), 'an empty-string target value was not filled');
        $this->assertSame(['0'], array_map('strval', $this->column('DbmHero', 'Num')), 'an int 0 was treated as empty');
    }

    /**
     * (#3) fill_empty on a PARTIAL overlap: the same-ID row is filled even though other rows are
     * inserted in the same merge (0.3 only touched same-ID rows when nothing was inserted).
     */
    public function testFillEmptyMergesSameIdRowsOnPartialOverlap(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        $this->createRawTable('DbmHero', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', \'e1\'), (2, \'src2\', \'e2\')');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'dst1\', NULL)');

        TableMergeHandler::create()->runTableMerges([
            'DbmBanner' => ['target' => 'DbmHero', 'merge_mode' => TableMergeHandler::MERGE_MODE_FILL_EMPTY],
        ]);

        $this->assertSame(['dst1', 'src2'], $this->column('DbmHero', 'Title'));
        $this->assertSame(['e1', 'e2'], $this->column('DbmHero', 'Extra'));
    }

    /**
     * (#3) overwrite on a PARTIAL overlap: the same-ID row takes every paired source value, a NULL
     * included, while the new row is inserted; a target-only column is left alone.
     */
    public function testOverwriteReplacesSameIdRowsOnPartialOverlap(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "Extra" varchar(50)');
        $this->createRawTable('DbmHero', '"ID" int, "Title" varchar(50), "Extra" varchar(50), "HeroOnly" varchar(50)');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', NULL), (2, \'src2\', \'e2\')');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'dst1\', \'old\', \'keep\')');

        TableMergeHandler::create()->runTableMerges([
            'DbmBanner' => ['target' => 'DbmHero', 'merge_mode' => TableMergeHandler::MERGE_MODE_OVERWRITE],
        ]);

        $this->assertSame(['src1', 'src2'], $this->column('DbmHero', 'Title'));
        $this->assertSame([null, 'e2'], $this->column('DbmHero', 'Extra'));
        $this->assertSame(['keep', null], $this->column('DbmHero', 'HeroOnly'));
    }

    /**
     * (#3) The merge_mode applies to the _Live table as well, and to a mapped column: the mapped
     * HeroImageID of 0 is a value and is kept in fill_empty mode, the empty Title is filled.
     *
     * (#3) Changed on purpose 2026-11-09 (mic): a has_one ID of 0 is empty in fill_empty, and
     * HeroImageID on a table no class owns counts as one by its name, so the 0 is now filled from
     * the source (11) on both tables. The test still pins what it was written for: the mode
     * reaches _Live and the mapped column.
     */
    public function testFillEmptyAppliesToLiveTableAndMappedColumns(): void
    {
        $this->createBlockTables();
        $this->createBlockTables('_Live');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\', 11), (2, \'b2\', 12)');
        DB::query('INSERT INTO "DbmBanner_Live" VALUES (1, \'b1 live\', 11)');
        DB::query('INSERT INTO "DbmHero" VALUES (1, \'\', 0)');
        DB::query('INSERT INTO "DbmHero_Live" VALUES (1, NULL, 0)');

        TableMergeHandler::create()->runTableMerges([
            'DbmBanner' => [
                'target' => 'DbmHero',
                'columns' => ['BannerImageID' => 'HeroImageID'],
                'versioned' => true,
            ],
        ]);

        $this->assertSame(['b1', 'b2'], $this->column('DbmHero', 'Title'));
        // $this->assertSame(['0', '12'], array_map('strval', $this->column('DbmHero', 'HeroImageID')));
        $this->assertSame(['11', '12'], array_map('strval', $this->column('DbmHero', 'HeroImageID')));
        $this->assertSame(['b1 live'], $this->column('DbmHero_Live', 'Title'));
        // $this->assertSame(['0'], array_map('strval', $this->column('DbmHero_Live', 'HeroImageID')));
        $this->assertSame(['11'], array_map('strval', $this->column('DbmHero_Live', 'HeroImageID')));
    }

    /**
     * (#3) An unknown merge_mode is an error, raised before ANY merge runs: the valid first
     * definition must not be merged either, or a typo leaves the build half done.
     */
    public function testUnknownMergeModeThrowsBeforeAnyTableIsTouched(): void
    {
        $this->createBlockTables();
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\', 11)');

        try {
            TableMergeHandler::create()->runTableMerges([
                'DbmBanner' => ['target' => 'DbmHero'],
                'DbmOther' => ['target' => 'DbmHero', 'merge_mode' => 'replace'],
            ]);
            $this->fail('an unknown merge_mode was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                "TableMerge: table_merges.DbmOther.merge_mode is 'replace', must be one of: fill_empty, overwrite",
                $e->getMessage()
            );
        }

        $this->assertTrue($this->tableExists('DbmBanner'), 'the valid merge ran before the error');
        $this->assertSame(0, $this->rowCount('DbmHero'));
    }

    /**
     * (#3) The same check for the default mode passed in (from DatabaseMigrationExtension.table_merge_mode)
     */
    public function testUnknownDefaultMergeModeThrows(): void
    {
        $this->createBlockTables();
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'b1\', 11)');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "TableMerge: DatabaseMigrationExtension.table_merge_mode is 'fill-empty', must be one of: fill_empty, overwrite"
        );

        TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']], 'fill-empty');
    }

    /**
     * fill_empty: a has_one ID of 0 means "no relation", so it is empty and takes the source
     * value. The target table here belongs to no DataObject, so the "...ID" name decides. A 0 in
     * a column that does not end in ID is still a value and is kept.
     */
    public function testFillEmptyFillsZeroHasOneIdColumnsByNameWithoutAClass(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "ImageID" int, "Num" int');
        $this->createRawTable('DbmHero', '"ID" int, "ImageID" int, "Num" int');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, 11, 5), (2, 12, 6)');
        DB::query('INSERT INTO "DbmHero" VALUES (1, 0, 0), (2, 7, 0)');

        TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'DbmHero']]);

        $this->assertSame(['11', '7'], array_map('strval', $this->column('DbmHero', 'ImageID')), 'a 0 has_one ID was not filled, or a set one was overwritten');
        $this->assertSame(['0', '0'], array_map('strval', $this->column('DbmHero', 'Num')), 'a 0 in a non-ID column was treated as empty');
    }

    /**
     * fill_empty on a target table that a DataObject owns: the schema decides which "...ID"
     * columns are has_one foreign keys. ImageID (has_one Image) at 0 is filled; ExternalID (a
     * plain Int that only looks like one) at 0 is kept.
     */
    public function testFillEmptyAsksTheSchemaWhichIdColumnsAreHasOnes(): void
    {
        $this->createRawTable('DbmBanner', '"ID" int, "Title" varchar(50), "ImageID" int, "ExternalID" int');
        DB::query('INSERT INTO "DbmBanner" VALUES (1, \'src1\', 11, 99)');
        DB::query('INSERT INTO "MergeTestTarget" ("ID", "Title", "ImageID", "ExternalID") VALUES (1, \'dst1\', 0, 0)');

        TableMergeHandler::create()->runTableMerges(['DbmBanner' => ['target' => 'MergeTestTarget']]);

        $this->assertSame(['dst1'], $this->column('MergeTestTarget', 'Title'));
        $this->assertSame(['11'], array_map('strval', $this->column('MergeTestTarget', 'ImageID')), 'a 0 has_one ID was not filled');
        $this->assertSame(['0'], array_map('strval', $this->column('MergeTestTarget', 'ExternalID')), 'a 0 in an Int that is not a has_one was treated as empty');
    }
}
