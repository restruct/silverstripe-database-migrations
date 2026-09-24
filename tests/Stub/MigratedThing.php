<?php

namespace Restruct\SilverStripe\Migrations\Tests\Stub;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A DataObject that was "renamed": both its class and its table used to be called something else.
 *
 * Deliberately concrete (never abstract): an abstract DataObject anywhere in a module's tests/ fatals
 * the temp-database build in every consuming project (see the module-update SOP).
 */
class MigratedThing extends DataObject implements TestOnly
{
    private static $table_name = 'DbmTestThing';

    private static $db = [
        'Title' => 'Varchar',
    ];

    private static $legacy_classnames = [
        'Old\\Namespace\\DbmThing',
    ];

    private static $legacy_table_names = [
        'DbmTestOldThing',
    ];
}
