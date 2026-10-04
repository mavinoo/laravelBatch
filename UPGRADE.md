# Upgrading from 2.x to 3.0

3.0 rewrites how queries are built. Every value is now sent as a bound parameter, which fixes
SQL injection and data corruption in 2.x (quotes, backslashes, JSON, leading zeros), and makes
PostgreSQL and SQLite fully supported. Most code keeps working unchanged. Go through the list
below to find what applies to you.

## Requirements

- PHP 8.1 or newer
- Laravel 10, 11, 12 or 13

On older versions, stay on 2.5:

```bash
composer require "mavinoo/laravel-batch:^2.5"
```

## Quick checklist

1. `composer require "mavinoo/laravel-batch:^3.0"`
2. Search your code for calls that pass `true` as the `$raw` argument, and use `DB::raw()` instead ([details](#the-raw-argument-was-removed)).
3. Search for `=== false` / `!== false` checks on batch results ([details](#return-values)).
4. Search for `Mavinoo\Batch\Common\Common` and for the package's `str()` helper ([details](#removed-common-and-str)).
5. If you implement `BatchInterface` or extend `Batch`, update the method signatures ([details](#method-signatures)).

## Breaking changes

### The `$raw` argument was removed

`update()`, `updateWithTwoIndex()`, `updateMultipleCondition()` and the `HasBatch` methods
`batchUpdate()` and `updateMultipleCondition()` no longer take a `$raw` flag. In 2.x it turned
**every** value in the call into raw SQL, so one value from user input was enough for SQL injection.

Wrap only the values that are SQL in `DB::raw()`:

```php
// 2.x
Batch::update(new User, [
    ['id' => 1, 'score' => 'score * 2'],
], 'id', true);

// 3.0
use Illuminate\Support\Facades\DB;

Batch::update(new User, [
    ['id' => 1, 'score' => DB::raw('score * 2')],
], 'id');
```

Passing `true` for the old `$raw` argument throws an `InvalidArgumentException`, so a missed call
fails loudly instead of storing `score * 2` as text. Passing `false` is ignored.

### Return values

| Method | 2.x | 3.0 |
|---|---|---|
| `update()`, `updateWithTwoIndex()`, `updateMultipleCondition()` with no rows | `false` | `0` |
| `insert()` with no rows | `false` | `['totalRows' => 0, 'totalBatch' => …, 'totalQuery' => 0]` |
| `insert()` when a row has the wrong number of values | `false` | throws `InvalidArgumentException` |

The update methods always return the number of affected rows (`int`). `0` is still falsy, so
`if (! $result)` keeps working; `=== false` checks do not.

### Invalid input throws `InvalidArgumentException`

Nothing is written when one of these is detected:

- a row that isn't an array, or has no index value (`update()`, `updateWithTwoIndex()`)
- an array where a single value to match on is expected, such as `['id' => [1, 2]]`
- `updateWithTwoIndex()` without a second index column
- an `updateMultipleCondition()` item without `conditions` / `columns`, or without the key column in its conditions
- an `insert()` row with a different number of values than columns
- an invalid increment / decrement array, for example `['^', 1]` or `['+', 'abc']`.
  In 2.x these threw `ArgumentCountError` or `TypeError`.

### MySQL: condition columns that depend on each other

MySQL assigns the columns of an `UPDATE` one after another, so a condition can see a value that
was already changed in the same query. 3.0 orders the columns so every condition reads the old
values. This fixes, for example, matching on `status` while also changing `status`.

If two columns are both updated *and* used in each other's conditions within one
`updateMultipleCondition()` call, no order works on MySQL / MariaDB and the call throws an
`InvalidArgumentException`. Split it into two calls. PostgreSQL and SQLite are not affected.

### Removed `Common` and `str()`

- `Mavinoo\Batch\Common\Common` (`mysql_escape()` and friends) was removed. Escaping by hand is
  what made 2.x unsafe; use bound parameters or Laravel's query builder instead.
- The global `str()` helper defined by this package was removed. Laravel 9 and newer ship their
  own `str()` helper, which works the same way.

### Method signatures

`BatchInterface` and `Batch` now declare return types and no longer have `$raw`:

```php
public function update(Model $table, array $values, ?string $index = null): int;
public function updateWithTwoIndex(Model $table, array $values, ?string $index = null, ?string $index2 = null): int;
public function updateMultipleCondition(Model $table, array $values, ?string $index = null): int;
public function insert(Model $table, array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false): array;
```

Classes that implement the interface or override these methods must match.

## Other changes you may notice

- **Values are stored exactly as given.** Strings with quotes or backslashes, JSON, numeric
  strings with leading zeros (`'0912…'`) and booleans are no longer changed on the way in.
  2.x decoded and re-encoded JSON strings, which broke escaped quotes and line breaks inside them.
- **Large batches are split automatically** when they would exceed the database's limit on bound
  parameters. When a call needs more than one query, all of them run in one transaction on the
  model's connection.
- **`insert()` runs in a transaction on the model's own connection.** 2.x used the default
  connection, so a failed batch on another connection was not rolled back.
- **`$insertIgnore` works on PostgreSQL and SQLite** (through Laravel's `insertOrIgnore()`).
  SQL Server doesn't support it and throws.
- **`updateMultipleCondition()`** works with string key values and on PostgreSQL, and a `null`
  condition matches `NULL` columns.
- **`updated_at`** is only changed for rows where a value actually changes, on every database.
  An explicit `updated_at` in a row is always kept.
- **Arithmetic with a negative number** (`['-', -5]`) works on every database. In 2.x it produced
  `balance--5`, which PostgreSQL and SQLite read as the start of a comment.
