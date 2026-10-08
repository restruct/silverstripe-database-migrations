# SilverStripe Database Migrations

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

Database migration utilities for Silverstripe 5 and 6.  
Handles table renames, classname value remapping, column renames and table merges during `dev/build`.

<img width="642" height="116" alt="Migrations applied during dev/build" src="https://github.com/user-attachments/assets/f471bbe4-9e91-414f-90ce-10d508c4aeea" />


## Features

- **ClassName Remapping**: Reads `$legacy_classnames` from DataObjects and injects into `DatabaseAdmin.classname_value_remapping`
- **Table Renames**: Reads `$legacy_table_names` from DataObjects and renames tables before schema updates
- **Column Renames**: Rename columns via config (useful for fixing name collisions)
- **Table Merges**: Merge a deprecated table's rows into its replacement (see below)
- **Conflict Handling**: Automatically handles cases where both old and new tables exist

## Requirements

| Release | Branch | Silverstripe | PHP |
| ------- | ------ | ------------ | --- |
| `0.3`   | `main` | 5 / 6        | 8.1+ (8.3+ on Silverstripe 6) |
| `0.1`, `0.2` | tags only | 5 | 8.1+ |

`composer.json` is the source of truth. Silverstripe 5 is supported until its end of life in
April 2027; Silverstripe 4 is not supported.

The SQL the module runs (`RENAME TABLE`, `ALTER TABLE ... RENAME COLUMN` / `CHANGE`, `UPDATE ... JOIN`,
`SHOW COLUMNS`, `SHOW CREATE TABLE`) is MySQL/MariaDB only.

## Installation

```bash
composer require restruct/silverstripe-database-migrations
```

The extension is applied automatically to the class that runs `dev/build`:
`SilverStripe\ORM\DatabaseAdmin` on Silverstripe 5, `SilverStripe\Dev\Command\DbBuild` on
Silverstripe 6. There is nothing to configure until you have something to migrate.

## Usage

### DataObject Migrations

Add legacy mappings directly to your DataObjects:

```php
class MyModel extends DataObject
{
    private static $table_name = 'MyModel';

    // Old class names that should map to this class
    private static $legacy_classnames = [
        'Old\Namespace\MyModel',
        'Another\Old\MyModel',
    ];

    // Old table names that should be renamed to this class's table
    private static $legacy_table_names = [
        'OldTableName',
    ];
}
```

### Config-based Migrations

For join tables, versioned tables, or other non-DataObject tables:

```yaml
Restruct\SilverStripe\Migrations\DatabaseMigrationExtension:
  # Table renames
  table_mappings:
    OldJoinTable: NewJoinTable
    OldModel_Versions: NewModel_Versions

  # Classname remappings (see explanation below)
  classname_mappings:
    'Old\Namespace\SomeClass': 'New\Namespace\SomeClass'

  # Column renames: [table => [old_column => new_column]]
  column_renames:
    MyTable:
      old_column_name: new_column_name

  # Table merges: merge deprecated tables into their replacements
  table_merges:
    OldBlockType:
      target: NewBlockType             # Target table to merge into
      columns:                         # Column mapping (optional)
        OldImageID: NewImageID
      marker:                          # Set a value on migrated records (optional)
        table: Element                 # Table containing the marker column
        column: Style                  # Column name
        value: 'old-style'             # Value to set
      versioned: true                  # Auto-handle _Live and _Versions tables
```

### Config options

All on `Restruct\SilverStripe\Migrations\DatabaseMigrationExtension`, all default to `[]`:

| Option | Format | Runs |
| ------ | ------ | ---- |
| `classname_mappings` | `'Old\Class': 'New\Class'` | registered before the schema build, applied in the populate phase |
| `table_mappings` | `OldTable: NewTable` | before the schema build |
| `column_renames` | `Table: { OldColumn: NewColumn }` | before the schema build |
| `table_merges` | `SourceTable: { target, columns, marker, versioned }` | after the schema build |

A column rename keeps the column's whole definition (type, NULL, default, `AUTO_INCREMENT`,
`ON UPDATE`, collation, comment): it uses `RENAME COLUMN` on MySQL 8.0+ / MariaDB 10.5.2+, and on
older servers a `CHANGE` that repeats the definition `SHOW CREATE TABLE` reports for the column.

On DataObjects, uninherited `private static` config:

| Option | Format |
| ------ | ------ |
| `legacy_classnames` | list of old class names that map to this class |
| `legacy_table_names` | list of old table names to rename to this class's `$table_name` (requires `$table_name` on the class itself) |

### Running Migrations

```bash
# Silverstripe 6
vendor/bin/sake db:build --flush

# Silverstripe 5
vendor/bin/sake dev/build flush=1
```

Table and column migrations run automatically before Silverstripe processes any schema updates.
Classname remapping is applied in the populate phase, so a build run without populating
(`db:build --no-populate` on Silverstripe 6) registers the mappings but does not apply them.

## How ClassName Remapping Works

SilverStripe stores the fully-qualified class name in a `ClassName` column for polymorphic queries. When you rename or move a class, existing database records still reference the old class name:

```
| ID | ClassName                  | Title    |
|----|----------------------------|----------|
| 1  | Old\Namespace\MyModel      | Record 1 |
| 2  | Old\Namespace\MyModel      | Record 2 |
```

Without remapping, SilverStripe cannot instantiate these records because the old class no longer exists.

**What happens during dev/build:**

1. The extension collects mappings from:
   - `$legacy_classnames` on each DataObject
   - `classname_mappings` config (for cases where you can't modify the class)

2. Injects them into Silverstripe's built-in `classname_value_remapping` config, on the class that
   runs the build: `DatabaseAdmin` on Silverstripe 5, `DbBuild` on Silverstripe 6. (On
   Silverstripe 6, remapping you configure yourself must be keyed to
   `SilverStripe\Dev\Command\DbBuild` too: a `SilverStripe\ORM\DatabaseAdmin` block is silently
   ignored there.)

3. SilverStripe runs UPDATE queries to fix the values:
   ```sql
   UPDATE MyModel SET ClassName = 'New\Namespace\MyModel'
   WHERE ClassName = 'Old\Namespace\MyModel'
   ```

**After remapping:**
```
| ID | ClassName                  | Title    |
|----|----------------------------|----------|
| 1  | New\Namespace\MyModel      | Record 1 |
| 2  | New\Namespace\MyModel      | Record 2 |
```

### When to use `$legacy_classnames` vs `classname_mappings` config

Use `$legacy_classnames` on your DataObject when:
- You control the class and can add the config to it

Use `classname_mappings` in YAML config when:
- The old class has been **deleted** from the codebase (you can't add config to a non-existent class)
- The class is from a **vendor/third-party module** you can't modify
- You prefer **centralised configuration** in one YAML file

**Common use cases:**
- Namespace changes (SS3->SS4/5 upgrades)
- Refactoring/renaming classes
- Merging multiple classes into one
- Moving classes between modules

## How Table Migrations Work

The extension hooks into the build's `onBeforeBuild` (`DatabaseAdmin` on Silverstripe 5, `DbBuild` on Silverstripe 6) and renames tables before Silverstripe processes schema updates.

**Conflict handling:** If both old and new tables exist:
- If the new table is empty: moves it aside as `_obsolete_NewTable` (or `_obsolete_NewTable_2`, `_3`, ... if an earlier copy is still there) and renames old->new
- If both have data: logs a warning for manual resolution

## How Table Merges Work

Table merges handle the case where you're **consolidating two block types** (or similar DataObjects) into one. This is different from a simple rename because the target table already has its own data.

**Use case:** Merging `BlockBanner` into `BlockHero` with a "banner" style variation:

```yaml
Restruct\SilverStripe\Migrations\DatabaseMigrationExtension:
  # First: remap ClassName values so records point to the new class
  classname_mappings:
    'App\Blocks\BlockBanner': 'App\Blocks\BlockHero'

  # Second: rename columns if needed (runs before merge)
  column_renames:
    BlockBanner:
      BannerImageID: HeroImageID

  # Third: merge table data (runs after schema build)
  table_merges:
    BlockBanner:
      target: BlockHero
      columns:
        HeroImageID: HeroImageID        # Source -> target column mapping
      marker:
        table: Element                  # BaseElement stores Style in Element table
        column: Style
        value: 'banner-style'           # Mark migrated records
      versioned: true                   # Handle _Live and _Versions tables
```

**What happens during dev/build:**

1. **onBeforeBuild** (before schema):
   - ClassName remapping is registered (Silverstripe applies it in the populate phase, after the
     schema build) -> `BlockBanner` records end up with `ClassName = 'BlockHero'`
   - Column renames -> `BannerImageID` becomes `HeroImageID`

2. **Schema build** (SilverStripe):
   - Creates/updates `BlockHero` table structure

3. **onAfterBuild** (after schema):
   - Table merge:
     - Inserts `BlockBanner` records that don't exist in `BlockHero`
     - If no rows needed inserting (every source ID already exists in the target), copies the
       mapped and same-named columns from source onto the target rows with the same ID. This
       overwrites the target values, it does not only fill empty ones. It only happens when there
       is more than one column pair (`ID` counts as one), so a merge whose only shared column is
       `ID` updates nothing.
     - Partial overlap: if some source rows are new and others already exist in the target by ID,
       the new rows are inserted and the existing target rows are NOT updated at all. The build
       prints an error-type message naming both tables and the number of rows skipped, e.g.
       `! TableMerge: BlockBanner -> BlockHero: 3 source row(s) already exist in BlockHero by ID and were NOT updated (existing rows are only updated when a merge inserts nothing)`.
       The same rules apply to the `_Live` table; `_Versions` rows are only ever inserted (matched
       on `RecordID` + `Version`).
     - Sets `Element.Style = 'banner-style'` on migrated records
     - Handles `_Live` and `_Versions` tables if `versioned: true`
     - Moves `BlockBanner` tables to `_obsolete_BlockBanner` (with counter suffix if already exists)

**Marker field:** The `marker` config is useful for:
- Distinguishing migrated records from original ones
- Setting a style/variant value so the correct template is rendered
- Audit trail of which records came from the deprecated type

## After Migrations: Cleanup Options

Once migrations have successfully run on all environments (dev, staging, production), you have two options:

### Option 1: Remove migration config (recommended for one-time migrations)

After deployment, you can safely remove:
- `$legacy_classnames` and `$legacy_table_names` from your DataObjects
- `table_mappings`, `classname_mappings`, and `column_renames` from YAML config

The migrations are idempotent (they check if old tables/values exist before acting), so keeping them has no functional impact. However, removing them:
- Keeps your codebase clean
- Slightly improves `dev/build` performance (fewer checks)
- Makes it clear which migrations are historical vs active

### Option 2: Keep migration config (recommended for distributed systems)

You may want to keep migration config in place when:
- **Multiple databases** need migrating at different times (e.g., client installations)
- **Database restores** from old backups might reintroduce legacy data
- **Future-proofing** against re-running migrations on cloned/restored environments

**Performance impact:** Minimal. The extension iterates through DataObject classes once during `dev/build` to collect mappings. Table/column checks only run if mappings are found, and bail out early if old tables/columns don't exist.

### Hybrid approach

Keep migration config during a transition period, then remove it in a future release once all environments are confirmed migrated.

## Running the tests

The suite needs a booted Silverstripe app and a MySQL/MariaDB database. Install the module into a
host project as a **symlinked path repository** (`/tests` is `export-ignore`, so a Packagist install
contains no tests), map `Restruct\SilverStripe\Migrations\Tests\` to
`vendor/restruct/silverstripe-database-migrations/tests/` in the host's `autoload-dev`, then:

```bash
# Silverstripe 6: the manifest flush is an environment variable
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-database-migrations/tests

# Silverstripe 5: flush=1 must come AFTER the test path
vendor/bin/phpunit vendor/restruct/silverstripe-database-migrations/tests flush=1
```

`.github/workflows/ci.yml` builds exactly such a host for every supported major.

## License

MIT, see [LICENSE](LICENSE).
