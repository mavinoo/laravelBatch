<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Casts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Mavinoo\Batch\Support\JsonPath;
use Mavinoo\Batch\Support\RecordingConnection;

/**
 * @phpstan-type Statement array{0: string, 1: list<mixed>}
 * @phpstan-type Entry array{conditions: array<string, mixed>, columns: array<string, mixed>}
 * @phpstan-type CompiledEntry array{conditions: array<string, mixed>, cases: array<string, Statement>, touch: Statement|null, cost: int, json: array<string, true>}
 * @phpstan-type RecordedQuery array{sql: string, bindings: array<mixed>, connection: string}
 */
class Batch implements BatchInterface
{
    /**
     * Maximum number of bound parameters sent in a single query, per driver.
     * Larger batches are split into several queries run in one transaction.
     */
    protected const MAX_BINDINGS = [
        'mysql'   => 65000,
        'mariadb' => 65000,
        'pgsql'   => 65000,
        'sqlsrv'  => 2000,
        'sqlite'  => 999,
    ];

    protected const DEFAULT_MAX_BINDINGS = 999;

    /**
     * Maximum number of rows in one UPDATE ... CASE statement.
     *
     * The database checks a row against the CASE branches one by one, so the cost of a statement
     * grows with the square of its rows. Benchmarks on MySQL 8.4, MariaDB 10.11, PostgreSQL 16 and
     * SQLite 3.45 were fastest at around 100 rows; 5,000-row statements were 10 to 60 times slower.
     */
    protected const MAX_ROWS_PER_UPDATE = 100;

    /**
     * Built-in Eloquent casts that store a column as JSON.
     */
    private const JSON_CASTS = [
        'array', 'json', 'json:unicode', 'object', 'collection',
        'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object',
    ];

    /**
     * Eloquent cast classes that store a column as JSON.
     */
    private const JSON_CAST_CLASSES = [
        Casts\AsArrayObject::class,
        Casts\AsCollection::class,
        Casts\AsEncryptedArrayObject::class,
        Casts\AsEncryptedCollection::class,
        Casts\AsEnumArrayObject::class,
        Casts\AsEnumCollection::class,
    ];

    /**
     * @var DatabaseManager
     */
    protected $db;

    /**
     * Statements recorded by pretend(), or null when Batch runs queries for real.
     *
     * @var list<RecordedQuery>|null
     */
    private $pretended = null;

    /**
     * @var array<string, RecordingConnection>
     */
    private $recorders = [];

    /**
     * Columns of the json type on PostgreSQL and MySQL, per connection and table.
     *
     * @var array<string, array<string, true>>
     */
    private $jsonTypeColumns = [];

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    /**
     * Update many rows, matched on one column, in a single query.
     *
     * Example:
     * ```
     * Batch::update(new User, [
     *     ['id' => 1, 'status' => 'active', 'nickname' => 'Mohammad'],
     *     ['id' => 5, 'status' => 'deactive'],
     *     ['id' => 7, 'balance' => ['+', 500]],          // arithmetic: + - * / %
     *     ['id' => 9, 'seen_at' => DB::raw('NOW()')],    // raw SQL for one value
     * ], 'id');
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $values rows, each holding the index value and the columns to set
     * @param string|null $index column to match rows on, defaults to the primary key
     * @return int number of affected rows
     *
     * @throws InvalidArgumentException when a row has no index value or an arithmetic array is invalid
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
     * @updatedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function update(Model $table, array $values, ?string $index = null): int
    {
        $this->rejectRawArgument(func_get_args(), 3);

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        return $this->updateByKeys($table, $values, [$index]);
    }

    /**
     * Update many rows, matched on two columns, in a single query.
     *
     * Example:
     * ```
     * Batch::updateWithTwoIndex(new Membership, [
     *     ['user_id' => 1, 'team_id' => 3, 'role' => 'admin'],
     *     ['user_id' => 2, 'team_id' => 3, 'role' => 'member'],
     * ], 'user_id', 'team_id');
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $values rows, each holding both index values and the columns to set
     * @param string|null $index first column to match on, defaults to the primary key
     * @param string|null $index2 second column to match on
     * @return int number of affected rows
     *
     * @throws InvalidArgumentException when $index2 is missing, a row lacks an index value or an arithmetic array is invalid
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
     * @updatedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function updateWithTwoIndex(Model $table, array $values, ?string $index = null, ?string $index2 = null): int
    {
        $this->rejectRawArgument(func_get_args(), 4);

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        if (!isset($index2) || empty($index2)) {
            throw new InvalidArgumentException('updateWithTwoIndex() requires a second index column.');
        }

        return $this->updateByKeys($table, $values, [$index, $index2]);
    }

    /**
     * Update many rows, matched on any number of key columns, in a single query.
     *
     * Example:
     * ```
     * Batch::updateByKeys(new Score, [
     *     ['org_id' => 1, 'year' => 2025, 'code' => 'A', 'points' => 10],
     *     ['org_id' => 1, 'year' => 2026, 'code' => 'B', 'points' => 20],
     * ], ['org_id', 'year', 'code']);
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $values rows, each holding every key column and the columns to set
     * @param array<array-key, mixed> $keys the columns a row is matched on
     * @return int number of affected rows
     *
     * @throws InvalidArgumentException when $keys is empty, a row lacks a key value or an arithmetic array is invalid
     */
    public function updateByKeys(Model $table, array $values, array $keys): int
    {
        $keys = $this->keyColumns($keys, 'updateByKeys');

        $entries = [];
        foreach ($values as $row) {
            $this->assertHasConditions($row, $keys);

            $conditions = [];
            foreach ($keys as $key) {
                $conditions[$key] = $row[$key];
                unset($row[$key]);
            }

            $entries[] = ['conditions' => $conditions, 'columns' => $row];
        }

        return $this->runCaseUpdate($table, $entries, $keys);
    }

    /**
     * Update many rows, each matched on its own set of conditions, in a single query.
     *
     * Every item must include the $index column in its conditions. On MySQL / MariaDB,
     * two condition columns that are both updated and used in each other's conditions
     * can't be updated in one call, because MySQL assigns columns one after another.
     *
     * Example:
     * ```
     * Batch::updateMultipleCondition(new User, [
     *     ['conditions' => ['id' => 1, 'status' => 'active'], 'columns' => ['status' => 'invalid', 'nickname' => 'mohammad']],
     *     ['conditions' => ['id' => 2], 'columns' => ['nickname' => 'mavinoo']],
     * ], 'id');
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $values items of ['conditions' => [column => value], 'columns' => [column => value]]
     * @param string|null $index column every item's conditions include, defaults to the primary key
     * @return int number of affected rows
     *
     * @throws InvalidArgumentException when an item is malformed or the update can't be ordered safely on MySQL
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
     */
    public function updateMultipleCondition(Model $table, array $values, ?string $index = null): int
    {
        $this->rejectRawArgument(func_get_args(), 3);

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        $entries = [];
        foreach ($values as $item) {
            if (!is_array($item) || !isset($item['conditions'], $item['columns']) || !is_array($item['conditions']) || !is_array($item['columns'])) {
                throw new InvalidArgumentException('Each item needs a "conditions" array and a "columns" array.');
            }

            $this->assertHasConditions($item['conditions'], [$index]);
            $this->assertColumnNames($item['columns']);

            $entries[] = ['conditions' => $item['conditions'], 'columns' => $item['columns']];
        }

        return $this->runCaseUpdate($table, $entries, [$index]);
    }

    /**
     * Insert many rows, $batchSize rows per query, in one transaction.
     *
     * Example:
     * ```
     * Batch::insert(new User, ['name', 'email'], [
     *     ['Mohammad', 'mohammad@example.com'],
     *     ['Saeed', 'saeed@example.com'],
     * ], 500);
     * ```
     *
     * @param Model $table
     * @param array<int, string> $columns column names
     * @param array<array-key, mixed> $values rows, each a list of values in the same order as $columns
     * @param int $batchSize rows per query (at least 100)
     * @param bool $insertIgnore skip rows that hit a unique key (not supported on SQL Server)
     * @return array{totalRows: int, totalBatch: int, totalQuery: int}
     *
     * @throws InvalidArgumentException when a row doesn't have one value per column
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
     * @updatedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function insert(Model $table, array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false): array
    {
        $minChunck = 100;

        $totalValues = count($values);
        $batchSizeInsert = ($totalValues < $batchSize && $batchSize < $minChunck) ? $minChunck : $batchSize;

        $totalChunk = ($batchSizeInsert < $minChunck) ? $minChunck : $batchSizeInsert;

        if (!$totalValues) {
            return ['totalRows' => 0, 'totalBatch' => $totalChunk, 'totalQuery' => 0];
        }

        $rows = $this->prepareInsertRows($table, $columns, $values);

        $connection = $this->db->connection($this->getConnectionName($table));

        $rowsPerQuery = $this->rowsPerInsert($connection, $totalChunk, count($rows[0]));

        return $this->transaction($connection, function () use ($connection, $table, $rows, $rowsPerQuery, $insertIgnore, $totalValues, $totalChunk) {
            $totalQuery = 0;
            foreach (array_chunk($rows, $rowsPerQuery) as $chunk) {
                $query = $this->baseQuery($connection, $table);
                $insertIgnore ? $query->insertOrIgnore($chunk) : $query->insert($chunk);
                $totalQuery++;
            }

            return [
                    'totalRows' => $totalValues,
                    'totalBatch' => $totalChunk,
                    'totalQuery' => $totalQuery
            ];
        });
    }

    /**
     * Insert many rows given as column => value pairs, $batchSize rows per query, in one transaction.
     *
     * Example:
     * ```
     * Batch::insertRows(new User, [
     *     ['name' => 'Ali', 'email' => 'ali@example.com'],
     *     ['email' => 'sara@example.com', 'name' => 'Sara'], // key order doesn't matter
     * ]);
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $rows rows of column => value pairs, all with the same columns
     * @param int $batchSize rows per query (at least 100)
     * @param bool $insertIgnore skip rows that hit a unique key (not supported on SQL Server)
     * @return array{totalRows: int, totalBatch: int, totalQuery: int}
     *
     * @throws InvalidArgumentException when a row isn't column => value pairs or has different columns
     */
    public function insertRows(Model $table, array $rows, int $batchSize = 500, bool $insertIgnore = false): array
    {
        [$columns, $values] = $this->splitRows($rows);

        return $this->insert($table, $columns, $values, $batchSize, $insertIgnore);
    }

    /**
     * Insert many rows given as column => value pairs and return their auto-increment ids,
     * in the same order as the rows.
     *
     * On PostgreSQL the ids come from INSERT ... RETURNING. On MySQL and MariaDB they are
     * computed from LAST_INSERT_ID(), and on SQLite from last_insert_rowid(): a multi-row
     * INSERT gets consecutive ids there. Inside pretend() nothing runs, so it returns [].
     *
     * Example:
     * ```
     * $ids = Batch::insertGetIds(new User, [
     *     ['name' => 'Ali', 'email' => 'ali@example.com'],
     *     ['name' => 'Sara', 'email' => 'sara@example.com'],
     * ]);
     * // [101, 102]
     * ```
     *
     * @param Model $table a model with an auto-incrementing primary key
     * @param array<array-key, mixed> $rows rows of column => value pairs, all with the same columns
     * @param int $batchSize rows per query (at least 100)
     * @return list<int> the new ids, in the order of $rows
     *
     * @throws InvalidArgumentException when the key isn't auto-incrementing, a row sets it, or a row is malformed
     */
    public function insertGetIds(Model $table, array $rows, int $batchSize = 500): array
    {
        $keyName = $table->getKeyName();

        if (!$table->getIncrementing()) {
            throw new InvalidArgumentException('insertGetIds() needs a model with an auto-incrementing primary key.');
        }

        [$columns, $values] = $this->splitRows($rows);

        if (!$values) {
            return [];
        }

        if (in_array($keyName, $columns, true)) {
            throw new InvalidArgumentException("Rows passed to insertGetIds() can't set the \"{$keyName}\" key; use insertRows() instead.");
        }

        $rows = $this->prepareInsertRows($table, $columns, $values);
        $connection = $this->db->connection($this->getConnectionName($table));
        $driver = $connection->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException("insertGetIds() is not supported on the \"{$driver}\" driver.");
        }

        $rowsPerQuery = $this->rowsPerInsert($connection, max(100, $batchSize), count($rows[0]));

        return $this->transaction($connection, function () use ($connection, $table, $rows, $rowsPerQuery, $keyName, $driver) {
            $runner = $this->runner($connection);
            $pretending = !is_null($this->pretended);
            $ids = [];
            $step = null;

            foreach (array_chunk($rows, $rowsPerQuery) as $chunk) {
                $query = $this->baseQuery($connection, $table);
                $bindings = $query->cleanBindings(Arr::flatten($chunk, 1));

                if ($driver === 'pgsql') {
                    $sql = $query->getGrammar()->compileInsertGetId($query, $chunk, $keyName);

                    if ($pretending) {
                        $runner->insert($sql, $bindings);
                        continue;
                    }

                    foreach ($connection->selectFromWriteConnection($sql, $bindings) as $row) {
                        $ids[] = $this->toId(((array) $row)[$keyName] ?? null);
                    }
                    continue;
                }

                $runner->insert($query->getGrammar()->compileInsert($query, $chunk), $bindings);

                if ($pretending) {
                    continue;
                }

                if ($driver === 'sqlite') {
                    // last_insert_rowid() is the id of the chunk's last row.
                    $last = $this->selectId($connection, 'SELECT last_insert_rowid() AS id');
                    array_push($ids, ...range($last - count($chunk) + 1, $last));
                } else {
                    // LAST_INSERT_ID() is the id of the chunk's first row.
                    $first = $this->selectId($connection, 'SELECT LAST_INSERT_ID() AS id');
                    $step ??= $this->selectId($connection, 'SELECT @@auto_increment_increment AS id');
                    foreach (array_keys($chunk) as $i) {
                        $ids[] = $first + $i * $step;
                    }
                }
            }

            return $ids;
        });
    }

    /**
     * Insert rows, or update them when a row with the same $uniqueBy values already exists.
     *
     * The $uniqueBy columns need a primary or unique index. On MySQL and MariaDB any unique
     * index decides what counts as an existing row. Timestamps are filled in like Eloquent does:
     * created_at only for new rows, updated_at for every row.
     *
     * Example:
     * ```
     * Batch::upsert(new User, [
     *     ['email' => 'ali@example.com', 'name' => 'Ali', 'score' => 90],
     *     ['email' => 'sara@example.com', 'name' => 'Sara', 'score' => 75],
     * ], ['email'], ['name', 'score']);
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $values rows of column => value pairs, all with the same columns
     * @param array<int, string>|string $uniqueBy the column(s) that identify an existing row
     * @param array<array-key, mixed>|null $update columns to update on existing rows; null updates every given column
     * @return int number of affected rows, as reported by the database
     *
     * @throws InvalidArgumentException when $uniqueBy or $update is empty or a row is malformed
     */
    public function upsert(Model $table, array $values, $uniqueBy, ?array $update = null): int
    {
        $uniqueBy = array_values((array) $uniqueBy);

        if (!$uniqueBy) {
            throw new InvalidArgumentException('upsert() needs at least one column to match existing rows on.');
        }

        if ($update === []) {
            throw new InvalidArgumentException(
                'upsert() needs columns to update, or null to update every column. '
                . 'To skip existing rows instead, use insertRows() with $insertIgnore.'
            );
        }

        [$columns, $rows] = $this->splitRows($values);

        if (!$rows) {
            return 0;
        }

        foreach ($uniqueBy as $column) {
            if (!in_array($column, $columns, true)) {
                throw new InvalidArgumentException("Every row must contain the \"{$column}\" column used to match existing rows.");
            }
        }

        foreach ($rows as $key => $row) {
            $rows[$key] = array_combine($columns, array_map([$this, 'enumValue'], $row));
        }

        $rows = $this->castRowsToJson($table, $rows);

        $connection = $this->db->connection($this->getConnectionName($table));

        // Keep each statement under the driver's bound parameter limit, counting the timestamps
        // Eloquent adds and the values bound once per statement for computed updates.
        $perRow = count($columns) + ($table->usesTimestamps() ? 2 : 0);
        $perStatement = $update ? count(array_filter(array_keys($update), 'is_string')) : 0;
        $rowsPerQuery = max(1, intdiv(max(1, $this->maxBindings($connection) - $perStatement), $perRow));
        $chunks = array_chunk($rows, $rowsPerQuery);

        $run = function () use ($connection, $table, $chunks, $uniqueBy, $update) {
            $affected = 0;
            foreach ($chunks as $chunk) {
                $query = $table->newQueryWithoutScopes()->setQuery($this->baseQuery($connection, $table));
                $affected += $query->upsert($chunk, $uniqueBy, $update);
            }

            return $affected;
        };

        return count($chunks) > 1 ? $this->transaction($connection, $run) : $run();
    }

    /**
     * Delete many rows, matched on one or more key columns, in as few queries as possible.
     *
     * Models that use SoftDeletes are soft deleted (deleted_at is set, rows already soft deleted
     * are left alone) unless $force is true, like Eloquent's delete() and forceDelete().
     *
     * Example:
     * ```
     * Batch::deleteByKeys(new Score, [
     *     ['org_id' => 1, 'year' => 2025],
     *     ['org_id' => 2, 'year' => 2026],
     * ], ['org_id', 'year']);
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $values rows holding the key values; other columns are ignored
     * @param array<array-key, mixed> $keys the columns a row is matched on
     * @param bool $force delete soft-deleting models for real
     * @return int number of deleted (or soft-deleted) rows
     *
     * @throws InvalidArgumentException when $keys is empty or a row lacks a key value
     */
    public function deleteByKeys(Model $table, array $values, array $keys, bool $force = false): int
    {
        $keys = $this->keyColumns($keys, 'deleteByKeys');

        $matches = [];
        foreach ($values as $row) {
            $this->assertHasConditions($row, $keys);

            $match = [];
            foreach ($keys as $key) {
                $match[$key] = $row[$key];
            }
            $matches[] = $match;
        }

        if (!$matches) {
            return 0;
        }

        $connection = $this->db->connection($this->getConnectionName($table));
        $grammar = $connection->getQueryGrammar();
        $deletedAtColumn = null;
        if (!$force && in_array(SoftDeletes::class, class_uses_recursive($table), true) && method_exists($table, 'getDeletedAtColumn')) {
            $deletedAtColumn = $table->getDeletedAtColumn();
        }

        // SET deleted_at = ?[, updated_at = ?] for soft deletes, DELETE otherwise.
        $prefix = 'DELETE FROM ' . $grammar->wrapTable($table->getTable());
        $prefixBindings = [];
        $suffix = '';

        if (is_string($deletedAtColumn)) {
            $now = Carbon::now()->format($table->getDateFormat());
            $deletedAt = $grammar->wrap($deletedAtColumn);
            $sets = [$deletedAt . ' = ?'];
            $prefixBindings[] = $now;

            if ($table->usesTimestamps() && !is_null($table->getUpdatedAtColumn())) {
                $sets[] = $grammar->wrap($table->getUpdatedAtColumn()) . ' = ?';
                $prefixBindings[] = $now;
            }

            $prefix = 'UPDATE ' . $grammar->wrapTable($table->getTable()) . ' SET ' . implode(', ', $sets);
            $suffix = ' AND ' . $deletedAt . ' IS NULL';
        }

        $rowsPerQuery = max(1, intdiv(max(1, $this->maxBindings($connection) - count($prefixBindings)), count($keys)));
        $statements = [];

        foreach (array_chunk($matches, $rowsPerQuery) as $chunk) {
            [$whereSql, $whereBindings] = $this->compileKeyMatch($grammar, $chunk, $keys);
            $statements[] = [$prefix . ' WHERE (' . $whereSql . ')' . $suffix, array_merge($prefixBindings, $whereBindings)];
        }

        $runner = $this->runner($connection);
        $run = function () use ($runner, $statements) {
            $affected = 0;
            foreach ($statements as [$sql, $bindings]) {
                $affected += $runner->affectingStatement($sql, $bindings);
            }

            return $affected;
        };

        return count($statements) > 1 ? $this->transaction($connection, $run) : $run();
    }

    /**
     * Show the statements Batch would run, without running them or connecting to the database.
     *
     * Example:
     * ```
     * $queries = Batch::pretend(function ($batch) {
     *     $batch->update(new User, [['id' => 1, 'name' => 'Ali']]);
     * });
     * // [['sql' => 'UPDATE `users` SET ... WHERE `id` IN (?)', 'bindings' => [...], 'connection' => 'mysql']]
     * ```
     *
     * The facade, the batch() helper and the HasBatch trait all share this instance, so calls
     * made through any of them inside the callback are recorded too.
     *
     * @param callable $callback receives this Batch instance
     * @return list<RecordedQuery>
     */
    public function pretend(callable $callback): array
    {
        // Nested pretend(): the outer call collects everything.
        if (!is_null($this->pretended)) {
            $callback($this);

            return [];
        }

        $this->pretended = [];

        try {
            $callback($this);

            return $this->pretended;
        } finally {
            $this->pretended = null;
            $this->recorders = [];
        }
    }

    /**
     * The connection statements are sent to: the real one, or a recorder inside pretend().
     */
    private function runner(Connection $connection): Connection
    {
        if (is_null($this->pretended)) {
            return $connection;
        }

        $name = (string) $connection->getName();

        if (!isset($this->recorders[$name])) {
            $this->recorders[$name] = new RecordingConnection($connection, function (string $sql, array $bindings, string $connectionName) {
                $this->pretended[] = ['sql' => $sql, 'bindings' => $bindings, 'connection' => $connectionName];
            });
        }

        return $this->recorders[$name];
    }

    /**
     * Run $work in a transaction on $connection. pretend() records without a transaction.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function transaction(Connection $connection, \Closure $work): mixed
    {
        return is_null($this->pretended) ? $connection->transaction($work) : $work();
    }

    /**
     * A query builder on the model's table that sends its statements through runner().
     */
    private function baseQuery(Connection $connection, Model $table): QueryBuilder
    {
        return (new QueryBuilder($this->runner($connection), $connection->getQueryGrammar(), $connection->getPostProcessor()))
            ->from($table->getTable());
    }

    /**
     * Split rows of column => value pairs into a column list and rows of values in that order.
     *
     * @param array<array-key, mixed> $rows
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function splitRows(array $rows): array
    {
        $columns = null;
        $values = [];

        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row) || !$row) {
                throw new InvalidArgumentException("Row {$i} must be a non-empty array of column => value pairs.");
            }

            $names = [];
            foreach (array_keys($row) as $column) {
                if (!is_string($column)) {
                    throw new InvalidArgumentException("Row {$i} must use column names as keys.");
                }
                $names[] = $column;
            }

            if (is_null($columns)) {
                $columns = $names;
            } elseif (count($row) !== count($columns) || array_diff_key($row, array_flip($columns))) {
                throw new InvalidArgumentException(sprintf(
                    'Row %d has the columns [%s], but row 0 has [%s]. Every row needs the same columns.',
                    $i,
                    implode(', ', array_keys($row)),
                    implode(', ', $columns)
                ));
            }

            $ordered = [];
            foreach ($columns as $column) {
                $ordered[] = $row[$column];
            }
            $values[] = $ordered;
        }

        return [$columns ?? [], $values];
    }

    /**
     * Build and run "UPDATE ... SET col = CASE WHEN ... END" statements.
     *
     * Every value and condition is sent as a bound parameter and every identifier is
     * wrapped by the connection's grammar, so the generated SQL is safe on all drivers.
     *
     * @param Model $model
     * @param list<Entry> $entries
     * @param list<string> $whereColumns condition columns used to limit the rows in the WHERE clause
     * @return int number of affected rows
     */
    private function runCaseUpdate(Model $model, array $entries, array $whereColumns): int
    {
        $connection = $this->db->connection($this->getConnectionName($model));
        $grammar = $connection->getQueryGrammar();

        $updatedAtColumn = null;
        $timestampValue = null;

        if ($model->usesTimestamps()) {
            $updatedAtColumn = $model->getUpdatedAtColumn();
            $timestampValue = Carbon::now()->format($model->getDateFormat());
        }

        $driver = $connection->getDriverName();
        $jsonCasts = $this->jsonCastColumns($model);
        $jsonTypeColumns = $entries ? $this->jsonTypeColumns($connection, $model) : [];
        $limit = $this->maxBindings($connection);
        $chunks = [];
        $chunk = [];
        $chunkCost = 0;

        foreach ($entries as $entry) {
            $compiled = $this->compileEntry($grammar, $driver, $model, $jsonCasts, $jsonTypeColumns, $entry, $updatedAtColumn, $timestampValue);
            $cost = $compiled['cost'] + count($whereColumns);

            if ($chunk && ($chunkCost + $cost > $limit || count($chunk) >= static::MAX_ROWS_PER_UPDATE)) {
                $chunks[] = $chunk;
                $chunk = [];
                $chunkCost = 0;
            }

            $chunk[] = $compiled;
            $chunkCost += $cost;
        }

        if ($chunk) {
            $chunks[] = $chunk;
        }

        $statements = [];
        foreach ($chunks as $chunk) {
            if ($statement = $this->compileUpdateStatement($grammar, $model, $chunk, $whereColumns, $updatedAtColumn, $driver)) {
                $statements[] = $statement;
            }
        }

        $runner = $this->runner($connection);
        $run = function () use ($runner, $statements) {
            $affected = 0;
            foreach ($statements as [$sql, $bindings]) {
                $affected += $runner->update($sql, $bindings);
            }

            return $affected;
        };

        return count($statements) > 1 ? $this->transaction($connection, $run) : $run();
    }

    /**
     * Compile the CASE branches contributed by a single row.
     *
     * @param array<string, true> $jsonCasts columns the model casts to JSON
     * @param array<string, true> $jsonTypeColumns columns of the json type, on PostgreSQL and MySQL
     * @param Entry $entry
     * @return CompiledEntry
     */
    private function compileEntry(Grammar $grammar, string $driver, Model $model, array $jsonCasts, array $jsonTypeColumns, array $entry, ?string $updatedAtColumn, ?string $timestampValue): array
    {
        [$whenSql, $whenBindings] = $this->compileConditions($grammar, $entry['conditions']);

        $cases = [];
        $touch = null;
        $changes = [];
        $changeBindings = [];
        $jsonPaths = []; // column => list of [keys, value]
        $jsonColumns = [];

        foreach ($entry['columns'] as $column => $value) {
            if ($path = JsonPath::parse($column)) {
                $jsonPaths[$path[0]][] = [$path[1], $this->enumValue($value)];
                continue;
            }

            $wrapped = $grammar->wrap($column);
            $value = $this->castToJson($model, $jsonCasts, $column, $value);

            if ($column === $updatedAtColumn) {
                // An explicit non-null updated_at wins over the automatic timestamp.
                if (!is_null($value)) {
                    [$valueSql, $valueBindings] = $this->compileValue($grammar, $value);
                    $touch = ['WHEN ' . $whenSql . ' THEN ' . $valueSql, array_merge($whenBindings, $valueBindings)];
                }
                continue;
            }

            if (is_array($value)) {
                // Increment / decrement
                [$operator, $operand] = $this->arithmetic($value);
                $valueSql = $wrapped . ' ' . $operator . ' ' . $operand;
                $valueBindings = [];
                $changes[] = $wrapped . ' IS NOT NULL';
            } else {
                [$valueSql, $valueBindings] = $this->compileValue($grammar, $value);

                // Null-safe "value actually changes" check, used for the automatic timestamp.
                if (is_null($value)) {
                    $changes[] = $wrapped . ' IS NOT NULL';
                } elseif (isset($jsonTypeColumns[$column])) {
                    // Compare JSON columns as JSON: PostgreSQL's json type has no "<>" operator,
                    // and MySQL treats a JSON document and a string as different values.
                    $changes[] = $driver === 'pgsql'
                        ? 'CAST(' . $wrapped . ' AS jsonb) IS DISTINCT FROM CAST(' . $valueSql . ' AS jsonb)'
                        : 'NOT (' . $wrapped . ' <=> JSON_EXTRACT(' . $valueSql . ', \'$\'))';
                    $changeBindings = array_merge($changeBindings, $valueBindings);
                } else {
                    $changes[] = '(' . $wrapped . ' <> ' . $valueSql . ' OR ' . $wrapped . ' IS NULL)';
                    $changeBindings = array_merge($changeBindings, $valueBindings);
                }
            }

            $cases[$column] = ['WHEN ' . $whenSql . ' THEN ' . $valueSql, array_merge($whenBindings, $valueBindings)];
        }

        foreach ($jsonPaths as $column => $paths) {
            if (array_key_exists($column, $entry['columns'])) {
                throw new InvalidArgumentException("A row can't set the \"{$column}\" column and a JSON path inside it at the same time.");
            }

            [$valueSql, $valueBindings] = JsonPath::compileSet($grammar, $driver, $column, $paths);
            [$changeSql, $changeSqlBindings] = JsonPath::compileChanged($grammar, $driver, $column, [$valueSql, $valueBindings]);

            $changes[] = $changeSql;
            $changeBindings = array_merge($changeBindings, $changeSqlBindings);
            $cases[$column] = ['WHEN ' . $whenSql . ' THEN ' . $valueSql, array_merge($whenBindings, $valueBindings)];
            $jsonColumns[$column] = true;
        }

        if (is_null($touch) && $updatedAtColumn && count($changes)) {
            $touch = [
                'WHEN ' . $whenSql . ' AND (' . implode(' OR ', $changes) . ') THEN ?',
                array_merge($whenBindings, $changeBindings, [$timestampValue]),
            ];
        }

        $cost = $touch ? count($touch[1]) : 0;
        foreach ($cases as [, $bindings]) {
            $cost += count($bindings);
        }

        return [
            'conditions' => $entry['conditions'],
            'cases' => $cases,
            'touch' => $touch,
            'cost' => $cost,
            'json' => $jsonColumns,
        ];
    }

    /**
     * Compile a chunk of compiled rows into one UPDATE statement.
     *
     * @param list<CompiledEntry> $chunk
     * @param list<string> $whereColumns
     * @return Statement|null [sql, bindings], or null when there is nothing to update
     */
    private function compileUpdateStatement(Grammar $grammar, Model $model, array $chunk, array $whereColumns, ?string $updatedAtColumn, string $driver): ?array
    {
        $whens = [];
        $touches = [];
        $readers = []; // condition column => [updated column whose CASE reads it => true]
        $jsonColumns = [];

        foreach ($chunk as $compiled) {
            $jsonColumns += $compiled['json'];

            foreach ($compiled['cases'] as $column => $case) {
                $whens[$column][] = $case;

                foreach (array_keys($compiled['conditions']) as $conditionColumn) {
                    $readers[$conditionColumn][$column] = true;
                }
            }

            if ($compiled['touch']) {
                $touches[] = $compiled['touch'];
            }
        }

        // MySQL evaluates SET assignments left to right and later ones see the new values,
        // so a column used in conditions must be assigned after every CASE that reads it.
        $whens = $this->orderAssignments($whens, $readers, $driver);

        // updated_at goes first, so the change detection runs before any column changes.
        if ($touches) {
            $whens = [$updatedAtColumn => $touches] + $whens;
        }

        if (!$whens) {
            return null;
        }

        $sets = [];
        $bindings = [];

        foreach ($whens as $column => $cases) {
            $wrapped = $grammar->wrap($column);
            // PostgreSQL needs every CASE branch to have the type of the jsonb_set() branches.
            $else = $driver === 'pgsql' && isset($jsonColumns[$column]) ? 'CAST(' . $wrapped . ' AS jsonb)' : $wrapped;
            $sets[] = $wrapped . ' = (CASE ' . implode(' ', array_column($cases, 0)) . ' ELSE ' . $else . ' END)';

            foreach ($cases as [, $caseBindings]) {
                array_push($bindings, ...$caseBindings);
            }
        }

        $wheres = [];
        foreach ($whereColumns as $whereColumn) {
            $ids = [];
            foreach ($chunk as $compiled) {
                [$idSql, $idBindings] = $this->compileValue($grammar, $compiled['conditions'][$whereColumn]);
                $ids[] = $idSql;
                array_push($bindings, ...$idBindings);
            }

            $wheres[] = $grammar->wrap($whereColumn) . ' IN (' . implode(', ', $ids) . ')';
        }

        $sql = 'UPDATE ' . $grammar->wrapTable($model->getTable())
            . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . implode(' AND ', $wheres);

        return [$sql, $bindings];
    }

    /**
     * Order SET assignments so no CASE reads a condition column that was already assigned.
     *
     * @param array<string, list<Statement>> $whens updated column => CASE branches
     * @param array<string, array<string, true>> $readers condition column => [updated column whose CASE reads it => true]
     * @return array<string, list<Statement>>
     */
    private function orderAssignments(array $whens, array $readers, string $driver): array
    {
        $pending = array_keys($whens);
        $ordered = [];

        while ($pending) {
            foreach ($pending as $i => $column) {
                $waitingFor = array_diff(array_keys($readers[$column] ?? []), [$column], $ordered);

                if (!$waitingFor) {
                    $ordered[] = $column;
                    unset($pending[$i]);
                    continue 2;
                }
            }

            // Columns that are conditions of each other: no order works on MySQL.
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                throw new InvalidArgumentException(
                    'MySQL cannot update the condition columns [' . implode(', ', $pending) . '] in one query, '
                    . 'because each is used in the conditions of another. Split the update into separate calls.'
                );
            }

            // Other databases evaluate every assignment against the old row, so order doesn't matter.
            return $whens;
        }

        $result = [];
        foreach ($ordered as $column) {
            $result[$column] = $whens[$column];
        }

        return $result;
    }

    /**
     * Match rows on their key values: "key IN (...)" for one key, OR-ed AND groups for several.
     *
     * @param list<array<string, mixed>> $matches
     * @param list<string> $keys
     * @return Statement
     */
    private function compileKeyMatch(Grammar $grammar, array $matches, array $keys): array
    {
        if (count($keys) > 1) {
            $sql = [];
            $bindings = [];
            foreach ($matches as $match) {
                [$conditionSql, $conditionBindings] = $this->compileConditions($grammar, $match);
                $sql[] = $conditionSql;
                array_push($bindings, ...$conditionBindings);
            }

            return [implode(' OR ', $sql), $bindings];
        }

        $key = $keys[0];
        $wrapped = $grammar->wrap($key);
        $placeholders = [];
        $bindings = [];
        $matchesNull = false;

        foreach ($matches as $match) {
            $value = $match[$key];

            if (is_array($value)) {
                throw new InvalidArgumentException("The value to match \"{$key}\" on must be a single value, not an array.");
            }

            if (is_null($value)) {
                $matchesNull = true;
                continue;
            }

            [$valueSql, $valueBindings] = $this->compileValue($grammar, $value);
            $placeholders[] = $valueSql;
            array_push($bindings, ...$valueBindings);
        }

        $sql = [];
        if ($placeholders) {
            $sql[] = $wrapped . ' IN (' . implode(', ', $placeholders) . ')';
        }
        if ($matchesNull) {
            $sql[] = $wrapped . ' IS NULL';
        }

        return [implode(' OR ', $sql), $bindings];
    }

    /**
     * Compile a set of column => value pairs into an AND-ed condition.
     *
     * @param array<string, mixed> $conditions
     * @return Statement
     */
    private function compileConditions(Grammar $grammar, array $conditions): array
    {
        $sql = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                throw new InvalidArgumentException("The value to match \"{$column}\" on must be a single value, not an array.");
            }

            if (is_null($value)) {
                $sql[] = $grammar->wrap($column) . ' IS NULL';
            } else {
                [$valueSql, $valueBindings] = $this->compileValue($grammar, $value);
                $sql[] = $grammar->wrap($column) . ' = ' . $valueSql;
                array_push($bindings, ...$valueBindings);
            }
        }

        return ['(' . implode(' AND ', $sql) . ')', $bindings];
    }

    /**
     * Compile a value to a placeholder, or to its SQL when it's a DB::raw() expression.
     *
     * @return Statement
     */
    private function compileValue(Grammar $grammar, mixed $value): array
    {
        if (is_null($value)) {
            return ['NULL', []];
        }

        if ($value instanceof Expression) {
            return [(string) $grammar->getValue($value), []];
        }

        return ['?', [$this->enumValue($value)]];
    }

    /**
     * The model's columns of the json type on PostgreSQL and MySQL, looked up once per table.
     *
     * Other databases store JSON as text, which compares fine. pretend() doesn't connect to
     * the database, so it treats no column as json.
     *
     * @return array<string, true>
     */
    private function jsonTypeColumns(Connection $connection, Model $model): array
    {
        $driver = $connection->getDriverName();

        if (!is_null($this->pretended) || !in_array($driver, ['pgsql', 'mysql'], true)) {
            return [];
        }

        $table = $connection->getTablePrefix() . $model->getTable();
        $key = $connection->getName() . '|' . $table;

        if (!isset($this->jsonTypeColumns[$key])) {
            if ($driver === 'pgsql') {
                $columns = $connection->select(
                    "SELECT attname AS name FROM pg_attribute WHERE attrelid = to_regclass(?) AND atttypid = 'json'::regtype AND attnum > 0 AND NOT attisdropped",
                    [$connection->getQueryGrammar()->wrapTable($model->getTable())],
                    false
                );
            } else {
                // A "database.table" name has the prefix on the table part only.
                $parts = explode('.', $model->getTable(), 2);
                $columns = $connection->select(
                    "SELECT column_name AS name FROM information_schema.columns WHERE table_schema = COALESCE(?, DATABASE()) AND table_name = ? AND data_type = 'json'",
                    count($parts) === 2
                        ? [$parts[0], $connection->getTablePrefix() . $parts[1]]
                        : [null, $connection->getTablePrefix() . $parts[0]],
                    false
                );
            }

            $this->jsonTypeColumns[$key] = [];
            foreach ($columns as $column) {
                $this->jsonTypeColumns[$key][(string) $column->name] = true;
            }
        }

        return $this->jsonTypeColumns[$key];
    }

    /**
     * The columns the model casts to JSON (array, json, collection, AsArrayObject, ...).
     *
     * @return array<string, true>
     */
    private function jsonCastColumns(Model $model): array
    {
        $columns = [];
        foreach ($model->getCasts() as $column => $cast) {
            $type = strtolower(trim((string) $cast));

            if (in_array($type, self::JSON_CASTS, true)) {
                $columns[$column] = true;
                continue;
            }

            foreach (self::JSON_CAST_CLASSES as $class) {
                if ((string) $cast === $class || str_starts_with((string) $cast, $class . ':')) {
                    $columns[$column] = true;
                }
            }
        }

        return $columns;
    }

    /**
     * Validate rows of values for insert(), and turn them into column => value rows with enums,
     * JSON casts and timestamps applied.
     *
     * @param array<int, string> $columns
     * @param array<array-key, mixed> $values
     * @return list<array<string, mixed>>
     */
    private function prepareInsertRows(Model $table, array $columns, array $values): array
    {
        $rows = [];
        foreach (array_values($values) as $i => $row) {
            if (!is_array($row) || count($row) !== count($columns) || !count($columns)) {
                throw new InvalidArgumentException(sprintf(
                    'Row %d has %d values, but there are %d columns.',
                    $i,
                    is_array($row) ? count($row) : 0,
                    count($columns)
                ));
            }

            $rows[] = array_combine($columns, array_map([$this, 'enumValue'], array_values($row)));
        }

        $rows = $this->castRowsToJson($table, $rows);

        if ($table->usesTimestamps()) {
            $now = Carbon::now()->format($table->getDateFormat());

            foreach ([$table->getCreatedAtColumn(), $table->getUpdatedAtColumn()] as $timestampColumn) {
                if (is_null($timestampColumn) || in_array($timestampColumn, $columns)) {
                    continue;
                }

                foreach ($rows as $key => $row) {
                    $rows[$key][$timestampColumn] = $now;
                }
            }
        }

        return $rows;
    }

    /**
     * Rows per INSERT: $batchSize, kept under the driver's bound parameter limit.
     *
     * @return positive-int
     */
    private function rowsPerInsert(Connection $connection, int $batchSize, int $columnCount): int
    {
        return max(1, min($batchSize, intdiv($this->maxBindings($connection), max(1, $columnCount))));
    }

    /**
     * Run a SELECT that returns one integer, aliased "id", on the write connection.
     */
    private function selectId(Connection $connection, string $sql): int
    {
        $row = $connection->selectOne($sql, [], false);

        return $this->toId(is_null($row) ? null : ((array) $row)['id']);
    }

    /**
     * An auto-increment id as returned by the database.
     */
    private function toId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('The database did not return an auto-increment id.');
    }

    /**
     * Encode the values of JSON-cast columns in rows of column => value pairs.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function castRowsToJson(Model $model, array $rows): array
    {
        $jsonCasts = $this->jsonCastColumns($model);

        if ($jsonCasts) {
            foreach ($rows as $i => $row) {
                foreach (array_intersect_key($row, $jsonCasts) as $column => $value) {
                    $rows[$i][$column] = $this->castToJson($model, $jsonCasts, $column, $value);
                }
            }
        }

        return $rows;
    }

    /**
     * Encode an array or object for a JSON-cast column the way the model itself would store it.
     *
     * @param array<string, true> $jsonCasts
     */
    private function castToJson(Model $model, array $jsonCasts, string $column, mixed $value): mixed
    {
        if (!isset($jsonCasts[$column])) {
            return $value;
        }

        if (!is_array($value) && (!is_object($value) || $value instanceof Expression || $value instanceof \UnitEnum || $value instanceof \DateTimeInterface)) {
            return $value;
        }

        $scratch = $model->newInstance();
        $scratch->setAttribute($column, $value);

        return $scratch->getAttributes()[$column];
    }

    /**
     * Store backed enums by value and other enums by name, on every Laravel version.
     */
    private function enumValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    /**
     * Validate an increment / decrement array and return its operator and numeric operand as SQL.
     *
     * @param array<mixed> $value
     * @return array{0: string, 1: string}
     */
    private function arithmetic(array $value): array
    {
        // If array has two values
        if (!array_key_exists(0, $value) || !array_key_exists(1, $value)) {
            throw new InvalidArgumentException('Increment/Decrement array needs to have 2 values, a math operator (+, -, *, /, %) and a number');
        }
        // Check first value
        if (gettype($value[0]) != 'string' || !in_array($value[0], ['+', '-', '*', '/', '%'])) {
            throw new InvalidArgumentException('First value in Increment/Decrement array needs to be a string and a math operator (+, -, *, /, %)');
        }
        // Check second value
        if (!is_numeric($value[1]) || !is_finite((float) $value[1])) {
            throw new InvalidArgumentException('Second value in Increment/Decrement array needs to be numeric');
        }

        $number = $value[1] + 0;

        // Parenthesised so a negative operand can never form a "--" comment.
        return [$value[0], '(' . (is_int($number) ? (string) $number : var_export($number, true)) . ')'];
    }

    /**
     * The $raw flag was removed in 3.0. Fail loudly instead of silently storing SQL as text.
     *
     * @param array<int, mixed> $arguments
     */
    private function rejectRawArgument(array $arguments, int $position): void
    {
        if (($arguments[$position] ?? false) === true) {
            throw new InvalidArgumentException(
                'The $raw argument was removed in laravel-batch 3.0. Wrap raw SQL values in DB::raw() instead.'
            );
        }
    }

    /**
     * Make sure every row is an array of column => value pairs that carries the columns used to match it.
     *
     * @param list<string> $columns
     * @phpstan-assert array<string, mixed> $row
     */
    private function assertHasConditions(mixed $row, array $columns): void
    {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Every row must be an array of column => value pairs.');
        }

        $this->assertColumnNames($row);

        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                throw new InvalidArgumentException("Every row must contain a value for the \"{$column}\" column.");
            }
        }
    }

    /**
     * Make sure an array is keyed by column names.
     *
     * @param array<mixed> $row
     * @phpstan-assert array<string, mixed> $row
     */
    private function assertColumnNames(array $row): void
    {
        foreach (array_keys($row) as $column) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Rows must use column names as keys.');
            }
        }
    }

    /**
     * Validate key columns and return them as a list without duplicates.
     *
     * @param array<array-key, mixed> $keys
     * @return list<string>
     */
    private function keyColumns(array $keys, string $method): array
    {
        $columns = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('Key columns must be given as non-empty strings.');
            }
            $columns[$key] = $key;
        }

        if (!$columns) {
            throw new InvalidArgumentException("{$method}() needs at least one key column.");
        }

        return array_values($columns);
    }

    /**
     * Get the maximum number of bound parameters to send in one query.
     */
    private function maxBindings(Connection $connection): int
    {
        return static::MAX_BINDINGS[$connection->getDriverName()] ?? static::DEFAULT_MAX_BINDINGS;
    }

    /**
     * Get connection name.
     *
     * @param Model $model
     * @return string|null
     * @author Ibrahim Sakr <ebrahimes@gmail.com>
     */
    private function getConnectionName(Model $model)
    {
        if (!is_null($cn = $model->getConnectionName())) {
            return $cn;
        }

        return $model->getConnection()->getName();
    }
}
