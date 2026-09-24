<?php

namespace Restruct\SilverStripe\Migrations\Tests;

use ReflectionProperty;
use Restruct\SilverStripe\Migrations\DatabaseMigrationExtension;
use Restruct\SilverStripe\Migrations\Tests\Stub\MigratedThing;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Raw-SQL table helpers shared by this module's tests.
 *
 * The module's whole job is to act on tables that NO DataObject declares any more (legacy tables,
 * join tables, _Live/_Versions copies), so the fixtures are created with plain SQL in the temp
 * database. Every table a test creates starts with "Dbm" so tearDown can remove them all.
 */
trait RawTablesTrait
{
    protected function createRawTable(string $table, string $columnsSql): void
    {
        DB::query(sprintf('CREATE TABLE "%s" (%s)', $table, $columnsSql));
    }

    protected function tableExists(string $table): bool
    {
        return DB::get_schema()->hasTable($table);
    }

    protected function rowCount(string $table): int
    {
        return (int) DB::query(sprintf('SELECT COUNT(*) FROM "%s"', $table))->value();
    }

    protected function column(string $table, string $column, string $orderBy = 'ID'): array
    {
        return DB::query(sprintf('SELECT "%s" FROM "%s" ORDER BY "%s"', $column, $table, $orderBy))->column();
    }

    /**
     * The extension guards each phase with a process-wide static so it runs once per dev/build.
     * A test process runs it many times, so reset both flags around every test (the SOP's
     * "no test-visible static may survive the test that set it").
     */
    protected function resetRunFlags(): void
    {
        foreach (['migrations_run', 'merges_run'] as $flag) {
            $prop = new ReflectionProperty(DatabaseMigrationExtension::class, $flag);
            $prop->setAccessible(true);
            $prop->setValue(null, false);
        }
    }

    /**
     * Drop every table a test created or renamed, then rebuild the stub's own table so the next
     * test (and SapphireTest's own teardown) finds the schema it expects.
     */
    protected function dropTestTables(): void
    {
        foreach (DB::table_list() as $table) {
            if (stripos($table, 'Dbm') === 0 || stripos($table, '_obsolete_Dbm') === 0) {
                DB::query(sprintf('DROP TABLE "%s"', $table));
            }
        }
        DB::get_schema()->schemaUpdate(function () {
            DataObject::singleton(MigratedThing::class)->requireTable();
        });
    }
}
