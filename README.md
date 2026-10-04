# Laravel BATCH (BULK)

Insert and update many rows in Laravel with a single query.

[![Tests](https://github.com/mavinoo/laravelBatch/actions/workflows/tests.yml/badge.svg)](https://github.com/mavinoo/laravelBatch/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/mavinoo/laravel-batch/license)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Latest Stable Version](https://poser.pugx.org/mavinoo/laravel-batch/v/stable)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Total Downloads](https://poser.pugx.org/mavinoo/laravel-batch/downloads)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Daily Downloads](https://poser.pugx.org/mavinoo/laravel-batch/d/daily)](https://packagist.org/packages/mavinoo/laravel-batch)

# Requirements

| laravel-batch | PHP       | Laravel                  | Databases                                          |
|---------------|-----------|--------------------------|----------------------------------------------------|
| 3.x           | 8.1 – 8.5 | 10 – 13                  | MySQL, MariaDB, PostgreSQL, SQLite                 |
| 2.5.x         | 7.1 – 8.5 | not pinned (tested 8, 11) | MySQL, MariaDB (PostgreSQL and SQLite only partly) |

Upgrading from 2.x? Read [UPGRADE.md](UPGRADE.md).

# Install

```bash
composer require mavinoo/laravel-batch
```

The service provider and the `Batch` facade are registered automatically.

# Update

Each row holds the index value (here `id`) and the columns to set. Rows can set different columns.

```php
use App\Models\User;
use Mavinoo\Batch\BatchFacade as Batch;

$affected = Batch::update(new User, [
    ['id' => 1, 'status' => 'active', 'nickname' => 'Mohammad'],
    ['id' => 5, 'status' => 'deactive'],
    ['id' => 10, 'date' => now()],
], 'id');
```

The index defaults to the model's primary key. `update()` returns the number of affected rows.
MySQL and MariaDB only count rows whose values really changed; PostgreSQL and SQLite count every
matched row.

If the same index value appears in more than one row of a call, only the first of those rows is applied.

Values can be strings, numbers, booleans, `null`, dates and enums (backed enums are stored by
their value, other enums by their name).

## Increment / decrement

```php
Batch::update(new User, [
    ['id' => 1, 'balance' => ['+', 500]], // add
    ['id' => 2, 'balance' => ['-', 200]], // subtract
    ['id' => 3, 'balance' => ['*', 5]],   // multiply
    ['id' => 4, 'balance' => ['/', 2]],   // divide
    ['id' => 5, 'balance' => ['%', 2]],   // modulo
]);
```

## Raw SQL values

Wrap a value in `DB::raw()` to use it as SQL instead of as a string:

```php
use Illuminate\Support\Facades\DB;

Batch::update(new User, [
    ['id' => 1, 'last_seen_at' => DB::raw('NOW()')],
    ['id' => 2, 'score' => DB::raw('score * 2 + bonus')],
]);
```

> **Never put user input inside `DB::raw()`.** Every other value is sent as a bound parameter and is safe.

## Update with two index columns

Rows are matched on both columns:

```php
Batch::updateWithTwoIndex(new Membership, [
    ['user_id' => 1, 'team_id' => 3, 'role' => 'admin'],
    ['user_id' => 2, 'team_id' => 3, 'role' => 'member'],
], 'user_id', 'team_id');
```

## Update with any number of key columns

Rows are matched on every column in the third argument:

```php
Batch::updateByKeys(new Score, [
    ['org_id' => 1, 'year' => 2025, 'code' => 'A', 'points' => 10],
    ['org_id' => 1, 'year' => 2026, 'code' => 'B', 'points' => 20],
], ['org_id', 'year', 'code']);
```

## Update with different conditions per row

Each item has its own `conditions` and `columns`. Every item's conditions must include the
key column (the third argument, the primary key by default).

```php
Batch::updateMultipleCondition(new User, [
    [
        'conditions' => ['id' => 1, 'status' => 'active'],
        'columns'    => ['status' => 'invalid', 'nickname' => 'mohammad'],
    ],
    [
        'conditions' => ['id' => 2],
        'columns'    => ['nickname' => 'mavinoo', 'name' => 'mohammad'],
    ],
], 'id');
```

A condition column can be updated in the same call, as `status` is above. On MySQL and MariaDB,
two columns that are both updated *and* used in each other's conditions can't be updated in one
call, because MySQL assigns columns one after another. The call throws an
`InvalidArgumentException`; split it into two calls.

## `updated_at`

If the model uses timestamps, `updated_at` is set to the current time **only for rows where a
value actually changes**. Pass `updated_at` in a row to set it yourself.

# Insert

```php
$result = Batch::insert(new User, ['name', 'email', 'is_active'], [
    ['Mohammad', 'mohammad@example.com', true],
    ['Saeed', 'saeed@example.com', false],
    ['Avin', 'avin@example.com', true],
], 500);

// ['totalRows' => 3, 'totalBatch' => 500, 'totalQuery' => 1]
```

- Each row lists its values in the same order as the columns.
- `created_at` and `updated_at` are filled in when the model uses timestamps.
- The fourth argument is the number of rows per query (at least 100, default 500).
- Pass `true` as the fifth argument to skip rows that hit a unique key (`INSERT IGNORE`).
  Not supported on SQL Server.

## Insert rows given as column => value pairs

`insertRows()` takes the same options as `insert()`, but each row names its columns. Every row
needs the same columns; their order doesn't matter.

```php
Batch::insertRows(new User, [
    ['name' => 'Mohammad', 'email' => 'mohammad@example.com'],
    ['email' => 'saeed@example.com', 'name' => 'Saeed'],
], 500);
```

# Delete

Delete rows matched on one or more key columns:

```php
Batch::deleteByKeys(new Score, [
    ['org_id' => 1, 'year' => 2025],
    ['org_id' => 2, 'year' => 2026],
], ['org_id', 'year']);
```

Models that use `SoftDeletes` are soft deleted (only `deleted_at`, and `updated_at`, are set).
Pass `true` as the fourth argument to delete them for real.

# Upsert

Insert rows, or update them when a row with the same `$uniqueBy` values already exists, in one query:

```php
Batch::upsert(new User, [
    ['email' => 'ali@example.com', 'name' => 'Ali', 'score' => 90],   // exists: name and score are updated
    ['email' => 'sara@example.com', 'name' => 'Sara', 'score' => 75], // new: inserted
], ['email'], ['name', 'score']);
```

- The third argument lists the columns that identify an existing row. They need a primary or
  unique index. On MySQL and MariaDB, any unique index decides what counts as an existing row.
- The fourth argument lists the columns to update on existing rows; leave it out (`null`) to update
  every given column.
- `created_at` is only set on new rows and `updated_at` on every row, like Eloquent's `upsert()`.
- Unlike `Model::upsert()`, large batches are split into several queries automatically, in one
  transaction.
- The returned count comes from the database: MySQL and MariaDB count an updated row as 2.

# Preview the SQL without running it

`pretend()` returns the statements Batch would run, with their bindings. Nothing is executed and no
database connection is opened.

```php
$queries = Batch::pretend(function ($batch) {
    $batch->update(new User, [['id' => 1, 'name' => 'Ali']]);
});

// [['sql' => 'UPDATE `users` SET ... WHERE `id` IN (?)', 'bindings' => [...], 'connection' => 'mysql']]
```

Calls made inside the callback through the facade, `batch()` or the `HasBatch` trait are recorded too.

# From a model

Add the `HasBatch` trait:

```php
use Illuminate\Database\Eloquent\Model;
use Mavinoo\Batch\Traits\HasBatch;

class User extends Model
{
    use HasBatch;
}
```

```php
User::batchUpdate($values, 'id');
User::batchUpdateWithTwoIndex($values, 'user_id', 'team_id');
User::batchUpdateByKeys($values, ['org_id', 'year']);
User::batchUpdateMultipleCondition($items, 'id');
User::batchInsert($columns, $values, 500);
User::batchInsertRows($rows, 500);
User::batchUpsert($rows, ['email'], ['name']);
User::batchDeleteByKeys($rows, ['org_id', 'year']);
```

`(new User)->updateMultipleCondition()` still works, but is deprecated in favour of the static
`User::batchUpdateMultipleCondition()` and will be removed in 4.0.

# Helper

```php
batch()->update(new User, $values, 'id');
batch()->insert(new User, $columns, $values, 500);
```

# Large batches and errors

- Batches that would go over the database's limit on bound parameters are split into several
  queries automatically, and each `UPDATE` holds at most 100 rows, which benchmarks showed to be the
  fastest size on every supported database. Several queries always run in one transaction on the model's connection,
  so either every row is written or none is.
- Invalid input (a row that isn't an array, a row without its index value, an array where a single
  value to match on is expected, a row with the wrong number of values, an invalid increment array)
  throws an `InvalidArgumentException` before anything is written.
- An empty list of rows does nothing: updates return `0`, and `insert()` returns `totalRows` `0`.

# Tests

The tests run on their own, from the root of this package:

```bash
composer install
composer test
```

They use an in-memory SQLite database by default. To run them against MySQL, MariaDB or PostgreSQL,
create an empty `batch_test` database and set `DB_CONNECTION` (plus `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD` or `DB_SOCKET` as needed):

```bash
DB_CONNECTION=mysql DB_USERNAME=root composer test
DB_CONNECTION=pgsql DB_USERNAME=postgres composer test
```

# Donate

USDT (BSC) Address: `0xe848f4a94adb70aba2f2da92181096b18aeb269b`
