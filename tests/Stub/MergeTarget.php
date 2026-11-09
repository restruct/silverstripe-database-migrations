<?php

namespace Restruct\SilverStripe\Migrations\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * The target of a table merge that a DataObject owns, so TableMergeHandler can ask the schema
 * which "...ID" columns are has_one foreign keys: ImageID is one, ExternalID is a plain int.
 *
 * The table name does not start with "Dbm" on purpose: RawTablesTrait::dropTestTables() drops
 * those after every test, and this table belongs to the temp schema, not to a single test.
 */
class MergeTarget extends DataObject implements TestOnly
{
    private static $table_name = 'MergeTestTarget';

    private static $db = [
        'Title' => 'Varchar',
        'ExternalID' => 'Int',
    ];

    private static $has_one = [
        'Image' => MigratedThing::class,
    ];
}
