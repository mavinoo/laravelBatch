# Changelog

All notable changes to `mavinoo/laravel-batch` are documented here.
Older releases are listed on the [GitHub releases page](https://github.com/mavinoo/laravelBatch/releases).

## 3.3.0 - Unreleased

### Added
- Counter upserts: `upsert($table, $rows, ['sku'], ['qty' => ['+']])` adds to the stored value.
  Also `['-']`, `['max']` and `['min']`, on every database.
- Conditional upserts: the `onlyIf` option, e.g. `['onlyIf' => ['updated_at' => '>']]`, updates an
  existing row only when the new value wins.
- `upsertReturning()`: upsert and get every row of the batch back as stored, in input order.
- `insertOrIgnoreRows()`: insert, skip duplicates, and report exactly which rows were skipped.
- Documented and tested that `Model::withoutTimestamps()` turns timestamps off for every method (#97).
- `import()` option `fast`: load with `COPY` on PostgreSQL and `LOAD DATA LOCAL INFILE` on MySQL and
  MariaDB, 2 to 2.8 times faster. Bad values that MySQL would store with a warning throw instead.
- `import()` option `rules`: validate each row with Laravel's validator; failing rows are skipped and
  their messages returned in the new `errors` result, by row number.
- `export()` and the `exportTo()` builder macro: write the rows a query matches to CSV, TSV or JSON
  Lines files (also gzipped), a chunk at a time, optionally split into parts.

### Changed
- `upsert()` takes an optional fifth `$options` argument.
- `import()` also returns an `errors` key.

## 3.2.0 - 2026-10-05

### Added
- Update keys inside JSON columns with `column->key` (#64, #83, #111). Values keep their JSON type,
  missing objects are created and `updated_at` only changes when the document changes.
- Arrays and collections for columns the model casts to JSON are encoded the way the model stores
  them, in every update, insert and upsert method.
- `insertGetIds()` and `HasBatch::batchInsertGetIds()`: insert rows and get their auto-increment ids,
  in row order (#48).
- `import()` and `HasBatch::batchImport()`: import rows from CSV, TSV and JSON Lines files (also
  gzipped), open streams or any iterable, a chunk at a time with constant memory. Supports insert,
  insert-ignore and upsert, column mapping, transforms, progress and error callbacks.
- `splitFile()`: split a large file into parts by number of records and / or size, without cutting
  a record or a multi-line CSV field in half.
- `deleteInChunks()`, `updateInChunks()` and `archive()`: delete, update or move the rows a query
  matches a chunk at a time, walking the table in primary key order. Also available as
  `deleteInChunks()`, `updateInChunks()` and `archiveTo()` on Eloquent and query builders.
- `sync()`: insert, update and delete the rows a query matches so they match a list, in one
  transaction. The database compares the keys, so its collation decides which are equal. Also
  available as `syncRows()` on Eloquent builders.

### Fixed
- Updating a PostgreSQL `json` column failed with "operator does not exist: json <> unknown".
- MySQL `json` columns touched `updated_at` even when their value didn't change.

## 3.1.0 - 2026-10-04

### Added
- `updateByKeys()`: update rows matched on any number of key columns.
- `insertRows()`: insert rows given as column => value pairs.
- `upsert()`: insert or update in one query, with timestamps, enums and automatic splitting of
  large batches in one transaction.
- `pretend()`: return the statements a call would run, with bindings, without running them or
  connecting to the database.
- `deleteByKeys()`: delete rows matched on one or more key columns. Soft-deleting models are soft
  deleted unless `$force` is true.
- Static `HasBatch` methods: `batchUpdateMultipleCondition()`, `batchUpdateWithTwoIndex()`,
  `batchUpdateByKeys()`, `batchInsertRows()`, `batchUpsert()` and `batchDeleteByKeys()`.
- PHPStan (level 9) with Larastan in CI, and a `composer analyse` script.

### Changed
- Large updates are much faster: each `UPDATE ... CASE` statement now holds at most 100 rows. The
  database checks every row against the CASE branches one by one, so big statements got slow
  quickly. Updating 50,000 rows went from 30.5 s to 3.1 s on MySQL 8.4, from 25.3 s to 2.4 s on
  MariaDB 10.11, and from over 15 minutes to 2.5 s on PostgreSQL 16.
- The service provider registers one shared `Batch` instance, so the facade, `batch()` and the
  `HasBatch` trait see the same `pretend()` state.

### Deprecated
- The instance method `HasBatch::updateMultipleCondition()`; use the static
  `batchUpdateMultipleCondition()`. It will be removed in 4.0.

## 3.0.0 - 2026-10-04

Upgrading from 2.x: see [UPGRADE.md](UPGRADE.md).

### Security
- Build every query with bound parameters and grammar-quoted identifiers. 2.x escaped values by
  hand, which allowed SQL injection, always on PostgreSQL and through index values and JSON keys
  on every database.

### Breaking
- Require PHP 8.1+ and Laravel 10 – 13.
- Remove the `$raw` argument; wrap raw SQL values in `DB::raw()` instead. Passing `true` throws.
- Update methods return `int`, `insert()` returns `array`. Empty input returns `0` / zero counts
  instead of `false`.
- Invalid input throws `InvalidArgumentException` instead of returning `false`, failing with an SQL
  error, or throwing `ArgumentCountError` / `TypeError`.
- Add return types to `BatchInterface` and `Batch`.
- Remove `Mavinoo\Batch\Common\Common` and the global `str()` helper.

### Added
- Laravel 13 support.
- `DB::raw()` values in rows, index values and conditions.
- Enum values in every method (backed enums by value, other enums by name).
- Automatic splitting of batches that exceed the database's bound parameter limit, in one transaction.
- A standalone test suite (`composer test`) for SQLite, MySQL, MariaDB and PostgreSQL.
- GitHub Actions running the tests on PHP 8.1 – 8.5, Laravel 10 – 13 and every supported database.

### Fixed
- Strings with quotes or backslashes, JSON, numeric strings with leading zeros and booleans are
  stored exactly as given (#66, #68, #70, #73, #76, #96, #101).
- PostgreSQL and SQLite support for every method (#116).
- `updateMultipleCondition()` with string key values, on PostgreSQL, with `null` conditions, and
  when a condition column is updated in the same call on MySQL.
- `updated_at` is only touched for rows that change, on every database (#120).
- `insert()` uses a transaction on the model's connection, and `$insertIgnore` works on
  PostgreSQL and SQLite.
- Arithmetic with a negative operand on PostgreSQL and SQLite.
- Models with `CREATED_AT` or `UPDATED_AT` set to `null` (#107).

## 2.5.1 - 2026-10-04

### Fixed
- PHP 7.1 – 7.4 support, broken in 2.5.0 (#123).
- `updated_at` not being updated on MySQL / MariaDB, when a column changes from `NULL`, and an
  explicit `updated_at` being dropped (#123).
- `updateWithTwoIndex()` dropping leading zeros, failing on `false` and on PostgreSQL (#123).
- `updateMultipleCondition()` failing on older Laravel versions with `Stringable::toString` (#118).

## 2.5.0 - 2026-10-04

### Added
- Only touch `updated_at` when a value changes (#119).

### Fixed
- PHP 8.4 deprecation notices (#113).
- `updateWithTwoIndex()` writing to its index columns (#122).
