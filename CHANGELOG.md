# Changelog

## 0.3.1 (unreleased)

### Fixed

- **Column renames kept only the type, NULL-ability and default** (#2). `column_renames` rebuilt
  the column with `ALTER TABLE ... CHANGE` from `SHOW COLUMNS`, which dropped `AUTO_INCREMENT`,
  `ON UPDATE CURRENT_TIMESTAMP`, the column's collation and its comment, and quoted an expression
  default into a string (`DEFAULT 'CURRENT_TIMESTAMP'`, which a timestamp column rejects, so the
  build aborted). Renames now use `ALTER TABLE ... RENAME COLUMN` (MySQL 8.0+, MariaDB 10.5.2+),
  which leaves the definition alone; older servers get a `CHANGE` that repeats the column
  definition exactly as `SHOW CREATE TABLE` prints it, with references to the column itself (a
  column-level `CHECK`, which MariaDB adds to every JSON column) following the rename, run under
  the same `ANSI_QUOTES` mode it was read in so a project `sql_mode` with `NO_BACKSLASH_ESCAPES`
  cannot change escaped defaults or comments. Tested on MariaDB 10.4, 11.4 and 12.3 and MySQL 5.7
  and 8.0, both paths on each.
- **A second build in the same process skipped every migration and merge** (#4). The run-once
  guards were set by the first `dev/build` / `db:build` and never cleared, so any later build in
  that process (a test run, a long-running worker) did nothing, without a word. They are now
  cleared when a build starts, and they are `protected static` run-state instead of `private
  static` (which on this `Configurable` class also made them config).

## 0.3.0 (2026-09-25)

Silverstripe 6 support, alongside Silverstripe 5. No breaking changes for Silverstripe 5 projects.

### Added

- **Silverstripe 6.** Silverstripe 6 removed `SilverStripe\ORM\DatabaseAdmin`; `dev/build`
  (`sake db:build`) now runs through `SilverStripe\Dev\Command\DbBuild`, which fires the same
  `onBeforeBuild`/`onAfterBuild` hooks and owns the `classname_value_remapping` config. The
  extension is now applied to whichever of the two classes exists, and classname remappings are
  written to the config of the class actually running the build. With the 0.2 code on Silverstripe
  6 the extension would have been applied to a class that does not exist, and none of the
  migrations would have run - with no error.
- A behavioural test suite (22 tests, run through the real build class on each major) and CI:
  Silverstripe 5 on PHP 8.1 and 8.3, Silverstripe 6 on PHP 8.3 and 8.4, against MariaDB 11.4,
  plus a real `dev/build` per leg and, on Silverstripe 6, a `sake config:audit` check that
  fails when `DatabaseAdmin` is still given a `Restruct\` extension (another package's own
  `DatabaseAdmin` config is reported but does not fail the build). Every test leg runs with
  `--fail-on-empty-test-suite`, so a suite that discovers no tests fails instead of passing.
- Table merges print an error-type build message on a partial overlap: when some source rows are
  inserted and others already exist in the target by ID, the existing target rows are not updated,
  and the message names both tables and how many rows were skipped. What a merge writes is
  unchanged; before this the skipped rows were dropped without a word.
- `LICENSE` file (MIT, as already declared in `composer.json`).
- `funding` in `composer.json`.

### Fixed

- `dev/build` aborted with a database error when a table rename needed to move an empty current
  table aside and an `_obsolete_<table>` copy already existed (for example on a restored or
  re-migrated database). It now uses `_obsolete_<table>_2`, `_3`, ..., as table merges already did.

### Changed

- Requires `silverstripe/framework: ^5 || ^6` (was `^5.0`) and now declares `php: ^8.1`, the floor
  Silverstripe 5 already implied.
- README: requirements and compatibility table, every config option, how to run the tests, and two
  corrections - classname remapping is applied in the populate phase (not in `onBeforeBuild`), and
  a table merge that inserts nothing overwrites same-ID target rows rather than filling only empty
  columns. That update only runs when the merge inserted no rows at all and there is more than one
  column pair (`ID` counts as one); on a partial overlap the existing target rows are left as they
  were (see the new warning above). The merge semantics are pinned by tests but not final for 1.0.

### If you are upgrading a project to Silverstripe 6

Your own `classname_value_remapping` YAML, if you have any, must move from
`SilverStripe\ORM\DatabaseAdmin` to `SilverStripe\Dev\Command\DbBuild`; the old key is silently
ignored on 6. Remappings this module collects (`legacy_classnames`, `classname_mappings`) need no
change.

## 0.2

Adds `table_merges`: merge a deprecated table's rows into its replacement, with column mapping, a
marker value on migrated records and handling of `_Live`/`_Versions` tables.

## 0.1

Initial release: classname remapping, table renames and column renames during `dev/build`,
Silverstripe 5.
