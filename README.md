# SilverStripe Database Migrations

Database migration utilities for SilverStripe 5.  
Handles table renames, classname value remapping, and column renames during `dev/build`.

<img width="642" height="116" alt="Migrations applied during dev/build" src="https://github.com/user-attachments/assets/f471bbe4-9e91-414f-90ce-10d508c4aeea" />


## Features

- **ClassName Remapping**: Reads `$legacy_classnames` from DataObjects and injects into `DatabaseAdmin.classname_value_remapping`
- **Table Renames**: Reads `$legacy_table_names` from DataObjects and renames tables before schema updates
- **Column Renames**: Rename columns via config (useful for fixing name collisions)
- **Conflict Handling**: Automatically handles cases where both old and new tables exist

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

### Running Migrations

```bash
vendor/bin/sake dev/build flush=1
```

Migrations run automatically before SilverStripe processes any schema updates.

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

2. Injects them into SilverStripe's built-in `DatabaseAdmin.classname_value_remapping`

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
- Namespace changes (SS3→SS4/5 upgrades)
- Refactoring/renaming classes
- Merging multiple classes into one
- Moving classes between modules

## How Table Migrations Work

The extension hooks into `DatabaseAdmin::onBeforeBuild()` and renames tables before SilverStripe processes schema updates.

**Conflict handling:** If both old and new tables exist:
- If the new table is empty: moves it aside as `_obsolete_NewTable` and renames old→new
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
        HeroImageID: HeroImageID        # Source → target column mapping
      marker:
        table: Element                  # BaseElement stores Style in Element table
        column: Style
        value: 'banner-style'           # Mark migrated records
      versioned: true                   # Handle _Live and _Versions tables
```

**What happens during dev/build:**

1. **onBeforeBuild** (before schema):
   - ClassName remapping → `BlockBanner` records now have `ClassName = 'BlockHero'`
   - Column renames → `BannerImageID` becomes `HeroImageID`

2. **Schema build** (SilverStripe):
   - Creates/updates `BlockHero` table structure

3. **onAfterBuild** (after schema):
   - Table merge:
     - Inserts `BlockBanner` records that don't exist in `BlockHero`
     - Updates existing `BlockHero` records where columns are empty
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

## License

MIT
