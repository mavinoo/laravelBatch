# Laravel BATCH (BULK)

Bulk database operations for Laravel: update, insert, upsert, delete, sync, import and export
many rows in a few queries, on MySQL, MariaDB, PostgreSQL and SQLite.

[![Tests](https://github.com/mavinoo/laravelBatch/actions/workflows/tests.yml/badge.svg)](https://github.com/mavinoo/laravelBatch/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/mavinoo/laravel-batch/license)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Latest Stable Version](https://poser.pugx.org/mavinoo/laravel-batch/v/stable)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Total Downloads](https://poser.pugx.org/mavinoo/laravel-batch/downloads)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Daily Downloads](https://poser.pugx.org/mavinoo/laravel-batch/d/daily)](https://packagist.org/packages/mavinoo/laravel-batch)

## What it does

- [Update](#update) many rows with different values in one query, with increments, raw SQL and
  [JSON paths](#json-columns).
- [Insert](#insert) many rows, [get their ids](#insert-and-get-the-new-ids), or
  [skip duplicates and see which](#insert-and-report-the-skipped-rows).
- [Upsert](#upsert) with [counters](#counters), [conditions](#update-only-when-the-new-row-wins)
  and [the stored rows returned](#get-the-rows-back).
- [Delete](#delete) rows matched on several key columns.
- [Sync](#sync-a-table-with-a-list) part of a table with a list: insert, update and delete in one go.
- [Import](#import-from-a-file-or-any-iterable) CSV, TSV and JSON Lines files or any iterable with
  constant memory, [row validation](#validate-rows) and the database's [own bulk loader](#fast-imports).
- [Export](#export) a query to files, and [split large files](#split-a-large-file).
- [Delete, update or archive millions of rows](#large-deletes-updates-and-archives) a chunk at a time.
- [Preview the SQL](#preview-the-sql-without-running-it) of any call without running it.

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

## JSON columns

Set keys inside a JSON column with `->`, like Laravel's `update()`:

```php
Batch::update(new User, [
    ['id' => 1, 'settings->theme' => 'dark', 'settings->notify->email' => false],
    ['id' => 2, 'settings->theme' => 'light'],
]);
```

- Other keys in the document are kept. Missing objects along the path are created, and a `NULL`
  column starts as an empty object.
- Values keep their JSON type: `false` is stored as `false`, arrays as JSON arrays or objects.
  `DB::raw()` values are used as given.
- Path keys are object keys; they can't contain quotes or backslashes.
- A row can't set a column and a path inside it in the same call.
- Works with every update method on MySQL, MariaDB, PostgreSQL and SQLite.

For columns the model casts to JSON (`array`, `json`, `object`, `collection`, `AsArrayObject`,
`AsCollection`, ...), arrays and collections are encoded the way the model would store them, in
every method:

```php
Batch::update(new User, [['id' => 1, 'settings' => ['theme' => 'dark']]]);
```

Without such a cast, an array value is read as an increment / decrement.

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

## Insert and get the new ids

`insertGetIds()` takes the same rows as `insertRows()` and returns the new auto-increment ids, in
the same order as the rows:

```php
$ids = Batch::insertGetIds(new User, [
    ['name' => 'Ali', 'email' => 'ali@example.com'],
    ['name' => 'Sara', 'email' => 'sara@example.com'],
]);

// [101, 102]
```

- PostgreSQL returns the ids with `INSERT ... RETURNING`. On MySQL and MariaDB they are computed
  from `LAST_INSERT_ID()` and `auto_increment_increment`, and on SQLite from `last_insert_rowid()`:
  these databases give the rows of one multi-row `INSERT` consecutive ids, also while other
  connections insert at the same time.
- The model's primary key must be auto-incrementing, and the rows can't set it.
- It's a separate method, so `insert()` and `insertRows()` don't pay for fetching ids.
- Inside `pretend()` nothing runs, so it returns `[]`.

## Insert and report the skipped rows

`insertOrIgnoreRows()` inserts rows, skips the ones that hit a unique key, like `insertOrIgnore()`,
and tells you exactly which ones were skipped:

```php
$result = Batch::insertOrIgnoreRows(new User, $rows, ['email']);
// ['inserted' => 98, 'skipped' => [['email' => 'dup@example.com', 'name' => 'Ali'], ...]]
```

- The second argument names the columns that identify a row; the skipped rows are returned as given.
- A key given twice in the list is inserted once and reported as skipped the second time.
- PostgreSQL and SQLite report with `RETURNING`. MySQL and MariaDB need an auto-incrementing primary
  key that the rows don't set.
- It is exact also while other connections insert the same keys. Like any `INSERT`, inserting the
  same keys from several connections at once can deadlock: retry the call when it does.

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

## Counters

Combine the stored value with the new one instead of replacing it, for stock levels, totals or
high scores:

```php
Batch::upsert(new Stock, [
    ['sku' => 'A1', 'qty' => 5],   // exists: qty = qty + 5
    ['sku' => 'B2', 'qty' => 10],  // new: inserted with qty 10
], ['sku'], ['qty' => ['+']]);
```

`['+']` adds, `['-']` subtracts, `['max']` keeps the larger value and `['min']` the smaller one. A
stored `NULL` counts as 0 for `+` and `-`, and is replaced for `max` and `min`. Counter and plain
columns can be mixed: `['name', 'qty' => ['+']]`.

## Update only when the new row wins

`onlyIf` updates an existing row only when the new value compares that way with the stored one,
or the stored one is `NULL`. New rows are always inserted:

```php
// Rows from an external API: keep whichever version was changed last.
Batch::upsert(new Product, $rows, ['sku'], null, ['onlyIf' => ['updated_at' => '>']]);
```

The operators are `>`, `>=`, `<`, `<=` and `<>`. Several columns must all match. On MySQL and
MariaDB at most one `onlyIf` column can also be updated.

## Get the rows back

`upsertReturning()` takes the same arguments as `upsert()`, plus the columns to return, and gives
back every row of the batch as it is stored afterwards, in the order of the input:

```php
$users = Batch::upsertReturning(new User, $rows, ['email'], ['name'], ['id', 'email']);
// [['id' => 7, 'email' => 'ali@example.com'], ['id' => 102, 'email' => 'sara@example.com']]
```

The rows are read back by their `uniqueBy` values in the same transaction, so this works the same
on every database, MySQL included. Rows an `onlyIf` condition left alone are returned as stored.

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

# Sync a table with a list

`sync()` makes the rows a query matches look like a list, in one transaction: rows that are new
are inserted, rows that exist are updated, and rows the list doesn't have are deleted.

```php
$result = Batch::sync(Product::where('supplier_id', 5), $feedRows, ['sku']);
// ['inserted' => 120, 'updated' => 4800, 'deleted' => 35]

// The same, on the query:
Product::where('supplier_id', 5)->syncRows($feedRows, ['sku']);
```

- Only rows in the scope query can be deleted, so the scope is always explicit: pass
  `Product::query()` to sync a whole table. The rows of the list should belong to the scope (here,
  have `supplier_id` 5).
- The third argument lists the columns that identify a row; they need a primary or unique index.
  The fourth lists the columns to update on existing rows (default: every given column).
- An empty list would delete every row in the scope, so it throws unless `allowEmpty: true`.
- The keys are compared by the database itself, through a temporary table, so its collation
  decides which keys are equal. On MySQL, `"ABC"` and `"abc"` are the same key by default, and
  `sync()` won't delete a row it just updated.
- When a key appears twice in the list, the later row wins.
- The list can be any iterable, such as a generator or a `LazyCollection`, and is written a chunk at
  a time: syncing 100,000 rows into a 100,000-row table took 1.4 to 4.8 seconds and 15 MB of memory.
- Models that use `SoftDeletes` are soft deleted unless `force: true`, and soft-deleted rows that are
  in the list are restored. Model events are not fired.
- The database user needs permission to create temporary tables.

# Import from a file or any iterable

`import()` reads rows one at a time and writes them a chunk at a time, so files of any size are
imported with constant memory. Importing a 500,000-row CSV file used 7 MB of memory instead of the
765 MB that reading it into an array for `insertRows()` took, and was 30 to 45% faster.

```php
$result = Batch::import(new User, storage_path('users.csv'), [
    'mode'      => 'upsert',                  // 'insert' (default), 'insertIgnore' or 'upsert'
    'uniqueBy'  => ['email'],
    'update'    => ['name'],
    'map'       => ['E-mail' => 'email', 'Full name' => 'name'], // only mapped columns are imported
    'transform' => fn (array $row) => $row['email'] ? $row : null, // null skips the row
]);

// ['totalRows' => 25000, 'skipped' => 12]
```

The source can be:

- a CSV, TSV or JSON Lines file (`.csv`, `.tsv`, `.jsonl` / `.ndjson`, also gzipped as `.csv.gz`);
- an open stream, such as `Storage::disk('s3')->readStream('users.csv')`, with the `format` option;
- any iterable of rows: an array, a generator, a `LazyCollection`, or `Model::cursor()` to copy
  rows between tables or connections.

| Option | Default | |
|---|---|---|
| `mode` | `'insert'` | `'insert'`, `'insertIgnore'` or `'upsert'` |
| `uniqueBy`, `update` | | the `upsert()` arguments, for `'upsert'` |
| `chunk` | `1000` | rows per write |
| `atomic` | `true` | one transaction for the whole import; `false` commits each chunk |
| `format` | from the extension | `'csv'`, `'tsv'` or `'jsonl'` |
| `header` | `true` | the first CSV row names the columns |
| `columns` | | column names for a CSV file without a header |
| `delimiter`, `enclosure` | `,` (tab for TSV), `"` | |
| `map` | | `[source column => table column]` |
| `nullValues` | `[]` | strings stored as `null`, e.g. `['', 'NULL']` |
| `transform` | | `fn (array $row, int $number): ?array` |
| `onChunk` | | `fn (int $written)`, after every chunk |
| `onError` | | `fn (InvalidArgumentException $e, int $number)`: skip malformed rows instead of stopping |
| `rules` | | Laravel validation rules for each row; failing rows are skipped and reported |
| `fast` | `false` | load with `COPY` (PostgreSQL) or `LOAD DATA LOCAL INFILE` (MySQL, MariaDB) |

- CSV files are read the RFC 4180 way: quotes inside a field are doubled, and quoted fields can span
  lines. A UTF-8 BOM is removed.
- Every row needs the same columns. A row that can't be read (wrong number of fields, invalid
  JSON, different columns) stops the import with its row number, unless `onError` is given.
- Never pass a path that comes from user input.

## Validate rows

Rows are checked with Laravel's validator after `map` and `nullValues`, and before `transform`.
Rows that fail are skipped and their messages are returned by row number:

```php
$result = Batch::import(new User, 'users.csv', [
    'rules' => ['email' => 'required|email', 'age' => 'nullable|integer|min:0'],
]);

// ['totalRows' => 9988, 'skipped' => 12, 'errors' => [15 => ['email' => ['The email field must be a valid email address.']], ...]]
```

## Fast imports

`'fast' => true` hands each chunk to the database's own bulk loader: `COPY` on PostgreSQL and
`LOAD DATA LOCAL INFILE` on MySQL and MariaDB. Importing a 1,000,000-row CSV file took 6.6 s instead
of 18.7 s on PostgreSQL, 8.4 s instead of 18.7 s on MySQL and 6.4 s instead of 12.6 s on MariaDB.

```php
Batch::import(new Order, storage_path('orders.csv'), ['fast' => true]);
```

- Only the default `insert` mode, and no `DB::raw()` values. Enums, JSON casts and timestamps work as
  usual. The chunk size defaults to 10,000 rows.
- MySQL and MariaDB need `LOCAL INFILE` allowed on both sides: add
  `PDO::MYSQL_ATTR_LOCAL_INFILE => true` to the connection's `options` in `config/database.php`, and
  set `local_infile = ON` on the server (MySQL 8 ships with it off).
- `LOAD DATA LOCAL` stores bad values (a word in a number column) with a warning instead of failing.
  Batch checks the warnings and throws, so with the default `atomic` import nothing is written.
- On SQLite and other databases, `fast` imports as usual.

## Split a large file

```php
$parts = Batch::splitFile(storage_path('users.csv'), lines: 50000);
// ['.../users-001.csv', '.../users-002.csv', ...]

Batch::splitFile($path, bytes: 100 * 1024 * 1024);          // at most 100 MB per part
Batch::splitFile($path, lines: 50000, bytes: 50_000_000);   // whichever comes first
```

- A record is never cut in half. In CSV files a quoted field that spans several lines stays one
  record, and the header row is repeated in every part (`'header' => false` turns that off).
- A record bigger than `bytes` gets a part of its own.
- Records are copied byte for byte, so quoting and line endings don't change.
- Parts go next to the file, or into the `directory` option. An existing part throws, unless
  `'overwrite' => true`. A `.gz` file is read and split into plain parts.

# Export

`export()` writes the rows a query matches to a CSV, TSV or JSON Lines file, also gzipped, a chunk at
a time with constant memory:

```php
Batch::export(User::where('active', true), storage_path('users.csv'), [
    'columns' => ['id', 'email', 'name'],
    'maxRows' => 100000,                  // users-001.csv, users-002.csv, ...
]);
// ['totalRows' => 250000, 'files' => ['.../users-001.csv', '.../users-002.csv', '.../users-003.csv']]

User::where('active', true)->exportTo(storage_path('users.jsonl'));   // on the query
```

| Option | Default | |
|---|---|---|
| `columns` | the query's select, or every column | the columns to export |
| `format` | from the extension | `'csv'`, `'tsv'` or `'jsonl'` |
| `header` | `true` | write the column names first in CSV files |
| `delimiter`, `enclosure` | `,` (tab for TSV), `"` | |
| `maxRows` | | rows per file; the parts are numbered |
| `chunk` | `1000` | rows per query |
| `overwrite` | `false` | replace existing files instead of throwing |
| `transform` | | `fn (array $row): ?array`; `null` leaves the row out |
| `onChunk` | | `fn (int $written)`, after every chunk |

Rows are read in primary key order (`DB::table()` queries use `id`), so the query can't have a limit
or offset. CSV files are written the RFC 4180 way and can be imported again with `import()`.

# Large deletes, updates and archives

Deleting or updating millions of rows in one statement locks the table for a long time and fills
the undo log. These methods do it a chunk at a time. Rows are walked in primary key order
(`WHERE id > ? ORDER BY id LIMIT ?`), which works on every database and always ends, even when
the update stops rows matching the query.

```php
// Delete
Batch::deleteInChunks(Log::where('created_at', '<', now()->subYear()), chunk: 10000, sleepMs: 100);

// Update every matching row with the same values
Batch::updateInChunks(User::where('active', false), ['status' => 'archived'], chunk: 5000);

// Move rows into another table
Batch::archive(Order::where('created_at', '<', '2020-01-01'), 'orders_archive', chunk: 5000);
```

The same methods are available on every Eloquent and query builder:

```php
Log::where('created_at', '<', now()->subYear())->deleteInChunks(10000);
User::where('active', false)->updateInChunks(['status' => 'archived']);
Order::where('created_at', '<', '2020-01-01')->archiveTo('orders_archive');
DB::table('logs')->where('level', 'debug')->deleteInChunks();
```

- All three return the number of rows they changed. `sleepMs` pauses between chunks, to go easy on
  the server and its replicas, and `onChunk` is called after every chunk with the running total.
- Each chunk is its own statement, so the whole operation is not one transaction.
- Models that use `SoftDeletes` are soft deleted by `deleteInChunks()`, unless `force: true`.
  Model events are not fired.
- `updateInChunks()` sets `updated_at` for Eloquent queries, and accepts `DB::raw()`, enums and
  `column->key` JSON paths. Arrays are only allowed for columns the model casts to JSON; use
  `DB::raw('balance + 1')` to increment.
- `archive()` locks each chunk and copies it with `INSERT ... SELECT` before deleting it, in one
  transaction, so no row is lost or copied twice. It copies the columns both tables have, or the
  `columns` you pass; `delete: false` only copies. The source rows are deleted for real, also for
  soft-deleting models.
- `archive()` to a model on another connection can't use one transaction. It copies the chunk with
  `INSERT IGNORE` first and deletes it after, so running it again after a failure is safe. The
  target table needs the primary key column, as a primary or unique key.
- Queries can't have a limit or offset. `DB::table()` queries are walked on the `id` column.
- Inside `pretend()` nothing runs: the `SELECT` that picks the first chunk is recorded.

# Turn timestamps off

Laravel's `withoutTimestamps()` works with every Batch method:

```php
User::withoutTimestamps(fn () => Batch::update(new User, $rows));  // updated_at is left alone
```

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
User::batchInsertGetIds($rows, 500);
User::batchImport(storage_path('users.csv'), ['mode' => 'upsert', 'uniqueBy' => ['email']]);
User::batchUpsert($rows, ['email'], ['name']);
User::batchUpsertReturning($rows, ['email'], ['name'], ['id', 'email']);
User::batchInsertOrIgnoreRows($rows, ['email']);
User::batchDeleteByKeys($rows, ['org_id', 'year']);
```

The query-based methods are available on every Eloquent query, with or without the trait:

```php
User::where('active', false)->deleteInChunks(1000);
User::where('active', false)->updateInChunks(['status' => 'archived']);
User::where('created_at', '<', '2020-01-01')->archiveTo('users_archive');
User::where('org_id', 5)->syncRows($rows, ['email']);
User::where('active', true)->exportTo(storage_path('users.csv'));
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
  fastest size on every supported database. Several queries run in one transaction on the model's
  connection, so either every row is written or none is. The exceptions are the chunked methods,
  which commit every chunk on purpose, and `import()` with `'atomic' => false`.
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

On MySQL the fast-import tests turn `local_infile` on with `SET GLOBAL`, so the MySQL user needs
that privilege (`root` has it).

# Donate

USDT (BSC) Address: `0xe848f4a94adb70aba2f2da92181096b18aeb269b`
