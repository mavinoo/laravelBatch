<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Grammar;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
     * @var DatabaseManager
     */
    protected $db;

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
     * @param array $values rows, each holding the index value and the columns to set
     * @param string|null $index column to match rows on, defaults to the primary key
     * @return int number of affected rows
     *
     * @throws InvalidArgumentException when a row has no index value or an arithmetic array is invalid
     */
    public function update(Model $table, array $values, ?string $index = null): int
    {
        $this->rejectRawArgument(func_get_args(), 3);

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        $entries = [];
        foreach ($values as $row) {
            $this->assertHasConditions($row, [$index]);

            $conditions = [$index => $row[$index]];
            unset($row[$index]);

            $entries[] = ['conditions' => $conditions, 'columns' => $row];
        }

        return $this->runCaseUpdate($table, $entries, [$index]);
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
     * @param array $values rows, each holding both index values and the columns to set
     * @param string|null $index first column to match on, defaults to the primary key
     * @param string|null $index2 second column to match on
     * @return int number of affected rows
     *
     * @throws InvalidArgumentException when $index2 is missing, a row lacks an index value or an arithmetic array is invalid
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

        $entries = [];
        foreach ($values as $row) {
            $this->assertHasConditions($row, [$index, $index2]);

            $conditions = [$index => $row[$index], $index2 => $row[$index2]];
            unset($row[$index], $row[$index2]);

            $entries[] = ['conditions' => $conditions, 'columns' => $row];
        }

        return $this->runCaseUpdate($table, $entries, [$index, $index2]);
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
     * @param array $values items of ['conditions' => [column => value], 'columns' => [column => value]]
     * @param string|null $index column every item's conditions include, defaults to the primary key
     * @return int number of affected rows
     *
     * @throws InvalidArgumentException when an item is malformed or the update can't be ordered safely on MySQL
     */
    public function updateMultipleCondition(Model $table, array $values, ?string $index = null): int
    {
        $this->rejectRawArgument(func_get_args(), 3);

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        $entries = [];
        foreach ($values as $item) {
            if (!isset($item['conditions'], $item['columns']) || !is_array($item['conditions']) || !is_array($item['columns'])) {
                throw new InvalidArgumentException('Each item needs a "conditions" array and a "columns" array.');
            }

            $this->assertHasConditions($item['conditions'], [$index]);

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
     * @param array $columns column names
     * @param array $values rows, each a list of values in the same order as $columns
     * @param int $batchSize rows per query (at least 100)
     * @param bool $insertIgnore skip rows that hit a unique key (not supported on SQL Server)
     * @return array{totalRows: int, totalBatch: int, totalQuery: int}
     *
     * @throws InvalidArgumentException when a row doesn't have one value per column
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

            $rows[] = array_combine($columns, array_values($row));
        }

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

        $connection = $this->db->connection($this->getConnectionName($table));

        // Keep each statement under the driver's bound parameter limit.
        $rowsPerQuery = max(1, min($totalChunk, intdiv($this->maxBindings($connection), count($rows[0]))));

        return $connection->transaction(function () use ($connection, $table, $rows, $rowsPerQuery, $insertIgnore, $totalValues, $totalChunk) {
            $totalQuery = 0;
            foreach (array_chunk($rows, $rowsPerQuery) as $chunk) {
                $query = $connection->table($table->getTable());
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
     * Build and run "UPDATE ... SET col = CASE WHEN ... END" statements.
     *
     * Every value and condition is sent as a bound parameter and every identifier is
     * wrapped by the connection's grammar, so the generated SQL is safe on all drivers.
     *
     * @param Model $model
     * @param array $entries list of ['conditions' => [col => value], 'columns' => [col => value]]
     * @param array $whereColumns condition columns used to limit the rows in the WHERE clause
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

        $limit = $this->maxBindings($connection);
        $chunks = [];
        $chunk = [];
        $chunkCost = 0;

        foreach ($entries as $entry) {
            $compiled = $this->compileEntry($grammar, $entry, $updatedAtColumn, $timestampValue);
            $cost = $compiled['cost'] + count($whereColumns);

            if ($chunk && $chunkCost + $cost > $limit) {
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
            if ($statement = $this->compileUpdateStatement($grammar, $model, $chunk, $whereColumns, $updatedAtColumn, $connection->getDriverName())) {
                $statements[] = $statement;
            }
        }

        $run = function () use ($connection, $statements) {
            $affected = 0;
            foreach ($statements as [$sql, $bindings]) {
                $affected += $connection->update($sql, $bindings);
            }

            return $affected;
        };

        return count($statements) > 1 ? $connection->transaction($run) : $run();
    }

    /**
     * Compile the CASE branches contributed by a single row.
     */
    private function compileEntry(Grammar $grammar, array $entry, ?string $updatedAtColumn, ?string $timestampValue): array
    {
        [$whenSql, $whenBindings] = $this->compileConditions($grammar, $entry['conditions']);

        $cases = [];
        $touch = null;
        $changes = [];
        $changeBindings = [];

        foreach ($entry['columns'] as $column => $value) {
            $wrapped = $grammar->wrap($column);

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
                $valueSql = $wrapped . ' ' . $value[0] . ' ' . $this->arithmeticOperand($value);
                $valueBindings = [];
                $changes[] = $wrapped . ' IS NOT NULL';
            } else {
                [$valueSql, $valueBindings] = $this->compileValue($grammar, $value);

                // Null-safe "value actually changes" check, used for the automatic timestamp.
                if (is_null($value)) {
                    $changes[] = $wrapped . ' IS NOT NULL';
                } else {
                    $changes[] = '(' . $wrapped . ' <> ' . $valueSql . ' OR ' . $wrapped . ' IS NULL)';
                    $changeBindings = array_merge($changeBindings, $valueBindings);
                }
            }

            $cases[$column] = ['WHEN ' . $whenSql . ' THEN ' . $valueSql, array_merge($whenBindings, $valueBindings)];
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
        ];
    }

    /**
     * Compile a chunk of compiled rows into one UPDATE statement.
     *
     * @return array|null [sql, bindings], or null when there is nothing to update
     */
    private function compileUpdateStatement(Grammar $grammar, Model $model, array $chunk, array $whereColumns, ?string $updatedAtColumn, string $driver): ?array
    {
        $whens = [];
        $touches = [];
        $readers = []; // condition column => [updated column whose CASE reads it => true]

        foreach ($chunk as $compiled) {
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
            $sets[] = $wrapped . ' = (CASE ' . implode(' ', array_column($cases, 0)) . ' ELSE ' . $wrapped . ' END)';

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
     * @param array $whens updated column => CASE branches
     * @param array $readers condition column => [updated column whose CASE reads it => true]
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
     * Compile a set of column => value pairs into an AND-ed condition.
     */
    private function compileConditions(Grammar $grammar, array $conditions): array
    {
        $sql = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
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
     */
    private function compileValue(Grammar $grammar, $value): array
    {
        if (is_null($value)) {
            return ['NULL', []];
        }

        if ($value instanceof Expression) {
            return [(string) $grammar->getValue($value), []];
        }

        return ['?', [$value]];
    }

    /**
     * Validate an increment / decrement array and return its numeric operand as SQL.
     */
    private function arithmeticOperand(array $value): string
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
        return '(' . (is_int($number) ? (string) $number : var_export($number, true)) . ')';
    }

    /**
     * The $raw flag was removed in 3.0. Fail loudly instead of silently storing SQL as text.
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
     * Make sure every row carries the columns used to match it.
     */
    private function assertHasConditions(array $row, array $columns): void
    {
        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                throw new InvalidArgumentException("Every row must contain a value for the \"{$column}\" column.");
            }
        }
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
