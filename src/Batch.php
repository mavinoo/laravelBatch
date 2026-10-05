<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Casts;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Mavinoo\Batch\Support\FileSplitter;
use Mavinoo\Batch\Support\JsonPath;
use Mavinoo\Batch\Support\RawSql;
use Mavinoo\Batch\Support\RecordingConnection;
use Mavinoo\Batch\Support\RowReader;
use Mavinoo\Batch\Support\RowWriter;

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
     * Column of sync()'s temporary key table that numbers the chunks.
     */
    private const SYNC_CHUNK_COLUMN = 'batch_sync_chunk';

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
     * Import rows from a CSV / TSV / JSON Lines file, an open stream or any iterable, a chunk at a
     * time, so sources of any size are imported with constant memory.
     *
     * Example:
     * ```
     * Batch::import(new User, storage_path('users.csv'), [
     *     'mode' => 'upsert',
     *     'uniqueBy' => ['email'],
     *     'map' => ['E-mail' => 'email', 'Full name' => 'name'],
     *     'transform' => fn (array $row) => $row['email'] ? $row : null, // null skips the row
     * ]);
     * // ['totalRows' => 25000, 'skipped' => 12]
     * ```
     *
     * Options:
     * - mode: "insert" (default), "insertIgnore" or "upsert"
     * - uniqueBy, update: the upsert() arguments, for mode "upsert"
     * - chunk: rows per write, default 1000
     * - atomic: run the whole import in one transaction (default true); false commits every chunk
     * - format: "csv", "tsv" or "jsonl"; by default taken from the file extension (".gz" is read too)
     * - header: whether the first CSV row names the columns (default true, false when "columns" is given)
     * - columns: column names for a CSV file, in field order
     * - delimiter, enclosure: CSV characters, default "," (tab for tsv) and '"'
     * - map: [source column => table column]; only the mapped columns are imported
     * - nullValues: strings stored as null, e.g. ['', 'NULL']
     * - rules: Laravel validation rules, checked after map and nullValues; failing rows are skipped
     *   and their messages returned in "errors", keyed by row number
     * - transform: fn (array $row, int $number): ?array, applied to every row; null skips it
     * - onChunk: fn (int $written): void, called after every chunk
     * - onError: fn (InvalidArgumentException $e, int $number): void; malformed rows are passed to
     *   it and skipped instead of stopping the import
     * - fast: load with COPY (PostgreSQL) or LOAD DATA LOCAL INFILE (MySQL, MariaDB); insert mode only,
     *   chunk defaults to 10000. Other databases import as usual.
     *
     * @param Model $table
     * @param mixed $source a file path, an open stream resource, or an iterable of rows (arrays, models or objects)
     * @param array<string, mixed> $options
     * @return array{totalRows: int, skipped: int, errors: array<int, array<string, array<int, string>>>}
     *         rows written, rows skipped, and the validation errors of skipped rows by row number
     *
     * @throws InvalidArgumentException for an invalid option, an unreadable source, or a malformed row without onError
     */
    public function import(Model $table, mixed $source, array $options = []): array
    {
        $this->assertKnownOptions('import', $options, [
            'mode', 'uniqueBy', 'update', 'chunk', 'atomic', 'format', 'header', 'columns', 'delimiter',
            'enclosure', 'map', 'nullValues', 'rules', 'transform', 'onChunk', 'onError', 'fast',
        ]);

        $fast = (bool) ($options['fast'] ?? false);

        $mode = $options['mode'] ?? 'insert';
        if (!in_array($mode, ['insert', 'insertIgnore', 'upsert'], true)) {
            throw new InvalidArgumentException('The import "mode" must be "insert", "insertIgnore" or "upsert".');
        }

        $uniqueBy = $options['uniqueBy'] ?? null;
        if ($mode === 'upsert' && !is_string($uniqueBy) && !is_array($uniqueBy)) {
            throw new InvalidArgumentException('An import in "upsert" mode needs the "uniqueBy" option.');
        }

        $update = $options['update'] ?? null;
        if (!is_null($update) && !is_array($update)) {
            throw new InvalidArgumentException('The import "update" option must be an array of columns.');
        }

        if ($fast && $mode !== 'insert') {
            throw new InvalidArgumentException('A "fast" import can only insert; use the default "insert" mode.');
        }

        $chunkSize = $options['chunk'] ?? ($fast ? 10000 : 1000);
        if (!is_int($chunkSize) || $chunkSize < 1) {
            throw new InvalidArgumentException('The import "chunk" option must be a positive integer.');
        }

        $map = null;
        if (isset($options['map'])) {
            if (!is_array($options['map']) || !$options['map']) {
                throw new InvalidArgumentException('The import "map" option must be a non-empty array of source column => table column.');
            }

            $map = [];
            foreach ($options['map'] as $from => $to) {
                if (!is_string($to) || $to === '') {
                    throw new InvalidArgumentException('The import "map" option must map every source column to a table column name.');
                }
                $map[$from] = $to;
            }
        }

        $nullValues = $options['nullValues'] ?? [];
        if (!is_array($nullValues)) {
            throw new InvalidArgumentException('The import "nullValues" option must be an array of strings.');
        }

        $rules = $options['rules'] ?? null;
        if (!is_null($rules) && (!is_array($rules) || !$rules)) {
            throw new InvalidArgumentException('The import "rules" option must be a non-empty array of Laravel validation rules.');
        }

        $transform = $this->callableOption($options, 'transform');
        $onChunk = $this->callableOption($options, 'onChunk');
        $onError = $this->callableOption($options, 'onError');
        $validate = null;
        if ($rules) {
            $factory = Container::getInstance()->make(ValidationFactory::class);
            $validate = fn (array $row) => $factory->make($row, $rules);
        }

        $reader = RowReader::read($source, [
            'format' => $this->stringOption($options, 'format'),
            'header' => isset($options['header']) ? (bool) $options['header'] : null,
            'columns' => isset($options['columns']) ? $this->columnList($options['columns']) : null,
            'delimiter' => $this->stringOption($options, 'delimiter'),
            'enclosure' => $this->stringOption($options, 'enclosure'),
        ]);

        $connection = $this->db->connection($this->getConnectionName($table));

        $write = function (array $rows) use ($table, $mode, $uniqueBy, $update, $chunkSize, $fast, $connection): void {
            if ($fast && in_array($connection->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
                $this->bulkLoad($connection, $table, $rows);
            } elseif ($mode === 'upsert') {
                /** @var array<int, string>|string $uniqueBy */
                $this->upsert($table, $rows, $uniqueBy, $update);
            } else {
                $this->insertRows($table, $rows, $chunkSize, $mode === 'insertIgnore');
            }
        };

        $run = function () use ($reader, $write, $chunkSize, $map, $nullValues, $validate, $transform, $onChunk, $onError): array {
            $written = 0;
            $skipped = 0;
            $errors = [];
            $chunk = [];
            $columns = null;

            foreach ($reader as [$number, $row, $error]) {
                if (!is_null($row) && $validate) {
                    $row = $this->mapImportRow($row, $map, $nullValues);

                    if (is_string($row)) {
                        [$row, $error] = [null, $row];
                    } else {
                        $validation = $validate($row);

                        if ($validation->fails()) {
                            $errors[$number] = $validation->errors()->toArray();
                            $skipped++;
                            continue;
                        }

                        [$row, $error] = $this->importRow($row, null, [], $transform, $number, $columns);
                    }
                } elseif (!is_null($row)) {
                    [$row, $error] = $this->importRow($row, $map, $nullValues, $transform, $number, $columns);

                    if (is_null($row) && is_null($error)) {
                        $skipped++; // skipped by transform
                        continue;
                    }
                }

                if (is_null($row)) {
                    $exception = new InvalidArgumentException("Row {$number} can't be imported: {$error}.");

                    if (is_null($onError)) {
                        throw $exception;
                    }

                    $onError($exception, $number);
                    $skipped++;
                    continue;
                }

                $columns ??= array_keys($row);
                $chunk[] = $row;

                if (count($chunk) >= $chunkSize) {
                    $write($chunk);
                    $written += count($chunk);
                    $chunk = [];

                    if ($onChunk) {
                        $onChunk($written);
                    }
                }
            }

            if ($chunk) {
                $write($chunk);
                $written += count($chunk);

                if ($onChunk) {
                    $onChunk($written);
                }
            }

            return ['totalRows' => $written, 'skipped' => $skipped, 'errors' => $errors];
        };

        return ($options['atomic'] ?? true) ? $this->transaction($connection, $run) : $run();
    }

    /**
     * Export the rows a query matches to a CSV, TSV or JSON Lines file (also gzipped), a chunk at
     * a time, so tables of any size are exported with constant memory.
     *
     * Rows are read in primary key order. With "maxRows" the export is split into numbered parts.
     *
     * Example:
     * ```
     * Batch::export(User::where('active', true), storage_path('users.csv'), ['columns' => ['id', 'email']]);
     * // ['totalRows' => 25000, 'files' => ['.../users.csv']]
     * User::where('active', true)->exportTo(storage_path('users.csv'));   // as a builder macro
     * ```
     *
     * Options:
     * - columns: the columns to export; default the query's select, or every column
     * - format: "csv", "tsv" or "jsonl"; by default taken from the file extension
     * - header: write the column names first in CSV files (default true)
     * - delimiter, enclosure: CSV characters, default "," (tab for tsv) and '"'
     * - maxRows: rows per file; the parts are named users-001.csv, users-002.csv, ...
     * - chunk: rows per query, default 1000
     * - overwrite: replace existing files (default false: an existing file throws)
     * - transform: fn (array $row): ?array, applied to every row; null leaves the row out
     * - onChunk: fn (int $written): void, called after every chunk
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query a query without limit or offset; DB::table() queries use the "id" key
     * @param array<string, mixed> $options
     * @return array{totalRows: int, files: list<string>}
     *
     * @throws InvalidArgumentException for an invalid option, an existing file or a query with limit / offset
     */
    public function export(EloquentBuilder|QueryBuilder $query, string $path, array $options = []): array
    {
        $this->assertKnownOptions('export', $options, [
            'columns', 'format', 'header', 'delimiter', 'enclosure', 'maxRows', 'chunk', 'overwrite', 'transform', 'onChunk',
        ]);

        $chunk = $options['chunk'] ?? 1000;
        if (!is_int($chunk) || $chunk < 1) {
            throw new InvalidArgumentException('The export "chunk" option must be a positive integer.');
        }

        $maxRows = $options['maxRows'] ?? null;
        if (!is_null($maxRows) && !is_int($maxRows)) {
            throw new InvalidArgumentException('The export "maxRows" option must be a positive integer.');
        }

        $transform = $this->callableOption($options, 'transform');
        $onChunk = $this->callableOption($options, 'onChunk');
        $connection = $this->connectionOf($query);
        $key = $this->keyOf($query);
        $qualifiedKey = $this->tableOf($query) . '.' . $key;

        $base = $query instanceof EloquentBuilder ? $query->toBase() : clone $query;
        if (!is_null($base->limit) || !is_null($base->offset)) {
            throw new InvalidArgumentException('Exported queries can\'t have a limit or offset; the rows are read a chunk at a time.');
        }

        // The key is needed to read the next chunk; leave it out of the file unless it was asked for.
        $columns = isset($options['columns']) ? $this->columnList($options['columns']) : null;
        $dropKey = false;
        if ($columns) {
            $base->select($columns);
            $dropKey = !in_array($key, $columns, true) && !in_array($qualifiedKey, $columns, true);
        } elseif (is_null($base->columns)) {
            $base->select($this->tableOf($query) . '.*');
        }
        if ($dropKey) {
            $base->addSelect($qualifiedKey);
        }

        $writer = new RowWriter(
            $path,
            $this->stringOption($options, 'format'),
            $maxRows,
            isset($options['header']) ? (bool) $options['header'] : null,
            $this->stringOption($options, 'delimiter'),
            $this->stringOption($options, 'enclosure') ?? '"',
            (bool) ($options['overwrite'] ?? false),
            $connection->getQueryGrammar()->getDateFormat(),
        );

        $written = 0;
        $last = null;

        try {
            while (true) {
                $select = (clone $base)->reorder()->orderBy($qualifiedKey)->limit($chunk);
                if (!is_null($last)) {
                    $select->where($qualifiedKey, '>', $last);
                }

                if (!is_null($this->pretended)) {
                    $this->pretended[] = [
                        'sql' => $select->toSql(),
                        'bindings' => $connection->prepareBindings($select->getBindings()),
                        'connection' => (string) $connection->getName(),
                    ];

                    return ['totalRows' => 0, 'files' => []];
                }

                $rows = $select->get()->all();

                foreach ($rows as $row) {
                    $row = (array) $row;
                    $last = $row[$key] ?? null;

                    if ($dropKey) {
                        unset($row[$key]);
                    }

                    if ($transform) {
                        $row = $transform($row);
                        if (is_null($row)) {
                            continue;
                        }
                        if (!is_array($row)) {
                            throw new InvalidArgumentException('The export "transform" option must return an array or null.');
                        }
                    }

                    /** @var array<string, mixed> $row */
                    $writer->write($row);
                    $written++;
                }

                if ($onChunk && $rows) {
                    $onChunk($written);
                }

                if (count($rows) < $chunk || is_null($last)) {
                    break;
                }
            }
        } finally {
            $files = $writer->close();
        }

        return ['totalRows' => $written, 'files' => $files];
    }

    /**
     * Split a large file into parts of at most $lines records and / or $bytes bytes, without
     * cutting a record in half. CSV fields that span several lines stay one record, and the
     * header row is repeated in every part.
     *
     * Example:
     * ```
     * Batch::splitFile(storage_path('users.csv'), lines: 50000);
     * // ['.../users-001.csv', '.../users-002.csv', ...]
     * ```
     *
     * Options:
     * - header: repeat the first line in every part (default true for csv / tsv files)
     * - format: "csv", "tsv", "jsonl" or "lines"; by default taken from the file extension
     * - directory: where the parts go, default the file's own directory
     * - overwrite: replace existing parts (default false: an existing part throws)
     * - enclosure: the CSV quote character, default '"'
     *
     * @param array<string, mixed> $options
     * @return list<string> paths of the parts, in order
     *
     * @throws InvalidArgumentException for invalid limits, an unreadable file or an existing part
     */
    public function splitFile(string $path, ?int $lines = null, ?int $bytes = null, array $options = []): array
    {
        $this->assertKnownOptions('splitFile', $options, ['header', 'format', 'directory', 'overwrite', 'enclosure']);

        return FileSplitter::split($path, $lines, $bytes, [
            'header' => isset($options['header']) ? (bool) $options['header'] : null,
            'format' => $this->stringOption($options, 'format'),
            'directory' => $this->stringOption($options, 'directory'),
            'overwrite' => (bool) ($options['overwrite'] ?? false),
            'enclosure' => $this->stringOption($options, 'enclosure'),
        ]);
    }

    /**
     * Delete the rows a query matches, $chunk rows at a time, so a large delete doesn't lock the
     * table or fill the undo log. Each chunk is its own statement.
     *
     * Rows are walked in primary key order, which works on every database. Models that use
     * SoftDeletes are soft deleted unless $force is true. Model events are not fired.
     *
     * Example:
     * ```
     * Batch::deleteInChunks(Log::where('created_at', '<', now()->subYear()), chunk: 10000, sleepMs: 100);
     * Log::where('created_at', '<', now()->subYear())->deleteInChunks(10000); // the same, as a builder macro
     * ```
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query a query without limit or offset; DB::table() queries use the "id" key
     * @param int $chunk rows per statement
     * @param int $sleepMs pause between chunks, to go easy on the server and its replicas
     * @param bool $force delete soft-deleting models for real
     * @param (callable(int): void)|null $onChunk called after every chunk with the number of rows deleted so far
     * @return int number of deleted (or soft-deleted) rows
     *
     * @throws InvalidArgumentException for a query with limit / offset or an invalid chunk size
     */
    public function deleteInChunks(EloquentBuilder|QueryBuilder $query, int $chunk = 1000, int $sleepMs = 0, bool $force = false, ?callable $onChunk = null): int
    {
        return $this->walkInChunks($query, $chunk, $sleepMs, $onChunk, false, function (array $keys, EloquentBuilder|QueryBuilder $matching, string $qualifiedKey) use ($force): int {
            $matching->whereIn($qualifiedKey, $keys);

            if ($matching instanceof QueryBuilder) {
                return $matching->delete();
            }

            $deleted = $force ? $matching->forceDelete() : $matching->delete();

            return is_int($deleted) ? $deleted : 0;
        });
    }

    /**
     * Update the rows a query matches with the same values, $chunk rows at a time.
     *
     * Rows are walked in primary key order, so a row whose update stops it matching the query
     * is never visited twice and the loop always ends. Eloquent queries set updated_at.
     *
     * Example:
     * ```
     * Batch::updateInChunks(User::where('active', false), ['status' => 'archived'], chunk: 5000);
     * User::where('active', false)->updateInChunks(['status' => 'archived'], 5000); // as a builder macro
     * ```
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query a query without limit or offset; DB::table() queries use the "id" key
     * @param array<array-key, mixed> $values column => value, DB::raw() and "column->key" JSON paths included
     * @param int $chunk rows per statement
     * @param int $sleepMs pause between chunks
     * @param (callable(int): void)|null $onChunk called after every chunk with the number of rows updated so far
     * @return int number of updated rows
     *
     * @throws InvalidArgumentException for empty values, a query with limit / offset or an invalid chunk size
     */
    public function updateInChunks(EloquentBuilder|QueryBuilder $query, array $values, int $chunk = 1000, int $sleepMs = 0, ?callable $onChunk = null): int
    {
        if (!$values) {
            throw new InvalidArgumentException('updateInChunks() needs at least one column to update.');
        }

        $this->assertColumnNames($values);

        $values = array_map([$this, 'enumValue'], $values);
        if ($query instanceof EloquentBuilder) {
            $model = $query->getModel();
            $jsonCasts = $this->jsonCastColumns($model);
            foreach ($values as $column => $value) {
                $values[$column] = $this->castToJson($model, $jsonCasts, $column, $value);
            }
        }

        return $this->walkInChunks($query, $chunk, $sleepMs, $onChunk, false, function (array $keys, EloquentBuilder|QueryBuilder $matching, string $qualifiedKey) use ($values): int {
            return $matching->whereIn($qualifiedKey, $keys)->update($values);
        });
    }

    /**
     * Move the rows a query matches into another table, $chunk rows at a time.
     *
     * Each chunk is locked, copied with INSERT ... SELECT and deleted in one transaction, so no
     * row is lost or copied twice. Columns default to the ones both tables have.
     *
     * When the target is a model on another connection, rows are copied with INSERT IGNORE first
     * and deleted from the source after. That can't be one transaction, but running the archive
     * again after a failure is safe: the target needs the source's primary key column, unique.
     *
     * Example:
     * ```
     * Batch::archive(Order::where('created_at', '<', '2020-01-01'), 'orders_archive', chunk: 5000);
     * Order::where('created_at', '<', '2020-01-01')->archiveTo('orders_archive'); // as a builder macro
     * ```
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query a query without limit or offset; DB::table() queries use the "id" key
     * @param Model|string $target the target model, or a table on the query's connection
     * @param int $chunk rows per transaction
     * @param int $sleepMs pause between chunks
     * @param bool $delete delete the rows from the source; false only copies them
     * @param list<string>|null $columns columns to copy; null copies every column both tables have
     * @param (callable(int): void)|null $onChunk called after every chunk with the number of rows archived so far
     * @return int number of archived rows
     *
     * @throws InvalidArgumentException for a query with limit / offset, an invalid chunk size or no shared columns
     */
    public function archive(EloquentBuilder|QueryBuilder $query, Model|string $target, int $chunk = 1000, int $sleepMs = 0, bool $delete = true, ?array $columns = null, ?callable $onChunk = null): int
    {
        $sourceConnection = $this->connectionOf($query);
        $sourceTable = $this->tableOf($query);

        if ($target instanceof Model) {
            $targetConnection = $this->db->connection($this->getConnectionName($target));
            $targetTable = $target->getTable();
        } else {
            if ($target === '') {
                throw new InvalidArgumentException('archive() needs a target table.');
            }
            $targetConnection = $sourceConnection;
            $targetTable = $target;
        }

        $sameConnection = $targetConnection->getName() === $sourceConnection->getName();
        $key = $this->keyOf($query);

        return $this->walkInChunks($query, $chunk, $sleepMs, $onChunk, true, function (array $keys) use (&$columns, $sourceConnection, $sourceTable, $targetConnection, $targetTable, $sameConnection, $key, $delete): int {
            $columns ??= $this->sharedColumns($sourceConnection, $sourceTable, $targetConnection, $targetTable);
            $rows = $sourceConnection->table($sourceTable)->select($columns)->whereIn($key, $keys);

            if ($sameConnection) {
                $copied = $targetConnection->table($targetTable)->insertUsing($columns, $rows);
            } else {
                if (!in_array($key, $columns, true)) {
                    throw new InvalidArgumentException("archive() to another connection needs to copy the \"{$key}\" key column.");
                }

                $records = array_map(fn ($row) => (array) $row, $rows->get()->all());
                $perQuery = $this->rowsPerInsert($targetConnection, 1000, count($columns));
                $targetConnection->transaction(function () use ($targetConnection, $targetTable, $records, $perQuery) {
                    foreach (array_chunk($records, $perQuery) as $part) {
                        $targetConnection->table($targetTable)->insertOrIgnore($part);
                    }
                });
                $copied = count($records);
            }

            if ($delete) {
                $sourceConnection->table($sourceTable)->whereIn($key, $keys)->delete();
            }

            return $copied;
        });
    }

    /**
     * A row's key values as a string, the same for values the database returns and values given.
     *
     * @param array<array-key, mixed> $row
     * @param list<string> $keys
     */
    private function keyString(Connection $connection, array $row, array $keys): string
    {
        $values = [];
        foreach ($keys as $key) {
            $value = $this->enumValue($row[$key] ?? null);

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format($connection->getQueryGrammar()->getDateFormat());
            } elseif (is_bool($value)) {
                $value = (int) $value;
            }

            $values[] = is_null($value) ? null : (is_scalar($value) ? (string) $value : serialize($value));
        }

        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    /**
     * Turn the counter (['+'], ['-'], ['max'], ['min']) and onlyIf parts of an upsert into the raw
     * SET expressions Laravel's upsert() takes, for the connection's database.
     *
     * @param list<string> $columns the columns of the rows
     * @param array<array-key, mixed>|null $update
     * @param mixed $onlyIf
     * @return array<array-key, mixed>|null
     */
    private function upsertAssignments(Connection $connection, Model $table, array $columns, ?array $update, mixed $onlyIf): ?array
    {
        if (!is_array($onlyIf)) {
            throw new InvalidArgumentException('The upsert "onlyIf" option must be an array of column => operator.');
        }

        if (!$onlyIf && !array_filter($update ?? [], 'is_array')) {
            return $update; // nothing special: let Laravel compile it
        }

        $grammar = $connection->getQueryGrammar();
        $driver = $connection->getDriverName();
        $update ??= $columns;

        // Eloquent would add updated_at as a plain column, and fills it in every row; add it here so
        // onlyIf covers it too.
        $updatedAt = $table->usesTimestamps() ? $table->getUpdatedAtColumn() : null;
        if ($updatedAt) {
            $columns[] = $updatedAt;
            if (!array_key_exists($updatedAt, $update) && !in_array($updatedAt, $update, true)) {
                $update[] = $updatedAt;
            }
        }

        $tableName = $table->getTable();
        $existing = fn (string $column): string => $grammar->wrap($tableName . '.' . $column);
        $incoming = function (string $column) use ($grammar, $driver, $connection, $columns): string {
            if (!in_array($column, $columns, true)) {
                throw new InvalidArgumentException("The upsert uses the new value of \"{$column}\", but the rows don't have that column.");
            }

            // The same references Laravel's grammars use for the new row (never table-prefixed).
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                return $grammar->wrap('excluded') . '.' . $grammar->wrap($column);
            }

            return $connection->getConfig('use_upsert_alias')
                ? $grammar->wrap('laravel_upsert_alias') . '.' . $grammar->wrap($column)
                : 'VALUES(' . $grammar->wrap($column) . ')';
        };

        $assignments = [];
        foreach ($update as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('upsert() update columns must be column names.');
                }
                $assignments[$value] = $incoming($value);
            } elseif ($value instanceof Expression) {
                $assignments[$key] = (string) $grammar->getValue($value);
            } elseif (is_array($value) && count($value) === 1 && in_array($value[0] ?? null, ['+', '-', 'max', 'min'], true)) {
                $old = $existing($key);
                $new = $incoming($key);
                $assignments[$key] = match ($value[0]) {
                    '+' => "COALESCE({$old}, 0) + {$new}",
                    '-' => "COALESCE({$old}, 0) - {$new}",
                    'max' => "CASE WHEN {$old} IS NULL OR {$new} > {$old} THEN {$new} ELSE {$old} END",
                    'min' => "CASE WHEN {$old} IS NULL OR {$new} < {$old} THEN {$new} ELSE {$old} END",
                };
            } else {
                throw new InvalidArgumentException(
                    "Invalid upsert update for \"{$key}\": use ['+'], ['-'], ['max'], ['min'] or DB::raw()."
                );
            }
        }

        if ($onlyIf) {
            $conditions = [];
            foreach ($onlyIf as $column => $operator) {
                if (!is_string($column) || !in_array($operator, ['>', '>=', '<', '<=', '<>', '!='], true)) {
                    throw new InvalidArgumentException('The upsert "onlyIf" option must map columns to >, >=, <, <=, <> or !=.');
                }
                $conditions[] = '(' . $existing($column) . ' IS NULL OR ' . $incoming($column) . ' ' . $operator . ' ' . $existing($column) . ')';
            }
            $condition = implode(' AND ', $conditions);

            // MySQL assigns columns left to right and later ones see the new values, so the
            // columns the condition reads are assigned last. Two of them can't both be updated.
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $read = array_intersect_key($assignments, $onlyIf);
                if (count($read) > 1) {
                    throw new InvalidArgumentException(
                        'MySQL cannot update more than one "onlyIf" column in an upsert; leave all but one out of $update.'
                    );
                }
                $assignments = array_diff_key($assignments, $read) + $read;
            }

            foreach ($assignments as $column => $sql) {
                $assignments[$column] = "CASE WHEN {$condition} THEN {$sql} ELSE {$existing($column)} END";
            }
        }

        return array_map(fn (string $sql) => new RawSql($sql), $assignments);
    }

    /**
     * Upsert rows and return them as they are stored afterwards, in the order of $values.
     *
     * The rows are read back by their $uniqueBy values in the same transaction, so the result is
     * the same on every database: inserted rows, updated rows, and rows an onlyIf condition left
     * alone. Inside pretend() nothing runs, so it returns [].
     *
     * Example:
     * ```
     * $users = Batch::upsertReturning(new User, $rows, ['email'], ['name'], ['id', 'email']);
     * // [['id' => 7, 'email' => 'ali@example.com'], ['id' => 102, 'email' => 'sara@example.com']]
     * ```
     *
     * @param array<array-key, mixed> $values rows of column => value pairs, all with the same columns
     * @param array<int, string>|string $uniqueBy the column(s) that identify a row
     * @param array<array-key, mixed>|null $update see upsert()
     * @param list<string> $returning columns to return, default every column
     * @param array<string, mixed> $options see upsert()
     * @return list<array<string, mixed>>
     */
    public function upsertReturning(Model $table, array $values, $uniqueBy, ?array $update = null, array $returning = ['*'], array $options = []): array
    {
        $keys = $this->keyColumns((array) $uniqueBy, 'upsertReturning');
        $connection = $this->db->connection($this->getConnectionName($table));

        return $this->transaction($connection, function () use ($table, $values, $keys, $update, $returning, $options, $connection): array {
            $this->upsert($table, $values, $keys, $update, $options);

            if (!is_null($this->pretended)) {
                return [];
            }

            $matches = [];
            foreach ($values as $row) {
                /** @var array<string, mixed> $row */
                $match = array_map([$this, 'enumValue'], array_intersect_key($row, array_flip($keys)));
                $matches[$this->keyString($connection, $match, $keys)] = $match;
            }

            $select = $returning === ['*'] ? ['*'] : array_values(array_unique(array_merge($returning, $keys)));
            $found = [];
            $perQuery = max(1, intdiv($this->maxBindings($connection), count($keys)));

            foreach (array_chunk($matches, $perQuery) as $chunk) {
                $query = $connection->table($table->getTable())->select($select)->where(function (QueryBuilder $query) use ($chunk) {
                    foreach ($chunk as $match) {
                        $query->orWhere(fn (QueryBuilder $row) => $row->where($match));
                    }
                });

                foreach ($query->get() as $row) {
                    $row = (array) $row;
                    $found[$this->keyString($connection, $row, $keys)] = $returning === ['*'] ? $row : array_intersect_key($row, array_flip($returning));
                }
            }

            // In the order of $values. Rows the database matched differently (a case-insensitive
            // collation, for example) come last.
            $result = [];
            foreach (array_keys($matches) as $key) {
                if (isset($found[$key])) {
                    $result[] = $found[$key];
                    unset($found[$key]);
                }
            }

            return array_merge($result, array_values($found));
        });
    }

    /**
     * Insert rows, skip the ones that hit a unique key, and report exactly which were skipped.
     *
     * PostgreSQL and SQLite tell with RETURNING. MySQL and MariaDB need an auto-incrementing
     * primary key that the rows don't set: a multi-row INSERT gets one block of ids, so the rows
     * of the block are the inserted ones. Inside pretend() nothing runs.
     *
     * Example:
     * ```
     * $result = Batch::insertOrIgnoreRows(new User, $rows, ['email']);
     * // ['inserted' => 98, 'skipped' => [['email' => 'dup@example.com', ...], ...]]
     * ```
     *
     * @param array<array-key, mixed> $rows rows of column => value pairs, all with the same columns
     * @param array<int, string>|string $uniqueBy the column(s) that identify a row
     * @param int $batchSize rows per query (at least 100)
     * @return array{inserted: int, skipped: list<array<string, mixed>>} the skipped rows as given
     *
     * @throws InvalidArgumentException for a malformed row, or on MySQL a model without an auto-incrementing key
     */
    public function insertOrIgnoreRows(Model $table, array $rows, array|string $uniqueBy, int $batchSize = 500): array
    {
        $keys = $this->keyColumns((array) $uniqueBy, 'insertOrIgnoreRows');
        [$columns, $values] = $this->splitRows($rows);

        if (!$values) {
            return ['inserted' => 0, 'skipped' => []];
        }

        foreach ($keys as $key) {
            if (!in_array($key, $columns, true)) {
                throw new InvalidArgumentException("Every row must contain the \"{$key}\" column used to find skipped rows.");
            }
        }

        $connection = $this->db->connection($this->getConnectionName($table));
        $driver = $connection->getDriverName();
        $keyName = $table->getKeyName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && (!$table->getIncrementing() || in_array($keyName, $columns, true))) {
            throw new InvalidArgumentException(
                'On MySQL and MariaDB, insertOrIgnoreRows() needs a model with an auto-incrementing primary key that the rows don\'t set.'
            );
        }

        if (!in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException("insertOrIgnoreRows() is not supported on the \"{$driver}\" driver.");
        }

        $prepared = $this->prepareInsertRows($table, $columns, $values);
        $original = array_values($rows);
        $rowsPerQuery = $this->rowsPerInsert($connection, max(100, $batchSize), count($prepared[0]));

        return $this->transaction($connection, function () use ($connection, $table, $prepared, $original, $rowsPerQuery, $keys, $keyName, $driver): array {
            $runner = $this->runner($connection);
            $inserted = 0;
            $skipped = [];

            foreach (array_chunk($prepared, $rowsPerQuery, true) as $chunk) {
                $query = $this->baseQuery($connection, $table);
                $bindings = $query->cleanBindings(Arr::flatten($chunk, 1));
                $sql = $query->getGrammar()->compileInsertOrIgnore($query, array_values($chunk));

                if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                    $sql .= ' returning ' . $query->getGrammar()->columnize($keys);

                    if (!is_null($this->pretended)) {
                        $runner->insert($sql, $bindings);
                        continue;
                    }

                    $returned = array_map(fn ($row) => (array) $row, $connection->selectFromWriteConnection($sql, $bindings));
                } else {
                    $affected = $runner->affectingStatement($sql, $bindings);

                    if (!is_null($this->pretended)) {
                        continue;
                    }

                    if ($affected === 0) {
                        $returned = [];
                    } elseif ($affected === count($chunk)) {
                        $returned = array_values($chunk);
                    } else {
                        // The chunk got one block of ids and LAST_INSERT_ID() is its first inserted
                        // row. Other connections' ids come before or after the block, so the inserted
                        // rows are the first $affected rows from there.
                        $first = $this->selectId($connection, 'SELECT LAST_INSERT_ID() AS id');
                        $returned = array_map(fn ($row) => (array) $row, $connection->table($table->getTable())
                            ->select($keys)
                            ->where($keyName, '>=', $first)
                            ->orderBy($keyName)
                            ->limit($affected)
                            ->get()
                            ->all());
                    }
                }

                // Count each returned key once, so a key given twice in the chunk is inserted once.
                $remaining = [];
                foreach ($returned as $row) {
                    $key = $this->keyString($connection, $row, $keys);
                    $remaining[$key] = ($remaining[$key] ?? 0) + 1;
                }

                foreach ($chunk as $index => $row) {
                    $key = $this->keyString($connection, $row, $keys);
                    if (($remaining[$key] ?? 0) > 0) {
                        $remaining[$key]--;
                        $inserted++;
                    } else {
                        /** @var array<string, mixed> $skippedRow */
                        $skippedRow = $original[$index];
                        $skipped[] = $skippedRow;
                    }
                }
            }

            return ['inserted' => $inserted, 'skipped' => $skipped];
        });
    }

    /**
     * Make the rows a query matches look like a list: insert new rows, update existing ones and
     * delete the rows the list doesn't have, in one transaction.
     *
     * The scope query decides which rows can be deleted, so it's an explicit query: use
     * Product::query() to sync a whole table. The list is compared with the database by the
     * database itself, through a temporary table, so its collation decides which keys are the
     * same ("ABC" and "abc" are on MySQL by default). When a key appears twice, the later row wins.
     *
     * Models that use SoftDeletes are soft deleted unless $force is true, and soft-deleted rows
     * that are in the list are restored. Model events are not fired.
     *
     * Example:
     * ```
     * Batch::sync(Product::where('supplier_id', 5), $feedRows, ['sku']);
     * // ['inserted' => 120, 'updated' => 4800, 'deleted' => 35]
     * ```
     *
     * @param EloquentBuilder<Model> $scope the rows the list replaces
     * @param iterable<mixed> $rows rows of column => value pairs, all with the same columns
     * @param array<int, string>|string $uniqueBy the column(s) that identify a row; they need a primary or unique index
     * @param array<array-key, mixed>|null $update columns to update on existing rows; null updates every given column
     * @param bool $force delete soft-deleting models for real
     * @param bool $allowEmpty allow an empty list, which deletes every row in the scope
     * @param int $chunk rows per write
     * @return array{inserted: int, updated: int, deleted: int}
     *
     * @throws InvalidArgumentException for an empty list without $allowEmpty, or a row without its key values
     */
    public function sync(EloquentBuilder $scope, iterable $rows, array|string $uniqueBy, ?array $update = null, bool $force = false, bool $allowEmpty = false, int $chunk = 1000): array
    {
        $keys = $this->keyColumns((array) $uniqueBy, 'sync');
        $model = $scope->getModel();
        $connection = $this->db->connection($this->getConnectionName($model));
        $driver = $connection->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException("sync() is not supported on the \"{$driver}\" driver.");
        }

        if ($chunk < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1.');
        }

        $deletedAt = in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getDeletedAtColumn')
            ? (string) $model->getDeletedAtColumn()
            : null;

        if ($deletedAt && !is_null($update)) {
            $update[] = $deletedAt; // restore soft-deleted rows that are in the list
        }

        // Keys of a chunk are matched in one query, so keep them under the binding limit.
        $chunk = max(1, min($chunk, intdiv($this->maxBindings($connection), count($keys))));

        return $this->transaction($connection, function () use ($scope, $rows, $keys, $update, $force, $allowEmpty, $chunk, $model, $connection, $deletedAt) {
            $runner = $this->runner($connection);
            $table = $model->getTable();
            $temporary = 'batch_sync_' . bin2hex(random_bytes(6));
            $counts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

            $this->createKeyTable($runner, $temporary, $table, $keys);

            try {
                $pending = [];
                $number = 0;

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        throw new InvalidArgumentException("Row {$number} must be an array of column => value pairs.");
                    }

                    $match = [];
                    foreach ($keys as $key) {
                        $value = $this->enumValue($row[$key] ?? null);

                        if (!is_scalar($value)) {
                            throw new InvalidArgumentException("Row {$number} needs a single value for the \"{$key}\" key column.");
                        }
                        $match[] = (string) $value;
                    }

                    if ($deletedAt) {
                        $row[$deletedAt] = null;
                    }

                    // The later of two rows with the same key wins.
                    $id = json_encode($match, JSON_THROW_ON_ERROR);
                    unset($pending[$id]);
                    $pending[$id] = $row;
                    $number++;

                    if (count($pending) >= $chunk) {
                        $this->syncChunk($connection, $runner, $temporary, $model, $keys, $update, array_values($pending), $counts);
                        $pending = [];
                    }
                }

                if (!$number && !$allowEmpty) {
                    throw new InvalidArgumentException('sync() got no rows, which would delete every row in the scope. Pass $allowEmpty = true to do that.');
                }

                if ($pending) {
                    $this->syncChunk($connection, $runner, $temporary, $model, $keys, $update, array_values($pending), $counts);
                }

                $counts['deleted'] = $this->deleteMissing($scope, $runner, $temporary, $table, $keys, $force, $deletedAt);
            } finally {
                $drop = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'DROP TEMPORARY TABLE IF EXISTS ' : 'DROP TABLE IF EXISTS ';
                $runner->statement($drop . $connection->getQueryGrammar()->wrapTable($temporary));
            }

            return $counts;
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
     *
     * Batch::upsert(new Stock, $rows, ['sku'], ['qty' => ['+']]);                           // add to the stored qty
     * Batch::upsert(new Product, $rows, ['sku'], null, ['onlyIf' => ['updated_at' => '>']]); // newer rows win
     * ```
     *
     * @param Model $table
     * @param array<array-key, mixed> $values rows of column => value pairs, all with the same columns
     * @param array<int, string>|string $uniqueBy the column(s) that identify an existing row
     * @param array<array-key, mixed>|null $update columns to update on existing rows; null updates every given column.
     *        A column => ['+'], ['-'], ['max'] or ['min'] combines the stored value with the new one.
     * @param array<string, mixed> $options onlyIf: [column => '>', ...] updates an existing row only when
     *        the new value compares that way with the stored one (or the stored one is null)
     * @return int number of affected rows, as reported by the database
     *
     * @throws InvalidArgumentException when $uniqueBy or $update is empty, a row is malformed or an option is invalid
     */
    public function upsert(Model $table, array $values, $uniqueBy, ?array $update = null, array $options = []): int
    {
        $this->assertKnownOptions('upsert', $options, ['onlyIf']);

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
        $update = $this->upsertAssignments($connection, $table, $columns, $update, $options['onlyIf'] ?? []);

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
     * Write one chunk of sync() rows: record their keys, count the ones the table already has,
     * and upsert them.
     *
     * @param list<string> $keys
     * @param array<array-key, mixed>|null $update
     * @param list<array<array-key, mixed>> $rows
     * @param array{inserted: int, updated: int, deleted: int} $counts
     */
    private function syncChunk(Connection $connection, Connection $runner, string $temporary, Model $model, array $keys, ?array $update, array $rows, array &$counts): void
    {
        $number = $counts['inserted'] + $counts['updated']; // unique per chunk
        $matches = array_map(
            fn (array $row) => array_map([$this, 'enumValue'], array_intersect_key($row, array_flip($keys))) + [self::SYNC_CHUNK_COLUMN => $number],
            $rows
        );

        foreach (array_chunk($matches, $this->rowsPerInsert($connection, 1000, count($keys) + 1)) as $part) {
            $runner->table($temporary)->insert($part);
        }

        // Rows the table already has, soft-deleted ones included, are updated by the upsert.
        $existing = 0;
        if (is_null($this->pretended)) {
            $table = $model->getTable();
            $existing = $connection->table($temporary)
                ->where(self::SYNC_CHUNK_COLUMN, $number)
                ->whereExists(function (QueryBuilder $query) use ($table, $temporary, $keys) {
                    $query->selectRaw('1')->from($table);
                    foreach ($keys as $key) {
                        $query->whereColumn("{$table}.{$key}", '=', "{$temporary}.{$key}");
                    }
                })
                ->count();
        }

        $this->upsert($model, $rows, $keys, $update);
        $counts['updated'] += $existing;
        $counts['inserted'] += count($rows) - $existing;
    }

    /**
     * Create a temporary table for sync() with the key columns of $table, with their types and
     * collations, so the database compares the keys the way its unique index does.
     *
     * @param list<string> $keys
     */
    private function createKeyTable(Connection $runner, string $temporary, string $table, array $keys): void
    {
        $grammar = $runner->getQueryGrammar();
        $wrapped = $grammar->wrapTable($temporary);
        $columns = implode(', ', array_map(fn (string $key) => $grammar->wrap($key), $keys));
        $source = $grammar->wrapTable($table);

        $chunk = $grammar->wrap(self::SYNC_CHUNK_COLUMN);

        if (in_array($runner->getDriverName(), ['mysql', 'mariadb'], true)) {
            $runner->statement("CREATE TEMPORARY TABLE {$wrapped} (INDEX ({$columns}), INDEX ({$chunk})) SELECT {$columns}, 0 AS {$chunk} FROM {$source} LIMIT 0");

            return;
        }

        $runner->statement("CREATE TEMPORARY TABLE {$wrapped} AS SELECT {$columns}, 0 AS {$chunk} FROM {$source} LIMIT 0");
        $runner->statement('CREATE INDEX ' . $grammar->wrap($runner->getTablePrefix() . $temporary . '_keys') . " ON {$wrapped} ({$columns})");
        $runner->statement('CREATE INDEX ' . $grammar->wrap($runner->getTablePrefix() . $temporary . '_chunk') . " ON {$wrapped} ({$chunk})");
    }

    /**
     * Delete the rows in the scope whose keys aren't in the temporary key table.
     *
     * @param EloquentBuilder<Model> $scope
     * @param list<string> $keys
     */
    private function deleteMissing(EloquentBuilder $scope, Connection $runner, string $temporary, string $table, array $keys, bool $force, ?string $deletedAt): int
    {
        $query = (clone $scope)->whereNotExists(function (QueryBuilder $exists) use ($temporary, $table, $keys) {
            $exists->selectRaw('1')->from($temporary);
            foreach ($keys as $key) {
                $exists->whereColumn("{$temporary}.{$key}", '=', "{$table}.{$key}");
            }
        })->toBase();

        // Run through the runner, so pretend() records the statement instead of running it.
        $query->connection = $runner;

        if ($force || is_null($deletedAt)) {
            return $query->delete();
        }

        $model = $scope->getModel();
        $now = $model->freshTimestampString();
        $values = [$deletedAt => $now];
        if ($model->usesTimestamps() && !is_null($model->getUpdatedAtColumn())) {
            $values[$model->getUpdatedAtColumn()] = $now;
        }

        return $query->update($values);
    }

    /**
     * Walk the rows a query matches in primary key order, $chunk keys at a time, and run $work for
     * each chunk with its keys and a fresh copy of the query.
     *
     * Inside pretend() nothing runs: the SELECT that picks the first chunk is recorded instead.
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query
     * @param (callable(int): void)|null $onChunk
     * @param bool $lock lock each chunk's rows and run $work in the same transaction
     * @param \Closure(list<mixed>, EloquentBuilder<Model>|QueryBuilder, string): int $work returns the rows it changed
     */
    private function walkInChunks(EloquentBuilder|QueryBuilder $query, int $chunk, int $sleepMs, ?callable $onChunk, bool $lock, \Closure $work): int
    {
        if ($chunk < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1.');
        }

        if ($sleepMs < 0) {
            throw new InvalidArgumentException('The pause between chunks can\'t be negative.');
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        if (!is_null($base->limit) || !is_null($base->offset)) {
            throw new InvalidArgumentException('Chunked queries can\'t have a limit or offset; the rows are walked a chunk at a time.');
        }

        $connection = $this->connectionOf($query);
        $key = $this->keyOf($query);
        $qualifiedKey = $this->tableOf($query) . '.' . $key;
        $total = 0;
        $last = null;

        while (true) {
            $select = (clone $query)->reorder()->orderBy($qualifiedKey)->limit($chunk);
            if (!is_null($last)) {
                $select->where($qualifiedKey, '>', $last);
            }
            $select = $select instanceof EloquentBuilder ? $select->toBase() : $select;
            $select->select($qualifiedKey);

            if (!is_null($this->pretended)) {
                $this->pretended[] = [
                    'sql' => $select->toSql(),
                    'bindings' => $connection->prepareBindings($select->getBindings()),
                    'connection' => (string) $connection->getName(),
                ];

                return 0;
            }

            $step = function () use ($select, $lock, $key, $work, $query, $qualifiedKey): ?array {
                if ($lock) {
                    $select->lockForUpdate();
                }

                $keys = $select->pluck($key)->all();

                return $keys ? [$keys, $work(array_values($keys), clone $query, $qualifiedKey)] : null;
            };

            $result = $lock ? $connection->transaction($step) : $step();

            if (is_null($result)) {
                break;
            }

            [$keys, $changed] = $result;
            $total += $changed;
            $last = end($keys);

            if ($onChunk) {
                $onChunk($total);
            }

            if (count($keys) < $chunk) {
                break;
            }

            if ($sleepMs) {
                usleep($sleepMs * 1000);
            }
        }

        return $total;
    }

    /**
     * The connection a chunked query runs on.
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query
     */
    private function connectionOf(EloquentBuilder|QueryBuilder $query): Connection
    {
        $connection = ($query instanceof EloquentBuilder ? $query->getQuery() : $query)->getConnection();

        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException('Chunked queries need a Laravel database connection.');
        }

        return $connection;
    }

    /**
     * The table a chunked query runs on.
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query
     */
    private function tableOf(EloquentBuilder|QueryBuilder $query): string
    {
        if ($query instanceof EloquentBuilder) {
            return $query->getModel()->getTable();
        }

        if (!is_string($query->from) || preg_match('/\s/', $query->from)) {
            throw new InvalidArgumentException('Chunked DB::table() queries need a plain table name, without an alias.');
        }

        return $query->from;
    }

    /**
     * The primary key a chunked query is walked on: the model's key, or "id" for DB::table().
     *
     * @param EloquentBuilder<Model>|QueryBuilder $query
     */
    private function keyOf(EloquentBuilder|QueryBuilder $query): string
    {
        return $query instanceof EloquentBuilder ? $query->getModel()->getKeyName() : 'id';
    }

    /**
     * The columns two tables have in common, in the source table's order.
     *
     * @return list<string>
     */
    private function sharedColumns(Connection $source, string $sourceTable, Connection $target, string $targetTable): array
    {
        $targetColumns = array_flip($target->getSchemaBuilder()->getColumnListing($targetTable));
        $columns = [];
        foreach ($source->getSchemaBuilder()->getColumnListing($sourceTable) as $column) {
            if (isset($targetColumns[$column])) {
                $columns[] = (string) $column;
            }
        }

        if (!$columns) {
            throw new InvalidArgumentException("The tables \"{$sourceTable}\" and \"{$targetTable}\" have no columns in common.");
        }

        return $columns;
    }

    /**
     * Map, clean and transform one imported row, and check it has the same columns as the first.
     *
     * @param array<array-key, mixed> $row
     * @param array<array-key, string>|null $map
     * @param array<array-key, mixed> $nullValues
     * @param list<array-key>|null $columns the first row's columns
     * @return array{0: array<string, mixed>|null, 1: string|null} the row, or null and an error (both null: skipped)
     */
    private function importRow(array $row, ?array $map, array $nullValues, ?callable $transform, int $number, ?array $columns): array
    {
        $row = $this->mapImportRow($row, $map, $nullValues);

        if (is_string($row)) {
            return [null, $row];
        }

        if ($transform) {
            $row = $transform($row, $number);

            if (is_null($row)) {
                return [null, null];
            }

            if (!is_array($row)) {
                return [null, 'transform must return an array or null'];
            }
        }

        $named = [];
        foreach ($row as $column => $value) {
            if (!is_string($column) || $column === '') {
                return [null, 'its columns must be named'];
            }
            $named[$column] = $value;
        }

        if (!is_null($columns) && (count($named) !== count($columns) || array_diff_key($named, array_flip($columns)))) {
            return [null, sprintf('it has the columns [%s], but the first row has [%s]', implode(', ', array_keys($named)), implode(', ', $columns))];
        }

        return [$named, null];
    }

    /**
     * Apply an import's column map and null values to a row.
     *
     * @param array<array-key, mixed> $row
     * @param array<array-key, string>|null $map
     * @param array<array-key, mixed> $nullValues
     * @return array<array-key, mixed>|string the row, or an error
     */
    private function mapImportRow(array $row, ?array $map, array $nullValues): array|string
    {
        if (!is_null($map)) {
            $mapped = [];
            foreach ($map as $from => $to) {
                if (!array_key_exists($from, $row)) {
                    return "it has no \"{$from}\" column";
                }
                $mapped[$to] = $row[$from];
            }
            $row = $mapped;
        }

        if ($nullValues) {
            foreach ($row as $column => $value) {
                if (is_string($value) && in_array($value, $nullValues, true)) {
                    $row[$column] = null;
                }
            }
        }

        return $row;
    }

    /**
     * Insert rows with the database's own bulk loader: COPY on PostgreSQL, LOAD DATA LOCAL INFILE
     * on MySQL and MariaDB. Rows get the same enums, JSON casts and timestamps as insertRows().
     *
     * @param array<array-key, mixed> $rows rows of column => value pairs
     */
    private function bulkLoad(Connection $connection, Model $table, array $rows): void
    {
        // import() checked that every row has the first row's columns, so no splitRows() here:
        // this loop runs for every value of a large file.
        $rows = array_values($rows);
        if (!$rows || !is_array($rows[0])) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $dateFormat = $grammar->getDateFormat();
        $columns = array_map('strval', array_keys($rows[0]));
        $jsonCasts = $this->jsonCastColumns($table);

        // Timestamps the rows don't set, the same for every row.
        $extra = [];
        if ($table->usesTimestamps()) {
            $now = Carbon::now()->format($table->getDateFormat());
            foreach ([$table->getCreatedAtColumn(), $table->getUpdatedAtColumn()] as $timestamp) {
                if (!is_null($timestamp) && !in_array($timestamp, $columns, true)) {
                    $extra[$timestamp] = $now;
                }
            }
        }
        $suffix = $extra ? "\t" . implode("\t", array_map(fn ($value) => $this->bulkLoadValue($value, $dateFormat), $extra)) : '';

        $lines = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $fields = [];
            foreach ($row as $column => $value) {
                if (is_string($value)) {
                    // Most values need no escaping; only scan them.
                    $fields[] = strpbrk($value, "\\\t\n\r") === false ? $value : $this->bulkLoadValue($value, $dateFormat);
                    continue;
                }

                if (isset($jsonCasts[$column])) {
                    $value = $this->castToJson($table, $jsonCasts, $column, $value);
                }

                $fields[] = $this->bulkLoadValue($this->enumValue($value), $dateFormat);
            }
            $lines[] = implode("\t", $fields) . $suffix;
        }

        $tableSql = $grammar->wrapTable($table->getTable());
        $fields = $grammar->columnize(array_merge($columns, array_keys($extra)));

        if ($connection->getDriverName() === 'pgsql') {
            if (!is_null($this->pretended)) {
                $this->pretended[] = ['sql' => "COPY {$tableSql} ({$fields}) FROM STDIN", 'bindings' => [], 'connection' => (string) $connection->getName()];

                return;
            }

            // PHP 8.4 moved the method to Pdo\Pgsql; older versions have it on the PDO object.
            $pdo = $connection->getPdo();
            $copy = [$pdo, class_exists(\Pdo\Pgsql::class) && $pdo instanceof \Pdo\Pgsql ? 'copyFromArray' : 'pgsqlCopyFromArray'];
            if (!is_callable($copy)) {
                throw new \RuntimeException('This PHP build has no COPY support in pdo_pgsql.');
            }
            $copy($tableSql, $lines, "\t", '\\\\N', $fields);

            return;
        }

        $file = tempnam(sys_get_temp_dir(), 'batch');
        if ($file === false) {
            throw new \RuntimeException('Could not create a temporary file for LOAD DATA.');
        }

        try {
            file_put_contents($file, implode("\n", $lines) . "\n");

            $sql = 'LOAD DATA LOCAL INFILE ' . $connection->getPdo()->quote($file) . " INTO TABLE {$tableSql} CHARACTER SET utf8mb4"
                . " FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\' LINES TERMINATED BY '\\n' ({$fields})";

            if (!is_null($this->pretended)) {
                $this->pretended[] = ['sql' => $sql, 'bindings' => [], 'connection' => (string) $connection->getName()];

                return;
            }

            try {
                // LOAD DATA LOCAL can't be a prepared statement.
                $loaded = $connection->getPdo()->exec($sql);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'LOAD DATA LOCAL INFILE failed: ' . $e->getMessage() . ' A "fast" import on MySQL and MariaDB needs '
                    . 'PDO::MYSQL_ATTR_LOCAL_INFILE => true in the connection\'s "options", and local_infile = ON on the server.',
                    0,
                    $e
                );
            }

            // LOCAL turns data errors into warnings and keeps going, so check nothing was lost or
            // changed. SHOW WARNINGS can't be a prepared statement either: run it as plain text.
            $warnings = $this->mysqlWarnings($connection->getPdo());

            if ($loaded !== count($lines) || $warnings) {
                $first = $warnings[0]['Message'] ?? null;

                throw new \RuntimeException(sprintf(
                    'LOAD DATA loaded %d of %d rows with %d warnings%s.',
                    (int) $loaded,
                    count($lines),
                    count($warnings),
                    is_string($first) ? ', the first: ' . $first : ''
                ));
            }
        } finally {
            @unlink($file);
        }
    }

    /**
     * The warnings of the last statement on a MySQL connection.
     *
     * @return list<array<array-key, mixed>>
     */
    private function mysqlWarnings(\PDO $pdo): array
    {
        $emulate = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $statement = $pdo->query('SHOW WARNINGS');
            $rows = $statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [];
        } finally {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emulate);
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * A value in the text format COPY and LOAD DATA read: \N for null, tabs, newlines and
     * backslashes escaped.
     */
    private function bulkLoadValue(mixed $value, string $dateFormat): string
    {
        if (is_null($value)) {
            return '\\N';
        }

        if ($value instanceof Expression) {
            throw new InvalidArgumentException('A "fast" import can\'t use DB::raw() values.');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format($dateFormat);
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new InvalidArgumentException('A "fast" import can only load single values, ' . get_debug_type($value) . ' given.');
        }

        return strtr((string) $value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $known
     */
    private function assertKnownOptions(string $method, array $options, array $known): void
    {
        if ($unknown = array_diff(array_keys($options), $known)) {
            throw new InvalidArgumentException(sprintf(
                '%s() has no option "%s". The options are: %s.',
                $method,
                implode('", "', $unknown),
                implode(', ', $known)
            ));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        if (!is_null($value) && (!is_string($value) || $value === '')) {
            throw new InvalidArgumentException("The \"{$name}\" option must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function callableOption(array $options, string $name): ?callable
    {
        $value = $options[$name] ?? null;

        if (!is_null($value) && !is_callable($value)) {
            throw new InvalidArgumentException("The \"{$name}\" option must be callable.");
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function columnList(mixed $columns): array
    {
        if (!is_array($columns) || !$columns) {
            throw new InvalidArgumentException('The "columns" option must be a non-empty list of column names.');
        }

        $list = [];
        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                throw new InvalidArgumentException('The "columns" option must be a non-empty list of column names.');
            }
            $list[] = $column;
        }

        return $list;
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
