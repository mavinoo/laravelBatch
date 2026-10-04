# Changelog

All notable changes to `mavinoo/laravel-batch` are documented here.
Older releases are listed on the [GitHub releases page](https://github.com/mavinoo/laravelBatch/releases).

## 3.0.0 - Unreleased

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
