# Changelog

## 0.3.0 (unreleased)

Silverstripe 6 support, alongside Silverstripe 5. No breaking changes for Silverstripe 5 projects.

### Added

- **Silverstripe 6.** Silverstripe 6 removed `SilverStripe\ORM\DatabaseAdmin`; `dev/build`
  (`sake db:build`) now runs through `SilverStripe\Dev\Command\DbBuild`, which fires the same
  `onBeforeBuild`/`onAfterBuild` hooks and owns the `classname_value_remapping` config. The
  extension is now applied to whichever of the two classes exists, and classname remappings are
  written to the config of the class actually running the build. With the 0.2 code on Silverstripe
  6 the extension would have been applied to a class that does not exist, and none of the
  migrations would have run - with no error.
- A behavioural test suite (21 tests, run through the real build class on each major) and CI:
  Silverstripe 5 on PHP 8.1 and 8.3, Silverstripe 6 on PHP 8.3 and 8.4, against MariaDB 11.4,
  plus a real `dev/build` per leg and, on Silverstripe 6, a `sake config:audit` check that
  fails when `DatabaseAdmin` is still given a `Restruct\` extension (another package's own
  `DatabaseAdmin` config is reported but does not fail the build). Each test leg also asserts the
  exact test count, and the Silverstripe 6 legs run with `--fail-on-empty-test-suite`.
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
