<?php

namespace Restruct\SilverStripe\Migrations\Tests\Stub;

use Restruct\SilverStripe\Migrations\DatabaseMigrationExtension;
use SilverStripe\Dev\TestOnly;

/**
 * The extension as it behaves on a server without ALTER TABLE ... RENAME COLUMN (MySQL before 8.0,
 * MariaDB before 10.5.2), so the CHANGE fallback is exercised on whatever server the suite runs on.
 */
class ChangeOnlyMigrationExtension extends DatabaseMigrationExtension implements TestOnly
{
    protected function supportsRenameColumn(): bool
    {
        return false;
    }
}
